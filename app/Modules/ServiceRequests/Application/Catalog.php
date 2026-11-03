<?php

namespace App\Modules\ServiceRequests\Application;

use App\Modules\ServiceRequests\Domain\Setting;

/** Categories, the department each one belongs to, default SLA minutes and the common item codes. */
final class Catalog
{
    public const DEPARTMENTS = ['housekeeping', 'maintenance', 'fnb', 'front_office'];

    /** category => [department, default SLA minutes] */
    public const CATEGORIES = [
        'housekeeping' => ['housekeeping', 30],
        'maintenance' => ['maintenance', 60],
        'room_service' => ['fnb', 30],
        'reception' => ['front_office', 10],
        'concierge' => ['front_office', 30],
        'laundry' => ['housekeeping', 120],
        'other' => ['front_office', 60],
    ];

    public const SOURCES = ['member_portal', 'whatsapp', 'reception', 'room_phone', 'staff'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Item code => [category, FR label, EN label]. Labels are plural or mass nouns so "Demande de ..." reads naturally. */
    public const ITEMS = [
        'towels' => ['housekeeping', 'serviettes', 'towels'],
        'pillows' => ['housekeeping', 'oreillers', 'pillows'],
        'blankets' => ['housekeeping', 'couvertures', 'blankets'],
        'soap' => ['housekeeping', 'savon', 'soap'],
        'shampoo' => ['housekeeping', 'shampoing', 'shampoo'],
        'toilet_paper' => ['housekeeping', 'papier toilette', 'toilet paper'],
        'cleaning' => ['housekeeping', 'ménage', 'cleaning'],
        'extra_bed' => ['housekeeping', 'lit supplémentaire', 'an extra bed'],
        'water' => ['housekeeping', 'eau', 'water'],
        'iron' => ['housekeeping', 'fer à repasser', 'an iron'],
        'ac' => ['maintenance', 'climatisation', 'air conditioning'],
        'light' => ['maintenance', 'éclairage', 'lighting'],
        'plumbing' => ['maintenance', 'plomberie', 'plumbing'],
        'tv' => ['maintenance', 'télévision', 'television'],
        'wifi' => ['maintenance', 'connexion wifi', 'wifi'],
        'breakfast' => ['room_service', 'petit-déjeuner', 'breakfast'],
        'meal' => ['room_service', 'repas', 'a meal'],
        'drinks' => ['room_service', 'boissons', 'drinks'],
        'ice' => ['room_service', 'glaçons', 'ice'],
        'call_reception' => ['reception', 'appel de la réception', 'a call from reception'],
        'taxi' => ['concierge', 'taxi', 'a taxi'],
        'wake_up' => ['concierge', 'réveil', 'a wake-up call'],
        'laundry_pickup' => ['laundry', 'collecte de linge', 'laundry pickup'],
    ];

    public static function department(string $category): string
    {
        return self::CATEGORIES[$category][0];
    }

    /** SLA minutes per category for the hotel in context (defaults overlaid with the hotel's own values). */
    public static function sla(): array
    {
        $own = Setting::first()?->sla ?? [];
        $out = [];
        foreach (self::CATEGORIES as $category => [, $default]) {
            $out[$category] = max(1, (int) ($own[$category] ?? $default));
        }

        return $out;
    }

    public static function defaultSla(): array
    {
        return array_map(fn ($c) => $c[1], self::CATEGORIES);
    }
}
