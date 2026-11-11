<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;
use Plugins\Outbox\Application\Ports\OutboxStore;
use Plugins\Outbox\Domain\OutboxEntry;
use Plugins\Outbox\Domain\OutboxStatus;
use Plugins\Outbox\Infrastructure\Exceptions\OutboxException;

/**
 * The only thing in this plugin that writes SQL. DatabasePort only.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * CLAIMING, AND WHY IT IS NOT `FOR UPDATE`
 * ──────────────────────────────────────────────────────────────────────────
 * Two relays reading `WHERE status = 0` both see the same rows and both
 * dispatch them, so every consumer gets two copies of every event for as long
 * as both run. Reading a row is not claiming it.
 *
 * The obvious fix is `SELECT … FOR UPDATE SKIP LOCKED`, and it is the wrong one
 * here for a specific reason: that lock is only held for the life of a
 * transaction, so the transaction would have to stay open across the dispatch —
 * and a dispatch runs listeners, which do HTTP calls, send mail and write to
 * other tables. Holding a row lock across a network call to someone else's
 * endpoint is how one slow consumer becomes a database incident. It would also
 * need a driver branch, since `FOR UPDATE` is a syntax error on SQLite
 * (`DriverAware` exists for exactly that, and is used only in {@see describe()}
 * here).
 *
 * So a claim is its own committed write:
 *
 *     UPDATE event_outbox SET status = 3, claimed_by = :owner, claimed_at = :at
 *      WHERE id = :id AND status = 0
 *
 * The `status = 0` predicate is the race: the database applies it atomically,
 * so exactly one relay sees one affected row and every other sees zero. No
 * lock is held afterwards, the dispatch runs with nothing open, and the
 * portability question never arises because this is ordinary SQL everywhere.
 *
 * The cost is one statement per row instead of one per batch. For a relay that
 * runs on a timer and is not latency-critical, that is the cheaper side of the
 * trade.
 *
 * A claimed row that is never settled — the relay was killed between the claim
 * and the dispatch — is released by {@see reclaimStale()}. Without that, the
 * claim that makes concurrency safe would also be a permanent leak.
 */
final class OutboxRepository implements OutboxStore
{
    public const TABLE = 'event_outbox';

    /**
     * `last_error` is a diagnostic, not a payload. Truncating it bounds a
     * listener exception whose message embeds a whole response body.
     */
    private const ERROR_MAX_CHARS = 1000;

    private const OWNER_MAX_CHARS = 64;

    /**
     * Rows deleted per statement by {@see prune()}, and how many statements it
     * will issue before handing control back.
     *
     * A single unbounded `DELETE` on a table nobody has pruned since the
     * application was deployed is a long lock on the relay's own hot table. The
     * cap means one call finishes in bounded time and says whether there is
     * more to do, rather than either locking for minutes or looping forever.
     */
    private const PRUNE_BATCH    = 1000;
    private const PRUNE_MAX_RUNS = 100;

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function on(DatabasePort $connection): OutboxStore
    {
        return new self($connection);
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }

    // ── write side (inside the CALLER'S transaction) ──────────────────────────

    public function write(
        string $eventId,
        string $eventName,
        string $eventVersion,
        string $payloadJson,
        \DateTimeImmutable $occurredAt,
    ): bool {
        try {
            // Insert-if-absent rather than a plain INSERT. Writing the same
            // event id twice is then an idempotent no-op instead of a unique
            // constraint violation — which matters because this runs inside the
            // caller's transaction, so that violation would abort THEIR work
            // over a duplicate the outbox was perfectly able to ignore.
            //
            // upsert() compiles the dialect's own form; hand-writing
            // ON DUPLICATE KEY / ON CONFLICT here is forbidden and unnecessary.
            $affected = $this->db->upsert(
                self::TABLE,
                [
                    'event_id'      => $eventId,
                    'event_name'    => $eventName,
                    'event_version' => $eventVersion,
                    'payload'       => $payloadJson,
                    'status'        => OutboxStatus::Pending->value,
                    'attempts'      => 0,
                    'occurred_at'   => self::stamp($occurredAt),
                    'created_at'    => self::stamp($occurredAt),
                ],
                conflictColumns: ['event_id'],
                // [] means insert-if-absent. NOT a no-op: the row is still
                // written when it is not already there.
                updateColumns: [],
            );
        } catch (\Throwable $e) {
            throw OutboxException::writeFailed($eventName, $e);
        }

        return $affected > 0;
    }

    // ── relay side ────────────────────────────────────────────────────────────

