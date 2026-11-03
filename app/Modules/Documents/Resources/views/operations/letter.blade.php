{{-- D-C HR letter: $docHtml comes from TemplateRenderer (escaped text, whitelisted placeholders). $ack = employee acknowledgement block. --}}
@extends('pdf.layout')
@section('title', $docTitle)
@section('content')
<style>
    .doc-body p { line-height: 1.55; font-size: 10.5pt; margin: 0 0 3.5mm 0; }
    .doc-body h2 { font-size: 12pt; margin: 6mm 0 3mm 0; }
</style>
<div class="doc-body">{!! $docHtml !!}</div>
<table style="width:100%; margin-top:14mm; border-collapse:collapse"><tr>
    <td style="width:50%; vertical-align:bottom">
        @if(!empty($ack))<div style="border-top:1px solid #6b7280; padding-top:1mm; width:75mm" class="small muted">{{ $t('Lu et approuvé — signature de l\'employé(e) et date', 'Read and acknowledged — employee signature and date') }}</div>@endif
    </td>
    <td style="width:50%; text-align:right; vertical-align:bottom">
        <div style="border-top:1px solid #6b7280; padding-top:1mm; margin-left:auto; width:70mm; text-align:left" class="small muted">{{ $t('La Direction — cachet et signature', 'Management — stamp and signature') }}</div>
    </td>
</tr></table>
@endsection
