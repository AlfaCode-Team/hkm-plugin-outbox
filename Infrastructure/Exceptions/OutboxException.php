<?php

declare(strict_types=1);

namespace Plugins\Outbox\Infrastructure\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;

/**
 * Lives in Infrastructure/, not Domain/, because it extends a kernel exception
 * and the Domain layer imports nothing outside Domain/. The Domain classes here
 * throw `\JsonException` and `\InvalidArgumentException`; anything kernel-typed
 * is built in this namespace.
 *
 * `RepositoryException` is the right parent: HTTP 500, severity critical. Both
 * failures below are faults in the application's own wiring or storage, never
 * anything a request author did, so there is no 4xx reading of either.
 */
final class OutboxException extends RepositoryException
{
    /**
     * The transactional guarantee is not available, and this is THE message
     * that has to be good — because the second cause is invisible from the
     * caller's side and reads, from there, exactly like the first.
     */
    public static function noTransaction(string $eventName, string $store): self
    {
        return new self(
            "Refusing to enqueue [{$eventName}]: no transaction is open on the outbox's own connection.\n"
            . "\n"
            . "There are two causes and they look identical from the caller:\n"
            . "\n"
            . "  1. No transaction was begun. An outbox row outside a transaction has no state\n"
            . "     change to be atomic with, which is the only reason to write one. If you want\n"
            . "     durable delivery of an event with nothing to be atomic with, that is a queue.\n"
            . "\n"
            . "  2. A transaction IS open — on a DIFFERENT connection. DatabasePort is bound as a\n"
            . "     TRANSIENT by the Database plugin, so every make() is a new PDO handle, and this\n"
            . "     outbox resolved its own. The row would commit independently of your transaction:\n"
            . "     delivered after a rollback, or visible before your commit.\n"
            . "\n"
            . "     Hand over the connection your transaction runs on:\n"
            . "         \$c->make(OutboxContract::class)->on(\$yourDatabasePort)\n"
            . "\n"
            . "Outbox connection: {$store}",
            layer: 'repository.outbox.write',
            context: ['event' => $eventName],
        );
    }

    public static function writeFailed(string $eventName, \Throwable $previous): self
    {
        return new self(
            "Failed to enqueue outbox event [{$eventName}].",
            layer: 'repository.outbox.write',
            context: ['event' => $eventName],
            previous: $previous,
        );
    }

    public static function readFailed(\Throwable $previous): self
    {
        return new self(
            'Failed to read the outbox.',
            layer: 'repository.outbox.read',
            previous: $previous,
        );
    }

    public static function statusChangeFailed(string $what, int $id, \Throwable $previous): self
    {
        return new self(
            "Failed to {$what} outbox row.",
            layer: 'repository.outbox.status',
            context: ['id' => $id, 'operation' => $what],
            previous: $previous,
        );
    }

    /**
     * The payload could not be encoded, so there is nothing to store.
     *
     * Separate from writeFailed() because the cause is the event, not the
     * database: a payload holding a resource, a closure, an object with no JSON
     * representation, or an invalid UTF-8 byte sequence. The event's own name is
     * the only useful thing to report, since the payload is what is broken.
     */
    public static function unencodablePayload(string $eventName, \Throwable $previous): self
    {
        return new self(
            "Outbox event [{$eventName}] has a payload that cannot be encoded as JSON. "
            . 'An integration event carries primitives only — a value object, a resource or a '
            . 'closure in the payload is the usual cause.',
            layer: 'repository.outbox.write',
            context: ['event' => $eventName],
            previous: $previous,
        );
    }
}
