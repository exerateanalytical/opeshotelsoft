@extends('pdf.layout')
@php($m = fn ($v) => number_format((int) $v, 0, ',', ' '))
@section('title', $po['number'])
@section('content')
<table style="width:100%">
    <tr>
        <td style="width:55%; vertical-align:top">
            <h1>{{ $t('Bon de commande', 'Purchase order') }} {{ $po['number'] }}</h1>
            <table class="kv">
                <tr><td class="k">{{ $t('Date', 'Date') }}</td><td>{{ \Illuminate\Support\Carbon::parse($po['order_date'])->format('d/m/Y') }}</td></tr>
                @if(!empty($po['expected_date']))<tr><td class="k">{{ $t('Livraison prévue', 'Expected delivery') }}</td><td>{{ \Illuminate\Support\Carbon::parse($po['expected_date'])->format('d/m/Y') }}</td></tr>@endif
                <tr><td class="k">{{ $t('Statut', 'Status') }}</td><td>{{ $po['status'] }}</td></tr>
            </table>
        </td>
        <td class="box" style="vertical-align:top">
            <span class="muted small">{{ $t('Fournisseur', 'Supplier') }}</span><br>
            <strong>{{ $po['supplier']['name'] ?? '' }}</strong><br>
            {{ $po['supplier']['address'] ?? '' }}<br>
            @if(!empty($po['supplier']['tax_id'])){{ $t('NIU', 'Tax ID') }} : {{ $po['supplier']['tax_id'] }}@endif
        </td>
    </tr>
</table>
<h2>{{ $t('Articles', 'Items') }}</h2>
<table class="grid">
    <thead><tr><th>{{ $t('Code', 'Code') }}</th><th>{{ $t('Article', 'Item') }}</th><th class="r">{{ $t('Qté', 'Qty') }}</th><th class="r">{{ $t('PU HT', 'Unit price') }}</th><th class="r">{{ $t('TVA %', 'VAT %') }}</th><th class="r">{{ $t('Total HT', 'Net') }}</th></tr></thead>
    @foreach($po['lines'] ?? [] as $l)
        @php($it = $items[$l['stock_item_id']] ?? null)
        <tr>
            <td>{{ $it->code ?? '' }}</td><td>{{ $it->name ?? '' }}</td>
            <td class="r">{{ rtrim(rtrim((string) $l['quantity'], '0'), '.') }}</td>
            <td class="r">{{ $m($l['unit_price']) }}</td>
            <td class="r">{{ rtrim(rtrim((string) $l['vat_rate'], '0'), '.') }}</td>
            <td class="r">{{ $m(round((float) $l['quantity'] * (int) $l['unit_price'])) }}</td>
        </tr>
    @endforeach
</table>
<table class="grid" style="margin-top:4mm; width:50%; margin-left:50%">
    <tr><td>{{ $t('Total HT', 'Net total') }}</td><td class="r">{{ $m($po['net_total']) }}</td></tr>
    <tr><td>{{ $t('TVA', 'VAT') }}</td><td class="r">{{ $m($po['tax_total']) }}</td></tr>
    <tr class="tot"><td>{{ $t('Total TTC', 'Total') }}</td><td class="r">{{ $m($po['total']) }} XAF</td></tr>
</table>
@if(!empty($po['notes']))<p>{{ $po['notes'] }}</p>@endif
<table style="width:100%; margin-top:12mm"><tr>
    <td style="width:50%">{{ $t('Établi par', 'Prepared by') }}<div class="box" style="height:18mm; width:60mm"></div></td>
    <td>{{ $t('Approuvé par', 'Approved by') }}<div class="box" style="height:18mm; width:60mm"></div></td>
</tr></table>
@endsection
