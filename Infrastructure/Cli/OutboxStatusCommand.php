<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Outbox\API\Contracts\OutboxContract;

/**
 * outbox:status — how much is owed, and whether anything needs a person.
 *
 * Exits NON-ZERO when rows are parked, so it works as a monitoring check
 * without anything having to parse its output.
 */
final class OutboxStatusCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'outbox:status';
        $this->description = 'Show outbox depth by status';
    }

    public function __construct(
        private readonly OutboxContract $outbox,
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $counts = $this->outbox->counts();

        // describe() carries the table name, the dialect and the tuning — never
        // a host, a database name or a credential.
        $this->section('Outbox');
        $this->muted($this->outbox->describe());
        $this->newLine();

        foreach ($counts as $label => $total) {
            $this->info(sprintf('  %-11s %d', $label, $total));
        }

        $this->newLine();

        $parked  = $counts['failed'] ?? 0;
        $claimed = $counts['claimed'] ?? 0;

        if ($claimed > 0) {
            // Not a fault on its own: a relay running right now holds claims.
            // It is only a problem if it does not go away, which is why the
            // wording points at repeating the command rather than at an action.
            $this->muted(
                "{$claimed} row(s) are claimed. That is a relay working if it clears on the next "
                . 'run, and a relay that died if it does not — `outbox:relay` reclaims those.',
            );
        }

        if ($parked > 0) {
            $this->error(
                "{$parked} event(s) are parked after exhausting their attempts and will NOT be "
                . 'retried. Fix the cause, then `outbox:retry`.',
            );

            return self::FAILURE;
        }

        $this->success('Nothing parked.');

        return self::SUCCESS;
    }
}
