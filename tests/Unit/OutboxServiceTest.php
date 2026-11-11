<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Unit;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Outbox\Application\Services\OutboxService;
use Plugins\Outbox\Domain\OutboxEntry;
use Plugins\Outbox\Domain\OutboxStatus;
use Plugins\Outbox\Domain\RelayReport;
use Plugins\Outbox\Infrastructure\Config\OutboxConfig;
use Plugins\Outbox\Infrastructure\Events\RelayedEvent;
use Plugins\Outbox\Infrastructure\Exceptions\OutboxException;
use Tests\Plugins\Outbox\Support\InMemoryOutboxStore;
use Tests\Plugins\Outbox\Support\RecordingListener;
use Tests\Plugins\Outbox\Support\TestEvent;
use Tests\Plugins\Outbox\Support\ThrowingListener;

/**
 * The delivery policy.
 *
 * The EventBus here is the REAL one, never a double, because the single most
 * expensive bug this plugin inherits is about the bus's return value: it
 * isolates a listener failure and returns normally, and an outbox that reads
 * that as success loses the event permanently while recording a clean
 * delivery. A fake bus would be written to whatever this code expects and
 * could not catch that.
 */
#[CoversClass(OutboxService::class)]
#[CoversClass(OutboxConfig::class)]
#[CoversClass(OutboxEntry::class)]
#[CoversClass(OutboxStatus::class)]
#[CoversClass(RelayReport::class)]
#[CoversClass(RelayedEvent::class)]
#[CoversClass(OutboxException::class)]
final class OutboxServiceTest extends TestCase
{
    private InMemoryOutboxStore $store;
    private EventBus $bus;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        RecordingListener::reset();
        ThrowingListener::reset();

