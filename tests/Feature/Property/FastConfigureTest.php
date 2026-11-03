<?php

namespace Tests\Feature\Property;

use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Property\FastConfigure\Application\Generator;
use App\Modules\Property\FastConfigure\Application\Presets;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Domain\Store;
use App\Modules\Tenancy\Domain\Hotel;
use Tests\TestCase;

class FastConfigureTest extends TestCase
{
    private const URL = '/api/v1/property/fast-configure';

    private function cfg(array $over = []): array
    {
        $base = [
            'hotel' => ['id' => $this->hotel->id],
            'floors' => ['mode' => 'numeric', 'count' => 3, 'default_rooms' => 10, 'per_floor' => []],
            'numbering' => ['pattern' => 'floor100'],
            'types' => [
                ['code' => 'STD', 'name' => 'Standard', 'share_mode' => 'percent', 'share' => 60, 'max_adults' => 2, 'max_children' => 1, 'beds' => [['type' => 'double', 'count' => 1]], 'base_price' => 40_000, 'weekend_uplift' => 10, 'high_season_uplift' => 25, 'assign' => 'spread', 'amenities' => ['wifi'], 'amenities_extra' => 'hairdryer'],
                ['code' => 'FAM', 'name' => 'Family', 'share_mode' => 'percent', 'share' => 40, 'max_adults' => 4, 'max_children' => 2, 'beds' => [['type' => 'double', 'count' => 1], ['type' => 'single', 'count' => 2]], 'base_price' => 70_000, 'assign' => 'ground'],
            ],
            'rate_plans' => Presets::plans(),
            'outlets' => ['enabled' => false, 'items' => []],
        ];

        return array_replace_recursive($base, $over);
    }

    private function apply(array $cfg, bool $dry = false, ?int $hotelId = null)
    {
        return $this->postJson(self::URL.'/apply', ['config' => $cfg, 'dry_run' => $dry]);
    }

    public function test_numbering_patterns_floor_overrides_and_skipping_13(): void
    {
        $g = Generator::generate($this->cfg(['floors' => ['count' => 2, 'default_rooms' => 3, 'per_floor' => ['2' => 2]]]));
        $this->assertSame(['101', '102', '103', '201', '202'], array_column($g['rooms'], 'number'));

        $g = Generator::generate($this->cfg(['floors' => ['mode' => 'ground', 'count' => 1, 'default_rooms' => 2], 'numbering' => ['pattern' => 'floor100', 'pad' => 3]]));
        $this->assertSame(['001', '002', '101', '102'], array_column($g['rooms'], 'number'));
        $this->assertSame(['G', '1'], array_column($g['floors'], 'label'));

        $g = Generator::generate($this->cfg(['floors' => ['count' => 1, 'default_rooms' => 3], 'numbering' => ['pattern' => 'prefix', 'prefix' => 'A']]));
        $this->assertSame(['A101', 'A102', 'A103'], array_column($g['rooms'], 'number'));

        $g = Generator::generate($this->cfg(['floors' => ['count' => 2, 'default_rooms' => 14], 'numbering' => ['pattern' => 'floor100', 'skip_13' => true]]));
        $this->assertNotContains('113', array_column($g['rooms'], 'number'));
        $this->assertNotContains('213', array_column($g['rooms'], 'number'));
        $this->assertCount(28, $g['rooms']); // skipped numbers do not reduce the room count

        $g = Generator::generate($this->cfg(['floors' => ['mode' => 'custom', 'custom' => ['RC', 'M1'], 'default_rooms' => 2], 'numbering' => ['pattern' => 'letter']]));
        $this->assertSame(['A01', 'A02', 'B01', 'B02'], array_column($g['rooms'], 'number'));

        $g = Generator::generate($this->cfg(['floors' => ['count' => 1, 'default_rooms' => 3], 'numbering' => ['pattern' => 'sequential', 'start' => 10, 'skip' => ['11']]]));
        $this->assertSame(['10', '12', '13'], array_column($g['rooms'], 'number'));
    }

