<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * A stored row, put back into the shape the `EventBus` dispatches.
 *
 * THE ORIGINAL CLASS IS GONE AND CANNOT COME BACK. An outbox row holds a name,
 * a version and a JSON payload — not a class-string — and that is on purpose:
 * the producing plugin may have been upgraded, renamed or uninstalled between
 * the write and the relay, and a relay that tried to rebuild the original class
 * would fail on exactly the rows that most need delivering.
 *
 * So listeners subscribed by NAME receive this. A listener that type-checks the
 * concrete event class instead of reading `name()` and `payload()` will not
 * recognise a relayed event, and that is a real constraint worth knowing rather
 * than a defect to work around — the published contract is the name, the
 * version and the primitives, which is also why integration events are
 * required to carry primitives only.
 *
 * The event id travels in the payload under `_outbox_event_id` so a consumer
 * can dedupe without the relay needing a second channel to pass it on. The
 * underscore marks it as envelope rather than domain data, and it is only added
 * when the payload does not already carry that key — a producer that set it
 * itself keeps its own value.
 */
final readonly class RelayedEvent implements IntegrationEventContract
{
    public const ID_KEY = '_outbox_event_id';

    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $name,
        private string $version,
        private array $payload,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function of(string $name, string $version, array $payload, string $eventId): self
    {
        $payload[self::ID_KEY] ??= $eventId;

        return new self($name, $version, $payload);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
