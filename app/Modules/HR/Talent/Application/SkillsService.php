<?php

namespace App\Modules\HR\Talent\Application;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Talent\Domain\CertType;
use App\Modules\HR\Talent\Domain\Certification;
use App\Modules\HR\Talent\Domain\EmployeeSkill;
use App\Modules\HR\Talent\Domain\PositionSkill;
use App\Modules\HR\Talent\Domain\Skill;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/** Skills per position, employee levels, certificates with expiry alerts and the gaps report. */
class SkillsService
{
    public function __construct(private TenantContext $ctx, private Notifier $notifier) {}

    public function setPositionSkill(int $positionId, int $skillId, int $level): PositionSkill
    {
        Position::findOrFail($positionId);
        Skill::findOrFail($skillId);

        return PositionSkill::updateOrCreate(['position_id' => $positionId, 'skill_id' => $skillId], ['required_level' => $level]);
    }

    public function setEmployeeSkill(Employee $e, int $skillId, int $level, ?string $on = null): EmployeeSkill
    {
        Skill::findOrFail($skillId);

        return EmployeeSkill::updateOrCreate(['employee_id' => $e->id, 'skill_id' => $skillId],
            ['level' => $level, 'assessed_on' => $on ?? now()->toDateString(), 'assessed_by' => $this->ctx->actor()?->id]);
    }

    public function addCertification(Employee $e, int $typeId, string $issuedOn, ?string $expiresOn, array $extra = []): Certification
    {
        $type = CertType::findOrFail($typeId);
        if (! $expiresOn && $type->validity_months) {
            $expiresOn = Carbon::parse($issuedOn)->addMonths($type->validity_months)->toDateString();
        }
        if ($expiresOn && $expiresOn < $issuedOn) {
            throw new DomainException('The expiry date is before the issue date.');
        }

        return Certification::create(['employee_id' => $e->id, 'cert_type_id' => $type->id, 'issued_on' => $issuedOn, 'expires_on' => $expiresOn] + $extra);
    }

    public static function certStatus(?Carbon $expires, int $alertDays): string
    {
        if (! $expires) {
            return 'valid';
        }
        if ($expires->lt(now()->startOfDay())) {
            return 'expired';
        }

        return $expires->lte(now()->startOfDay()->addDays($alertDays)) ? 'expiring' : 'valid';
    }

    /** Latest certificate per employee and type (renewals supersede older rows). */
    private function latestCerts()
    {
        return Certification::orderBy('issued_on')->orderBy('id')->get()->groupBy(fn ($c) => $c->employee_id.'-'.$c->cert_type_id)->map->last();
    }

    public function certifications(?string $status = null): array
    {
        $types = CertType::all()->keyBy('id');
        $emps = Employee::whereIn('id', Certification::pluck('employee_id'))->get()->keyBy('id');
        $rows = $this->latestCerts()->map(function (Certification $c) use ($types, $emps) {
            $t = $types[$c->cert_type_id];

            return ['id' => $c->id, 'employee_id' => $c->employee_id, 'employee' => $emps[$c->employee_id]?->full_name, 'type' => $t->name, 'code' => $t->code, 'issued_on' => $c->issued_on->toDateString(),
                'expires_on' => $c->expires_on?->toDateString(), 'status' => self::certStatus($c->expires_on, $t->alert_days), 'reference' => $c->reference];
        })->values();

        return ($status ? $rows->where('status', $status) : $rows)->sortBy('expires_on')->values()->all();
    }

