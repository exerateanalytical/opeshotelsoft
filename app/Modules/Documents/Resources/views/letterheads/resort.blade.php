@if($part === 'head')
<div style="background:{{ $lh['color'] }}; color:{{ $lh['on_color'] }}; margin:-18mm -15mm 6mm -15mm; padding:7mm 15mm 5mm 15mm; border-bottom:1.2mm solid {{ $lh['accent'] }}">
    <table style="width:100%; border-collapse:collapse"><tr>
        @if($lh['logo'])<td style="width:24mm; vertical-align:middle"><div style="background:#ffffff; padding:1.5mm; width:20mm"><img src="{{ $lh['logo'] }}" style="max-width:17mm; max-height:15mm" alt=""></div></td>@endif
        <td style="vertical-align:middle"><strong style="font-size:15pt; color:{{ $lh['on_color'] }}">{{ $lh['name'] }}</strong><br><span style="font-size:8pt; color:{{ $lh['on_color'] }}">{{ $lh['address'] }} · {{ $lh['phone'] }}</span></td>
    </tr></table>
</div>
@else
<div style="text-align:center; font-size:7.5pt; color:{{ $lh['accent_text'] }}; border-top:0.8mm solid {{ $lh['accent'] }}; padding-top:1.5mm">@include('documents::letterheads._legal')</div>
@endif
