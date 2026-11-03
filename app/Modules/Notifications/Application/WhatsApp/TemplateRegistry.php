<?php

namespace App\Modules\Notifications\Application\WhatsApp;

use App\Modules\Notifications\Domain\WhatsApp\WhatsAppTemplate;

/**
 * Built-in WhatsApp templates (FR/EN) for each journey step, seeded per hotel.
 * Bodies use Meta's positional {{1}}..{{n}} placeholders; `params` names the variables.
 * Each row carries its Meta approval status: only `approved` templates are sent live.
 */
final class TemplateRegistry
{
    /** journey => [name, category, params, [locale => body]] */
    public const DEFAULTS = [
        'booking_confirmation' => ['booking_confirmation', 'utility', ['guest_name', 'hotel_name', 'number', 'arrival', 'departure', 'manage_url'], [
            'fr' => 'Bonjour {{1}}, votre réservation {{3}} à {{2}} est confirmée du {{4}} au {{5}}. Gérer votre réservation : {{6}}',
            'en' => 'Hello {{1}}, your booking {{3}} at {{2}} is confirmed from {{4}} to {{5}}. Manage your booking: {{6}}',
        ]],
        'pre_arrival' => ['pre_arrival_checkin', 'utility', ['guest_name', 'hotel_name', 'arrival', 'checkin_url'], [
            'fr' => 'Bonjour {{1}}, {{2}} vous attend le {{3}}. Gagnez du temps : faites votre enregistrement en ligne ici {{4}}',
            'en' => 'Hello {{1}}, {{2}} looks forward to welcoming you on {{3}}. Save time: check in online here {{4}}',
        ]],
        'welcome' => ['welcome_in_stay', 'utility', ['guest_name', 'hotel_name', 'room', 'order_url'], [
            'fr' => 'Bienvenue {{1}} à {{2}} ! Vous êtes en chambre {{3}}. Commandez au room service depuis votre téléphone : {{4}}. Répondez à ce message pour toute demande.',
            'en' => 'Welcome {{1}} to {{2}}! You are in room {{3}}. Order room service from your phone: {{4}}. Reply to this message for any request.',
        ]],
        'bill' => ['bill_before_checkout', 'utility', ['guest_name', 'balance', 'currency', 'pay_url'], [
            'fr' => 'Bonjour {{1}}, votre départ est prévu aujourd’hui. Solde de votre note : {{2}} {{3}}. Payer en ligne : {{4}}',
            'en' => 'Hello {{1}}, your departure is today. Your bill balance: {{2}} {{3}}. Pay online: {{4}}',
        ]],
        'review' => ['post_stay_review', 'utility', ['guest_name', 'hotel_name', 'review_url'], [
            'fr' => 'Merci {{1}} pour votre séjour à {{2}}. Votre avis compte : {{3}}',
            'en' => 'Thank you {{1}} for staying at {{2}}. We would love your review: {{3}}',
        ]],
        'order_confirmation' => ['order_confirmation', 'utility', ['guest_name', 'number', 'total', 'currency'], [
            'fr' => 'Merci {{1}}, votre commande {{2}} ({{3}} {{4}}) est en préparation. Elle est ajoutée à votre note.',
            'en' => 'Thank you {{1}}, your order {{2}} ({{3}} {{4}}) is being prepared. It has been added to your bill.',
        ]],
        'opt_out' => ['opt_out_confirmation', 'utility', [], [
            'fr' => 'Vous ne recevrez plus de messages WhatsApp de notre part. Envoyez START pour vous réabonner.',
            'en' => 'You will no longer receive WhatsApp messages from us. Send START to subscribe again.',
        ]],
    ];

    public static function ensureForCurrentHotel(): void
    {
        $have = WhatsAppTemplate::get(['name', 'locale'])->map(fn ($t) => "$t->name|$t->locale")->flip();
        foreach (self::DEFAULTS as $journey => [$name, $category, $params, $bodies]) {
            foreach ($bodies as $locale => $body) {
                if (! isset($have["$name|$locale"])) {
                    WhatsAppTemplate::create(compact('journey', 'name', 'locale', 'category', 'body', 'params') + ['meta_status' => 'draft']);
                }
            }
        }
    }

    public static function find(string $journey, string $locale): ?WhatsAppTemplate
    {
        self::ensureForCurrentHotel();

        return WhatsAppTemplate::where('journey', $journey)->where('locale', $locale)->first()
            ?? WhatsAppTemplate::where('journey', $journey)->where('locale', 'fr')->first();
    }

    /** @return list<string> */
    public static function params(WhatsAppTemplate $t, array $vars): array
    {
        return array_map(fn ($k) => (string) ($vars[$k] ?? ''), $t->params ?? []);
    }

    public static function render(WhatsAppTemplate $t, array $vars): string
    {
        $p = self::params($t, $vars);

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $p[(int) $m[1] - 1] ?? '', $t->body);
    }
}
