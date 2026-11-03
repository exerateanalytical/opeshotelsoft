{{-- Live HTML preview of a letterhead design (same partials as the PDF). Container is a transformed box so fixed bands stay inside it. --}}
@php($lt = fn ($fr, $en) => $lang === 'en' ? $en : $fr)
<div data-letterhead="{{ $lh['design'] }}" style="position:relative; transform:translateZ(0); width:210mm; height:140mm; padding:18mm 15mm 0 15mm; box-sizing:border-box; background:#ffffff; overflow:hidden; font-family:DejaVu Sans, Arial, sans-serif; font-size:9.5pt; color:#1f2937; border:1px solid #e5e7eb">
    @include('documents::partials.letterhead', ['lh' => $lh, 'lt' => $lt, 'part' => 'head', 'docTitle' => $lt('Facture', 'Invoice').' F-0001'])
    <div style="@if($lh['design'] === 'executive') margin-left:18mm; @endif">
        <strong>{{ $lt('Facture', 'Invoice') }} F-0001</strong>
        <p style="color:#6b7280">{{ $lt('Exemple de contenu de document : désignation, quantité, montant.', 'Sample document content: description, quantity, amount.') }}</p>
        <table style="width:100%; border-collapse:collapse"><tr><td style="border-bottom:1px solid #e5e7eb; padding:1.5mm">{{ $lt('Chambre Deluxe', 'Deluxe room') }} x 2</td><td style="border-bottom:1px solid #e5e7eb; padding:1.5mm; text-align:right">100 000 XAF</td></tr></table>
    </div>
    <div style="position:absolute; left:15mm; right:15mm; bottom:6mm">
        @include('documents::partials.letterhead', ['lh' => $lh, 'lt' => $lt, 'part' => 'foot', 'docTitle' => ''])
    </div>
</div>
