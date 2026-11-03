<!doctype html>
<html lang="{{ $lang ?? 'fr' }}">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: 18mm 15mm 18mm 15mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1f2937; }
    h1 { font-size: 15pt; margin: 0 0 2mm 0; }
    h2 { font-size: 11pt; margin: 5mm 0 2mm 0; }
    .muted { color: #6b7280; }
    .small { font-size: 8pt; }
    .head { width: 100%; border-bottom: 1px solid #d1d5db; padding-bottom: 3mm; margin-bottom: 4mm; }
    .head td { vertical-align: top; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #4b5563; border-bottom: 1px solid #9ca3af; padding: 1.5mm 1.5mm; }
    table.grid td { border-bottom: 1px solid #e5e7eb; padding: 1.5mm 1.5mm; }
    .r { text-align: right; }
    .tot td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
    .box { border: 1px solid #d1d5db; padding: 3mm; }
    .kv td { padding: 0.8mm 2mm 0.8mm 0; }
    .kv td.k { color: #6b7280; width: 38%; }
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; text-align: center; }
</style>
</head>
<body>
@php($tt = $t ?? fn ($fr, $en) => $fr)
<table class="head">
    <tr>
        <td>
            <strong style="font-size:12pt">{{ $hotel?->legal_name ?: $hotel?->name }}</strong><br>
            @if($hotel?->legal_name && $hotel?->legal_name !== $hotel?->name)<span class="muted">{{ $hotel->name }}</span><br>@endif
            {{ $hotel?->address }} {{ $hotel?->city }}<br>
            {{ $hotel?->phone }} {{ $hotel?->email }}
        </td>
        <td class="r small">
            @if($hotel?->tax_id){{ $tt('NIU', 'Tax ID') }} : {{ $hotel->tax_id }}<br>@endif
            @if($hotel?->trade_register)RCCM : {{ $hotel->trade_register }}<br>@endif
        </td>
    </tr>
</table>
@yield('content')
<div class="foot">{{ $hotel?->legal_name ?: $hotel?->name }} · {{ $tt('Édité le', 'Printed on') }} {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
