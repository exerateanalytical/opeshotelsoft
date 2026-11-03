<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Application\Provisioner;
use App\Modules\FrontOffice\Application\CashierService;
use App\Modules\FrontOffice\Application\FolioService;
use App\Modules\FrontOffice\Application\FrontDeskService;
use App\Modules\FrontOffice\Application\NightAudit;
use App\Modules\FrontOffice\Domain\Folio;
use App\Modules\Guests\Domain\Company;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Application\HrService;
use App\Modules\HR\Application\PayrollService;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\Position;
use App\Modules\Loyalty\Application\LoyaltyService;
use App\Modules\POS\Application\PosService;
use App\Modules\POS\Domain\MenuItem;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\FastConfigure\Application\FastConfigure;
use App\Modules\Property\FastConfigure\Application\Presets;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Stock\Application\StockService;
use App\Modules\Stock\Domain\StockItem;
use App\Modules\Stock\Domain\Store;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Floods the demo group with volume: 12 hotels (~1 000 rooms), ~1 000 guests-stays simulated day by day
 * through the real services (so every book balances), ~100 employees with contracts and payroll,
 * POS sales, suppliers and opening stock. Local development only.
 *
 *   php artisan db:seed --class=DemoBulkSeeder
 * Tunables (env): DEMO_BOOKINGS (1000), DEMO_HOTELS (12), DEMO_PAST_DAYS (14), DEMO_FUTURE_DAYS (10), DEMO_GUESTS (1200).
 */
class DemoBulkSeeder extends Seeder
{
    // code, name, city, stars, rooms, rate factor
    private const HOTELS = [
        ['DLA', 'Demo Hôtel Douala Bonanjo', 'Douala', 4, 120, 1.00],
        ['YDE', 'Demo Hôtel Yaoundé Centre', 'Yaoundé', 3, 100, 0.85],
        ['KRI', 'Opes Resort Kribi', 'Kribi', 5, 110, 1.35],
        ['LIM', 'Opes Beach Limbé', 'Limbé', 4, 95, 1.10],
        ['BAF', 'Opes Bafoussam Palace', 'Bafoussam', 3, 80, 0.75],
        ['GAR', 'Opes Garoua Plaza', 'Garoua', 3, 85, 0.75],
        ['BUE', 'Opes Mont Buea Lodge', 'Buéa', 3, 70, 0.80],
        ['BAM', 'Opes Bamenda Grand', 'Bamenda', 3, 75, 0.78],
        ['EBO', 'Opes Ebolowa Inn', 'Ebolowa', 2, 60, 0.60],
        ['MAR', 'Opes Maroua Sahel', 'Maroua', 2, 55, 0.60],
        ['BER', 'Opes Bertoua Forêt', 'Bertoua', 2, 50, 0.58],
        ['NGA', 'Opes Ngaoundéré Express', 'Ngaoundéré', 3, 100, 0.72],
    ];

    // code, name, adults, children, base rate, share of rooms, bed type, beds
    private const TYPES = [
        ['STD', 'Chambre Standard', 2, 1, 45_000, 0.40, 'double', 1],
        ['TWN', 'Chambre Twin', 2, 0, 48_000, 0.20, 'twin', 2],
        ['DLX', 'Chambre Deluxe', 2, 1, 65_000, 0.20, 'king', 1],
        ['FAM', 'Chambre Familiale', 4, 2, 85_000, 0.10, 'family', 3],
        ['STE', 'Suite Junior', 3, 2, 120_000, 0.10, 'king+sofa', 2],
    ];

    private const FIRST = ['Jean', 'Paul', 'Pierre', 'Marie', 'Aminata', 'Fatima', 'Christelle', 'Brice', 'Serge', 'Arnaud', 'Estelle', 'Nadège', 'Hervé', 'Patrick', 'Sylvie', 'Rodrigue', 'Carine', 'Landry', 'Mireille', 'Yannick', 'Blaise', 'Flore', 'Cédric', 'Aïcha', 'Ibrahim', 'Moussa', 'Ngwa', 'Tabe', 'Ayuk', 'Fonyuy', 'Emmanuel', 'Grace', 'Joseph', 'Blandine', 'Alain', 'Ursule', 'Thierry', 'Pascaline', 'Gaston', 'Solange'];

