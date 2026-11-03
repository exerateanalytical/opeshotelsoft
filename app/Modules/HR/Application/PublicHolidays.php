<?php

namespace App\Modules\HR\Application;

use App\Modules\HR\Application\Payroll\CameroonRulePack;
use App\Modules\HR\Domain\PublicHoliday;
use App\Modules\HR\Domain\RulePack;
use Illuminate\Support\Carbon;

/**
 * Public holidays per country. Fixed-date holidays come from the country rule pack
 * (`public_holidays_fixed`, data not code) and are materialised per year on demand;
 * movable religious holidays are entered per year by an admin.
 */
class PublicHolidays
{
    /** @var array<string, true> */
    private array $seeded = [];

    public function ensureYear(string $country, int $year): void
    {
        $key = "$country-$year";
        if (isset($this->seeded[$key])) {
            return;
        }
        $this->seeded[$key] = true;
        foreach ($this->fixedFor($country) as [$md, $name]) {
            PublicHoliday::firstOrCreate(['country' => $country, 'date' => "$year-$md"], ['name' => $name, 'movable' => false]);
        }
    }

    /** @return array<string, string> date => name */
    public function between(string $country, string $from, string $to): array
    {
        foreach (range((int) substr($from, 0, 4), (int) substr($to, 0, 4)) as $y) {
            $this->ensureYear($country, $y);
        }

        return PublicHoliday::where('country', $country)->whereBetween('date', [$from, $to])->orderBy('date')->get()
            ->mapWithKeys(fn ($h) => [$h->date->toDateString() => $h->name])->all();
    }

    public function isHoliday(string $country, Carbon|string $date): bool
    {
        $d = Carbon::parse($date)->toDateString();

        return isset($this->between($country, $d, $d)[$d]);
    }

    private function fixedFor(string $country): array
    {
        $pack = RulePack::where('country', $country)->orderByDesc('effective_from')->first();
        $fixed = $pack->params['public_holidays_fixed'] ?? null;

        return $fixed ?? ($country === 'CM' ? CameroonRulePack::params()['public_holidays_fixed'] : []);
    }
}
