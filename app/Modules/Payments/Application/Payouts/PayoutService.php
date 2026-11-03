<?php

namespace App\Modules\Payments\Application\Payouts;

use App\Models\User;
use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\Payments\Domain\PaymentGatewaySetting;
use App\Modules\Payments\Domain\PayoutItem;
use App\Modules\Payments\Domain\PayoutRun;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Salary payout of an approved payroll run: one item per payslip, paid by
 * MTN / Orange bulk disbursement (dry run without credentials), bank transfer
 * file (CSV, confirmed when the bank executes it) or cash (confirmed by the cashier).
 * Each batch of newly paid items posts Dr 422100 salaries payable / Cr 521500 MoMo,
 * 521100 bank or 571100 cash. When every item is paid the payroll run is marked paid
 * (so HR's markPaid, which would post the same payment, can no longer run).
 */
class PayoutService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private Ledger $ledger, private EventBus $bus, private TenantContext $ctx) {}

    public function create(PayrollRun $payroll, ?User $actor = null): PayoutRun
    {
        if ($payroll->status !== 'approved') {
            throw new DomainException('Only an approved, unpaid payroll can be paid out.', 'invalid-state');
        }
        if (PayoutRun::where('payroll_run_id', $payroll->id)->exists()) {
            throw new DomainException("A payout already exists for payroll {$payroll->period}.", 'duplicate');
        }

        return DB::transaction(function () use ($payroll, $actor) {
            $run = PayoutRun::create(['payroll_run_id' => $payroll->id, 'period' => $payroll->period, 'created_by' => $actor?->id]);
            $total = 0;
            foreach ($payroll->payslips()->with('employee')->orderBy('id')->get() as $slip) {
                if ($slip->net <= 0) {
                    continue;
                }
                $e = $slip->employee;
                [$channel, $dest] = match ($e->payment_method) {
                    'mobile_money' => [self::operator($e->momo_number), $e->momo_number],
                    'cash' => ['cash', null],
                    default => ['bank', $e->bank_account],
                };
                $run->items()->create([
                    'employee_id' => $e->id, 'payslip_id' => $slip->id, 'channel' => $channel, 'destination' => $dest,
                    'bank_name' => $channel === 'bank' ? $e->bank_name : null, 'employee_name' => $e->full_name,
                    'amount' => $slip->net, 'reference' => "SAL-{$payroll->period}-{$run->id}-{$slip->id}",
                    'status' => in_array($channel, ['mtn_momo', 'orange_money'], true) && ! $dest ? 'failed' : 'pending',
                    'error' => in_array($channel, ['mtn_momo', 'orange_money'], true) && ! $dest ? 'No Mobile Money number on file' : null,
                ]);
                $total += $slip->net;
            }
            $run->update(['total' => $total]);
            $this->bus->record('payout.run_created', ['id' => $run->id, 'payroll_run_id' => $payroll->id, 'total' => $total], 'payout_run', $run->id);

            return $run->fresh('items');
        });
    }

    /** Cameroon numbering: Orange 69x and 655-659; MTN 67x, 68x and 650-654. */
    public static function operator(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        $d = strlen($d) > 9 ? substr($d, -9) : $d;

        return preg_match('/^(69|65[5-9])/', $d) ? 'orange_money' : 'mtn_momo';
    }

    public function driverFor(string $provider): array
    {
        $setting = PaymentGatewaySetting::where('provider', $provider)->first();
        if (! $setting?->hasCredentials()) {
            if (app()->isProduction()) {
                throw new DomainException('Mobile Money disbursement is not configured for the hotel.', 'gateway-not-configured');
            }

            return [new DryRunDisbursement, []];
        }

        return [$provider === 'orange_money' ? new OrangeDisbursement : new MtnDisbursement, $setting->credentials];
    }

    /** Send pending / retryable Mobile Money items, re-check sent ones, then post what got paid. */
    public function process(PayoutRun $run, bool $retryFailed = false): PayoutRun
    {
        $currency = Hotel::findOrFail($this->ctx->requireHotelId())->currency ?? 'XAF';
        $items = $run->items()->whereIn('channel', ['mtn_momo', 'orange_money'])
            ->whereIn('status', $retryFailed ? ['pending', 'sent', 'failed'] : ['pending', 'sent'])->get();
        foreach ($items as $item) {
            [$driver, $creds] = $this->driverFor($item->channel);
            if ($item->status === 'sent') {
                $status = $item->driver === 'dry_run' ? (new DryRunDisbursement)->status($item, []) : $driver->status($item, $creds);
                $item->update(['status' => $status, 'paid_at' => $status === 'succeeded' ? now() : null]);

                continue;
            }
            if (! $item->destination || $item->attempts >= self::MAX_ATTEMPTS) {
                continue;
            }
            $res = $driver->disburse($item, $currency, $creds);
            $item->update([
                'status' => $res['status'], 'attempts' => $item->attempts + 1, 'driver' => $driver->name(),
                'provider_reference' => $res['provider_reference'] ?? null, 'error' => $res['reason'] ?? null,
                'paid_at' => $res['status'] === 'succeeded' ? now() : null,
            ]);
        }
        $run->update(['status' => 'processing']);

        return $this->postPaid($run);
    }

    /** Bank transfer file (CSV, ; separated, UTF-8 BOM) for the bank items; marks them exported. */
    public function bankFile(PayoutRun $run): string
    {
        $items = $run->items()->where('channel', 'bank')->whereIn('status', ['pending', 'exported'])->get();
        $rows = [['reference', 'beneficiaire', 'banque', 'compte', 'montant', 'devise', 'motif']];
        $currency = Hotel::findOrFail($this->ctx->requireHotelId())->currency ?? 'XAF';
        foreach ($items as $i) {
            $rows[] = [$i->reference, $i->employee_name, $i->bank_name, $i->destination, $i->amount, $currency, "Salaire {$run->period}"];
        }
        $run->items()->whereIn('id', $items->pluck('id'))->update(['status' => 'exported']);
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($out, $r, ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /** Bank executed the file / cashier paid: confirm bank or cash items (all when $ids is empty). */
    public function confirmManual(PayoutRun $run, array $ids = []): PayoutRun
    {
        $run->items()->whereIn('channel', ['bank', 'cash'])->whereIn('status', ['pending', 'exported'])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))->update(['status' => 'succeeded', 'paid_at' => now()]);
        $run->update(['status' => 'processing']);

        return $this->postPaid($run);
    }

    /** Mark items failed on the bank's rejection list. */
    public function rejectManual(PayoutRun $run, array $ids, string $reason): PayoutRun
    {
        $run->items()->whereIn('channel', ['bank', 'cash'])->whereIn('status', ['pending', 'exported'])->whereIn('id', $ids)
            ->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 250)]);

        return $run->fresh('items');
    }

    /** Fix a destination after a failure and re-queue the item. */
    public function updateDestination(PayoutRun $run, int $itemId, string $destination): PayoutItem
    {
        $item = $run->items()->whereKey($itemId)->firstOrFail();
        if ($item->status !== 'failed') {
            throw new DomainException('Only a failed item can be changed.');
        }
        $item->update(['destination' => $destination, 'status' => 'pending', 'attempts' => 0, 'error' => null,
            'channel' => $item->channel === 'bank' || $item->channel === 'cash' ? $item->channel : self::operator($destination)]);

        return $item;
    }

    /** Post newly paid items: one balanced entry per treasury account. */
    private function postPaid(PayoutRun $run): PayoutRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayoutRun::lockForUpdate()->findOrFail($run->id);
            $date = Hotel::findOrFail($run->hotel_id)->business_date ?? now();
            $items = $run->items()->where('status', 'succeeded')->whereNull('journal_entry_id')->get();
            foreach ($items->groupBy(fn ($i) => match ($i->channel) {
                'bank' => C::role('bank'), 'cash' => C::role('cash'), default => C::role('mobile_money'),
            }) as $account => $group) {
                $sum = (int) $group->sum('amount');
                $journal = match ($account) {
                    C::role('bank') => 'BQ', C::role('cash') => 'CA', default => 'MM',
                };
                $entry = $this->ledger->post($journal, $date, "Paiement salaires {$run->period} (".$group->count().')', [
                    [C::role('wages_payable'), $sum],
                    [$account, -$sum],
                ], ['source_type' => 'payout_run', 'source_id' => $run->id]);
                PayoutItem::whereIn('id', $group->pluck('id'))->update(['journal_entry_id' => $entry?->id]);
            }
            $paid = (int) $run->items()->where('status', 'succeeded')->sum('amount');
            $open = $run->items()->where('status', '!=', 'succeeded')->exists();
            $run->update(['paid' => $paid, 'status' => $open ? 'processing' : 'completed']);
            if (! $open) {
                PayrollRun::whereKey($run->payroll_run_id)->where('status', 'approved')->update(['status' => 'paid', 'paid_at' => now()]);
                $this->bus->record('payout.run_completed', ['id' => $run->id, 'payroll_run_id' => $run->payroll_run_id, 'paid' => $paid], 'payout_run', $run->id);
            }

            return $run->fresh('items');
        });
    }
}
