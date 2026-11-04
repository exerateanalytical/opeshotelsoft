{{-- D-C staff ID card, ID-1 (243 x 153 pt). The verify QR is printed by the engine footer. --}}
@extends('pdf.layout')
@section('title', $card['number'])
@section('content')
<style>
    .idc { width: 243pt; height: 153pt; position: absolute; top: 0; left: 0; }
    .band { background: {{ $engine['lh']['color'] ?? '#1e3a5f' }}; color: #fff; padding: 6pt 9pt; font-size: {{ mb_strlen($card['hotel']) > 26 ? 7 : 9 }}pt; font-weight: bold; white-space: nowrap; }
    .band span { float: right; font-weight: normal; font-size: 7pt; }
    .photo { position: absolute; left: 9pt; top: 30pt; width: 54pt; height: 66pt; border: 1px solid #cbd5e1; background: #f1f5f9; }
    .photo img { width: 54pt; height: 66pt; }
    .info { position: absolute; left: 72pt; top: 30pt; right: 6pt; }
    .nm { font-size: 10pt; font-weight: bold; margin-bottom: 2pt; }
    .k { font-size: 5.5pt; color: #6b7280; text-transform: uppercase; }
    .v { font-size: 7.5pt; margin-bottom: 2.5pt; }
</style>
<div class="idc">
    <div class="band">{{ $card['hotel'] }} <span>{{ $t('Carte de service', 'Staff card') }}</span></div>
    <div class="photo">@if($card['photo'])<img src="{{ $card['photo'] }}" alt="">@endif</div>
    <div class="info">
        <div class="nm">{{ $card['name'] }}</div>
        <div class="k">{{ $t('Fonction', 'Position') }}</div><div class="v">{{ $card['position'] }}</div>
        <div class="k">{{ $t('Service', 'Department') }}</div><div class="v">{{ $card['department'] }}</div>
        <div class="k">{{ $t('Matricule', 'Staff no.') }}</div><div class="v">{{ $card['number'] }}</div>
        @if($card['valid_until'])<div class="k">{{ $t('Valide jusqu\'au', 'Valid until') }}</div><div class="v">{{ $card['valid_until'] }}</div>@endif
    </div>
</div>
@endsection
