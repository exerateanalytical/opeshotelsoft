<?php

namespace App\Modules\POS\Application;

use App\Modules\POS\Domain\Check;

/**
 * Pre-settlement checks other modules register (e.g. Stock: the outlet's store holds the bill of materials).
 * They run inside the settle / charge-to-room transaction before the check is closed, so a refusal
 * (DomainException, 422) leaves the check open and nothing posted. Event subscribers run after commit
 * and cannot roll the settlement back, hence this hook.
 */
final class SettlementGuards
{
    /** @var array<string, callable(Check):void> */
    private static array $guards = [];

    public static function register(string $name, callable $guard): void
    {
        self::$guards[$name] = $guard;
    }

    public static function run(Check $check): void
    {
        foreach (self::$guards as $guard) {
            $guard($check);
        }
    }
}
