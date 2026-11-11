<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * A real EventBus listener that records what it received.
 *
 * Dependency-free on purpose: `EventBus::resolveListener()` falls back to
 * `new $class()` only when the constructor needs nothing, and the point of
 * these tests is the outbox policy rather than the bus's own resolution. State
 * is therefore static — the bus constructs its own instance.
 */
final class RecordingListener implements EventListenerContract
{
    /** @var list<IntegrationEventContract> */
    public static array $received = [];

    public static function reset(): void
    {
        self::$received = [];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(
            static fn (IntegrationEventContract $e): string => $e->name(),
            self::$received,
        );
    }

    public function handle(IntegrationEventContract $event): void
    {
        self::$received[] = $event;
    }
}