    private const LAST = ['Mballa', 'Nkomo', 'Fotso', 'Mbarga', 'Ekani', 'Ngono', 'Kamga', 'Talla', 'Nana', 'Tchamba', 'Atangana', 'Essomba', 'Onana', 'Abena', 'Fouda', 'Mvondo', 'Ndzana', 'Bella', 'Njoya', 'Kouam', 'Simo', 'Djoumessi', 'Tagne', 'Moukoko', 'Ewane', 'Eyoum', 'Manga', 'Eboa', 'Sone', 'Tabi', 'Achu', 'Mbah', 'Fonkam', 'Ndi', 'Bouba', 'Hamadou', 'Oumarou', 'Issa', 'Yaya', 'Abdoulaye'];

    private const FOREIGN = [['Dupont', 'FR'], ['Martin', 'FR'], ['Bernard', 'FR'], ['Okafor', 'NG'], ['Adeyemi', 'NG'], ['Haddad', 'LB'], ['Smith', 'GB'], ['Garcia', 'ES'], ['Rossi', 'IT'], ['Wang', 'CN'], ['Diallo', 'SN'], ['Nguema', 'GA'], ['Mabiala', 'CG'], ['Mahamat', 'TD']];

    private TenantContext $ctx;

    private ?User $actor = null;

    private ?User $approver = null;

    public function run(): void
    {
        mt_srand(2026);
        DB::disableQueryLog();
        $this->ctx = app(TenantContext::class);

        if (! Group::where('slug', 'opes-demo')->exists()) {
            $this->call(DemoSeeder::class);
        }
        $group = Group::where('slug', 'opes-demo')->firstOrFail();
        $users = User::orderBy('id')->take(2)->get();
        $this->actor = $users[0];
        $this->approver = $users[1] ?? $users[0];

        $hotelCount = (int) env('DEMO_HOTELS', 12);
        $bookingsTotal = (int) env('DEMO_BOOKINGS', 1000);
        $past = (int) env('DEMO_PAST_DAYS', 14);
        $future = (int) env('DEMO_FUTURE_DAYS', 10);
        $totalRooms = array_sum(array_column(array_slice(self::HOTELS, 0, $hotelCount), 4));

        $this->ctx->set($group->id, null, null, $this->actor);
        $guests = $this->guests($group, (int) env('DEMO_GUESTS', 1200));
        $companies = $this->companies();
        $this->suppliers();
        $this->say('Guests, companies, suppliers ready.');

        foreach (array_slice(self::HOTELS, 0, $hotelCount) as [$code, $name, $city, $stars, $rooms, $factor]) {
            $hotel = $this->ctx->withoutScope(fn () => Hotel::where('group_id', $group->id)->where('code', $code)->first());
            if (! $hotel) {
                $hotel = new Hotel(['code' => $code, 'name' => $name, 'city' => $city, 'country' => 'CM', 'stars' => $stars, 'legal_name' => "$name SARL", 'tax_id' => 'M0'.random_int(10000000, 99999999).'X']);
                $hotel->group_id = $group->id;
                $hotel->save();
            }
            $this->ctx->set($group->id, $hotel->id, [$hotel->id], $this->actor);
            app(Provisioner::class)->provision($hotel);

            $this->structure($rooms, $factor);
            $this->stockAndMenu();
            $staff = $this->staff(max(6, (int) round($rooms * 0.1)));
            $this->say("$code: $rooms rooms, $staff staff, stock and menu ready.");

            $bookings = (int) round($bookingsTotal * $rooms / $totalRooms);
            $done = $this->simulate($hotel, $bookings, $past, $future, $guests, $companies);
            $this->say("$code: {$done['reservations']} reservations ({$done['stays']} stays, {$done['checks']} POS checks, {$done['cancelled']} cancelled).");

            $this->payroll($hotel);
        }

        $this->ctx->set($group->id, null, null, $this->actor);
        $this->loyalty($guests);
        $this->summary($group);
    }

    // ------------------------------------------------------------------ structure

