<?php

namespace App\Modules\Property\FastConfigure\Application;

/** One-click starting points for Fast Configure (realistic XAF prices, Cameroon market). */
final class Presets
{
    public const KEYS = ['auberge-20', 'eco-3-40', 'business-4-120', 'resort-5-80'];

    /** @return array<string, array{label: string, rooms: int, hotel: array}> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $c = self::get($key);
            $out[$key] = ['label' => $c['preset_label'], 'rooms' => Generator::roomTotal($c), 'hotel' => $c['hotel']['create']];
        }

        return $out;
    }

    public static function get(string $key): array
    {
        return match ($key) {
            'auberge-20' => self::auberge(),
            'eco-3-40' => self::eco(),
            'business-4-120' => self::business(),
            'resort-5-80' => self::resort(),
            default => throw new \InvalidArgumentException("Unknown preset $key"),
        };
    }

    public static function plans(int $bb = 12, int $nrf = -10, int $mem = -15, int $corp = -20, bool $all = true): array
    {
        return [
            ['code' => 'BAR', 'name' => 'Meilleur tarif disponible', 'enabled' => true, 'percent' => 0, 'meal_plan' => 'RO'],
            ['code' => 'BB', 'name' => 'Chambre + petit-déjeuner', 'enabled' => true, 'percent' => $bb, 'meal_plan' => 'BB'],
            ['code' => 'NRF', 'name' => 'Non remboursable', 'enabled' => $all, 'percent' => $nrf, 'meal_plan' => 'RO', 'refundable' => false],
            ['code' => 'MEM', 'name' => 'Tarif membres', 'enabled' => $all, 'percent' => $mem, 'meal_plan' => 'RO', 'members_only' => true],
            ['code' => 'CORP', 'name' => 'Tarif entreprise', 'enabled' => $all, 'percent' => $corp, 'meal_plan' => 'RO', 'public' => false],
        ];
    }

    private static function type(string $code, string $name, int $ad, int $ch, array $beds, int $size, string $view, int $share, int $price, int $xa, int $xc, array $amen, string $assign = 'spread', int $wk = 0, int $hs = 0): array
    {
        return [
            'code' => $code, 'name' => $name, 'share_mode' => 'percent', 'share' => $share, 'max_adults' => $ad, 'max_children' => $ch,
            'beds' => $beds, 'size' => $size, 'view' => $view, 'smoking' => false, 'amenities' => $amen, 'amenities_extra' => '',
            'base_price' => $price, 'extra_adult' => $xa, 'extra_child' => $xc, 'weekend_uplift' => $wk, 'high_season_uplift' => $hs, 'assign' => $assign,
        ];
    }

    private static function base(string $label, string $name, string $city, int $stars, array $floors, array $types, array $plans, array $outlets): array
    {
        return [
            'preset_label' => $label,
            'hotel' => ['create' => ['name' => $name, 'city' => $city, 'stars' => $stars]],
            'floors' => $floors,
            'numbering' => ['pattern' => 'floor100', 'prefix' => '', 'pad' => 0, 'skip_13' => false, 'skip' => []],
            'types' => $types, 'rate_plans' => $plans, 'outlets' => $outlets,
            'options' => ['update_existing' => false, 'remove_missing' => false],
        ];
    }

    private static function auberge(): array
    {
        return self::base('Auberge 20 chambres', 'Auberge Le Repos', 'Bafoussam', 2,
            ['mode' => 'numeric', 'count' => 2, 'default_rooms' => 10, 'per_floor' => []],
            [
                self::type('STD', 'Chambre ventilée', 2, 1, [['type' => 'double', 'count' => 1]], 14, '', 50, 15_000, 3_000, 2_000, ['wifi', 'fan', 'tv']),
                self::type('CLM', 'Chambre climatisée', 2, 1, [['type' => 'double', 'count' => 1]], 18, '', 35, 22_000, 5_000, 3_000, ['wifi', 'ac', 'tv', 'kettle'], 'spread', 10),
                self::type('FAM', 'Chambre familiale', 4, 2, [['type' => 'double', 'count' => 1], ['type' => 'single', 'count' => 2]], 26, '', 15, 32_000, 6_000, 3_000, ['wifi', 'ac', 'tv'], 'ground'),
            ],
            array_map(fn ($p) => array_merge($p, ['enabled' => in_array($p['code'], ['BAR', 'BB', 'NRF'], true)]), self::plans()),
            ['enabled' => false, 'items' => []],
        );
    }

    private static function eco(): array
    {
        return self::base('Hôtel économique 3 étoiles 40 chambres', 'Hôtel Étoile du Centre', 'Yaoundé', 3,
            ['mode' => 'numeric', 'count' => 4, 'default_rooms' => 10, 'per_floor' => []],
            [
                self::type('STD', 'Chambre Standard', 2, 1, [['type' => 'double', 'count' => 1]], 18, '', 40, 35_000, 8_000, 4_000, ['wifi', 'ac', 'tv', 'desk'], 'spread', 10),
                self::type('TWN', 'Chambre Twin', 2, 0, [['type' => 'twin', 'count' => 2]], 20, '', 25, 38_000, 8_000, 4_000, ['wifi', 'ac', 'tv', 'desk'], 'spread', 10),
                self::type('DLX', 'Chambre Deluxe', 2, 1, [['type' => 'king', 'count' => 1]], 24, 'city', 25, 52_000, 10_000, 5_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'balcony'], 'top', 10),
                self::type('FAM', 'Chambre Familiale', 4, 2, [['type' => 'double', 'count' => 1], ['type' => 'twin', 'count' => 2]], 32, '', 10, 65_000, 12_000, 6_000, ['wifi', 'ac', 'tv', 'minibar'], 'ground', 10),
            ],
            self::plans(),
            ['enabled' => true, 'items' => ['restaurant', 'bar', 'kitchen', 'main_store']],
        );
    }

    private static function business(): array
    {
        return self::base('Hôtel affaires 4 étoiles 120 chambres', 'Hôtel Prestige Affaires', 'Douala', 4,
            ['mode' => 'numeric', 'count' => 8, 'default_rooms' => 15, 'per_floor' => []],
            [
                self::type('STD', 'Chambre Standard', 2, 1, [['type' => 'queen', 'count' => 1]], 22, 'city', 40, 65_000, 15_000, 8_000, ['wifi', 'ac', 'tv', 'desk', 'safe', 'kettle'], 'spread', 10),
                self::type('TWN', 'Chambre Twin', 2, 0, [['type' => 'twin', 'count' => 2]], 24, 'city', 20, 68_000, 15_000, 8_000, ['wifi', 'ac', 'tv', 'desk', 'safe', 'kettle'], 'spread', 10),
                self::type('DLX', 'Chambre Deluxe', 2, 1, [['type' => 'king', 'count' => 1]], 30, 'city', 25, 85_000, 18_000, 9_000, ['wifi', 'ac', 'tv', 'desk', 'safe', 'minibar', 'bathtub'], 'spread', 10),
                self::type('EXE', 'Chambre Exécutive', 2, 1, [['type' => 'king', 'count' => 1]], 36, 'city', 10, 110_000, 20_000, 10_000, ['wifi', 'ac', 'tv', 'desk', 'safe', 'minibar', 'bathtub', 'kettle'], 'top', 10),
                self::type('STE', 'Suite', 3, 2, [['type' => 'king', 'count' => 1], ['type' => 'sofa', 'count' => 1]], 55, 'city', 5, 220_000, 25_000, 12_000, ['wifi', 'ac', 'tv', 'desk', 'safe', 'minibar', 'bathtub', 'kitchenette', 'balcony'], 'top', 10),
            ],
            self::plans(),
            ['enabled' => true, 'items' => ['restaurant', 'bar', 'kitchen', 'main_store', 'housekeeping_store', 'maintenance_store']],
        );
    }

    private static function resort(): array
    {
        return self::base('Resort 5 étoiles 80 chambres', 'Resort Océan Bleu', 'Kribi', 5,
            ['mode' => 'numeric', 'count' => 4, 'default_rooms' => 20, 'per_floor' => []],
            [
                self::type('STD', 'Chambre Supérieure', 2, 1, [['type' => 'queen', 'count' => 1]], 30, 'garden', 30, 95_000, 20_000, 10_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'balcony'], 'spread', 15, 30),
                self::type('DLX', 'Deluxe vue mer', 2, 2, [['type' => 'king', 'count' => 1]], 38, 'sea', 35, 130_000, 25_000, 12_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'balcony', 'sea_view', 'bathtub'], 'top', 15, 30),
                self::type('FAM', 'Chambre Familiale', 4, 2, [['type' => 'king', 'count' => 1], ['type' => 'twin', 'count' => 2]], 50, 'garden', 15, 180_000, 30_000, 15_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'kitchenette'], 'ground', 15, 30),
                self::type('STE', 'Suite Océan', 3, 2, [['type' => 'king', 'count' => 1], ['type' => 'sofa', 'count' => 1]], 70, 'sea', 15, 280_000, 35_000, 18_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'balcony', 'sea_view', 'bathtub', 'kitchenette'], 'top', 15, 30),
                self::type('VIL', 'Villa privée', 4, 2, [['type' => 'king', 'count' => 2]], 110, 'sea', 5, 450_000, 40_000, 20_000, ['wifi', 'ac', 'tv', 'minibar', 'safe', 'sea_view', 'bathtub', 'kitchenette', 'balcony'], 'ground', 15, 30),
            ],
            self::plans(15, -10, -15, -20),
            ['enabled' => true, 'items' => ['restaurant', 'bar', 'kitchen', 'main_store', 'housekeeping_store', 'maintenance_store']],
        );
    }
}
