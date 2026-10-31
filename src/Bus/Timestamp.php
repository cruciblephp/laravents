<?php

declare(strict_types=1);

namespace Laravents\Bus;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;

/**
 * The `timestamp` header of a message: ISO 8601 in UTC with microseconds and Z, the form Carbon's toJSON() and
 * Eloquent's date serialization write, built with PHP's own DateTimeImmutable rather than through Carbon.
 *
 * Time frozen by a test (Date::setTestNow(), travelTo()) is honoured: that "now" is Carbon's, so it is read from
 * Carbon. An application that wants another form sets its own with using(), as Str::createUlidsUsing() does for
 * ids, and normally() puts the default back. Unused, the override costs one null check per message.
 *
 * Not 1:1 with Date::now()->toJSON() in one case: an application whose Date::useClass() date overrides toJSON()
 * got that form before; it now writes it with using().
 */
final class Timestamp
{
    private const string FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var (Closure(): string)|null */
    private static ?Closure $using = null;

    /** The header's value for a message sent now. */
    public static function now(): string
    {
        if (self::$using instanceof Closure) {
            return (self::$using)();
        }

        if (Carbon::hasTestNow() || CarbonImmutable::hasTestNow()) {
            return Date::now()->utc()->format(self::FORMAT);
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::FORMAT);
    }

    /** @param  Closure(): string  $using  writes the header from here on, for every message */
    public static function using(Closure $using): void
    {
        self::$using = $using;
    }

    /** The default form again. */
    public static function normally(): void
    {
        self::$using = null;
    }
}
