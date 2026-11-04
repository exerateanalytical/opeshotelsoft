<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentTemplate;
use App\Modules\Documents\Operations\HrTemplates;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Carbon;

/**
 * Safe template rendering. User text is NEVER compiled as Blade or PHP: the whole
 * body is HTML-escaped, then only whitelisted {{ group.key }} placeholders are
 * replaced (values escaped too), plus two formatting marks: "# " heading lines
 * and **bold**. Anything else stays literal text.
 */
final class TemplateRenderer
{
    public const PATTERN = '/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*\}\}/';

    private const HOTEL = ['hotel.name', 'hotel.legal_name', 'hotel.address', 'hotel.city', 'hotel.phone', 'hotel.email',
        'hotel.niu', 'hotel.rccm', 'hotel.bank_details', 'hotel.check_in_time', 'hotel.check_out_time', 'today'];

    private const GUEST = ['guest.name', 'guest.first_name', 'guest.last_name', 'guest.title', 'guest.email', 'guest.phone'];

    private const RESERVATION = ['reservation.number', 'reservation.arrival', 'reservation.departure', 'reservation.nights',
        'reservation.room_type', 'reservation.room', 'reservation.adults', 'reservation.children', 'reservation.total', 'reservation.currency'];

    private const INVOICE = ['invoice.number', 'invoice.date', 'invoice.total', 'invoice.currency', 'invoice.bill_to'];

    /** @return list<string> */
    public static function placeholders(string $kind): array
    {
        if (HrTemplates::is($kind)) {
            return HrTemplates::placeholders($kind);
        }

        return match ($kind) {
            'invoice' => [...self::HOTEL, ...self::INVOICE],
            'quote', 'confirmation', 'registration_card' => [...self::HOTEL, ...self::GUEST, ...self::RESERVATION],
            default => [...self::HOTEL, ...self::GUEST],
        };
    }

    /** Placeholders or template-like code in $body that are not allowed for $kind. @return list<string> */
    public static function unknown(string $kind, string $body): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', $body, $m);
        $bad = [];
        foreach ($m[1] as $inner) {
            if (! in_array(trim($inner), self::placeholders($kind), true)) {
                $bad[] = '{{'.$inner.'}}';
            }
        }
        if (preg_match_all('/\{!!|@php|@\w+\s*\(|<\?/', $body, $x)) {
            array_push($bad, ...$x[0]);
        }

