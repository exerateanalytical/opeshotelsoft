<?php

namespace App\Modules\HR\Application\Lifecycle;

use App\Modules\Documents\Application\NumberToWords;
use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\ContractTemplate;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Domain\Lifecycle\EContractAudit;
use App\Modules\HR\Domain\Position;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Domain\NumberSequence;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * E-contracts: generate from a template → send a signing link → employee
 * verifies an OTP (SMS / WhatsApp / email) and draws a signature on the phone →
 * employer counter-signs → final PDF stored with its SHA-256 and the audit trail.
 */
class EContractService
{
    public const LINK_DAYS = 7;

    public const OTP_MINUTES = 10;

    public const OTP_MAX_ATTEMPTS = 5;

    public function __construct(private TenantContext $ctx, private EventBus $bus, private Notifier $notifier) {}

    public function template(string $kind, string $locale): array
    {
        $t = ContractTemplate::where(['kind' => $kind, 'locale' => $locale, 'active' => true])->first();

        return $t ? ['title' => $t->title, 'body' => $t->body] : (ContractTemplates::default($kind, $locale)
            ?? throw new DomainException("No contract template for $kind.", 'no-template'));
    }

    /** Placeholder values for an employee (and optional contract / change). */
    public function variables(Employee $e, ?Contract $c, string $locale, array $change = []): array
    {
        $hotel = Hotel::withoutGlobalScopes()->find($e->hotel_id);
        $position = $e->position_id ? Position::withoutGlobalScopes()->find($e->position_id) : null;
        $dept = $e->department_id ? Department::withoutGlobalScopes()->find($e->department_id) : null;
        $d = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '…';
        $m = fn ($v) => number_format((int) $v, 0, ',', ' ');
        $salary = (int) ($c?->base_salary ?? $e->base_salary);

        return [
            'employee' => [
                'full_name' => $e->full_name, 'number' => $e->number, 'date_of_birth' => $d($e->date_of_birth),
                'id_number' => $e->id_number ?: '…', 'address' => $e->address ?: '…', 'nationality' => $e->nationality,
            ],
            'position' => ['title' => $position?->title ?? '…', 'category' => $position?->category ?? '…'],
            'department' => ['name' => $dept?->name ?? '…'],
            'contract' => [
                'kind' => $c?->kind ?? '…', 'start_date' => $d($c?->start_date), 'end_date' => $d($c?->end_date),
                'probation_end' => $d($c?->probation_end), 'hours_per_week' => (string) ($c?->hours_per_week ?? 40),
            ],
            'salary' => ['amount' => $m($salary), 'words' => NumberToWords::convert($salary, $locale)],
            'hotel' => [
                'name' => $hotel?->name ?? '', 'legal_name' => $hotel?->legal_name ?: ($hotel?->name ?? ''),
                'city' => $hotel?->city ?? '', 'address' => $hotel?->address ?? '',
            ],
            'today' => now()->format('d/m/Y'),
            'change' => $change,
        ];
    }

    public function generate(Employee $e, ?string $kind = null, ?int $contractId = null, ?string $locale = null, array $change = []): EContract
    {
        $contract = $contractId ? $e->contracts()->findOrFail($contractId) : $e->contracts()->where('status', 'active')->first();
        $kind ??= $contract?->kind ?? throw new DomainException('Choose a contract type: the employee has no active contract.');
        $locale = $locale ?? ($e->payslip_language ?? 'fr');
        $tpl = $this->template($kind, $locale);
        $vars = $this->variables($e, $contract, $locale, $change);

        $doc = EContract::create([
            'employee_id' => $e->id, 'contract_id' => $contract?->id, 'kind' => $kind, 'locale' => $locale,
            'number' => NumberSequence::next($e->hotel_id, 'econtract', 'CT'),
            'title' => TemplateRenderer::render($tpl['title'], $vars), 'body' => TemplateRenderer::render($tpl['body'], $vars),
            'created_by' => $this->ctx->actor()?->id,
        ]);
        $this->audit($doc, 'generated', null, null, ['kind' => $kind, 'locale' => $locale]);

        return $doc;
    }