        $this->store = new InMemoryOutboxStore();
        $this->bus   = new EventBus(new CoreContainer());
        $this->clock = new FrozenClock('2026-11-11 12:00:00');
    }

    private function service(?OutboxConfig $config = null): OutboxService
    {
        return new OutboxService(
            store:  $this->store,
            bus:    $this->bus,
            config: $config ?? OutboxConfig::of(),
            clock:  $this->clock,
        );
    }

    // ── The transactional guarantee ───────────────────────────────────────────

    public function test_write_refuses_when_no_transaction_is_open_on_its_own_connection(): void
    {
        // THE central property, and the one that makes "transactional" a claim
        // rather than a hope. The second cause — a transaction open on a
        // DIFFERENT connection, because DatabasePort is bound as a transient —
        // presents here identically, which is the whole reason for the check.
        $this->store->inTransaction = false;

        $this->expectException(OutboxException::class);

        $this->service()->write(new TestEvent('user.registered'));
    }

    public function test_the_refusal_names_both_causes_and_the_fix(): void
    {
        // An operator reading this message has to be able to tell which of the
        // two it is, because the fixes are completely different and the
        // symptoms are identical.
        $this->store->inTransaction = false;

        try {
            $this->service()->write(new TestEvent('user.registered'));
            self::fail('expected a refusal');
        } catch (OutboxException $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('No transaction was begun', $message);
            self::assertStringContainsString('DIFFERENT connection', $message);
            self::assertStringContainsString('TRANSIENT', $message);
            self::assertStringContainsString('->on($yourDatabasePort)', $message);
            self::assertStringContainsString('user.registered', $message);
        }
    }

    public function test_nothing_is_written_when_the_guard_refuses(): void
    {
        $this->store->inTransaction = false;

        try {
            $this->service()->write(new TestEvent());
        } catch (OutboxException) {
            // expected
        }

        self::assertSame([], $this->store->rows, 'the guard must refuse BEFORE writing');
    }

    public function test_write_stores_the_event_and_returns_its_id(): void
    {
        $id = $this->service()->write(new TestEvent('invoice.paid', ['amount' => 1250], '2.0'));

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'the id is the consumer-side idempotency key, so it has to be a real UUID',
        );

        $row = $this->store->row(1);
        self::assertSame('invoice.paid', $row['event_name']);
        self::assertSame('2.0', $row['event_version']);
        self::assertSame($id, $row['event_id']);
        self::assertSame(['amount' => 1250], json_decode((string) $row['payload'], true));
        self::assertSame(OutboxStatus::Pending, $this->store->statusOf(1));
    }

    public function test_a_supplied_event_id_makes_the_enqueue_idempotent(): void
    {
        // A duplicate must NOT be a constraint violation: write() runs inside
        // the CALLER'S transaction, so a throw here would abort their work over
        // a duplicate the outbox is perfectly able to ignore.
        $service = $this->service();

        $first  = $service->write(new TestEvent('user.registered'), 'fixed-id');
        $second = $service->write(new TestEvent('user.registered'), 'fixed-id');

        self::assertSame('fixed-id', $first);
        self::assertSame('fixed-id', $second);
        self::assertCount(1, $this->store->rows, 'the second write is a no-op, not a second row');
        self::assertContains('write:duplicate', $this->store->log);
    }

    public function test_an_unencodable_payload_is_reported_as_the_events_fault(): void
    {
        // Not as a database error. The payload is what is broken, and an
        // integration event is required to carry primitives only.
        $event = new TestEvent('bad.event', ['handle' => fopen('php://memory', 'rb')]);

        try {
            $this->service()->write($event);
            self::fail('expected a refusal');
        } catch (OutboxException $e) {
            self::assertStringContainsString('cannot be encoded as JSON', $e->getMessage());
            self::assertStringContainsString('primitives only', $e->getMessage());
        }

        self::assertSame([], $this->store->rows);
    }

    // ── Relaying ──────────────────────────────────────────────────────────────

    public function test_a_pending_event_is_dispatched_and_marked(): void
    {
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $id = $this->store->seed('user.registered', ['userId' => 'u-1']);

        $report = $this->service()->relay();

        self::assertSame(['user.registered'], RecordingListener::names());
        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($id));
        self::assertSame(1, $report->dispatched);
        self::assertSame(0, $report->failed);
    }

    public function test_the_row_is_claimed_before_it_is_dispatched(): void
    {
        // Order matters: dispatching first and claiming after would let a second
        // relay dispatch the same row in between.
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $this->store->seed('user.registered');

        $this->service()->relay();

        self::assertSame(['claim:1', 'dispatched:1'], $this->store->log);
    }

    public function test_the_event_id_travels_to_the_consumer_in_the_payload(): void
    {
        // At-least-once delivery is only safe if consumers can dedupe, and they
        // can only dedupe on something they actually receive.
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $this->store->seed('user.registered', ['userId' => 'u-1']);

        $this->service()->relay();

        $payload = RecordingListener::$received[0]->payload();
        self::assertSame('evt-1', $payload[RelayedEvent::ID_KEY]);
        self::assertSame('u-1', $payload['userId'], 'the domain payload is untouched');
    }

    public function test_a_producer_supplied_envelope_id_is_not_overwritten(): void
    {
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $this->store->seed('user.registered', [RelayedEvent::ID_KEY => 'producer-chose-this']);

        $this->service()->relay();

        self::assertSame(
            'producer-chose-this',
            RecordingListener::$received[0]->payload()[RelayedEvent::ID_KEY],
        );
    }

    public function test_relay_takes_the_oldest_rows_first(): void
    {
        $this->bus->subscribe('a.one', RecordingListener::class);
        $this->bus->subscribe('b.two', RecordingListener::class);
        $this->store->seed('a.one');
        $this->store->seed('b.two');

        $this->service()->relay();

        self::assertSame(['a.one', 'b.two'], RecordingListener::names());
    }

    public function test_an_event_with_no_subscriber_is_still_marked_dispatched(): void
    {
        // Documented behaviour, not an oversight: the outbox's job is to hand
        // the event to the bus, not to have opinions about who should have been
        // listening. An operator debugging "the webhook never fired" will see
        // status=dispatched, which is why the README says so.
        $id = $this->store->seed('nobody.listens');

        $report = $this->service()->relay();

        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($id));
        self::assertSame(1, $report->dispatched);
    }

    // ── A listener that throws is NOT a delivery ───────────────────────────────

    public function test_a_throwing_listener_does_not_mark_the_row_dispatched(): void
    {
        // THE PRODUCTION INCIDENT. EventBus isolates the failure and returns
        // normally; reading that as success marked the row dispatched, never
        // retried it, and lost the work for good — with
        // `status=1, attempts=1, last_error=NULL` recording a clean delivery.
        $this->bus->subscribe('user.registered', ThrowingListener::class);
        $id = $this->store->seed('user.registered');

        $report = $this->service()->relay();

        self::assertSame(1, ThrowingListener::$calls, 'the listener did run');
        self::assertSame(
            OutboxStatus::Pending,
            $this->store->statusOf($id),
            'it must be back in the retry path, NOT dispatched',
        );
        self::assertSame(0, $report->dispatched);
        self::assertSame(1, $report->failed);
        self::assertSame(1, $this->store->row($id)['attempts']);
    }

    public function test_the_failure_reason_names_the_listener_and_its_message(): void
    {
        // This lands in last_error, and EventBus's logger is OPTIONAL — so with
        // none bound this row is the ONLY record of what went wrong.
        $this->bus->subscribe('user.registered', ThrowingListener::class);
        $id = $this->store->seed('user.registered');

        $this->service()->relay();

        $error = (string) $this->store->row($id)['last_error'];
        self::assertStringContainsString(ThrowingListener::class, $error);
        self::assertStringContainsString(ThrowingListener::MESSAGE, $error);
    }

    public function test_a_failing_row_is_retried_until_it_is_parked(): void
    {
        $this->bus->subscribe('user.registered', ThrowingListener::class);
        $id      = $this->store->seed('user.registered');
        $service = $this->service(OutboxConfig::of(maxAttempts: 3));

        $service->relay();
        self::assertSame(OutboxStatus::Pending, $this->store->statusOf($id), 'attempt 1');

        $service->relay();
        self::assertSame(OutboxStatus::Pending, $this->store->statusOf($id), 'attempt 2');

        $report = $service->relay();
        self::assertSame(OutboxStatus::Failed, $this->store->statusOf($id), 'parked on attempt 3');
        self::assertSame(1, $report->parked);
        self::assertSame(3, $this->store->row($id)['attempts']);
    }

    public function test_a_parked_row_is_not_picked_up_again(): void
    {
        // Parking is what stops a broken consumer being retried forever. A
        // parked row that quietly re-entered the batch would make the attempt
        // budget meaningless.
        $this->bus->subscribe('user.registered', ThrowingListener::class);
        $this->store->seed('user.registered');
        $service = $this->service(OutboxConfig::of(maxAttempts: 1));

        $service->relay();
        ThrowingListener::reset();

        $report = $service->relay();

        self::assertSame(0, ThrowingListener::$calls);
        self::assertTrue($report->isIdle());
    }

    public function test_one_failing_row_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->bus->subscribe('breaks', ThrowingListener::class);
        $this->bus->subscribe('works', RecordingListener::class);
        $this->store->seed('breaks');
        $good = $this->store->seed('works');

        $report = $this->service()->relay();

        self::assertSame(['works'], RecordingListener::names());
        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($good));
        self::assertSame(1, $report->dispatched);
        self::assertSame(1, $report->failed);
    }

    public function test_a_corrupt_payload_parks_only_its_own_row(): void
    {
        // Decoding at the edge of the batch would throw while the batch was
        // being assembled and take every good row with it — and the failure
        // would read as "the outbox stopped" rather than "one row is corrupt".
        $this->bus->subscribe('works', RecordingListener::class);
        $corrupt = $this->store->seedCorrupt('corrupt.row');
        $good    = $this->store->seed('works');

        $report = $this->service()->relay();

        self::assertSame(OutboxStatus::Pending, $this->store->statusOf($corrupt));
        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($good));
        self::assertSame(1, $report->dispatched);
        self::assertSame(1, $report->failed);
    }

    // ── Concurrency ───────────────────────────────────────────────────────────

    public function test_a_row_claimed_by_another_relay_is_skipped_not_dispatched(): void
    {
        // The difference between "concurrent relays are safe" and "every
        // consumer gets two copies of everything".
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $lost = $this->store->seed('user.registered');
        $mine = $this->store->seed('user.registered');
        $this->store->claimsLostFor = [$lost];

        $report = $this->service()->relay();

        self::assertCount(1, RecordingListener::$received, 'only the row this relay won');
        self::assertSame(1, $report->lost);
        self::assertSame(1, $report->dispatched);
        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($mine));
    }

    public function test_a_row_a_dead_relay_left_claimed_is_reclaimed(): void
    {
        // The claim is a LEASE. Without this the mechanism that makes
        // concurrency safe would also be a permanent leak, and a row stuck in
        // `claimed` would never be delivered by anything.
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $id = $this->store->seedClaimed('user.registered', new \DateTimeImmutable('2026-11-11 11:00:00'));

        // Claimed an hour ago; the lease is five minutes.
        $report = $this->service(OutboxConfig::of(claimTimeout: 300))->relay();

        self::assertSame(1, $report->reclaimed);
        self::assertSame(1, $report->dispatched, 'and delivered in the same run');
        self::assertSame(OutboxStatus::Dispatched, $this->store->statusOf($id));
    }

    public function test_a_freshly_claimed_row_is_left_alone(): void
    {
        // A relay that is working right now holds claims. Reclaiming those would
        // be the double delivery the claim exists to prevent.
        $this->bus->subscribe('user.registered', RecordingListener::class);
        $id = $this->store->seedClaimed('user.registered', new \DateTimeImmutable('2026-11-11 11:59:30'));

        $report = $this->service(OutboxConfig::of(claimTimeout: 300))->relay();

        self::assertSame(0, $report->reclaimed);
        self::assertSame(0, $report->dispatched);
        self::assertSame(OutboxStatus::Claimed, $this->store->statusOf($id));
    }

    public function test_a_reclaim_costs_an_attempt(): void
    {
        // Deliberate, and it has a cost: a relay killed BY a particular row
        // would otherwise reclaim and retry it forever, and the loop would look
        // like an outbox that works.
        $id = $this->store->seedClaimed('user.registered', new \DateTimeImmutable('2026-11-11 10:00:00'));
        $this->store->claimsLostFor = [$id]; // keep it out of this run's delivery

        $this->service()->relay();

        self::assertSame(1, $this->store->row($id)['attempts']);
    }

    // ── Operator surface ──────────────────────────────────────────────────────

    public function test_an_empty_outbox_reports_idle(): void
    {
        self::assertTrue($this->service()->relay()->isIdle());
    }

    public function test_counts_report_every_status_even_at_zero(): void
    {
        // So a caller never has to tell "none" from "not reported", and a
        // dashboard shows a 0 rather than a gap.
        self::assertSame(
            ['pending' => 0, 'dispatched' => 0, 'failed' => 0, 'claimed' => 0],
            $this->service()->counts(),
        );
    }

    public function test_retry_puts_parked_rows_back_with_attempts_reset(): void
    {
        $this->bus->subscribe('user.registered', ThrowingListener::class);
        $id = $this->store->seed('user.registered');
        $service = $this->service(OutboxConfig::of(maxAttempts: 1));

        $service->relay();
        self::assertSame(OutboxStatus::Failed, $this->store->statusOf($id));

        $released = $service->retryFailed();

        self::assertSame(1, $released);
        self::assertSame(OutboxStatus::Pending, $this->store->statusOf($id));
        self::assertSame(0, $this->store->row($id)['attempts'], 'the budget is restored, not continued');
    }

    public function test_describe_carries_the_tuning_and_no_credential(): void
    {
        $described = $this->service()->describe();

        self::assertStringContainsString('in-memory outbox', $described);
        self::assertStringContainsString('max 10 attempts', $described);
        self::assertStringNotContainsStringIgnoringCase('password', $described);
        self::assertStringNotContainsStringIgnoringCase('dsn', $described);
    }

    // ── on() ──────────────────────────────────────────────────────────────────

    public function test_on_returns_a_new_service_and_leaves_the_original_alone(): void
    {
        // The caller hands over the connection its transaction runs on. If this
        // mutated or returned $this, a second consumer wiring its own connection
        // would silently repoint the first one's.
        $service = $this->service();
        $other   = $service->on(new \Tests\Plugins\Outbox\Support\NullDatabase());

        self::assertNotSame($service, $other);
    }

    // ── Config ────────────────────────────────────────────────────────────────

    public function test_the_relay_limit_is_clamped_on_every_construction_path(): void
    {
        // The batch size reaches SQL as an inlined integer, so the clamp is what
        // makes that safe — and it has to hold wherever the config is built.
        self::assertSame(1, OutboxConfig::of(relayLimit: 0)->relayLimit);
        self::assertSame(1, OutboxConfig::of(relayLimit: -5)->relayLimit);
        self::assertSame(1000, OutboxConfig::of(relayLimit: 999_999)->relayLimit);
        self::assertSame(100, OutboxConfig::of()->relayLimit);
    }

    public function test_at_most_the_limit_is_attempted(): void
    {
        $this->bus->subscribe('user.registered', RecordingListener::class);

        for ($i = 0; $i < 5; $i++) {
            $this->store->seed('user.registered');
        }

        $report = $this->service()->relay(limit: 2);

        self::assertSame(2, $report->dispatched);
        self::assertCount(2, RecordingListener::$received);
    }
}
