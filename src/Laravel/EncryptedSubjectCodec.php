<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Techork\Saga\PlainSubjectCodec;
use Techork\Saga\SagaException;
use Techork\Saga\SubjectCodec;

use function sprintf;
use function strlen;

/**
 * {@see SubjectCodec} that authenticates the payload with Laravel's encrypter.
 *
 * Takes {@see StringEncrypter}, not `Encryption\Encrypter`: encryptString() and
 * decryptString() are declared on that contract and not on the other one, so
 * naming the wrong contract compiles and then fatals at runtime — the same trap
 * as `Cache\Repository::getStore()` and `Events\Dispatcher::getListeners()`.
 *
 * Laravel's {@see \Illuminate\Encryption\Encrypter} is authenticated in every
 * cipher it supports: the CBC modes carry an HMAC-SHA256 over `iv.value`
 * compared with `hash_equals`, and the GCM modes carry an AEAD tag. So a payload
 * the application did not write fails to decrypt and never reaches
 * `unserialize()` — which is the only place a check can do any good, since
 * construction is itself the exploit.
 *
 * Two consequences worth knowing:
 *
 *  - Because provenance is guaranteed, `unserialize()` needs no `allowed_classes`
 *    allow-list. That matters practically: an allow-list is an exact match on ONE
 *    class name, so it turns every nested object inside a subject into
 *    `__PHP_Incomplete_Class` — and assigning that to a typed property is a raw
 *    TypeError. A subject like `class Order { public Money $total; }` simply
 *    could not be stored.
 *  - The column stops being readable. A backup, a replica or a BI export no
 *    longer exposes the customer identifiers, addresses and payment tokens a
 *    subject carries, and a database error that quotes its bindings quotes
 *    ciphertext.
 *
 * The key must NOT live in the database this protects — otherwise it is inside
 * the blast radius of the very access this guards against. Laravel's `APP_KEY`
 * from the environment is the right source. Key rotation works if the encrypter
 * is configured with `previous_keys`.
 */
final readonly class EncryptedSubjectCodec implements SubjectCodec
{
    private PlainSubjectCodec $inner;

    public function __construct(private StringEncrypter $encrypter)
    {
        $this->inner = new PlainSubjectCodec();
    }

    public function encode(object $subject): string
    {
        return $this->encrypter->encryptString($this->inner->encode($subject));
    }

    public function decode(string $payload, string $sagaId): object
    {
        try {
            $plain = $this->encrypter->decryptString($payload);
        } catch (DecryptException $e) {
            throw new SagaException(sprintf(
                "Saga '%s' has a subject payload this application did not write (%d bytes): %s. Either the "
                . 'row was tampered with, or the encryption key changed without the old one being kept in '
                . 'previous_keys. The payload was NOT deserialized.',
                $sagaId,
                strlen($payload),
                $e->getMessage(),
            ), 0, $e);
        }

        return $this->inner->decode($plain, $sagaId);
    }
}
