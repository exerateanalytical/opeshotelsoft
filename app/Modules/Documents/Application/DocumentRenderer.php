<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Support\QrCode;
use App\Platform\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * The document engine. render($type, $data, $options) issues the document (record, number, verify code,
 * content hash), then renders the blade view with the shared layout: letterhead design, QR, issue-time
 * footer, "Duplicata n" mark and platform watermark. See docs/DOCUMENTS.md.
 */
final class DocumentRenderer
{
    /** size => dompdf paper */
    public const SIZES = [
        'a4' => ['a4', 'portrait'],
        'a4-landscape' => ['a4', 'landscape'],
        '80mm' => [[0, 0, 226.77, 566.93], 'portrait'],
        'id1' => [[0, 0, 242.65, 153.07], 'portrait'],
    ];

    public function __construct(private IssuedDocuments $issued, private TenantContext $ctx) {}

    /**
     * @param  array  $data  view data (also what the SHA-256 is computed over)
     * @param  array{view?: string, hotel?: ?Hotel, lang?: string, size?: string, number?: ?string, source_type?: ?string, source_id?: ?int, amount?: ?int, currency?: ?string, title?: ?string, issue?: bool, draft?: bool, design?: ?string, filename?: ?string}  $options
     */
    public function render(string $type, array $data, array $options = []): RenderedDocument
    {
        $hotel = $options['hotel'] ?? ($this->ctx->hotelId() ? Hotel::withoutGlobalScopes()->find($this->ctx->hotelId()) : null);
        $lang = ($options['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';
        $size = isset(self::SIZES[$options['size'] ?? 'a4']) ? ($options['size'] ?? 'a4') : 'a4';
        $number = (string) ($options['number'] ?? '');
        $issue = ($options['issue'] ?? true) && $hotel;
        $record = null;
        $copy = 0;
        if ($issue) {
            $number = $number !== '' ? $number : strtoupper(substr($type, 0, 3)).'-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(2)));
            ['record' => $record, 'copy' => $copy] = $this->issued->issue([
                'type' => $type, 'number' => $number, 'hotel' => $hotel, 'source_type' => $options['source_type'] ?? null,
                'source_id' => $options['source_id'] ?? null, 'data' => $data, 'amount' => $options['amount'] ?? null,
                'currency' => $options['currency'] ?? null, 'locale' => $lang, 'user_id' => auth()->id(),
            ]);
            $number = $record->number;
        }

        app()->setLocale($lang);
        $t = fn (string $fr, string $en) => $lang === 'en' ? $en : $fr;
        $engine = $this->engine($type, $hotel, $lang, $size, $record, $copy, $number, $options);
        $view = $options['view'] ?? 'pdf.'.$type;
        $pdf = Pdf::loadView($view, $data + ['hotel' => $hotel, 'lang' => $lang, 't' => $t, 'engine' => $engine])
            ->setPaper(...self::SIZES[$size]);

        return new RenderedDocument(
            $pdf->output(), $record, $copy, $engine,
            preg_replace('/[^\w.-]/', '_', (string) ($options['filename'] ?? ($type.'-'.($number ?: 'doc')))).'.pdf'
        );
    }

    /** Layout data without an issued record (previews, or views rendered outside render()). */
    public static function bareEngine(?Hotel $hotel, string $lang = 'fr'): array
    {
        return (new self(app(IssuedDocuments::class), app(TenantContext::class)))
            ->engine('document', $hotel, $lang, 'a4', null, 0, '', ['issue' => false]);
    }

    private function engine(string $type, ?Hotel $hotel, string $lang, string $size, ?IssuedDocument $record, int $copy, string $number, array $options): array
    {
        $lh = Letterhead::for($hotel);
        if (! empty($options['design']) && in_array($options['design'], Letterhead::DESIGNS, true)) {
            $lh['design'] = $options['design'];
        }
        $tz = $hotel?->timezone ?: 'Africa/Douala';
        $issuedAt = ($record?->issued_at ?? now())->copy();
        $url = $short = $qr = null;
        if ($record) {
            $short = '/verify/'.$record->code;
            $url = url($short);
            $text = $url.'?h='.substr($record->content_hash, 0, 12);
            if (strlen($text) > 104) {
                $text = $url;
            }
            try {
                $qr = 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($text, 4, 2));
            } catch (\InvalidArgumentException) {
                $qr = null; // very long host name: the printed code remains
            }
            $short = preg_replace('#^https?://#', '', $url);
        }
        $label = __('documents2.type.'.$type);

        return [
            'size' => $size, 'design' => $lh['design'], 'lh' => $lh, 'qr' => $qr, 'verify_url' => $url, 'verify_url_short' => $short,
            'stamp_local' => $issuedAt->setTimezone($tz)->format('d/m/Y H:i'), 'stamp_utc' => $issuedAt->utc()->format('d/m/Y H:i'),
            'tz' => $tz, 'hash_short' => $record ? substr($record->content_hash, 0, 16) : null, 'copy' => $copy, 'number' => $number,
            'type_label' => $label === 'documents2.type.'.$type ? ucfirst(str_replace(['_', '-'], ' ', $type)) : $label,
            'title' => (string) ($options['title'] ?? ''), 'code' => $record?->code,
            'watermark' => Watermark::forRender($hotel, (bool) ($options['draft'] ?? ! $record), $copy),
        ];
    }

    public function response(RenderedDocument $doc): Response
    {
        return response($doc->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
