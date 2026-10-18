<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Publishes\Invalid\UnknownHook;

use Illuminate\Database\Eloquent\Model;
use Laravents\Attributes\Publishes;

/** "opened" is no Eloquent event. */
#[Publishes(DrawerOpened::class, on: 'opened')]
final class Drawer extends Model {}
