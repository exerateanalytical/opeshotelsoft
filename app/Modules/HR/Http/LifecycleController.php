<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Application\Lifecycle\ContractTemplates;
use App\Modules\HR\Application\Lifecycle\EContractService;
use App\Modules\HR\Application\Lifecycle\LifecycleService;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\CompensationChange;
use App\Modules\HR\Domain\Lifecycle\ContractTemplate;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Domain\Lifecycle\PayElement;
use App\Modules\HR\Domain\Lifecycle\SalaryHistory;
use App\Modules\HR\Domain\Lifecycle\ServiceChargePool;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Q2 employment lifecycle API: e-contracts, pay changes, pay elements, service-charge pools, transfers. */
class LifecycleController
{
    private const PERIOD = ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(private EContractService $contracts, private LifecycleService $life) {}

    // ---- Templates ----

    public function templates(Request $request)
    {
        $this->can($request, 'view');
        $custom = ContractTemplate::get()->keyBy(fn ($t) => "$t->kind|$t->locale");
        $out = [];
        foreach (ContractTemplates::KINDS as $kind) {
            foreach (['fr', 'en'] as $locale) {
                $c = $custom["$kind|$locale"] ?? null;
                $out[] = ['kind' => $kind, 'locale' => $locale, 'custom' => (bool) $c, 'active' => $c?->active ?? true] + ($c ? ['title' => $c->title, 'body' => $c->body] : ContractTemplates::default($kind, $locale));
            }
        }

        return ['templates' => $out, 'placeholders' => ContractTemplates::PLACEHOLDERS];
    }

