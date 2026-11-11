# hkm-plugin-outbox

> HKM Kernel plugin — provides **`event.outbox`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

A shared transactional outbox any plugin can write to. Declares no `requires[]`,
so it installs into any application that has a database.

## The problem it solves

Committing a state change and telling the rest of the system about it are two
operations against two different systems, and there is no atomic way to do both.

| Order | What goes wrong |
| --- | --- |
| dispatch, then commit | the transaction rolls back and consumers have acted on a change that never happened |
| commit, then dispatch | the process dies in between: the data is perfectly consistent, the notification is gone, and nobody is aware anything is missing |

So the event is written as a **row, inside the same transaction as the state
change**, and a relay delivers it afterwards. The two commit or roll back
together because they are the same commit.

## Writing an event

```php
// In the consuming plugin's module.json
{ "requires": ["event.outbox"] }
```

```php
$this->collector->beginCollection();
$this->transaction->begin();

try {
    $this->repository->save($thing);

    // Same transaction. Returns the event id consumers dedupe on.
    $eventId = $this->outbox->write(new ThingCreatedIntegrationEvent(...));

    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();
    throw $e;
}
```

The event is delivered by the next `outbox:relay`, so the request does not wait
for a listener and a slow consumer cannot fail the write.

## The one way it silently does not work

**"The same transaction" means the same connection.** The Database plugin binds
`DatabasePort` as a **transient**: every `make()` hands back a new adapter with a
new PDO handle. A caller whose transaction runs on connection A and an outbox
that resolved connection B produce a row that is not in the transaction at all —
delivered after a rollback, or visible before the commit.

Nothing about that looks wrong, and it is wiring-dependent: the kernel's own
`withPorts([...])` path uses `instance()` and **is** shared, so it works in the
default setup and breaks under the per-request rebinding that tenancy needs.

Two things address it:

**Hand over your connection at wiring time.**

```php
$container->bindInternal(
    ThingRepository::class,
    static fn ($c) => new ThingRepository(
        $c->make(RequestConnection::class)->db,
        $c->make(OutboxContract::class)->on($c->make(RequestConnection::class)->db),
    ),
);
```

**And `write()` refuses when its own connection has no open transaction**, which
is exactly what the second case looks like from here. The message names both
causes, because they are indistinguishable from the caller and the fixes are
completely different.

## What it promises, and what it does not

```
✓ The event row and the state change commit or roll back together.
✓ AT-LEAST-ONCE delivery. Consumers MUST dedupe on the event id, which is
  returned by write() and arrives in the payload as `_outbox_event_id`.
✓ Concurrent relays are safe — they claim rows, see below.
✗ NO ordering guarantee. A row that fails is retried later while the rows
  behind it go out now. A consumer that needs ordering must get it from the
  payload.
✗ NO exactly-once. Nothing offers that across two systems.
✗ "Dispatched" means handed to the EventBus and accepted by every subscribed
  listener — INCLUDING when that is none at all. An event with no subscriber is
  marked dispatched, because the outbox's job is to deliver it to the bus and
  not to have opinions about who should have been listening. Worth knowing when
  debugging "the webhook never fired" against a row that says `dispatched`.
```

## A listener that throws is not a delivery

`EventBus::dispatch()` deliberately isolates subscriber failures so one broken
listener cannot stop the others — then **returns normally**. An outbox that reads
that as success marks the row dispatched, never retries it, and the work the
listener existed to do is lost, while `status=1, attempts=1, last_error=NULL`
records a clean delivery.

That is how a tenant membership once went missing with every table insisting the
event was fine. So the relay checks the returned failures and puts the row back
in the retry path, with every failing listener and its message in `last_error`.

A **partial** failure — one listener fine, another broken — redelivers to both.
That is the same at-least-once contract, and the reason it is a contract.

## Concurrency: claiming, not locking

Two relays reading `WHERE status = 0` both see the same rows and both dispatch
them. Reading a row is not claiming it. So a claim is its own committed write:

```sql
UPDATE event_outbox SET status = 3, claimed_by = :owner, claimed_at = :at
 WHERE id = :id AND status = 0
```

The database applies `status = 0` atomically, so exactly one relay sees one
affected row and every other sees zero and skips the row.

**This is deliberately not `SELECT … FOR UPDATE SKIP LOCKED`.** That lock lives
only inside a transaction, so the transaction would have to stay open across the
dispatch — holding a row lock while a listener calls someone else's HTTP
endpoint, which is how one slow consumer becomes a database incident. It would
also need a driver branch, since `FOR UPDATE` is a syntax error on SQLite.

The claim is a **lease**. A relay killed between claiming and settling would hold
the row forever, so `outbox:relay` first releases anything claimed longer than
`OUTBOX_CLAIM_TIMEOUT`. That reclaim is the only signal anywhere that a relay
died, which is why it is reported rather than silent.

A reclaim **costs an attempt**, deliberately: a relay that is killed *by* a
particular row would otherwise reclaim and retry it forever, and the loop would
look like an outbox that works. The price is an attempt charged to innocent rows
when a relay dies for unrelated reasons.

## Commands

