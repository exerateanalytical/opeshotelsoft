<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Withholding tax (retenue à la source) on supplier payments. A supplier tax profile names a withholding rate code;
 * ProcurementService::pay asks forPayment() and the payment then settles the invoice in full while the bank pays net and the
 * withheld part is credited to 447100 (state, to remit). A numbered certificate is issued per withholding; remit() posts the
 * payment to the tax office. Rates and bases are configuration: to be validated by the chartered accountant.
 */
class Withholding
{
    use Scoped;

    public const PAYABLE = '447100';

    public function __construct(TenantContext $ctx, private AuditTrail $audit, private Ledger $ledger, private CountryProfiles $profiles)
    {
        $this->ctx = $ctx;
    }

    public function rates(): array
    {
        if (! $this->table('withholding_rates')->exists()) {
            foreach ($this->profiles->profile()['wht'] as $w) {
                DB::table('withholding_rates')->insert($this->tenant() + ['code' => $w['code'], 'name' => $w['name'], 'rate' => $w['rate'], 'base' => $w['base'], 'threshold' => $w['threshold'], ...$this->stamp()]);
            }
        }

        return $this->table('withholding_rates')->orderBy('code')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function saveRate(array $d): array
    {
        $this->rates();
        DB::table('withholding_rates')->updateOrInsert(['hotel_id' => $this->hotelId(), 'code' => $d['code']], [
            'group_id' => $this->ctx->groupId(), 'name' => $d['name'], 'rate' => $d['rate'], 'base' => $d['base'] ?? 'net', 'threshold' => $d['threshold'] ?? 0,
            'active' => $d['active'] ?? true, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->audit->record('wht_rate_saved', 'withholding_rate', $d['code'], $d);

        return (array) $this->table('withholding_rates')->where('code', $d['code'])->first();
    }

    public function saveSupplierProfile(int $supplierId, array $d): array
    {
        $this->rates(); // seeds the country defaults on first use
        if (! empty($d['withholding_code']) && ! $this->table('withholding_rates')->where('code', $d['withholding_code'])->exists()) {
            throw new DomainException('Unknown withholding code.', 'unknown-code');
        }
        DB::table('supplier_tax_profiles')->updateOrInsert(['hotel_id' => $this->hotelId(), 'supplier_id' => $supplierId], [
            'group_id' => $this->ctx->groupId(), 'withholding_code' => $d['withholding_code'] ?? null, 'exempt' => $d['exempt'] ?? false, 'niu' => $d['niu'] ?? null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->audit->record('supplier_tax_profile_saved', 'supplier', $supplierId, $d);

        return (array) $this->table('supplier_tax_profiles')->where('supplier_id', $supplierId)->first();
    }

    /** @return ?array{code:string, rate:float, base:int, amount:int} */
    public function forPayment(SupplierInvoice $inv, int $amount): ?array
    {
        $p = $this->table('supplier_tax_profiles')->where('supplier_id', $inv->supplier_id)->first();
        if (! $p || $p->exempt || ! $p->withholding_code) {
            return null;
        }
        $rate = $this->table('withholding_rates')->where(['code' => $p->withholding_code, 'active' => true])->first();
        if (! $rate) {
            return null;
        }
        $base = $rate->base === 'gross' || $inv->total <= 0 ? $amount : (int) round($amount * $inv->net_total / $inv->total);
        if ($base < $rate->threshold) {
            return null;
        }
        $wht = min((int) round($base * $rate->rate / 100), $amount - 1);
        if ($wht <= 0) {
            return null;
        }
        $this->ensureAccounts([self::PAYABLE => ['État - retenues à la source sur fournisseurs', 4, 'liability']]);

        return ['code' => $rate->code, 'rate' => (float) $rate->rate, 'base' => $base, 'amount' => $wht];
    }

    /** Numbered certificate for one withholding. */
    public function issue(int $supplierId, int $paymentId, string $date, array $w): array
    {
        $supplier = DB::table('suppliers')->find($supplierId);
        $niu = $this->table('supplier_tax_profiles')->where('supplier_id', $supplierId)->value('niu') ?? ($supplier->tax_id ?? null);
        $year = substr($date, 0, 4);
        $n = $this->table('withholding_certificates')->where('number', 'like', "RAS-$year-%")->count() + 1;
        $number = sprintf('RAS-%s-%04d', $year, $n);
        $data = ['number' => $number, 'supplier_id' => $supplierId, 'payment' => $paymentId, 'date' => $date, 'code' => $w['code'], 'rate' => $w['rate'], 'base' => $w['base'], 'amount' => $w['amount']];
        $id = DB::table('withholding_certificates')->insertGetId($this->tenant() + [
            'number' => $number, 'supplier_id' => $supplierId, 'supplier_payment_id' => $paymentId, 'date' => $date, 'code' => $w['code'], 'rate' => $w['rate'], 'base' => $w['base'], 'amount' => $w['amount'],
            'supplier_name' => $supplier->name ?? '', 'supplier_niu' => $niu, 'hash' => hash('sha256', json_encode($data)), ...$this->stamp(),
        ]);
        $this->audit->record('wht_certificate_issued', 'withholding_certificate', $id, $data);

        return (array) DB::table('withholding_certificates')->find($id);
    }

    public function certificates(?string $status = null): array
    {
        return $this->table('withholding_certificates')->when($status, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function certificate(int $id): array
    {
        $c = $this->table('withholding_certificates')->where('id', $id)->first() ?? throw new DomainException('Certificate not found.', 'not-found');
        $hotel = DB::table('hotels')->find($this->hotelId());

        return (array) $c + ['payer' => ['name' => $hotel->name ?? '', 'tax_id' => $hotel->tax_id ?? null], 'document' => 'certificate_of_withholding'];
    }

    /** Pay the withheld amounts to the tax office (Dr 447100 / Cr bank). */
    public function remit(array $ids, string $date, string $account, ?string $reference): array
    {
        $certs = $this->table('withholding_certificates')->whereIn('id', $ids)->where('status', 'issued')->get();
        if ($certs->isEmpty() || $certs->count() !== count(array_unique($ids))) {
            throw new DomainException('Some certificates are missing or already remitted.', 'bad-selection');
        }
        $total = (int) $certs->sum('amount');

        return DB::transaction(function () use ($certs, $total, $date, $account, $reference) {
            $entry = $this->ledger->post(str_starts_with($account, '57') ? 'CA' : 'BQ', $date, 'Reversement retenues à la source'.($reference ? " $reference" : ''), [
                [self::PAYABLE, $total], [$account, -$total],
            ], ['source_type' => 'wht_remittance', 'source_id' => $certs->first()->id]);
            DB::table('withholding_certificates')->whereIn('id', $certs->pluck('id'))->update(['status' => 'remitted', 'remitted_on' => $date, 'remittance_reference' => $reference, 'updated_at' => now()]);
            $this->audit->record('wht_remitted', 'withholding_certificate', $certs->first()->id, ['ids' => $certs->pluck('id'), 'total' => $total, 'entry' => $entry->id]);

            return ['total' => $total, 'certificates' => $certs->count(), 'entry_id' => $entry->id];
        });
    }
}
