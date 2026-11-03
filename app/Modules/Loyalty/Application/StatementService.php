<?php

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Loyalty\Domain\Transaction;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use App\Modules\Documents\Application\DocumentRenderer;
use Carbon\Carbon;

/** Member points statement for a period: opening balance, movements, closing balance. */
class StatementService
{
    public const EVENT = 'loyalty.statement';

    private const TEMPLATES = [
        'fr' => ['Votre relevé de points {{ member_number }}', "Bonjour {{ guest_name }},\n\nVoici votre relevé de points fidélité du {{ from }} au {{ to }}.\nSolde d'ouverture : {{ opening }} pts\nPoints gagnés : {{ earned }} pts\nPoints utilisés ou expirés : {{ spent }} pts\nSolde au {{ to }} : {{ closing }} pts\n\nMerci de votre fidélité,\n{{ hotel_name }}"],
        'en' => ['Your points statement {{ member_number }}', "Hello {{ guest_name }},\n\nHere is your loyalty points statement from {{ from }} to {{ to }}.\nOpening balance: {{ opening }} pts\nPoints earned: {{ earned }} pts\nPoints used or expired: {{ spent }} pts\nBalance on {{ to }}: {{ closing }} pts\n\nThank you for your loyalty,\n{{ hotel_name }}"],
    ];

    public function __construct(private TenantContext $ctx) {}

    public function data(Membership $m, ?string $from = null, ?string $to = null): array
    {
        $from = Carbon::parse($from ?? now()->subMonths(3)->toDateString())->startOfDay();
        $to = Carbon::parse($to ?? now()->toDateString())->endOfDay();
        if ($from > $to) {
            throw new DomainException('The start date must be before the end date.', 'statement-period');
        }
        $q = fn () => Transaction::where('loyalty_membership_id', $m->id);
        $opening = (int) $q()->where('created_at', '<', $from)->sum('points');
        $lines = $q()->whereBetween('created_at', [$from, $to])->orderBy('created_at')->orderBy('id')->get();
        $running = $opening;
        $rows = $lines->map(function (Transaction $t) use (&$running) {
            $running += $t->points;

            return ['id' => $t->id, 'date' => $t->created_at->toDateString(), 'kind' => $t->kind, 'description' => $t->description,
                'points' => (int) $t->points, 'balance' => $running, 'expires_on' => $t->expires_on?->toDateString()];
        })->all();
        $m->loadMissing('guest', 'tier');

        return [
            'member' => ['id' => $m->id, 'number' => $m->number, 'name' => trim("{$m->guest?->first_name} {$m->guest?->last_name}"),
                'tier' => $m->tier?->name, 'email' => $m->guest?->email],
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'opening' => $opening,
            'earned' => (int) $lines->where('points', '>', 0)->sum('points'), 'spent' => (int) -$lines->where('points', '<', 0)->sum('points'),
            'closing' => $running, 'lines' => $rows,
            'expiring' => (int) $q()->where('remaining', '>', 0)->whereBetween('expires_on', [now()->toDateString(), now()->addDays(90)->toDateString()])->sum('remaining'),
        ];
    }

    public function pdf(Membership $m, ?string $from, ?string $to, string $lang): string
    {
        $s = $this->data($m, $from, $to);

        // Each distinct period is its own issued statement (a reprint of the same period is a copy).
        return app(DocumentRenderer::class)->render('loyalty_statement', ['s' => $s], [
            'view' => 'loyalty::statement', 'lang' => $lang, 'number' => 'PTS-'.$m->number.'-'.str_replace('-', '', (string) ($s['from'] ?? '')).'-'.str_replace('-', '', (string) ($s['to'] ?? '')),
            'source_type' => 'loyalty_statement', 'source_id' => (int) crc32($m->id.'|'.($s['from'] ?? '').'|'.($s['to'] ?? '')),
            'title' => $lang === 'en' ? 'Points statement' : 'Relevé de points',
        ])->pdf;
    }

    /** Email the statement summary to the member through the Notifications outbox. */
    public function email(Membership $m, ?string $from, ?string $to): array
    {
        $s = $this->data($m, $from, $to);
        if (! $m->guest?->email) {
            throw new DomainException('This member has no email address.', 'member-no-email');
        }
        $this->ensureTemplates();

        return app(Notifier::class)->notify(self::EVENT, ['guest_id' => $m->guest_id, 'email' => $m->guest->email], [
            'member_number' => $m->number, 'from' => $s['from'], 'to' => $s['to'], 'opening' => $s['opening'],
            'earned' => $s['earned'], 'spent' => $s['spent'], 'closing' => $s['closing'],
        ], ['type' => 'loyalty_membership', 'id' => $m->id]);
    }

    /** Statement templates are created per hotel on first use (editable afterwards in Notifications). */
    private function ensureTemplates(): void
    {
        if (! $this->ctx->hotelId()) {
            return;
        }
        foreach (self::TEMPLATES as $locale => [$subject, $body]) {
            NotificationTemplate::firstOrCreate(['event' => self::EVENT, 'channel' => 'email', 'locale' => $locale],
                ['category' => 'transactional', 'subject' => $subject, 'body' => $body, 'active' => true]);
        }
    }
}
