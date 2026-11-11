<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\Application\Services\OutboxService;
use Plugins\Outbox\Infrastructure\Config\OutboxConfig;
use Plugins\Outbox\Infrastructure\Persistence\OutboxRepository;

/**
 * Assembly in one place, so the Provider and the CLI path cannot drift.
 *
 * They are built from two DIFFERENT containers — the Provider from the
 * request-scoped `ModuleContainer`, the CLI from the app-lifetime
 * `CoreContainer` — and that is exactly the kind of duplication that ends with
 * one of them quietly missing a clock or holding a different connection.
 */
final class OutboxFactory
{
    public static function make(
        DatabasePort $db,
        EventBus $bus,
        ?OutboxConfig $config = null,
        ?ClockPort $clock = null,
    ): OutboxService {
        return new OutboxService(
            store:  new OutboxRepository($db),
            bus:    $bus,
            config: $config ?? OutboxConfig::fromEnv(),
            clock:  $clock,
        );
    }
}
