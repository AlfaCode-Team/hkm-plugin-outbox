<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * A listener that fails the way the production incident failed.
 *
 * `EventBus::dispatch()` ISOLATES this and returns normally — it is the return
 * VALUE that names the failure. An outbox that reads the normal return as a
 * delivery marks the row dispatched and loses the work for good, which is
 * exactly what this listener exists to prove does not happen.
 */
final class ThrowingListener implements EventListenerContract
{
    public const MESSAGE = 'the listener could not do its job';

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function handle(IntegrationEventContract $event): void
    {
        self::$calls++;

        throw new \RuntimeException(self::MESSAGE);
    }
}
