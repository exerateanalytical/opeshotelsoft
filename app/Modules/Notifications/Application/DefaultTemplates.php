<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\NotificationTemplate;

/** Built-in FR/EN templates, seeded into each hotel on first use and used as fallback when no hotel is known. */
final class DefaultTemplates
{
    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    /** event => [category, [locale => [subject, email body, short body (sms/whatsapp)]]] */
    public static function all(): array
    {
        return [
            'reservation.created' => ['transactional', [
                'fr' => ['Confirmation de réservation {{number}}',
                    "Bonjour {{guest_name}},\n\nNous confirmons votre réservation {{number}} à {{hotel_name}} du {{arrival}} au {{departure}}.\nRéférence de réservation (à conserver) : {{reference}}\nMontant : {{total_amount}} {{currency}}.\n\nÀ bientôt,\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}} : réservation {{number}} confirmée du {{arrival}} au {{departure}}.'],
                'en' => ['Booking confirmation {{number}}',
                    "Hello {{guest_name}},\n\nWe confirm your booking {{number}} at {{hotel_name}} from {{arrival}} to {{departure}}.\nBooking reference (keep it): {{reference}}\nAmount: {{total_amount}} {{currency}}.\n\nSee you soon,\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}}: booking {{number}} confirmed from {{arrival}} to {{departure}}.'],
            ]],
            'reservation.cancelled' => ['transactional', [
                'fr' => ['Annulation de la réservation {{number}}',
                    "Bonjour {{guest_name}},\n\nVotre réservation {{number}} du {{arrival}} au {{departure}} a été annulée.\n\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}} : réservation {{number}} annulée.'],
                'en' => ['Booking {{number}} cancelled',
                    "Hello {{guest_name}},\n\nYour booking {{number}} from {{arrival}} to {{departure}} has been cancelled.\n\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}}: booking {{number}} cancelled.'],
            ]],
            'reservation.pre_arrival' => ['transactional', [
                'fr' => ['Votre arrivée demain à {{hotel_name}}',
                    "Bonjour {{guest_name}},\n\nNous vous attendons demain {{arrival}} à {{hotel_name}} (réservation {{number}}). Enregistrement à partir de {{check_in_time}}.\n\n{{hotel_phone}}",
                    '{{hotel_name}} : nous vous attendons demain ({{arrival}}), réservation {{number}}.'],
                'en' => ['Your arrival tomorrow at {{hotel_name}}',
                    "Hello {{guest_name}},\n\nWe look forward to welcoming you tomorrow {{arrival}} at {{hotel_name}} (booking {{number}}). Check-in from {{check_in_time}}.\n\n{{hotel_phone}}",
                    '{{hotel_name}}: see you tomorrow ({{arrival}}), booking {{number}}.'],
            ]],
            'reservation.deposit_reminder' => ['transactional', [ // R-B deposit automation
                'fr' => ['Acompte à régler pour la réservation {{number}}',
                    "Bonjour {{guest_name}},\n\nL'acompte de {{amount_due}} {{currency}} pour votre réservation {{number}} est à régler avant le {{deadline}}, faute de quoi la chambre sera remise en vente.\nPayer : {{pay_url}}\n\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}} : acompte {{amount_due}} {{currency}} à régler avant le {{deadline}} (réservation {{number}}). {{pay_url}}'],
                'en' => ['Deposit due for booking {{number}}',
                    "Hello {{guest_name}},\n\nThe deposit of {{amount_due}} {{currency}} for your booking {{number}} is due before {{deadline}}, otherwise the room will be released.\nPay: {{pay_url}}\n\n{{hotel_name}} {{hotel_phone}}",
                    '{{hotel_name}}: deposit {{amount_due}} {{currency}} due before {{deadline}} (booking {{number}}). {{pay_url}}'],
            ]],
            'invoice.issued' => ['transactional', [
                'fr' => ['Facture {{number}}',
                    "Bonjour {{guest_name}},\n\nVeuillez trouver votre facture {{number}} d'un montant de {{total}} {{currency}}.\n\n{{hotel_name}}",
                    '{{hotel_name}} : facture {{number}} de {{total}} {{currency}} émise.'],
                'en' => ['Invoice {{number}}',
                    "Hello {{guest_name}},\n\nYour invoice {{number}} for {{total}} {{currency}} has been issued.\n\n{{hotel_name}}",
                    '{{hotel_name}}: invoice {{number}} for {{total}} {{currency}} issued.'],
            ]],
            'guest_portal.otp' => ['transactional', [
                'fr' => ['Votre code de connexion', "Votre code de connexion est {{code}}. Il expire dans {{minutes}} minutes.", 'Code {{hotel_name}} : {{code}} (valable {{minutes}} min).'],
                'en' => ['Your sign-in code', "Your sign-in code is {{code}}. It expires in {{minutes}} minutes.", '{{hotel_name}} code: {{code}} (valid {{minutes}} min).'],
            ]],
            'staff.password_reset' => ['transactional', [
                'fr' => ['Réinitialisation du mot de passe', "Bonjour {{name}},\n\nPour choisir un nouveau mot de passe, ouvrez ce lien : {{url}}\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.", null],
                'en' => ['Password reset', "Hello {{name}},\n\nTo choose a new password, open this link: {{url}}\nIf you did not request this, ignore this message.", null],
            ]],
            'marketing.offer' => ['marketing', [
                'fr' => ['{{title}}', "Bonjour {{guest_name}},\n\n{{message}}\n\n{{hotel_name}}", '{{hotel_name}} : {{message}}'],
                'en' => ['{{title}}', "Hello {{guest_name}},\n\n{{message}}\n\n{{hotel_name}}", '{{hotel_name}}: {{message}}'],
            ]],
        ];
    }

    public static function events(): array
    {
        return array_keys(self::all());
    }

    public static function category(string $event): string
    {
        return self::all()[$event][0] ?? 'transactional';
    }

    /** @return array{category:string,subject:?string,body:string}|null */
    public static function find(string $event, string $channel, string $locale): ?array
    {
        $def = self::all()[$event] ?? null;
        $t = $def[1][$locale] ?? null;
        if (! $t) {
            return null;
        }
        $body = $channel === 'email' ? $t[1] : $t[2];

        return $body === null ? null : ['category' => $def[0], 'subject' => $channel === 'email' ? $t[0] : null, 'body' => $body];
    }

    /** Seed missing default rows for the hotel in context. */
    public static function ensureForCurrentHotel(): void
    {
        $existing = NotificationTemplate::get(['event', 'channel', 'locale'])
            ->map(fn ($t) => "$t->event|$t->channel|$t->locale")->flip();
        foreach (self::all() as $event => [$category, $locales]) {
            foreach ($locales as $locale => $_) {
                foreach (self::CHANNELS as $channel) {
                    $tpl = self::find($event, $channel, $locale);
                    if ($tpl && ! isset($existing["$event|$channel|$locale"])) {
                        NotificationTemplate::create(['event' => $event, 'channel' => $channel, 'locale' => $locale] + $tpl);
                    }
                }
            }
        }
    }
}