        return array_values(array_unique($bad));
    }

    /** The hotel's template for $kind, or the default. @return array{title: string, body: string, custom: bool} */
    public static function template(string $kind, string $locale): array
    {
        $row = DocumentTemplate::where('kind', $kind)->where('locale', $locale)->first();

        return $row ? ['title' => (string) $row->title, 'body' => $row->body, 'custom' => true]
            : DefaultTemplates::get($kind, $locale) + ['custom' => false];
    }

    /** Render to safe HTML. */
    public static function render(string $kind, string $body, array $values): string
    {
        $allowed = array_flip(self::placeholders($kind));
        $html = e($body);
        $html = preg_replace_callback(self::PATTERN, fn ($m) => isset($allowed[$m[1]]) ? e((string) ($values[$m[1]] ?? '')) : '', $html);
        $html = preg_replace('/\{\{.*?\}\}/s', '', $html); // any leftover braces are dropped, never evaluated
        $html = preg_replace('/\*\*\s*\*\*/', '', $html);  // an empty bold value never prints as "****"
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
        $out = [];
        foreach (preg_split('/\R{2,}/', trim($html)) as $para) {
            $buf = [];
            foreach (preg_split('/\R/', $para) as $line) {
                if (str_starts_with($line, '# ')) {
                    if ($buf) {
                        $out[] = '<p>'.implode('<br>', $buf).'</p>';
                        $buf = [];
                    }
                    $out[] = '<h2>'.substr($line, 2).'</h2>';
                } else {
                    $buf[] = $line;
                }
            }
            if ($buf) {
                $out[] = '<p>'.implode('<br>', $buf).'</p>';
            }
        }

        return implode("\n", $out);
    }

    /** Render the hotel's (or default) template for a kind with the given values. */
    public static function renderFor(string $kind, string $locale, array $values): array
    {
        $tpl = self::template($kind, $locale);

        return ['title' => $tpl['title'], 'html' => self::render($kind, $tpl['body'], $values)];
    }

    /** Placeholder values from the resources (arrays as returned by the API). */
    public static function values(?Hotel $hotel, ?array $guest = null, ?array $reservation = null, ?array $invoice = null): array
    {
        $date = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';
        $money = fn ($v) => number_format((int) $v, 0, ',', ' ');
        $v = [
            'hotel.name' => $hotel?->name, 'hotel.legal_name' => $hotel?->legal_name ?: $hotel?->name, 'hotel.address' => $hotel?->address,
            'hotel.city' => $hotel?->city, 'hotel.phone' => $hotel?->phone, 'hotel.email' => $hotel?->email, 'hotel.niu' => $hotel?->tax_id,
            'hotel.rccm' => $hotel?->trade_register, 'hotel.bank_details' => $hotel?->bank_details,
            'hotel.check_in_time' => substr((string) ($hotel?->check_in_time ?? '14:00'), 0, 5),
            'hotel.check_out_time' => substr((string) ($hotel?->check_out_time ?? '12:00'), 0, 5),
            'today' => now()->format('d/m/Y'),
        ];
        if ($guest) {
            $v += [
                'guest.name' => trim(($guest['title'] ?? '').' '.($guest['first_name'] ?? '').' '.($guest['last_name'] ?? '')),
                'guest.first_name' => $guest['first_name'] ?? '', 'guest.last_name' => $guest['last_name'] ?? '', 'guest.title' => $guest['title'] ?? '',
                'guest.email' => $guest['email'] ?? '', 'guest.phone' => $guest['phone'] ?? '',
            ];
        }
        if ($reservation) {
            $v += [
                'reservation.number' => $reservation['number'] ?? '', 'reservation.arrival' => $date($reservation['arrival'] ?? null),
                'reservation.departure' => $date($reservation['departure'] ?? null),
                'reservation.nights' => isset($reservation['arrival'], $reservation['departure'])
                    ? (int) Carbon::parse($reservation['arrival'])->diffInDays(Carbon::parse($reservation['departure'])) : '',
                'reservation.room_type' => $reservation['room_type']['name'] ?? '', 'reservation.room' => $reservation['room']['number'] ?? '',
                'reservation.adults' => $reservation['adults'] ?? '', 'reservation.children' => $reservation['children'] ?? '',
                'reservation.total' => $money($reservation['total_amount'] ?? 0), 'reservation.currency' => $reservation['currency'] ?? 'XAF',
            ];
        }
        if ($invoice) {
            $v += [
                'invoice.number' => $invoice['number'] ?? '', 'invoice.date' => $date($invoice['issue_date'] ?? null),
                'invoice.total' => $money($invoice['total'] ?? 0), 'invoice.currency' => $invoice['currency'] ?? 'XAF', 'invoice.bill_to' => $invoice['bill_to_name'] ?? '',
            ];
        }

        return $v;
    }

    /** Sample values for the live preview. */
    public static function sample(?Hotel $hotel): array
    {
        return HrTemplates::sample() + self::values($hotel,
            ['title' => 'M.', 'first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'jean.mballa@example.cm', 'phone' => '+237 677 00 00 00'],
            ['number' => 'R-000123', 'arrival' => now()->addDays(7)->toDateString(), 'departure' => now()->addDays(9)->toDateString(),
                'room_type' => ['name' => 'Deluxe'], 'room' => ['number' => '204'], 'adults' => 2, 'children' => 0, 'total_amount' => 130000, 'currency' => 'XAF'],
            ['number' => 'FA-2026-000045', 'issue_date' => now()->toDateString(), 'total' => 130000, 'currency' => 'XAF', 'bill_to_name' => 'Société Exemple SA']);
    }
}