| Command | Does |
| --- | --- |
| `outbox:relay` | deliver owed rows. Safe to run concurrently and every minute |
| `outbox:status` | depth by status; **exits non-zero when rows are parked**, so it works as a monitoring check |
| `outbox:retry` | put parked rows back, attempts reset — after fixing the cause |
| `outbox:prune` | delete settled rows past the retention window. **Deletes nothing without `--yes`** |

`outbox:relay` exits non-zero **only** when something was parked. A transient
failure that will be retried is not a failed run, and a supervisor restarting on
a non-zero exit would restart the relay for an endpoint being briefly down.

Both the relay and the prune are declared in `schedule[]`, so a deployment is not
one forgotten cron line away from an outbox that fills up and delivers nothing.
The scheduler itself still needs its own tick (`hkm cli schedule:run`).

## Configuration

| Variable | Default | Notes |
| --- | --- | --- |
| `OUTBOX_MAX_ATTEMPTS` | `10` | attempts before a row is parked. ~10 minutes of a transient outage absorbed at one relay per minute |
| `OUTBOX_RELAY_LIMIT` | `100` | rows per run. Clamped to 1–1000 |
| `OUTBOX_CLAIM_TIMEOUT` | `300` | seconds before a claim is treated as a dead relay's |
| `OUTBOX_PRUNE_AFTER_DAYS` | `7` | settled rows are the audit trail of what was delivered, so they are kept rather than deleted on success |

## Multi-tenancy

The CLI operates on the **core** `DatabasePort`, which for a single-database
application is the right connection and the only one. In a database-per-tenant
deployment it is not: a tenant's outbox rows live in the tenant's own database,
and **nothing here iterates tenants** — that is the tenancy plugin's business.
Such a deployment runs the relay once per tenant, with the tenancy plugin having
rebound the port, exactly as for any other per-tenant command.

## Why it does not subscribe to `EventBus::ALL`

It could capture every dispatch automatically, and that would not be an outbox.
A wildcard listener fires at **dispatch** time, which is after the commit — so it
carries none of the atomicity this exists for. That is an event journal, and a
useful thing, but it is a different thing. Events arrive here only through an
explicit `write()` inside a transaction.

## `last_error` is untrusted text

It holds whatever a listener's exception said, truncated to 1000 characters. It
belongs in a terminal or a log and must **not** be echoed into an HTTP response.
`describe()` never carries it, and never carries a host, a database name or a
credential.

## Testing

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel vendor/bin/phpunit
```

The integration suite needs the kernel's own `vendor/` — it runs this plugin's
real migration through LetMigrate and points a real `DatabasePort` at the result.

## Verified / not verified

**Verified**: 53 tests, 155 assertions. `ground check --strict` clean.

The unit suite drives the delivery policy against an in-memory store, using the
**real** `EventBus` rather than a double — the most expensive bug this design
inherits is about that class's return value, and a fake bus would be written to
whatever this code expects.

The integration suite runs the real SQL against the schema this plugin's own
migration produces, on SQLite. Two properties are established there that no
in-memory double can show:

- **Atomicity.** A `write()` inside a real transaction followed by a `rollback()`
  leaves no row, and the relay delivers nothing. This is the property the plugin
  exists for and everything else is bookkeeping around it.
- **The claim race.** Two repositories over one database claim the same row;
  exactly one wins, and the loser does not dispatch.

Five behaviours confirmed load-bearing by reverting them: the transaction guard
(4 failures), the claim predicate (14), reading a listener failure as a delivery
(6), `markDispatched`'s claimed-row predicate (6), and the reclaim cutoff (1).

**Not verified:**

- **Only SQLite has run it.** No MySQL, no PostgreSQL. The statements avoid
  dialect-specific grammar deliberately — `upsert()` compiles the driver's own
  form, `LIMIT` is never used on an `UPDATE`, and there is no `FOR UPDATE` — but
  "portable by construction" is an argument, not a measurement. The one thing
  most worth checking on MySQL is that `execute()` reports **1** for a winning
  claim: `rowCount()` counts *changed* rows there, and the claim does change
  columns, so it should — but should is not did.
- **No real concurrency.** The claim race is proven with two sequential
  statements against one database, which is what makes the predicate atomic, not
  with two processes running at once.
- **No command has been run.** All four CLI classes are covered by reading only.
  Their wiring is the part most likely to be wrong, and it is subtle: commands
  must be registered as **instances** from `boot()`, because a class-string is
  built from the CoreContainer and one whose dependencies do not resolve there is
  **silently not registered at all**. That reasoning is written down in
  `Provider::boot()`; it has not been exercised.
- **The `schedule[]` entries parse and compute the right due times** (checked
  against the kernel's own `CronExpression` and `ScheduledTask`), but no
  scheduler has ever run them.
- **`prune()`'s two status filters mask each other.** A revert check showed the
  suite passing with either one removed. Both are kept and the comment explains
  what each is actually for — the id query makes the batching correct, the
  DELETE's predicate closes the window where a concurrent `outbox:retry` could
  have rescued a row. Neither is pinned individually, and that narrow race is
  not reproduced.
- **Nothing has migrated against a production-shaped table.** The `json` column
  type and the `CURRENT_TIMESTAMP` default are exercised on SQLite only.

## License

MIT — see [LICENSE](LICENSE).
