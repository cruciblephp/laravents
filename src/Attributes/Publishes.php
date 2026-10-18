<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * On an Eloquent model: when the model fires the Eloquent event named by $on (created, updated, deleted, saved,
 * restored, ... any of the model's observable events), Laravents builds $event from the model and publishes it on
 * the event bus. Repeatable, one attribute per event and hook. #[Publishes(NicknameGiven::class, on: 'created')].
 *
 * The publish happens inside the save, so inside whatever transaction the save is in: an #[Asynchronous] handler's
 * outbox row commits or rolls back with the model, and a sync handler runs inline before the save returns. Inside a
 * handler the event is that message's child, as any event published there; outside one it is a root message with
 * the actor from the guard.
 *
 * It is an Eloquent model listener like any other, so whatever silences those silences it: saveQuietly(),
 * updateQuietly(), deleteQuietly() and the rest of the *Quietly() family, and Model::withoutEvents(), skip the publish,
 * as they skip every model listener; so does Event::fake(), which records the model's event instead of running the
 * listeners (Event::fake([...]) only for the events it names).
 *
 * How the event is built, checked at discovery (so at laravents:cache time, not at save time):
 * - a `public static function fromModel(Model $model): static` on the event wins when present;
 * - otherwise its constructor is filled by parameter name from $model->getAttributes() through the PayloadConverter.
 *   A parameter matches an attribute by its name or its snake_case (openedBy matches opened_by). Every required
 *   parameter must name an attribute the model declares: its key, $fillable, its casts, its $attributes defaults,
 *   or its timestamp columns. An attribute only the database knows needs fromModel().
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Publishes
{
    /** @param class-string $event */
    public function __construct(
        public string $event,
        public string $on,
    ) {}
}
