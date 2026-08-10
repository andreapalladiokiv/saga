<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use Psr\Clock\ClockInterface;
use Techork\Saga\SagaAlreadyExistsException;
use Techork\Saga\SagaConcurrencyException;
use Techork\Saga\SagaException;
use Techork\Saga\SagaState;
use Techork\Saga\SagaStateRepository;
use Techork\Saga\SubjectCodec;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * {@see SagaStateRepository} backed by a Laravel database connection.
 *
 * Schema (see the published migration):
 *   id          string, primary key
 *   marking     json  (place name -> 1)
 *   subject     longtext — whatever the {@see SubjectCodec} produces, which the
 *                        contract requires to be text-safe. With the default
 *                        Laravel wiring that is an authenticated-encryption
 *                        envelope, so it is neither readable nor forgeable
 *   history     json  (list<string>)
 *   version     unsigned integer (optimistic lock)
 *   created_at  datetime
 *   updated_at  datetime
 *
 * Concurrency: parallel saga branches may race to update the same row, so
 * {@see save()} applies an optimistic-lock UPDATE guarded by the version
 * carried on the incoming state. When the compare-and-set finds no row to
 * update, a {@see SagaConcurrencyException} is thrown — a dedicated type,
 * because losing this race is an infrastructure conflict and must never be
 * mistaken for a failed business step. The queue layer retries the step
 * against the winner's state instead of compensating the saga.
 */
final readonly class DatabaseSagaStateRepository implements SagaStateRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
        private SubjectCodec $codec,
        private string $table = 'sagas',
    ) {}

    public function load(string $id): ?SagaState
    {
        $row = $this->connection->table($this->table)
            ->where('id', $id)
            ->first(['id', 'marking', 'subject', 'history', 'version']);

        if ($row === null) {
            return null;
        }

        $id = (string) $row->id;

        return new SagaState(
            id: $id,
            marking: $this->decodeMarking((string) $row->marking, $id),
            subject: $this->codec->decode((string) $row->subject, $id),
            history: $this->decodeNameList((string) $row->history, $id, 'history'),
            version: (int) $row->version,
        );
    }

    public function save(SagaState $state): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // version 1 means "first save after start()" — no prior row exists.
        if ($state->version === 1) {
            $this->insert($state, $now);

            return;
        }

        $this->update($state, $now);
    }

    private function insert(SagaState $state, string $now): void
    {
        try {
            $this->connection->table($this->table)->insert([
                'id' => $state->id,
                'marking' => $this->encodeJson($state->marking),
                'subject' => $this->codec->encode($state->subject),
                'history' => $this->encodeJson($state->history),
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new SagaAlreadyExistsException($this->describe($state->id, $e), 0);
        } catch (QueryException $e) {
            throw new SagaException($this->describe($state->id, $e), 0);
        }
    }

    private function update(SagaState $state, string $now): void
    {
        // Optimistic lock: update only if the stored version is the one we
        // loaded from. 0 affected rows means a concurrent worker won the race.
        $previousVersion = $state->version - 1;

        try {
            $affected = $this->connection->table($this->table)
                ->where('id', $state->id)
                ->where('version', $previousVersion)
                ->update([
                    'marking' => $this->encodeJson($state->marking),
                    'subject' => $this->codec->encode($state->subject),
                    'history' => $this->encodeJson($state->history),
                    'version' => $state->version,
                    'updated_at' => $now,
                ]);
        } catch (QueryException $e) {
            throw new SagaException($this->describe($state->id, $e), 0);
        }

        if ($affected === 0) {
            // Zero affected rows has two very different causes, and the caller
            // needs to tell them apart: the row moved on (another worker won
            // the race) or the row is gone (another worker completed or
            // compensated the saga). Re-select to find out — neither case is a
            // business failure, so both raise SagaConcurrencyException.
            $exists = $this->connection->table($this->table)
                ->where('id', $state->id)
                ->exists();

            throw $exists
                ? SagaConcurrencyException::versionMismatch($state->id, $previousVersion)
                : SagaConcurrencyException::stateVanished($state->id);
        }
    }

    public function delete(string $id): void
    {
        try {
            $this->connection->table($this->table)
                ->where('id', $id)
                ->delete();
        } catch (QueryException $e) {
            throw new SagaException($this->describe($id, $e), 0);
        }
    }

    /**
     * Builds an error message from a database failure WITHOUT the query or its
     * bindings.
     *
     * `QueryException::formatMessage()` interpolates every binding into the
     * message unredacted, and one of the bindings here is the serialized
     * subject — the object carrying customer identifiers, addresses and payment
     * tokens. That message lands in the log, in `failed_jobs.exception` and in
     * whatever APM the application uses. Note the previous exception is
     * deliberately NOT chained: `getPrevious()` would hand the same string to
     * any handler that walks the chain.
     */
    private function describe(string $sagaId, QueryException $e): string
    {
        return \sprintf(
            "Database error while persisting saga '%s' (SQLSTATE %s). Query and bindings omitted: they "
            . 'contain the serialized subject.',
            $sagaId,
            (string) $e->getCode(),
        );
    }

    /** @param array<array-key, mixed> $value */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            throw new SagaException('Failed to encode saga field: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Decodes a JSON column, refusing anything that is not a JSON object/array.
     *
     * The previous `is_array($decoded) ? $decoded : []` turned `null`, `"x"` or
     * `7` into an empty array. For the marking column that is the worst possible
     * default: Symfony reads an empty marking as "subject not in the workflow
     * yet" and re-seeds the initial places, silently restarting the saga.
     *
     * @return array<array-key, mixed>
     */
    private function decodeArray(string $raw, string $id, string $field): array
    {
        if ($raw === '') {
            throw new SagaException(\sprintf("Saga '%s' has an empty %s column.", $id, $field));
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SagaException(
                \sprintf("Saga '%s' has malformed JSON in %s: %s", $id, $field, $e->getMessage()),
                0,
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new SagaException(\sprintf(
                "Saga '%s' has a %s column that is not a JSON object.",
                $id,
                $field,
            ));
        }

        return $decoded;
    }

    /** @return array<string, int<1, max>> */
    private function decodeMarking(string $raw, string $id): array
    {
        $decoded = $this->decodeArray($raw, $id, 'marking');

        if ($decoded === []) {
            throw new SagaException(\sprintf(
                "Saga '%s' has an empty marking. The runner never persists one, so the row is corrupt.",
                $id,
            ));
        }

        $marking = [];
        foreach ($decoded as $place => $tokens) {
            if (! is_string($place) || ! is_int($tokens) || $tokens < 1) {
                throw new SagaException(\sprintf(
                    "Saga '%s' has a malformed marking: expected place name => token count (int >= 1).",
                    $id,
                ));
            }
            $marking[$place] = $tokens;
        }

        return $marking;
    }

    /**
     * Decodes a list of transition names.
     *
     * History entries are interpolated straight into compensation event names,
     * so anything writable into this column would otherwise choose which
     * application listeners run.
     *
     * @return list<string>
     */
    private function decodeNameList(string $raw, string $id, string $field): array
    {
        $decoded = $this->decodeArray($raw, $id, $field);

        foreach ($decoded as $name) {
            if (! is_string($name)) {
                throw new SagaException(\sprintf(
                    "Saga '%s' has a malformed %s: every entry must be a transition name.",
                    $id,
                    $field,
                ));
            }
        }

        return \array_values($decoded);
    }

}
