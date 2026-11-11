<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that does nothing, for the one test that only needs a distinct
 * connection IDENTITY — proving `on()` hands back a different store rather than
 * repointing the one it was called on.
 *
 * Every method throws rather than returning a plausible empty value: if
 * anything ever actually queries through this, the test should say so loudly
 * instead of passing against silence.
 */
final class NullDatabase implements DatabasePort
{
    public function query(string $sql, array $params = []): array
    {
        throw new \LogicException('NullDatabase was queried.');
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        throw new \LogicException('NullDatabase was queried.');
    }

    public function execute(string $sql, array $params = []): int
    {
        throw new \LogicException('NullDatabase was written to.');
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        throw new \LogicException('NullDatabase was written to.');
    }

    public function lastInsertId(?string $sequence = null): string
    {
        throw new \LogicException('NullDatabase was queried.');
    }

    public function beginTransaction(): void
    {
        throw new \LogicException('NullDatabase cannot hold a transaction.');
    }

    public function commit(): void
    {
        throw new \LogicException('NullDatabase cannot hold a transaction.');
    }

    public function rollback(): void
    {
        throw new \LogicException('NullDatabase cannot hold a transaction.');
    }

    public function inTransaction(): bool
    {
        return false;
    }
}