    public function test_shares_round_to_exactly_the_room_total(): void
    {
        foreach ([7, 23, 41, 55, 99, 120] as $n) {
            $types = [];
            foreach ([33.3, 33.3, 33.4] as $i => $p) {
                $types[] = ['code' => 'T'.$i, 'name' => 'T', 'share_mode' => 'percent', 'share' => $p, 'beds' => [['type' => 'king', 'count' => 1]], 'base_price' => 1000];
            }
            $g = Generator::generate($this->cfg(['floors' => ['count' => 1, 'default_rooms' => $n], 'types' => $types]));
            $this->assertSame($n, array_sum(array_column($g['types'], 'rooms')), "n=$n");
            $this->assertCount($n, $g['rooms']);
        }
        // mixed: exact counts first, percentages share the rest
        $g = Generator::generate($this->cfg(['floors' => ['count' => 1, 'default_rooms' => 20], 'types' => [
            ['code' => 'A', 'share_mode' => 'count', 'share' => 5, 'beds' => [['type' => 'king', 'count' => 1]]],
            ['code' => 'B', 'share_mode' => 'percent', 'share' => 50, 'beds' => [['type' => 'king', 'count' => 1]]],
            ['code' => 'C', 'share_mode' => 'percent', 'share' => 50, 'beds' => [['type' => 'king', 'count' => 1]]],
        ]]));
        $this->assertSame([5, 8, 7], array_column($g['types'], 'rooms'));
    }

    public function test_warnings_for_shares_duplicates_zero_rooms_and_price_anomalies(): void
    {
        $g = Generator::generate($this->cfg(['types' => [
            ['code' => 'A', 'share' => 50, 'max_adults' => 2, 'beds' => [['type' => 'king', 'count' => 1]], 'base_price' => 90_000],
            ['code' => 'B', 'share' => 30, 'max_adults' => 4, 'beds' => [['type' => 'king', 'count' => 1]], 'base_price' => 50_000],
            ['code' => 'Z', 'share_mode' => 'count', 'share' => 0, 'beds' => [], 'base_price' => 0],
        ], 'floors' => ['count' => 1, 'default_rooms' => 4], 'numbering' => ['pattern' => 'floor100', 'skip' => []]]));
        $codes = array_column($g['warnings'], 'code');
        foreach (['shares_not_100', 'type_no_rooms', 'price_zero', 'price_inversion', 'no_beds'] as $w) {
            $this->assertContains($w, $codes, $w);
        }
    }

    public function test_beds_capacity_prices_and_uplifts(): void
    {
        $g = Generator::generate($this->cfg(['floors' => ['count' => 1, 'default_rooms' => 10]]));
        $t = collect($g['types'])->keyBy('code');
        $this->assertSame([6, 4], [$t['STD']['rooms'], $t['FAM']['rooms']]);
        $this->assertSame([1, 3], [$t['STD']['beds_total'], $t['FAM']['beds_total']]);
        $this->assertSame(6 * 1 + 4 * 3, $g['totals']['beds']);
        $this->assertSame(6 * 3 + 4 * 6, $g['totals']['capacity']);
        $this->assertSame(44_000, $t['STD']['weekend_price']);
        $this->assertSame(50_000, $t['STD']['high_season_price']);
        $this->assertSame(6 * 40_000 + 4 * 70_000, $g['totals']['revenue_100']);
        $this->assertSame((int) round(520_000 * 0.6), $g['totals']['revenue_60']);
        $this->assertSame(52_000, $g['totals']['avg_rate']);
        $bb = collect($g['rate_plans'])->firstWhere('code', 'BB');
        $this->assertSame(44_800, $bb['prices']['STD']);
        $this->assertSame(36_000, collect($g['rate_plans'])->firstWhere('code', 'NRF')['prices']['STD']);
        // ground-floor rule: all FAM rooms come first
        $this->assertSame(['FAM', 'FAM', 'FAM', 'FAM'], array_slice(array_column($g['rooms'], 'type'), 0, 4));
    }

