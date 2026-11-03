{{-- Shared print layout of the document engine. Data comes from DocumentRenderer ($engine); see docs/DOCUMENTS.md. --}}
@php
    $tt = $t ?? fn ($fr, $en) => $fr;
    $e = $engine ?? \App\Modules\Documents\Application\DocumentRenderer::bareEngine($hotel ?? null, $lang ?? 'fr');
    $size = $e['size'];
    $compact = in_array($size, ['80mm', 'id1'], true);
    $margins = match ($size) { '80mm' => '4mm 3mm 24mm 3mm', 'id1' => '0', default => '18mm 15mm 32mm 15mm' };
    $footBottom = match ($size) { '80mm' => '-21mm', 'id1' => '1.5mm', default => '-28mm' };
    $lh = $e['lh'];
    $wm = $e['watermark'];
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'fr' }}">
<head>
<meta charset="utf-8">
<title>@yield('title')</title>
<style>
    @page { margin: {{ $margins }}; }
    body { font-family: DejaVu Sans, sans-serif; font-size: {{ $size === '80mm' ? '8pt' : '9.5pt' }}; color: #1f2937; }
    h1 { font-size: 15pt; margin: 0 0 2mm 0; }
    h2 { font-size: 11pt; margin: 5mm 0 2mm 0; }
    .muted { color: #6b7280; }
    .small { font-size: 8pt; }
    .head { width: 100%; border-bottom: 1px solid #d1d5db; padding-bottom: 3mm; margin-bottom: 4mm; }
    .head td { vertical-align: top; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { text-align: left; font-size: 8pt; text-transform: uppercase; color: #4b5563; border-bottom: 1px solid #9ca3af; padding: 1.5mm 1.5mm; }
    table.grid td { border-bottom: 1px solid #e5e7eb; padding: 1.5mm 1.5mm; }
    .r { text-align: right; }
    .tot td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
    .box { border: 1px solid #d1d5db; padding: 3mm; }
    .kv td { padding: 0.8mm 2mm 0.8mm 0; }
    .kv td.k { color: #6b7280; width: 38%; }
    .engine-foot { position: fixed; bottom: {{ $footBottom }}; left: 0; right: 0; font-size: 6.5pt; color: #6b7280; }
    .engine-wm { position: fixed; top: 0; left: 0; right: 0; bottom: 0; }
</style>
</head>
<body>
@if($wm)
    @php
        $wmCss = 'color: rgba(75,85,99,'.($wm['opacity'] / 100).'); font-weight: bold; white-space: nowrap;';
        $wmItem = fn ($size) => $wm['mode'] === 'image'
            ? '<img src="'.$wm['image'].'" style="opacity:'.($wm['opacity'] / 100).'; max-width:'.$size.'mm; max-height:'.$size.'mm" alt="">'
            : '<span style="'.$wmCss.' font-size:'.($size * 0.9).'pt">'.e($wm['text']).'</span>';
        $pw = $size === 'a4-landscape' ? 297 : ($size === '80mm' ? 80 : ($size === 'id1' ? 86 : 210));
        $ph = $size === 'a4-landscape' ? 210 : ($size === '80mm' ? 200 : ($size === 'id1' ? 54 : 297));
    @endphp
    <div class="engine-wm" data-watermark="{{ $wm['position'] }}">
    @if($wm['position'] === 'tile')
        <table style="width:100%; height:100%; border-collapse:collapse">
        @for($r = 0; $r < ($compact ? 3 : 5); $r++)<tr>@for($c = 0; $c < ($compact ? 1 : 3); $c++)<td style="text-align:center; vertical-align:middle">{!! $wmItem($compact ? 20 : 34) !!}</td>@endfor</tr>@endfor
        </table>
    @else
        <div style="position:absolute; top:{{ $ph * 0.42 }}mm; left:0; right:0; text-align:center; @if($wm['position'] === 'diagonal') transform: rotate(-35deg); @endif">{!! $wmItem($compact ? 40 : 110) !!}</div>
    @endif
    </div>
@endif

@unless($compact)
    @include('documents::partials.letterhead', ['lh' => $lh, 'lt' => $tt, 'part' => 'head', 'docTitle' => $e['title']])
@else
    @if($size === '80mm')
    <div style="text-align:center; margin-bottom:2mm"><strong>{{ $lh['name'] }}</strong><br><span class="small">{{ $lh['address'] }} {{ $lh['phone'] }}</span>@if($lh['niu'])<br><span class="small">NIU {{ $lh['niu'] }}</span>@endif</div>
    @endif
@endunless

<div style="@if($e['design'] === 'executive' && ! $compact) margin-left:18mm; @endif">
@yield('content')
@if(!empty($templateNote))
<div class="small" style="margin-top:6mm">{!! $templateNote !!}</div>
@endif
</div>

<div class="engine-foot" data-engine="foot">
@if($size === 'id1')
    @if($e['qr'])<img src="{{ $e['qr'] }}" style="width:9mm; height:9mm; margin-left:2mm" alt="">@endif
    <span>{{ $e['number'] }}@if($e['copy'] > 0) · {{ $tt('Duplicata', 'Copy') }} {{ $e['copy'] }}@endif</span>
@else
    @unless($compact)
        @include('documents::partials.letterhead', ['lh' => $lh, 'lt' => $tt, 'part' => 'foot', 'docTitle' => $e['title']])
    @endunless
    <table style="width:100%; border-collapse:collapse; margin-top:1.5mm"><tr>
        <td style="vertical-align:middle; font-size:6.5pt; color:#6b7280">
            @if($e['copy'] > 0)<strong style="color:#b91c1c; font-size:8pt">{{ $tt('DUPLICATA', 'COPY') }} {{ $e['copy'] }}</strong> — {{ $tt('réimpression, original émis le', 'reprint, original issued on') }} {{ $e['stamp_local'] }}<br>@endif
            @if($e['number']){{ $e['type_label'] }} {{ $e['number'] }} · @endif{{ $tt('Émis le', 'Issued') }} {{ $e['stamp_local'] }} ({{ $e['tz'] }}) · {{ $e['stamp_utc'] }} UTC<br>
            @if($e['verify_url']){{ $tt('Vérifier', 'Verify') }} : {{ $e['verify_url_short'] }} · SHA-256 {{ $e['hash_short'] }}<br>@endif
            @php($brandMark = is_file(public_path('brand/emblem-64.png')) ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('brand/emblem-64.png'))) : null)
            <span style="font-size:6pt; color:#9ca3af">@if($brandMark)<img src="{{ $brandMark }}" style="width:2.6mm; height:2.6mm; vertical-align:middle; margin-right:1mm" alt="">@endif{{ $tt('Propulsé par', 'Powered by') }} OPES Hôtels Soft</span>
        </td>
        @if($e['qr'])<td style="width:{{ $compact ? 18 : 17 }}mm; text-align:right; vertical-align:middle"><img src="{{ $e['qr'] }}" style="width:{{ $compact ? 17 : 16 }}mm; height:{{ $compact ? 17 : 16 }}mm" alt=""></td>@endif
    </tr></table>
@endif
</div>
</body>
</html>
