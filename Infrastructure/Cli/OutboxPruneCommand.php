<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Outbox\API\Contracts\OutboxContract;

/**
 * outbox:prune — delete settled rows past the retention window.
 *
 * DELETES NOTHING WITHOUT `--yes`, and that is not timidity: this is the only
 * command here that destroys data, it is the kind of thing that gets pasted
 * into a shell to "see what it says", and the row it would wrongly delete is an
 * event somebody is trying to account for. A confirmation PROMPT would have
 * been worse — this belongs in cron, and a prompt there either blocks forever
 * or is auto-answered by whatever is on stdin.
 *
 * Only `dispatched` and `failed` rows are eligible. A pending or claimed row is
 * still owed to its consumers however old it is.
 */
final class OutboxPruneCommand extends AbstractCommand
{
    public function __construct(
        private readonly OutboxContract $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'outbox:prune';
        $this->description = 'Delete settled outbox rows older than the retention window';

        $this->addOption('days', 'd', 'Override the retention window, in days', acceptsValue: true);
        $this->addOption('yes', 'y', 'Actually delete. Without this, nothing is deleted');
    }

    protected function handle(): int
    {
        $days   = $this->option('days');
        $days   = $days === null ? null : max(1, (int) $days);
        $counts = $this->outbox->counts();
        $window = $days === null ? 'the configured retention window' : "{$days} day(s)";

        $settled = ($counts['dispatched'] ?? 0) + ($counts['failed'] ?? 0);
        $owed    = ($counts['pending'] ?? 0) + ($counts['claimed'] ?? 0);

        if (!$this->hasOption('yes')) {
            $this->info("Would delete dispatched and failed rows older than {$window}.");
            $this->muted("Settled rows in the table: {$settled} (not all are necessarily older than the window).");
            $this->muted("Rows that are still owed and will never be pruned: {$owed}.");
            $this->newLine();
            $this->warning('Nothing was deleted. Re-run with --yes to delete.');

            return self::SUCCESS;
        }

        $deleted = $this->outbox->prune($days);

        if ($deleted === 0) {
            $this->success('Nothing was old enough to prune.');

            return self::SUCCESS;
        }

        $this->success("Deleted {$deleted} settled row(s) older than {$window}.");

        // The repository deletes in bounded batches so one invocation cannot
        // hold a long lock on the relay's own hot table. Hitting the ceiling is
        // normal on a table that has never been pruned, and the operator needs
        // to know to run it again rather than assume it is done.
        if ($deleted >= 100_000) {
            $this->warning('The per-run ceiling was reached — run it again to continue.');
        }

        return self::SUCCESS;
    }
}
