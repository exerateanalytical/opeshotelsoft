{{-- D-C kitchen / bar ticket, 80 mm roll: one ticket per fire and station, each on its own page. --}}
@extends('pdf.layout')
@section('title', 'KOT '.$check['number'])
@section('content')
@foreach($tickets as $i => $tk)
<div style="@if(!$loop->last) page-break-after: always; @endif">
    <div style="text-align:center; font-size:13pt; font-weight:bold; text-transform:uppercase">{{ $tk['station'] }}</div>
    <div style="text-align:center">{{ $check['outlet'] }}</div>
    <table style="width:100%; margin-top:1.5mm; font-size:11pt; font-weight:bold"><tr>
        <td>{{ $t('Table', 'Table') }} {{ $check['table'] ?? '-' }}</td><td style="text-align:right">{{ $t('Couverts', 'Covers') }} {{ $check['covers'] }}</td>
    </tr></table>
    <table style="width:100%"><tr><td>#{{ $check['number'] }}</td><td style="text-align:right">{{ \Illuminate\Support\Carbon::parse($tk['fired_at'])->format('d/m H:i') }}</td></tr></table>
    @if($server)<div>{{ $t('Serveur', 'Server') }} : {{ $server }}</div>@endif
    <div style="border-top:1px solid #000; margin:1.5mm 0"></div>
    @foreach($tk['items'] as $it)
        <div style="margin-bottom:1.5mm; @if($it['voided']) text-decoration: line-through; @endif">
            <div style="font-size:11pt; font-weight:bold">{{ rtrim(rtrim(number_format((float) $it['quantity'], 3, '.', ''), '0'), '.') }} x {{ $it['name'] }}@if($it['voided']) ({{ $t('annulé', 'void') }})@endif</div>
            @foreach($it['modifiers'] ?? [] as $m)<div style="padding-left:4mm">+ {{ is_array($m) ? ($m['name'] ?? '') : $m }}</div>@endforeach
            @if(!empty($it['notes']))<div style="padding-left:4mm; font-style:italic">** {{ $it['notes'] }}</div>@endif
        </div>
    @endforeach
</div>
@endforeach
@if(empty($tickets))<p style="text-align:center">{{ $t('Aucun ticket à imprimer.', 'No ticket to print.') }}</p>@endif
@endsection
