<?php

declare(strict_types=1);

namespace Plugins\Outbox;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Outbox\API\Contracts\OutboxContract;
use Plugins\Outbox\Application\Ports\OutboxStore;
use Plugins\Outbox\Application\Services\OutboxService;
use Plugins\Outbox\Infrastructure\Cli\OutboxPruneCommand;
use Plugins\Outbox\Infrastructure\Cli\OutboxRelayCommand;
use Plugins\Outbox\Infrastructure\Cli\OutboxRetryCommand;
use Plugins\Outbox\Infrastructure\Cli\OutboxStatusCommand;
use Plugins\Outbox\Infrastructure\Config\OutboxConfig;
use Plugins\Outbox\Infrastructure\OutboxFactory;
use Plugins\Outbox\Infrastructure\Persistence\OutboxRepository;

/**
 * Outbox — wires the 'event.outbox' domain.
 *
 * Declares NO requires[]: it needs `DatabasePort` and the kernel's `EventBus`,
 * and ports are not listed in requires[]. So it installs into any application
 * that has a database.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'event.outbox';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [OutboxContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(OutboxConfig::class, static fn (): OutboxConfig => OutboxConfig::fromEnv());

        $container->bindInternal(
            OutboxStore::class,
            static fn (ModuleContainer $c): OutboxStore => new OutboxRepository($c->make(DatabasePort::class)),
        );

        $container->bind(
            OutboxContract::class,
            static fn (ModuleContainer $c): OutboxService => new OutboxService(
                store:  $c->make(OutboxStore::class),
                bus:    $c->make(EventBus::class),
                config: $c->make(OutboxConfig::class),
                // Elapsed time decides two things here — when a claim is stale
                // and what is old enough to prune — so a clock is warranted.
                // Optional, because an application that bound no ClockPort must
                // still get a working outbox.
                clock:  $c->has(ClockPort::class) ? $c->make(ClockPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // ─────────────────────────────────────────────────────────────────────
        // COMMANDS ARE REGISTERED AS INSTANCES, AND THIS IS NOT A STYLE CHOICE
        // ─────────────────────────────────────────────────────────────────────
        // A command registered as a CLASS-STRING is built by CliPipeline from
        // the CoreContainer — and when that fails, `instantiate()` returns null
        // and the command is SILENTLY NOT REGISTERED. It does not appear in
        // `list`, and running it reports an unknown command.
        //
        // Every command here takes `OutboxContract`, an INTERFACE, which is
        // bound in register() against the request-scoped ModuleContainer and
        // which bind-it cannot autowire by reflection. So the class-string form
        // would compile, boot, pass every test that does not run the CLI, and
        // produce four commands that do not exist.
        //
        // Building the instances here is the documented alternative: "an
        // already-constructed AbstractCommand — used as-is, which lets a module
        // build a command with its OWN services without leaking them into the
        // CoreContainer."
        //
        // They are also still declared in module.json commands[], for
        // discovery. That costs nothing and cannot double-register:
        // DeclaredCommands skips any class whose constructor takes parameters,
        // precisely so a module that builds its own command keeps control of
        // which connection it is on.
        //
        // DEFERRED, so the DatabasePort behind these commands is resolved ONLY
        // on the CLI path. Every module's boot() also runs during an HTTP build,
        // and an outbox has no business opening a connection there.
        $cli->defer(static function (CliPipeline $cli): void {
            $outbox = self::forCli($cli->container());

            $cli->command(new OutboxRelayCommand($outbox));
            $cli->command(new OutboxStatusCommand($outbox));
            $cli->command(new OutboxRetryCommand($outbox));
            $cli->command(new OutboxPruneCommand($outbox));
        });
    }

    /**
     * The outbox the CLI operates on.
     *
     * Built from the CoreContainer, which for a single-database application is
     * the right connection and the only one there is.
     *
     * IN A DATABASE-PER-TENANT DEPLOYMENT IT IS NOT. The core `DatabasePort` is
     * the central connection; a tenant's outbox rows live in the tenant's own
     * database, and nothing here iterates tenants — that is the tenancy
     * plugin's business, not this plugin's. Such a deployment must invoke the
     * relay once per tenant, with the tenancy plugin having rebound the port,
     * exactly as it does for any other per-tenant command.
     */
    private static function forCli(CoreContainer $core): OutboxService
    {
        return OutboxFactory::make(
            db:     $core->make(DatabasePort::class),
            bus:    $core->make(EventBus::class),
            config: OutboxConfig::fromEnv(),
            clock:  $core->has(ClockPort::class) ? $core->make(ClockPort::class) : null,
        );
    }
}
