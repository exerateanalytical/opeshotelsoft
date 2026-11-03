{{-- 80 mm till receipt (POS receipt, quick payment receipts). $sheet as sheet.blade.php, plus 'lines' and 'totals'. --}}
@extends('pdf.layout')
@php
    $m = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $pick = fn ($x) => is_array($x) ? ($lang === 'en' ? ($x[1] ?? $x[0]) : $x[0]) : (string) $x;
@endphp
@section('title', $pick($sheet['title']).' '.($sheet['number'] ?? ''))
@section('content')
<div style="text-align:center"><strong style="font-size:10pt">{{ $pick($sheet['title']) }}</strong><br>{{ $sheet['number'] ?? '' }}</div>
@if(!empty($sheet['status_note']))<div style="text-align:center; color:#b91c1c; font-weight:bold">{{ $pick($sheet['status_note']) }}</div>@endif
<table style="width:100%; margin:1.5mm 0">
@foreach($sheet['meta'] as $row)
    @if(($row[2] ?? '') !== '' && ($row[2] ?? null) !== null)<tr><td style="color:#6b7280">{{ $pick([$row[0], $row[1]]) }}</td><td style="text-align:right">{{ $row[2] }}</td></tr>@endif
@endforeach
</table>
<table style="width:100%; border-top:1px dashed #6b7280; border-bottom:1px dashed #6b7280; border-collapse:collapse">
@foreach($sheet['lines'] as $l)
    <tr><td style="padding:0.6mm 0">{{ rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.') }} x {{ $l['name'] }}@if(!empty($l['void'])) <span style="color:#b91c1c">({{ $lang === 'en' ? 'void' : 'annulé' }})</span>@endif</td><td style="text-align:right; white-space:nowrap">{{ $m($l['amount']) }}</td></tr>
@endforeach
</table>
<table style="width:100%; margin-top:1.5mm">
@foreach($sheet['totals'] as $tt)
    <tr @if(!empty($tt[3])) style="font-weight:bold; font-size:10pt" @endif><td>{{ $pick([$tt[0], $tt[1]]) }}</td><td style="text-align:right">{{ $m($tt[2]) }} {{ $sheet['currency'] ?? 'XAF' }}</td></tr>
@endforeach
</table>
@if(!empty($sheet['note']))<p style="text-align:center; margin-top:3mm; font-size:7pt">{{ $pick($sheet['note']) }}</p>@endif
@endsection
