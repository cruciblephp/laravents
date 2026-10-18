<?php

declare(strict_types=1);

namespace Laravents\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use JsonException;
use Laravents\Bus\HeaderValue;
use Laravents\Bus\Message;
use Laravents\Discovery\HandlerKind;
use Laravents\Exceptions\InvalidPayloadException;
use LogicException;

/**
 * One row per (event, asynchronous listener): the source of truth for a publication. Written inside the
 * publisher's transaction, completed by the job, re-dispatched by laravents:republish when never picked up.
 * The row lives on the connection of the innermost open transaction around the publisher (PublisherConnection),
 * else the default one, so the table must exist on every connection a publisher's transaction uses, whether
 * #[Transactional(connection:)] or a hand-written DB::connection(...)->transaction(). Completed rows older than
 * laravents.outbox.prune_after hours are deleted by model:prune, on every outbox connection.
 *
 * @property string $id The publication_id header
 * @property string $routing_key
 * @property string|null $event_class Null when the payload is an array (a keyed event)
 * @property string $event_payload The serialize()d payload as a JSON string literal, as the queue stores a job
 * @property string $listener Class@method
 * @property array<string, scalar|null> $headers Every header as published
 * @property string|null $message_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $actor_id
 * @property string|null $actor_guard
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $completed_at
 */
final class EventPublication extends Model
{
    use HasUlids;
    use MassPrunable {
        pruneAll as private pruneAllOnThisConnection;
    }

    /** Header keys copied into their own columns, for reporting and indexing. */
    private const array METADATA = ['message_id', 'correlation_id', 'causation_id', 'actor_id', 'actor_guard'];

    public $timestamps = false;

    protected $table = 'laravents_event_publications';

    protected $fillable = [
        'routing_key', 'event_class', 'event_payload', 'listener', 'headers',
        'message_id', 'correlation_id', 'causation_id', 'actor_id', 'actor_guard',
        'published_at', 'dispatched_at', 'completed_at',
    ];

    /**
     * Writes the row for one listener of a published event, on the connection of the innermost open database
     * transaction (PublisherConnection), else on the default connection, and so inside the publisher's
     * transaction. dispatched_at is set now: a job is handed over right after.
     *
     * The payload is stored as Queue::createPayload() stores a job: serialize(), then json_encode(), which turns
     * the NUL bytes of protected and private properties into \u0000, so every driver's text column accepts it.
     * A payload that is not valid UTF-8 cannot be encoded and throws, rolling the publisher back.
     *
     * @throws InvalidPayloadException
     */
    public static function record(string $listener, Message $message): self
    {
        $now = Date::now();

        try {
            $payload = json_encode(serialize($message->payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidPayloadException::for(is_object($message->payload) ? $message->payload::class : $message->routingKey, $listener, $exception);
        }

        return self::on(app(PublisherConnection::class)->name())->create([
            'routing_key' => $message->routingKey,
            'event_class' => is_object($message->payload) ? $message->payload::class : null,
            'event_payload' => $payload,
            'listener' => $listener,
            'headers' => $message->headers,
            ...array_map(
                static fn (string $key): ?string => HeaderValue::string($message->headers[$key] ?? null),
                array_combine(self::METADATA, self::METADATA),
            ),
            'published_at' => $now,
            'dispatched_at' => $now,
        ]);
    }

    /**
     * The event as published, with the publication_id header added. A row whose payload is not a JSON string
     * holding a serialized object or field-keyed array is corrupt.
     */
    public function message(): Message
    {
        $serialized = json_decode($this->event_payload);
        $payload = is_string($serialized) ? unserialize($serialized) : throw $this->corrupt();
        $headers = [...$this->headers, 'publication_id' => $this->id];

        if (is_object($payload)) {
            return new Message(HandlerKind::Event, $this->routing_key, $payload, $headers);
        }

        if (! is_array($payload)) {
            throw $this->corrupt();
        }

        $fields = [];

        foreach ($payload as $field => $value) {
            $fields[is_string($field) ? $field : throw $this->corrupt()] = $value;
        }

        return new Message(HandlerKind::Event, $this->routing_key, $fields, $headers);
    }

    /**
     * Takes the row for a new dispatch, unless someone else has re-dispatched or completed it since it was
     * read. One conditional UPDATE, so of two concurrent callers exactly one wins.
     */
    public function claim(): bool
    {
        $query = $this->newQuery()->whereKey($this->id)->whereNull('completed_at');
        $read = $this->getRawOriginal('dispatched_at');

        if ($read === null) {
            $query->whereNull('dispatched_at');
        } else {
            $query->where('dispatched_at', $read);
        }

        return $query->update(['dispatched_at' => Date::now()]) === 1;
    }

    /**
     * The database connections that hold outbox rows (laravents.outbox.connections); null is the default connection.
     *
     * @return list<string|null>
     */
    public static function outboxConnections(): array
    {
        $connections = config('laravents.outbox.connections', [null]);

        if (! is_array($connections) || $connections === []) {
            return [null];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $connection): ?string => is_string($connection) && $connection !== '' ? $connection : null,
            $connections,
        ), SORT_REGULAR));
    }

    /**
     * Rows completed longer ago than laravents.outbox.prune_after hours, Laravel's unit for pruning; with no value
     * set, none, so every row is kept.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $hours = config('laravents.outbox.prune_after');
        $query = $this->newQuery()->whereNotNull('completed_at');

        return is_numeric($hours)
            ? $query->where('completed_at', '<', Date::now()->subHours((int) $hours))
            : $query->whereRaw('1 = 0');
    }

    /** MassPrunable's pruneAll() on every outbox connection, as laravents:republish searches them all. */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $total = 0;

        foreach (self::outboxConnections() as $connection) {
            $total += (new self)->setConnection($connection)->pruneAllOnThisConnection($chunkSize);
        }

        return $total;
    }

    /** @param Builder<self> $query */
    public function scopeIncomplete(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /**
     * Never dispatched, or dispatched longer ago than $seconds.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query, int $seconds): void
    {
        $cutoff = Date::now()->subSeconds($seconds);

        $query->where(static function (Builder $query) use ($cutoff): void {
            $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $cutoff);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'published_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    private function corrupt(): LogicException
    {
        return new LogicException(sprintf('The payload of publication [%s] is not a JSON string holding a serialized object or an array keyed by field.', $this->id));
    }
}
