@extends('pdf.layout')
@php
    $fr = $lang === 'fr';
    $d = fn ($date) => $date ? \Carbon\Carbon::parse($date)->format('d/m/Y') : '-';
@endphp
@section('title', $fr ? 'Certificat de travail' : 'Work certificate')
@section('content')
<style>
    h1.wc { font-size: 16pt; text-align: center; margin: 14mm 0; letter-spacing: 1px; }
    p.wc { line-height: 1.7; font-size: 11pt; }
    .sign { margin-top: 18mm; text-align: right; }
</style>
<div class="muted" style="text-align:right">N° {{ $exit->certificate_number }}</div>
<h1 class="wc">{{ $fr ? 'CERTIFICAT DE TRAVAIL' : 'WORK CERTIFICATE' }}</h1>
@if ($fr)
    <p class="wc">Je soussigné(e), représentant de <strong>{{ $hotel?->name }}</strong>, certifie que
    <strong>{{ $employee->full_name }}</strong>@if ($employee->cnps_number), immatriculé(e) à la CNPS sous le n° {{ $employee->cnps_number }}@endif,
    a été employé(e) dans notre établissement du <strong>{{ $d($employee->hire_date) }}</strong> au <strong>{{ $d($exit->last_day) }}</strong>
    @if ($position) en qualité de <strong>{{ $position }}</strong>@endif.</p>
    <p class="wc">Il/Elle nous quitte libre de tout engagement.</p>
    <p class="wc">En foi de quoi, le présent certificat lui est délivré pour servir et valoir ce que de droit.</p>
    <div class="sign">Fait à {{ $hotel?->city }}, le {{ now()->format('d/m/Y') }}<br><br>La Direction</div>
@else
    <p class="wc">The undersigned, representing <strong>{{ $hotel?->name }}</strong>, certifies that
    <strong>{{ $employee->full_name }}</strong>@if ($employee->cnps_number), CNPS no. {{ $employee->cnps_number }}@endif,
    was employed by our establishment from <strong>{{ $d($employee->hire_date) }}</strong> to <strong>{{ $d($exit->last_day) }}</strong>
    @if ($position) as <strong>{{ $position }}</strong>@endif.</p>
    <p class="wc">The employee leaves free of any commitment to us.</p>
    <p class="wc">This certificate is issued for all legal purposes.</p>
    <div class="sign">Issued in {{ $hotel?->city }}, on {{ now()->format('d/m/Y') }}<br><br>Management</div>
@endif
@endsection
