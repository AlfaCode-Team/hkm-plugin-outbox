<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Integration;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Outbox\Application\Services\OutboxService;
use Plugins\Outbox\Domain\OutboxStatus;
use Plugins\Outbox\Infrastructure\Config\OutboxConfig;
use Plugins\Outbox\Infrastructure\Exceptions\OutboxException;
use Plugins\Outbox\Infrastructure\Persistence\OutboxRepository;
use Tests\Plugins\Outbox\Support\RecordingListener;
use Tests\Plugins\Outbox\Support\TestEvent;
use Tests\Plugins\Outbox\Support\ThrowingListener;

/**
 * The real SQL, against the schema this plugin's own migration produces.
 *
 * The unit suite drives the delivery policy over an array and can say nothing
 * about whether these statements run: an in-memory double accepts a query
 * naming a column that does not exist, and it cannot reproduce the thing this
 * design actually rests on — that `UPDATE … WHERE id = :id AND status = 0`
 * reports exactly ONE affected row to exactly ONE caller.
 *
 * `MigratedSqlite` comes from `alfacode-team/ground`, so there is no local copy
 * of it here.
 */
#[CoversClass(OutboxRepository::class)]
#[CoversClass(OutboxService::class)]
final class OutboxRepositoryTest extends TestCase
{
    private ?MigratedSqlite $db = null;
    private OutboxRepository $store;

    protected function setUp(): void
    {
        RecordingListener::reset();
        ThrowingListener::reset();

        $this->db    = MigratedSqlite::migrated(\dirname(__DIR__, 2) . '/database/migrations', 'outbox');
        $this->store = new OutboxRepository($this->db);
    }

    protected function tearDown(): void
    {
        // Guarded: a failure in setUp still runs tearDown, and an unguarded
        // access would replace the real error with "must not be accessed before
        // initialization".
        $this->db?->discard();
        $this->db = null;
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    private function seed(string $name, string $eventId, string $occurredAt = '2026-01-01 00:00:00'): int
    {
        $this->store->write($eventId, $name, '1.0', '{"ok":true}', $this->at($occurredAt));

        $row = $this->db?->queryOne(
            'SELECT id FROM event_outbox WHERE event_id = :id',
            ['id' => $eventId],
        );

        return (int) ($row['id'] ?? 0);
    }

    // ── The schema ────────────────────────────────────────────────────────────

    public function test_the_migration_applies_and_the_table_has_the_columns_the_sql_names(): void
    {
        // If this fails, every other test in this file is failing for the wrong
        // reason — so it asserts the schema directly rather than inferring it.
        $columns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $this->db?->query('PRAGMA table_info(event_outbox)') ?? [],
        );

        foreach ([
            'id', 'event_id', 'event_name', 'event_version', 'payload', 'status',
            'attempts', 'last_error', 'occurred_at', 'claimed_by', 'claimed_at',
            'dispatched_at', 'created_at',
        ] as $expected) {
            self::assertContains($expected, $columns, "the migration must create [{$expected}]");
        }
    }

    public function test_the_event_id_is_unique_in_the_schema_not_only_in_the_code(): void
    {
        // write() relies on insert-if-absent, which needs a real unique index to
        // conflict against. Without the index the ON CONFLICT target is invalid
        // and duplicates insert silently.
        $indexes = array_map(
            static fn (array $i): string => (string) $i['name'],
            $this->db?->query('PRAGMA index_list(event_outbox)') ?? [],
        );

        self::assertContains('uniq_outbox_event', $indexes);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    public function test_a_write_lands_as_a_pending_row(): void
    {
        $id = $this->seed('user.registered', 'evt-1');

        $row = $this->db?->queryOne('SELECT * FROM event_outbox WHERE id = :id', ['id' => $id]);

        self::assertSame('user.registered', $row['event_name']);
        self::assertSame(OutboxStatus::Pending->value, (int) $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertNull($row['dispatched_at']);
        self::assertNull($row['claimed_by']);
    }

    public function test_writing_the_same_event_id_twice_does_not_throw_and_does_not_duplicate(): void
    {
        // Against REAL SQL, which is the only place this can be established:
        // the whole point is that the unique index does NOT raise a constraint
        // violation here, because this runs inside the caller's transaction and
        // a throw would abort their work.
        $first  = $this->store->write('evt-1', 'user.registered', '1.0', '{}', $this->at('2026-01-01 00:00:00'));
        $second = $this->store->write('evt-1', 'user.registered', '1.0', '{}', $this->at('2026-01-01 00:00:00'));

        self::assertTrue($first, 'the first write inserted');
        self::assertFalse($second, 'the second was an idempotent no-op');

        $total = $this->db?->queryOne('SELECT COUNT(*) AS n FROM event_outbox');
        self::assertSame(1, (int) $total['n']);
    }

    // ── Claiming: the property the whole design rests on ──────────────────────

    public function test_only_one_of_two_relays_can_claim_the_same_row(): void
    {
        // THE headline property, and it cannot be tested without a real
        // database: two stores, two independent claims, one row. The predicate
        // `AND status = 0` is applied atomically, so exactly one UPDATE reports
        // an affected row.
        $id    = $this->seed('user.registered', 'evt-1');
        $rival = new OutboxRepository($this->db);

        $mine   = $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));
        $theirs = $rival->claim($id, 'relay-b', $this->at('2026-01-01 00:00:10'));

        self::assertTrue($mine, 'the first claim wins');
        self::assertFalse($theirs, 'the second must NOT be allowed to dispatch the same row');

        $row = $this->db?->queryOne('SELECT status, claimed_by FROM event_outbox WHERE id = :id', ['id' => $id]);
        self::assertSame(OutboxStatus::Claimed->value, (int) $row['status']);
        self::assertSame('relay-a', $row['claimed_by']);
    }