    private function structure(int $roomCount, float $factor): void
    {
        // Same code path as the Fast Configure screen: legacy counts (share, STE gets the rest) and numbering (20 rooms a floor, 101..).
        $k = 0;
        $types = [];
        foreach (self::TYPES as $i => [$code, $name, $adults, $children, $rate, $share, $bed, $beds]) {
            $count = $code === 'STE' ? $roomCount - $k : (int) round($roomCount * $share);
            $k += $count;
            $types[] = [
                'code' => $code, 'name' => $name, 'share_mode' => 'count', 'share' => max(0, $count), 'max_adults' => $adults, 'max_children' => $children,
                'beds' => [['type' => str_contains($bed, '+') ? explode('+', $bed)[0] : ($bed === 'family' ? 'double' : $bed), 'count' => max(1, $beds)]],
                'base_price' => (int) (round($rate * $factor / 1000) * 1000), 'amenities' => ['wifi', 'clim', 'tv'], 'assign' => 'block',
            ];
        }
        $floors = max(1, (int) ceil($roomCount / 20));
        $perFloor = [(string) $floors => $roomCount - 20 * ($floors - 1)];
        $plans = Presets::plans();
        $plans[0]['name'] = 'Meilleur tarif disponible';
        app(FastConfigure::class)->apply([
            'hotel' => ['id' => $this->ctx->requireHotelId()],
            'floors' => ['mode' => 'numeric', 'count' => $floors, 'default_rooms' => 20, 'per_floor' => $perFloor],
            'numbering' => ['pattern' => 'floor100'],
            'types' => $types, 'rate_plans' => $plans,
            'outlets' => ['enabled' => true, 'items' => ['restaurant', 'bar', 'kitchen', 'main_store', 'housekeeping_store', 'maintenance_store']],
        ], $this->actor);
        app(HrService::class)->seedLeaveTypes();
    }

    private function stockAndMenu(): void
    {
        // code, name, category, unit, store, inventory account, cost account, unit cost, opening qty
        $items = [
            ['BEER65', 'Bière 65cl', 'beverage', 'btl', 'BARS', '311200', '603120', 700, 480], ['SODA', 'Soda 33cl', 'beverage', 'btl', 'BARS', '311200', '603120', 280, 360],
            ['WATER', 'Eau minérale 50cl', 'beverage', 'btl', 'BARS', '311200', '603120', 180, 600], ['WINE', 'Vin rouge (bouteille)', 'beverage', 'btl', 'BARS', '311200', '603120', 4200, 60],
            ['WHISKY', 'Whisky (bouteille)', 'beverage', 'btl', 'BARS', '311200', '603120', 9500, 24], ['JUICE', 'Jus de fruit 1L', 'beverage', 'btl', 'BARS', '311200', '603120', 900, 80],
            ['POULET', 'Poulet entier', 'food', 'kg', 'KIT', '311100', '603110', 2200, 120], ['POISSON', 'Poisson capitaine', 'food', 'kg', 'KIT', '311100', '603110', 3500, 80],
            ['BOEUF', 'Viande de bœuf', 'food', 'kg', 'KIT', '311100', '603110', 3200, 70], ['RIZ', 'Riz', 'food', 'kg', 'KIT', '311100', '603110', 650, 200],
            ['PLANTAIN', 'Plantain', 'food', 'kg', 'KIT', '311100', '603110', 450, 150], ['TOMATE', 'Tomates', 'food', 'kg', 'KIT', '311100', '603110', 600, 60],
            ['OIGNON', 'Oignons', 'food', 'kg', 'KIT', '311100', '603110', 500, 50], ['HUILE', 'Huile de palme', 'food', 'l', 'KIT', '311100', '603110', 1300, 60],
            ['OEUF', 'Œufs (plateau)', 'food', 'pc', 'KIT', '311100', '603110', 1900, 40], ['CREVETTE', 'Crevettes', 'food', 'kg', 'KIT', '311100', '603110', 5200, 30],
            ['SOAP', "Savon d'accueil", 'amenities', 'pc', 'HSK', '322100', '603200', 120, 2000], ['SHAMPOO', 'Shampoing mini', 'amenities', 'pc', 'HSK', '322100', '603200', 150, 1800],
            ['TOWEL', 'Serviette de bain', 'amenities', 'pc', 'HSK', '322100', '603200', 3500, 300], ['DETERG', 'Détergent', 'amenities', 'l', 'HSK', '322100', '603200', 1100, 100],
            ['BLEACH', 'Eau de Javel', 'amenities', 'l', 'HSK', '322100', '603200', 700, 120], ['BULB', 'Ampoule LED', 'maintenance', 'pc', 'MNT', '322100', '603200', 1500, 150],
            ['PAINT', 'Peinture murale', 'maintenance', 'l', 'MNT', '322100', '603200', 4500, 40], ['PIPE', 'Raccord plomberie', 'maintenance', 'pc', 'MNT', '322100', '603200', 800, 90],
        ];
        $stock = app(StockService::class);
        $byCode = [];
        foreach ($items as [$code, $name, $cat, $unit, $store, $inv, $cost, $price, $qty]) {
            $item = StockItem::firstOrCreate(['code' => $code], ['name' => $name, 'category' => $cat, 'unit' => $unit, 'inventory_account' => $inv, 'cost_account' => $cost, 'reorder_level' => max(5, (int) ($qty * 0.2))]);
            $byCode[$code] = $item;
            $st = Store::where('code', $store)->first();
            if ($stock->onHand($st->id, $item->id) <= 0) {
                $stock->receive($item, $st, $qty, $price, ['reference' => 'OPENING']);
            }
        }

        // code, name, price, charge, category, station, [[stock code, qty]]
        $menu = [
            ['B65', 'Bière pression 65cl', 2000, 'BAR', 'Boissons', 'bar', [['BEER65', 1]]], ['SODA', 'Soda', 1000, 'BAR', 'Boissons', 'bar', [['SODA', 1]]],
            ['EAU', 'Eau minérale', 700, 'BAR', 'Boissons', 'bar', [['WATER', 1]]], ['VIN', 'Verre de vin rouge', 3500, 'BAR', 'Boissons', 'bar', [['WINE', 0.2]]],
            ['WHISKY', 'Whisky 4cl', 4000, 'BAR', 'Boissons', 'bar', [['WHISKY', 0.06]]], ['JUS', 'Jus de fruit', 1500, 'BAR', 'Boissons', 'bar', [['JUICE', 0.33]]],
            ['NDOLE', 'Ndolè crevettes', 8500, 'FNB', 'Plats', 'kitchen', [['CREVETTE', 0.15], ['HUILE', 0.05]]], ['POISSON', 'Poisson braisé', 9500, 'FNB', 'Plats', 'kitchen', [['POISSON', 0.4], ['PLANTAIN', 0.3]]],
            ['POULETDG', 'Poulet DG', 7500, 'FNB', 'Plats', 'kitchen', [['POULET', 0.5], ['PLANTAIN', 0.3]]], ['RIZ', 'Riz sauce arachide', 5000, 'FNB', 'Plats', 'kitchen', [['RIZ', 0.25], ['BOEUF', 0.15]]],
            ['BROCHETTE', 'Brochettes de bœuf', 4500, 'FNB', 'Plats', 'kitchen', [['BOEUF', 0.25]]], ['OMELETTE', 'Omelette', 3000, 'FNB', 'Petit-déjeuner', 'kitchen', [['OEUF', 0.1], ['OIGNON', 0.05]]],
            ['SALADE', 'Salade composée', 4000, 'FNB', 'Entrées', 'kitchen', [['TOMATE', 0.2], ['OIGNON', 0.05]]], ['SOUPE', 'Soupe de poisson', 5500, 'FNB', 'Entrées', 'kitchen', [['POISSON', 0.2]]],
        ];
        foreach ($menu as [$code, $name, $price, $charge, $cat, $station, $comps]) {
            $mi = MenuItem::firstOrCreate(['code' => $code], ['name' => $name, 'price' => $price, 'charge_code' => $charge, 'category' => $cat, 'kitchen_station' => $station]);
            if (! $mi->components()->exists()) {
                foreach ($comps as [$sc, $q]) {
                    $mi->components()->create(['stock_item_id' => $byCode[$sc]->id, 'quantity' => $q]);
                }
            }
        }
    }