    public function saveTemplate(Request $request)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'kind' => ['required', Rule::in(ContractTemplates::KINDS)], 'locale' => ['required', Rule::in(['fr', 'en'])],
            'title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:50000'], 'active' => ['sometimes', 'boolean'],
        ]);

        return ContractTemplate::updateOrCreate(['kind' => $d['kind'], 'locale' => $d['locale']], $d);
    }

    // ---- E-contracts ----

    public function econtracts(Request $request)
    {
        $this->can($request, 'view');

        return EContract::with('employee:id,number,first_name,last_name')
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function generate(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'kind' => ['nullable', Rule::in(ContractTemplates::KINDS)], 'contract_id' => ['nullable', 'integer'], 'locale' => ['nullable', Rule::in(['fr', 'en'])],
        ]);

        return response()->json($this->contracts->generate($employee, $d['kind'] ?? null, $d['contract_id'] ?? null, $d['locale'] ?? null), 201);
    }

    public function showEContract(Request $request, EContract $econtract)
    {
        $this->can($request, 'view');

        return $econtract->load('employee:id,number,first_name,last_name,phone,email', 'audit')
            ->setAttribute('has_employee_signature', (bool) $econtract->employee_signature);
    }

    public function updateEContract(Request $request, EContract $econtract)
    {
        $this->can($request, 'manage');
        $d = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'body' => ['sometimes', 'string', 'max:50000']]);
        abort_unless($econtract->status === 'draft', 422, 'Only drafts can be edited.');
        $econtract->update($d);
        $this->contracts->audit($econtract, 'edited', $request->ip(), $request->userAgent());

        return $econtract;
    }

    public function send(Request $request, EContract $econtract)
    {
        $this->can($request, 'manage');

        return $this->contracts->send($econtract);
    }

    public function countersign(Request $request, EContract $econtract)
    {
        $this->can($request, 'approve');
        $d = $request->validate(['signature' => ['required', 'string']]);

        return $this->contracts->countersign($econtract, $d['signature'], $request->ip(), $request->userAgent());
    }

    public function cancel(Request $request, EContract $econtract)
    {
        $this->can($request, 'manage');

        return $this->contracts->cancel($econtract);
    }

    public function pdf(Request $request, EContract $econtract)
    {
        $this->can($request, 'view');

        return response($this->contracts->pdf($econtract), 200, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="'.preg_replace('/[^\w.-]/', '_', $econtract->number).'.pdf"',
        ]);
    }

    // ---- Raises & promotions ----

    public function changes(Request $request)
    {
        $this->can($request, 'view');

        return CompensationChange::with('employee:id,number,first_name,last_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function requestChange(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'type' => ['required', Rule::in(['raise', 'promotion'])], 'to_salary' => ['required', 'integer', 'min:0'],
            'to_position_id' => ['nullable', 'integer'], 'effective_date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->life->requestChange($employee, $d), 201);
    }

    public function decideChange(Request $request, CompensationChange $change)
    {
        $this->can($request, 'approve');

        return $this->life->decideChange($change, $request->validate(['approve' => ['required', 'boolean']])['approve']);
    }

    public function salaryHistory(Request $request, Employee $employee)
    {
        $this->can($request, 'view');

        return SalaryHistory::where('employee_id', $employee->id)->orderBy('effective_date')->orderBy('id')->get();
    }

    public function applyDue(Request $request)
    {
        $this->can($request, 'approve');

        return ['applied' => $this->life->applyDue()];
    }

    // ---- Bonuses & allowances ----

    public function elements(Request $request)
    {
        $this->can($request, 'view');

        return PayElement::with('employee:id,number,first_name,last_name')
            ->when($request->query('period'), fn ($q, $v) => $q->forPeriod($v))
            ->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->orderByDesc('id')->paginate(100);
    }

    public function addElement(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_]+$/', Rule::notIn(['BASE', 'CNPS', 'IRPP', 'CAC', 'CFC', 'RAV', 'TDL', 'AVANCE', 'base', 'cnps', 'irpp'])],
            'name' => ['required', 'string', 'max:120'], 'kind' => ['required', Rule::in(['one_off', 'recurring'])],
            'amount' => ['required', 'integer', 'min:1'], 'taxable' => ['sometimes', 'boolean'], 'cnps' => ['sometimes', 'boolean'],
            'period' => ['required_if:kind,one_off', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'start_period' => ['required_if:kind,recurring', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'end_period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        return response()->json($this->life->addElement($employee, $d), 201);
    }

    public function cancelElement(Request $request, PayElement $element)
    {
        $this->can($request, 'manage');

        return $this->life->cancelElement($element);
    }

    public function pools(Request $request)
    {
        $this->can($request, 'view');

        return ServiceChargePool::orderByDesc('period')->orderByDesc('id')->paginate(50);
    }

    public function createPool(Request $request)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'period' => self::PERIOD, 'amount' => ['required', 'integer', 'min:1'], 'method' => ['required', Rule::in(['points', 'days'])],
            'entries' => ['required', 'array', 'min:1'], 'entries.*.employee_id' => ['required', 'integer', 'distinct'], 'entries.*.weight' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json($this->life->createPool($d['period'], $d['amount'], $d['method'], $d['entries']), 201);
    }

    public function distributePool(Request $request, ServiceChargePool $pool)
    {
        $this->can($request, 'approve');

        return $this->life->distributePool($pool);
    }

    // ---- Transfers ----

    public function transfers(Request $request)
    {
        $this->can($request, 'view');

        return Transfer::query()->when($request->query('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')->paginate(50);
    }

    public function requestTransfer(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $d = $request->validate([
            'to_hotel_id' => ['required', 'integer'], 'to_department_id' => ['nullable', 'integer'], 'to_position_id' => ['nullable', 'integer'],
            'new_base_salary' => ['nullable', 'integer', 'min:0'], 'effective_date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->life->requestTransfer($employee, $d), 201);
    }

    public function decideTransfer(Request $request, Transfer $transfer)
    {
        $this->can($request, 'approve');

        return $this->life->decideTransfer($transfer, $request->validate(['approve' => ['required', 'boolean']])['approve']);
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        $ok = match ($ability) {
            'approve' => $u->can('hr.lifecycle.approve'),
            'manage' => $u->can('hr.lifecycle.manage') || $u->can('hr.manage'),
            default => $u->can('hr.lifecycle.manage') || $u->can('hr.manage') || $u->can('hr.view') || $u->can('payroll.manage'),
        };
        abort_unless($ok, 403);
    }
}
