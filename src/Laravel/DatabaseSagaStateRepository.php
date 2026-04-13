<?php

declare(strict_types=1);

namespace Techork\Saga\Laravel;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use Psr\Clock\ClockInterface;
use Techork\Saga\SagaException;
use Techork\Saga\SagaState;
use Techork\Saga\SagaStateRepository;

use function is_array;
use function json_decode;
use function json_encode;
use function serialize;
use function unserialize;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * {@see SagaStateRepository} backed by a Laravel database connection.
 *
 * Schema (see the published migration):
 *   id          string, primary key
 *   marking     json  (place name -> 1)
 *   subject     longtext / blob — PHP `serialize()` payload of the typed subject
 *   history     json  (list<string>)
 *   version     unsigned integer (optimistic lock)
 *   created_at  datetime
 *   updated_at  datetime
 *
 * Concurrency: parallel saga branches may race to update the same row, so
 * {@see save()} applies an optimistic-lock UPDATE guarded by the version
 * carried on the incoming state. On version mismatch a {@see SagaException}
 * is thrown — the caller (typically the queue worker) should let the job
 * fail so Laravel re-queues it.
 */
final readonly class DatabaseSagaStateRepository implements SagaStateRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
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

        return new SagaState(
            id: (string) $row->id,
            marking: $this->decodeJson((string) $row->marking),
            subject: $this->decodeSubject((string) $row->subject),
            history: \array_values($this->decodeJson((string) $row->history)),
            version: (int) $row->version,
        );
    }

    public function save(SagaState $state): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // version 1 means "first save after start()" — no prior row exists.
        if ($state->version === 1) {
            $this->connection->table($this->table)->insert([
                'id' => $state->id,
                'marking' => $this->encodeJson($state->marking),
                'subject' => serialize($state->subject),
                'history' => $this->encodeJson($state->history),
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        // Optimistic lock: update only if the stored version is the one we
        // loaded from. 0 affected rows means a concurrent worker won the race.
        $previousVersion = $state->version - 1;
        $affected = $this->connection->table($this->table)
            ->where('id', $state->id)
            ->where('version', $previousVersion)
            ->update([
                'marking' => $this->encodeJson($state->marking),
                'subject' => serialize($state->subject),
                'history' => $this->encodeJson($state->history),
                'version' => $state->version,
                'updated_at' => $now,
            ]);

        if ($affected === 0) {
            throw new SagaException(\sprintf(
                "Optimistic lock failure saving saga '%s' at version %d (another worker won the race).",
                $state->id,
                $previousVersion,
            ));
        }
    }

    public function delete(string $id): void
    {
        $this->connection->table($this->table)
            ->where('id', $id)
            ->delete();
    }

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

    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SagaException('Failed to decode saga field: '.$e->getMessage(), 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeSubject(string $raw): object
    {
        $value = @unserialize($raw);
        if (! is_object($value)) {
            throw new SagaException('Failed to deserialize saga subject — payload is not an object.');
        }

        return $value;
    }
}