    /** Issue a fresh signing link (invalidates any previous one) and send it to the employee. */
    public function send(EContract $doc): array
    {
        if (! in_array($doc->status, ['draft', 'sent'], true)) {
            throw new DomainException("This contract is {$doc->status}.", 'invalid-state');
        }
        $token = Str::random(48);
        $doc->update([
            'status' => 'sent', 'token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(self::LINK_DAYS),
            'body_sha256' => hash('sha256', $doc->body), 'otp_hash' => null, 'otp_attempts' => 0, 'otp_verified_at' => null,
        ]);
        $url = url('/sign/contract/'.$token);
        $e = $doc->employee;
        $this->ensureTemplates();
        $this->notifier->notify('hr.contract_sign_link', ['email' => $e->email, 'phone' => $e->phone, 'locale' => $doc->locale, 'name' => $e->full_name],
            ['name' => $e->full_name, 'title' => $doc->title, 'url' => $url, 'days' => self::LINK_DAYS], ['type' => 'econtract', 'id' => $doc->id]);
        $this->audit($doc, 'sent', null, null, ['expires_at' => $doc->token_expires_at->toIso8601String()]);
        $this->bus->record('hr.econtract_sent', ['id' => $doc->id, 'employee_id' => $doc->employee_id], 'econtract', $doc->id);

        return ['url' => $url, 'expires_at' => $doc->token_expires_at];
    }

    /** Resolve a public signing token and enter that hotel's tenant context. */
    public function byToken(string $token): EContract
    {
        $doc = $this->ctx->withoutScope(fn () => EContract::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first());
        if (! $doc || ! $doc->token_expires_at || $doc->token_expires_at->isPast() || $doc->status === 'cancelled') {
            throw new DomainException('This signing link is invalid or has expired.', 'invalid-link', 404);
        }
        $this->ctx->set($doc->group_id, $doc->hotel_id, [$doc->hotel_id], null);

        return $doc;
    }

    public function publicView(EContract $doc): array
    {
        $e = $doc->employee;
        $mask = fn (?string $v) => $v ? substr($v, 0, 2).str_repeat('•', max(0, strlen($v) - 4)).substr($v, -2) : null;

        return [
            'number' => $doc->number, 'title' => $doc->title, 'body' => $doc->body, 'status' => $doc->status, 'locale' => $doc->locale,
            'employee' => $e->full_name, 'hotel' => Hotel::withoutGlobalScopes()->find($doc->hotel_id)?->name,
            'channels' => array_values(array_filter([
                $e->phone ? ['channel' => 'sms', 'to' => $mask($e->phone)] : null,
                $e->phone ? ['channel' => 'whatsapp', 'to' => $mask($e->phone)] : null,
                $e->email ? ['channel' => 'email', 'to' => $mask($e->email)] : null,
            ])),
            'otp_verified' => (bool) $doc->otp_verified_at, 'employee_signed_at' => $doc->employee_signed_at,
        ];
    }

    public function requestOtp(EContract $doc, string $channel, ?string $ip, ?string $ua): void
    {
        if ($doc->status !== 'sent') {
            throw new DomainException('This contract is not waiting for your signature.', 'invalid-state');
        }
        $e = $doc->employee;
        $to = $channel === 'email' ? ['email' => $e->email] : ['phone' => $e->phone];
        if (! array_filter($to)) {
            throw new DomainException('No address on file for this channel.', 'no-address');
        }
        $code = (string) random_int(100000, 999999);
        $doc->update(['otp_hash' => hash('sha256', $doc->id.'|'.$code), 'otp_expires_at' => now()->addMinutes(self::OTP_MINUTES), 'otp_attempts' => 0, 'otp_channel' => $channel]);
        $this->ensureTemplates();
        $this->notifier->notify("hr.contract_otp.$channel", $to + ['locale' => $doc->locale, 'name' => $e->full_name],
            ['code' => $code, 'minutes' => self::OTP_MINUTES, 'number' => $doc->number], ['type' => 'econtract', 'id' => $doc->id]);
        $this->audit($doc, 'otp_sent', $ip, $ua, ['channel' => $channel]);
    }

