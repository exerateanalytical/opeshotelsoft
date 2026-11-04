@if($part === 'head')
<div style="position:fixed; top:-18mm; left:-15mm; width:30mm; height:297mm; background:{{ $lh['color'] }}; color:{{ $lh['on_color'] }}; font-size:6.5pt; line-height:1.5">
    <div style="padding:20mm 2.5mm 0 2.5mm">
        @if($lh['logo'])<div style="background:#ffffff; padding:1.5mm; margin-bottom:4mm"><img src="{{ $lh['logo'] }}" style="max-width:21mm; max-height:18mm" alt=""></div>@endif
        <strong style="font-size:8pt">{{ $lh['name'] }}</strong><br><br>
        {{ $lh['address'] }}<br>@if($lh['postal_box']){{ $lh['postal_box'] }}<br>@endif{{ $lh['phone'] }}<br>{{ $lh['email'] }}
    </div>
</div>
<div style="margin-left:18mm; margin-bottom:5mm"><strong style="font-size:12pt; color:{{ $lh['color_text'] }}">{{ $lh['name'] }}</strong></div>
@else
<div style="font-size:7.5pt; color:{{ $lh['accent_text'] }}">@include('documents::letterheads._legal')</div>
@endif
