<?php

namespace App\Modules\ServiceRequests\Application;

/**
 * Builds what the voice engine says, as a pure function (no database, no translator).
 * `text` is for the toast (digits); `speech` is for the synthesiser (numbers in words).
 *
 *   FR "Chambre deux cent quatre. Demande de serviettes."
 *   EN "Room two hundred four. Towels requested."
 */
final class SpokenText
{
    private const P = [
        'fr' => [
            'room' => 'Chambre', 'late' => 'En retard.', 'urgent' => 'Urgent.', 'manager' => 'Alerte responsable.',
            'message' => 'Message de la chambre', 'note' => 'Note :', 'and' => ' et ', 'minute' => 'minute', 'minutes' => 'minutes',
            'cat' => ['housekeeping' => 'ménage', 'maintenance' => 'maintenance', 'room_service' => 'service en chambre', 'reception' => 'réception', 'concierge' => 'conciergerie', 'laundry' => 'blanchisserie', 'other' => 'service'],
        ],
        'en' => [
            'room' => 'Room', 'late' => 'Overdue.', 'urgent' => 'Urgent.', 'manager' => 'Manager alert.',
            'message' => 'Message from room', 'note' => 'Note:', 'and' => ' and ', 'minute' => 'minute', 'minutes' => 'minutes',
            'cat' => ['housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'room_service' => 'Room service', 'reception' => 'Reception', 'concierge' => 'Concierge', 'laundry' => 'Laundry', 'other' => 'Service'],
        ],
    ];

    /**
     * @param  array{kind?:string,room_number:string,category?:string,items?:array,note?:?string,priority?:?string,minutes_late?:int}  $e
     * @return array{text:string,speech:string}
     */
    public static function build(string $locale, array $e): array
    {
        $locale = $locale === 'en' ? 'en' : 'fr';

        return ['text' => self::compose($locale, $e, false), 'speech' => self::compose($locale, $e, true)];
    }

    /** Room number read for the synthesiser: digit groups in words, leading zeros digit by digit, letters as they are. */
    public static function roomWords(string $room, string $locale): string
    {
        preg_match_all('/\d+|[^\d\s]+/u', trim($room), $m);
        $parts = [];
        foreach ($m[0] as $chunk) {
            if (! ctype_digit($chunk)) {
                $parts[] = mb_strtoupper($chunk);
            } elseif ((strlen($chunk) > 1 && $chunk[0] === '0') || strlen($chunk) > 4) {
                $parts[] = implode(' ', array_map(fn ($d) => NumberWords::of((int) $d, $locale), str_split($chunk)));
            } else {
                $parts[] = NumberWords::of((int) $chunk, $locale);
            }
        }

        return implode(' ', $parts) ?: $room;
    }

    private static function compose(string $l, array $e, bool $words): string
    {
        $p = self::P[$l];
        $kind = $e['kind'] ?? 'request';
        $room = $words ? self::roomWords((string) $e['room_number'], $l) : (string) $e['room_number'];

        if ($kind === 'message') {
            return "{$p['message']} $room.";
        }

        $what = self::what($l, $e, $words);
        if ($kind === 'overdue') {
            $mins = max(0, (int) ($e['minutes_late'] ?? 0));
            $n = $words ? NumberWords::of($mins, $l) : (string) $mins;
            $unit = $mins === 1 ? $p['minute'] : $p['minutes'];
            $what = mb_strtoupper(mb_substr($what, 0, 1)).mb_substr($what, 1);
            $tail = $l === 'fr' ? "$what en retard de $n $unit." : "$what overdue by $n $unit.";

            return "{$p['manager']} {$p['room']} $room. $tail";
        }

        $parts = [];
        if ($kind === 'late') {
            $parts[] = $p['late'];
        } elseif (($e['priority'] ?? 'normal') === 'urgent') {
            $parts[] = $p['urgent'];
        }
        $parts[] = "{$p['room']} $room.";
        $parts[] = self::request($l, $what, $e);
        if (($note = trim((string) ($e['note'] ?? ''))) !== '') {
            $parts[] = $p['note'].' '.mb_substr($note, 0, 100).(mb_strlen($note) > 100 ? '…' : '');
        }

        return implode(' ', $parts);
    }

    /** "serviettes", "deux serviettes et oreillers", or the category label when no item is given. */
    private static function what(string $l, array $e, bool $words): string
    {
        $labels = [];
        foreach ((array) ($e['items'] ?? []) as $item) {
            $code = is_array($item) ? (string) ($item['code'] ?? $item['label'] ?? '') : (string) $item;
            $qty = is_array($item) ? max(1, (int) ($item['qty'] ?? 1)) : 1;
            $label = Catalog::ITEMS[$code][$l === 'en' ? 2 : 1] ?? (is_array($item) ? (string) ($item['label'] ?? $code) : str_replace('_', ' ', $code));
            if ($label === '') {
                continue;
            }
            $labels[] = $qty > 1 ? ($words ? NumberWords::of($qty, $l) : $qty).' '.$label : $label;
        }
        if (! $labels) {
            return self::P[$l]['cat'][$e['category'] ?? 'other'] ?? self::P[$l]['cat']['other'];
        }
        $last = array_pop($labels);

        return $labels ? implode(', ', $labels).self::P[$l]['and'].$last : $last;
    }

    private static function request(string $l, string $what, array $e): string
    {
        if ($l === 'fr') {
            $de = preg_match('/^[aeiouyhàâéèêîôû]/iu', $what) ? "d'" : 'de ';

            return "Demande $de$what.";
        }
        $what = mb_strtoupper(mb_substr($what, 0, 1)).mb_substr($what, 1);
        $hasItems = (bool) array_filter((array) ($e['items'] ?? []));

        return $hasItems ? "$what requested." : "$what request.";
    }
}
