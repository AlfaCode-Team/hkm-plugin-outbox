<?php

declare(strict_types=1);

namespace Plugins\Outbox\Domain;

/**
 * One row of the outbox, as the relay reads it.
 *
 * THE PAYLOAD IS CARRIED AS RAW JSON, NOT AS AN ARRAY, and that is deliberate.
 * Decoding at the edge of the batch means one corrupt row — a `TEXT` column
 * that was truncated, a payload written by an older version, a byte sequence a
 * `mb_` setting mangled — throws while the batch is being assembled and takes
 * every other row with it. The relay cannot then deliver the 99 good rows
 * behind the bad one, and the failure looks like "the outbox stopped" rather
 * than "one row is corrupt".
 *
 * So the row is read verbatim and {@see decode()} is called INSIDE the relay's
 * per-row try block, where a corrupt payload parks that row and nothing else.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $eventName,
        public string $eventVersion,
        public string $payloadJson,
        public int $attempts,
    ) {}

    /**
     * The payload as an array.
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException when the stored payload is not decodable — call
     *         this where that can be attributed to this row alone.
     */
    public function decode(): array
    {
        $decoded = json_decode($this->payloadJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            // A payload that decodes to a scalar is not a payload. Refusing it
            // here keeps the event contract's `array` return honest instead of
            // handing a listener an int and letting it fail further away.
            throw new \JsonException(sprintf(
                'Outbox payload for [%s] decoded to %s, not an object.',
                $this->eventName,
                get_debug_type($decoded),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
