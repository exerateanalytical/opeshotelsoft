@extends('pdf.layout')
@php($m = fn ($v) => number_format((int) $v, 0, ',', ' '))
@section('title', 'Folio '.($folio['number'] ?? $folio['id']))
@section('content')
<h1>{{ $t('Relevé de folio', 'Folio statement') }} {{ $folio['number'] ?? $folio['id'] }}</h1>
<table class="kv">
    <tr><td class="k">{{ $t('Titulaire', 'Account') }}</td><td>{{ $folio['name'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Statut', 'Status') }}</td><td>{{ ['open' => $t('Ouvert', 'Open'), 'closed' => $t('Clôturé', 'Closed'), 'settled' => $t('Soldé', 'Settled'), 'void' => $t('Annulé', 'Void')][$folio['status'] ?? ''] ?? ($folio['status'] ?? '') }}</td></tr>
    <tr><td class="k">{{ $t('Devise', 'Currency') }}</td><td>{{ $folio['currency'] ?? 'XAF' }}</td></tr>
</table>
<h2>{{ $t('Mouvements', 'Transactions') }}</h2>
<table class="grid">
    <thead><tr><th>{{ $t('Date', 'Date') }}</th><th>{{ $t('Libellé', 'Description') }}</th><th class="r">{{ $t('Débit', 'Charges') }}</th><th class="r">{{ $t('Crédit', 'Credits') }}</th><th class="r">{{ $t('Solde', 'Balance') }}</th></tr></thead>
    <tbody>
    @php($run = 0)
    @foreach($folio['lines'] ?? [] as $l)
        @php($run += (int) $l['amount'])
        <tr>
            <td>{{ \Illuminate\Support\Carbon::parse($l['business_date'])->format('d/m/Y') }}</td>
            <td>{{ $l['description'] }}@if(!empty($l['reversed'])) <span class="muted">({{ $t('annulé', 'reversed') }})</span>@endif</td>
            <td class="r">{{ $l['amount'] > 0 ? $m($l['amount']) : '' }}</td>
            <td class="r">{{ $l['amount'] < 0 ? $m(-$l['amount']) : '' }}</td>
            <td class="r">{{ $m($run) }}</td>
        </tr>
    @endforeach
    <tr class="tot"><td colspan="4">{{ $t('Solde dû', 'Balance due') }}</td><td class="r">{{ $m($folio['balance'] ?? $run) }} {{ $folio['currency'] ?? 'XAF' }}</td></tr>
    </tbody>
</table>
@endsection
