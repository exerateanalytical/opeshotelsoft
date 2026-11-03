<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Application\Payroll\PayslipLabels;
use App\Modules\HR\Application\PayrollService;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\PayrollRun;
use App\Modules\HR\Domain\Payslip;
use App\Modules\HR\Domain\RulePack;
use Illuminate\Http\Request;

class PayrollController
{
    public function __construct(private PayrollService $payroll) {}

    public function runs(Request $request)
    {
        $this->can($request, 'view');

        return PayrollRun::orderByDesc('period')->paginate(24);
    }

    public function show(Request $request, PayrollRun $run)
    {
        $this->can($request, 'view');

        return $run->load('payslips.employee:id,number,first_name,last_name');
    }

    public function compute(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'inputs' => ['sometimes', 'array'],
            'inputs.*.bonuses' => ['sometimes', 'integer', 'min:0'],
            'inputs.*.service_charge' => ['sometimes', 'integer', 'min:0'],
            'inputs.*.overtime_hours' => ['sometimes', 'numeric', 'min:0', 'max:200'],
        ]);

        return $this->payroll->compute($data['period'], $data['inputs'] ?? []);
    }

    public function approve(Request $request, PayrollRun $run)
    {
        abort_unless($request->user()->can('payroll.approve'), 403);

        return $this->payroll->approve($run);
    }

    public function pay(Request $request, PayrollRun $run)
    {
        abort_unless($request->user()->can('payroll.approve'), 403);
        $data = $request->validate(['account' => ['required', 'string']]);

        return $this->payroll->markPaid($run, $data['account']);
    }

    public function paymentFile(Request $request, PayrollRun $run)
    {
        $this->can($request, 'manage');

        return ['period' => $run->period, 'rows' => $this->payroll->paymentFile($run)];
    }

    public function declaration(Request $request, PayrollRun $run)
    {
        $this->can($request, 'view');

        return $this->payroll->declaration($run);
    }

    public function payslip(Request $request, Payslip $payslip)
    {
        PayrollRun::findOrFail($payslip->payroll_run_id); // tenant-scoped: 404 outside the user's hotels
        $own = Employee::where('id', $payslip->employee_id)->where('user_id', $request->user()->id)->exists();
        abort_unless($own || $request->user()->can('payroll.view') || $request->user()->can('payroll.manage'), 403);

        $payslip->load('employee');
        $l10n = PayslipLabels::localise($payslip, $payslip->employee->payslip_language ?? 'fr');

        return array_merge($payslip->toArray(), $l10n);
    }

    /** Employee self-service: my payslips. */
    public function mine(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->firstOrFail();
        $lang = $employee->payslip_language ?? 'fr';

        return Payslip::with('run:id,period,status')->where('employee_id', $employee->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->orderByDesc('id')->get()
            ->each(function (Payslip $s) use ($lang) {
                $start = \Carbon\Carbon::createFromFormat('Y-m-d', $s->run->period.'-01')->startOfDay();
                $l10n = PayslipLabels::localise($s, $lang);
                $s->setAttribute('language', $l10n['language'])->setAttribute('labels', $l10n['labels'])->setAttribute('lines', $l10n['lines']);
                $s->run->setAttribute('period_start', $start->toDateString())->setAttribute('period_end', $start->copy()->endOfMonth()->toDateString());
            });
    }

    public function rulePacks(Request $request)
    {
        $this->can($request, 'view');

        return RulePack::orderBy('country')->orderByDesc('effective_from')->get();
    }

    /** Record certification of a rule pack by a qualified accountant. */
    public function certify(Request $request, RulePack $pack)
    {
        abort_unless($request->user()->hasRole(['group-owner', 'finance-director']), 403);
        $data = $request->validate(['certified_by' => ['required', 'string', 'max:150']]);
        $pack->update(['certified' => true, 'certified_by' => $data['certified_by'], 'certified_at' => now()]);

        return $pack;
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('payroll.manage') || ($ability === 'view' && $u->can('payroll.view')), 403);
    }
}