    /** Matrix of active employees against the skills required for their positions (plus any skill they hold). */
    public function matrix(?int $departmentId = null): array
    {
        $emps = Employee::where('status', '!=', 'terminated')->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))->orderBy('last_name')->get();
        $levels = EmployeeSkill::whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');
        $req = PositionSkill::get()->groupBy('position_id');
        $skillIds = collect($req->flatten()->pluck('skill_id'))->merge($levels->flatten()->pluck('skill_id'))->unique();
        $skills = Skill::whereIn('id', $skillIds)->orderBy('name')->get();

        return [
            'skills' => $skills->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->all(),
            'rows' => $emps->map(function (Employee $e) use ($levels, $req, $skills) {
                $mine = ($levels[$e->id] ?? collect())->keyBy('skill_id');
                $needs = ($req[$e->position_id] ?? collect())->keyBy('skill_id');

                return ['employee_id' => $e->id, 'name' => $e->full_name, 'department_id' => $e->department_id, 'position_id' => $e->position_id,
                    'cells' => $skills->mapWithKeys(fn ($s) => [$s->id => ['level' => $mine[$s->id]->level ?? null, 'required' => $needs[$s->id]->required_level ?? null,
                        'gap' => isset($needs[$s->id]) && (($mine[$s->id]->level ?? 0) < $needs[$s->id]->required_level)]])->all()];
            })->values()->all(),
        ];
    }

    /** Gaps per department: skills below the required level and mandatory certificates missing or expired. */
    public function gaps(): array
    {
        $depts = Department::get()->keyBy('id');
        $skills = Skill::get()->keyBy('id');
        $types = CertType::get()->keyBy('id');
        $mandatory = $types->where('mandatory', true);
        $levels = EmployeeSkill::get()->groupBy('employee_id');
        $req = PositionSkill::get()->groupBy('position_id');
        $certs = $this->latestCerts();
        $out = [];
        foreach (Employee::where('status', '!=', 'terminated')->get() as $e) {
            $dept = $depts[$e->department_id]?->name ?? '-';
            $out[$dept] ??= ['department' => $dept, 'department_id' => $e->department_id, 'employees' => 0, 'skill_gaps' => [], 'cert_gaps' => []];
            $out[$dept]['employees']++;
            $mine = ($levels[$e->id] ?? collect())->keyBy('skill_id');
            foreach ($req[$e->position_id] ?? [] as $rule) {
                $have = $mine[$rule->skill_id]->level ?? 0;
                if ($have < $rule->required_level) {
                    $out[$dept]['skill_gaps'][] = ['employee_id' => $e->id, 'employee' => $e->full_name, 'skill' => $skills[$rule->skill_id]->name, 'have' => $have, 'required' => $rule->required_level];
                }
            }
            foreach ($mandatory as $t) {
                $c = $certs[$e->id.'-'.$t->id] ?? null;
                $st = $c ? self::certStatus($c->expires_on, $t->alert_days) : 'missing';
                if ($st === 'missing' || $st === 'expired') {
                    $out[$dept]['cert_gaps'][] = ['employee_id' => $e->id, 'employee' => $e->full_name, 'certificate' => $t->name, 'status' => $st];
                }
            }
        }

        return collect($out)->map(fn ($d) => $d + ['skill_gap_count' => count($d['skill_gaps']), 'cert_gap_count' => count($d['cert_gaps'])])->sortBy('department')->values()->all();
    }

    /**
     * Send expiry alerts once per stage (expiring, expired) for the hotel in context.
     * Notifies the employee and the line manager. Returns the number of alerts raised.
     */
    public function scanExpiries(): int
    {
        $this->templates();
        $types = CertType::get()->keyBy('id');
        $n = 0;
        foreach ($this->latestCerts() as $c) {
            $t = $types[$c->cert_type_id] ?? null;
            if (! $t || ! $c->expires_on) {
                continue;
            }
            $st = self::certStatus($c->expires_on, $t->alert_days);
            if ($st === 'valid' || $c->alert_stage === $st || ($st === 'expiring' && $c->alert_stage === 'expired')) {
                continue;
            }
            $e = Employee::find($c->employee_id);
            if (! $e || $e->status === 'terminated') {
                continue;
            }
            $vars = ['name' => $e->full_name, 'certificate' => $t->name, 'date' => $c->expires_on->format('d/m/Y'), 'days' => max(0, (int) now()->startOfDay()->diffInDays($c->expires_on, false))];
            $to = [['email' => $e->email, 'phone' => $e->phone, 'name' => $e->full_name, 'locale' => $e->payslip_language ?? 'fr']];
            if ($e->reports_to && ($m = Employee::find($e->reports_to))) {
                $to[] = ['email' => $m->email, 'phone' => $m->phone, 'name' => $m->full_name, 'locale' => $m->payslip_language ?? 'fr'];
            }
            foreach ($to as $r) {
                $this->notifier->notify($st === 'expired' ? 'talent.cert_expired' : 'talent.cert_expiring', $r, $vars, ['type' => 'certification', 'id' => $c->id]);
            }
            $c->update(['alert_stage' => $st, 'last_alert_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** Scan every hotel (scheduled command). */
    public function scanAllHotels(): int
    {
        $n = 0;
        $prev = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        foreach ($this->ctx->withoutScope(fn () => Hotel::withoutGlobalScopes()->get()) as $h) {
            $this->ctx->forHotel($h);
            $n += $this->scanExpiries();
        }
        $this->ctx->set(...$prev);

        return $n;
    }

    private function templates(): void
    {
        $rows = [
            ['talent.cert_expiring', 'email', 'fr', 'Certificat à renouveler : {{certificate}}', "Bonjour,\n\nLe certificat « {{certificate}} » de {{name}} expire le {{date}} (dans {{days}} jours). Merci d'organiser son renouvellement."],
            ['talent.cert_expiring', 'email', 'en', 'Certificate to renew: {{certificate}}', "Hello,\n\nThe \"{{certificate}}\" certificate of {{name}} expires on {{date}} ({{days}} days left). Please arrange its renewal."],
            ['talent.cert_expiring', 'sms', 'fr', null, '{{hotel_name}} : le certificat {{certificate}} de {{name}} expire le {{date}}.'],
            ['talent.cert_expiring', 'sms', 'en', null, '{{hotel_name}}: the {{certificate}} certificate of {{name}} expires on {{date}}.'],
            ['talent.cert_expired', 'email', 'fr', 'Certificat expiré : {{certificate}}', "Bonjour,\n\nLe certificat « {{certificate}} » de {{name}} a expiré le {{date}}."],
            ['talent.cert_expired', 'email', 'en', 'Certificate expired: {{certificate}}', "Hello,\n\nThe \"{{certificate}}\" certificate of {{name}} expired on {{date}}."],
            ['talent.cert_expired', 'sms', 'fr', null, '{{hotel_name}} : le certificat {{certificate}} de {{name}} a expiré le {{date}}.'],
            ['talent.cert_expired', 'sms', 'en', null, '{{hotel_name}}: the {{certificate}} certificate of {{name}} expired on {{date}}.'],
        ];
        foreach ($rows as [$event, $channel, $locale, $subject, $body]) {
            NotificationTemplate::firstOrCreate(['event' => $event, 'channel' => $channel, 'locale' => $locale], ['category' => 'transactional', 'subject' => $subject, 'body' => $body]);
        }
    }
}
