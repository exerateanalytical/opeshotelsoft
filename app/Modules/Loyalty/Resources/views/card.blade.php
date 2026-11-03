@extends('pdf.layout')
@section('title', $member->number)
@section('content')
<style>
    .card { width: 243pt; height: 153pt; position: absolute; top: 0; left: 0; }
    .band { background: {{ $engine['lh']['color'] ?? '#1e3a5f' }}; color: #fff; padding: 7pt 9pt; font-size: 9pt; font-weight: bold; }
    .band span { float: right; font-weight: normal; }
    .cbody { padding: 6pt 9pt; }
    .name { font-size: 10pt; font-weight: bold; margin-bottom: 3pt; }
    .k { font-size: 6pt; color: #6b7280; text-transform: uppercase; }
    .v { font-size: 8pt; margin-bottom: 3pt; }
    .num { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; letter-spacing: 1pt; }
    table.qr { border-collapse: collapse; position: absolute; right: 9pt; top: 34pt; }
    table.qr td { width: 2.6pt; height: 2.6pt; padding: 0; }
    .d { background: #000; }
</style>
<div class="card">
    <div class="band">{{ $t('Carte de fidélité', 'Loyalty card') }} <span>{{ $member->tier?->name }}</span></div>
    <div class="cbody">
        <div class="name">{{ trim(($member->guest?->first_name ?? '').' '.($member->guest?->last_name ?? '')) }}</div>
        <div class="k">{{ $t('N° membre', 'Member no.') }}</div>
        <div class="v">{{ $member->number }}</div>
        <div class="k">{{ $t('N° carte', 'Card no.') }}</div>
        <div class="v num">{{ trim(chunk_split($card->card_number, 4, ' ')) }}</div>
        <div class="k">{{ $t('Expire le', 'Expires') }}</div>
        <div class="v">{{ $card->expires_on?->format('m/Y') ?? '—' }}</div>
    </div>
    <table class="qr">
        @foreach ($qr as $row)
            <tr>@foreach ($row as $dark)<td class="{{ $dark ? 'd' : '' }}"></td>@endforeach</tr>
        @endforeach
    </table>
</div>
@endsection
