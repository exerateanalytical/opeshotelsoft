@extends('pdf.layout')
@php($m = fn ($v) => number_format((int) $v, 0, ',', ' '))
@section('title', $invoice['number'])
@section('content')
<table style="width:100%">
    <tr>
        <td style="width:55%; vertical-align:top">
            <h1>{{ ($invoice['kind'] ?? 'invoice') === 'credit_note' ? $t('Avoir', 'Credit note') : $t('Facture', 'Invoice') }} {{ $invoice['number'] }}</h1>
            <table class="kv">
                <tr><td class="k">{{ $t('Date', 'Date') }}</td><td>{{ \Illuminate\Support\Carbon::parse($invoice['issue_date'])->format('d/m/Y') }}</td></tr>
                @if(!empty($invoice['due_date']))<tr><td class="k">{{ $t('Échéance', 'Due date') }}</td><td>{{ \Illuminate\Support\Carbon::parse($invoice['due_date'])->format('d/m/Y') }}</td></tr>@endif
                <tr><td class="k">{{ $t('Devise', 'Currency') }}</td><td>{{ $invoice['currency'] }}</td></tr>
            </table>
        </td>
        <td class="box" style="vertical-align:top">
            <span class="muted small">{{ $t('Facturé à', 'Bill to') }}</span><br>
            <strong>{{ $invoice['bill_to_name'] }}</strong><br>
            @if(!empty($invoice['bill_to_address'])){{ $invoice['bill_to_address'] }}<br>@endif
            @if(!empty($invoice['bill_to_tax_id'])){{ $t('NIU', 'Tax ID') }} : {{ $invoice['bill_to_tax_id'] }}@endif
        </td>
    </tr>
</table>

<h2>{{ $t('Détail', 'Details') }}</h2>
<table class="grid">
    <thead><tr><th>{{ $t('Date', 'Date') }}</th><th>{{ $t('Désignation', 'Description') }}</th><th class="r">{{ $t('Qté', 'Qty') }}</th><th class="r">{{ $t('Taxes', 'Tax') }}</th><th class="r">{{ $t('Montant TTC', 'Amount incl. tax') }}</th></tr></thead>
    <tbody>
    @foreach($invoice['lines'] as $l)
        <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($l['business_date'])->format('d/m/Y') }}</td>
            <td>{{ $l['description'] }}</td>
            <td class="r">{{ rtrim(rtrim((string) $l['quantity'], '0'), '.') }}</td>
            <td class="r">{{ $m($l['tax_amount'] ?? 0) }}</td>
            <td class="r">{{ $m($l['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="width:100%; margin-top:4mm">
    <tr>
        <td style="width:50%; vertical-align:top">
            <table class="grid">
                <thead><tr><th>{{ $t('Taxe', 'Tax') }}</th><th class="r">{{ $t('Montant', 'Amount') }}</th></tr></thead>
                @forelse($invoice['taxes'] as $tx)
                    <tr><td>{{ $tx['name'] }} ({{ $tx['code'] }})</td><td class="r">{{ $m($tx['amount']) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">{{ $t('Aucune taxe', 'No tax') }}</td></tr>
                @endforelse
            </table>
        </td>
        <td style="vertical-align:top; padding-left:8mm">
            <table class="grid">
                <tr><td>{{ $t('Total HT', 'Net total') }}</td><td class="r">{{ $m($net) }}</td></tr>
                <tr><td>{{ $t('Total taxes', 'Total tax') }}</td><td class="r">{{ $m($invoice['tax_total']) }}</td></tr>
                <tr class="tot"><td>{{ $t('Total TTC', 'Total incl. tax') }}</td><td class="r">{{ $m($invoice['total']) }} {{ $invoice['currency'] }}</td></tr>
                <tr><td>{{ $t('Déjà réglé', 'Paid') }}</td><td class="r">{{ $m($invoice['paid'] ?? 0) }}</td></tr>
                <tr class="tot"><td>{{ $t('Reste à payer', 'Balance due') }}</td><td class="r">{{ $m($invoice['total'] - ($invoice['paid'] ?? 0)) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin-top:6mm">
    {{ $t('Arrêtée la présente facture à la somme de', 'This invoice is closed at the sum of') }}
    <strong>{{ ucfirst($words) }} {{ $invoice['currency'] === 'XAF' ? $t('francs CFA', 'CFA francs') : $invoice['currency'] }}</strong>.
</p>
@endsection
