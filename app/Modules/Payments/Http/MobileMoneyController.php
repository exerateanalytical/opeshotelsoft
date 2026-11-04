<?php

namespace App\Modules\Payments\Http;

use App\Modules\Accounting\Domain\BankAccount;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\Payments\Application\InboundMatcher;
use App\Modules\Payments\Application\PayLinkService;
use App\Modules\Payments\Application\Payouts\PayoutService;
use App\Modules\Payments\Domain\PaymentInbound;
use App\Modules\Payments\Domain\PaymentLink;
use App\Modules\Payments\Domain\PayoutRun;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff API (Q5): pay links + QR, inbound matching review queue, salary payouts. */
class MobileMoneyController
{
    public function __construct(private PayLinkService $links, private InboundMatcher $matcher, private PayoutService $payouts) {}

    private function can(Request $request, string $perm): void
    {
        abort_unless($request->user()?->can($perm), 403);
    }

    // Pay links

    public function links(Request $request)
    {
        $this->can($request, 'payments.links');

        return PaymentLink::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('target_type'), fn ($q, $v) => $q->where('target_type', $v))
            ->when($request->query('target_id'), fn ($q, $v) => $q->where('target_id', $v))
            ->orderByDesc('id')->paginate(50)->through(fn ($l) => $this->links->present($l, false));
    }

    public function storeLink(Request $request)
    {
        $this->can($request, 'payments.links');
        $d = $request->validate([
            'target_type' => ['required', Rule::in(PaymentLink::TARGETS)], 'target_id' => ['required', 'integer'],
            'amount' => ['nullable', 'integer', 'min:1', 'max:100000000'], 'allow_partial' => ['nullable', 'boolean'],
            'payer_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{8,15}$/'], 'valid_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);
        $link = $this->links->create($d['target_type'], $d['target_id'], $d['amount'] ?? null, $d, $request->user());

        return response()->json($this->links->present($link), 201);
    }

    public function showLink(Request $request, int $link)
    {
        $this->can($request, 'payments.links');
        $l = PaymentLink::with('intents:id,payment_link_id,reference,provider,driver,amount,status,payer_phone,created_at,completed_at')->findOrFail($link);

        return $this->links->present($l) + ['intents' => $l->intents];
    }

    public function cancelLink(Request $request, int $link)
    {
        $this->can($request, 'payments.links');

        return $this->links->present($this->links->cancel(PaymentLink::findOrFail($link)), false);
    }

    // Inbound matching

    public function inbound(Request $request)
    {
        $this->can($request, 'payments.reconcile');

        return PaymentInbound::query()->with('link:id,code,label,target_type,target_id')
            ->where('status', $request->query('status', 'review'))->orderByDesc('id')->paginate(50);
    }

    public function scanStatement(Request $request, int $bank)
    {
        $this->can($request, 'payments.reconcile');

        return $this->matcher->scanStatement(BankAccount::findOrFail($bank));
    }

    public function rematch(Request $request, int $inbound)
    {
        $this->can($request, 'payments.reconcile');

        return $this->matcher->match(PaymentInbound::where('status', 'review')->findOrFail($inbound));
    }

    public function postInbound(Request $request, int $inbound)
    {
        $this->can($request, 'payments.reconcile');
        $d = $request->validate(['payment_link_id' => ['required', 'integer']]);

        return $this->matcher->post(PaymentInbound::findOrFail($inbound), PaymentLink::findOrFail($d['payment_link_id']), $request->user());
    }

    public function ignoreInbound(Request $request, int $inbound)
    {
        $this->can($request, 'payments.reconcile');

        return $this->matcher->ignore(PaymentInbound::findOrFail($inbound), $request->user());
    }

    // Salary payouts

    public function payouts(Request $request)
    {
        $this->can($request, 'payments.payouts');

        return [
            'runs' => PayoutRun::orderByDesc('id')->limit(50)->get(),
            'payable' => PayrollRun::where('status', 'approved')->whereNotIn('id', PayoutRun::select('payroll_run_id'))
                ->orderByDesc('period')->get(['id', 'period', 'net', 'status']),
        ];
    }

    public function storePayout(Request $request)
    {
        $this->can($request, 'payments.payouts');
        $d = $request->validate(['payroll_run_id' => ['required', 'integer']]);

        return response()->json($this->payouts->create(PayrollRun::findOrFail($d['payroll_run_id']), $request->user()), 201);
    }

    public function showPayout(Request $request, int $payout)
    {
        $this->can($request, 'payments.payouts');

        return PayoutRun::with('items')->findOrFail($payout);
    }

    public function processPayout(Request $request, int $payout)
    {
        $this->can($request, 'payments.payouts');

        return $this->payouts->process(PayoutRun::findOrFail($payout), $request->boolean('retry_failed'));
    }

    public function bankFile(Request $request, int $payout)
    {
        $this->can($request, 'payments.payouts');
        $run = PayoutRun::findOrFail($payout);

        return response($this->payouts->bankFile($run), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"virements-salaires-{$run->period}.csv\"",
        ]);
    }

    public function confirmPayout(Request $request, int $payout)
    {
        $this->can($request, 'payments.payouts');
        $d = $request->validate(['item_ids' => ['nullable', 'array'], 'item_ids.*' => ['integer']]);

        return $this->payouts->confirmManual(PayoutRun::findOrFail($payout), $d['item_ids'] ?? []);
    }

    public function rejectPayoutItems(Request $request, int $payout)
    {
        $this->can($request, 'payments.payouts');
        $d = $request->validate(['item_ids' => ['required', 'array'], 'item_ids.*' => ['integer'], 'reason' => ['required', 'string', 'max:250']]);

        return $this->payouts->rejectManual(PayoutRun::findOrFail($payout), $d['item_ids'], $d['reason']);
    }

    public function updatePayoutItem(Request $request, int $payout, int $item)
    {
        $this->can($request, 'payments.payouts');
        $d = $request->validate(['destination' => ['required', 'string', 'max:60']]);

        return $this->payouts->updateDestination(PayoutRun::findOrFail($payout), $item, $d['destination'], $request->user());
    }
}
