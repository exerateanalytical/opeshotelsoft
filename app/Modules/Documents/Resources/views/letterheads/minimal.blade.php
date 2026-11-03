@if($part === 'head')
<div style="border-bottom:0.15mm solid {{ $lh['accent'] }}; padding-bottom:2mm; margin-bottom:8mm">
    @if($lh['logo'])<img src="{{ $lh['logo'] }}" style="max-height:9mm; max-width:25mm; vertical-align:middle; margin-right:3mm" alt="">@endif
    <span style="font-size:9pt; color:{{ $lh['color'] }}; letter-spacing:0.3mm">{{ $lh['name'] }}</span>
</div>
@else
<div style="font-size:6pt; color:{{ $lh['accent'] }}">{{ $lh['address'] }} · {{ $lh['phone'] }} · @include('documents::letterheads._legal')</div>
@endif
