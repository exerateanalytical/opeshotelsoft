@extends('pdf.layout')
@section('title', $t('Attestation', 'Certificate'))
@section('content')
<h1 style="text-align:center;font-size:14pt">
    @if($kind === 'salary_certificate'){{ $t('ATTESTATION DE SALAIRE', 'SALARY CERTIFICATE') }}@else{{ $t('ATTESTATION DE TRAVAIL', 'CERTIFICATE OF EMPLOYMENT') }}@endif
</h1>
<p style="line-height:1.6">
    {{ $t("Nous soussignés, $hotel?->name, certifions que", "We, $hotel?->name, certify that") }}
    <strong>{{ $employee->full_name }}</strong> ({{ $t('matricule', 'employee no.') }} {{ $employee->number }})
    {{ $t('est employé(e) dans notre établissement depuis le', 'has been employed at our establishment since') }} {{ $employee->hire_date->format('d/m/Y') }}
    @if($position){{ $t('en qualité de', 'as') }} {{ $position }}@endif
    @if($contract), {{ $t('sous contrat', 'under a') }} {{ $contract->kind }}@endif.
</p>
@if($kind === 'salary_certificate')
<p style="line-height:1.6">{{ $t('Son salaire de base mensuel brut est de', 'Their gross monthly base salary is') }} <strong>{{ number_format($salary, 0, ',', ' ') }} XAF</strong>.</p>
@endif
@if($purpose)<p>{{ $t('Cette attestation est délivrée pour', 'This certificate is issued for') }} : {{ $purpose }}.</p>@endif
<p>{{ $t('Fait le', 'Issued on') }} {{ $on }}.</p>
@endsection