    public function test_claiming_a_row_that_does_not_exist_is_false_not_an_error(): void
    {
        self::assertFalse($this->store->claim(99999, 'relay-a', $this->at('2026-01-01 00:00:00')));
    }

    public function test_a_claimed_row_is_no_longer_pending(): void
    {
        // This is what keeps it out of every other relay's batch — the claim is
        // only useful if the read query excludes it.
        $id = $this->seed('user.registered', 'evt-1');

        $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));

        self::assertSame([], $this->store->pending(100));
    }

    // ── Settling ──────────────────────────────────────────────────────────────

    public function test_mark_dispatched_settles_a_claimed_row(): void
    {
        $id = $this->seed('user.registered', 'evt-1');
        $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));

        $this->store->markDispatched($id, $this->at('2026-01-01 00:00:11'));

        $row = $this->db?->queryOne('SELECT * FROM event_outbox WHERE id = :id', ['id' => $id]);
        self::assertSame(OutboxStatus::Dispatched->value, (int) $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['dispatched_at']);
        self::assertNull($row['claimed_by']);
    }

    public function test_mark_dispatched_refuses_a_row_this_relay_does_not_hold(): void
    {
        // The `AND status = :claimed` predicate. A stale reference must not
        // overwrite a row another relay has already reclaimed and redelivered.
        $id = $this->seed('user.registered', 'evt-1');

        $this->store->markDispatched($id, $this->at('2026-01-01 00:00:11'));

        $row = $this->db?->queryOne('SELECT status FROM event_outbox WHERE id = :id', ['id' => $id]);
        self::assertSame(
            OutboxStatus::Pending->value,
            (int) $row['status'],
            'a pending row must not be settleable without claiming it first',
        );
    }

    public function test_release_puts_a_row_back_with_its_error(): void
    {
        $id = $this->seed('user.registered', 'evt-1');
        $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));

        $this->store->release($id, 1, 'the listener said no', park: false);

        $row = $this->db?->queryOne('SELECT * FROM event_outbox WHERE id = :id', ['id' => $id]);
        self::assertSame(OutboxStatus::Pending->value, (int) $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('the listener said no', $row['last_error']);
        self::assertNull($row['claimed_by'], 'the lease is given up with the row');
    }

    public function test_release_can_park_a_row_out_of_the_retry_path(): void
    {
        $id = $this->seed('user.registered', 'evt-1');
        $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));

        $this->store->release($id, 10, 'gave up', park: true);

        self::assertSame([], $this->store->pending(100), 'a parked row is not picked up again');
        self::assertSame(1, $this->store->counts()['failed']);
    }

    public function test_a_long_error_is_truncated_rather_than_failing_the_write(): void
    {
        // A listener exception can embed a whole response body, and a column
        // overflow would turn a reportable failure into an unreportable one.
        $id = $this->seed('user.registered', 'evt-1');
        $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));

        $this->store->release($id, 1, str_repeat('x', 5000), park: false);

        $row = $this->db?->queryOne('SELECT last_error FROM event_outbox WHERE id = :id', ['id' => $id]);
        self::assertSame(1000, mb_strlen((string) $row['last_error']));
    }

    // ── The lease ─────────────────────────────────────────────────────────────

    public function test_a_stale_claim_is_reclaimed_and_a_fresh_one_is_not(): void
    {
        $stale = $this->seed('user.registered', 'evt-stale');
        $fresh = $this->seed('user.registered', 'evt-fresh');

        $this->store->claim($stale, 'dead-relay', $this->at('2026-01-01 00:00:00'));
        $this->store->claim($fresh, 'live-relay', $this->at('2026-01-01 01:00:00'));

        $reclaimed = $this->store->reclaimStale($this->at('2026-01-01 00:30:00'));

        self::assertSame(1, $reclaimed);

        $staleRow = $this->db?->queryOne('SELECT * FROM event_outbox WHERE id = :id', ['id' => $stale]);
        self::assertSame(OutboxStatus::Pending->value, (int) $staleRow['status']);
        self::assertSame(1, (int) $staleRow['attempts'], 'a reclaim costs an attempt');
        self::assertNull($staleRow['claimed_at']);

        $freshRow = $this->db?->queryOne('SELECT status FROM event_outbox WHERE id = :id', ['id' => $fresh]);
        self::assertSame(
            OutboxStatus::Claimed->value,
            (int) $freshRow['status'],
            'a relay that is working right now must keep its claims',
        );
    }

    // ── Operator surface ──────────────────────────────────────────────────────

    public function test_counts_report_each_status_with_zeros_present(): void
    {
        $a = $this->seed('a', 'evt-a');
        $b = $this->seed('b', 'evt-b');
        $this->store->claim($b, 'relay-a', $this->at('2026-01-01 00:00:10'));
        $this->store->markDispatched($b, $this->at('2026-01-01 00:00:11'));

        self::assertSame(
            ['pending' => 1, 'dispatched' => 1, 'failed' => 0, 'claimed' => 0],
            $this->store->counts(),
        );
        self::assertGreaterThan(0, $a);
    }

    public function test_retry_failed_releases_parked_rows_and_respects_the_limit(): void
    {
        foreach (['x', 'y', 'z'] as $n) {
            $id = $this->seed($n, 'evt-' . $n);
            $this->store->claim($id, 'relay-a', $this->at('2026-01-01 00:00:10'));
            $this->store->release($id, 10, 'gave up', park: true);
        }

        $released = $this->store->retryFailed(2);

        self::assertSame(2, $released);
        self::assertSame(['pending' => 2, 'dispatched' => 0, 'failed' => 1, 'claimed' => 0], $this->store->counts());

        $row = $this->db?->queryOne('SELECT attempts FROM event_outbox WHERE status = 0 LIMIT 1');
        self::assertSame(0, (int) $row['attempts'], 'the attempt budget is restored');
    }

    public function test_prune_deletes_settled_rows_and_never_an_owed_one(): void
    {
        // The single thing this plugin exists to prevent is losing an event, so
        // the one row prune must never touch is a row still owed.
        $old = $this->seed('old', 'evt-old', '2026-01-01 00:00:00');
        $this->store->claim($old, 'relay-a', $this->at('2026-01-01 00:00:10'));
        $this->store->markDispatched($old, $this->at('2026-01-01 00:00:11'));

        $oldButPending  = $this->seed('pending', 'evt-pending', '2026-01-01 00:00:00');
        $oldButClaimed  = $this->seed('claimed', 'evt-claimed', '2026-01-01 00:00:00');
        $this->store->claim($oldButClaimed, 'relay-a', $this->at('2026-01-01 00:00:10'));

        $deleted = $this->store->prune($this->at('2026-06-01 00:00:00'));

        self::assertSame(1, $deleted);
        self::assertSame(
            ['pending' => 1, 'dispatched' => 0, 'failed' => 0, 'claimed' => 1],
            $this->store->counts(),
        );
        self::assertGreaterThan(0, $oldButPending);
    }

    public function test_prune_leaves_settled_rows_inside_the_window(): void
    {
        $id = $this->seed('recent', 'evt-recent', '2026-11-11 00:00:00');
        $this->store->claim($id, 'relay-a', $this->at('2026-11-11 00:00:10'));
        $this->store->markDispatched($id, $this->at('2026-11-11 00:00:11'));

        self::assertSame(0, $this->store->prune($this->at('2026-06-01 00:00:00')));
        self::assertSame(1, $this->store->counts()['dispatched']);
    }

    public function test_prune_on_an_empty_table_is_zero_not_a_syntax_error(): void
    {
        // An empty `IN ()` list is a syntax error on every driver, which is why
        // the id batch falls back to `IN (0)`.
        self::assertSame(0, $this->store->prune($this->at('2026-06-01 00:00:00')));
    }

    public function test_describe_names_the_dialect_and_no_credential(): void
    {
        $described = $this->store->describe();

        self::assertStringContainsString('event_outbox', $described);
        self::assertStringContainsString('sqlite', $described);
        self::assertStringNotContainsStringIgnoringCase('password', $described);
    }

    // ── Ordering and batching ─────────────────────────────────────────────────

    public function test_pending_returns_oldest_first_and_honours_the_limit(): void
    {
        $this->seed('third', 'evt-3', '2026-03-01 00:00:00');
        $this->seed('first', 'evt-1', '2026-01-01 00:00:00');
        $this->seed('second', 'evt-2', '2026-02-01 00:00:00');

        $names = array_map(
            static fn ($e): string => $e->eventName,
            $this->store->pending(2),
        );

        self::assertSame(['first', 'second'], $names);
    }

    // ── End to end, through the service, on a real transaction ────────────────

    public function test_a_rollback_takes_the_outbox_row_with_it(): void
    {
        // THE ATOMICITY PROOF, and the reason this plugin exists. Everything
        // else is bookkeeping around this one property: the event row and the
        // state change are the same commit, so a rollback cannot leave
        // consumers being told about a change that never happened.
        $service = $this->service();

        $this->db?->beginTransaction();
        $service->write(new TestEvent('user.registered'));
        $this->db?->rollback();

        $total = $this->db?->queryOne('SELECT COUNT(*) AS n FROM event_outbox');
        self::assertSame(0, (int) $total['n'], 'the event must roll back with the transaction');

        $this->bus()->subscribe('user.registered', RecordingListener::class);
        self::assertTrue($service->relay()->isIdle());
        self::assertSame([], RecordingListener::$received, 'and nothing may be delivered');
    }

    public function test_a_commit_keeps_the_row_and_the_relay_delivers_it(): void
    {
        $service = $this->service();
        $bus     = $this->bus();
        $bus->subscribe('user.registered', RecordingListener::class);

        $this->db?->beginTransaction();
        $eventId = $service->write(new TestEvent('user.registered', ['userId' => 'u-7']));
        $this->db?->commit();

        $report = $this->serviceWith($bus)->relay();

        self::assertSame(1, $report->dispatched);
        self::assertSame(['user.registered'], RecordingListener::names());
        self::assertSame('u-7', RecordingListener::$received[0]->payload()['userId']);
        self::assertSame($eventId, RecordingListener::$received[0]->payload()['_outbox_event_id']);
    }

    public function test_write_outside_a_transaction_is_refused_against_a_real_connection(): void
    {
        // The guard, proven against a port that genuinely reports its own
        // transaction state rather than a boolean a test set.
        self::assertFalse($this->db?->inTransaction());

        $this->expectException(OutboxException::class);

        $this->service()->write(new TestEvent('user.registered'));
    }

    public function test_a_failing_listener_leaves_the_row_owed_in_the_real_table(): void
    {
        $bus = $this->bus();
        $bus->subscribe('user.registered', ThrowingListener::class);
        $service = $this->serviceWith($bus);

        $this->db?->beginTransaction();
        $service->write(new TestEvent('user.registered'));
        $this->db?->commit();

        $report = $service->relay();

        self::assertSame(0, $report->dispatched);
        self::assertSame(1, $report->failed);

        $row = $this->db?->queryOne('SELECT status, attempts, last_error FROM event_outbox LIMIT 1');
        self::assertSame(OutboxStatus::Pending->value, (int) $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertStringContainsString(ThrowingListener::MESSAGE, (string) $row['last_error']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function bus(): EventBus
    {
        return $this->busInstance ??= new EventBus(new CoreContainer());
    }

    private ?EventBus $busInstance = null;

    private function service(): OutboxService
    {
        return $this->serviceWith($this->bus());
    }

    private function serviceWith(EventBus $bus): OutboxService
    {
        return new OutboxService(
            store:  $this->store,
            bus:    $bus,
            config: OutboxConfig::of(),
            clock:  new FrozenClock('2026-11-11 12:00:00'),
        );
    }
}