    /** @return list<OutboxEntry> */
    public function pending(int $limit): array
    {
        // Inlined, not bound: a bound parameter is sent as a string and a native
        // prepare rejects `LIMIT '100'`. OutboxConfig clamps the value to
        // 1..1000 on every construction path, which is what makes this safe.
        $limit = max(1, min(1000, $limit));

        try {
            $rows = $this->db->query(
                'SELECT id, event_id, event_name, event_version, payload, attempts
                   FROM ' . self::TABLE . '
                  WHERE status = ' . OutboxStatus::Pending->value . '
                  ORDER BY occurred_at ASC, id ASC
                  LIMIT ' . $limit,
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }

        return array_map(
            static fn (array $row): OutboxEntry => new OutboxEntry(
                id:           (int) $row['id'],
                eventId:      (string) $row['event_id'],
                eventName:    (string) $row['event_name'],
                eventVersion: (string) $row['event_version'],
                payloadJson:  (string) $row['payload'],
                attempts:     (int) $row['attempts'],
            ),
            $rows,
        );
    }

    public function claim(int $id, string $owner, \DateTimeImmutable $at): bool
    {
        try {
            // `status = :pending` is the whole race. One relay's UPDATE affects
            // one row; every other relay's affects none, and reads it as a lost
            // claim rather than permission to dispatch.
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET status = :claimed, claimed_by = :owner, claimed_at = :at
                  WHERE id = :id AND status = :pending',
                [
                    'claimed' => OutboxStatus::Claimed->value,
                    'owner'   => mb_substr($owner, 0, self::OWNER_MAX_CHARS),
                    'at'      => self::stamp($at),
                    'id'      => $id,
                    'pending' => OutboxStatus::Pending->value,
                ],
            );
        } catch (\Throwable $e) {
            throw OutboxException::statusChangeFailed('claim', $id, $e);
        }

        return $affected === 1;
    }

