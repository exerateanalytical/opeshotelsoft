@extends('pdf.layout')
@section('title', $docTitle)
@section('content')
{{-- $docHtml comes from TemplateRenderer: escaped user text with whitelisted placeholders only. --}}
<div class="doc-body">{!! $docHtml !!}</div>
@endsection
