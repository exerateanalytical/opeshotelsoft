@extends('pdf.layout')
@section('title', $t('Relevé de points', 'Points statement'))
@section('content')
<h1>{{ $t('Relevé de points fidélité', 'Loyalty points statement') }}</h1>
<div class="muted">{{ $t('Période', 'Period') }} : {{ $s['from'] }} → {{ $s['to'] }}</div>
<table class="kv" style="margin-top:4mm">
    <tr><td class="muted">{{ $t('Membre', 'Member') }}</td><td>{{ $s['member']['name'] }} ({{ $s['member']['number'] }})</td></tr>
    <tr><td class="muted">{{ $t('Niveau', 'Tier') }}</td><td>{{ $s['member']['tier'] }}</td></tr>
    <tr><td class="muted">{{ $t('Solde d\'ouverture', 'Opening balance') }}</td><td>{{ number_format($s['opening'], 0, ',', ' ') }} pts</td></tr>
    <tr><td class="muted">{{ $t('Gagnés', 'Earned') }}</td><td>{{ number_format($s['earned'], 0, ',', ' ') }} pts</td></tr>
    <tr><td class="muted">{{ $t('Utilisés / expirés', 'Used / expired') }}</td><td>{{ number_format($s['spent'], 0, ',', ' ') }} pts</td></tr>
    <tr><td class="muted">{{ $t('Expirent sous 90 jours', 'Expiring within 90 days') }}</td><td>{{ number_format($s['expiring'], 0, ',', ' ') }} pts</td></tr>
</table>
<table class="grid">
    <thead><tr><th>{{ $t('Date', 'Date') }}</th><th>{{ $t('Opération', 'Activity') }}</th><th class="r">{{ $t('Points', 'Points') }}</th><th class="r">{{ $t('Solde', 'Balance') }}</th></tr></thead>
    <tbody>
    @forelse ($s['lines'] as $l)
        <tr><td>{{ $l['date'] }}</td><td>{{ $l['description'] }}</td><td class="r">{{ $l['points'] > 0 ? '+' : '' }}{{ number_format($l['points'], 0, ',', ' ') }}</td><td class="r">{{ number_format($l['balance'], 0, ',', ' ') }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">{{ $t('Aucune opération sur la période.', 'No activity in this period.') }}</td></tr>
    @endforelse
    <tr class="tot"><td colspan="3">{{ $t('Solde de clôture', 'Closing balance') }}</td><td class="r">{{ number_format($s['closing'], 0, ',', ' ') }}</td></tr>
    </tbody>
</table>
@endsection
