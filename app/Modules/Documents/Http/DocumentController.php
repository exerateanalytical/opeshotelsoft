<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\InternalApi;
use App\Modules\Documents\Application\NumberToWords;
use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Application\Payroll\PayslipLabels;
use App\Modules\HR\Domain\Payslip;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PDF documents. Each one first loads its resource through the existing API
 * endpoint (InternalApi), so the permission and tenant checks are exactly
 * those of the underlying resource.
 */
class DocumentController
{
    public function __construct(private InternalApi $api, private TenantContext $ctx, private DocumentRenderer $engine) {}

    public function invoice(Request $request, int $invoice)
    {
        $inv = $this->api->get("invoices/$invoice");
        // The invoice is a legal document (OHADA / DGI): always issued in French.
        $lang = 'fr';

        return $this->pdf('invoice', 'invoice-'.$inv['number'], $lang, [
            'invoice' => $inv,
            'words' => NumberToWords::convert((int) $inv['total'], $lang),
            'net' => (int) $inv['total'] - (int) $inv['tax_total'],
            'templateNote' => TemplateRenderer::renderFor('invoice', 'fr', TemplateRenderer::values($this->hotel(), null, null, $inv))['html'],
        ], ['number' => $inv['number'], 'source_id' => $invoice, 'amount' => (int) $inv['total'], 'currency' => $inv['currency'] ?? 'XAF',
            'title' => (($inv['kind'] ?? 'invoice') === 'credit_note' ? 'Avoir' : 'Facture').' '.$inv['number']]);
    }

    public function folio(Request $request, int $folio)
    {
        $f = $this->api->get("folios/$folio");
        $lang = $this->lang($request, $f['guest_id'] ?? null);

        return $this->pdf('folio', 'folio-'.($f['number'] ?? $folio), $lang, ['folio' => $f], [
            'number' => (string) ($f['number'] ?? $folio), 'source_id' => $folio, 'amount' => (int) ($f['balance'] ?? 0), 'currency' => $f['currency'] ?? 'XAF',
            'title' => $lang === 'en' ? 'Folio statement' : 'Relevé de folio',
        ]);
    }

    public function registrationCard(Request $request, int $reservation)
    {
        $card = $this->api->get("reservations/$reservation/registration-card");
        $signature = null;
        if (! empty($card['has_signature'])) {
            $res = $this->api->raw("reservations/$reservation/registration-card/signature");
            $bin = $res instanceof BinaryFileResponse
                ? file_get_contents($res->getFile()->getPathname())
                : (string) $res->getContent();
            if ($bin === '' && method_exists($res, 'sendContent')) {
                ob_start();
                $res->sendContent();
                $bin = (string) ob_get_clean();
            }
            $signature = $bin !== '' ? 'data:image/png;base64,'.base64_encode($bin) : null;
        }
        $lang = $this->lang($request, $card['guest_id'] ?? null);

        $note = TemplateRenderer::renderFor('registration_card', $lang, TemplateRenderer::values($this->hotel(), $card))['html'];

        return $this->pdf('registration-card', 'registration-card-'.$reservation, $lang, ['card' => $card, 'signature' => $signature, 'templateNote' => $note], [
            'number' => 'RC-'.($card['reservation_number'] ?? $card['number'] ?? $reservation), 'source_id' => $reservation,
            'title' => $lang === 'en' ? 'Registration card' : 'Fiche d\'arrivée',
        ]);
    }

    public function payslip(Request $request, int $payslip)
    {
        $slip = $this->api->get("payroll/payslips/$payslip");
        $lang = in_array($request->query('lang'), ['fr', 'en'], true) ? $request->query('lang') : ($slip['language'] ?? 'fr');
        if ($lang !== ($slip['language'] ?? 'fr')) {
            $slip = array_merge($slip, PayslipLabels::localise(Payslip::findOrFail($payslip), $lang));
        }
        $run = DB::table('payroll_runs')->where('id', $slip['payroll_run_id'])->first();

        return $this->pdf('payslip', 'payslip-'.$payslip, $lang, [
            'slip' => $slip, 'run' => $run,
            'sections' => collect($slip['lines'])->groupBy('section')->map->values()->all(),
        ], ['number' => 'PAY-'.$payslip, 'source_id' => $payslip, 'title' => $slip['labels']['title'] ?? '']);
    }

    public function purchaseOrder(Request $request, int $order)
    {
        $po = $this->api->get("purchasing/orders/$order");
        $items = DB::table('stock_items')->whereIn('id', collect($po['lines'] ?? [])->pluck('stock_item_id'))->get()->keyBy('id');

        $lang = $this->lang($request);

        return $this->pdf('purchase-order', 'purchase-order-'.$po['number'], $lang, ['po' => $po, 'items' => $items], [
            'number' => $po['number'], 'source_id' => $order, 'amount' => (int) ($po['total'] ?? 0), 'currency' => $po['currency'] ?? 'XAF',
            'title' => $lang === 'en' ? 'Purchase order' : 'Bon de commande',
        ]);
    }

    private function lang(Request $request, ?int $guestId = null): string
    {
        $q = $request->query('lang');
        if (in_array($q, ['fr', 'en'], true)) {
            return $q;
        }
        if ($guestId && ($g = Guest::find($guestId)) && in_array($g->language, ['fr', 'en'], true)) {
            return $g->language;
        }
        $hotel = $this->hotel();

        return $hotel && str_starts_with((string) $hotel->locale, 'en') ? 'en' : 'fr';
    }

    private function hotel(): ?Hotel
    {
        return $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
    }

    private function pdf(string $view, string $name, string $lang, array $data, array $meta = [])
    {
        $type = $meta['type'] ?? str_replace('-', '_', $view);
        $doc = $this->engine->render($type, $data, $meta + ['view' => "pdf.$view", 'lang' => $lang, 'hotel' => $this->hotel(), 'source_type' => $type, 'filename' => $name]);

        return $this->engine->response($doc);
    }
}
