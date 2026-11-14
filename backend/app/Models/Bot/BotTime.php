<?php

namespace App\Models\Bot;

use Illuminate\Support\Carbon;

/**
 * The bot keeps subscription and temporary-token deadlines as numbers (start_sub_time, end_sub_time,
 * temp_tokens_until). BOT_TIME_UNIT says whether they are seconds (default) or milliseconds.
 */
final class BotTime
{
    private static function factor(): int
    {
        return config('neurogpt.bot.time_unit') === 'ms' ? 1000 : 1;
    }

    public static function now(): int
    {
        return self::from(now());
    }

    public static function from(\DateTimeInterface $at): int
    {
        return (int) ($at->getTimestamp() * self::factor());
    }

    public static function toCarbon(?int $value): ?Carbon
    {
        return $value ? Carbon::createFromTimestamp(intdiv((int) $value, self::factor()), config('app.timezone')) : null;
    }
}
