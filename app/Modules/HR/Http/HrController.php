<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Application\HrService;
use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\LeaveType;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Domain\SalaryAdvance;
use App\Modules\HR\Domain\Shift;
use App\Platform\Domain\DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrController
{
    public function __construct(private HrService $hr) {}

    public function departments(Request $request)
    {
        $this->can($request, 'view');

        return Department::orderBy('name')->get();
    }

    public function saveDepartment(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:12'], 'name' => ['required', 'string'], 'cost_center' => ['nullable', 'string']]);

        return Department::updateOrCreate(['code' => $data['code']], $data);
    }

    public function positions(Request $request)
    {
        $this->can($request, 'view');

        return Position::orderBy('title')->get();
    }

    public function savePosition(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['department_id' => ['required', 'integer'], 'title' => ['required', 'string'], 'category' => ['nullable', 'string']]);

        return response()->json(Position::create($data), 201);
    }

    public function employees(Request $request)
    {
        $this->can($request, 'view');

        return Employee::with('department:id,name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('department_id'), fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('last_name', 'ilike', "%$v%")->orWhere('first_name', 'ilike', "%$v%")->orWhere('number', 'ilike', "%$v%")))
            ->orderBy('last_name')->paginate(50);
    }

    public function showEmployee(Request $request, Employee $employee)
    {
        $this->can($request, 'view');

        return $employee->load('contracts', 'department')->setAttribute('leave_balance', $this->hr->leaveBalance($employee));
    }

    public function hire(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'first_name' => ['required', 'string'], 'last_name' => ['required', 'string'],
            'gender' => ['nullable', 'in:M,F'], 'date_of_birth' => ['nullable', 'date'], 'nationality' => ['sometimes', 'string', 'size:2'],
            'id_number' => ['nullable', 'string'], 'cnps_number' => ['nullable', 'string'], 'tax_number' => ['nullable', 'string'],
            'marital_status' => ['nullable', 'string'], 'children' => ['sometimes', 'integer', 'between:0,20'],
            'phone' => ['nullable', 'string'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string'],
            'payment_method' => ['sometimes', Rule::in(['bank', 'mobile_money', 'cash'])], 'bank_name' => ['nullable', 'string'],
            'bank_account' => ['nullable', 'string'], 'momo_number' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'], 'position_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'reports_to' => ['nullable', 'integer'], 'payslip_language' => ['sometimes', Rule::in(['fr', 'en'])],
            'hire_date' => ['required', 'date'],
            'allowances' => ['sometimes', 'array'], 'allowances.*.code' => ['required', 'string'], 'allowances.*.name' => ['required', 'string'],
            'allowances.*.amount' => ['required', 'integer', 'min:0'], 'allowances.*.taxable' => ['sometimes', 'boolean'], 'allowances.*.cnps' => ['sometimes', 'boolean'],
            'contract' => ['required', 'array'], 'contract.kind' => ['required', Rule::in(['CDI', 'CDD', 'stage', 'journalier'])],
            'contract.start_date' => ['required', 'date'], 'contract.end_date' => ['nullable', 'date', 'required_if:contract.kind,CDD'],
            'contract.probation_end' => ['nullable', 'date'], 'contract.base_salary' => ['required', 'integer', 'min:0'],
            'contract.hours_per_week' => ['sometimes', 'integer', 'between:1,60'],
        ]);
        $this->hr->assertNoCycle(null, $data['reports_to'] ?? null);

        return response()->json($this->hr->hire($data), 201);
    }

    public function updateEmployee(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'phone' => ['nullable', 'string'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'], 'position_id' => ['nullable', 'integer'],
            'base_salary' => ['sometimes', 'integer', 'min:0'], 'allowances' => ['sometimes', 'array'],
            'payment_method' => ['sometimes', 'string'], 'bank_account' => ['nullable', 'string'], 'momo_number' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['active', 'on_leave', 'suspended'])], 'cnps_number' => ['nullable', 'string'], 'tax_number' => ['nullable', 'string'],
            'reports_to' => ['nullable', 'integer'], 'payslip_language' => ['sometimes', Rule::in(['fr', 'en'])],
        ]);
        if (array_key_exists('reports_to', $data)) {
            $this->hr->assertNoCycle($employee, $data['reports_to']);
        }

        return $this->hr->updateEmployee($employee, $data);
    }

    public function terminate(Request $request, Employee $employee)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string']]);

        return $this->hr->terminate($employee, $data['date'], $data['reason']);
    }

    public function shifts(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);

        return Shift::with('employee:id,number,first_name,last_name')->whereBetween('date', [$data['from'], $data['to']])->orderBy('date')->orderBy('starts_at')->get();
    }

    public function saveShifts(Request $request)
    {
        $this->can($request, 'manage');
        $data = $request->validate([
            'shifts' => ['required', 'array'], 'shifts.*.employee_id' => ['required', 'integer'], 'shifts.*.date' => ['required', 'date'],
            'shifts.*.starts_at' => ['required', 'date_format:H:i'], 'shifts.*.ends_at' => ['required', 'date_format:H:i'], 'shifts.*.role' => ['nullable', 'string'],
        ]);

        return collect($data['shifts'])->map(fn ($s) => Shift::updateOrCreate(['employee_id' => $s['employee_id'], 'date' => $s['date'], 'starts_at' => $s['starts_at']], $s));
    }

    /** Self-service clock in/out for the logged-in employee. */
    public function clock(Request $request, string $direction)
    {
        $employee = $this->selfEmployee($request);

        return $direction === 'in' ? $this->hr->clockIn($employee) : $this->hr->clockOut($employee);
    }

    public function leaveTypes(Request $request)
    {
        $this->hr->seedLeaveTypes();

        return LeaveType::orderBy('name')->get();
    }

    public function leaveRequests(Request $request)
    {
        $this->can($request, 'view');

        return LeaveRequest::with('employee:id,number,first_name,last_name')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->paginate(50);
    }

    public function requestLeave(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer'], 'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'reason' => ['nullable', 'string'],
        ]);
        $employee = isset($data['employee_id']) && $request->user()->can('hr.manage')
            ? Employee::findOrFail($data['employee_id'])
            : $this->selfEmployee($request);

        return response()->json($this->hr->requestLeave($employee, $data['leave_type_id'], $data['start_date'], $data['end_date'], $data['reason'] ?? null), 201);
    }

    public function decideLeave(Request $request, LeaveRequest $leave)
    {
        $this->can($request, 'manage');
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        return $this->hr->decideLeave($leave, $data['approve']);
    }

    public function grantAdvance(Request $request, Employee $employee)
    {
        abort_unless($request->user()->can('payroll.manage'), 403);
        $data = $request->validate(['kind' => ['sometimes', 'in:advance,loan'], 'amount' => ['required', 'integer', 'min:1'], 'monthly_deduction' => ['required', 'integer', 'min:1']]);

        return response()->json(SalaryAdvance::create($data + ['employee_id' => $employee->id, 'balance' => $data['amount'], 'granted_on' => now()->toDateString()]), 201);
    }

    /** Salary advances and loans of one employee, with their remaining balance. */
    public function advances(Request $request, Employee $employee)
    {
        abort_unless($request->user()->can('payroll.manage') || $request->user()->can('hr.manage') || $request->user()->can('hr.view'), 403);

        return SalaryAdvance::where('employee_id', $employee->id)->orderByDesc('granted_on')->orderByDesc('id')->get();
    }

    public function attendance(Request $request)
    {
        $this->can($request, 'view');
        $data = $request->validate(['employee_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return Attendance::with('employee:id,number,first_name,last_name')
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->orderByDesc('clock_in')->paginate(50);
    }

    /** Current user's clock state. */
    public function clockStatus(Request $request)
    {
        $employee = $this->selfEmployee($request);
        $open = Attendance::where('employee_id', $employee->id)->whereNull('clock_out')->latest('clock_in')->first();

        return ['employee_id' => $employee->id, 'clocked_in' => (bool) $open, 'since' => $open?->clock_in, 'attendance_id' => $open?->id];
    }

    private function selfEmployee(Request $request): Employee
    {
        return Employee::where('user_id', $request->user()->id)->first()
            ?? throw new DomainException('Your user account is not linked to an employee record.', 'no-employee-record');
    }

    private function can(Request $request, string $ability): void
    {
        $u = $request->user();
        abort_unless($u->can('hr.manage') || ($ability === 'view' && ($u->can('hr.view') || $u->can('payroll.manage'))), 403);
    }
}
