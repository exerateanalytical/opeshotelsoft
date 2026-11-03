@if($part === 'head')
<div style="position:fixed; top:-18mm; left:-15mm; right:-15mm; height:5mm; background:{{ $lh['color'] }}"></div>
<table style="width:100%; border-collapse:collapse; margin-bottom:5mm"><tr>
    <td style="vertical-align:middle; width:60%">
        @if($lh['logo'])<img src="{{ $lh['logo'] }}" style="max-height:18mm; max-width:45mm" alt="">@else<strong style="font-size:14pt; color:{{ $lh['color'] }}">{{ $lh['name'] }}</strong>@endif
    </td>
    <td style="vertical-align:top; text-align:right; font-size:8pt; color:{{ $lh['accent'] }}">
        <strong style="color:{{ $lh['color'] }}">{{ $lh['name'] }}</strong><br>{{ $lh['address'] }}@if($lh['postal_box']) · {{ $lh['postal_box'] }}@endif<br>{{ $lh['phone'] }}<br>{{ $lh['email'] }}
    </td>
</tr></table>
@else
<div style="font-size:7.5pt; color:{{ $lh['accent'] }}; border-top:0.3mm solid {{ $lh['color'] }}; padding-top:1.5mm">@include('documents::letterheads._legal')</div>
@endif
