<?php

declare(strict_types=1);

namespace Laravents\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravents\Contracts\CommandBus;
use Laravents\Http\RequestPayload;

/**
 * Opt-in entry point: GiveNickname::send(...) takes the constructor's arguments, named or positional.
 */
trait Sendable
{
    public static function send(mixed ...$arguments): mixed
    {
        return app(CommandBus::class)->dispatch(new static(...$arguments)); // @phpstan-ignore argument.type (the arguments are the constructor's own, checked by PHP at call time, as in Laravel's Dispatchable)
    }

    /**
     * The command from a request's input ($request->all()), validated with rules() when the class implements
     * ValidatesPayload, then hydrated: GiveNickname::fromRequest($request)->send() is not needed, dispatch it as it is.
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request): static
    {
        return app(RequestPayload::class)->message(static::class, $request->all());
    }
}
