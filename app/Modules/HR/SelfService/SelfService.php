<?php

namespace App\Modules\HR\SelfService;

use App\Models\User;
use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\HR\Application\HrService;
use App\Modules\HR\Application\Lifecycle\EContractService;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Talent\Domain\ChangeRequest;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * Employee self-service. Identity is the existing employees.user_id link; nothing here creates accounts.
 * Sensitive changes (address, phone, bank, MoMo) and certificates are requests that HR decides.
 */
class SelfService
{
    public const KINDS = ['employment_certificate', 'salary_certificate', 'address_change', 'phone_change', 'bank_change', 'momo_change'];

    /** Fields each change request may carry, mapped to employee columns. */
    private const CHANGES = [
        'address_change' => ['address' => 'address'],
        'phone_change' => ['phone' => 'phone'],
        'bank_change' => ['bank_name' => 'bank_name', 'bank_account' => 'bank_account'],
        'momo_change' => ['momo_number' => 'momo_number'],
    ];

    public function __construct(private TenantContext $ctx, private EventBus $bus, private HrService $hr, private EContractService $contracts) {
        View::addNamespace('talent', dirname(__DIR__).'/Talent/Resources/views');
    }

    public function employeeFor(User $u): Employee
    {
        return Employee::where('user_id', $u->id)->first()
            ?? throw new DomainException('Your user account is not linked to an employee record.', 'no-employee-record', 403);
    }

    public function profile(Employee $e): array
    {
        return ['id' => $e->id, 'number' => $e->number, 'name' => $e->full_name, 'phone' => $e->phone, 'email' => $e->email, 'address' => $e->address,
            'payment_method' => $e->payment_method, 'bank_name' => $e->bank_name, 'bank_account' => $this->mask($e->bank_account), 'momo_number' => $this->mask($e->momo_number),
            'hire_date' => $e->hire_date->toDateString(), 'status' => $e->status];
    }

    private function mask(?string $v): ?string
    {
        return $v ? str_repeat('*', max(0, strlen($v) - 4)).substr($v, -4) : null;
    }

    public function request(Employee $e, string $kind, array $payload): ChangeRequest
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new DomainException('Unknown request type.');
        }
        if (isset(self::CHANGES[$kind])) {
            $payload = array_intersect_key($payload, self::CHANGES[$kind]);
            foreach (array_keys(self::CHANGES[$kind]) as $f) {
                if (empty($payload[$f])) {
                    throw new DomainException("The field $f is required.");
                }
            }
        } else {
            $payload = array_intersect_key($payload, ['purpose' => 1, 'lang' => 1]);
        }
        if (ChangeRequest::where('employee_id', $e->id)->where('kind', $kind)->where('status', 'pending')->exists()) {
            throw new DomainException('You already have a pending request of this type.', 'duplicate-request');
        }
        $r = ChangeRequest::create(['employee_id' => $e->id, 'kind' => $kind, 'payload' => $payload]);
        $this->bus->record('hr.self_service_request', ['id' => $r->id, 'kind' => $kind, 'employee_id' => $e->id], 'tal_request', $r->id);

        return $r;
    }

    /** HR decision. Approving a data change applies it; approving a certificate request makes it downloadable. */
    public function decide(ChangeRequest $r, bool $approve, ?string $note): ChangeRequest
    {
        if ($r->status !== 'pending') {
            throw new DomainException('This request is already decided.', 'invalid-state');
        }
        DB::transaction(function () use ($r, $approve, $note) {
            if ($approve && isset(self::CHANGES[$r->kind])) {
                $e = Employee::findOrFail($r->employee_id);
                $e->update(collect(self::CHANGES[$r->kind])->mapWithKeys(fn ($col, $f) => [$col => $r->payload[$f] ?? null])->all());
                if ($r->kind === 'bank_change') {
                    $e->update(['payment_method' => 'bank']);
                }
                if ($r->kind === 'momo_change') {
                    $e->update(['payment_method' => 'mobile_money']);
                }
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'note' => $note, 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now()]);
        });
        $this->bus->record('hr.self_service_decided', ['id' => $r->id, 'kind' => $r->kind, 'approved' => $approve], 'tal_request', $r->id);

        return $r;
    }

    /** PDF of an approved certificate request, issued through the document engine. */
    public function certificate(ChangeRequest $r): string
    {
        if ($r->status !== 'approved' || ! in_array($r->kind, ['employment_certificate', 'salary_certificate'], true)) {
            throw new DomainException('No certificate is available for this request.', 'not-available', 404);
        }
        $e = Employee::findOrFail($r->employee_id);
        $lang = ($r->payload['lang'] ?? $e->payslip_language ?? 'fr') === 'en' ? 'en' : 'fr';
        $hotel = Hotel::withoutGlobalScopes()->find($e->hotel_id);
        $contract = $e->contracts()->first();
        $data = ['employee' => $e, 'kind' => $r->kind, 'contract' => $contract, 'salary' => (int) ($contract?->base_salary ?? $e->base_salary), 'position' => $e->position_id ? DB::table('positions')->where('id', $e->position_id)->value('title') : null,
            'purpose' => $r->payload['purpose'] ?? null, 'on' => now()->format('d/m/Y')];

        return app(DocumentRenderer::class)->render($r->kind, $data, [
            'view' => 'talent::certificate', 'hotel' => $hotel, 'lang' => $lang, 'number' => strtoupper(substr($r->kind, 0, 3)).'-'.$r->id, 'source_type' => 'tal_request', 'source_id' => $r->id,
            'title' => $lang === 'en' ? 'Certificate' : 'Attestation',
        ])->pdf;
    }

    /** Contracts waiting for the employee's signature. */
    public function contractsToSign(Employee $e): array
    {
        return EContract::where('employee_id', $e->id)->whereIn('status', ['sent', 'draft'])->orderByDesc('id')->get(['id', 'number', 'title', 'status', 'kind'])->all();
    }

    /** A fresh signing link for the employee's own contract (the OTP step still applies). */
    public function signingLink(Employee $e, int $contractId): array
    {
        $doc = EContract::where('employee_id', $e->id)->findOrFail($contractId);

        return $this->contracts->send($doc);
    }
}
