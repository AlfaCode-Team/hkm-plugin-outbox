<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Outbox\API\Contracts\OutboxContract;

/**
 * outbox:retry — put parked events back in the retry path.
 *
 * The deliberate second step after a failure has been fixed. Parking is what
 * stops a broken consumer from being retried forever; this is the only thing
 * that undoes it, and it is a person's decision because nothing automated can
 * know the cause was addressed.
 */
final class OutboxRetryCommand extends AbstractCommand
{
    public function __construct(
        private readonly OutboxContract $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'outbox:retry';
        $this->description = 'Move parked (failed) outbox events back to pending';

        $this->addOption('limit', 'l', 'Max rows to release', acceptsValue: true);
    }

    protected function handle(): int
    {
        $parked = $this->outbox->counts()['failed'] ?? 0;

        if ($parked === 0) {
            $this->success('Nothing is parked.');

            return self::SUCCESS;
        }

        $limit    = $this->option('limit');
        $released = $this->outbox->retryFailed($limit === null ? null : (int) $limit);

        if ($released === 0) {
            $this->warning('Nothing was released.');

            return self::SUCCESS;
        }

        $this->success("Released {$released} event(s) back to pending; attempts reset.");

        if ($released < $parked) {
            // Says so explicitly rather than leaving the operator to compare two
            // numbers: a batch limit silently leaving rows parked is how a retry
            // gets believed to be complete when it is not.
            $remaining = $parked - $released;
            $this->warning("{$remaining} still parked — the batch limit stopped short. Run it again.");
        }

        $this->muted('They are delivered by the next `outbox:relay`.');

        return self::SUCCESS;
    }
}
