<?php

declare(strict_types=1);

namespace Laravents\Discovery;

/**
 * When a handler runs: inline, after the response in the same process (#[Deferred]), or on a queue
 * (#[Asynchronous]). Commands and queries are always Sync.
 */
enum HandlerMode: string
{
    case Sync = 'sync';
    case Deferred = 'deferred';
    case Async = 'async';
}