    // ------------------------------------------------------------------ people

    private function staff(int $target): int
    {
        $existing = Employee::count();
        if ($existing >= $target) {
            return $existing;
        }
        $hr = app(HrService::class);
        $depts = [];
        foreach ([['FO', 'Réception', 'rooms'], ['HK', 'Hébergement / Étages', 'rooms'], ['FB', 'Restauration', 'fnb'], ['ADM', 'Administration', 'admin'], ['MNT', 'Maintenance', 'maintenance'], ['SEC', 'Sécurité', 'admin']] as [$c, $n, $cc]) {
            $depts[$c] = Department::firstOrCreate(['code' => $c], ['name' => $n, 'cost_center' => $cc]);
        }
        // dept, title, category, salary, weight
        $roles = [
            ['ADM', 'Directeur général', 'Cadre', 1_200_000, 0], ['ADM', 'Comptable', 'Agent de maîtrise', 380_000, 1], ['ADM', 'Responsable RH', 'Agent de maîtrise', 400_000, 1], ['ADM', 'Magasinier', 'Employé', 140_000, 1],
            ['FO', 'Chef de réception', 'Agent de maîtrise', 320_000, 1], ['FO', 'Réceptionniste', 'Employé', 150_000, 4], ['FO', 'Veilleur de nuit / night auditor', 'Employé', 160_000, 2],
            ['HK', 'Gouvernante', 'Agent de maîtrise', 280_000, 1], ['HK', 'Femme de chambre', 'Employé', 85_000, 8], ['HK', 'Valet de chambre', 'Employé', 85_000, 2],
            ['FB', 'Chef de cuisine', 'Agent de maîtrise', 450_000, 1], ['FB', 'Cuisinier', 'Employé', 140_000, 3], ['FB', 'Serveur', 'Employé', 95_000, 5], ['FB', 'Barman', 'Employé', 110_000, 2],
            ['MNT', 'Technicien maintenance', 'Employé', 150_000, 2], ['SEC', 'Agent de sécurité', 'Employé', 80_000, 3],
        ];
        $positions = [];
        foreach ($roles as [$d, $title, $cat]) {
            $positions[$title] = Position::firstOrCreate(['department_id' => $depts[$d]->id, 'title' => $title], ['category' => $cat]);
        }
        $pool = [];
        foreach ($roles as $i => [, , , , $w]) {
            for ($n = 0; $n < $w; $n++) {
                $pool[] = $i;
            }
        }
        $hired = [];
        $chiefs = [];
        $hotelId = $this->ctx->requireHotelId();
        for ($i = $existing; $i < $target; $i++) {
            $role = $roles[$i === 0 ? 0 : $pool[($i - 1) % count($pool)]];
            [$d, $title, , $salary] = $role;
            $female = mt_rand(0, 100) < 52;
            $first = self::FIRST[array_rand(self::FIRST)];
            $last = self::LAST[array_rand(self::LAST)];
            $start = Carbon::now()->subDays(mt_rand(40, 2400))->startOfDay();
            $kind = mt_rand(1, 100) <= 72 ? 'CDI' : (mt_rand(1, 100) <= 85 ? 'CDD' : 'journalier');
            $pay = mt_rand(1, 100) <= 55 ? 'mobile_money' : (mt_rand(1, 100) <= 80 ? 'bank' : 'cash');
            $salary = (int) (round($salary * (0.92 + mt_rand(0, 25) / 100) / 500) * 500);
            $phone = '6'.random_int(50000000, 99999999);
            $e = $hr->hire([
                'first_name' => $first, 'last_name' => $last, 'gender' => $female ? 'F' : 'M', 'date_of_birth' => Carbon::now()->subYears(mt_rand(21, 55))->subDays(mt_rand(0, 360))->toDateString(),
                'nationality' => 'CM', 'id_number' => (string) random_int(100000000, 999999999), 'cnps_number' => 'C'.random_int(1000000, 9999999), 'tax_number' => 'P'.random_int(100000000, 999999999).'Z',
                'phone' => $phone, 'email' => strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first.'.'.$last))).$i.'.h'.$hotelId.'@opes-demo.cm',
                'marital_status' => ['single', 'married', 'married', 'divorced'][mt_rand(0, 3)], 'children' => mt_rand(0, 4), 'payment_method' => $pay,
                'bank_name' => $pay === 'bank' ? ['Afriland First Bank', 'BICEC', 'UBA', 'SGBC', 'Ecobank'][mt_rand(0, 4)] : null, 'bank_account' => $pay === 'bank' ? (string) random_int(10000000000, 99999999999) : null,
                'momo_number' => $pay === 'mobile_money' ? '237'.$phone : null, 'department_id' => $depts[$d]->id, 'position_id' => $positions[$title]->id,
                'hire_date' => $start->toDateString(), 'photo_path' => '/demo/avatar.png',
                'contract' => ['kind' => $kind, 'start_date' => $start->toDateString(), 'end_date' => $kind === 'CDD' ? $start->copy()->addYear()->max(Carbon::now()->addMonths(3))->toDateString() : null, 'base_salary' => $salary, 'hours_per_week' => 40],
            ]);
            $hired[$title][] = $e;
            if ($i === $existing) {
                $chiefs['gm'] = $e;
            }
        }
        $gm = Employee::orderBy('id')->first();
        foreach (Employee::whereNull('reports_to')->where('id', '!=', $gm->id)->get() as $e) {
            $e->update(['reports_to' => $gm->id]);
        }

        // Some leave requests, mostly approved.
        $types = LeaveType::pluck('id');
        foreach (Employee::inRandomOrder()->limit((int) ceil($target * 0.35))->get() as $e) {
            try {
                $s = Carbon::now()->addDays(mt_rand(-40, 30));
                $req = $hr->requestLeave($e, $types->random(), $s->toDateString(), $s->copy()->addDays(mt_rand(1, 5))->toDateString(), 'Demo');
                if (mt_rand(1, 100) <= 80) {
                    $hr->decideLeave($req, mt_rand(1, 100) <= 90);
                }
            } catch (\Throwable) {
                // balance or overlap rules: skip
            }
        }

        return Employee::count();
    }

    private function payroll(Hotel $hotel): void
    {
        $svc = app(PayrollService::class);
        foreach ([2, 1] as $back) {
            $period = Carbon::now()->subMonthsNoOverflow($back)->format('Y-m');
            try {
                $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $this->actor);
                $run = $svc->compute($period);
                $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $this->approver);
                $svc->approve($run);
                $svc->markPaid($run->refresh(), '521100');
            } catch (\Throwable $e) {
                $this->say("  payroll $period skipped: ".$e->getMessage());
            }
        }
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $this->actor);
    }

    // ------------------------------------------------------------------ guests and partners

    private function guests(Group $group, int $target): array
    {
        $have = Guest::count();
        for ($i = $have; $i < $target; $i++) {
            $local = mt_rand(1, 100) <= 78;
            [$last, $nat] = $local ? [self::LAST[array_rand(self::LAST)], 'CM'] : self::FOREIGN[array_rand(self::FOREIGN)];
            $first = self::FIRST[array_rand(self::FIRST)];
            Guest::create([
                'title' => mt_rand(0, 1) ? 'M.' : 'Mme', 'first_name' => $first, 'last_name' => $last, 'nationality' => $nat, 'country' => $nat, 'language' => in_array($nat, ['FR', 'GB', 'NG', 'ES', 'IT', 'CN']) && $nat !== 'FR' ? 'en' : 'fr',
                'email' => strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $first.$last))).$i.'@example.com', 'phone' => '+2376'.random_int(50000000, 99999999),
                'gender' => mt_rand(0, 1) ? 'M' : 'F', 'date_of_birth' => Carbon::now()->subYears(mt_rand(20, 70))->toDateString(), 'id_type' => mt_rand(1, 100) <= 70 ? 'cni' : 'passport',
                'id_number' => strtoupper(substr($last, 0, 2)).random_int(1000000, 9999999), 'profession' => ['Commerçant', 'Ingénieur', 'Enseignant', 'Médecin', 'Consultant', 'Fonctionnaire', 'Étudiant', 'Chef d\'entreprise'][mt_rand(0, 7)], 'vip' => mt_rand(1, 100) <= 6,
            ]);
        }

        return Guest::pluck('id')->all();
    }

    private function companies(): array
    {
        $names = ['Camtel', 'MTN Cameroon', 'Orange Cameroun', 'Total Energies', 'Société Générale Cameroun', 'Brasseries du Cameroun', 'Dangote Cement', 'CAMRAIL', 'Eneo', 'Port Autonome de Douala', 'Afriland First Bank', 'SABC', 'Bolloré Logistics', 'Perenco', 'Guinness Cameroon', 'Nestlé Cameroun', 'Orange Business', 'Ambassade de France', 'PNUD Cameroun', 'Cabinet Ngo & Associés'];
        foreach ($names as $n) {
            Company::firstOrCreate(['name' => $n], ['city_ledger' => true, 'credit_limit' => mt_rand(3, 25) * 1_000_000, 'payment_terms_days' => [15, 30, 45][mt_rand(0, 2)]]);
        }
        foreach (['Voyages Kamer', 'Sahel Tours', 'Cameroon Travel Group', 'Atlantic Voyages', 'Safari Cam', 'Equator Trips'] as $n) {
            Company::firstOrCreate(['name' => $n], ['kind' => 'travel_agent', 'commission_percent' => [8, 10, 12][mt_rand(0, 2)]]);
        }

        return Company::where('city_ledger', true)->pluck('id')->all();
    }

    private function suppliers(): void
    {
        foreach ([['BRASS', 'Brasseries du Cameroun', 30], ['FRESH', 'Demo Produits Frais', 15], ['VIANDE', 'Boucherie Centrale', 7], ['POISS', 'Pêcherie de Kribi', 7], ['EPICE', 'Grossiste Mokolo', 30], ['HYG', 'Hygiène Pro', 30], ['LIN', 'Textiles Bonaberi', 45], ['QUINC', 'Quincaillerie Moderne', 30], ['BOISS', 'Sodiscam Boissons', 30], ['ELEC', 'Électro Plus', 30]] as [$c, $n, $t]) {
            Supplier::firstOrCreate(['code' => $c], ['name' => $n, 'payment_terms_days' => $t]);
        }
    }

    private function loyalty(array $guestIds): void
    {
        $svc = app(LoyaltyService::class);
        foreach (Guest::whereIn('id', array_slice($guestIds, 0, (int) (count($guestIds) * 0.15)))->get() as $g) {
            try {
                $svc->enrol($g, mt_rand(0, 1) ? 'physical' : 'digital');
            } catch (\Throwable) {
                // already a member
            }
        }
    }

    // ------------------------------------------------------------------ the day-by-day simulation

    private function simulate(Hotel $hotel, int $bookings, int $past, int $future, array $guestIds, array $companyIds): array
    {
        $res = app(ReservationService::class);
        $front = app(FrontDeskService::class);
        $folios = app(FolioService::class);
        $audit = app(NightAudit::class);
        $cashier = app(CashierService::class);
        $pos = app(PosService::class);

        $today = Carbon::now('Africa/Douala')->startOfDay();
        $start = $today->copy()->subDays($past);
        $plans = RatePlan::whereIn('code', ['BAR', 'BB', 'NRF', 'CORP'])->get()->keyBy('code');
        $types = RoomType::all();
        $weights = [];
        foreach ($types as $t) {
            $weights[$t->id] = match ($t->code) { 'STD' => 40, 'TWN' => 20, 'DLX' => 20, 'FAM' => 10, default => 10 };
        }
        $stats = ['reservations' => 0, 'stays' => 0, 'checks' => 0, 'cancelled' => 0];

        // 1. Book everything up front (arrivals between the start of history and the future window).
        $span = $past + $future;
        $created = [];
        for ($i = 0; $i < $bookings; $i++) {
            $arrival = $start->copy()->addDays(mt_rand(0, $span));
            $nights = [1, 1, 2, 2, 2, 3, 3, 4, 5, 7][mt_rand(0, 9)];
            $corp = mt_rand(1, 100) <= 14;
            $plan = $corp ? $plans['CORP'] : $plans[['BAR', 'BAR', 'BB', 'BB', 'NRF'][mt_rand(0, 4)]];
            $typeId = $this->weighted($weights);
            $channel = ['direct', 'direct', 'phone', 'booking_com', 'booking_com', 'expedia', 'agent', 'corporate'][mt_rand(0, 7)];
            try {
                $r = $res->create([
                    'guest_id' => $guestIds[array_rand($guestIds)], 'room_type_id' => $typeId, 'rate_plan_id' => $plan->id,
                    'arrival' => $arrival->toDateString(), 'departure' => $arrival->copy()->addDays($nights)->toDateString(),
                    'adults' => mt_rand(1, 2), 'children' => mt_rand(0, 100) <= 12 ? 1 : 0, 'channel' => $corp ? 'corporate' : $channel,
                    'company_id' => $corp ? $companyIds[array_rand($companyIds)] : null, 'guarantee' => $corp ? 'company' : null, 'enforce_restrictions' => false,
                ]);
                $created[] = $r->id;
                $stats['reservations']++;
            } catch (DomainException) {
                // sold out that night: skip
            }
        }
        // 9 % cancel before arrival
        foreach (array_slice($created, 0, (int) (count($created) * 0.09)) as $id) {
            try {
                $res->cancel(Reservation::find($id), 'Annulation client', true);
                $stats['cancelled']++;
            } catch (\Throwable) {
            }
        }

        // 2. Walk through history, one business day at a time.
        DB::table('hotels')->where('id', $hotel->id)->update(['business_date' => $start->toDateString()]);
        $methods = ['CASH', 'CASH', 'CASH', 'MTN', 'MTN', 'OM', 'CARD', 'XFER'];
        for ($d = $start->copy(); $d->lte($today); $d->addDay()) {
            $date = $d->toDateString();
            \App\Modules\FrontOffice\Domain\CashierShift::where('user_id', $this->actor->id)->where('status', 'open')->update(['status' => 'closed', 'closed_at' => now()]);
            $shift = $cashier->open(mt_rand(5, 20) * 5000, 'FRONT');

            foreach (Reservation::where('status', 'confirmed')->whereDate('arrival', $date)->get() as $r) {
                if (mt_rand(1, 100) <= 4) {
                    continue; // no-show, closed by the night audit
                }
                $room = Room::where('room_type_id', $r->room_type_id)->where('occupied', false)->where('hk_status', 'vacant_clean')->orderBy('id')->first()
                    ?? Room::where('occupied', false)->where('hk_status', 'vacant_clean')->orderBy('id')->first();
                if (! $room) {
                    continue;
                }
                try {
                    $front->checkIn($r, ['room_id' => $room->id]);
                    $stats['stays']++;
                } catch (\Throwable) {
                }
            }

            // Restaurant and bar.
            $inHouse = Reservation::where('status', 'checked_in')->pluck('id')->all();
            $outlets = Outlet::whereIn('code', ['REST', 'BAR'])->get();
            $menu = MenuItem::all();
            $checks = min(30, mt_rand(3, 8) + (int) (count($inHouse) / 3));
            for ($c = 0; $c < $checks && $menu->isNotEmpty(); $c++) {
                try {
                    $check = $pos->open($outlets->random()->id, ['covers' => mt_rand(1, 4)]);
                    foreach ($menu->random(mt_rand(2, 4)) as $mi) {
                        $pos->addItem($check, $mi->id, mt_rand(1, 3));
                    }
                    $pos->fire($check);
                    $check->refresh();
                    if ($inHouse && mt_rand(1, 100) <= 28) {
                        $pos->chargeToRoom($check, $inHouse[array_rand($inHouse)]);
                    } else {
                        $total = (int) $check->total;
                        $pos->settle($check, [['method' => $methods[array_rand($methods)], 'amount' => $total, 'tendered' => $total]]);
                    }
                    $stats['checks']++;
                } catch (\Throwable) {
                }
            }

            // Departures: pay the bill, check out, housekeeping cleans.
            foreach (Reservation::where('status', 'checked_in')->whereDate('departure', '<=', $date)->get() as $r) {
                try {
                    $data = ['waive_fee' => true];
                    foreach (Folio::where('reservation_id', $r->id)->where('status', 'open')->get() as $f) {
                        $bal = (int) $f->fresh()->balance;
                        if ($bal > 0) {
                            if ($r->company_id && $r->guarantee === 'company') {
                                $data['city_ledger_company_id'] = $r->company_id;
                            } else {
                                $folios->payment($f, $methods[array_rand($methods)], $bal);
                            }
                        }
                    }
                    $front->checkOut($r, $data);
                    Room::where('id', $r->room_id)->update(['hk_status' => mt_rand(1, 100) <= 85 ? 'vacant_clean' : 'vacant_dirty']);
                } catch (\Throwable) {
                }
            }

            // Close the cashier shift (small shortages now and then), then run the night audit except for today.
            try {
                $expected = $cashier->expected($shift);
                $counted = $expected;
                if (mt_rand(1, 100) <= 12) {
                    $counted['CASH'] = max(0, ($expected['CASH'] ?? 0) - mt_rand(1, 6) * 500);
                }
                $cashier->close($shift, $counted);
            } catch (\Throwable) {
            }
            if ($d->lt($today)) {
                try {
                    $audit->run(Hotel::find($hotel->id));
                } catch (\Throwable $e) {
                    $this->say("  audit $date: ".$e->getMessage());
                    DB::table('hotels')->where('id', $hotel->id)->update(['business_date' => $d->copy()->addDay()->toDateString()]);
                }
            }
        }
        // Sweep housekeeping so tomorrow's arrivals find clean rooms.
        Room::where('hk_status', 'vacant_dirty')->limit(PHP_INT_MAX)->update(['hk_status' => 'vacant_clean']);

        return $stats;
    }

    private function weighted(array $weights): int
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $id => $w) {
            if (($r -= $w) <= 0) {
                return $id;
            }
        }

        return array_key_first($weights);
    }

    private function summary(Group $group): void
    {
        $q = fn (string $t, string $w = '') => DB::table($t)->when($w, fn ($x) => $x->whereRaw($w))->count();
        $this->say(sprintf(
            "\nDONE. Hotels %d · rooms %d · guests %d · reservations %d (in house %d) · employees %d · payslips %d · POS checks %d · stock items %d · GL entries %d",
            $q('hotels'), $q('rooms'), $q('guests'), $q('reservations'), $q('reservations', "status='checked_in'"), $q('employees'), $q('payslips'), $q('pos_checks'), $q('stock_items'), $q('journal_entries'),
        ));
    }

    private function say(string $m): void
    {
        $this->command?->info($m);
    }
}
