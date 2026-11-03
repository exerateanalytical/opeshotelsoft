@php($ids = array_filter([$lh['niu'] ? $lt('NIU', 'Tax ID').' : '.$lh['niu'] : null, $lh['rccm'] ? 'RCCM : '.$lh['rccm'] : null]))
@if($lh['footer']){{ $lh['footer'] }}<br>@endif
@if($lh['bank_details']){{ $lt('Banque', 'Bank') }} : {{ $lh['bank_details'] }}<br>@endif
{{ implode(' · ', $ids) }}
