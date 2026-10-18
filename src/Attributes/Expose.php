<?php

declare(strict_types=1);

namespace Laravents\Attributes;

use Attribute;

/**
 * Makes a command or query handler reachable over HTTP: POST /laravents/commands/{key} or GET|POST
 * /laravents/queries/{key}. Everything else is a 404. Not allowed on event handlers: events are not requested.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Expose {}
