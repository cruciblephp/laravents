<?php

declare(strict_types=1);

namespace Laravents\Tests\Fixtures\Deferred\Mail\Interceptors;

use Laravents\Attributes\Before;
use Laravents\Bus\Message;
use Laravents\Tests\Fixtures\Deferred\Mail\Attributes\Stamped;
use Laravents\Tests\Fixtures\Recorder;

final class Stamp
{
    #[Before(pointcut: Stamped::class)]
    public function handle(Message $message): void
    {
        Recorder::record('stamp', $message->routingKey);
    }
}
