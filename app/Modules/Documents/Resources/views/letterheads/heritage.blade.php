@if($part === 'head')
<div style="text-align:center; font-family:DejaVu Serif, serif; margin-bottom:6mm; border-top:0.5mm solid {{ $lh['accent'] }}; border-bottom:0.5mm solid {{ $lh['accent'] }}; padding:3mm 0">
    <div style="border-top:0.2mm solid {{ $lh['accent'] }}; border-bottom:0.2mm solid {{ $lh['accent'] }}; padding:3mm 0">
        @if($lh['logo'])<img src="{{ $lh['logo'] }}" style="max-height:17mm; max-width:17mm; border:0.3mm solid {{ $lh['accent'] }}; padding:1mm" alt=""><br>@endif
        <strong style="font-size:15pt; color:{{ $lh['color'] }}; letter-spacing:0.4mm">{{ $lh['name'] }}</strong><br>
        <span style="font-size:8pt; color:{{ $lh['accent'] }}"><em>{{ $lh['address'] }}@if($lh['phone']) · {{ $lh['phone'] }}@endif</em></span>
    </div>
</div>
@else
<div style="text-align:center; font-family:DejaVu Serif, serif; font-size:7.5pt; color:{{ $lh['accent'] }}; border-top:0.5mm solid {{ $lh['accent'] }}; padding-top:1.5mm">@include('documents::letterheads._legal')</div>
@endif
