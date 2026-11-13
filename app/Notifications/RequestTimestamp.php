<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use DateMalformedStringException;
use DateTimeImmutable;

class RequestTimestamp
{
    public static function ceilToSecond(CarbonImmutable $date): CarbonImmutable
    {
        return $date->micro > 0 ? $date->addSecond()->startOfSecond() : $date;
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $value)) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (DateMalformedStringException) {
            return null;
        }
        if (DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        $date = CarbonImmutable::instance($date)->utc();

        return $date->year >= 1 && $date->year <= 9999 ? $date : null;
    }
}