    public function test_top_floors_first_and_spread_across_floors(): void
    {
        $g = Generator::generate($this->cfg(['floors' => ['count' => 4, 'default_rooms' => 5], 'types' => [
            ['code' => 'STD', 'share' => 80, 'beds' => [['type' => 'king', 'count' => 1]], 'base_price' => 1, 'assign' => 'spread'],
            ['code' => 'STE', 'share' => 20, 'beds' => [['type' => 'king', 'count' => 1]], 'base_price' => 2, 'assign' => 'top'],
        ]]));
        $ste = collect($g['rooms'])->where('type', 'STE')->pluck('floor')->unique()->all();
        $this->assertSame(['4'], $ste);
        $std = collect($g['rooms'])->where('type', 'STD')->pluck('floor')->unique()->sort()->values()->all();
        $this->assertSame(['1', '2', '3', '4'], $std);
    }

    public function test_apply_creates_everything_and_is_idempotent(): void
    {
        $cfg = $this->cfg(['outlets' => ['enabled' => true, 'items' => ['restaurant', 'bar', 'kitchen', 'main_store']]]);
        $r1 = $this->apply($cfg)->assertCreated()->json();
        $this->assertCount(30, $r1['rooms']['created']);
        $this->assertSame(['STD', 'FAM'], $r1['room_types']['created']);
        $this->assertSame(['BAR', 'BB', 'NRF', 'MEM', 'CORP'], $r1['rate_plans']['created']);
        $this->assertSame(['REST', 'BAR'], $r1['outlets']['created']);
        $this->assertSame(['MAIN', 'KIT', 'BARS'], $r1['stores']['created']);
        $this->assertSame(30, Room::count());
        $std = RoomType::where('code', 'STD')->first();
        $this->assertSame([1, 40_000, ['wifi', 'hairdryer'], 3], [$std->beds_total, $std->base_rate, $std->amenities, $std->max_adults + $std->max_children]);
        $bb = RatePlan::where('code', 'BB')->first();
        $this->assertSame(RatePlan::where('code', 'BAR')->value('id'), $bb->parent_rate_plan_id);
        $this->assertSame(12.0, $bb->derived_adjust_percent);

        $r2 = $this->apply($cfg)->assertCreated()->json();
        foreach (['rooms', 'room_types', 'rate_plans', 'outlets', 'stores'] as $k) {
            $this->assertSame([], $r2[$k]['created'], $k);
            $this->assertSame([], $r2[$k]['updated'], $k);
        }
        $this->assertSame([30, 2, 5, 2, 3], [Room::count(), RoomType::count(), RatePlan::count(), Outlet::count(), Store::count()]);
    }

    public function test_update_existing_changes_prices_and_only_when_ticked(): void
    {
        $this->apply($this->cfg())->assertCreated();
        $cfg = $this->cfg();
        $cfg['types'][0]['base_price'] = 55_000;
        $cfg['rate_plans'][1]['percent'] = 20;

        $r = $this->apply($cfg)->json();
        $this->assertSame(40_000, (int) RoomType::where('code', 'STD')->value('base_rate'));
        $this->assertSame([], $r['room_types']['updated']);

        $cfg['options']['update_existing'] = true;
        $r = $this->apply($cfg)->json();
        $this->assertSame(['STD'], $r['room_types']['updated']);
        $this->assertSame(['BB'], $r['rate_plans']['updated']);
        $this->assertSame(55_000, (int) RoomType::where('code', 'STD')->value('base_rate'));
        $this->assertSame(20.0, RatePlan::where('code', 'BB')->first()->derived_adjust_percent);
        $this->assertSame(30, Room::count());
        $this->assertSame([], $r['rooms']['created']);
    }

