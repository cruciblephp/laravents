<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Invocation\Direct\Handlers;

use Laravents\Attributes\CommandHandler;
use Laravents\Tests\Fixtures\Invocation\Direct\Stamp;
use Laravents\Tests\Fixtures\Recorder;

/**
 * Handlers whose every parameter the engine names, each with one thing the container's call would do besides
 * calling it. Not final: a test binds a subclass in its place.
 */
class DirectHandlers
{
    /** @param  array<string, mixed>  $payload */
    #[CommandHandler('direct.nothing')]
    public function nothing(array $payload): null
    {
        Recorder::record('direct.nothing', $payload);

        return null;
    }

    /** @param  array<string, mixed>  $payload */
    #[CommandHandler('direct.voice')]
    public function voice(array $payload): string
    {
        return 'quiet';
    }

    /** @param  array<string, mixed>  $payload */
    #[CommandHandler('direct.greeting')]
    public function greeting(array $payload): mixed
    {
        return app('laravents.direct.greeting');
    }

    /** @param  array<string, mixed>  $payload */
    #[CommandHandler('direct.stamped')]
    public function stamped(#[Stamp] array $payload): string
    {
        return 'stamped';
    }

    /**
     * @param  array<string, mixed>  ...$payloads
     * @return array<array-key, array<string, mixed>>
     */
    #[CommandHandler('direct.gathered')]
    public function gathered(array ...$payloads): array
    {
        return $payloads;
    }
}
