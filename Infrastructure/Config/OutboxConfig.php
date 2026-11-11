<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Config;

final readonly class OutboxConfig
{
    private function __construct(
        /** Attempts before a row is parked as failed and stops retrying. */
        public int $maxAttempts,
        /** Rows one relay run will attempt. */
        public int $relayLimit,
        /**
         * Seconds after which a claimed-but-unsettled row is released.
         *
         * This is a crash timer, not a delivery timeout: a relay that dies
         * between claiming a row and settling it leaves the row claimed
         * forever, and nothing else in the system would ever release it.
         */
        public int $claimTimeout,
        /** Days a settled row is kept before `outbox:prune` may delete it. */
        public int $pruneAfterDays,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            // Ten attempts over a relay running each minute is roughly ten
            // minutes of a transient outage absorbed, which covers a restart or
            // a failover without parking the row.
            maxAttempts: max(1, (int) env('OUTBOX_MAX_ATTEMPTS', 10)),
            relayLimit:  self::clampLimit((int) env('OUTBOX_RELAY_LIMIT', 100)),
            // Five minutes: long enough that a slow listener is never mistaken
            // for a dead relay, short enough that a real crash is recovered on
            // the same shift.
            claimTimeout: max(30, (int) env('OUTBOX_CLAIM_TIMEOUT', 300)),
            // Settled rows are the audit trail of what was delivered, so the
            // default keeps a week of it rather than deleting on success.
            pruneAfterDays: max(1, (int) env('OUTBOX_PRUNE_AFTER_DAYS', 7)),
        );
    }

    public static function of(
        int $maxAttempts = 10,
        int $relayLimit = 100,
        int $claimTimeout = 300,
        int $pruneAfterDays = 7,
    ): self {
        return new self(
            max(1, $maxAttempts),
            self::clampLimit($relayLimit),
            max(1, $claimTimeout),
            max(1, $pruneAfterDays),
        );
    }

    /**
     * The batch size reaches SQL as an inlined integer, because a bound `LIMIT`
     * is sent as a string and a native prepare rejects `LIMIT '100'`. Clamping
     * it here is what makes that inlining safe, so it happens in the one place
     * every construction path goes through rather than at the query.
     *
     * The ceiling is also a real protection: one relay run holds its claims
     * until it finishes, so an unbounded batch means an unbounded number of
     * rows stuck behind one slow listener.
     */
    private static function clampLimit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }
}
