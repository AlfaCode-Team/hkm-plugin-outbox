<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Outbox\API\Contracts\OutboxContract;

/**
 * outbox:relay — deliver owed events to the EventBus.
 *
 * Safe to run concurrently and safe to run every minute. A run that finds
 * nothing says so and exits 0.
 */
final class OutboxRelayCommand extends AbstractCommand
{
    public function __construct(
        private readonly OutboxContract $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'outbox:relay';
        $this->description = 'Deliver pending outbox events to the EventBus';

        $this->addOption('limit', 'l', 'Max events to attempt this run', acceptsValue: true);
        $this->addOption('quiet-when-idle', '', 'Print nothing when there was no work');
    }

    protected function handle(): int
    {
        $limit  = $this->option('limit');
        $report = $this->outbox->relay($limit === null ? null : (int) $limit);

        if ($report->isIdle()) {
            if (!$this->hasOption('quiet-when-idle')) {
                $this->muted('Nothing to relay.');
            }

            return self::SUCCESS;
        }

        if ($report->dispatched > 0) {
            $this->success("Dispatched {$report->dispatched} event(s).");
        }

        if ($report->reclaimed > 0) {
            // The ONLY place this is ever reported. A relay that was killed
            // between claiming a row and settling it leaves no other trace.
            $this->warning(
                "Reclaimed {$report->reclaimed} row(s) from a relay that never finished — "
                . 'a previous run was killed mid-delivery.',
            );
        }

        if ($report->failed > 0) {
            $this->error("{$report->failed} event(s) failed and will be retried.");
        }

        if ($report->parked > 0) {
            // Separate from `failed` because this is the only count that needs a
            // person: these rows will not move again on their own.
            $this->error(
                "{$report->parked} event(s) exhausted their attempts and are now parked. "
                . 'They will NOT be retried — see `outbox:status` and then `outbox:retry`.',
            );
        }

        if ($report->lost > 0) {
            // Normal and healthy with more than one relay: it means another one
            // got there first. Worth printing, because `lost` being most of the
            // batch every run means more relays are running than the work needs.
            $this->muted("{$report->lost} row(s) were claimed by another relay first.");
        }

        // Non-zero ONLY when something is parked. A transient failure that will
        // be retried is not a failed run, and a supervisor that restarts on a
        // non-zero exit would restart the relay for an endpoint being briefly
        // down — turning a retry into a restart loop.
        return $report->parked > 0 ? self::FAILURE : self::SUCCESS;
    }
}
