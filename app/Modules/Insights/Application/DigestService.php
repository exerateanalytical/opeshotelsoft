<?php

namespace App\Modules\Insights\Application;

use App\Modules\Insights\Domain\Digest;
use App\Modules\Insights\Domain\Setting;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Domain\NotificationTemplate;
use App\Platform\Tenancy\TenantContext;

/**
 * Owner daily digest for the active hotel: revenue, occupancy, ADR, cash by method and
 * top alerts, sent by email and WhatsApp through the Notifications module (one per date).
 */
class DigestService
{
    public const EVENT = 'insights.owner_digest';

    public function __construct(private TenantContext $ctx, private OwnerMetrics $metrics, private Notifier $notifier) {}

    public function send(string $date, bool $force = false): ?Digest
    {
        $hotelId = $this->ctx->requireHotelId();
        $s = Setting::first();
        if (! $s?->digest_enabled || (! $s->digest_emails && ! $s->digest_whatsapp)) {
            return null;
        }
        $existing = Digest::where('date', $date)->first();
        if ($existing?->sent_at && ! $force) {
            return $existing;
        }

        $m = $this->metrics->forHotel($hotelId, $date);
        $locale = $s->digest_locale === 'en' ? 'en' : 'fr';
        $this->ensureTemplates($locale);
        $text = $this->render($m, $locale);
        $vars = ['digest' => $text, 'date' => $date];
        $ref = ['type' => 'insight_digest'];

        $count = 0;
        foreach ($s->digest_emails as $email) {
            $count += count($this->notifier->notify(self::EVENT, ['email' => $email, 'locale' => $locale, 'name' => ''], $vars, $ref));
        }
        foreach ($s->digest_whatsapp as $phone) {
            // Only a WhatsApp template exists for phone recipients, so the transactional fallback picks WhatsApp.
            $count += count($this->notifier->notify(self::EVENT, ['phone' => $phone, 'locale' => $locale, 'name' => ''], $vars, $ref));
        }

        $digest = $existing ?? new Digest(['date' => $date]);
        $digest->fill(['payload' => $m + ['text' => $text], 'messages' => $count, 'sent_at' => now()])->save();

        return $digest;
    }

    public function render(array $m, string $locale): string
    {
        $t = fn (string $k, array $r = []) => __("insights.digest.$k", $r, $locale);
        $money = fn (int $v) => Evaluator::money($v);
        $lines = [
            $t('title', ['date' => $m['date']]),
            $t('revenue', ['amount' => $money($m['revenue'])]),
            $t('occupancy', ['pct' => $m['occupancy'], 'occupied' => $m['occupied'], 'rooms' => $m['rooms']]),
            $t('adr', ['amount' => $money($m['adr'])]),
            $t('cash'),
        ];
        foreach ($m['cash_by_method'] as $c) {
            $lines[] = "  - {$c['method']}: ".$money($c['amount']);
        }
        $lines[] = $t('alerts', ['count' => $m['open_alerts']]);
        foreach ($m['top_alerts'] as $a) {
            $lines[] = '  - ['.strtoupper($a['severity']).'] '.$a['title'].($a['amount'] ? ' ('.$money((int) $a['amount']).')' : '');
        }

        return implode("\n", $lines);
    }

    /** The hotel's digest templates (email + WhatsApp) are created on first use and stay editable in Notifications. */
    private function ensureTemplates(string $locale): void
    {
        $subject = __('insights.digest.subject', [], $locale).' {{date}}';
        foreach (['email', 'whatsapp'] as $channel) {
            NotificationTemplate::firstOrCreate(
                ['event' => self::EVENT, 'channel' => $channel, 'locale' => $locale],
                ['category' => 'transactional', 'subject' => $channel === 'email' ? $subject : null, 'body' => '{{digest}}', 'active' => true],
            );
        }
    }
}
