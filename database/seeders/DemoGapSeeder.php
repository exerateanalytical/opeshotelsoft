<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Application\FixedAssets;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Application\Reconciliation;
use App\Modules\Accounting\Close\MonthEndClose;
use App\Modules\Accounting\Close\StatementsPack;
use App\Modules\Accounting\Application\Core\Budgets;
use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\Accounting\Payables\Dunning;
use App\Modules\Accounting\Payables\InvoiceApprovals;
use App\Modules\Accounting\Tax\PayrollFilings;
use App\Modules\Accounting\Tax\VatReturns;
use App\Modules\Maintenance\Application\MaintenanceService;
use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Domain\Supplier;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fills the demo screens that the other demo seeders leave empty (bank reconciliation, budgets, fixed assets,
 * maintenance, rosters, WhatsApp inbox, talent, reviews, payments, payables approvals, dunning, closing, tax filings...).
 * Accounting-relevant data goes through the real domain services so ledgers and numbering stay consistent.
 * Does NOT touch POS, stock or price-list tables (DemoPosStockSeeder owns those).
 *
 *   php artisan db:seed --class=DemoGapSeeder
 * Idempotent per hotel and section; local/demo only (refuses production unless ALLOW_DEMO_SEED=true).
 */
class DemoGapSeeder extends Seeder
{
    private TenantContext $ctx;

    private ?User $actor = null;

    private Hotel $hotel;

    private bool $full = false;

    private array $log = [];

    private const FIRST = ['Jean-Pierre', 'Marie-Claire', 'Paul', 'Aïcha', 'Emmanuel', 'Chantal', 'Blaise', 'Nadège', 'Serge', 'Fatima', 'Landry', 'Brenda', 'Ruth', 'Ibrahim', 'Estelle', 'Cyrille'];

    private const LAST = ['Mballa', 'Nkodo', 'Tchamba', 'Fotso', 'Ngono', 'Abena', 'Essomba', 'Kamga', 'Njoya', 'Biya Ondoa', 'Atangana', 'Mbarga', 'Tagne', 'Ewane', 'Manga', 'Nguimfack'];

