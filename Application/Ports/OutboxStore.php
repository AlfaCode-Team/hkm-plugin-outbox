<?php

declare(strict_types=1);

namespace Plugins\Outbox\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\Domain\OutboxEntry;

/**
 * The persistence seam, so the delivery policy can be tested without a
 * database and so the SQL lives in exactly one place.
 *
 * Every timestamp crosses this boundary as a `DateTimeImmutable` rather than a
 * formatted string: the service owns the clock (and in tests, a frozen one),
 * and the store owns the column format. A string here would mean the service
 * had to know the latter.
 */
interface OutboxStore
{
    /**
     * The same store over a different connection.
     *
     * This is the method the whole transactional guarantee rests on — see
     * {@see \Plugins\Outbox\API\Contracts\OutboxContract::on()}.
     */
    public function on(DatabasePort $connection): self;

    /**
     * Is this store's OWN connection inside a transaction?
     *
     * The guard that makes "transactional" checkable rather than hoped for. A
     * caller writing from inside its transaction, on a connection this store
     * does not share, gets `false` here — which is the whole silent failure,
     * detected.
     */
    public function inTransaction(): bool;

    /**
     * Enqueue an event. Insert-if-absent on `event_id`.
     *
     * @return bool true when a row was written, false when that `event_id` was
     *         already present — which is a successful idempotent no-op, not a
     *         failure, and must not abort the caller's transaction.
     */
    public function write(
        string $eventId,
        string $eventName,
        string $eventVersion,
        string $payloadJson,
        \DateTimeImmutable $occurredAt,
    ): bool;

    /**
     * Rows owed to consumers, oldest first.
     *
     * Reading a row is NOT claiming it — see {@see claim()}.
     *
     * @return list<OutboxEntry>
     */
    public function pending(int $limit): array;

    /**
     * Take exclusive ownership of one row.
     *
     * @return bool true when THIS caller won the row. False means another relay
     *         claimed it between the read and now, and the caller must not
     *         dispatch it.
     */
    public function claim(int $id, string $owner, \DateTimeImmutable $at): bool;

    public function markDispatched(int $id, \DateTimeImmutable $at): void;

    /**
     * Record a failed attempt.
     *
     * @param bool $park true to take the row out of the retry path for good.
     */
    public function release(int $id, int $attempts, string $error, bool $park): void;

    /**
     * Return rows claimed before $cutoff to pending.
     *
     * @return int how many were released — a non-zero count is the only signal
     *         anywhere that a relay process died mid-run.
     */
    public function reclaimStale(\DateTimeImmutable $cutoff): int;

    /**
     * How many rows sit in each status.
     *
     * @return array<string, int> keyed by {@see \Plugins\Outbox\Domain\OutboxStatus::label()},
     *         with every status present even at zero so a caller never has to
     *         distinguish "none" from "not reported".
     */
    public function counts(): array;

    /** Move parked rows back to pending, resetting their attempts. */
    public function retryFailed(int $limit): int;

    /** Delete settled rows older than $before. Returns how many went. */
    public function prune(\DateTimeImmutable $before): int;

    /** For status output — never a credential or a DSN. */
    public function describe(): string;
}
