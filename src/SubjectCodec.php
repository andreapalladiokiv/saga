<?php

declare(strict_types=1);

namespace Techork\Saga;

/**
 * Turns a saga subject into bytes for storage and back.
 *
 * This exists because of forgery, not confidentiality. `unserialize()` does not
 * parse data — it CONSTRUCTS the class the payload names, assigns its properties
 * and runs its magic methods. So whoever can write the `subject` column chooses
 * which already-loaded class gets built, with which property values, and its
 * `__wakeup`/`__destruct` then runs inside the queue worker. The library itself
 * has no vulnerability; the precondition is write access to the row, via a SQL
 * injection elsewhere in the application, a restored backup, a replica, or a
 * tool with write access to the same database.
 *
 * The defence therefore has to be applied BEFORE `unserialize()` sees anything.
 * Checking the result — `$value instanceof OrderSubject` — is too late: by then
 * the object exists, `__wakeup` has run, and `__destruct` is queued for the
 * moment the failed check drops it.
 *
 * An implementation is responsible for guaranteeing that a payload it decodes is
 * one it encoded. {@see \Techork\Saga\Laravel\EncryptedSubjectCodec} does that
 * with authenticated encryption.
 */
interface SubjectCodec
{
    /**
     * MUST return a text-safe string — 7-bit-clean, no NUL bytes.
     *
     * Raw `serialize()` output is not: it encodes non-public property names with
     * NUL delimiters (`\0*\0cents`, `\0Order\0ref`), which is how most people
     * write a value object. A character column cannot hold that — PostgreSQL
     * silently truncates at the first NUL, MySQL in strict mode rejects the row,
     * MariaDB substitutes bytes and lets a corrupted object deserialize. Making
     * text-safety the codec's job rather than the column's means the schema needs
     * no binary type, and so does not inherit `BLOB`'s 64 KiB ceiling on MySQL.
     */
    public function encode(object $subject): string;

    /**
     * @param  string  $sagaId  for error messages only — never include the payload
     *                          itself, which carries the business data
     *
     * @throws SagaException when the payload was not produced by this codec, or
     *                       cannot be turned back into an object
     */
    public function decode(string $payload, string $sagaId): object;
}
