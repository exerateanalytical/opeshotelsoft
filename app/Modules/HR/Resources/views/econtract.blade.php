@extends('pdf.layout')
@php($tt = $t)
@php($dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone(config('app.timezone'))->format('d/m/Y H:i:s') : '')
@section('title', $doc->title)
@section('content')
<style>h1{text-align:center;font-size:14pt} .body{line-height:1.5} table.sig{width:100%;margin-top:8mm} table.sig td{width:50%;vertical-align:top;padding-right:4mm} .sigbox{border:1px solid #d1d5db;height:30mm;text-align:center} .sigbox img{max-height:28mm;max-width:80mm} .page-break{page-break-before:always}</style>

<h1>{{ $doc->title }}</h1>
<div class="body">{!! nl2br(e($doc->body)) !!}</div>

<table class="sig">
    <tr>
        <td>
            @if($ph = $doc->employee?->photoDataUri())<img src="{{ $ph }}" alt="" style="float:right;width:60px;height:60px;border:1px solid #cbd5e1">@endif
            <strong>{{ $tt('Le Salarié', 'The Employee') }}</strong> — {{ $doc->employee?->full_name }}<br>
            <span class="small muted">{{ $tt('Lu et approuvé, signé électroniquement le', 'Read and approved, signed electronically on') }} {{ $dt($doc->employee_signed_at) }}</span>
            <div class="sigbox">@if($doc->employee_signature)<img src="{{ $doc->employee_signature }}" alt="">@endif</div>
        </td>
        <td>
            <strong>{{ $tt("L'Employeur", 'The Employer') }}</strong> — {{ $employer }}<br>
            <span class="small muted">{{ $tt('Signé électroniquement le', 'Signed electronically on') }} {{ $dt($doc->employer_signed_at) }}</span>
            <div class="sigbox">@if($doc->employer_signature)<img src="{{ $doc->employer_signature }}" alt="">@endif</div>
        </td>
    </tr>
</table>

<div class="page-break"></div>
<h2>{{ $tt('Certificat de signature électronique', 'Electronic signature certificate') }}</h2>
<p class="small">{{ $tt('Empreinte SHA-256 du texte signé', 'SHA-256 of the signed text') }} : {{ $doc->body_sha256 ?? hash('sha256', $doc->body) }}</p>
<p class="small muted">{{ $tt("L'empreinte SHA-256 de ce fichier PDF est conservée par l'employeur et permet d'en vérifier l'intégrité.", 'The SHA-256 of this PDF file is kept by the employer and proves it has not been altered.') }}</p>
<table class="grid">
    <thead><tr><th>{{ $tt('Date', 'Date') }}</th><th>{{ $tt('Événement', 'Event') }}</th><th>IP</th><th>{{ $tt('Détails', 'Details') }}</th></tr></thead>
    @foreach($audit as $a)
        <tr><td>{{ $dt($a->created_at) }}</td><td>{{ $a->event }}</td><td>{{ $a->ip }}</td><td>{{ collect($a->meta)->map(fn ($v, $k) => "$k: ".(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') }} {{ $a->user_agent ? '· '.$a->user_agent : '' }}</td></tr>
    @endforeach
</table>
@endsection
