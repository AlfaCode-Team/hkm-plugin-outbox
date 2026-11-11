# Changelog

All notable changes to `hkm-plugin-outbox` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: a shared transactional outbox.** Solves `event.outbox` and
  declares no `requires[]`, so it installs into any application with a database.
  Any plugin can write an integration event inside its own transaction and have
  it delivered afterwards, instead of carrying its own outbox table, relay
  command and retry policy.

- **The transactional guarantee is CHECKED, not assumed.** "The same
  transaction" means the same connection, and `DatabasePort` is bound as a
  TRANSIENT by the Database plugin — so a caller whose transaction runs on
  connection A and an outbox that resolved connection B produce a row that is
  not in the transaction at all: delivered after a rollback, or visible before
  the commit. Nothing about it looks wrong, and it works under the kernel's own
  `withPorts()` path (shared via `instance()`) while breaking under the
  per-request rebinding tenancy needs. `OutboxContract::on()` lets a caller hand
  over its exact connection, and `write()` REFUSES when its own connection has
  no open transaction — which is precisely what the second case looks like from
  here. The refusal names both causes, because the fixes are completely
  different and the symptoms are identical.

- **A listener that throws is not a delivery.** `EventBus::dispatch()` isolates
  subscriber failures by design and then returns normally; reading that as
  success marks the row dispatched, never retries it, and loses the work for
  good while `status=1, attempts=1, last_error=NULL` records a clean delivery.
  The relay checks the returned failures and puts the row back in the retry
  path, with every failing listener and its message in `last_error`. A partial
  failure redelivers to every listener, which is the same at-least-once
  contract and the reason it is a contract.

- **Concurrent relays claim rows rather than locking them.** Two relays reading
  `WHERE status = 0` both dispatch the same rows, because reading a row is not
  claiming it. A claim is its own committed write — `UPDATE … WHERE id = :id AND
  status = 0` — so the database applies the predicate atomically and exactly one
  relay sees one affected row.

  Deliberately NOT `SELECT … FOR UPDATE SKIP LOCKED`: that lock lives only
  inside a transaction, so the transaction would have to stay open across the
  dispatch, holding a row lock while a listener calls someone else's HTTP
  endpoint. It would also need a driver branch, since `FOR UPDATE` is a syntax
  error on SQLite.

- **The claim is a lease, and the reclaim is the only crash signal there is.** A
  relay killed between claiming a row and settling it would hold it forever, so
  each run first releases anything claimed longer than `OUTBOX_CLAIM_TIMEOUT`
  and reports how many — nothing else in the system records that a relay died. A
  reclaim costs an attempt, which bounds a row that is killing the relay rather
  than letting it retry forever and look like an outbox that works.

- **An explicit event id makes an enqueue idempotent.** `write()` uses
  insert-if-absent on the unique `event_id`, so writing the same id twice is a
  no-op rather than a constraint violation — which matters because it runs
  inside the CALLER'S transaction, where a throw would abort their work over a
  duplicate the outbox could perfectly well ignore.

- **A corrupt payload parks only its own row.** The payload is carried as raw
  JSON and decoded inside the relay's per-row try block. Decoding while the
  batch was being assembled would take every good row with it, and the failure
  would read as "the outbox stopped" rather than "one row is corrupt".

- **Four commands, and `outbox:status` exits non-zero when rows are parked** so
  it works as a monitoring check without anything parsing its output.
  `outbox:relay` exits non-zero ONLY on a parked row — a transient failure that
  will be retried is not a failed run, and a supervisor restarting on non-zero
  would restart the relay because someone else's endpoint was briefly down.
  `outbox:prune` deletes nothing without `--yes`, and takes no interactive
  confirmation because it belongs in cron, where a prompt either blocks forever
  or is auto-answered by whatever is on stdin.

- **The relay and the prune are declared in `schedule[]`**, so a deployment is
  not one forgotten cron line away from an outbox that fills up and delivers
  nothing.

- **It does NOT subscribe to `EventBus::ALL`**, although it now could. A
  wildcard listener fires at dispatch time, which is after the commit, so it
  carries none of the atomicity this exists for — that is an event journal, not
  an outbox. Events arrive only through an explicit `write()`.

### Notes

- Delivery is **at-least-once** and there is **no ordering guarantee**: a row
  that fails is retried later while the rows behind it go out now. Consumers
  dedupe on the event id, which arrives in the payload as `_outbox_event_id`.
- "Dispatched" means handed to the `EventBus` and accepted by every subscribed
  listener, **including when that is none at all**. An event nobody subscribed
  to is marked dispatched.
- `last_error` is listener-supplied text. It belongs in a terminal or a log and
  must not be echoed into an HTTP response.
