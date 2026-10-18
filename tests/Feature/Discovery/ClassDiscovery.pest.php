<?php

declare(strict_types=1);

use Laravents\Discovery\ClassDiscovery;
use Laravents\Tests\Fixtures\ClassDiscovery\Band\Instrument;
use Laravents\Tests\Fixtures\ClassDiscovery\Band\Member;
use Laravents\Tests\Fixtures\ClassDiscovery\Band\Tunes;
use Laravents\Tests\Fixtures\ClassDiscovery\Concert;
use Laravents\Tests\Fixtures\ClassDiscovery\Gig;

test('in() lists the classes declared in the paths, abstract ones too, in path order; anonymous classes and ::class declare none', function (): void {
    expect(ClassDiscovery::in([dirname(__DIR__, 2).'/Fixtures/ClassDiscovery']))->toBe([Concert::class, Gig::class]);
});

test('structures() adds interfaces, traits and enums, in the same path order', function (): void {
    expect(ClassDiscovery::structures([dirname(__DIR__, 2).'/Fixtures/ClassDiscovery']))
        ->toBe([Instrument::class, Member::class, Tunes::class, Concert::class, Gig::class]);
});

test('a path that is not a directory is skipped', function (): void {
    expect(ClassDiscovery::in([dirname(__DIR__, 2).'/Fixtures/ClassDiscovery/missing']))->toBe([])
        ->and(ClassDiscovery::structures([]))->toBe([]);
});