    public function test_never_deletes_rooms_or_types_with_history(): void
    {
        $this->apply($this->cfg())->assertCreated();
        $room = Room::where('number', '201')->first();
        $guest = \App\Modules\Guests\Domain\Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'j@test.cm']);
        Reservation::create([
            'number' => 'R1', 'booking_ref' => 'B1', 'guest_id' => $guest->id, 'room_type_id' => $room->room_type_id, 'rate_plan_id' => RatePlan::where('code', 'BAR')->value('id'),
            'room_id' => $room->id, 'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(),
        ]);

        // a smaller base: 2 floors x 5 rooms, remove_missing on, only STD kept
        $small = $this->cfg(['floors' => ['count' => 1, 'default_rooms' => 5], 'options' => ['remove_missing' => true]]);
        $small['types'] = [$small['types'][0]];
        $small['types'][0]['share'] = 100;
        $r = $this->apply($small)->assertCreated()->json();

        $blockedRooms = collect($r['blocked'])->where('kind', 'room')->pluck('code')->all();
        $this->assertContains('201', $blockedRooms);
        $this->assertNotNull(Room::where('number', '201')->first());
        $this->assertContains('FAM', collect($r['blocked'])->where('kind', 'room_type')->pluck('code')->all()); // FAM still has rooms
        $this->assertNotNull(RoomType::where('code', 'FAM')->first());
        $this->assertContains('202', $r['rooms']['removed']);
        $this->assertNull(Room::where('number', '202')->first());
    }

    public function test_dry_run_writes_nothing_and_reports_everything(): void
    {
        $cfg = $this->cfg(['outlets' => ['enabled' => true, 'items' => ['restaurant']]]);
        $r = $this->apply($cfg, dry: true)->assertOk()->json();
        $this->assertTrue($r['dry_run']);
        $this->assertCount(30, $r['rooms']['created']);
        $this->assertSame(['REST'], $r['outlets']['created']);
        $this->assertSame([0, 0, 0, 0], [Room::count(), RoomType::count(), RatePlan::count(), Outlet::count()]);

        // dry run that would create a hotel leaves no hotel behind
        $before = Hotel::count();
        $new = $this->cfg(['hotel' => ['id' => null, 'create' => ['name' => 'Nouvel Hotel', 'city' => 'Kribi', 'stars' => 3]]]);
        $r = $this->apply($new, dry: true)->assertOk()->json();
        $this->assertTrue($r['hotel']['created']);
        $this->assertSame($before, Hotel::count());
    }

    public function test_create_new_hotel_and_tenant_isolation(): void
    {
        $new = $this->cfg(['hotel' => ['id' => null, 'create' => ['name' => 'Hotel Neuf', 'city' => 'Douala', 'stars' => 4]]]);
        $r = $this->apply($new)->assertCreated()->json();
        $this->assertTrue($r['hotel']['created']);
        $id = $r['hotel']['id'];
        $this->assertSame(30, Room::withoutGlobalScopes()->where('hotel_id', $id)->count());
        $this->assertSame(0, Room::withoutGlobalScopes()->where('hotel_id', $this->hotel->id)->count());
        $this->assertSame(Hotel::withoutGlobalScopes()->find($id)->group_id, $this->group->id);

        // another group's hotel cannot be targeted
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $this->apply($this->cfg(['hotel' => ['id' => $h2->id]]))->assertStatus(404);
        $this->assertSame(0, Room::withoutGlobalScopes()->where('hotel_id', $h2->id)->count());
    }

    public function test_presets_apply_cleanly_with_expected_totals(): void
    {
        $expect = ['auberge-20' => 20, 'eco-3-40' => 40, 'business-4-120' => 120, 'resort-5-80' => 80];
        $this->assertSame($expect, collect(Presets::catalog())->map(fn ($p) => $p['rooms'])->all());
        foreach ($expect as $key => $rooms) {
            $g = Generator::generate(Presets::get($key));
            $this->assertSame([], array_filter($g['warnings'], fn ($w) => ! in_array($w['code'], ['price_inversion'], true)), "$key warnings");
            $this->assertSame($rooms, $g['totals']['rooms'], $key);
            $this->assertGreaterThan(0, $g['totals']['revenue_100']);
        }
        $cfg = Presets::get('eco-3-40');
        $cfg['hotel'] = ['id' => $this->hotel->id];
        $r = $this->apply($cfg)->assertCreated()->json();
        $this->assertCount(40, $r['rooms']['created']);
        $this->assertSame(40, Room::count());
        $this->assertSame(['REST', 'BAR'], $r['outlets']['created']);
        $this->assertSame(0, Room::where('floor', '4')->where('room_type_id', RoomType::where('code', 'FAM')->value('id'))->count()); // FAM on the ground-most floor
    }

    public function test_demo_endpoint_creates_a_new_hotel_from_a_preset(): void
    {
        $r = $this->postJson(self::URL.'/demo', ['preset' => 'auberge-20'])->assertCreated()->json();
        $this->assertTrue($r['hotel']['created']);
        $this->assertCount(20, $r['rooms']['created']);
        $this->postJson(self::URL.'/demo', ['preset' => 'nope'])->assertStatus(422);
    }

    public function test_export_then_import_round_trip(): void
    {
        $cfg = $this->cfg(['floors' => ['count' => 2, 'default_rooms' => 6], 'outlets' => ['enabled' => true, 'items' => ['restaurant', 'main_store']]]);
        $this->apply($cfg)->assertCreated();
        $export = $this->getJson(self::URL.'/export?hotel_id='.$this->hotel->id)->assertOk()->json();
        $this->assertCount(12, $export['explicit_rooms']);

        $export['hotel'] = ['create' => ['name' => 'Copie', 'city' => 'Kribi']];
        $r = $this->apply($export)->assertCreated()->json();
        $id = $r['hotel']['id'];
        $a = Room::withoutGlobalScopes()->where('hotel_id', $this->hotel->id)->with(['roomType' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('number')->get()->map(fn ($x) => [$x->number, $x->floor, $x->roomType->code, $x->roomType->base_rate])->all();
        $b = Room::withoutGlobalScopes()->where('hotel_id', $id)->with(['roomType' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('number')->get()->map(fn ($x) => [$x->number, $x->floor, $x->roomType->code, $x->roomType->base_rate])->all();
        $this->assertSame($a, $b);
        $this->assertSame(5, RatePlan::withoutGlobalScopes()->where('hotel_id', $id)->count());
        $this->assertTrue(Outlet::withoutGlobalScopes()->where('hotel_id', $id)->where('code', 'REST')->exists());
        $this->assertTrue(Store::withoutGlobalScopes()->where('hotel_id', $id)->where('code', 'MAIN')->exists());
        $this->assertSame(3, (int) RoomType::withoutGlobalScopes()->where('hotel_id', $id)->where('code', 'FAM')->value('beds_total'));
    }

    public function test_preview_returns_grid_totals_and_diff(): void
    {
        $r = $this->postJson(self::URL.'/preview', ['config' => $this->cfg()])->assertOk()->json();
        $this->assertSame(30, $r['totals']['rooms']);
        $this->assertCount(3, $r['floors']);
        $this->assertCount(30, $r['diff']['rooms']['created']);
        $this->assertSame(0, Room::count());
    }

    public function test_permission_guards(): void
    {
        $fo = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($fo, $this->hotel);
        $this->getJson(self::URL.'/presets')->assertForbidden();
        $this->postJson(self::URL.'/apply', ['config' => $this->cfg()])->assertForbidden();
        $this->postJson(self::URL.'/preview', ['config' => $this->cfg()])->assertForbidden();
        $this->get('/properties/fast-configure')->assertForbidden();
        $this->assertSame(0, Room::count());

        $this->actAs($this->user, $this->hotel);
        $this->getJson(self::URL.'/presets')->assertOk()->assertJsonPath('presets.auberge-20.rooms', 20);
        $this->assertTrue($this->user->can('property.fast_configure'));
    }
}
