# Document engine (D-A)

Every PDF goes through `App\Modules\Documents\Application\DocumentRenderer`. It issues the document (record in
`issued_documents`), then renders a blade view inside the shared layout `resources/views/pdf/layout.blade.php`.
The layout adds: letterhead design, platform watermark, issue-time footer (hotel time zone + UTC) on every page,
verify QR, content fingerprint, and a "DUPLICATA n" mark on reprints.

## Render a document

```php
$doc = app(DocumentRenderer::class)->render('credit_note', $data, [
    'view'        => 'pdf.credit-note',   // default: "pdf.<type>"; module views use a namespace: 'loyalty::card'
    'lang'        => 'fr',                // fr|en (legal invoice stays fr)
    'size'        => 'a4',                // a4 | a4-landscape | 80mm | id1
    'number'      => 'AV-2026-0007',      // the document's own number (omit: generated)
    'source_type' => 'credit_note', 'source_id' => $note->id,   // same source twice = same record, copy +1
    'amount'      => 125000, 'currency' => 'XAF',               // shown on the public verify page (omit for personal amounts)
    'title'       => 'Avoir',             // shown by designs that print a title (Corporate)
    'hotel'       => $hotel,              // default: active hotel
    'issue'       => true,                // false = preview: no record, no QR, watermark as draft
    'draft'       => false,               // true = counts as draft for "drafts and copies only" watermark
    'design'      => null,                // force a letterhead design (previews)
    'filename'    => 'credit-note-AV-2026-0007',
]);
return app(DocumentRenderer::class)->response($doc);   // inline application/pdf
// $doc->pdf (bytes), $doc->record (IssuedDocument|null), $doc->copy (0 = original), $doc->engine (layout data)
```

`$data` is passed to the view and is also what the SHA-256 is computed over (closures and the hotel are ignored,
images are hashed). The view receives `$hotel`, `$lang`, `$t` (`$t('fr text', 'en text')`) and `$engine`.

### View skeleton

```blade
@extends('pdf.layout')
@section('title', $note['number'])
@section('content')
  <h1>{{ $t('Avoir', 'Credit note') }} {{ $note['number'] }}</h1>
  ...
@endsection
```

Layout CSS helpers: `table.grid`, `.r` (right), `.tot`, `.box`, `.kv`, `.muted`, `.small`. dompdf: no flex/grid, use tables.
For `80mm` and `id1` the layout drops the letterhead (80mm keeps a compact name block) and keeps a small QR.
For `id1` the card view must position its own content (absolute, 243 x 153 pt).

Type labels shown on the verify page: add `documents2.type.<type>` to `lang/{fr,en}/documents2.json`.

## Issued documents

- `IssuedDocuments::issue([...])` is idempotent per (hotel, type, source_type, source_id); a second call increments
  `copy_count` and writes `issued_document_reprints` (who, when, ip). Without `source_id` it matches on (type, number).
- Status: `valid`, `voided`, `replaced`. Never deleted.
  - `IssuedDocuments::voidSource($type, $sourceType, $sourceId, $reason)` flips the verify page to voided.
  - `IssuedDocuments::replaceSource($type, $sourceType, $sourceId, $newRecord)` flips it to "replaced by <number>".
  Call these from the place that voids or corrects the source (credit note issued, folio line reversed, payslip regenerated...).
- Verify code = base36 id + `-` + first 10 hex of HMAC-SHA256(`id|type|number`, APP_KEY). Not guessable, not enumerable.
- QR text: `<app url>/verify/<code>?h=<first 12 hex of content hash>`; the same 16 hex are printed in the footer.

## Public verification

- `GET /verify/{code}` (Inertia page `Verify/Show`, FR/EN with `?lang=`) and `GET /api/v1/verify/{code}` (JSON).
- Rate limited (`throttle:verify`, 30/min/IP), unauthenticated. Reveals only: type, number, hotel name, issue time,
  amount (not for payslip, e-contract, work certificate, loyalty), status, replaced-by number, and `hash_match`
  (true/false/null) when `?h=` (8+ hex chars, prefix of the SHA-256) is presented.

## Watermark

Stored per group in `groups.settings.watermark`: `mode` none|text|image, `text`, `image_path`, `opacity` 2-60,
`position` center|diagonal|tile, `apply` all|drafts (drafts and copies only). API: `GET/PUT documents/watermark`,
`POST documents/watermark/image` (PNG/JPEG, 1 MB), `POST documents/watermark/image/remove`. UI: onboarding step
`watermark` and the Branding screen.

## Letterheads

Seven designs (`classic modern executive minimal heritage corporate resort`) in
`app/Modules/Documents/Resources/views/letterheads/`, each rendering a `head` and a `foot` part. Chosen per hotel
(`hotels.settings.letterhead_design`, saved by `PUT documents/branding`). Live HTML preview:
`GET documents/branding/preview?design=&brand_color=&accent_color=`; real sample PDF: `GET documents/branding/sample?design=`.

## Permissions

`branding.manage` (branding, watermark), `documents.verify.log` (`GET documents/issued`, `documents/issued/{id}` with the reprint log).
