<?php

declare(strict_types=1);

use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Facades\Laravents;
use Laravents\Tests\Fixtures\Invocation\Direct\Handlers\DirectHandlers;
use Laravents\Tests\Fixtures\Invocation\Direct\Handlers\LouderHandlers;
use Laravents\Tests\Fixtures\Invocation\Direct\Stamp;
use Laravents\Tests\Fixtures\Recorder;

/*
 * A handler whose every parameter the engine names is called without the container's call. Each test sets up one
 * thing that call does besides calling the method, and the handler answers as the container's call makes it answer.
 */

beforeEach(function (): void {
    Recorder::reset();
    app()->instance(Registry::class, (new RegistryBuilder('Laravents\Tests\Fixtures\Invocation\Direct'))->build([DirectHandlers::class]));
});

test('a handler that answers null runs once', function (): void {
    expect(Laravents::command('direct.nothing', ['n' => 1]))->toBeNull()
        ->and(Recorder::calls())->toBe([['direct.nothing', ['n' => 1]]]);
});

test('a method binding still answers for the handler', function (): void {
    app()->bindMethod(DirectHandlers::class.'@voice', static fn (): string => 'bound');

    expect(Laravents::command('direct.voice', []))->toBe('bound');
});

test('a class bound in the handler\'s place answers with its own method', function (): void {
    app()->bind(DirectHandlers::class, LouderHandlers::class);

    expect(Laravents::command('direct.voice', []))->toBe('loud');
});

test('the handler\'s body still resolves in the handler\'s context', function (): void {
    app()->when(DirectHandlers::class)->needs('laravents.direct.greeting')->give(static fn (): string => 'in context');

    expect(Laravents::command('direct.greeting', []))->toBe('in context');
});

test('a parameter attribute\'s callback still sees the argument', function (): void {
    $seen = new ArrayObject;
    app()->afterResolvingAttribute(Stamp::class, static function (Stamp $stamp, mixed $argument) use ($seen): void {
        $seen[] = $argument;
    });

    expect(Laravents::command('direct.stamped', ['n' => 1]))->toBe('stamped')
        ->and($seen->getArrayCopy())->toBe([['n' => 1]]);
});

test('a variadic parameter still takes the payload as its one positional value', function (): void {
    expect(Laravents::command('direct.gathered', ['n' => 1, 'm' => 2]))->toBe([['n' => 1, 'm' => 2]]);
});
