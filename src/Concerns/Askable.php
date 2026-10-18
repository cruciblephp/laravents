<?php

declare(strict_types=1);

namespace Laravents\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravents\Contracts\QueryBus;
use Laravents\Http\RequestPayload;

/**
 * Opt-in entry point: GetNickname::ask(...) takes the constructor's arguments, named or positional.
 */
trait Askable
{
    public static function ask(mixed ...$arguments): mixed
    {
        return app(QueryBus::class)->dispatch(new static(...$arguments)); // @phpstan-ignore argument.type (the arguments are the constructor's own, checked by PHP at call time, as in Laravel's Dispatchable)
    }

    /**
     * The query from a request's input ($request->all()), validated with rules() when the class implements
     * ValidatesPayload, then hydrated.
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request): static
    {
        return app(RequestPayload::class)->message(static::class, $request->all());
    }
}
