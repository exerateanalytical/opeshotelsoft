<?php

namespace App\Modules\Accounting\Payables;

use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Notifications\Application\SendNotificationJob;
use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Domain\OutboxMessage;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer dunning: reminder letters at configurable levels (days overdue, fee), sent by email / WhatsApp through the
 * Notifications outbox, logged per invoice, with stop-dunning holds and optional simple late-payment interest.
 * Fees and interest are claims shown on the letter and the log only: nothing is posted to the ledger (chartered accountant to decide).
 */
class Dunning
{
    use Scoped;

    public const DEFAULTS = [
        1 => ['days' => 7, 'fee' => 0],
        2 => ['days' => 21, 'fee' => 5_000],
        3 => ['days' => 45, 'fee' => 15_000],
    ];

    public function __construct(TenantContext $ctx, private AuditTrail $audit)
    {
        $this->ctx = $ctx;
    }

    public function settings(): array
    {
        $row = $this->table('dunning_settings')->first();
        if (! $row) {
            DB::table('dunning_settings')->insert($this->tenant() + $this->stamp());
            $row = $this->table('dunning_settings')->first();
        }

        return ['interest_enabled' => (bool) $row->interest_enabled, 'interest_rate' => (float) $row->interest_rate, 'channels' => json_decode($row->channels, true)];
    }

    public function saveSettings(array $d): array
    {
        $this->settings();
        $this->table('dunning_settings')->update(array_filter([
            'interest_enabled' => $d['interest_enabled'] ?? null, 'interest_rate' => $d['interest_rate'] ?? null,
            'channels' => isset($d['channels']) ? json_encode(array_values(array_intersect($d['channels'], ['email', 'whatsapp']))) : null, 'updated_at' => now(),
        ], fn ($v) => $v !== null));
        $this->audit->record('dunning_settings_saved', 'dunning', 'settings', $d);

        return $this->settings();
    }

    public function levels(): array
    {
        if (! $this->table('dunning_levels')->exists()) {
            foreach (self::DEFAULTS as $level => $d) {
                DB::table('dunning_levels')->insert($this->tenant() + ['level' => $level, 'days_overdue' => $d['days'], 'fee' => $d['fee'], ...$this->stamp()]);
            }
        }

        return $this->table('dunning_levels')->orderBy('level')->get()->map(fn ($l) => (array) $l + ['letter' => $this->letterTexts($l)])->all();
    }

    public function saveLevel(int $level, array $d): array
    {
        $this->levels();
        $this->table('dunning_levels')->where('level', $level)->update(array_filter([
            'days_overdue' => $d['days_overdue'] ?? null, 'fee' => $d['fee'] ?? null, 'active' => $d['active'] ?? null,
            'subject_fr' => $d['subject_fr'] ?? null, 'subject_en' => $d['subject_en'] ?? null, 'body_fr' => $d['body_fr'] ?? null, 'body_en' => $d['body_en'] ?? null, 'updated_at' => now(),
        ], fn ($v) => $v !== null));
        $days = $this->table('dunning_levels')->orderBy('level')->pluck('days_overdue')->all();
        if ($days !== collect($days)->sort()->values()->all()) {
            throw new DomainException('Days overdue must increase with the level.', 'bad-levels');
        }
        $this->audit->record('dunning_level_saved', 'dunning', "level-$level", $d);

        return (array) $this->table('dunning_levels')->where('level', $level)->first();
    }

    /** Overdue invoices and the level each one is due for (the next level above what was already sent). */
    public function candidates(?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? Hotel::find($this->hotelId())->business_date)->startOfDay();
        $levels = collect($this->levels())->where('active', true)->sortByDesc('level')->values();
        $set = $this->settings();
        $holdCompanies = $this->table('dunning_holds')->where('active', true)->whereNotNull('company_id')->pluck('company_id')->all();
        $holdInvoices = $this->table('dunning_holds')->where('active', true)->whereNotNull('invoice_id')->pluck('invoice_id')->all();
        $sent = $this->table('dunning_notices')->groupBy('invoice_id')->selectRaw('invoice_id, max(level) as level')->pluck('level', 'invoice_id');

