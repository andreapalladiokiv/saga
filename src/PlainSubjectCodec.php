<?php

declare(strict_types=1);

namespace Techork\Saga;

use __PHP_Incomplete_Class;
use Throwable;

use function base64_decode;
use function base64_encode;
use function is_object;
use function is_string;
use function serialize;
use function sprintf;
use function strlen;
use function unserialize;

/**
 * `serialize()`/`unserialize()` in base64, with NO protection against a forged
 * payload.
 *
 * The base64 is not decoration — it is what makes the output text-safe, as
 * {@see SubjectCodec::encode()} requires. Without it a subject with any
 * non-public property emits NUL bytes and cannot be stored in a character
 * column. It costs ~33% in size, which is free here because the only repository
 * that uses this codec keeps everything in memory.
 *
 * Correct for a store that never leaves the process — {@see InMemorySagaStateRepository}
 * — where there is nothing to forge. NOT correct for a database, a cache, or
 * anything else another process can write: see {@see SubjectCodec} for why, and
 * use {@see \Techork\Saga\Laravel\EncryptedSubjectCodec} there.
 */
final readonly class PlainSubjectCodec implements SubjectCodec
{
    public function encode(object $subject): string
    {
        try {
            return base64_encode(serialize($subject));
        } catch (Throwable $e) {
            throw new SagaException(sprintf(
                'Saga subject %s cannot be serialized: %s. Subjects must be plain DTOs — no closures, '
                . 'resources, PDO handles or other unserializable references.',
                $subject::class,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    public function decode(string $payload, string $sagaId): object
    {
        $raw = base64_decode($payload, true);
        if (! is_string($raw)) {
            throw new SagaException(sprintf(
                "Saga '%s' has a subject payload that is not valid base64 (%d bytes).",
                $sagaId,
                strlen($payload),
            ));
        }

        $value = @unserialize($raw);

        // A subject class that was renamed or moved since the row was written
        // comes back as this. is_object() is TRUE for it, so it would otherwise
        // sail through and be handed to transition and compensation listeners,
        // which would then read nulls out of a ghost.
        if ($value instanceof __PHP_Incomplete_Class) {
            throw new SagaException(sprintf(
                "Saga '%s' holds a subject whose class no longer exists (%d bytes). It was probably "
                . 'renamed or moved since the row was written.',
                $sagaId,
                strlen($payload),
            ));
        }

        if (! is_object($value)) {
            throw new SagaException(sprintf(
                "Saga '%s' has an unreadable subject payload (%d bytes): it did not deserialize to an object.",
                $sagaId,
                strlen($payload),
            ));
        }

        return $value;
    }
}
