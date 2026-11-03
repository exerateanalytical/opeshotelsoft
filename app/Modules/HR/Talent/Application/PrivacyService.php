<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Domain\Attendance;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\EmployeeDocument;
use App\Modules\HR\Domain\LeaveRequest;
use App\Modules\HR\Domain\Payslip;
use App\Modules\HR\Talent\Domain\ChangeRequest;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\HR\Talent\Domain\EmployeeSkill;
use App\Modules\HR\Talent\Domain\PrivacyLog;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Data subject export and erasure for employees. Erasure keeps payslip amounts so payroll history is intact. */
class PrivacyService
{
    public const ERASED = 'Anonymised';

    public function __construct(private TenantContext $ctx, private TrainingService $training) {}

    public function export(Employee $e, bool $log = true): array
    {
        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => collect($e->getAttributes())->except(['group_id', 'hotel_id'])->all(),
            'contracts' => $e->contracts()->get()->toArray(),
            'documents' => EmployeeDocument::where('employee_id', $e->id)->get(['id', 'type', 'title', 'original_name', 'expires_on'])->toArray(),
            'leave_requests' => LeaveRequest::where('employee_id', $e->id)->get(['start_date', 'end_date', 'days', 'status', 'reason'])->toArray(),
            'attendance_days' => Attendance::where('employee_id', $e->id)->count(),
            'payslips' => Payslip::with('run:id,period,status')->where('employee_id', $e->id)->get()->map(fn ($p) => ['period' => $p->run->period, 'gross' => $p->gross, 'net' => $p->net, 'taxes_withheld' => $p->taxes_withheld])->all(),
            'skills' => EmployeeSkill::where('employee_id', $e->id)->get(['skill_id', 'level', 'assessed_on'])->toArray(),
            'certifications' => Certification::where('employee_id', $e->id)->get(['cert_type_id', 'issued_on', 'expires_on', 'reference'])->toArray(),
            'training' => $this->training->recordsFor($e->id),
            'requests' => ChangeRequest::where('employee_id', $e->id)->get(['kind', 'payload', 'status', 'created_at'])->toArray(),
        ];
        if ($log) {
            PrivacyLog::create(['employee_id' => $e->id, 'action' => 'export', 'subject_ref' => $e->number, 'user_id' => $this->ctx->actor()?->id]);
        }

        return $data;
    }

    /** One sheet of section / field / value rows. */
    public function xlsx(Employee $e): string
    {
        $data = $this->export($e);
        $tmp = tempnam(sys_get_temp_dir(), 'dsx');
        $w = new Writer;
        $w->openToFile($tmp);
        $w->addRow(Row::fromValues(['section', 'field', 'value']));
        foreach ($data as $section => $v) {
            if (! is_array($v)) {
                $w->addRow(Row::fromValues([$section, '', (string) $v]));
                continue;
            }
            $rows = array_is_list($v) ? $v : [$v];
            foreach ($rows as $i => $row) {
                foreach ((array) $row as $k => $val) {
                    $w->addRow(Row::fromValues([$section.(count($rows) > 1 ? ' #'.($i + 1) : ''), (string) $k, is_scalar($val) || $val === null ? (string) $val : json_encode($val)]));
                }
            }
        }
        $w->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function isErased(Employee $e): bool
    {
        return $e->first_name === self::ERASED;
    }

    /** Leavers whose retention has run out. */
    public function eligible(): array
    {
        $months = app(PayrollChecks::class)->settings()->leaver_retention_months;
        $cut = now()->subMonths($months)->toDateString();

        return Employee::where('status', 'terminated')->whereNotNull('termination_date')->where('termination_date', '<=', $cut)->where('first_name', '!=', self::ERASED)
            ->orderBy('termination_date')->get(['id', 'number', 'first_name', 'last_name', 'termination_date'])->all();
    }

    public function erase(Employee $e): Employee
    {
        if ($e->status !== 'terminated') {
            throw new DomainException('Only former employees can be erased.', 'not-a-leaver');
        }
        if ($this->isErased($e)) {
            throw new DomainException('This employee is already anonymised.', 'already-erased');
        }
        $months = app(PayrollChecks::class)->settings()->leaver_retention_months;
        if (! $e->termination_date || $e->termination_date->gt(now()->subMonths($months))) {
            throw new DomainException("Records are kept {$months} months after departure.", 'retention-active');
        }

        return DB::transaction(function () use ($e) {
            foreach (EmployeeDocument::where('employee_id', $e->id)->get() as $d) {
                if ($d->path) {
                    Storage::disk('local')->delete($d->path);
                }
                $d->update(['path' => '', 'title' => '[removed]', 'original_name' => '[removed]']);
            }
            if ($e->photo_path && ! str_starts_with($e->photo_path, '/') && ! str_starts_with($e->photo_path, 'http')) {
                Storage::disk('public')->delete($e->photo_path);
            }
            ChangeRequest::where('employee_id', $e->id)->update(['payload' => '{}']);
            $ref = $e->number;
            $e->update([
                'first_name' => self::ERASED, 'last_name' => $ref, 'gender' => null, 'date_of_birth' => null, 'id_number' => null, 'cnps_number' => null, 'tax_number' => null,
                'marital_status' => null, 'phone' => null, 'email' => null, 'address' => null, 'bank_name' => null, 'bank_account' => null, 'momo_number' => null,
                'photo_path' => null, 'user_id' => null,
            ]);
            PrivacyLog::create(['employee_id' => $e->id, 'action' => 'erase', 'subject_ref' => $ref, 'user_id' => $this->ctx->actor()?->id]);

            return $e;
        });
    }
}
