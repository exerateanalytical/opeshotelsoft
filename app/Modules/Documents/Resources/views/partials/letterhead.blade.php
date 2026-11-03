{{-- Letterhead dispatcher. $part = 'head' | 'foot'. Design is chosen per hotel (hotels.settings.letterhead_design) or forced with $design. --}}
@php
    $lh = $lh ?? \App\Modules\Documents\Application\Letterhead::for($hotel ?? null);
    $lt = $lt ?? $tt ?? $t ?? fn ($fr, $en) => $fr;
    $part = $part ?? 'head';
    $chosen = $design ?? $lh['design'];
    $chosen = in_array($chosen, \App\Modules\Documents\Application\Letterhead::DESIGNS, true) ? $chosen : 'classic';
    $docTitle = $docTitle ?? '';
@endphp
@include('documents::letterheads.'.$chosen, ['lh' => $lh, 'lt' => $lt, 'part' => $part, 'docTitle' => $docTitle])
