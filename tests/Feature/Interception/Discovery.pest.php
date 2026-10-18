<?php

declare(strict_types=1);

use Laravents\Discovery\InterceptorDefinition;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\Exceptions\DiscoveryException;
use Laravents\Tests\Fixtures\Interception\Invalid;

test('discovery rejects an interceptor that breaks a rule', function (string $class, string $message): void {
    /** @var class-string $class */
    expect(fn (): Registry => (new RegistryBuilder('Laravents\Tests\Fixtures\Interception'))->build([$class]))
        ->toThrow(DiscoveryException::class, $message);
})->with([
    'static method' => [Invalid\StaticInterceptor::class, 'must be a public, non-static method'],
    'unknown pointcut' => [Invalid\UnknownPointcut::class, 'has an unknown pointcut [Laravents\Tests\Fixtures\Interception\Nope]'],
    'Around without MethodInvocation' => [Invalid\AroundWithoutInvocation::class, 'is an #[Around] without a MethodInvocation parameter'],
    'two kinds on one method' => [Invalid\TwoInterceptorKinds::class, 'has more than one of #[Before], #[Around] and #[After]'],
]);

test('the built-in interceptors are always registered, Authorize at -1000 and Transactional at -500', function (): void {
    $registry = (new RegistryBuilder('Laravents\Tests\Fixtures\Interception'))->build([]);

    expect(array_map(fn (InterceptorDefinition $interceptor): array => [$interceptor->kind->value, $interceptor->precedence], $registry->interceptors()))
        ->toBe([['before', -1000], ['around', -500]]);
});
