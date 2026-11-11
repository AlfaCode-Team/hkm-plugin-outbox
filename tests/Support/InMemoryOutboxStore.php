<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\Application\Ports\OutboxStore;
use Plugins\Outbox\Domain\OutboxEntry;
use Plugins\Outbox\Domain\OutboxStatus;

/**
 * An OutboxStore that holds rows in an array.
 *
 * For driving the DELIVERY POLICY — claim races, retry budgets, what a listener
 * failure does to a row. It runs no SQL, so it can say nothing about whether
 * the real statements match the schema; that is the integration suite's job
 * against a migrated database.
 *
 * It is deliberately NOT forgiving: {@see claim()} refuses a row that is not
 * pending, and {@see markDispatched()} refuses one this store does not have
 * claimed, exactly as the real `WHERE` clauses do. A permissive double would
 * let the service pass tests that the repository's own predicates would fail.
 */
final class InMemoryOutboxStore implements OutboxStore
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /** Every state-changing call, in order, for asserting what the policy did. */
    /** @var list<string> */
    public array $log = [];

    /** Row ids whose next claim attempt loses the race, as if another relay won. */
    /** @var list<int> */
    public array $claimsLostFor = [];

    public bool $inTransaction = true;

    public ?\Throwable $failNextWrite = null;

    private int $nextId = 1;

    public function on(DatabasePort $connection): OutboxStore
    {
        // The real repository returns a new instance over the new connection.
        // Returning $this would let a test pass while the service dropped the
        // connection it was handed on the floor.
        $copy = clone $this;
        $copy->log[] = 'on';

        return $copy;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function write(
        string $eventId,
        string $eventName,
        string $eventVersion,
        string $payloadJson,
        \DateTimeImmutable $occurredAt,
    ): bool {
        if ($this->failNextWrite !== null) {
            $failure = $this->failNextWrite;
            $this->failNextWrite = null;

            throw $failure;
        }

        foreach ($this->rows as $row) {
            if ($row['event_id'] === $eventId) {
                // Insert-if-absent: a duplicate id is a successful no-op.
                $this->log[] = 'write:duplicate';

                return false;
            }
        }

        $id = $this->nextId++;

        $this->rows[$id] = [
            'id'            => $id,
            'event_id'      => $eventId,
            'event_name'    => $eventName,
            'event_version' => $eventVersion,
            'payload'       => $payloadJson,
            'status'        => OutboxStatus::Pending->value,
            'attempts'      => 0,
            'last_error'    => null,
            'occurred_at'   => $occurredAt,
            'claimed_by'    => null,
            'claimed_at'    => null,
        ];

        $this->log[] = 'write';

        return true;
    }

    /** @return list<OutboxEntry> */
    public function pending(int $limit): array
    {
        $pending = array_filter(
            $this->rows,
            static fn (array $r): bool => $r['status'] === OutboxStatus::Pending->value,
        );

        usort($pending, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_map(
            static fn (array $r): OutboxEntry => new OutboxEntry(
                id:           (int) $r['id'],
                eventId:      (string) $r['event_id'],
                eventName:    (string) $r['event_name'],
                eventVersion: (string) $r['event_version'],
                payloadJson:  (string) $r['payload'],
                attempts:     (int) $r['attempts'],
            ),
            array_slice($pending, 0, max(1, $limit)),
        );
    }

    public function claim(int $id, string $owner, \DateTimeImmutable $at): bool
    {
        if (in_array($id, $this->claimsLostFor, true)) {
            $this->log[] = "claim:lost:{$id}";

            return false;
        }

        // Same predicate as the real UPDATE: only a PENDING row can be claimed.
        if (($this->rows[$id]['status'] ?? null) !== OutboxStatus::Pending->value) {
            $this->log[] = "claim:lost:{$id}";

            return false;
        }

        $this->rows[$id]['status']     = OutboxStatus::Claimed->value;
        $this->rows[$id]['claimed_by'] = $owner;
        $this->rows[$id]['claimed_at'] = $at;

        $this->log[] = "claim:{$id}";

        return true;
    }

    public function markDispatched(int $id, \DateTimeImmutable $at): void
    {
        // The real statement carries `AND status = :claimed`.
        if (($this->rows[$id]['status'] ?? null) !== OutboxStatus::Claimed->value) {
            return;
        }

        $this->rows[$id]['status']        = OutboxStatus::Dispatched->value;
        $this->rows[$id]['attempts']++;
        $this->rows[$id]['claimed_by']    = null;
        $this->rows[$id]['last_error']    = null;
        $this->rows[$id]['dispatched_at'] = $at;

        $this->log[] = "dispatched:{$id}";
    }

    public function release(int $id, int $attempts, string $error, bool $park): void
    {
        $this->rows[$id]['status']     = $park ? OutboxStatus::Failed->value : OutboxStatus::Pending->value;
        $this->rows[$id]['attempts']   = $attempts;
        $this->rows[$id]['last_error'] = mb_substr($error, 0, 1000);
        $this->rows[$id]['claimed_by'] = null;
        $this->rows[$id]['claimed_at'] = null;

        $this->log[] = ($park ? 'parked:' : 'released:') . $id;
    }

    public function reclaimStale(\DateTimeImmutable $cutoff): int
    {
        $reclaimed = 0;

        foreach ($this->rows as $id => $row) {
            if ($row['status'] !== OutboxStatus::Claimed->value) {
                continue;
            }

            if (!$row['claimed_at'] instanceof \DateTimeImmutable || $row['claimed_at'] >= $cutoff) {
                continue;
            }

            $this->rows[$id]['status']     = OutboxStatus::Pending->value;
            $this->rows[$id]['attempts']++;
            $this->rows[$id]['claimed_by'] = null;
            $this->rows[$id]['claimed_at'] = null;
            $this->rows[$id]['last_error'] = 'reclaimed: the relay that claimed this row never settled it';

            $reclaimed++;
        }

        if ($reclaimed > 0) {
            $this->log[] = "reclaimed:{$reclaimed}";
        }

        return $reclaimed;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];
        foreach (OutboxStatus::cases() as $status) {
            $counts[$status->label()] = 0;
        }

        foreach ($this->rows as $row) {
            $status = OutboxStatus::tryFrom((int) $row['status']);

            if ($status !== null) {
                $counts[$status->label()]++;
            }
        }

        return $counts;
    }

    public function retryFailed(int $limit): int
    {
        $released = 0;

        foreach ($this->rows as $id => $row) {
            if ($released >= $limit) {
                break;
            }

            if ($row['status'] !== OutboxStatus::Failed->value) {
                continue;
            }

            $this->rows[$id]['status']     = OutboxStatus::Pending->value;
            $this->rows[$id]['attempts']   = 0;
            $this->rows[$id]['last_error'] = null;

            $released++;
        }

        return $released;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $deleted = 0;

        foreach ($this->rows as $id => $row) {
            $status = OutboxStatus::tryFrom((int) $row['status']);

            if ($status === null || !$status->isSettled()) {
                continue;
            }

            if ($row['occurred_at'] instanceof \DateTimeImmutable && $row['occurred_at'] < $before) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function describe(): string
    {
        return 'in-memory outbox';
    }

    // ── test helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function row(int $id): array
    {
        return $this->rows[$id] ?? [];
    }

    public function statusOf(int $id): ?OutboxStatus
    {
        return OutboxStatus::tryFrom((int) ($this->rows[$id]['status'] ?? -1));
    }

    /** Put a pending row in directly, bypassing write()'s transaction guard. */
    public function seed(string $name, array $payload = [], int $attempts = 0, string $version = '1.0'): int
    {
        $id = $this->nextId++;

        $this->rows[$id] = [
            'id'            => $id,
            'event_id'      => 'evt-' . $id,
            'event_name'    => $name,
            'event_version' => $version,
            'payload'       => json_encode($payload, JSON_THROW_ON_ERROR),
            'status'        => OutboxStatus::Pending->value,
            'attempts'      => $attempts,
            'last_error'    => null,
            'occurred_at'   => new \DateTimeImmutable('2026-01-01 00:00:00'),
            'claimed_by'    => null,
            'claimed_at'    => null,
        ];

        return $id;
    }

    /** Put a row in that is already claimed, as a dead relay would leave it. */
    public function seedClaimed(string $name, \DateTimeImmutable $claimedAt): int
    {
        $id = $this->seed($name);

        $this->rows[$id]['status']     = OutboxStatus::Claimed->value;
        $this->rows[$id]['claimed_by'] = 'dead-relay:1';
        $this->rows[$id]['claimed_at'] = $claimedAt;

        return $id;
    }

    /** Put a row in with a payload that is not decodable JSON. */
    public function seedCorrupt(string $name): int
    {
        $id = $this->seed($name);

        $this->rows[$id]['payload'] = '{"truncated":';

        return $id;
    }
}
