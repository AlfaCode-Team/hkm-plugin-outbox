<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * The shared transactional outbox.
 *
 * An integration event is written here inside the SAME transaction as the state
 * change it describes, then relayed to the EventBus by `outbox:relay`. The row
 * and the state change commit or roll back together, because they are the same
 * commit — which is the only way to avoid both "consumers acted on a change
 * that rolled back" and "the change committed and nobody was told".
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('event_outbox', static function ($t) {
            $t->id();

            // The consumer-side idempotency key. Delivery is at-least-once, so a
            // message CAN arrive twice and consumers dedupe on this. Unique, so
            // a producer may also supply it to make its own enqueue idempotent —
            // see OutboxContract::write($event, $eventId).
            $t->char('event_id', 36)->comment('UUID — the key consumers dedupe on');

            // 150, not 100: this table takes events from EVERY plugin, and a
            // name is `vendor.resource.sub-resource.verb` in the deepest cases.
            // A truncated event name routes to nothing and is unrecoverable from
            // the row.
            $t->string('event_name', 150);

            $t->string('event_version', 16)->default('1.0');

            // Everything a consumer needs travels with the row, so the relay
            // never re-queries the producing domain — which may have changed or
            // been deleted since.
            $t->json('payload');

            // 0=pending 1=dispatched 2=failed 3=claimed.
            //
            // `claimed` is the one that makes concurrent relays safe. Two relays
            // reading `status = 0` both dispatch the same rows; a relay that has
            // CLAIMED a row has moved it out of every other relay's query.
            $t->tinyInteger('status')->unsigned()->default(0)
                ->comment('0=pending,1=dispatched,2=failed,3=claimed');

            $t->unsignedInteger('attempts')->default(0);

            // Listener-supplied text, truncated to 1000 chars by the repository.
            // Treat it as untrusted for display: it is whatever a listener's
            // exception said, so it belongs in a terminal or a log and never
            // echoed into an HTTP response.
            $t->text('last_error')->nullable();

            // When the event happened, which is the relay's ordering key —
            // distinct from created_at so a backfilled event relays in its own
            // causal position rather than at the moment it was inserted.
            $t->timestamp('occurred_at');

            // Which relay holds this row, and since when. The pair is a LEASE:
            // reclaimStale() releases rows claimed longer than
            // OUTBOX_CLAIM_TIMEOUT, because a relay killed between claiming and
            // settling would otherwise hold the row forever and nothing else
            // would ever notice.
            $t->string('claimed_by', 64)->nullable()->comment('host:pid:rand — diagnostic only');
            $t->timestamp('claimed_at')->nullable();

            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // uniq_outbox_event: one row per event instance. This is also what
            // lets write() use insert-if-absent, so a duplicate enqueue is a
            // no-op rather than a constraint violation that would abort the
            // CALLER'S transaction over a duplicate the outbox could ignore.
            $t->unique(['event_id'], 'uniq_outbox_event');

            // idx_outbox_pending: the relay's hot query — pending rows, oldest
            // first — without scanning the table.
            $t->index(['status', 'occurred_at'], 'idx_outbox_pending');

            // idx_outbox_lease: the reclaim scan. Separate from the above
            // because it orders on claimed_at, and a composite whose second
            // column is wrong is an index the planner ignores.
            $t->index(['status', 'claimed_at'], 'idx_outbox_lease');

            // THERE IS DELIBERATELY NO (status, created_at) INDEX for pruning.
            // Prune runs daily at most and reads through the status prefix of
            // idx_outbox_pending; a third composite on the same leading column
            // costs write amplification on every insert and every status change
            // — which is the hot path — to speed up a job nobody waits for.

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('event_outbox');
    }
};
