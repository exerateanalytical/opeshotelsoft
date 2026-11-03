<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\DefaultTemplates;
use App\Modules\Documents\Application\InternalApi;
use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\Documents\Domain\DocumentTemplate;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Q1: document-template editor. Texts use {{ group.key }} placeholders from a
 * whitelist (TemplateRenderer); nothing a user types is executed.
 */
class TemplateController
{
    public function __construct(private TenantContext $ctx, private EventBus $bus, private InternalApi $api) {}

    public function index(Request $request)
    {
        $this->can($request);
        $out = [];
        foreach (DefaultTemplates::KINDS as $kind) {
            foreach (DefaultTemplates::LOCALES as $locale) {
                $tpl = TemplateRenderer::template($kind, $locale);
                $out[] = ['kind' => $kind, 'locale' => $locale] + $tpl + [
                    'placeholders' => TemplateRenderer::placeholders($kind),
                    'default' => DefaultTemplates::get($kind, $locale),
                ];
            }
        }

        return ['templates' => $out];
    }

    public function update(Request $request, string $kind, string $locale)
    {
        $this->can($request);
        $this->assertKind($kind, $locale);
        $data = $this->validated($request, $kind);
        $tpl = DocumentTemplate::updateOrCreate(['kind' => $kind, 'locale' => $locale],
            ['title' => $data['title'] ?? null, 'body' => $data['body'], 'updated_by' => $request->user()->id]);
        $this->bus->record('documents.template.saved', ['kind' => $kind, 'locale' => $locale], 'document_template', $tpl->id);

        return TemplateRenderer::template($kind, $locale) + ['kind' => $kind, 'locale' => $locale];
    }

    /** Reset to default: the custom text is removed and the built-in one applies again. */
    public function reset(Request $request, string $kind, string $locale)
    {
        $this->can($request);
        $this->assertKind($kind, $locale);
        DocumentTemplate::where('kind', $kind)->where('locale', $locale)->get()->each->delete();
        $this->bus->record('documents.template.reset', ['kind' => $kind, 'locale' => $locale]);

        return TemplateRenderer::template($kind, $locale) + ['kind' => $kind, 'locale' => $locale];
    }

    /** Live preview with sample data (HTML fragment, or a PDF with ?format=pdf). */
    public function preview(Request $request)
    {
        $this->can($request);
        $kind = (string) $request->input('kind');
        $locale = (string) $request->input('locale', 'fr');
        $this->assertKind($kind, $locale);
        $data = $this->validated($request, $kind);
        $html = TemplateRenderer::render($kind, $data['body'], TemplateRenderer::sample($this->hotel()));
        if ($request->input('format') === 'pdf') {
            return $this->pdf($data['title'] ?? $kind, $html, $locale, 'preview-'.$kind);
        }

        return ['html' => $html];
    }

    /**
     * Print a quote, confirmation or letter. With reservation_id / guest_id the
     * resource is loaded through its own API (its permission check applies).
     */
    public function print(Request $request, string $kind)
    {
        abort_unless(in_array($kind, ['quote', 'confirmation', 'letter'], true), 404);
        $reservation = $guest = null;
        if ($request->filled('reservation_id')) {
            $reservation = $this->api->get('reservations/'.$request->integer('reservation_id'));
            $guest = $reservation['guest'] ?? null;
        } elseif ($request->filled('guest_id')) {
            $guest = $this->api->get('guests/'.$request->integer('guest_id'));
        } else {
            $this->can($request);
        }
        abort_if(in_array($kind, ['quote', 'confirmation'], true) && ! $reservation, 422, 'reservation_id is required.');
        $locale = in_array($request->query('lang'), DefaultTemplates::LOCALES, true) ? $request->query('lang')
            : (in_array($guest['language'] ?? null, DefaultTemplates::LOCALES, true) ? $guest['language'] : 'fr');
        $r = TemplateRenderer::renderFor($kind, $locale, TemplateRenderer::values($this->hotel(), $guest, $reservation));

        return $this->pdf($r['title'], $r['html'], $locale, $kind.'-'.($reservation['number'] ?? $guest['id'] ?? 'doc'),
            $reservation ? ['type' => $kind, 'number' => $kind === 'quote' ? 'Q-'.$reservation['number'] : 'C-'.$reservation['number'], 'source_id' => (int) $reservation['id']] : ['type' => $kind, 'issue' => false]);
    }

    private function pdf(string $title, string $html, string $locale, string $name, array $meta = [])
    {
        $engine = app(\App\Modules\Documents\Application\DocumentRenderer::class);
        $doc = $engine->render($meta['type'] ?? 'letter', ['docTitle' => $title, 'docHtml' => $html], $meta + [
            'view' => 'documents::template', 'hotel' => $this->hotel(), 'lang' => $locale, 'title' => $title, 'filename' => $name, 'issue' => false,
        ]);

        return $engine->response($doc);
    }

    private function validated(Request $request, string $kind): array
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:150'], 'body' => ['required', 'string', 'max:20000']]);
        if ($bad = TemplateRenderer::unknown($kind, $data['body'])) {
            throw ValidationException::withMessages(['body' => ['Unknown or unsafe placeholders: '.implode(', ', $bad)]]);
        }

        return $data;
    }

    private function assertKind(string $kind, string $locale): void
    {
        validator(['kind' => $kind, 'locale' => $locale], [
            'kind' => ['required', Rule::in(DefaultTemplates::KINDS)], 'locale' => ['required', Rule::in(DefaultTemplates::LOCALES)],
        ])->validate();
    }

    private function hotel(): ?Hotel
    {
        return $this->ctx->hotelId() ? Hotel::find($this->ctx->hotelId()) : null;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('branding.manage'), 403);
    }
}
