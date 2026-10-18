<?php

declare(strict_types=1);

/*
 * A module may use another module only through its Api namespace. Crucible's arch dialect has no `->not`,
 * and its `ignoring()` drops target classes rather than dependencies, so the rule is the whitelist form:
 * Modules\A may only use itself, Modules\B\Api, the modules the other pairs cover, and the other source
 * roots in crucible.php (Laravents, Workbench). toOnlyUse() counts only references into that source, so vendor
 * classes need no listing, and a new source root fails here instead of passing silently.
 */

$modules = array_map(basename(...), glob(dirname(__DIR__, 2).'/workbench/modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $user) {
    foreach ($modules as $used) {
        if ($user === $used) {
            continue;
        }

        $others = array_map(
            static fn (string $module): string => 'Modules\\'.$module,
            array_values(array_diff($modules, [$used])),
        );

        arch(sprintf('Modules\\%s uses Modules\\%s only through Modules\\%s\\Api', $user, $used, $used))
            ->expect('Modules\\'.$user)
            ->toOnlyUse(...[...$others, 'Modules\\'.$used.'\\Api', 'Laravents', 'Workbench']);
    }
}
