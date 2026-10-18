<?php

use Laravents\Bus\CommandBus;
use Laravents\Bus\EventBus;
use Laravents\Bus\QueryBus;
use Laravents\Discovery\KeyPattern;
use Laravents\Discovery\Registry;
use Laravents\Discovery\RegistryBuilder;
use Laravents\LaraventsServiceProvider;

arch('the Laravents core never references App, Modules or the example application in Workbench')
    ->expect('Laravents')
    ->toOnlyUse('Laravents');

arch('only the service provider names the concrete buses; everything else depends on the contracts, so a fake bound to them reaches every path')
    ->expect(CommandBus::class, QueryBus::class, EventBus::class)
    ->toOnlyBeUsedIn(LaraventsServiceProvider::class);

arch('routing-key patterns are compiled at discovery and matched in the registry, nowhere else: a second matcher fails here')
    ->expect(KeyPattern::class)
    ->toOnlyBeUsedIn(Registry::class, RegistryBuilder::class);
