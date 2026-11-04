@if($part === 'head')
<div style="text-align:center; border-bottom:0.3mm solid {{ $lh['color'] }}; padding-bottom:3mm; margin-bottom:5mm">
    @if($lh['logo'])<img src="{{ $lh['logo'] }}" style="max-height:20mm; max-width:50mm" alt=""><br>@endif
    <strong style="font-size:14pt; color:{{ $lh['color_text'] }}">{{ $lh['name'] }}</strong><br>
    <span style="font-size:8.5pt; color:{{ $lh['accent_text'] }}">{{ $lh['address'] }}@if($lh['postal_box']) · {{ $lh['postal_box'] }}@endif @if($lh['phone']) · {{ $lh['phone'] }}@endif @if($lh['email']) · {{ $lh['email'] }}@endif</span>
</div>
@else
<div style="text-align:center; font-size:7.5pt; color:{{ $lh['accent_text'] }}; border-top:0.3mm solid {{ $lh['color'] }}; padding-top:1.5mm">@include('documents::letterheads._legal')</div>
@endif
