@extends('pdf.layout')
@php($m = fn ($v) => number_format((int) $v, 0, ',', ' '))
@php($L = $slip['labels'])
@php($emp = $slip['employee'] ?? [])
@section('title', $L['title'])
@section('content')
<h1>{{ $L['title'] }}</h1>
<table class="kv" style="width:100%">
    <tr><td class="k">{{ $L['employee'] }}</td><td>{{ trim(($emp['first_name'] ?? '').' '.($emp['last_name'] ?? '')) }}</td></tr>
    <tr><td class="k">{{ $L['number'] }}</td><td>{{ $emp['number'] ?? '' }}</td></tr>
    <tr><td class="k">{{ $L['period'] }}</td><td>{{ $run->period ?? '' }}</td></tr>
    @if(!empty($emp['cnps_number']))<tr><td class="k">CNPS</td><td>{{ $emp['cnps_number'] }}</td></tr>@endif
</table>

@foreach(['earning' => $L['earning'], 'employee_deduction' => $L['employee_deduction'], 'employer_charge' => $L['employer_charge']] as $key => $heading)
    @if(!empty($sections[$key]))
    <h2>{{ $heading }}</h2>
    <table class="grid">
        <thead><tr><th></th><th class="r">{{ $L['basis'] }}</th><th class="r">{{ $L['rate'] }}</th><th class="r">{{ $L['amount'] }}</th></tr></thead>
        @foreach($sections[$key] as $l)
            <tr>
                <td>{{ $l['label'] }}</td>
                <td class="r">{{ isset($l['basis']) && $l['basis'] !== null ? $m($l['basis']) : '' }}</td>
                <td class="r">{{ isset($l['rate']) && $l['rate'] !== null ? rtrim(rtrim(number_format((float) $l['rate'] * (((float) $l['rate']) < 1 ? 100 : 1), 3, ',', ''), '0'), ',').(((float) $l['rate']) < 1 ? ' %' : '') : '' }}</td>
                <td class="r">{{ $m($l['amount']) }}</td>
            </tr>
        @endforeach
    </table>
    @endif
@endforeach

<table class="grid" style="margin-top:5mm; width:60%; margin-left:40%">
    <tr><td>{{ $L['gross'] }}</td><td class="r">{{ $m($slip['gross']) }}</td></tr>
    <tr><td>{{ $L['taxable'] }}</td><td class="r">{{ $m($slip['taxable']) }}</td></tr>
    <tr><td>{{ $L['employer_charge'] }}</td><td class="r">{{ $m((int) $slip['employer_social'] + (int) $slip['other_employer']) }}</td></tr>
    <tr class="tot"><td>{{ $L['net'] }}</td><td class="r">{{ $m($slip['net']) }} XAF</td></tr>
</table>
@endsection
