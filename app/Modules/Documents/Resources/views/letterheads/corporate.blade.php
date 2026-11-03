@if($part === 'head')
<table style="width:100%; border-collapse:collapse; margin-bottom:5mm"><tr>
    <td style="vertical-align:middle; width:50%">
        @if($lh['logo'])<img src="{{ $lh['logo'] }}" style="max-height:16mm; max-width:40mm" alt="">@endif
        <div style="font-size:8pt; color:{{ $lh['accent'] }}">{{ $lh['name'] }}</div>
    </td>
    <td style="vertical-align:middle; text-align:right"><strong style="font-size:15pt; color:{{ $lh['color'] }}">{{ $docTitle }}</strong></td>
</tr></table>
@else
<div style="background:{{ $lh['color'] }}; color:#ffffff; font-size:7pt; padding:2mm 3mm; text-align:center">@include('documents::letterheads._legal')</div>
@endif
