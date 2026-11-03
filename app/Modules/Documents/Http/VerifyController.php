<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\IssuedDocuments;
use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Public verification of an issued document (QR target). Unauthenticated and rate limited.
 * Reveals only: type, number, hotel name, issue time, amount (where relevant), status and
 * whether a presented content hash matches. Never names, lines or any personal data.
 */
class VerifyController
{
    /** Documents whose amount is personal (pay, contracts): never shown publicly. */
    private const NO_AMOUNT = ['payslip', 'econtract', 'work_certificate', 'loyalty_card', 'loyalty_statement'];

    public function __construct(private IssuedDocuments $issued) {}

    public function page(Request $request, string $code)
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fr';
        app()->setLocale($lang);
        $data = $this->data($code, (string) $request->query('h', ''));

        return Inertia::render('Verify/Show', [
            'result' => $data, 'code' => $code, 'lang' => $lang,
            'labels' => $this->labels(),
        ])->toResponse($request)->setStatusCode($data ? 200 : 404);
    }

    public function json(Request $request, string $code)
    {
        $data = $this->data($code, (string) $request->query('h', ''));

        return response()->json($data ?? ['status' => 'unknown'], $data ? 200 : 404, ['Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed>|null */
    private function data(string $code, string $presentedHash): ?array
    {
        $d = $this->issued->findByCode($code);
        if (! $d) {
            return null;
        }
        $hotel = Hotel::withoutGlobalScopes()->find($d->hotel_id);
        $tz = $hotel?->timezone ?: 'Africa/Douala';
        $h = strtolower(trim($presentedHash));
        $label = __('documents2.type.'.$d->type);

        return [
            'type' => $d->type, 'type_label' => $label === 'documents2.type.'.$d->type ? ucfirst(str_replace('_', ' ', $d->type)) : $label,
            'number' => $d->number, 'hotel' => $hotel?->name,
            'issued_at_utc' => $d->issued_at->utc()->toIso8601String(), 'issued_at_local' => $d->issued_at->copy()->setTimezone($tz)->format('Y-m-d H:i'), 'timezone' => $tz,
            'amount' => $d->amount !== null && ! in_array($d->type, self::NO_AMOUNT, true) ? $d->amount : null,
            'currency' => $d->amount !== null && ! in_array($d->type, self::NO_AMOUNT, true) ? $d->currency : null,
            'status' => $d->status, 'status_at' => $d->status !== 'valid' ? $d->status_at?->utc()->toIso8601String() : null,
            'replaced_by' => $d->status === 'replaced' && $d->replaced_by_id
                ? (($r = IssuedDocument::withoutGlobalScopes()->find($d->replaced_by_id)) ? ['number' => $r->number, 'code' => $r->code] : null) : null,
            'hash_match' => strlen($h) >= 8 && ctype_xdigit($h) ? str_starts_with($d->content_hash, $h) : null,
        ];
    }

    private function labels(): array
    {
        $out = [];
        foreach (['title', 'valid', 'voided', 'replaced', 'unknown', 'unknown_help', 'type', 'number', 'hotel', 'issued', 'amount', 'status',
            'hash', 'hash_ok', 'hash_bad', 'hash_none', 'hash_check', 'check', 'replaced_by', 'privacy', 'other_lang'] as $k) {
            $out[$k] = __('documents2.verify.'.$k);
        }

        return $out;
    }
}
