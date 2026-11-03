<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\IssuedDocument;

/** Result of DocumentRenderer::render(): PDF bytes, the issued record (null for previews) and copy number. */
final class RenderedDocument
{
    public function __construct(
        public readonly string $pdf,
        public readonly ?IssuedDocument $record,
        public readonly int $copy,
        public readonly array $engine,
        public readonly string $filename,
    ) {}
}
