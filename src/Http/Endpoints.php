<?php

declare(strict_types=1);

namespace Laravents\Http;

use Illuminate\Contracts\Config\Repository;
use Laravents\Discovery\HandlerDefinition;
use Laravents\Discovery\HandlerKind;

/**
 * Where the HTTP gateway serves a handler, for laravents:list and laravents:show: POST /laravents/commands/band.give_nickname,
 * GET /laravents/queries/band.get_nickname, or nothing when the handler is not #[Expose]d or the routes are off.
 */
final readonly class Endpoints
{
    /** @param string|null $prefix The route prefix without slashes; null when laravents.http.enabled is false */
    public function __construct(private ?string $prefix) {}

    public static function fromConfig(Repository $config): self
    {
        if (! (bool) $config->get('laravents.http.enabled', true)) {
            return new self(null);
        }

        $prefix = $config->get('laravents.http.prefix', 'laravents');

        return new self(trim(is_string($prefix) ? $prefix : 'laravents', '/'));
    }

    public function for(HandlerDefinition $handler): ?string
    {
        if ($this->prefix === null || ! $handler->exposed || $handler->routingKey === null) {
            return null;
        }

        $route = match ($handler->kind) {
            HandlerKind::Command => 'POST %s/commands/%s',
            HandlerKind::Query => 'GET %s/queries/%s',
            HandlerKind::Event => null,
        };

        return $route === null ? null : sprintf($route, $this->prefix === '' ? '' : '/'.$this->prefix, $handler->routingKey);
    }
}