        $out = [];
        $invoices = $this->table('invoices')->leftJoin('companies as c', 'c.id', '=', 'invoices.company_id')->where('invoices.kind', 'invoice')
            ->whereIn('invoices.status', ['issued', 'partially_paid'])->whereRaw('invoices.total > invoices.paid')
            ->whereRaw('coalesce(invoices.due_date, invoices.issue_date) < ?', [$asOf->toDateString()])
            ->get(['invoices.*', 'c.name as company_name', 'c.email as company_email', 'c.phone as company_phone']);
        foreach ($invoices as $i) {
            $days = (int) Carbon::parse($i->due_date ?? $i->issue_date)->diffInDays($asOf);
            $due = $levels->first(fn ($l) => $days >= $l['days_overdue']);
            $held = in_array($i->id, $holdInvoices, true) || ($i->company_id && in_array($i->company_id, $holdCompanies, true));
            $balance = (int) ($i->total - $i->paid);
            $out[] = [
                'invoice_id' => $i->id, 'number' => $i->number, 'company_id' => $i->company_id, 'bill_to' => $i->company_name ?? $i->bill_to_name, 'email' => $i->company_email,
                'phone' => $i->company_phone, 'due_date' => $i->due_date ?? $i->issue_date, 'days_overdue' => $days, 'balance' => $balance, 'currency' => $i->currency,
                'last_level_sent' => (int) ($sent[$i->id] ?? 0), 'next_level' => $due ? $due['level'] : null, 'fee' => $due['fee'] ?? 0,
                'interest' => $set['interest_enabled'] ? $this->interest($balance, $days, $set['interest_rate']) : 0, 'held' => $held,
                'eligible' => $due && $due['level'] > ($sent[$i->id] ?? 0) && ! $held,
            ];
        }
        usort($out, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        return $out;
    }

    public static function interest(int $balance, int $days, float $annualRate): int
    {
        return (int) round($balance * $annualRate / 100 * $days / 365);
    }

    /** Send the due level for the chosen invoices (all eligible ones when none are given). */
    public function send(?array $invoiceIds, ?string $asOf, string $locale = 'fr'): array
    {
        $locale = in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
        $hotel = Hotel::findOrFail($this->hotelId());
        $levels = collect($this->levels())->keyBy('level');
        $channels = $this->settings()['channels'];
        $results = [];
        foreach ($this->candidates($asOf) as $c) {
            if ($invoiceIds !== null && ! in_array($c['invoice_id'], $invoiceIds, true)) {
                continue;
            }
            if (! $c['eligible']) {
                $results[] = ['invoice_id' => $c['invoice_id'], 'skipped' => $c['held'] ? 'held' : 'not-due'];

                continue;
            }
            $l = $levels[$c['next_level']];
            $vars = ['company' => $c['bill_to'], 'invoice' => $c['number'], 'due_date' => $c['due_date'], 'balance' => number_format($c['balance'], 0, ',', ' '), 'currency' => $c['currency'],
                'days' => $c['days_overdue'], 'fee' => number_format($c['fee'], 0, ',', ' '), 'interest' => number_format($c['interest'], 0, ',', ' '),
                'total_due' => number_format($c['balance'] + $c['fee'] + $c['interest'], 0, ',', ' '), 'hotel' => $hotel->name, 'level' => $c['next_level']];
            $subject = TemplateRenderer::render($l['letter']["subject_$locale"], $vars);
            $body = TemplateRenderer::render($l['letter']["body_$locale"], $vars);
            $sentTo = [];
            foreach ($channels as $ch) {
                $to = $ch === 'email' ? $c['email'] : $c['phone'];
                if (! $to) {
                    continue;
                }
                $m = OutboxMessage::create([
                    'group_id' => $this->ctx->groupId(), 'hotel_id' => $hotel->id, 'event' => 'dunning.notice', 'locale' => $locale, 'category' => 'transactional', 'channel' => $ch,
                    'recipient' => $to, 'subject' => $subject, 'body' => $ch === 'email' ? $body : mb_substr($body, 0, 600), 'status' => 'queued', 'ref_type' => 'invoice', 'ref_id' => $c['invoice_id'],
                ]);
                SendNotificationJob::dispatch($m->id)->afterCommit();
                $sentTo[] = ['channel' => $ch, 'recipient' => $to, 'outbox_id' => $m->id];
            }
            $id = DB::table('dunning_notices')->insertGetId($this->tenant() + [
                'invoice_id' => $c['invoice_id'], 'company_id' => $c['company_id'], 'level' => $c['next_level'], 'days_overdue' => $c['days_overdue'], 'balance' => $c['balance'],
                'fee' => $c['fee'], 'interest' => $c['interest'], 'locale' => $locale, 'subject' => $subject, 'body' => $body, 'channels' => json_encode($sentTo),
                'status' => $sentTo ? 'sent' : 'no_contact', 'sent_by' => $this->actorId(), ...$this->stamp(),
            ]);
            $this->audit->record('dunning_sent', 'invoice', $c['invoice_id'], ['notice' => $id, 'level' => $c['next_level'], 'channels' => array_column($sentTo, 'channel')]);
            $results[] = ['invoice_id' => $c['invoice_id'], 'notice_id' => $id, 'level' => $c['next_level'], 'status' => $sentTo ? 'sent' : 'no_contact'];
        }

        return $results;
    }

