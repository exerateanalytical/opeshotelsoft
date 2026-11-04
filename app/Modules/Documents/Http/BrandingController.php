<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\Letterhead;
use App\Modules\Documents\Application\Watermark;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Q1: per-hotel letterhead (logo, colours, legal ids, bank details, footer). Requires branding.manage. */
class BrandingController
{
    private const FIELDS = ['legal_name', 'tax_id', 'trade_register', 'address', 'city', 'postal_box', 'phone', 'email',
        'brand_color', 'accent_color', 'bank_details', 'document_footer'];

    public function __construct(private TenantContext $ctx, private EventBus $bus) {}

    public function show(Request $request)
    {
        $this->can($request);

        return $this->payload($this->hotel());
    }

    public function update(Request $request)
    {
        $this->can($request);
        $hex = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:200'], 'tax_id' => ['nullable', 'string', 'max:50'],
            'trade_register' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'], 'postal_box' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'],
            'brand_color' => $hex, 'accent_color' => $hex,
            'bank_details' => ['nullable', 'string', 'max:500'], 'document_footer' => ['nullable', 'string', 'max:500'],
            'letterhead_design' => ['sometimes', Rule::in(Letterhead::DESIGNS)],
        ]);
        $hotel = $this->hotel();
        if (isset($data['letterhead_design'])) {
            $hotel->settings = array_merge($hotel->settings ?? [], ['letterhead_design' => $data['letterhead_design']]);
            unset($data['letterhead_design']);
        }
        $hotel->fill($data)->save();
        $this->bus->record('documents.branding.updated', ['hotel_id' => $hotel->id, 'changes' => array_keys($hotel->getChanges())], 'hotel', $hotel->id);

        return $this->payload($hotel);
    }

    public function uploadLogo(Request $request)
    {
        $this->can($request);
        // PNG/JPEG only: SVG could carry script into rendered documents.
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $hotel = $this->hotel();
        $file = $request->file('logo');
        $ext = strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';
        abort_unless(@getimagesize($file->getRealPath()), 422, 'Not a valid image.');
        $path = "branding/{$hotel->id}/logo-".now()->format('YmdHis').".$ext";
        Storage::disk(Letterhead::LOGO_DISK)->put($path, file_get_contents($file->getRealPath()));
        if ($hotel->logo_path && $hotel->logo_path !== $path) {
            Storage::disk(Letterhead::LOGO_DISK)->delete($hotel->logo_path);
        }
        $hotel->update(['logo_path' => $path]);
        $this->bus->record('documents.branding.logo_uploaded', ['hotel_id' => $hotel->id], 'hotel', $hotel->id);

        return $this->payload($hotel);
    }

    public function uploadSeal(Request $request)
    {
        $this->can($request);
        $request->validate(['seal' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $hotel = $this->hotel();
        $file = $request->file('seal');
        $ext = strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';
        abort_unless(@getimagesize($file->getRealPath()), 422, 'Not a valid image.');
        $path = "branding/{$hotel->id}/seal-".now()->format('YmdHis').".$ext";
        Storage::disk(Letterhead::LOGO_DISK)->put($path, file_get_contents($file->getRealPath()));
        $old = $hotel->settings['seal_path'] ?? null;
        $hotel->update(['settings' => array_merge($hotel->settings ?? [], ['seal_path' => $path])]);
        if ($old && $old !== $path) {
            Storage::disk(Letterhead::LOGO_DISK)->delete($old);
        }
        $this->bus->record('documents.branding.seal_uploaded', ['hotel_id' => $hotel->id], 'hotel', $hotel->id);

        return $this->payload($hotel);
    }

    public function removeSeal(Request $request)
    {
        $this->can($request);
        $hotel = $this->hotel();
        if ($old = $hotel->settings['seal_path'] ?? null) {
            Storage::disk(Letterhead::LOGO_DISK)->delete($old);
            $hotel->update(['settings' => array_diff_key($hotel->settings ?? [], ['seal_path' => 1])]);
        }

        return $this->payload($hotel);
    }

    public function removeLogo(Request $request)
    {
        $this->can($request);
        $hotel = $this->hotel();
        if ($hotel->logo_path) {
            Storage::disk(Letterhead::LOGO_DISK)->delete($hotel->logo_path);
            $hotel->update(['logo_path' => null]);
        }

        return $this->payload($hotel);
    }

    public function designs(Request $request)
    {
        $this->can($request);

        return ['designs' => Letterhead::DESIGNS, 'current' => Letterhead::design($this->hotel())];
    }

    /** Live HTML preview of a design with unsaved colours: {html}. */
    public function preview(Request $request)
    {
        $this->can($request);
        $lh = $this->previewLetterhead($request);
        $lang = $request->query('lang') === 'en' ? 'en' : 'fr';

        return ['html' => view('documents::branding-preview', ['lh' => $lh, 'lang' => $lang])->render()];
    }

    /** A real sample PDF of the chosen design (not issued; carries the watermark like a draft). */
    public function sample(Request $request, DocumentRenderer $engine)
    {
        $this->can($request);
        $lang = $request->query('lang') === 'en' ? 'en' : 'fr';
        $doc = $engine->render('letter', [
            'docTitle' => $lang === 'en' ? 'Sample document' : 'Document exemple',
            'docHtml' => '<p>'.($lang === 'en' ? 'Sample content to check the letterhead, the watermark and the footer.' : 'Contenu exemple pour contrôler l\'en-tête, le filigrane et le pied de page.').'</p>',
        ], ['view' => 'documents::template', 'hotel' => $this->hotel(), 'lang' => $lang, 'issue' => false, 'draft' => true,
            'design' => $request->query('design'), 'title' => $lang === 'en' ? 'Sample' : 'Exemple', 'filename' => 'letterhead-sample']);

        return $engine->response($doc);
    }

    public function watermark(Request $request)
    {
        $this->canWatermark($request);

        return $this->watermarkPayload();
    }

    public function saveWatermark(Request $request)
    {
        $this->canWatermark($request);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['none', 'text', 'image'])],
            'text' => ['nullable', 'string', 'max:60'], 'opacity' => ['required', 'integer', 'min:2', 'max:60'],
            'position' => ['required', Rule::in(Watermark::POSITIONS)], 'apply' => ['required', Rule::in(['all', 'drafts'])],
        ]);
        $data['text'] = (string) ($data['text'] ?? '');
        abort_if($data['mode'] === 'text' && trim($data['text']) === '', 422, 'Text is required for a text watermark.');
        abort_if($data['mode'] === 'image' && ! Watermark::settings($this->group())['image_path'], 422, 'Upload an image first.');
        Watermark::save($this->group(), $data);
        $this->bus->record('documents.watermark.updated', ['hotel_id' => $this->hotel()->id, 'mode' => $data['mode']], 'hotel', $this->hotel()->id);

        return $this->watermarkPayload();
    }

    public function uploadWatermark(Request $request)
    {
        $this->canWatermark($request);
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024']]);
        $file = $request->file('image');
        abort_unless(@getimagesize($file->getRealPath()), 422, 'Not a valid image.');
        $ext = strtolower($file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';
        Watermark::storeImage($this->group(), file_get_contents($file->getRealPath()), $ext);

        return $this->watermarkPayload();
    }

    public function removeWatermark(Request $request)
    {
        $this->canWatermark($request);
        Watermark::removeImage($this->group());

        return $this->watermarkPayload();
    }

    private function watermarkPayload(): array
    {
        $s = Watermark::settings($this->group());

        return ['image' => Watermark::imageUri($s)] + array_diff_key($s, ['image_path' => 1]);
    }

    private function previewLetterhead(Request $request): array
    {
        $lh = Letterhead::for($this->hotel());
        foreach (['color' => 'brand_color', 'accent' => 'accent_color'] as $k => $param) {
            $v = (string) $request->query($param);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                $lh[$k] = $v;
            }
        }
        $design = (string) $request->query('design');
        if (in_array($design, Letterhead::DESIGNS, true)) {
            $lh['design'] = $design;
        }

        return Letterhead::derive($lh);
    }

    private function group(): Group
    {
        return Group::findOrFail($this->hotel()->group_id);
    }

    private function canWatermark(Request $request): void
    {
        abort_unless($request->user()->can('branding.manage') || $request->user()->can('onboarding.manage'), 403);
    }

    private function payload(Hotel $hotel): array
    {
        return $hotel->only(['id', 'name', ...self::FIELDS]) + ['logo' => Letterhead::logoDataUri($hotel), 'seal' => Letterhead::sealDataUri($hotel), 'letterhead_design' => Letterhead::design($hotel)];
    }

    private function hotel(): Hotel
    {
        return Hotel::findOrFail($this->ctx->requireHotelId());
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('branding.manage'), 403);
    }
}
