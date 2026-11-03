@extends('pdf.layout')
@section('title', $title)
@section('content')
<h1>{{ $title }}</h1>
@if(!empty($params))
<p class="muted small">@foreach($params as $k => $v){{ $k }}: {{ is_scalar($v) ? $v : json_encode($v) }}@if(!$loop->last) · @endif @endforeach</p>
@endif
<table class="grid">
    <thead><tr>@foreach($columns as $c)<th>{{ str_replace('_', ' ', $c) }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse($rows as $r)
        <tr>@foreach($r as $v)<td class="{{ is_int($v) || is_float($v) ? 'r' : '' }}">{{ is_int($v) ? number_format($v, 0, ',', ' ') : $v }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ max(count($columns), 1) }}" class="muted">—</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
