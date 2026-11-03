<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Application\Provisioner;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Department;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Fictional demo group with two hotels, for local development and UAT.
 * Test logins: see DemoUsersSeeder (local only — change before any shared environment).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $ctx = app(TenantContext::class);
        $group = Group::firstOrCreate(['slug' => 'opes-demo'], ['name' => 'Opes Demo Hotels', 'legal_name' => 'Opes Demo Hotels SA', 'country' => 'CM']);

        $hotels = [];
        foreach ([['DLA', 'Demo Hôtel Douala Bonanjo', 'Douala', 4, 40], ['YDE', 'Demo Hôtel Yaoundé Centre', 'Yaoundé', 3, 24]] as [$code, $name, $city, $stars, $rooms]) {
            $hotel = $ctx->withoutScope(fn () => Hotel::where('group_id', $group->id)->where('code', $code)->first());
            if (! $hotel) {
                $hotel = new Hotel(['code' => $code, 'name' => $name, 'city' => $city, 'country' => 'CM', 'stars' => $stars, 'legal_name' => "$name SARL", 'tax_id' => 'M0'.random_int(10000000, 99999999).'X']);
                $hotel->group_id = $group->id;
                $hotel->save();
            }
            $ctx->set($group->id, $hotel->id, [$hotel->id]);
            app(Provisioner::class)->provision($hotel);
            $this->hotel($rooms);
            $hotels[$code] = $hotel;
        }

        $ctx->set($group->id, null, null);
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(DemoUsersSeeder::class);

        foreach ([['Jean', 'Mballa', 'jean.mballa@example.cm', 'CM', true], ['Amina', 'Bouba', 'amina.b@example.cm', 'CM', false], ['Pierre', 'Durand', 'p.durand@example.fr', 'FR', false]] as [$f, $l, $e, $n, $vip]) {
            Guest::firstOrCreate(['email' => $e], ['first_name' => $f, 'last_name' => $l, 'nationality' => $n, 'vip' => $vip, 'id_type' => 'passport', 'id_number' => strtoupper(substr($l, 0, 2)).random_int(100000, 999999)]);
        }
        Company::firstOrCreate(['name' => 'Demo Corporate SA'], ['city_ledger' => true, 'credit_limit' => 5_000_000, 'payment_terms_days' => 30]);
        Company::firstOrCreate(['name' => 'Demo Voyages'], ['kind' => 'travel_agent', 'commission_percent' => 10]);
        Supplier::firstOrCreate(['code' => 'BRASS'], ['name' => 'Demo Brasseries SA', 'payment_terms_days' => 30]);
        Supplier::firstOrCreate(['code' => 'FRESH'], ['name' => 'Demo Produits Frais', 'payment_terms_days' => 15]);

        $loyalty = app(LoyaltyService::class);
        $loyalty->setupDefault('Opes Demo Rewards');
        $loyalty->enrol(Guest::where('email', 'jean.mballa@example.cm')->first(), 'physical');

        foreach ($hotels as $hotel) {
            $ctx->set($group->id, $hotel->id, [$hotel->id]);
            foreach ([['FO', 'Réception', 'rooms'], ['HK', 'Hébergement / Étages', 'rooms'], ['FB', 'Restauration', 'fnb'], ['ADM', 'Administration', 'admin']] as [$c, $n, $cc]) {
                Department::firstOrCreate(['code' => $c], ['name' => $n, 'cost_center' => $cc]);
            }
            app(HrService::class)->seedLeaveTypes();
        }

        $this->command?->info('Demo group "opes-demo" ready with hotels: '.implode(', ', array_keys($hotels)));
    }

    private function hotel(int $roomCount): void
    {
        $types = [
            ['STD', 'Chambre Standard', 2, 1, 45_000, 0.55],
            ['DLX', 'Chambre Deluxe', 2, 1, 65_000, 0.3],
            ['STE', 'Suite Junior', 3, 2, 120_000, 0.15],
        ];
        $number = 101;
        foreach ($types as $i => [$code, $name, $adults, $children, $rate, $share]) {
            $t = RoomType::firstOrCreate(['code' => $code], ['name' => $name, 'max_adults' => $adults, 'max_children' => $children, 'base_rate' => $rate, 'sort' => $i, 'amenities' => ['wifi', 'clim', 'tv']]);
            for ($n = 0; $n < (int) round($roomCount * $share); $n++) {
                Room::firstOrCreate(['number' => (string) $number], ['room_type_id' => $t->id, 'floor' => (string) intdiv($number, 100)]);
                $number = $number % 100 >= 20 ? (intdiv($number, 100) + 1) * 100 + 1 : $number + 1;
            }
        }
        $bar = RatePlan::firstOrCreate(['code' => 'BAR'], ['name' => 'Meilleur tarif disponible', 'meal_plan' => 'RO']);
        RatePlan::firstOrCreate(['code' => 'BB'], ['name' => 'Chambre + petit-déjeuner', 'meal_plan' => 'BB', 'parent_rate_plan_id' => $bar->id, 'derived_adjust_percent' => 12]);
        RatePlan::firstOrCreate(['code' => 'NRF'], ['name' => 'Non remboursable', 'refundable' => false, 'parent_rate_plan_id' => $bar->id, 'derived_adjust_percent' => -10]);
        RatePlan::firstOrCreate(['code' => 'MEM'], ['name' => 'Tarif membres', 'members_only' => true, 'parent_rate_plan_id' => $bar->id, 'derived_adjust_percent' => -15]);
        RatePlan::firstOrCreate(['code' => 'CORP'], ['name' => 'Tarif entreprise', 'public' => false, 'parent_rate_plan_id' => $bar->id, 'derived_adjust_percent' => -20]);

        $restaurant = Outlet::firstOrCreate(['code' => 'REST'], ['name' => 'Restaurant Le Wouri', 'kind' => 'restaurant']);
        $barOutlet = Outlet::firstOrCreate(['code' => 'BAR'], ['name' => 'Lobby Bar', 'kind' => 'bar']);
        Store::firstOrCreate(['code' => 'MAIN'], ['name' => 'Magasin central', 'kind' => 'main']);
        Store::firstOrCreate(['code' => 'KIT'], ['name' => 'Cuisine', 'kind' => 'kitchen', 'department' => 'fnb', 'outlet_id' => $restaurant->id]);
        Store::firstOrCreate(['code' => 'BARS'], ['name' => 'Stock bar', 'kind' => 'bar', 'department' => 'beverage', 'outlet_id' => $barOutlet->id]);
        Store::firstOrCreate(['code' => 'HSK'], ['name' => 'Lingerie / produits d\'accueil', 'kind' => 'housekeeping', 'department' => 'rooms']);
        Store::firstOrCreate(['code' => 'MNT'], ['name' => 'Pièces maintenance', 'kind' => 'maintenance', 'department' => 'maintenance']);

        $beer = StockItem::firstOrCreate(['code' => 'BEER65'], ['name' => 'Bière 65cl', 'category' => 'beverage', 'unit' => 'btl', 'purchase_unit' => 'casier', 'purchase_factor' => 12, 'inventory_account' => '311200', 'cost_account' => '603120', 'reorder_level' => 24]);
        $chicken = StockItem::firstOrCreate(['code' => 'POULET'], ['name' => 'Poulet entier', 'category' => 'food', 'unit' => 'kg', 'inventory_account' => '311100', 'cost_account' => '603110', 'reorder_level' => 10]);
        StockItem::firstOrCreate(['code' => 'SOAP'], ['name' => 'Savon d\'accueil', 'category' => 'amenities', 'unit' => 'pc', 'inventory_account' => '322100', 'cost_account' => '603200', 'reorder_level' => 100]);

        $item = MenuItem::firstOrCreate(['code' => 'B65'], ['name' => 'Bière pression 65cl', 'price' => 2_000, 'charge_code' => 'BAR', 'category' => 'Boissons', 'kitchen_station' => 'bar']);
        if (! $item->components()->exists()) {
            $item->components()->create(['stock_item_id' => $beer->id, 'quantity' => 1]);
        }
        $dish = MenuItem::firstOrCreate(['code' => 'NDOLE'], ['name' => 'Ndolè crevettes', 'price' => 8_500, 'charge_code' => 'FNB', 'category' => 'Plats', 'kitchen_station' => 'kitchen']);
        MenuItem::firstOrCreate(['code' => 'POISSON'], ['name' => 'Poisson braisé', 'price' => 9_500, 'charge_code' => 'FNB', 'category' => 'Plats', 'kitchen_station' => 'kitchen']);
        $grill = MenuItem::firstOrCreate(['code' => 'POULETDG'], ['name' => 'Poulet DG', 'price' => 7_500, 'charge_code' => 'FNB', 'category' => 'Plats', 'kitchen_station' => 'kitchen']);
        if (! $grill->components()->exists()) {
            $grill->components()->create(['stock_item_id' => $chicken->id, 'quantity' => 0.5]);
        }
    }
}
