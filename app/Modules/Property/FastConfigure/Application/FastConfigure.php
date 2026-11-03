<?php

namespace App\Modules\Property\FastConfigure\Application;

use App\Models\User;
use App\Modules\Accounting\Application\Provisioner;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fast Configure application service: generates a whole room base (types, rooms, rate plans, outlets, stores)
 * from one form, idempotently, in one transaction. Never deletes anything that has reservations or history.
 */
final class FastConfigure
{
    private const OUTLETS = ['restaurant' => ['REST', 'Restaurant', 'restaurant'], 'bar' => ['BAR', 'Lobby Bar', 'bar']];

    // item => [code, name, kind, department, outlet item]
    private const STORES = [
        'main_store' => ['MAIN', 'Magasin central', 'main', null, null],
        'kitchen' => ['KIT', 'Cuisine', 'kitchen', 'fnb', 'restaurant'],
        'bar' => ['BARS', 'Stock bar', 'bar', 'beverage', 'bar'],
        'housekeeping_store' => ['HSK', "Lingerie / produits d'accueil", 'housekeeping', 'rooms', null],
        'maintenance_store' => ['MNT', 'Pièces maintenance', 'maintenance', 'maintenance', null],
    ];

    public function __construct(private TenantContext $ctx, private EventBus $bus) {}

    /** Live preview: generated base plus a diff against the target hotel (exact: a rolled-back dry run). */
    public function preview(array $config, User $actor): array
    {
        $gen = Generator::generate($config);
        $diff = null;
        $hotelId = $config['hotel']['id'] ?? null;
        if ($hotelId) {
            $diff = $this->apply($config, $actor, dryRun: true);
        } elseif (! empty($config['hotel']['create'])) {
            $diff = ['new_hotel' => true];
        }

        return $gen + ['diff' => $diff];
    }

