<?php

declare(strict_types=1);

namespace Plugins\Outbox\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\API\Contracts\OutboxContract;
use Plugins\Outbox\Application\Ports\OutboxStore;
use Plugins\Outbox\Domain\RelayReport;
use Plugins\Outbox\Infrastructure\Config\OutboxConfig;
use Plugins\Outbox\Infrastructure\Events\RelayedEvent;
use Plugins\Outbox\Infrastructure\Exceptions\OutboxException;

/**
 * The delivery policy. Consumes the store for persistence and the EventBus for
 * dispatch, and never touches DatabasePort itself.
 */
final class OutboxService implements OutboxContract
{
    public function __construct(
        private readonly OutboxStore $store,
        private readonly EventBus $bus,
        private readonly OutboxConfig $config,
        private readonly ?ClockPort $clock = null,
    ) {}

    public function on(DatabasePort $connection): OutboxContract
    {
        return new self($this->store->on($connection), $this->bus, $this->config, $this->clock);
    }

    // ── write ─────────────────────────────────────────────────────────────────

    public function write(IntegrationEventContract $event, ?string $eventId = null): string
    {
        // THE GUARD. Everything this plugin promises is "the row and the state
        // change commit together", and that is only true when there IS a
        // transaction and it is on THIS connection. Both failures are invisible
        // from the caller's side and both are catastrophic in the same quiet
        // way, so neither is allowed to pass.
        if (!$this->store->inTransaction()) {
            throw OutboxException::noTransaction($event->name(), $this->store->describe());
        }

        $id = $eventId ?? self::uuid();

        try {
            // Encoded HERE, before the row is attempted, so an unencodable
            // payload is reported as the event's fault rather than surfacing as
            // a database error inside the caller's transaction.
            $payloadJson = json_encode($event->payload(), JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw OutboxException::unencodablePayload($event->name(), $e);
        }

        $this->store->write(
            eventId:      $id,
            eventName:    $event->name(),
            eventVersion: $event->version(),
            payloadJson:  $payloadJson,
            occurredAt:   $this->now(),
        );

        return $id;
    }

    // ── relay ─────────────────────────────────────────────────────────────────

    public function relay(?int $limit = null): RelayReport
    {
        // Before anything else, recover rows a dead relay is still holding.
        // The claim that makes concurrency safe is also a lease, and nothing
        // else in the system would ever release it.
        $reclaimed = $this->store->reclaimStale(
            $this->now()->sub(new \DateInterval('PT' . $this->config->claimTimeout . 'S')),
        );

        $owner      = self::owner();
        $dispatched = 0;
        $failed     = 0;
        $lost       = 0;
        $parked     = 0;

        foreach ($this->store->pending($limit ?? $this->config->relayLimit) as $entry) {
            // Reading the row was not claiming it. Another relay may have taken
            // it in between, in which case this one must NOT dispatch: that is
            // the difference between "concurrent relays are safe" and "every
            // consumer gets two copies of everything".
            if (!$this->store->claim($entry->id, $owner, $this->now())) {
                $lost++;

                continue;
            }

            try {
                // decode() is inside the try on purpose: one corrupt payload
                // parks its own row and the rest of the batch still goes out.
                $failures = $this->bus->dispatch(RelayedEvent::of(
                    $entry->eventName,
                    $entry->eventVersion,
                    $entry->decode(),
                    $entry->eventId,
                ));

                // A LISTENER THAT THREW IS NOT A DELIVERY, and reading the
                // isolation as success is the bug this line exists to prevent.
                //
                // EventBus deliberately isolates subscriber failures so one
                // broken listener cannot stop the others — then returns
                // normally. An outbox that takes that as success marks the row
                // dispatched, never retries it, and the work the listener
                // existed to do is lost, while `status=1, attempts=1,
                // last_error=NULL` records a clean delivery. That is exactly how
                // a tenant membership went missing with every table insisting
                // the event was fine.
                //
                // Parking it instead puts the row back in the retry path, so the
                // same event is redelivered once the cause is fixed and lands in
                // the failed pile if it never is. Redelivery is safe by
                // contract: this outbox is at-least-once and its consumers
                // dedupe on the event id. Note that a PARTIAL failure — one
                // listener fine, another broken — redelivers to BOTH, which is
                // the same contract and the reason it is a contract.
                if ($failures !== []) {
                    throw new \RuntimeException(self::describeFailures($failures));
                }

                $this->store->markDispatched($entry->id, $this->now());
                $dispatched++;
            } catch (\Throwable $e) {
                $attempts = $entry->attempts + 1;
                $park     = $attempts >= $this->config->maxAttempts;

                $this->store->release($entry->id, $attempts, $e->getMessage(), $park);

                $failed++;

                if ($park) {
                    $parked++;
                }
            }
        }

        return new RelayReport(
            dispatched: $dispatched,
            failed:     $failed,
            lost:       $lost,
            reclaimed:  $reclaimed,
            parked:     $parked,
        );
    }

    // ── operator surface ──────────────────────────────────────────────────────

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->store->counts();
    }

    public function retryFailed(?int $limit = null): int
    {
        return $this->store->retryFailed($limit ?? $this->config->relayLimit);
    }

    public function prune(?int $olderThanDays = null): int
    {
        $days = max(1, $olderThanDays ?? $this->config->pruneAfterDays);

        return $this->store->prune(
            $this->now()->sub(new \DateInterval('P' . $days . 'D')),
        );
    }

    public function describe(): string
    {
        return sprintf(
            '%s — batch %d, max %d attempts, claim lease %ds, keep %dd',
            $this->store->describe(),
            $this->config->relayLimit,
            $this->config->maxAttempts,
            $this->config->claimTimeout,
            $this->config->pruneAfterDays,
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }

    /**
     * Who claimed a row. Diagnostic only — nothing keys on it.
     *
     * Host and pid together are what let an operator find the relay that is
     * holding a row, which is the question `claimed_by` exists to answer. The
     * random suffix separates two relays that share a pid across containers.
     */
    private static function owner(): string
    {
        return substr(
            (gethostname() ?: 'unknown') . ':' . getmypid() . ':' . bin2hex(random_bytes(3)),
            0,
            64,
        );
    }

    /**
     * One line naming every listener that failed and why.
     *
     * This lands in the row's `last_error`, so it has to be enough to act on
     * without going to a log — the logger is optional on EventBus, so there may
     * be no log line at all.
     *
     * @param array<class-string, \Throwable> $failures
     */
    private static function describeFailures(array $failures): string
    {
        $parts = [];

        foreach ($failures as $listener => $error) {
            $parts[] = $listener . ': ' . $error->getMessage();
        }

        return 'Listener(s) failed — ' . implode(' | ', $parts);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);

        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
