{{-- D-B generic money document. $sheet = ['title'=>[fr,en], 'subtitle'=>?, 'meta'=>[[fr,en,value]], 'blocks'=>[...], 'sign'=>[[fr,en]], 'note'=>[fr,en]] --}}
@extends('pdf.layout')
@php
    $pick = fn ($x) => is_array($x) ? ($lang === 'en' ? ($x[1] ?? $x[0]) : $x[0]) : (string) $x;
    $m = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $fmt = function ($v, $type) use ($m, $lang) {
        if ($v === null || $v === '') { return ''; }
        return match ($type) {
            'money' => $m($v),
            'int' => (string) (int) $v,
            'date' => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y'),
            'datetime' => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y H:i'),
            'bool' => $v ? ($lang === 'en' ? 'yes' : 'oui') : ($lang === 'en' ? 'no' : 'non'),
            default => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v,
        };
    };
    $title = $pick($sheet['title']);
@endphp
@section('title', $title.' '.($sheet['number'] ?? ''))
@section('content')
<h1>{{ $title }}@if(!empty($sheet['number'])) {{ $sheet['number'] }}@endif</h1>
@if(!empty($sheet['subtitle']))<div class="muted">{{ $pick($sheet['subtitle']) }}</div>@endif
@if(!empty($sheet['status_note']))<div class="box" style="margin:2mm 0; color:#b91c1c"><strong>{{ $pick($sheet['status_note']) }}</strong></div>@endif
@if(!empty($sheet['meta']))
<table class="kv" style="margin-top:2mm">
    @foreach($sheet['meta'] as $row)
        @if(($row[2] ?? '') !== null && ($row[2] ?? '') !== '')
        <tr><td class="k">{{ $pick([$row[0], $row[1]]) }}</td><td>{{ $row[2] }}</td></tr>
        @endif
    @endforeach
</table>
@endif
@foreach($sheet['blocks'] ?? [] as $b)
    @if(($b['type'] ?? '') === 'kv')
        @if(!empty($b['title']))<h2>{{ $pick($b['title']) }}</h2>@endif
        <table class="kv">
        @foreach($b['rows'] as $row)
            <tr><td class="k">{{ $pick([$row[0], $row[1]]) }}</td><td class="{{ ($row[3] ?? '') === 'money' ? 'r' : '' }}">{{ $fmt($row[2], $row[3] ?? 'text') }}</td></tr>
        @endforeach
        </table>
    @elseif(($b['type'] ?? '') === 'text')
        @if(!empty($b['title']))<h2>{{ $pick($b['title']) }}</h2>@endif
        <p style="white-space: pre-line">{{ $pick($b['text']) }}</p>
    @elseif(($b['type'] ?? '') === 'table')
        @if(!empty($b['title']))<h2>{{ $pick($b['title']) }}</h2>@endif
        <table class="grid">
            <thead><tr>
            @foreach($b['cols'] as $c)<th class="{{ in_array($c['t'] ?? 'text', ['money', 'int']) ? 'r' : '' }}">{{ $pick([$c['fr'], $c['en']]) }}</th>@endforeach
            </tr></thead>
            <tbody>
            @forelse($b['rows'] as $r)
                <tr>
                @foreach($b['cols'] as $c)
                    @php($v = is_array($r) ? ($r[$c['k']] ?? null) : ($r->{$c['k']} ?? null))
                    <td class="{{ in_array($c['t'] ?? 'text', ['money', 'int']) ? 'r' : '' }}">{{ $fmt($v, $c['t'] ?? 'text') }}</td>
                @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($b['cols']) }}" class="muted">{{ $lang === 'en' ? 'Nothing to show.' : 'Aucune ligne.' }}</td></tr>
            @endforelse
            @foreach($b['totals'] ?? [] as $tot)
                <tr class="tot"><td colspan="{{ max(1, count($b['cols']) - 1) }}">{{ $pick([$tot[0], $tot[1]]) }}</td><td class="r">{{ $m($tot[2]) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach
@if(!empty($sheet['words']))
<p style="margin-top:3mm"><span class="muted">{{ $t('Arrêté la présente somme à', 'Amount in words') }} :</span> <strong>{{ $sheet['words'] }}</strong></p>
@endif
@if(!empty($sheet['note']))<p class="small muted" style="margin-top:3mm; white-space: pre-line">{{ $pick($sheet['note']) }}</p>@endif
@if(!empty($sheet['sign']))
<table style="width:100%; margin-top:12mm; border-collapse:collapse"><tr>
    @foreach($sheet['sign'] as $s)
    <td style="width:{{ floor(100 / count($sheet['sign'])) }}%; padding-right:6mm; vertical-align:bottom"><div style="border-top:1px solid #6b7280; padding-top:1mm" class="small muted">{{ $pick($s) }}</div></td>
    @endforeach
</tr></table>
@endif
@endsection
