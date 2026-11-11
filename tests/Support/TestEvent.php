<?php

declare(strict_types=1);

namespace Tests\Plugins\Outbox\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/** An integration event with whatever name and payload a test needs. */
final readonly class TestEvent implements IntegrationEventContract
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private string $name = 'test.happened',
        private array $payload = ['ok' => true],
        private string $version = '1.0',
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