    public function markDispatched(int $id, \DateTimeImmutable $at): void
    {
        try {
            // `status = :claimed` so this can only settle a row this relay
            // actually holds. Without it a stale reference could overwrite a row
            // another relay had already reclaimed and redelivered.
            $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET status = :dispatched, dispatched_at = :at,
                        attempts = attempts + 1, claimed_by = NULL, last_error = NULL
                  WHERE id = :id AND status = :claimed',
                [
                    'dispatched' => OutboxStatus::Dispatched->value,
                    'at'         => self::stamp($at),
                    'id'         => $id,
                    'claimed'    => OutboxStatus::Claimed->value,
                ],
            );
        } catch (\Throwable $e) {
            throw OutboxException::statusChangeFailed('mark dispatched', $id, $e);
        }
    }

    public function release(int $id, int $attempts, string $error, bool $park): void
    {
        try {
            $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET status = :status, attempts = :attempts, last_error = :error,
                        claimed_by = NULL, claimed_at = NULL
                  WHERE id = :id',
                [
                    'status'   => $park ? OutboxStatus::Failed->value : OutboxStatus::Pending->value,
                    'attempts' => $attempts,
                    'error'    => mb_substr($error, 0, self::ERROR_MAX_CHARS),
                    'id'       => $id,
                ],
            );
        } catch (\Throwable $e) {
            throw OutboxException::statusChangeFailed('release', $id, $e);
        }
    }

    public function reclaimStale(\DateTimeImmutable $cutoff): int
    {
        try {
            // Attempts is incremented, and that is a deliberate choice with a
            // cost. A relay that is killed BY a particular row — a listener that
            // exhausts the memory limit, say — would otherwise reclaim and retry
            // that row forever, and the loop would look like an outbox that
            // works. Counting the reclaim bounds it, at the price of charging an
            // attempt to innocent rows when a relay dies for unrelated reasons.
            // Bounded retries of a poison row is the better failure.
            return $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET status = :pending, attempts = attempts + 1,
                        claimed_by = NULL, claimed_at = NULL,
                        last_error = :note
                  WHERE status = :claimed AND claimed_at < :cutoff',
                [
                    'pending' => OutboxStatus::Pending->value,
                    'note'    => 'reclaimed: the relay that claimed this row never settled it',
                    'claimed' => OutboxStatus::Claimed->value,
                    'cutoff'  => self::stamp($cutoff),
                ],
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }
    }

    // ── operator surface ──────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function counts(): array
    {
        // Every status present at zero, so a caller never has to tell "none"
        // from "not reported" — and so a dashboard does not show a gap where it
        // should show a 0.
        $counts = [];
        foreach (OutboxStatus::cases() as $status) {
            $counts[$status->label()] = 0;
        }

        try {
            $rows = $this->db->query(
                'SELECT status, COUNT(*) AS total FROM ' . self::TABLE . ' GROUP BY status',
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }

        foreach ($rows as $row) {
            $status = OutboxStatus::tryFrom((int) $row['status']);

            if ($status !== null) {
                $counts[$status->label()] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function retryFailed(int $limit): int
    {
        // Ids first, then the update. `UPDATE … LIMIT` is MySQL-only and
        // PostgreSQL rejects it, so selecting the ids is the portable form —
        // and the ids are integers this method produced, so interpolating them
        // introduces nothing.
        $ids = $this->idsWithStatus(OutboxStatus::Failed, max(1, min(1000, $limit)));

        if ($ids === []) {
            return 0;
        }

        try {
            return $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET status = :pending, attempts = 0, last_error = NULL,
                        claimed_by = NULL, claimed_at = NULL
                  WHERE id IN (' . implode(',', $ids) . ')',
                ['pending' => OutboxStatus::Pending->value],
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $deleted = 0;

        for ($run = 0; $run < self::PRUNE_MAX_RUNS; $run++) {
            try {
                // Only settled rows. A pending or claimed row is still owed to
                // its consumers however old it is, and deleting one is losing an
                // event silently — which is the single thing this plugin exists
                // to prevent.
                //
                // THE STATUS FILTER APPEARS TWICE AND NEITHER COPY IS DEAD.
                // A revert check showed each one passing the suite alone, which
                // means they mask each other in the tests — so the reason each
                // exists is written here rather than left to be rediscovered by
                // whoever deletes one as duplication:
                //
                //   In settledIdsBefore(), it makes the BATCHING correct. The
                //   loop stops when a batch comes back short, so a batch of ids
                //   that included owed rows would delete fewer than it selected,
                //   read as "short", and stop — leaving older settled rows
                //   behind forever.
                //
                //   Here, it closes the window between that SELECT and this
                //   DELETE. `retryFailed()` moves rows from failed to pending,
                //   so a row selected as settled can have been rescued by a
                //   concurrent `outbox:retry` by the time this runs. Without
                //   this predicate, prune would delete an event an operator had
                //   just deliberately put back.
                $batch = $this->db->execute(
                    'DELETE FROM ' . self::TABLE . '
                      WHERE status IN (:dispatched, :failed)
                        AND created_at < :before
                        AND id IN (' . implode(',', $this->settledIdsBefore($before)) . ')',
                    [
                        'dispatched' => OutboxStatus::Dispatched->value,
                        'failed'     => OutboxStatus::Failed->value,
                        'before'     => self::stamp($before),
                    ],
                );
            } catch (\Throwable $e) {
                throw OutboxException::readFailed($e);
            }

            $deleted += $batch;

            if ($batch < self::PRUNE_BATCH) {
                break;
            }
        }

        return $deleted;
    }

    public function describe(): string
    {
        // DriverAware is OPTIONAL — checked, never assumed, because every
        // in-memory test double implements DatabasePort and not this. And the
        // answer is a dialect name, which is why it is safe to print: it carries
        // no host, no database name and no credential.
        $driver = $this->db instanceof DriverAware ? $this->db->driver() : 'unknown driver';

        return self::TABLE . ' on ' . $driver;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function idsWithStatus(OutboxStatus $status, int $limit): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id FROM ' . self::TABLE . '
                  WHERE status = ' . $status->value . '
                  ORDER BY id ASC
                  LIMIT ' . $limit,
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * One batch of settled row ids older than $before.
     *
     * Returns `[0]` rather than `[]` when there is nothing to delete, because an
     * empty `IN ()` list is a syntax error on every driver — and `id` is a
     * positive auto-increment, so `IN (0)` matches nothing and the DELETE
     * reports zero rows, which is the answer the caller needs.
     *
     * @return non-empty-list<int>
     */
    private function settledIdsBefore(\DateTimeImmutable $before): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id FROM ' . self::TABLE . '
                  WHERE status IN (' . OutboxStatus::Dispatched->value . ', '
                                     . OutboxStatus::Failed->value . ')
                    AND created_at < :before
                  ORDER BY id ASC
                  LIMIT ' . self::PRUNE_BATCH,
                ['before' => self::stamp($before)],
            );
        } catch (\Throwable $e) {
            throw OutboxException::readFailed($e);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        return $ids === [] ? [0] : $ids;
    }

    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
