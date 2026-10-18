<?php

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/workbench',
        __DIR__.'/tests',
    ])
    // Laravel 13's floor: never rewrite into 8.4+ syntax.
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    // Handler methods are found by attribute and typed by their first parameter: an "unused"
    // parameter or an empty listener body is meaningful, not dead.
    ->withSkip([
        RemoveUnusedPublicMethodParameterRector::class,
        RemoveEmptyClassMethodRector::class,
        // The dependency scanner's fixtures: how each reference is spelled (fully qualified, through a namespace
        // alias, only in a string) is what they test, so no tool may rewrite it.
        __DIR__.'/tests/Fixtures/Verify',
    ]);
