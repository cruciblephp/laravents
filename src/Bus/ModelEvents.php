<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravents\Contracts\EventBus;
use Laravents\Discovery\ModelPublication;
use Laravents\Discovery\Registry;
use LogicException;

/**
 * #[Publishes] at run time: one listener per (model, hook) on the Eloquent event Laravel fires for it, the same
 * listen("eloquent.created: Nickname") that Nickname::created(...) registers. It builds the event from the model and
 * publishes it on the event bus, inside the save and so inside its transaction.
 */
final readonly class ModelEvents
{
    public function __construct(private Container $container) {}

    public function subscribe(Registry $registry, Dispatcher $events): void
    {
        $container = $this->container;

        foreach ($registry->modelPublications() as $publication) {
            // Resolved per event, so a Laravents::fake() bound later receives it.
            $events->listen($publication->eloquentEvent(), static function (Model $model) use ($container, $publication): void {
                $container->make(EventBus::class)->publish($container->make(self::class)->build($publication, $model));
            });
        }
    }

    /** The event, by its fromModel() or from the model's attributes (by name or camelCase name). */
    public function build(ModelPublication $publication, Model $model): object
    {
        $event = $publication->event;

        if ($publication->fromModel) {
            $built = $event::fromModel($model);
        } else {
            $attributes = $model->getAttributes();

            foreach ($model->getAttributes() as $name => $value) {
                $attributes[Str::camel($name)] ??= $value;
            }

            $built = $this->container->make(PayloadConverter::class)->convert($event, $attributes);
        }

        return $built instanceof $event
            ? $built
            : throw new LogicException(sprintf('%s::fromModel() returned %s.', $event, get_debug_type($built)));
    }
}
