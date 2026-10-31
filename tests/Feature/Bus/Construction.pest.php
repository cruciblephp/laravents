<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Laravents\Bus\PayloadConverter;
use Laravents\Tests\Fixtures\Bus\Converter\AbstractGig;
use Laravents\Tests\Fixtures\Bus\Converter\BareGig;
use Laravents\Tests\Fixtures\Bus\Converter\Booker;
use Laravents\Tests\Fixtures\Bus\Converter\EmptyGig;
use Laravents\Tests\Fixtures\Bus\Converter\Gig;
use Laravents\Tests\Fixtures\Bus\Converter\GigMarker;
use Laravents\Tests\Fixtures\Bus\Converter\MarkedGig;
use Laravents\Tests\Fixtures\Bus\Converter\SelfBuiltGig;
use Laravents\Tests\Fixtures\Bus\Converter\Ticketed;

/*
 * The converter builds a message without the container when nothing of the container's own would take part. Each
 * test registers one thing makeWith() would run, and the message is built as the container builds it.
 */

/**
 * @param  array<string, mixed>  $payload
 * @param  class-string  $class
 */
function convertGig(array $payload = ['venue' => 'The Cavern'], string $class = Gig::class): object
{
    return app(PayloadConverter::class)->convert($class, $payload);
}

test('a container callback for the message class, a type it implements, or every type, still runs', function (Closure $register): void {
    /** @var ArrayObject<int, mixed> $seen */
    $seen = new ArrayObject;
    $register(static function (mixed ...$arguments) use ($seen): void {
        $seen[] = $arguments[0];
    });

    expect(convertGig())->toEqual(new Gig('The Cavern'))
        ->and(count($seen))->toBe(1);
})->with([
    'resolving the class' => [static fn (Closure $seen) => app()->resolving(Gig::class, $seen)],
    'resolving an interface' => [static fn (Closure $seen) => app()->resolving(Ticketed::class, $seen)],
    'resolving anything' => [static fn (Closure $seen) => app()->resolving(static fn (object $object) => $object instanceof Gig ? $seen($object) : null)],
    'after resolving the class' => [static fn (Closure $seen) => app()->afterResolving(Gig::class, $seen)],
    'after resolving anything' => [static fn (Closure $seen) => app()->afterResolving(static fn (object $object) => $object instanceof Gig ? $seen($object) : null)],
    'before resolving the class' => [static fn (Closure $seen) => app()->beforeResolving(Gig::class, $seen)],
    'before resolving an interface' => [static fn (Closure $seen) => app()->beforeResolving(Ticketed::class, $seen)],
    'before resolving anything' => [static fn (Closure $seen) => app()->beforeResolving(static fn (string $abstract) => $abstract === Gig::class ? $seen($abstract) : null)],
]);

test('an extender or a binding still decides the message', function (Closure $register): void {
    $register();

    expect(convertGig())->toEqual(new Gig('decided by the container'));
})->with([
    'extend' => [static fn () => app()->extend(Gig::class, static fn (): Gig => new Gig('decided by the container'))],
    'bind' => [static fn () => app()->bind(Gig::class, static fn (): Gig => new Gig('decided by the container'))],
]);

test('a contextual binding for what the container is building now, by the class or an alias of it, still gives the message', function (string $needed): void {
    app()->alias(Gig::class, 'laravents.gig');
    app()->when(Booker::class)->needs($needed)->give(static fn (): Gig => new Gig('given in context'));

    expect(app()->call([new Booker, 'book']))->toEqual(new Gig('given in context'))
        ->and(convertGig())->toEqual(new Gig('The Cavern'));
})->with([
    'the class' => [Gig::class],
    'an alias' => ['laravents.gig'],
]);

test('a class attribute\'s callback still sees the message', function (): void {
    /** @var ArrayObject<int, mixed> $seen */
    $seen = new ArrayObject;
    app()->afterResolvingAttribute(GigMarker::class, static function (GigMarker $marker, object $gig) use ($seen): void {
        $seen[] = $gig;
    });

    expect(convertGig(class: MarkedGig::class))->toEqual(new MarkedGig('The Cavern'))
        ->and(count($seen))->toBe(1);
});

test('a SelfBuilding message still builds itself', function (): void {
    expect(convertGig(class: SelfBuiltGig::class))->toEqual(new SelfBuiltGig('built by itself'));
});

test('a class with no constructor, or one with no parameters, is made as the container makes it', function (): void {
    expect(convertGig(class: BareGig::class))->toBeInstanceOf(BareGig::class)
        ->and(convertGig([], EmptyGig::class))->toBeInstanceOf(EmptyGig::class)
        ->and(app()->resolved(EmptyGig::class))->toBeTrue();
});

test('a class that cannot be instantiated is a payload that cannot build it', function (): void {
    expect(static fn (): object => convertGig(class: AbstractGig::class))
        ->toThrow(ValidationException::class, 'The payload cannot build AbstractGig.');
});
