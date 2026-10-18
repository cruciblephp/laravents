<?php

declare(strict_types=1);

namespace Laravents\Discovery;

use Illuminate\Database\Eloquent\Model;

/**
 * One #[Publishes] on a model, as plain data so the registry cache carries it: on the model's $hook Eloquent
 * event, $event is built from the model (by its fromModel() or from the model's attributes) and published.
 */
final readonly class ModelPublication
{
    /**
     * @param  class-string<Model>  $model
     * @param  class-string  $event
     */
    public function __construct(
        public string $model,
        public string $hook,
        public string $event,
        public bool $fromModel,
        public string $file,
        public int $line,
    ) {}

    /** The Eloquent event name Laravel's dispatcher fires, as Model::registerModelEvent() listens to it. */
    public function eloquentEvent(): string
    {
        return sprintf('eloquent.%s: %s', $this->hook, $this->model);
    }

    public function location(): string
    {
        return sprintf('%s (%s:%d)', $this->model, $this->file, $this->line);
    }
}
