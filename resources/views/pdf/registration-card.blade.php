@extends('pdf.layout')
@php($d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '')
@section('title', $t('Fiche de police', 'Registration card'))
@section('content')
<h1>{{ $t('Fiche de police / d\'enregistrement', 'Registration card') }}</h1>
<p class="muted">{{ $t('Chambre', 'Room') }} {{ $card['room_number'] ?? '' }}</p>

<h2>{{ $t('Identité du client', 'Guest identity') }}</h2>
<table class="kv" style="width:100%">
    <tr><td class="k">{{ $t('Nom complet', 'Full name') }}</td><td>{{ $card['full_name'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Nationalité', 'Nationality') }}</td><td>{{ $card['nationality'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Date et lieu de naissance', 'Date and place of birth') }}</td><td>{{ $d($card['date_of_birth'] ?? null) }} {{ $card['place_of_birth'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Profession', 'Occupation') }}</td><td>{{ $card['profession'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Pièce d\'identité', 'ID document') }}</td><td>{{ $card['id_type'] ?? '' }} {{ $card['id_number'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Expiration', 'Expiry') }}</td><td>{{ $d($card['id_expiry'] ?? null) }}</td></tr>
</table>

<h2>{{ $t('Séjour', 'Stay') }}</h2>
<table class="kv" style="width:100%">
    <tr><td class="k">{{ $t('Arrivée', 'Arrival') }}</td><td>{{ $d($card['arrival'] ?? null) }}</td></tr>
    <tr><td class="k">{{ $t('Départ', 'Departure') }}</td><td>{{ $d($card['departure'] ?? null) }}</td></tr>
    <tr><td class="k">{{ $t('Venant de', 'Coming from') }}</td><td>{{ $card['coming_from'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Allant à', 'Going to') }}</td><td>{{ $card['going_to'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $t('Motif du séjour', 'Purpose of stay') }}</td><td>{{ $card['purpose'] ?? '' }}</td></tr>
</table>

<h2>{{ $t('Signature du client', 'Guest signature') }}</h2>
<div class="box" style="height:30mm; width:80mm">
    @if($signature)<img src="{{ $signature }}" style="max-height:28mm; max-width:78mm">@endif
</div>
<p class="small muted">{{ $t('Je certifie l\'exactitude des renseignements ci-dessus.', 'I certify that the information above is correct.') }}</p>
@endsection