    public function employeeSign(EContract $doc, string $otp, string $signature, ?string $ip, ?string $ua): EContract
    {
        $this->assertSignature($signature);
        // Failed attempts are committed outside the signing transaction so they count.
        $ok = DB::transaction(function () use ($doc, $otp, $ip, $ua) {
            $doc = EContract::withoutGlobalScopes()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status !== 'sent') {
                return 'invalid-state';
            }
            if (! $doc->otp_hash || $doc->otp_expires_at?->isPast()) {
                return 'otp-expired';
            }
            if ($doc->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
                return 'otp-locked';
            }
            if (! hash_equals($doc->otp_hash, hash('sha256', $doc->id.'|'.trim($otp)))) {
                $doc->increment('otp_attempts');
                $this->audit($doc, 'otp_failed', $ip, $ua, ['attempts' => $doc->otp_attempts]);

                return 'otp-invalid';
            }

            return true;
        });
        if ($ok !== true) {
            $msg = ['invalid-state' => 'This contract is not waiting for your signature.', 'otp-expired' => 'Request a new code.',
                'otp-locked' => 'Too many wrong codes. Request a new code.', 'otp-invalid' => 'Wrong code.'][$ok];
            throw new DomainException($msg, $ok);
        }

        return DB::transaction(function () use ($doc, $signature, $ip, $ua) {
            $doc = EContract::withoutGlobalScopes()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->status !== 'sent' || ! $doc->otp_hash) {
                throw new DomainException('This contract is not waiting for your signature.', 'invalid-state');
            }
            $doc->update([
                'otp_verified_at' => now(), 'otp_hash' => null, 'employee_signature' => $signature, 'employee_signed_at' => now(),
                'employee_ip' => $ip, 'employee_user_agent' => Str::limit((string) $ua, 250, ''), 'status' => 'employee_signed',
            ]);
            $this->audit($doc, 'otp_verified', $ip, $ua, ['channel' => $doc->otp_channel]);
            $this->audit($doc, 'employee_signed', $ip, $ua, ['body_sha256' => $doc->body_sha256, 'signature_sha256' => hash('sha256', $signature)]);
            $this->bus->record('hr.econtract_employee_signed', ['id' => $doc->id, 'employee_id' => $doc->employee_id], 'econtract', $doc->id);

            return $doc;
        });
    }

    /** Employer counter-signature: closes the link, renders and seals the final PDF. */
    public function countersign(EContract $doc, string $signature, ?string $ip, ?string $ua): EContract
    {
        if ($doc->status !== 'employee_signed') {
            throw new DomainException('The employee must sign first.', 'invalid-state');
        }
        $actor = $this->ctx->actor();
        if ($actor && $doc->employee->user_id && $doc->employee->user_id === $actor->id) {
            throw new DomainException('You cannot counter-sign your own contract.', 'sod');
        }
        $this->assertSignature($signature);

        return DB::transaction(function () use ($doc, $signature, $ip, $ua, $actor) {
            $doc->update([
                'employer_signature' => $signature, 'employer_signed_by' => $actor?->id, 'employer_signed_at' => now(),
                'employer_ip' => $ip, 'status' => 'completed', 'token_hash' => null, 'token_expires_at' => null,
            ]);
            $this->audit($doc, 'employer_signed', $ip, $ua, ['signature_sha256' => hash('sha256', $signature)]);
            $pdf = $this->renderPdf($doc->fresh());
            $path = "hr/contracts/{$doc->hotel_id}/{$doc->number}.pdf";
            Storage::disk('local')->put($path, $pdf);
            $doc->update(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $pdf)]);
            $this->audit($doc, 'sealed', $ip, $ua, ['pdf_sha256' => $doc->pdf_sha256]);
            if ($doc->contract_id) {
                Contract::whereKey($doc->contract_id)->update(['status' => 'active']);
            }
            $this->bus->record('hr.econtract_completed', ['id' => $doc->id, 'employee_id' => $doc->employee_id, 'pdf_sha256' => $doc->pdf_sha256], 'econtract', $doc->id);

            return $doc;
        });
    }

    public function cancel(EContract $doc): EContract
    {
        if ($doc->status === 'completed') {
            throw new DomainException('A signed contract cannot be cancelled; issue an amendment.', 'invalid-state');
        }
        $doc->update(['status' => 'cancelled', 'token_hash' => null, 'token_expires_at' => null]);
        $this->audit($doc, 'cancelled', request()->ip(), request()->userAgent());

        return $doc;
    }

    /** PDF bytes: the stored sealed file once completed, else a live draft preview. */
    public function pdf(EContract $doc): string
    {
        if ($doc->pdf_path && Storage::disk('local')->exists($doc->pdf_path)) {
            return Storage::disk('local')->get($doc->pdf_path);
        }

        return $this->renderPdf($doc);
    }

    public function renderPdf(EContract $doc): string
    {
        $lang = $doc->locale === 'en' ? 'en' : 'fr';
        app()->setLocale($lang);
        $hotel = Hotel::withoutGlobalScopes()->find($doc->hotel_id);
        $data = [
            'doc' => $doc, 'audit' => $doc->audit()->get(),
            'employer' => $doc->employer_signed_by ? DB::table('users')->where('id', $doc->employer_signed_by)->value('name') : null,
        ];
        // Through the document engine; a draft preview (not yet signed by both) is not issued.
        return app(\App\Modules\Documents\Application\DocumentRenderer::class)->render('econtract', $data, [
            'view' => 'hr::econtract', 'hotel' => $hotel, 'lang' => $lang, 'number' => (string) $doc->number, 'source_type' => 'econtract',
            'source_id' => $doc->id, 'title' => (string) $doc->title, 'issue' => $doc->status === 'completed', 'draft' => $doc->status !== 'completed',
        ])->pdf;
    }

    public function audit(EContract $doc, string $event, ?string $ip = null, ?string $ua = null, array $meta = []): void
    {
        EContractAudit::create([
            'econtract_id' => $doc->id, 'event' => $event, 'actor_id' => $this->ctx->actor()?->id,
            'ip' => $ip, 'user_agent' => $ua ? Str::limit($ua, 250, '') : null, 'meta' => $meta, 'created_at' => now(),
        ]);
    }

    private function assertSignature(string $sig): void
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $sig, $m) || strlen($sig) > 400_000) {
            throw new DomainException('The signature must be a PNG drawing.', 'invalid-signature');
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || ! str_starts_with($bin, "\x89PNG") || strlen($bin) < 100) {
            throw new DomainException('The signature must be a PNG drawing.', 'invalid-signature');
        }
    }

    /** Hotel-level notification templates for the signing messages (created once per hotel). */
    private function ensureTemplates(): void
    {
        $rows = [
            ['hr.contract_sign_link', 'email', 'fr', 'Contrat à signer : {{title}}', "Bonjour {{name}},\n\nVotre contrat « {{title}} » est prêt. Ouvrez ce lien sur votre téléphone pour le lire et le signer : {{url}}\nLe lien est valable {{days}} jours."],
            ['hr.contract_sign_link', 'email', 'en', 'Contract to sign: {{title}}', "Hello {{name}},\n\nYour contract \"{{title}}\" is ready. Open this link on your phone to read and sign it: {{url}}\nThe link is valid for {{days}} days."],
            ['hr.contract_sign_link', 'sms', 'fr', null, '{{hotel_name}} : votre contrat est prêt à signer : {{url}}'],
            ['hr.contract_sign_link', 'sms', 'en', null, '{{hotel_name}}: your contract is ready to sign: {{url}}'],
        ];
        foreach (['sms', 'whatsapp', 'email'] as $ch) {
            $rows[] = ["hr.contract_otp.$ch", $ch, 'fr', $ch === 'email' ? 'Code de signature {{number}}' : null, 'Code de signature du contrat {{number}} : {{code}} (valable {{minutes}} min). Ne le communiquez à personne.'];
            $rows[] = ["hr.contract_otp.$ch", $ch, 'en', $ch === 'email' ? 'Signing code {{number}}' : null, 'Signing code for contract {{number}}: {{code}} (valid {{minutes}} min). Do not share it.'];
        }
        foreach ($rows as [$event, $channel, $locale, $subject, $body]) {
            NotificationTemplate::firstOrCreate(['event' => $event, 'channel' => $channel, 'locale' => $locale], ['category' => 'transactional', 'subject' => $subject, 'body' => $body]);
        }
    }
}
