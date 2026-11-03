<?php

namespace App\Modules\Documents\Money;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\RenderedDocument;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;

/** Renders a Sheets builder result through the document engine. */
class Printer
{
    public function __construct(private DocumentRenderer $engine, private TenantContext $ctx) {}

    public function render(string $type, array $built, string $lang): RenderedDocument
    {
        $opts = $built['opts'];
        $size = ($built['landscape'] ?? false) ? 'a4-landscape' : ($opts['size'] ?? 'a4');
        $hotel = $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
        $sheet = $built['sheet'];
        $sheet['number'] ??= $opts['number'] ?? null;
        $title = $sheet['title'][$lang === 'en' ? 1 : 0] ?? '';
        unset($opts['size']);

        return $this->engine->render($built['type'] ?? str_replace('-', '_', $type), ['sheet' => $sheet], $opts + [
            'view' => $built['view'] ?? 'documents::money.sheet', 'lang' => $lang, 'hotel' => $hotel, 'size' => $size,
            'issue' => $built['issue'] ?? true, 'title' => $title, 'filename' => $type.'-'.($opts['number'] ?? 'doc'),
            'source_type' => str_replace('-', '_', $type),
        ]);
    }
}
