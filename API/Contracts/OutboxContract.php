<?php

declare(strict_types=1);

namespace Plugins\Outbox\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\Domain\RelayReport;

/**
 * A transactional outbox any plugin can write to.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHAT IT IS FOR
 * ──────────────────────────────────────────────────────────────────────────
 * Committing a state change and telling the rest of the system about it are
 * two different operations against two different systems, and there is no
 * atomic way to do both. Dispatch before the commit and a rollback leaves
 * consumers acting on a change that never happened; dispatch after it and a
 * crash in between loses the notification with the database perfectly
 * consistent and nobody aware anything is missing.
 *
 * So the event is written as a ROW, inside the same transaction as the state
 * change, and a relay delivers it afterwards. The two commit or roll back
 * together because they are the same commit.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE ONE THING THAT MAKES IT WORK, AND THE ONE WAY IT SILENTLY DOES NOT
 * ──────────────────────────────────────────────────────────────────────────
 * "The same transaction" means THE SAME CONNECTION. The Database plugin binds
 * `DatabasePort` as a TRANSIENT: every `make()` hands back a new adapter with
 * a new PDO handle. So a caller whose transaction runs on connection A, and an
 * outbox that resolved connection B, produce a row that is not in the
 * transaction at all — the caller rolls back and the event is delivered
 * anyway, or the caller commits and the row was already visible before it.
 *
 * Nothing about that looks wrong. It is also wiring-dependent: the kernel's own
 * `withPorts([...])` path uses `instance()` and IS shared, so it works in the
 * default setup and breaks under the per-request rebinding that tenancy needs.
 *
 * Two things address it, and both matter:
 *
 *   {@see on()} lets a caller hand over the exact connection its transaction
 *   is on, so the question is answered at wiring time instead of hoped for.
 *
 *   {@see write()} REFUSES when its own connection has no open transaction.
 *   That turns the silent case into an immediate, explicit failure — because a
 *   caller inside a transaction on a connection the outbox does not share sees
 *   exactly that: no transaction on this connection.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHAT IT DOES NOT PROMISE
 * ──────────────────────────────────────────────────────────────────────────
 * Delivery is AT-LEAST-ONCE. Consumers MUST dedupe on the event id, which is
 * why it is returned. There is NO ordering guarantee: a row that fails is
 * retried later while the rows behind it go out now, so a consumer that needs
 * ordering must get it from the payload.
 *
 * "Dispatched" means handed to the `EventBus` and accepted by every listener
 * that was subscribed — INCLUDING when that is none at all. An event with no
 * subscriber is marked dispatched, because the outbox's job is to deliver it to
 * the bus and not to have opinions about who should have been listening.
 */
interface OutboxContract
{
    /**
     * The same outbox over a specific connection.
     *
     * Call this at wiring time with the connection your transaction runs on:
     *
     *     $container->bindInternal(
     *         ThingRepository::class,
     *         static fn ($c) => new ThingRepository(
     *             $c->make(RequestConnection::class)->db,
     *             $c->make(OutboxContract::class)->on($c->make(RequestConnection::class)->db),
     *         ),
     *     );
     *
     * Returns a NEW instance; the one it came from is unchanged, so this is
     * safe to call per binding and under OpenSwoole.
     */
    public function on(DatabasePort $connection): self;

    /**
     * Enqueue an event inside the CALLER'S open transaction.
     *
     * @param string|null $eventId Supply one to make the enqueue idempotent:
     *        writing the same id twice is a no-op rather than a duplicate, and
     *        rather than a unique-constraint violation that would abort the
     *        caller's transaction. Omit it for a fresh UUID.
     *
     * @return string the event id — the key consumers dedupe on.
     *
     * @throws \Plugins\Outbox\Infrastructure\Exceptions\OutboxException when
     *         this outbox's connection has no open transaction, which is both
     *         "you forgot to begin one" and "you are on a different one".
     */
    public function write(IntegrationEventContract $event, ?string $eventId = null): string;

    /**
     * Deliver owed rows to the `EventBus`.
     *
     * Safe to run concurrently: rows are claimed one at a time and a relay that
     * loses the race skips the row rather than double-delivering it.
     *
     * @param int|null $limit how many rows to attempt; null uses the configured
     *        batch size.
     */
    public function relay(?int $limit = null): RelayReport;

    /**
     * How many rows sit in each status.
     *
     * @return array<string, int> every status present, even at zero.
     */
    public function counts(): array;

    /**
     * Put parked rows back in the retry path, attempts reset.
     *
     * The operator action after the cause of a failure has been fixed.
     *
     * @return int how many were released.
     */
    public function retryFailed(?int $limit = null): int;

    /**
     * Delete settled rows older than the retention window.
     *
     * Only `dispatched` and `failed` rows are eligible. A table nobody prunes
     * grows for the lifetime of the application and the relay's index scan
     * grows with it.
     *
     * @return int how many were deleted.
     */
    public function prune(?int $olderThanDays = null): int;

    /** One line for status output. Never a credential or a DSN. */
    public function describe(): string;
}