    public function run(): void
    {
        if (app()->isProduction() && ! filter_var(env('ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->warn('Production environment: DemoGapSeeder skipped (set ALLOW_DEMO_SEED=true to force).');

            return;
        }
        mt_srand(4242);
        $this->ctx = app(TenantContext::class);
        $group = Group::where('slug', 'opes-demo')->first();
        if (! $group) {
            $this->command?->warn('Demo group not found: run DemoSeeder first.');

            return;
        }
        $this->actor = User::where('email', 'gm@demo.opeshotelsoft.test')->first() ?? User::orderBy('id')->first();
        $hotels = $this->ctx->withoutScope(fn () => Hotel::where('group_id', $group->id)->orderBy('id')->get());

        foreach ($hotels->take(4) as $i => $hotel) {
            $this->hotel = $hotel;
            $this->full = $i === 0;
            $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $this->actor);
            $this->command?->info("Gap seeding {$hotel->code} ({$hotel->name})".($this->full ? ' [full]' : ' [light]'));
            foreach ([
                'bank', 'budgets', 'fixedAssets', 'maintenance', 'workforce', 'whatsapp', 'serviceRequests', 'talent', 'reviews', 'payments',
                'guestFacing', 'revenue', 'loyaltyAndGroups', 'payables', 'dunning', 'closing', 'tax', 'insights',
            ] as $section) {
                $this->step($section);
            }
        }
        $this->ctx->set(null, null, null, $this->actor);
        foreach ($this->log as $l) {
            $this->command?->line($l);
        }
    }

    private function step(string $section): void
    {
        try {
            $n = DB::transaction(fn () => $this->{$section}());
            $this->log[] = "{$this->hotel->code} $section: ".($n ?? 'ok');
        } catch (\Throwable $e) {
            $this->log[] = "{$this->hotel->code} $section FAILED: ".substr($e->getMessage(), 0, 200);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function has(string $table): bool
    {
        return Schema::hasTable($table) && DB::table($table)->where('hotel_id', $this->hotel->id)->exists();
    }

    private function hasGroup(string $table): bool
    {
        return Schema::hasTable($table) && DB::table($table)->where('group_id', $this->hotel->group_id)->exists();
    }

    /** Raw insert with tenant columns and timestamps filled in; arrays are JSON-encoded. */
    private function ins(string $table, array $row, bool $hotelScoped = true): int
    {
        static $cols = [];
        $cols[$table] ??= Schema::getColumnListing($table);
        $row += ['group_id' => $this->hotel->group_id];
        if ($hotelScoped) {
            $row += ['hotel_id' => $this->hotel->id];
        }
        foreach (['created_at', 'updated_at'] as $c) {
            if (in_array($c, $cols[$table], true)) {
                $row += [$c => now()];
            }
        }
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        $row = array_intersect_key($row, array_flip($cols[$table]));

        return (int) DB::table($table)->insertGetId($row);
    }

    private function pick(array $a): mixed
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function name(): array
    {
        return [$this->pick(self::FIRST), $this->pick(self::LAST)];
    }

    private function user(string $login): ?User
    {
        return User::where('email', "$login@demo.opeshotelsoft.test")->first();
    }

    private function employees(int $n = 12)
    {
        return DB::table('employees')->where('hotel_id', $this->hotel->id)->orderBy('id')->limit($n)->get();
    }

    // ---------------------------------------------------------------- finance

    private function bank(): ?string
    {
        if ($this->has('bank_accounts')) {
            return 'skip';
        }
        $recon = app(Reconciliation::class);
        $ledger = app(Ledger::class);
        $main = BankAccount::create(['name' => 'Compte courant Afriland First Bank', 'kind' => 'bank', 'bank_name' => 'Afriland First Bank', 'account_number' => '10005 00012 '.mt_rand(10000000, 99999999).' 41', 'iban' => 'CM21 10005 00012 '.mt_rand(10000000, 99999999).' 41', 'currency' => 'XAF', 'gl_account' => '521100']);
        $momo = BankAccount::create(['name' => 'MTN Mobile Money Marchand', 'kind' => 'mobile_money', 'bank_name' => 'MTN MoMo', 'account_number' => '6'.mt_rand(70000000, 79999999), 'currency' => 'XAF', 'gl_account' => '521500']);

        // Ledger entries the statement will be matched against.
        $rows = [];
        $balance = 4_500_000;
        $items = [
            [-26, 'Versement espèces recette Réception', 1_850_000, 'VRS-'], [-24, 'Virement client Société Générale Cameroun', 3_200_000, 'VIR-'],
            [-21, 'Paiement fournisseur Brasseries du Cameroun', -1_450_000, 'CHQ-'], [-19, 'Frais de tenue de compte', -35_000, 'FRS-'],
            [-17, 'Versement espèces restaurant', 980_000, 'VRS-'], [-14, 'Paiement ENEO facture électricité', -2_150_000, 'PRL-'],
            [-12, 'Virement Orange Business Services', 1_600_000, 'VIR-'], [-9, 'Paiement CAMWATER eau', -420_000, 'PRL-'],
            [-7, 'Versement carte bancaire GIMAC', 2_750_000, 'GIM-'], [-5, 'Commission Booking.com', -385_000, 'COM-'],
            [-3, 'Versement espèces recette Bar', 640_000, 'VRS-'], [-1, 'Remboursement assurance AXA', 510_000, 'VIR-'],
        ];
        foreach ($items as $k => [$off, $desc, $amt, $pfx]) {
            $date = now()->addDays($off)->toDateString();
            $ref = $pfx.str_pad((string) (4400 + $k), 6, '0', STR_PAD_LEFT);
            // Post a ledger counterpart for most lines (leave the last two unmatched for the reconciliation screen).
            if ($k < count($items) - 2) {
                $counter = $amt > 0 ? '571100' : '605200';
                if ($amt < 0 && str_contains($desc, 'Frais')) {
                    $counter = '631100';
                } elseif ($amt < 0 && str_contains($desc, 'Commission')) {
                    $counter = '632000';
                } elseif ($amt < 0 && str_contains($desc, 'CAMWATER')) {
                    $counter = '605100';
                } elseif ($amt < 0 && str_contains($desc, 'Brasseries')) {
                    $counter = '622000';
                } elseif ($amt > 0 && str_contains($desc, 'Virement')) {
                    $counter = '706100';
                }
                $ledger->post('OD', $date, "$desc ($ref)", [['521100', $amt], [$counter, -$amt]], ['source_type' => 'demo_bank', 'source_id' => $k + 1]);
            }
            $balance += $amt;
            $rows[] = sprintf('%s;%s;%s;%s;%s;%s', Carbon::parse($date)->format('d/m/Y'), $desc, $amt < 0 ? abs($amt) : '', $amt > 0 ? $amt : '', $ref, $balance);
        }
        $csv = "Date;Libellé;Débit;Crédit;Référence;Solde\n".implode("\n", $rows);
        $recon->import($main, $csv, ['date' => 'date', 'description' => 'libellé', 'debit' => 'débit', 'credit' => 'crédit', 'reference' => 'référence', 'balance' => 'solde'], ['delimiter' => ';', 'date_format' => 'd/m/Y', 'filename' => 'releve_afriland_'.now()->format('Ym').'.csv']);
        $recon->autoMatch($main);

        $momoCsv = "Date;Description;Montant;Référence\n"
            .now()->subDays(6)->format('d/m/Y').";Paiement client MoMo 677 12 34 56;85000;MM-771203\n"
            .now()->subDays(4)->format('d/m/Y').";Paiement client MoMo 699 88 21 10;120000;MM-771544\n"
            .now()->subDays(2)->format('d/m/Y').";Frais de retrait MoMo;-2500;MM-771901\n";
        $recon->import($momo, $momoCsv, ['date' => 'date', 'description' => 'description', 'amount' => 'montant', 'reference' => 'référence'], ['delimiter' => ';', 'date_format' => 'd/m/Y', 'filename' => 'momo.csv']);

        // A bank-feed rule so the rules screen is not empty.
        if (Schema::hasTable('bank_rules')) {
            try {
                app(\App\Modules\Accounting\Payables\BankFeed::class)->saveRule(['name' => 'Frais bancaires', 'bank_account_id' => $main->id, 'pattern' => 'Frais', 'account_code' => '631100']);
            } catch (\Throwable) {
            }
        }

        return 'accounts=2';
    }

    private function budgets(): ?string
    {
        if ($this->has('budgets')) {
            return 'skip';
        }
        $accounts = DB::table('accounts')->where('hotel_id', $this->hotel->id)->whereIn('code', ['706100', '706200', '706300', '706500', '605100', '605200', '624000', '622000'])->pluck('code')->all();
        $base = ['706100' => 38_000_000, '706200' => 12_000_000, '706300' => 9_000_000, '706500' => 2_500_000, '605100' => 1_200_000, '605200' => 4_800_000, '624000' => 2_200_000, '622000' => 3_000_000];
        $season = [0.85, 0.9, 1.0, 1.05, 1.0, 0.95, 0.9, 0.95, 1.05, 1.1, 1.15, 1.25];
        $lines = [];
        foreach ($accounts as $code) {
            foreach ($season as $m => $f) {
                $lines[] = ['account' => $code, 'department' => in_array($code[0], ['7'], true) ? null : 'rooms', 'month' => $m + 1, 'amount' => (int) (round($base[$code] * $f / 10_000) * 10_000)];
            }
        }
        $year = (int) now()->format('Y');
        $b = app(Budgets::class);
        $draft = $b->save("Budget $year", $year, $lines);
        $b->approve($draft['id']);
        $b->save('Budget '.($year + 1).' (brouillon)', $year + 1, array_map(fn ($l) => $l + ['amount' => (int) ($l['amount'] * 1.06)] + $l, $lines));

        return 'budgets=2';
    }

    private function fixedAssets(): ?string
    {
        if ($this->has('fixed_assets')) {
            return 'skip';
        }
        $fa = app(FixedAssets::class);
        $classes = [
            $fa->saveClass(['code' => 'MAT', 'name' => 'Matériel et outillage', 'asset_account' => '241000', 'depreciation_account' => '284100', 'expense_account' => '681000', 'method' => 'straight_line', 'useful_life_months' => 120]),
            $fa->saveClass(['code' => 'MOB', 'name' => 'Mobilier et agencements', 'asset_account' => '244000', 'depreciation_account' => '284400', 'expense_account' => '681000', 'method' => 'straight_line', 'useful_life_months' => 84]),
            $fa->saveClass(['code' => 'VEH', 'name' => 'Matériel de transport', 'asset_account' => '245000', 'depreciation_account' => '284500', 'expense_account' => '681000', 'method' => 'straight_line', 'useful_life_months' => 60]),
        ];
        $defs = [
            [0, 'Groupe électrogène 250 kVA', 38_500_000, 30], [0, 'Chaudière centrale et ballons ECS', 14_200_000, 22], [0, 'Équipement de buanderie industrielle', 9_800_000, 18],
            [1, 'Mobilier chambres étage 1', 21_000_000, 26], [1, 'Mobilier restaurant Le Wouri', 7_400_000, 14], [1, 'Literie Deluxe (lot de 20)', 5_600_000, 10],
            [2, 'Minibus navette aéroport Toyota Hiace', 24_500_000, 16], [2, 'Berline de direction', 18_000_000, 8],
        ];
        $n = 0;
        foreach ($defs as [$ci, $name, $cost, $monthsAgo]) {
            $acq = now()->subMonths($monthsAgo)->startOfMonth()->addDays(mt_rand(2, 20));
            $fa->acquire(['asset_class_id' => $classes[$ci]->id, 'name' => $name, 'cost' => $cost, 'acquired_on' => $acq->toDateString(), 'salvage_value' => 0]);
            $n++;
        }
        // Depreciation for the last 6 closed months.
        for ($m = 6; $m >= 1; $m--) {
            $d = now()->subMonths($m);
            try {
                $fa->run((int) $d->format('Y'), (int) $d->format('n'));
            } catch (\Throwable) {
            }
        }

        return "assets=$n";
    }

    private function payables(): ?string
    {
        if (DB::table('supplier_invoices')->where('hotel_id', $this->hotel->id)->whereIn('status', ['approved', 'paid', 'partially_paid'])->exists()) {
            return 'skip';
        }
        $proc = app(ProcurementService::class);
        $appr = app(InvoiceApprovals::class);
        $suppliers = [];
        foreach ([['ENEO', 'ENEO Cameroun SA', 30], ['CAMWT', 'CAMWATER', 30], ['SOCAM', 'Socam Maintenance SARL', 30], ['IMMOB', 'SCI Bonanjo Immobilier', 30], ['TELCO', 'Orange Business Services', 15]] as [$code, $name, $terms]) {
            $suppliers[$code] = Supplier::firstOrCreate(['code' => $code], ['name' => $name, 'payment_terms_days' => $terms]);
        }
        // Approval chains by amount (idempotent).
        if (! DB::table('invoice_approval_chains')->where('hotel_id', $this->hotel->id)->exists()) {
            $appr->saveChain(['name' => 'Petites factures', 'min_amount' => 0, 'max_amount' => 1_000_000, 'steps' => [['role' => 'finance-director', 'label' => 'Directeur financier']]]);
            $appr->saveChain(['name' => 'Grosses factures', 'min_amount' => 1_000_001, 'steps' => [['role' => 'finance-director', 'label' => 'Directeur financier'], ['role' => 'general-manager', 'label' => 'Directeur général']]]);
        }
        $fin = $this->user('finance');
        $gm = $this->user('gm');
        $defs = [
            // supplier, ref, account, desc, net, days ago, outcome
            ['ENEO', 'ENEO-'.now()->format('Ym').'-0412', '605200', 'Électricité mois courant', 2_350_000, 12, 'paid'],
            ['CAMWT', 'CW-8841', '605100', 'Consommation eau', 410_000, 20, 'paid'],
            ['SOCAM', 'SM-2231', '624000', 'Maintenance climatisation', 780_000, 9, 'approved'],
            ['IMMOB', 'LOY-'.now()->format('m'), '622000', 'Loyer parking et annexes', 1_500_000, 6, 'half-approved'],
            ['TELCO', 'OBS-77120', '624000', 'Liaison internet fibre', 295_000, 4, 'pending'],
            ['SOCAM', 'SM-2250', '624000', 'Réfection plomberie chambres 201-210', 1_240_000, 3, 'pending'],
            ['ENEO', 'ENEO-REG-77', '605200', 'Régularisation compteur', 190_000, 15, 'rejected'],
        ];
        $n = 0;
        foreach ($defs as [$sup, $ref, $acct, $desc, $net, $ago, $outcome]) {
            $date = now()->subDays($ago);
            $tax = (int) round($net * 0.1925);
            $inv = SupplierInvoice::where('supplier_id', $suppliers[$sup]->id)->where('reference', $ref)->first()
                ?? $proc->recordInvoice(['supplier_id' => $suppliers[$sup]->id, 'reference' => $ref, 'date' => $date->toDateString(), 'due_date' => $date->copy()->addDays(30)->toDateString(),
                    'net_total' => $net, 'tax_total' => $tax, 'expense_lines' => [['account' => $acct, 'amount' => $net, 'description' => $desc]]]);
            $n++;
            if ($outcome === 'pending' || DB::table('invoice_workflows')->where('supplier_invoice_id', $inv->id)->exists()) {
                continue;
            }
            $this->ctx->set($this->hotel->group_id, $this->hotel->id, [$this->hotel->id], $this->user('accountant') ?? $this->actor);
            $wf = $appr->submit($inv);
            $steps = $wf['steps'] ?? $wf['approvals'] ?? [];
            $first = DB::table('invoice_approvals')->where('supplier_invoice_id', $inv->id)->orderBy('step_no')->get();
            foreach ($first as $idx => $step) {
                if ($outcome === 'rejected') {
                    try {
                        $appr->decide($step->id, false, 'Montant non conforme au relevé compteur', $fin);
                    } catch (\Throwable) {
                    }
                    break;
                }
                if ($outcome === 'half-approved' && $idx >= 1) {
                    break;
                }
                $user = $step->role === 'general-manager' ? $gm : $fin;
                $this->ctx->set($this->hotel->group_id, $this->hotel->id, [$this->hotel->id], $user);
                try {
                    $appr->decide($step->id, true, 'Conforme', $user);
                } catch (\Throwable) {
                    break;
                }
            }
            $this->ctx->set($this->hotel->group_id, $this->hotel->id, [$this->hotel->id], $this->actor);
            if ($outcome === 'paid') {
                $proc->pay(SupplierInvoice::find($inv->id), (int) $inv->total, '521100', 'VIR-'.$ref);
            }
        }

        return "invoices=$n";
    }

    private function dunning(): ?string
    {
        if ($this->has('dunning_notices')) {
            return 'skip';
        }
        $d = app(Dunning::class);
        $res = $d->send(null, null, 'fr');
        $cand = $d->candidates();
        $held = collect($cand)->firstWhere('held', false);
        if ($held && $held['company_id'] && $this->full) {
            $d->hold($held['company_id'], null, 'Litige en cours sur la facture, accord de la direction');
        }

        return 'notices='.count($res);
    }

    private function closing(): ?string
    {
        if ($this->has('close_runs')) {
            return 'skip';
        }
        $m = app(MonthEndClose::class);
        $prev = now()->subMonth();
        $m->start((int) $prev->format('Y'), (int) $prev->format('n'));
        $run = $m->show((int) $prev->format('Y'), (int) $prev->format('n'));
        $tasks = $run['tasks'] ?? [];
        $signed = 0;
        foreach ($tasks as $i => $t) {
            if ($i >= (int) ceil(count($tasks) / 2)) {
                break;
            }
            try {
                $m->sign($t['id'], $this->actor, 'Contrôle effectué', 'Visé en démonstration');
                $signed++;
            } catch (\Throwable) {
            }
        }

        return 'tasks='.count($tasks).' signed='.$signed;
    }

    private function tax(): ?string
    {
        $n = 0;
        if (! $this->has('vat_returns')) {
            $from = now()->subMonth()->startOfMonth();
            $v = app(VatReturns::class);
            $r = $v->prepare($from->toDateString(), $from->copy()->endOfMonth()->toDateString());
            $n++;
            if ($this->full && isset($r['id'])) {
                $v->file($r['id'], 'DSF-'.$from->format('Ym').'-0071', now()->subDays(5)->toDateString());
            }
        }
        if (! $this->has('statement_packs')) {
            $from = now()->subMonth()->startOfMonth();
            $p = app(StatementsPack::class)->save($from->toDateString(), $from->copy()->endOfMonth()->toDateString());
            $n++;
            if ($this->full && isset($p['id'])) {
                app(StatementsPack::class)->finalize($p['id']);
            }
        }
        if (! $this->has('payroll_filings')) {
            $period = DB::table('payroll_runs')->where('hotel_id', $this->hotel->id)->whereIn('status', ['approved', 'paid'])->orderByDesc('period')->value('period');
            if ($period) {
                foreach (PayrollFilings::KINDS as $kind) {
                    try {
                        [, , $fid] = app(PayrollFilings::class)->export($kind, $period, 'csv');
                        $f = ['id' => $fid];
                        $n++;
                        if ($this->full && isset($f['id']) && $kind === PayrollFilings::KINDS[0]) {
                            app(PayrollFilings::class)->markFiled($f['id'], 'CNPS-'.str_replace('-', '', $period).'-118', now()->subDays(3)->toDateString());
                        }
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return "filings=$n";
    }

    private function insights(): ?string
    {
        if (! $this->full || $this->has('insight_digests')) {
            return 'skip';
        }
        $svc = app(\App\Modules\Insights\Application\DigestService::class);
        foreach ([3, 2, 1] as $d) {
            $date = now()->subDays($d)->toDateString();
            try {
                $svc->build($date);
            } catch (\Throwable) {
                $this->ins('insight_digests', ['date' => $date, 'payload' => ['revenue' => mt_rand(3, 6) * 1_000_000, 'occupancy' => mt_rand(55, 88), 'alerts' => mt_rand(0, 4)], 'messages' => 1, 'sent_at' => now()->subDays($d)]);
            }
        }

        return 'digests';
    }

    // ---------------------------------------------------------------- operations

    private function maintenance(): ?string
    {
        if ($this->has('work_orders')) {
            return 'skip';
        }
        $assetIds = [];
        foreach ([['AST-GEN-01', 'Groupe électrogène 250 kVA', 'electrical', 'Local technique'], ['AST-CLIM-01', 'Centrale de climatisation Chiller', 'hvac', 'Toit terrasse'], ['AST-ASC-01', 'Ascenseur principal', 'lift', 'Hall'],
            ['AST-PIS-01', 'Pompe de piscine', 'plumbing', 'Piscine'], ['AST-BUA-01', 'Lave-linge industriel 25 kg', 'laundry', 'Buanderie'], ['AST-CUI-01', 'Chambre froide cuisine', 'kitchen', 'Cuisine']] as [$tag, $name, $cat, $loc]) {
            $assetIds[$tag] = $this->ins('assets', ['tag' => $tag, 'name' => $name, 'category' => $cat, 'location' => $loc, 'purchased_on' => now()->subMonths(mt_rand(10, 40))->toDateString(), 'cost' => mt_rand(8, 40) * 1_000_000, 'status' => 'active']);
        }
        $pms = [];
        foreach ([['AST-GEN-01', 'Vidange et contrôle groupe électrogène', 'electrical', 90], ['AST-CLIM-01', 'Nettoyage filtres climatisation', 'hvac', 30], ['AST-ASC-01', 'Contrôle sécurité ascenseur', 'lift', 60], ['AST-CUI-01', 'Contrôle température chambre froide', 'kitchen', 14]] as [$tag, $title, $cat, $days]) {
            $pms[$tag] = $this->ins('pm_schedules', ['asset_id' => $assetIds[$tag], 'title' => $title, 'category' => $cat, 'every_days' => $days, 'next_due' => now()->addDays(mt_rand(-5, 20))->toDateString(), 'active' => true]);
        }
        $svc = app(MaintenanceService::class);
        $tech = $this->user('tech');
        $rooms = DB::table('rooms')->where('hotel_id', $this->hotel->id)->orderBy('id')->limit(12)->pluck('id')->all();
        $defs = [
            ['Fuite robinet salle de bain', 'plumbing', 'normal', 'done'], ['Climatisation ne refroidit pas', 'hvac', 'high', 'done'], ['Ampoule grillée couloir 2e étage', 'electrical', 'low', 'done'],
            ['Serrure carte défectueuse', 'locks', 'high', 'in_progress'], ['Télévision sans signal', 'electrical', 'normal', 'assigned'], ['Joint douche à refaire', 'plumbing', 'low', 'open'],
            ['Panne ascenseur - arrêt à l\'étage 3', 'lift', 'urgent', 'open'], ['Remplacer moustiquaire fenêtre', 'general', 'low', 'on_hold'], ['Peinture mur taché', 'painting', 'low', 'cancelled'],
        ];
        $n = 0;
        foreach ($defs as $i => [$title, $cat, $prio, $end]) {
            $payload = ['title' => $title, 'category' => $cat, 'priority' => $prio, 'room_id' => $rooms[$i % max(1, count($rooms))] ?? null, 'due_at' => now()->addDays(mt_rand(-2, 4))];
            if ($i === 6) {
                $payload['asset_id'] = $assetIds['AST-ASC-01'];
                $payload['room_id'] = null;
            }
            if ($tech && $end !== 'open') {
                $payload['assigned_to'] = $tech->id;
            }
            $wo = $svc->open($payload);
            $n++;
            if (in_array($end, ['in_progress', 'done', 'on_hold'], true)) {
                $svc->start($wo);
            }
            if ($end === 'done') {
                $svc->complete($wo, 'Intervention terminée, test concluant', mt_rand(30, 180), [], mt_rand(0, 2) * 5_000);
            } elseif ($end === 'on_hold') {
                $svc->hold($wo, 'Attente de la pièce chez le fournisseur');
            } elseif ($end === 'cancelled') {
                $svc->cancel($wo, 'Reporté à la rénovation de l\'étage');
            }
        }
        try {
            $svc->runPreventive();
        } catch (\Throwable) {
        }

        return "work_orders=$n";
    }

    private function workforce(): ?string
    {
        if ($this->has('workforce_rosters')) {
            return 'skip';
        }
        $depts = DB::table('departments')->where('hotel_id', $this->hotel->id)->get(['id', 'code']);
        $tpl = [];
        foreach ($depts as $d) {
            foreach ([['Matin', '06:00', '14:00', 40, 2], ['Après-midi', '14:00', '22:00', 40, 2], ['Nuit', '22:00', '06:00', 20, 1]] as [$nm, $s, $e, $share, $min]) {
                $tpl[$d->id][] = DB::table('workforce_shift_templates')->where(['hotel_id' => $this->hotel->id, 'department_id' => $d->id, 'name' => $nm])->value('id') ?? $this->ins('workforce_shift_templates', ['department_id' => $d->id, 'name' => $nm, 'starts_at' => $s, 'ends_at' => $e, 'share' => $share, 'min_staff' => $min, 'active' => true]);
            }
        }
        $emps = DB::table('employees')->where('hotel_id', $this->hotel->id)->where('status', 'active')->orderBy('id')->limit($this->full ? 30 : 10)->get();
        $weeks = $this->full ? [-1, 0, 1] : [0];
        $count = 0;
        foreach ($weeks as $w) {
            $monday = now()->startOfWeek()->addWeeks($w);
            $roster = $this->ins('workforce_rosters', ['week_start' => $monday->toDateString(), 'status' => $w <= 0 ? 'published' : 'draft', 'published_by' => $w <= 0 ? $this->actor?->id : null, 'published_at' => $w <= 0 ? $monday->copy()->subDays(3) : null, 'generated_by' => $this->actor?->id]);
            foreach ($emps as $k => $e) {
                $tplIds = $tpl[$e->department_id] ?? null;
                if (! $tplIds) {
                    continue;
                }
                for ($d = 0; $d < 7; $d++) {
                    if (($d + $k) % 7 === 6 || ($d + $k) % 7 === 3 && $k % 2) {
                        continue; // days off
                    }
                    $slot = [['06:00', '14:00'], ['14:00', '22:00'], ['22:00', '06:00']][($k + intdiv($d, 3)) % 3];
                    $date = $monday->copy()->addDays($d)->toDateString();
                    $this->ins('workforce_roster_shifts', ['roster_id' => $roster, 'department_id' => $e->department_id, 'employee_id' => $e->id, 'date' => $date, 'starts_at' => $slot[0], 'ends_at' => $slot[1], 'template_id' => $tplIds[($k + intdiv($d, 3)) % 3]]);
                    if ($w === 0 && Schema::hasTable('shifts')) {
                        $this->ins('shifts', ['employee_id' => $e->id, 'date' => $date, 'starts_at' => $slot[0], 'ends_at' => $slot[1], 'role' => 'Service']);
                    }
                    $count++;
                }
            }
        }

        return "roster_shifts=$count";
    }

    private function whatsapp(): ?string
    {
        if ($this->has('whatsapp_conversations')) {
            return 'skip';
        }
        if (! $this->has('whatsapp_accounts')) {
            $this->ins('whatsapp_accounts', ['phone_number_id' => (string) mt_rand(100000000000000, 999999999999999), 'display_phone' => '+237 699 00 11 22', 'active' => true]);
        }
        foreach ([['Bienvenue', "Bonjour et bienvenue à l'hôtel ! Comment pouvons-nous vous aider ?", 'fr'], ['Heure d\'arrivée', "L'enregistrement commence à 14h00 et le départ est à 12h00.", 'fr'], ['Wi-Fi', 'Le mot de passe Wi-Fi est affiché sur votre carte de chambre.', 'fr'], ['Welcome', 'Welcome to our hotel! How can we help you today?', 'en'], ['Airport shuttle', 'Our airport shuttle runs every hour from 06:00 to 22:00.', 'en']] as [$t, $b, $l]) {
            $this->ins('whatsapp_quick_replies', ['title' => $t, 'body' => $b, 'locale' => $l]);
        }
        $guests = DB::table('guests')->orderBy('id')->limit(6)->get(['id', 'first_name', 'last_name', 'phone']);
        $threads = [
            ['fr', 'open', 2, [['in', 'Bonsoir, est-ce que le restaurant est ouvert ce soir ?'], ['out', 'Bonsoir, oui Le Wouri est ouvert jusqu\'à 23h.'], ['in', 'Merci, pouvez-vous réserver une table pour 4 à 20h ?']]],
            ['en', 'open', 1, [['in', 'Hello, can I get a late check-out tomorrow?'], ['out', 'Hello, we can offer a 15:00 check-out for 10,000 XAF.'], ['in', 'Perfect, please confirm.']]],
            ['fr', 'resolved', 0, [['in', 'Avez-vous une navette pour l\'aéroport de Douala ?'], ['out', 'Oui, départs toutes les heures de 6h à 22h.'], ['in', 'Parfait, merci !']]],
            ['fr', 'open', 3, [['in', 'Ma climatisation ne marche pas (chambre 204).'], ['out', 'Désolé, un technicien arrive dans 10 minutes.']]],
            ['en', 'resolved', 0, [['in', 'Do you accept MTN Mobile Money?'], ['out', 'Yes, we accept MTN MoMo and Orange Money at the front desk.']]],
        ];
        $n = 0;
        foreach ($threads as $i => [$loc, $status, $unread, $msgs]) {
            $g = $guests[$i] ?? null;
            $wa = '2376'.mt_rand(50000000, 99999999);
            $conv = $this->ins('whatsapp_conversations', ['wa_id' => $wa, 'name' => $g ? trim($g->first_name.' '.$g->last_name) : implode(' ', $this->name()), 'guest_id' => $g->id ?? null, 'locale' => $loc, 'status' => $status, 'unread' => $unread, 'last_inbound_at' => now()->subMinutes(mt_rand(5, 600)), 'last_message_at' => now()->subMinutes(mt_rand(1, 300)), 'channel' => 'whatsapp']);
            foreach ($msgs as $k => [$dir, $body]) {
                $this->ins('whatsapp_messages', ['conversation_id' => $conv, 'direction' => $dir === 'in' ? 'in' : 'out', 'type' => 'text', 'body' => $body, 'status' => $dir === 'in' ? 'received' : 'read', 'dry_run' => true, 'sent_by' => $dir === 'out' ? $this->actor?->id : null, 'created_at' => now()->subMinutes(600 - $k * 20 - $i * 30), 'updated_at' => now()]);
                $n++;
            }
        }

        return "messages=$n";
    }

    private function serviceRequests(): ?string
    {
        if (DB::table('service_requests')->where('hotel_id', $this->hotel->id)->count() > 2) {
            return 'skip';
        }
        $rooms = DB::table('rooms')->where('hotel_id', $this->hotel->id)->orderBy('id')->limit(10)->get(['id', 'number']);
        $defs = [
            ['housekeeping', 'housekeeping', 'Serviettes supplémentaires (4)', 'normal', 'completed'], ['maintenance', 'maintenance', 'Robinet qui goutte', 'normal', 'in_progress'],
            ['room_service', 'fnb', 'Petit-déjeuner continental en chambre', 'normal', 'completed'], ['housekeeping', 'housekeeping', 'Nettoyage chambre avant 14h', 'high', 'pending'],
            ['concierge', 'front_office', 'Réserver un taxi pour 6h30', 'normal', 'acknowledged'], ['maintenance', 'maintenance', 'Climatisation bruyante', 'high', 'pending'],
            ['laundry', 'housekeeping', 'Pressing - 2 chemises, 1 costume', 'normal', 'completed'], ['concierge', 'front_office', 'Fer à repasser', 'low', 'cancelled'],
        ];
        $n = 0;
        foreach ($defs as $i => [$cat, $dept, $note, $prio, $status]) {
            $r = $rooms[$i % max(1, count($rooms))] ?? null;
            $created = now()->subHours(mt_rand(1, 40));
            $this->ins('service_requests', ['room_id' => $r->id ?? null, 'room_number' => $r->number ?? (string) (101 + $i), 'category' => $cat, 'department' => $dept, 'note' => $note, 'priority' => $prio, 'source' => $this->pick(['guest_portal', 'phone', 'staff']),
                'status' => $status, 'created_by' => $this->actor?->id, 'due_at' => $created->copy()->addMinutes($prio === 'high' ? 30 : 90),
                'acknowledged_at' => in_array($status, ['acknowledged', 'in_progress', 'completed'], true) ? $created->copy()->addMinutes(5) : null,
                'started_at' => in_array($status, ['in_progress', 'completed'], true) ? $created->copy()->addMinutes(12) : null,
                'completed_at' => $status === 'completed' ? $created->copy()->addMinutes(35) : null, 'completion_note' => $status === 'completed' ? 'Fait' : null,
                'cancelled_at' => $status === 'cancelled' ? $created->copy()->addMinutes(8) : null, 'created_at' => $created]);
            $n++;
        }

        return "requests=$n";
    }

    // ---------------------------------------------------------------- people

    private function talent(): ?string
    {
        if ($this->has('tal_job_posts')) {
            return 'skip';
        }
        $dept = DB::table('departments')->where('hotel_id', $this->hotel->id)->get()->keyBy('code');
        $posts = [];
        foreach ([['Réceptionniste bilingue', 'FO', 'open', 250_000, 350_000], ['Serveur / Serveuse restaurant', 'FB', 'open', 180_000, 260_000], ['Gouvernante adjointe', 'HK', 'open', 220_000, 300_000], ['Comptable fournisseurs', 'ADM', 'closed', 300_000, 450_000]] as [$title, $code, $status, $min, $max]) {
            $posts[] = $this->ins('tal_job_posts', ['title' => $title, 'department_id' => $dept[$code]->id ?? null, 'description' => "Nous recrutons un(e) $title pour notre établissement de {$this->hotel->city}.", 'requirements' => 'BTS/Licence hôtellerie, français et anglais courants, 2 ans d\'expérience.',
                'location' => $this->hotel->city, 'contract_kind' => 'cdi', 'salary_min' => $min, 'salary_max' => $max, 'openings' => 1, 'status' => $status, 'closes_on' => now()->addDays(25)->toDateString(), 'published_at' => now()->subDays(12)]);
        }
        $stages = ['applied', 'screening', 'interview', 'offer', 'interview', 'applied', 'rejected', 'screening'];
        $apps = [];
        foreach ($stages as $i => $stage) {
            [$f, $l] = $this->name();
            $apps[] = $this->ins('tal_applicants', ['job_post_id' => $posts[$i % 3], 'first_name' => $f, 'last_name' => $l, 'email' => strtolower(preg_replace('/[^a-z]/i', '', $f.'.'.$l)).'@example.cm', 'phone' => '+237 6'.mt_rand(50, 99).' '.mt_rand(10, 99).' '.mt_rand(10, 99).' '.mt_rand(10, 99),
                'cover_note' => 'Motivé(e) par un poste dans votre établissement.', 'source' => $this->pick(['site', 'linkedin', 'cooptation', 'emploi.cm']), 'stage' => $stage, 'consent_at' => now()->subDays(10), 'retain_until' => now()->addYears(2)->toDateString()]);
        }
        foreach ([2, 4, 3] as $k => $idx) {
            $this->ins('tal_interviews', ['applicant_id' => $apps[$idx], 'scheduled_at' => now()->addDays($k * 2 - 1)->setTime(10 + $k, 0), 'location' => 'Salle de réunion RH', 'kind' => 'panel', 'panel' => [$this->actor?->id], 'status' => $k === 0 ? 'done' : 'scheduled']);
        }
        // Training
        $cert = $this->hasGroup('tal_cert_types') ? DB::table('tal_cert_types')->where('group_id', $this->hotel->group_id)->first() : null;
        $certId = $cert->id ?? $this->ins('tal_cert_types', ['code' => 'HACCP', 'name' => 'Hygiène alimentaire HACCP', 'validity_months' => 24, 'mandatory' => true, 'alert_days' => 60], false);
        $sstId = DB::table('tal_cert_types')->where('group_id', $this->hotel->group_id)->where('code', 'SST')->value('id') ?? $this->ins('tal_cert_types', ['code' => 'SST', 'name' => 'Secourisme au travail', 'validity_months' => 36, 'mandatory' => false, 'alert_days' => 90], false);
        $course = DB::table('tal_courses')->where('group_id', $this->hotel->group_id)->value('id')
            ?? $this->ins('tal_courses', ['title' => 'Accueil client et excellence de service', 'duration_hours' => 14, 'provider' => 'Institut Supérieur d\'Hôtellerie de Douala', 'default_cost' => 150_000, 'active' => true], false);
        $course2 = DB::table('tal_courses')->where('group_id', $this->hotel->group_id)->where('title', 'Hygiène et sécurité alimentaire')->value('id')
            ?? $this->ins('tal_courses', ['title' => 'Hygiène et sécurité alimentaire', 'cert_type_id' => $certId, 'duration_hours' => 7, 'provider' => 'Bureau Veritas Cameroun', 'default_cost' => 90_000, 'active' => true], false);
        $emps = $this->employees(12);
        foreach ([[$course, -20, 'done'], [$course2, 8, 'planned'], [$course, 21, 'planned']] as [$c, $off, $status]) {
            $s = $this->ins('tal_training_sessions', ['course_id' => $c, 'starts_on' => now()->addDays($off)->toDateString(), 'ends_on' => now()->addDays($off + 1)->toDateString(), 'location' => 'Salle de conférence', 'trainer' => implode(' ', $this->name()), 'capacity' => 12, 'cost' => 400_000, 'status' => $status]);
            foreach ($emps->take(5) as $e) {
                $this->ins('tal_training_attendances', ['session_id' => $s, 'employee_id' => $e->id, 'attendance' => $status === 'done' ? 'present' : 'registered', 'result' => $status === 'done' ? 'passed' : null, 'score' => $status === 'done' ? mt_rand(65, 98) : null, 'cost' => 80_000, 'applied' => false]);
            }
        }
        foreach ($emps as $k => $e) {
            $type = $k % 2 ? $certId : $sstId;
            $issued = now()->subMonths(mt_rand(1, 30));
            $this->ins('tal_certifications', ['employee_id' => $e->id, 'cert_type_id' => $type, 'issued_on' => $issued->toDateString(), 'expires_on' => $issued->copy()->addMonths($k % 2 ? 24 : 36)->toDateString(), 'reference' => 'CERT-'.mt_rand(10000, 99999), 'issuer' => 'Ministère de la Santé Publique']);
        }

        return 'posts=4';
    }

    private function reviews(): ?string
    {
        if (DB::table('people_reviews')->where('hotel_id', $this->hotel->id)->count() > 3) {
            return 'skip';
        }
        $y = (int) now()->format('Y');
        $cycle = DB::table('people_review_cycles')->where(['hotel_id' => $this->hotel->id, 'name' => "Évaluation annuelle $y"])->value('id') ?? $this->ins('people_review_cycles', ['name' => "Évaluation annuelle $y", 'period_start' => "$y-01-01", 'period_end' => "$y-12-31", 'status' => 'open', 'scale' => 5]);
        DB::table('people_review_cycles')->where(['hotel_id' => $this->hotel->id, 'name' => 'Évaluation annuelle '.($y - 1)])->exists() || $this->ins('people_review_cycles', ['name' => 'Évaluation annuelle '.($y - 1), 'period_start' => ($y - 1).'-01-01', 'period_end' => ($y - 1).'-12-31', 'status' => 'closed', 'scale' => 5]);
        $emps = $this->employees(14);
        foreach ($emps as $k => $e) {
            if (DB::table('people_reviews')->where(['cycle_id' => $cycle, 'employee_id' => $e->id])->exists()) {
                continue;
            }
            $status = ['pending', 'self_done', 'manager_done', 'calibrated'][$k % 4];
            $self = mt_rand(3, 5);
            $mgr = max(2, min(5, $self + mt_rand(-1, 1)));
            $this->ins('people_reviews', ['cycle_id' => $cycle, 'employee_id' => $e->id, 'department_id' => $e->department_id, 'status' => $status,
                'self_rating' => $status !== 'pending' ? $self : null, 'self_comment' => $status !== 'pending' ? 'Objectifs atteints, souhait de formation en management.' : null, 'self_submitted_at' => $status !== 'pending' ? now()->subDays(9) : null,
                'manager_rating' => in_array($status, ['manager_done', 'calibrated'], true) ? $mgr : null, 'manager_comment' => in_array($status, ['manager_done', 'calibrated'], true) ? 'Bon engagement et ponctualité.' : null,
                'manager_submitted_at' => in_array($status, ['manager_done', 'calibrated'], true) ? now()->subDays(4) : null, 'calibrated_rating' => $status === 'calibrated' ? $mgr : null]);
        }

        return 'reviews='.count($emps);
    }

    // ---------------------------------------------------------------- guest-facing / revenue

    private function payments(): ?string
    {
        // Credentials are an encrypted cast: always go through the model (also repairs rows written as plain JSON).
        DB::table('payment_gateways')->where('hotel_id', $this->hotel->id)->delete();
        foreach (['mtn_momo', 'orange_money'] as $p) {
            \App\Modules\Payments\Domain\PaymentGatewaySetting::create(['provider' => $p, 'enabled' => true, 'mode' => 'sandbox', 'credentials' => ['demo' => true]]);
        }
        if ($this->has('payment_intents')) {
            return 'skip';
        }
        $res = DB::table('reservations')->where('hotel_id', $this->hotel->id)->whereIn('status', ['confirmed', 'checked_in'])->orderByDesc('id')->limit(8)->get(['id', 'guest_id']);
        $n = 0;
        foreach ($res as $i => $r) {
            $amount = $this->pick([75_000, 120_000, 250_000, 45_000]);
            $status = ['paid', 'pending', 'paid', 'expired', 'pending'][$i % 5];
            $code = strtoupper(substr(md5($this->hotel->id.'-'.$r->id), 0, 10));
            $link = DB::table('payment_links')->where('code', $code)->value('id') ?? $this->ins('payment_links', ['code' => $code, 'target_type' => 'reservation', 'target_id' => $r->id, 'label' => 'Acompte de réservation', 'amount' => $amount, 'amount_paid' => $status === 'paid' ? $amount : 0,
                'currency' => 'XAF', 'status' => $status, 'created_by' => $this->actor?->id, 'expires_at' => $status === 'expired' ? now()->subDays(2) : now()->addDays(5), 'paid_at' => $status === 'paid' ? now()->subDays(mt_rand(0, 3)) : null, 'payer_phone' => '+237 677 '.mt_rand(10, 99).' '.mt_rand(10, 99).' '.mt_rand(10, 99)]);
            $this->ins('payment_intents', ['reference' => (string) \Illuminate\Support\Str::uuid(), 'provider' => $i % 2 ? 'orange_money' : 'mtn_momo', 'driver' => 'sandbox', 'amount' => $amount, 'currency' => 'XAF', 'reservation_id' => $r->id,
                'payer_phone' => '+237 699 '.mt_rand(10, 99).' '.mt_rand(10, 99).' '.mt_rand(10, 99), 'status' => $status === 'paid' ? 'succeeded' : ($status === 'expired' ? 'failed' : 'pending'), 'provider_reference' => 'TX'.mt_rand(100000, 999999), 'failure_reason' => $status === 'expired' ? 'Délai dépassé' : null,
                'channel' => 'link', 'created_by' => $this->actor?->id, 'expires_at' => now()->addDay(), 'completed_at' => $status === 'paid' ? now()->subDays(1) : null, 'payment_link_id' => $link]);
            $n++;
        }

        return "links=$n";
    }

    private function guestFacing(): ?string
    {
        $out = [];
        $res = DB::table('reservations')->where('hotel_id', $this->hotel->id)->where('status', 'checked_out')->orderByDesc('id')->limit(10)->get(['id', 'guest_id', 'room_id', 'checked_out_at']);
        if (! $this->has('guest_reviews')) {
            $comments = ['Très bon séjour, personnel accueillant.', 'Chambre propre et calme, petit-déjeuner excellent.', 'Service un peu lent au restaurant mais cadre agréable.', 'Great stay, will come back!', 'Wi-Fi instable pendant mon séjour.', 'Excellent rapport qualité-prix à Douala.', 'Climatisation bruyante la nuit.'];
            foreach ($res as $i => $r) {
                $this->ins('guest_reviews', ['reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'rating' => [5, 4, 4, 5, 3, 5, 2, 4, 5, 4][$i % 10], 'comment' => $comments[$i % count($comments)], 'submitted_at' => now()->subDays($i + 1)]);
            }
            $out[] = 'reviews='.count($res);
        }
        if (! $this->has('lost_found_items')) {
            foreach ([['Chargeur de téléphone Samsung', 'Chambre', 'found'], ['Lunettes de vue noires', 'Restaurant', 'returned'], ['Parapluie bleu', 'Hall', 'found'], ['Montre homme Casio', 'Piscine', 'found'], ['Sac à main en cuir marron', 'Bar', 'disposed']] as $i => [$d, $loc, $st]) {
                $this->ins('lost_found_items', ['description' => $d, 'location' => $loc, 'found_on' => now()->subDays(3 + $i * 4)->toDateString(), 'found_by' => $this->actor?->id, 'status' => $st, 'returned_to' => $st === 'returned' ? 'M. '.$this->pick(self::LAST) : null,
                    'returned_at' => $st === 'returned' ? now()->subDays(2) : null, 'disposal_reason' => $st === 'disposed' ? 'Non réclamé après 90 jours' : null, 'disposed_at' => $st === 'disposed' ? now()->subDays(5) : null]);
            }
            $out[] = 'lost_found=5';
        }
        if (! $this->has('walk_records') && $res->count() > 3 && $this->full) {
            foreach ($res->take(2) as $r) {
                $this->ins('walk_records', ['reservation_id' => $r->id, 'partner_hotel' => $this->pick(['Hôtel Sawa Douala', 'Pullman Douala Rabingha', 'Hôtel Franco']), 'compensation' => 30_000, 'transport_paid' => true, 'note' => 'Surbooking : client relogé avec transport offert.', 'walked_by' => $this->actor?->id]);
            }
            $out[] = 'walks=2';
        }
        if (! $this->has('minibar_pars')) {
            $types = DB::table('room_types')->where('hotel_id', $this->hotel->id)->pluck('id');
            $items = DB::table('stock_items')->where('hotel_id', $this->hotel->id)->where('category', 'beverage')->limit(2)->pluck('id');
            foreach ($types as $t) {
                foreach ($items as $it) {
                    $this->ins('minibar_pars', ['room_type_id' => $t, 'stock_item_id' => $it, 'par_qty' => 4, 'price' => 1_500]);
                }
            }
            $out[] = 'minibar_pars='.($types->count() * $items->count());
        }

        return implode(' ', $out) ?: 'skip';
    }

    private function revenue(): ?string
    {
        $out = [];
        $types = DB::table('room_types')->where('hotel_id', $this->hotel->id)->orderBy('id')->get(['id', 'base_rate']);
        if (! $this->has('competitor_rates') && $types->isNotEmpty()) {
            foreach (['Hôtel Sawa', 'Pullman Rabingha', 'Hôtel Franco', 'Ibis Douala'] as $ci => $comp) {
                for ($d = 0; $d < 14; $d++) {
                    $t = $types[$ci % $types->count()];
                    $this->ins('competitor_rates', ['competitor' => $comp, 'date' => now()->addDays($d)->toDateString(), 'room_type_id' => $t->id, 'rate' => (int) (round($t->base_rate * (0.85 + mt_rand(0, 40) / 100) / 500) * 500), 'entered_by' => $this->actor?->id]);
                }
            }
            $out[] = 'competitor=56';
        }

        return implode(' ', $out) ?: 'skip';
    }

    private function loyaltyAndGroups(): ?string
    {
        $out = [];
        if (! $this->hasGroup('loyalty_promotions') && $this->full) {
            $this->ins('loyalty_promotions', ['name' => 'Points x2 week-end', 'kind' => 'multiplier', 'multiplier' => 2, 'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->endOfMonth()->toDateString(), 'active' => true], false);
            $this->ins('loyalty_promotions', ['name' => 'Bonus de bienvenue membre', 'kind' => 'bonus', 'bonus_points' => 500, 'starts_on' => now()->subMonths(1)->toDateString(), 'ends_on' => now()->addMonths(2)->toDateString(), 'active' => true], false);
            $out[] = 'promos=2';
        }
        $co = DB::table('companies')->orderBy('id')->limit(3)->pluck('id');
        $plan = DB::table('rate_plans')->where('hotel_id', $this->hotel->id)->where('code', 'CORP')->value('id');
        if (! $this->has('corporate_contracts') && $plan) {
            foreach ($co as $i => $c) {
                $this->ins('corporate_contracts', ['company_id' => $c, 'rate_plan_id' => $plan, 'code' => 'CTR-'.now()->format('Y').'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'name' => 'Contrat cadre entreprise '.($i + 1), 'valid_from' => now()->startOfYear()->toDateString(), 'valid_to' => now()->endOfYear()->toDateString(), 'last_room_availability' => $i === 0, 'active' => true, 'notes' => 'Tarif négocié, paiement à 30 jours.']);
            }
            $out[] = 'contracts='.$co->count();
        }
        if (! $this->has('room_blocks')) {
            $this->ins('room_blocks', ['code' => 'GRP-MARIAGE', 'name' => 'Mariage Mballa-Nkodo', 'company_id' => $co[0] ?? null, 'rate_plan_id' => $plan, 'arrival' => now()->addDays(20)->toDateString(), 'departure' => now()->addDays(23)->toDateString(), 'cutoff_date' => now()->addDays(10)->toDateString(), 'status' => 'tentative', 'notes' => '15 chambres pour la famille des mariés']);
            $this->ins('room_blocks', ['code' => 'GRP-SEMINAIRE', 'name' => 'Séminaire annuel Société Générale', 'company_id' => $co[1] ?? null, 'rate_plan_id' => $plan, 'arrival' => now()->addDays(35)->toDateString(), 'departure' => now()->addDays(37)->toDateString(), 'cutoff_date' => now()->addDays(21)->toDateString(), 'status' => 'definite']);
            $out[] = 'blocks=2';
        }

        return implode(' ', $out) ?: 'skip';
    }
}
