<?php

declare(strict_types=1);

namespace Plugins\Outbox\Domain;

/**
 * What one relay run did.
 *
 * Every field exists because an operator asked a question that the single
 * "dispatched N events" number could not answer:
 *
 *   `failed`    — delivery was attempted and did not work. The row is back in
 *                 the retry path, or parked if it is out of attempts.
 *   `lost`      — the row was read and another relay claimed it first. This is
 *                 normal and healthy under two relays; a `lost` count that is
 *                 consistently most of the batch means more relays are running
 *                 than the work needs, and they are mostly colliding.
 *   `reclaimed` — rows a previous relay claimed and never settled, released
 *                 back to pending. A non-zero count means a relay died mid-run,
 *                 which nothing else in the system reports.
 *   `parked`    — rows that exhausted their attempts on THIS run. These are the
 *                 only ones that need a human, so they are counted separately
 *                 from `failed` rather than inferred from a second query.
 */
final readonly class RelayReport
{
    public function __construct(
        public int $dispatched = 0,
        public int $failed = 0,
        public int $lost = 0,
        public int $reclaimed = 0,
        public int $parked = 0,
    ) {}

    /** How many rows this run actually took responsibility for. */
    public function claimed(): int
    {
        return $this->dispatched + $this->failed;
    }

    /**
     * Did this run find nothing to do?
     *
     * A reclaim counts as work: it is the only signal that a relay died.
     */
    public function isIdle(): bool
    {
        return $this->dispatched === 0
            && $this->failed === 0
            && $this->lost === 0
            && $this->reclaimed === 0;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'dispatched' => $this->dispatched,
            'failed'     => $this->failed,
            'lost'       => $this->lost,
            'reclaimed'  => $this->reclaimed,
            'parked'     => $this->parked,
        ];
    }
}
