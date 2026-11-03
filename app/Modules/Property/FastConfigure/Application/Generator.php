<?php

namespace App\Modules\Property\FastConfigure\Application;

/**
 * Pure room-base generator: turns a Fast Configure form into rooms, types, rate plans and totals.
 * No database access, so the live preview and the apply step share exactly the same arithmetic.
 */
final class Generator
{
    public const BED_TYPES = ['king', 'queen', 'double', 'twin', 'single', 'sofa', 'bunk'];

    public const AMENITIES = ['wifi', 'ac', 'fan', 'tv', 'minibar', 'safe', 'balcony', 'bathtub', 'kettle', 'desk', 'sea_view', 'kitchenette'];

    public const ASSIGN = ['spread', 'top', 'ground', 'block', 'manual'];

    public const OUTLET_ITEMS = ['restaurant', 'bar', 'kitchen', 'main_store', 'housekeeping_store', 'maintenance_store'];

    public const MAX_FLOORS = 60;

    public const MAX_ROOMS = 2000;

    public static function normalize(array $c): array
    {
        $f = $c['floors'] ?? [];
        $n = $c['numbering'] ?? [];
        $mode = in_array($f['mode'] ?? 'numeric', ['numeric', 'ground', 'custom'], true) ? ($f['mode'] ?? 'numeric') : 'numeric';
        $custom = array_values(array_filter(array_map(fn ($l) => mb_substr(trim((string) $l), 0, 10), (array) ($f['custom'] ?? [])), fn ($l) => $l !== ''));
        $perFloor = [];
        foreach ((array) ($f['per_floor'] ?? []) as $label => $v) {
            if ($v !== null && $v !== '') {
                $perFloor[(string) $label] = max(0, min(200, (int) $v));
            }
        }
        $types = [];
        foreach ((array) ($c['types'] ?? []) as $i => $t) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($t['code'] ?? '')));
            $beds = [];
            foreach ((array) ($t['beds'] ?? []) as $b) {
                $cnt = max(0, min(20, (int) ($b['count'] ?? 0)));
                if ($cnt > 0 && in_array($b['type'] ?? '', self::BED_TYPES, true)) {
                    $beds[] = ['type' => $b['type'], 'count' => $cnt];
                }
            }
            $types[] = [
                'code' => substr($code, 0, 12), 'name' => mb_substr(trim((string) ($t['name'] ?? $code)), 0, 100),
                'share_mode' => ($t['share_mode'] ?? 'percent') === 'count' ? 'count' : 'percent',
                'share' => max(0, (float) ($t['share'] ?? 0)),
                'max_adults' => max(1, min(12, (int) ($t['max_adults'] ?? 2))), 'max_children' => max(0, min(12, (int) ($t['max_children'] ?? 0))),
                'beds' => $beds, 'size' => isset($t['size']) && $t['size'] !== '' ? max(1, (int) $t['size']) : null,
                'view' => mb_substr(trim((string) ($t['view'] ?? '')), 0, 30) ?: null, 'smoking' => (bool) ($t['smoking'] ?? false),
                'amenities' => self::amenityList($t),
                'base_price' => max(0, (int) ($t['base_price'] ?? 0)), 'extra_adult' => max(0, (int) ($t['extra_adult'] ?? 0)), 'extra_child' => max(0, (int) ($t['extra_child'] ?? 0)),
                'weekend_uplift' => max(0, min(500, (float) ($t['weekend_uplift'] ?? 0))), 'high_season_uplift' => max(0, min(500, (float) ($t['high_season_uplift'] ?? 0))),
                'assign' => in_array($t['assign'] ?? 'spread', self::ASSIGN, true) ? ($t['assign'] ?? 'spread') : 'spread', 'sort' => $i,
            ];
        }
        $explicit = [];
        foreach ((array) ($c['explicit_rooms'] ?? []) as $r) {
            if (($r['number'] ?? '') !== '') {
                $explicit[] = ['number' => mb_substr((string) $r['number'], 0, 10), 'floor' => (string) ($r['floor'] ?? ''), 'type' => strtoupper((string) ($r['type'] ?? ''))];
            }
        }
        $plans = [];
        foreach ((array) ($c['rate_plans'] ?? []) as $p) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($p['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $plans[] = [
                'code' => substr($code, 0, 16), 'name' => mb_substr((string) ($p['name'] ?? $code), 0, 100), 'enabled' => $code === 'BAR' ? true : (bool) ($p['enabled'] ?? true),
                'percent' => max(-90, min(300, (float) ($p['percent'] ?? 0))), 'meal_plan' => in_array($p['meal_plan'] ?? 'RO', ['RO', 'BB', 'HB', 'FB', 'AI'], true) ? ($p['meal_plan'] ?? 'RO') : 'RO',
                'refundable' => (bool) ($p['refundable'] ?? true), 'members_only' => (bool) ($p['members_only'] ?? false), 'public' => (bool) ($p['public'] ?? true),
            ];
        }
        if (! collect($plans)->contains('code', 'BAR')) {
            array_unshift($plans, ['code' => 'BAR', 'name' => 'Meilleur tarif disponible', 'enabled' => true, 'percent' => 0, 'meal_plan' => 'RO', 'refundable' => true, 'members_only' => false, 'public' => true]);
        }
        $o = $c['outlets'] ?? [];

        return [
            'hotel' => $c['hotel'] ?? [],
            'floors' => ['mode' => $mode, 'count' => max(0, min(self::MAX_FLOORS, (int) ($f['count'] ?? 1))), 'custom' => $custom, 'default_rooms' => max(0, min(200, (int) ($f['default_rooms'] ?? 10))), 'per_floor' => $perFloor],
            'numbering' => [
                'pattern' => in_array($n['pattern'] ?? 'floor100', ['floor100', 'prefix', 'letter', 'sequential'], true) ? ($n['pattern'] ?? 'floor100') : 'floor100',
                'prefix' => mb_substr(trim((string) ($n['prefix'] ?? '')), 0, 4), 'pad' => max(0, min(5, (int) ($n['pad'] ?? 0))),
                'start' => max(1, (int) ($n['start'] ?? 1)), 'skip_13' => (bool) ($n['skip_13'] ?? false),
                'skip' => array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) ($n['skip'] ?? [])), fn ($s) => $s !== '')),
            ],
            'types' => $types, 'manual_grid' => array_map('strtoupper', array_filter((array) ($c['manual_grid'] ?? []), 'is_string')),
            'explicit_rooms' => $explicit, 'rate_plans' => $plans,
            'outlets' => ['enabled' => (bool) ($o['enabled'] ?? false), 'items' => array_values(array_intersect(self::OUTLET_ITEMS, (array) ($o['items'] ?? [])))],
            'options' => ['update_existing' => (bool) ($c['options']['update_existing'] ?? false), 'remove_missing' => (bool) ($c['options']['remove_missing'] ?? false)],
        ];
    }

    public static function roomTotal(array $c): int
    {
        $c = self::normalize($c);

        return $c['explicit_rooms'] ? count($c['explicit_rooms']) : array_sum(array_column(self::floorPlan($c), 'rooms'));
    }

    /** @return list<array{label: string, value: int, rooms: int}> */
    public static function floorPlan(array $c): array
    {
        $f = $c['floors'];
        $labels = match ($f['mode']) {
            'custom' => $f['custom'],
            'ground' => array_merge(['G'], array_map('strval', $f['count'] > 0 ? range(1, $f['count']) : [])),
            default => array_map('strval', $f['count'] > 0 ? range(1, $f['count']) : []),
        };
        $out = [];
        foreach ($labels as $i => $label) {
            $value = $label === 'G' ? 0 : (ctype_digit($label) ? (int) $label : $i + 1);
            $out[] = ['label' => $label, 'value' => $value, 'rooms' => $f['per_floor'][$label] ?? $f['default_rooms']];
        }

        return $out;
    }

    public static function generate(array $raw): array
    {
        $c = self::normalize($raw);
        $warnings = [];
        $slots = $c['explicit_rooms'] ? self::explicitSlots($c) : self::slots($c, $warnings);
        $typesByCode = collect($c['types'])->keyBy('code');

        if ($c['explicit_rooms']) {
            $assigned = array_map(fn ($s) => $s['forced'] ?? null, $slots);
            $counts = [];
        } else {
            $counts = self::counts($c['types'], count($slots), $warnings);
            $assigned = self::assign($c['types'], $slots, $counts);
            foreach ($c['manual_grid'] as $num => $code) {
                foreach ($slots as $i => $s) {
                    if ($s['number'] === (string) $num && isset($typesByCode[$code])) {
                        $assigned[$i] = $code;
                    }
                }
            }
        }

        $rooms = [];
        $seen = [];
        foreach ($slots as $i => $s) {
            $code = $assigned[$i] ?? null;
            if ($code === null || ! isset($typesByCode[$code])) {
                $warnings[] = ['code' => 'unassigned_room', 'params' => ['number' => $s['number']]];

                continue;
            }
            if (isset($seen[$s['number']])) {
                $warnings[] = ['code' => 'duplicate_number', 'params' => ['number' => $s['number']]];

                continue;
            }
            $seen[$s['number']] = true;
            $t = $typesByCode[$code];
            $rooms[] = [
                'number' => $s['number'], 'floor' => $s['floor'], 'type' => $code, 'beds' => self::beds($t), 'price' => $t['base_price'],
                'weekend_price' => self::weekend($t), 'view' => $t['view'], 'smoking' => $t['smoking'], 'capacity' => $t['max_adults'] + $t['max_children'],
            ];
        }

        $byType = array_count_values(array_column($rooms, 'type'));
        $types = [];
        foreach ($c['types'] as $t) {
            $n = $byType[$t['code']] ?? 0;
            $t['rooms'] = $n;
            $t['beds_total'] = self::beds($t);
            $t['bed_type'] = implode('+', array_map(fn ($b) => $b['type'], $t['beds'])) ?: null;
            $t['weekend_price'] = self::weekend($t);
            $t['high_season_price'] = (int) (round($t['base_price'] * (1 + $t['high_season_uplift'] / 100) / 500) * 500);
            $types[] = $t;
            if ($n === 0) {
                $warnings[] = ['code' => 'type_no_rooms', 'params' => ['code' => $t['code']]];
            }
        }
        $warnings = array_merge($warnings, self::typeWarnings($c['types']));

        $floors = [];
        foreach ($rooms as $r) {
            $floors[$r['floor']][] = $r;
        }
        $floorList = [];
        foreach ($floors as $label => $list) {
            $floorList[] = ['label' => (string) $label, 'rooms' => $list];
        }

        $plans = self::plans($c);

        return [
            'config' => $c, 'rooms' => $rooms, 'floors' => $floorList, 'types' => $types, 'rate_plans' => $plans,
            'totals' => self::totals($rooms, $types), 'warnings' => $warnings,
        ];
    }

    public static function beds(array $t): int
    {
        return (int) array_sum(array_column($t['beds'], 'count'));
    }

    public static function weekend(array $t): int
    {
        return (int) (round($t['base_price'] * (1 + $t['weekend_uplift'] / 100) / 500) * 500);
    }

    /** Derived plan price from the BAR price. */
    public static function planPrice(int $bar, float $percent): int
    {
        return (int) round($bar * (1 + $percent / 100));
    }

    private static function plans(array $c): array
    {
        $out = [];
        foreach ($c['rate_plans'] as $p) {
            $p['prices'] = [];
            foreach ($c['types'] as $t) {
                $p['prices'][$t['code']] = $p['code'] === 'BAR' ? $t['base_price'] : self::planPrice($t['base_price'], $p['percent']);
            }
            $out[] = $p;
        }

        return $out;
    }

    private static function totals(array $rooms, array $types): array
    {
        $n = count($rooms);
        $revenue = (int) array_sum(array_column($rooms, 'price'));
        $beds = 0;
        $cap = 0;
        foreach ($types as $t) {
            $beds += $t['rooms'] * $t['beds_total'];
            $cap += $t['rooms'] * ($t['max_adults'] + $t['max_children']);
        }

        return [
            'rooms' => $n, 'floors' => count(array_unique(array_column($rooms, 'floor'))), 'beds' => $beds, 'capacity' => $cap,
            'revenue_100' => $revenue, 'revenue_60' => (int) round($revenue * 0.6), 'avg_rate' => $n ? (int) round($revenue / $n) : 0,
            'weekend_revenue_100' => (int) array_sum(array_column($rooms, 'weekend_price')),
        ];
    }

    private static function typeWarnings(array $types): array
    {
        $w = [];
        $pct = array_filter($types, fn ($t) => $t['share_mode'] === 'percent');
        if ($pct && abs(array_sum(array_column($pct, 'share')) - 100) > 0.01) {
            $w[] = ['code' => 'shares_not_100', 'params' => ['sum' => round(array_sum(array_column($pct, 'share')), 2)]];
        }
        $codes = array_column($types, 'code');
        foreach (array_count_values($codes) as $code => $n) {
            if ($n > 1 || $code === '') {
                $w[] = ['code' => $code === '' ? 'type_no_code' : 'duplicate_type', 'params' => ['code' => $code]];
            }
        }
        foreach ($types as $t) {
            if ($t['base_price'] <= 0) {
                $w[] = ['code' => 'price_zero', 'params' => ['code' => $t['code']]];
            }
            if ($t['base_price'] > 0 && ($t['extra_adult'] > $t['base_price'] || $t['extra_child'] > $t['base_price'])) {
                $w[] = ['code' => 'extra_above_base', 'params' => ['code' => $t['code']]];
            }
            if ($t['weekend_uplift'] > 100 || $t['high_season_uplift'] > 100) {
                $w[] = ['code' => 'uplift_high', 'params' => ['code' => $t['code']]];
            }
            if (! $t['beds']) {
                $w[] = ['code' => 'no_beds', 'params' => ['code' => $t['code']]];
            }
        }
        $priced = array_values(array_filter($types, fn ($t) => $t['base_price'] > 0));
        foreach ($priced as $a) {
            foreach ($priced as $b) {
                if ($a['code'] !== $b['code'] && $a['max_adults'] > $b['max_adults'] && $a['base_price'] < $b['base_price']) {
                    $w[] = ['code' => 'price_inversion', 'params' => ['cheap' => $a['code'], 'dear' => $b['code']]];
                }
            }
        }

        return $w;
    }

    /** @return list<array{floor: string, number: string, forced?: string}> */
    private static function explicitSlots(array $c): array
    {
        return array_map(fn ($r) => ['floor' => $r['floor'] === '' ? '1' : $r['floor'], 'number' => $r['number'], 'forced' => $r['type']], $c['explicit_rooms']);
    }

    /** @return list<array{floor: string, number: string}> */
    private static function slots(array $c, array &$warnings): array
    {
        $plan = self::floorPlan($c);
        if (array_sum(array_column($plan, 'rooms')) > self::MAX_ROOMS) {
            $warnings[] = ['code' => 'too_many_rooms', 'params' => ['max' => self::MAX_ROOMS]];
            $left = self::MAX_ROOMS;
            foreach ($plan as &$p) {
                $p['rooms'] = min($p['rooms'], $left);
                $left -= $p['rooms'];
            }
            unset($p);
        }
        $n = $c['numbering'];
        $slots = [];
        $seq = $n['start'] - 1;
        foreach ($plan as $idx => $floor) {
            $k = 0;
            $made = 0;
            $guard = 0;
            while ($made < $floor['rooms'] && $guard++ < 1000) {
                $k++;
                $seq++;
                [$num, $core] = match ($n['pattern']) {
                    'sequential' => [$seq, $seq],
                    'letter' => [chr(65 + ($idx % 26)).str_pad((string) $k, max(2, $n['pad']), '0', STR_PAD_LEFT), $k],
                    'prefix' => [$n['prefix'].($floor['value'] * 100 + $k), $floor['value'] * 100 + $k],
                    default => [$floor['value'] * 100 + $k, $floor['value'] * 100 + $k],
                };
                if (is_int($num) && $n['pad'] > 0) {
                    $num = str_pad((string) $num, $n['pad'], '0', STR_PAD_LEFT);
                } elseif ($n['pattern'] === 'prefix' && $n['pad'] > 0) {
                    $num = $n['prefix'].str_pad((string) $core, $n['pad'], '0', STR_PAD_LEFT);
                }
                $num = (string) $num;
                $thirteen = $n['skip_13'] && ($n['pattern'] === 'sequential' ? $core === 13 : ($n['pattern'] === 'letter' ? $k === 13 : $core % 100 === 13 || $floor['label'] === '13'));
                if ($thirteen || in_array($num, $n['skip'], true) || in_array((string) $core, $n['skip'], true)) {
                    continue;
                }
                $slots[] = ['floor' => $floor['label'], 'number' => $num];
                $made++;
            }
            $seq = $n['pattern'] === 'sequential' ? $seq : $n['start'] - 1;
        }
        $nums = array_column($slots, 'number');
        foreach (array_keys(array_filter(array_count_values($nums), fn ($x) => $x > 1)) as $dup) {
            $warnings[] = ['code' => 'duplicate_number', 'params' => ['number' => (string) $dup]];
        }

        return $slots;
    }

    /** Largest-remainder allocation: counts always add up exactly to the rooms available. */
    private static function counts(array $types, int $total, array &$warnings): array
    {
        $counts = array_fill_keys(array_column($types, 'code'), 0);
        $remaining = $total;
        foreach ($types as $t) {
            if ($t['share_mode'] === 'count') {
                $c = min((int) round($t['share']), max(0, $remaining));
                if ($c < (int) round($t['share'])) {
                    $warnings[] = ['code' => 'counts_exceed', 'params' => ['code' => $t['code']]];
                }
                $counts[$t['code']] = $c;
                $remaining -= $c;
            }
        }
        $pct = array_values(array_filter($types, fn ($t) => $t['share_mode'] === 'percent'));
        $sum = array_sum(array_column($pct, 'share'));
        if ($pct && $sum > 0) {
            $quota = [];
            $floorSum = 0;
            foreach ($pct as $i => $t) {
                $q = $remaining * $t['share'] / $sum;
                $quota[$i] = [floor($q + 1e-9), $q - floor($q + 1e-9)];
                $floorSum += $quota[$i][0];
            }
            $left = $remaining - $floorSum;
            $order = array_keys($quota);
            usort($order, fn ($a, $b) => $quota[$b][1] <=> $quota[$a][1] ?: $a <=> $b);
            foreach ($pct as $i => $t) {
                $counts[$t['code']] += (int) $quota[$i][0];
            }
            foreach ($order as $i) {
                if ($left <= 0) {
                    break;
                }
                $counts[$pct[$i]['code']]++;
                $left--;
            }
        } elseif ($remaining > 0 && $types && ! array_filter($types, fn ($t) => $t['share_mode'] === 'count')) {
            $warnings[] = ['code' => 'no_shares', 'params' => []];
        }
        if (array_sum($counts) < $total && ! array_filter($types, fn ($t) => $t['share_mode'] === 'percent')) {
            $warnings[] = ['code' => 'counts_short', 'params' => ['missing' => $total - array_sum($counts)]];
        }

        return $counts;
    }

    /** @return array<int, ?string> slot index => type code */
    private static function assign(array $types, array $slots, array $counts): array
    {
        $assigned = array_fill(0, count($slots), null);
        $free = array_keys($slots);
        $take = function (string $code, int $n, bool $fromEnd) use (&$free, &$assigned) {
            for ($k = 0; $k < $n && $free; $k++) {
                $i = $fromEnd ? array_pop($free) : array_shift($free);
                $assigned[$i] = $code;
            }
        };
        foreach ($types as $t) {
            if (in_array($t['assign'], ['ground', 'block'], true)) {
                $take($t['code'], $counts[$t['code']] ?? 0, false);
            }
        }
        foreach ($types as $t) {
            if ($t['assign'] === 'top') {
                $take($t['code'], $counts[$t['code']] ?? 0, true);
            }
        }
        $free = array_values($free);
        $spread = array_values(array_filter($types, fn ($t) => in_array($t['assign'], ['spread', 'manual'], true) && ($counts[$t['code']] ?? 0) > 0));
        $R = count($free);
        $given = array_fill_keys(array_column($spread, 'code'), 0);
        for ($j = 0; $j < $R && $spread; $j++) {
            $best = null;
            $bestScore = -INF;
            foreach ($spread as $t) {
                $cnt = $counts[$t['code']];
                if ($given[$t['code']] >= $cnt) {
                    continue;
                }
                $score = $cnt * ($j + 1) / $R - $given[$t['code']];
                if ($score > $bestScore + 1e-12) {
                    $best = $t['code'];
                    $bestScore = $score;
                }
            }
            if ($best === null) {
                break;
            }
            $given[$best]++;
            $assigned[$free[$j]] = $best;
        }

        return $assigned;
    }

    private static function amenityList(array $t): array
    {
        $out = [];
        foreach ((array) ($t['amenities'] ?? []) as $a) {
            $a = trim((string) $a);
            if ($a !== '') {
                $out[] = $a;
            }
        }
        foreach (explode(',', (string) ($t['amenities_extra'] ?? '')) as $a) {
            $a = trim($a);
            if ($a !== '') {
                $out[] = mb_substr($a, 0, 40);
            }
        }

        return array_values(array_unique($out));
    }
}
