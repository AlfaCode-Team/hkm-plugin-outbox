<?php

declare(strict_types=1);

namespace Plugins\Outbox\Domain;

/**
 * Where a row is in its delivery life.
 *
 * Backed by the integers the column already stores, so a deployment migrating
 * from a per-plugin outbox table keeps the meaning of its existing rows.
 *
 * `Claimed` is the one that did not exist before, and it is the whole reason
 * concurrent relays are safe: without it, two relays both read the same
 * `status = 0` rows and both dispatch them, so every consumer gets two copies
 * of every event for as long as both relays run. Reading the row is not
 * claiming it.
 */
enum OutboxStatus: int
{
    case Pending    = 0;
    case Dispatched = 1;
    case Failed     = 2;
    case Claimed    = 3;

    /** Is this row still owed to its consumers? */
    public function isOwed(): bool
    {
        return $this === self::Pending || $this === self::Claimed;
    }

    /**
     * Is this row finished with, one way or the other?
     *
     * `Failed` counts as settled even though nothing was delivered: it is out
     * of the retry path and will not move again without an operator, which is
     * exactly what makes it worth alerting on.
     */
    public function isSettled(): bool
    {
        return $this === self::Dispatched || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'pending',
            self::Dispatched => 'dispatched',
            self::Failed     => 'failed',
            self::Claimed    => 'claimed',
        };
    }
}
