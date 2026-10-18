<?php

use LucianoPereira\Crucible\Configuration\Crucible;

return Crucible::configure()
    ->bootstrap('vendor/autoload.php')
    ->testSuite('unit', 'tests/Unit')
    ->testSuite('feature', 'tests/Feature')
    ->testSuite('architecture', 'tests/Architecture')
    ->source(include: ['src', 'workbench/modules', 'workbench/app'])
    ->build();