    /** Apply (or dry-run) a configuration. Returns the full report. */
    public function apply(array $config, User $actor, bool $dryRun = false): array
    {
        $gen = Generator::generate($config);
        $c = $gen['config'];
        if (! $c['types']) {
            throw new DomainException('Define at least one room type.', 'fastconfig-no-types');
        }
        if (! $gen['rooms'] && ! $c['types']) {
            throw new DomainException('Nothing to generate.', 'fastconfig-empty');
        }
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        DB::beginTransaction();
        try {
            [$hotel, $created] = $this->resolveHotel($c['hotel'], $actor);
            $report = ['hotel' => ['id' => $hotel->id, 'code' => $hotel->code, 'name' => $hotel->name, 'created' => $created], 'dry_run' => $dryRun];
            $report['room_types'] = $this->types($gen, $c);
            $report['rooms'] = $this->rooms($gen, $c);
            $report['rate_plans'] = $this->ratePlans($gen, $c);
            [$report['outlets'], $report['stores']] = $this->outlets($c);
            $report['totals'] = $gen['totals'];
            $report['warnings'] = $gen['warnings'];
            $report['blocked'] = array_merge(
                array_map(fn ($b) => ['kind' => 'room'] + $b, $report['rooms']['blocked']),
                array_map(fn ($b) => ['kind' => 'room_type'] + $b, $report['room_types']['blocked']),
            );
            if (! $dryRun) {
                $this->bus->record('property.fast_configure.applied', ['hotel_id' => $hotel->id, 'rooms_created' => count($report['rooms']['created']), 'types_created' => count($report['room_types']['created']), 'blocked' => count($report['blocked'])], 'hotel', $hotel->id);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $this->ctx->set(...$saved);
        }

        return $report;
    }

    /** Export a hotel's current room base in the same shape the form imports. */
    public function export(Hotel $hotel): array
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($hotel->group_id, $hotel->id, array_values(array_unique(array_merge($saved[2] ?? [], [$hotel->id]))), $saved[3]);
        try {
            $types = RoomType::orderBy('sort')->orderBy('id')->get();
            $rooms = Room::orderBy('id')->get()->sortBy(fn ($r) => sprintf('%s|%s', str_pad((string) $r->floor, 10, '0', STR_PAD_LEFT), str_pad($r->number, 10, '0', STR_PAD_LEFT)));
            $codes = $types->pluck('code', 'id');
            $counts = $rooms->countBy('room_type_id');
            $plans = RatePlan::orderBy('id')->get();
            $planCodes = $plans->pluck('code', 'id');
            $stores = Store::pluck('code')->all();
            $outlets = Outlet::pluck('code')->all();
            $items = [];
            foreach (['restaurant' => in_array('REST', $outlets, true), 'bar' => in_array('BAR', $outlets, true), 'kitchen' => in_array('KIT', $stores, true), 'main_store' => in_array('MAIN', $stores, true),
                'housekeeping_store' => in_array('HSK', $stores, true), 'maintenance_store' => in_array('MNT', $stores, true)] as $k => $on) {
                if ($on) {
                    $items[] = $k;
                }
            }

            return [
                'hotel' => ['create' => ['name' => $hotel->name, 'city' => $hotel->city, 'stars' => $hotel->stars]],
                'floors' => ['mode' => 'custom', 'custom' => $rooms->pluck('floor')->map(fn ($f) => (string) $f)->unique()->values()->all(), 'count' => 0, 'default_rooms' => 0, 'per_floor' => []],
                'numbering' => ['pattern' => 'floor100', 'prefix' => '', 'pad' => 0, 'skip_13' => false, 'skip' => []],
                'explicit_rooms' => $rooms->map(fn ($r) => ['number' => $r->number, 'floor' => (string) $r->floor, 'type' => $codes[$r->room_type_id] ?? ''])->values()->all(),
                'types' => $types->map(fn (RoomType $t) => [
                    'code' => $t->code, 'name' => $t->name, 'share_mode' => 'count', 'share' => $counts[$t->id] ?? 0, 'max_adults' => $t->max_adults, 'max_children' => $t->max_children,
                    'beds' => $t->bed_config ?? [], 'size' => $t->size_sqm, 'view' => $t->view, 'smoking' => (bool) $t->smoking, 'amenities' => $t->amenities ?? [], 'amenities_extra' => '',
                    'base_price' => (int) $t->base_rate, 'extra_adult' => (int) $t->extra_adult_price, 'extra_child' => (int) $t->extra_child_price,
                    'weekend_uplift' => (float) $t->weekend_uplift_percent, 'high_season_uplift' => (float) $t->high_season_uplift_percent, 'assign' => 'manual',
                ])->values()->all(),
                'rate_plans' => $plans->map(fn (RatePlan $p) => [
                    'code' => $p->code, 'name' => $p->name, 'enabled' => (bool) $p->active, 'percent' => $p->parent_rate_plan_id ? (float) $p->derived_adjust_percent : 0, 'meal_plan' => $p->meal_plan,
                    'refundable' => (bool) $p->refundable, 'members_only' => (bool) $p->members_only, 'public' => (bool) $p->public, 'parent' => $planCodes[$p->parent_rate_plan_id] ?? null,
                ])->values()->all(),
                'outlets' => ['enabled' => (bool) $items, 'items' => $items],
                'options' => ['update_existing' => false, 'remove_missing' => false],
            ];
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    // ------------------------------------------------------------------ steps

    /** @return array{0: Hotel, 1: bool} */
    private function resolveHotel(array $spec, User $actor): array
    {
        $group = $this->ctx->groupId() ?? $actor->group_id;
        if (! empty($spec['id'])) {
            $hotel = Hotel::find((int) $spec['id']);
            if (! $hotel) {
                throw new DomainException('Hotel not found or not allowed.', 'hotel-not-found', 404);
            }
            $this->ctx->set($hotel->group_id, $hotel->id, $this->ctx->allowedHotelIds(), $this->ctx->actor());

            return [$hotel, false];
        }
        $new = $spec['create'] ?? null;
        if (! $new || trim((string) ($new['name'] ?? '')) === '') {
            throw new DomainException('Choose a hotel or give the new hotel a name.', 'fastconfig-no-hotel');
        }
        if (! $actor->can('hotels.manage')) {
            throw new DomainException('Creating a hotel needs the hotels.manage permission.', 'forbidden', 403);
        }
        $hotel = $this->ctx->withoutScope(function () use ($new, $group) {
            $code = $this->freshCode((string) ($new['code'] ?? ''), (string) $new['name'], $group);
            $h = new Hotel([
                'code' => $code, 'name' => mb_substr(trim($new['name']), 0, 150), 'city' => $new['city'] ?? null, 'country' => $new['country'] ?? 'CM',
                'stars' => isset($new['stars']) ? max(1, min(5, (int) $new['stars'])) : null, 'legal_name' => mb_substr(trim($new['name']), 0, 150).' SARL',
            ]);
            $h->group_id = $group;
            $h->save();

            return $h;
        });
        if (! $actor->all_hotels) {
            $actor->hotels()->syncWithoutDetaching([$hotel->id]);
        }
        $allowed = $this->ctx->allowedHotelIds();
        $this->ctx->set($group, $hotel->id, $allowed === null ? null : array_values(array_unique(array_merge($allowed, [$hotel->id]))), $this->ctx->actor());
        app(Provisioner::class)->provision($hotel);

        return [$hotel, true];
    }

    private function freshCode(string $wanted, string $name, int $group): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $wanted)) ?: '';
        if ($base === '') {
            $initials = collect(preg_split('/\s+/', trim(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name)))->map(fn ($w) => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $w), 0, 1)))->implode('');
            $base = substr($initials ?: 'HTL', 0, 4);
        }
        $base = substr($base, 0, 8);
        $code = $base;
        for ($i = 2; Hotel::where('group_id', $group)->where('code', $code)->exists(); $i++) {
            $code = $base.$i;
        }

        return $code;
    }

    private function types(array $gen, array $c): array
    {
        $r = ['created' => [], 'updated' => [], 'skipped' => [], 'blocked' => [], 'removed' => []];
        $existing = RoomType::get()->keyBy('code');
        foreach ($gen['types'] as $t) {
            $attrs = [
                'name' => $t['name'], 'max_adults' => $t['max_adults'], 'max_children' => $t['max_children'], 'bed_type' => $t['bed_type'], 'size_sqm' => $t['size'],
                'amenities' => $t['amenities'], 'base_rate' => $t['base_price'], 'sort' => $t['sort'], 'bed_config' => $t['beds'], 'beds_total' => $t['beds_total'],
                'view' => $t['view'], 'smoking' => $t['smoking'], 'extra_adult_price' => $t['extra_adult'], 'extra_child_price' => $t['extra_child'],
                'weekend_uplift_percent' => $t['weekend_uplift'], 'high_season_uplift_percent' => $t['high_season_uplift'],
            ];
            if (! $t['code']) {
                $r['skipped'][] = ['code' => '', 'reason' => 'no code'];

                continue;
            }
            if ($row = $existing->get($t['code'])) {
                if ($c['options']['update_existing']) {
                    $row->fill($attrs);
                    if ($row->isDirty()) {
                        $row->save();
                        $r['updated'][] = $t['code'];
                    } else {
                        $r['skipped'][] = ['code' => $t['code'], 'reason' => 'unchanged'];
                    }
                } else {
                    $r['skipped'][] = ['code' => $t['code'], 'reason' => 'exists'];
                }
            } else {
                RoomType::create(['code' => $t['code']] + $attrs);
                $r['created'][] = $t['code'];
            }
        }
        if ($c['options']['remove_missing']) {
            $keep = array_column($gen['types'], 'code');
            foreach ($existing as $code => $row) {
                if (in_array($code, $keep, true)) {
                    continue;
                }
                if ($why = $this->typeInUse($row)) {
                    $r['blocked'][] = ['code' => $code, 'reason' => $why];
                } else {
                    $row->delete();
                    $r['removed'][] = $code;
                }
            }
        }

        return $r;
    }

    private function rooms(array $gen, array $c): array
    {
        $r = ['created' => [], 'updated' => [], 'skipped' => [], 'blocked' => [], 'removed' => []];
        $types = RoomType::pluck('id', 'code');
        $codes = $types->flip();
        $existing = Room::get()->keyBy('number');
        $update = $c['options']['update_existing'];
        $retype = [];
        foreach ($gen['rooms'] as $x) {
            $typeId = $types[$x['type']] ?? null;
            if (! $typeId) {
                $r['skipped'][] = ['code' => $x['number'], 'reason' => 'unknown type '.$x['type']];

                continue;
            }
            if ($room = $existing->get($x['number'])) {
                if (! $update) {
                    $r['skipped'][] = ['code' => $x['number'], 'reason' => $room->room_type_id === $typeId ? 'exists' : 'exists with type '.($codes[$room->room_type_id] ?? '?')];

                    continue;
                }
                if ($room->room_type_id !== $typeId) {
                    $retype[$room->id] = [$room, $typeId, $x];

                    continue;
                }
                $room->fill(['floor' => $x['floor'], 'view' => $x['view'], 'smoking' => $x['smoking']]);
                if ($room->isDirty()) {
                    $room->save();
                    $r['updated'][] = $x['number'];
                } else {
                    $r['skipped'][] = ['code' => $x['number'], 'reason' => 'unchanged'];
                }

                continue;
            }
            Room::create(['room_type_id' => $typeId, 'number' => $x['number'], 'floor' => $x['floor'], 'view' => $x['view'], 'smoking' => $x['smoking']]);
            $r['created'][] = $x['number'];
        }
        $history = $this->roomHistory(array_merge(array_keys($retype), $c['options']['remove_missing'] ? $existing->pluck('id')->all() : []));
        foreach ($retype as $id => [$room, $typeId, $x]) {
            if (isset($history[$id])) {
                $r['blocked'][] = ['code' => $room->number, 'reason' => 'has history, type not changed'];

                continue;
            }
            $room->fill(['room_type_id' => $typeId, 'floor' => $x['floor'], 'view' => $x['view'], 'smoking' => $x['smoking']])->save();
            $r['updated'][] = $room->number;
        }
        if ($c['options']['remove_missing']) {
            $keep = array_column($gen['rooms'], 'number');
            foreach ($existing as $number => $room) {
                if (in_array((string) $number, $keep, true)) {
                    continue;
                }
                if (isset($history[$room->id])) {
                    $r['blocked'][] = ['code' => (string) $number, 'reason' => 'has reservations or history, not deleted'];
                } else {
                    $room->delete();
                    $r['removed'][] = (string) $number;
                }
            }
        }

        return $r;
    }

    private function ratePlans(array $gen, array $c): array
    {
        $r = ['created' => [], 'updated' => [], 'skipped' => [], 'blocked' => [], 'removed' => []];
        $existing = RatePlan::get()->keyBy('code');
        $bar = $existing->get('BAR');
        $update = $c['options']['update_existing'];
        foreach ($gen['rate_plans'] as $p) {
            $isBar = $p['code'] === 'BAR';
            $attrs = ['name' => $p['name'], 'meal_plan' => $p['meal_plan'], 'refundable' => $p['refundable'], 'members_only' => $p['members_only'], 'public' => $p['public']]
                + ($isBar ? [] : ['derived_adjust_percent' => $p['percent']]);
            if ($row = $existing->get($p['code'])) {
                if ($update && $p['enabled']) {
                    $row->fill($attrs);
                    if ($row->isDirty()) {
                        $row->save();
                        $r['updated'][] = $p['code'];

                        continue;
                    }
                }
                $r['skipped'][] = ['code' => $p['code'], 'reason' => $p['enabled'] ? 'exists' : 'switched off'];

                continue;
            }
            if (! $p['enabled']) {
                $r['skipped'][] = ['code' => $p['code'], 'reason' => 'switched off'];

                continue;
            }
            $new = RatePlan::create(['code' => $p['code']] + $attrs + (! $isBar && $bar ? ['parent_rate_plan_id' => $bar->id] : []));
            if ($isBar) {
                $bar = $new;
            }
            $r['created'][] = $p['code'];
        }

        return $r;
    }

    private function outlets(array $c): array
    {
        $o = ['created' => [], 'updated' => [], 'skipped' => [], 'blocked' => [], 'removed' => []];
        $s = $o;
        if (! $c['outlets']['enabled']) {
            return [$o, $s];
        }
        $items = $c['outlets']['items'];
        foreach (self::OUTLETS as $item => [$code, $name, $kind]) {
            if (! in_array($item, $items, true)) {
                continue;
            }
            if (Outlet::where('code', $code)->exists()) {
                $o['skipped'][] = ['code' => $code, 'reason' => 'exists'];
            } else {
                Outlet::create(['code' => $code, 'name' => $name, 'kind' => $kind]);
                $o['created'][] = $code;
            }
        }
        foreach (self::STORES as $item => [$code, $name, $kind, $dept, $outletItem]) {
            if (! in_array($item, $items, true)) {
                continue;
            }
            if (Store::where('code', $code)->exists()) {
                $s['skipped'][] = ['code' => $code, 'reason' => 'exists'];

                continue;
            }
            $outletId = $outletItem ? Outlet::where('code', self::OUTLETS[$outletItem][0])->value('id') : null;
            Store::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'department' => $dept, 'outlet_id' => $outletId]);
            $s['created'][] = $code;
        }

        return [$o, $s];
    }

    // ------------------------------------------------------------------ safety

    /** @return array<int, true> room ids that appear in any table with a room_id column (reservations, tasks, folios...) */
    private function roomHistory(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return [];
        }
        $found = [];
        foreach (DB::select("select distinct table_name from information_schema.columns where table_schema = current_schema() and column_name = 'room_id' and table_name <> 'rooms'") as $row) {
            foreach (DB::table($row->table_name)->whereIn('room_id', $ids)->distinct()->pluck('room_id') as $id) {
                $found[(int) $id] = true;
            }
        }

        return $found;
    }

    private function typeInUse(RoomType $type): ?string
    {
        if (Room::where('room_type_id', $type->id)->exists()) {
            return 'has rooms, not deleted';
        }
        foreach (DB::select("select distinct table_name from information_schema.columns where table_schema = current_schema() and column_name = 'room_type_id' and table_name <> 'room_types'") as $row) {
            if (DB::table($row->table_name)->where('room_type_id', $type->id)->exists()) {
                return 'has reservations or history, not deleted';
            }
        }

        return null;
    }
}