    public function notices(?int $invoiceId = null): array
    {
        return $this->table('dunning_notices')->when($invoiceId, fn ($q, $v) => $q->where('invoice_id', $v))->orderByDesc('id')->limit(300)->get()
            ->map(fn ($n) => (array) $n + ['channels' => json_decode($n->channels, true)])->all();
    }

    public function holds(): array
    {
        return $this->table('dunning_holds')->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function hold(?int $companyId, ?int $invoiceId, string $reason): array
    {
        if (! $companyId && ! $invoiceId) {
            throw new DomainException('Choose a company or an invoice.', 'target-required');
        }
        $id = DB::table('dunning_holds')->insertGetId($this->tenant() + ['company_id' => $companyId, 'invoice_id' => $invoiceId, 'reason' => $reason, 'created_by' => $this->actorId(), ...$this->stamp()]);
        $this->audit->record('dunning_hold', 'dunning', $id, compact('companyId', 'invoiceId', 'reason'));

        return (array) DB::table('dunning_holds')->find($id);
    }

    public function release(int $id): array
    {
        $this->table('dunning_holds')->where('id', $id)->exists() || throw new DomainException('Hold not found.', 'not-found');
        $this->table('dunning_holds')->where('id', $id)->update(['active' => false, 'released_by' => $this->actorId(), 'released_at' => now(), 'updated_at' => now()]);
        $this->audit->record('dunning_release', 'dunning', $id);

        return (array) DB::table('dunning_holds')->find($id);
    }

    private function letterTexts(object $l): array
    {
        $d = fn ($level) => match ($level) {
            1 => ['Rappel de paiement : facture {{invoice}}', "Bonjour {{company}},\n\nSauf erreur de notre part, la facture {{invoice}} échue le {{due_date}} reste impayée : {{balance}} {{currency}} ({{days}} jours de retard).\nMerci de procéder au règlement.\n\n{{hotel}}",
                'Payment reminder: invoice {{invoice}}', "Hello {{company}},\n\nOur records show invoice {{invoice}}, due on {{due_date}}, is unpaid: {{balance}} {{currency}} ({{days}} days overdue).\nPlease arrange payment.\n\n{{hotel}}"],
            2 => ['Deuxième rappel : facture {{invoice}}', "Bonjour {{company}},\n\nMalgré notre premier rappel, la facture {{invoice}} ({{balance}} {{currency}}, échue le {{due_date}}) demeure impayée depuis {{days}} jours.\nFrais de relance : {{fee}} {{currency}}. Total à régler : {{total_due}} {{currency}}.\n\n{{hotel}}",
                'Second reminder: invoice {{invoice}}', "Hello {{company}},\n\nDespite our first reminder, invoice {{invoice}} ({{balance}} {{currency}}, due {{due_date}}) is still unpaid after {{days}} days.\nReminder fee: {{fee}} {{currency}}. Total due: {{total_due}} {{currency}}.\n\n{{hotel}}"],
            default => ['Mise en demeure : facture {{invoice}}', "Bonjour {{company}},\n\nNous vous mettons en demeure de régler sous huit jours la facture {{invoice}} ({{balance}} {{currency}}, {{days}} jours de retard), faute de quoi le dossier sera transmis au contentieux.\nFrais : {{fee}} {{currency}}, intérêts : {{interest}} {{currency}}. Total : {{total_due}} {{currency}}.\n\n{{hotel}}",
                'Final notice: invoice {{invoice}}', "Hello {{company}},\n\nWe require payment of invoice {{invoice}} ({{balance}} {{currency}}, {{days}} days overdue) within eight days, failing which the matter will be referred for collection.\nFees: {{fee}} {{currency}}, interest: {{interest}} {{currency}}. Total: {{total_due}} {{currency}}.\n\n{{hotel}}"],
        };
        [$sfr, $bfr, $sen, $ben] = $d($l->level);

        return ['subject_fr' => $l->subject_fr ?: $sfr, 'body_fr' => $l->body_fr ?: $bfr, 'subject_en' => $l->subject_en ?: $sen, 'body_en' => $l->body_en ?: $ben];
    }
}
