<?php

namespace App\Modules\Onboarding\Http;

use App\Modules\Onboarding\Application\CopySetup;
use App\Modules\Onboarding\Application\Importer;
use App\Modules\Onboarding\Application\SetupWizard;
use App\Modules\Onboarding\Domain\ImportBatch;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Q1 onboarding API: wizard progress, go-live, imports, copy setup. Requires onboarding.manage. */
class OnboardingController
{
    public function __construct(private TenantContext $ctx, private SetupWizard $wizard) {}

    public function state(Request $request)
    {
        $this->can($request);

        return $this->wizard->state($this->hotel());
    }

    public function save(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'current_step' => ['sometimes', Rule::in(SetupWizard::STEPS)],
            'complete' => ['sometimes', 'array'], 'complete.*' => [Rule::in(SetupWizard::STEPS)],
            'reopen' => ['sometimes', 'array'], 'reopen.*' => [Rule::in(SetupWizard::STEPS)],
            'data' => ['sometimes', 'array'],
        ]);
        $this->wizard->save($this->hotel(), $data);

        return $this->wizard->state($this->hotel());
    }

    public function group(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'legal_name' => ['nullable', 'string', 'max:200'],
            'country' => ['sometimes', Rule::in(['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'])],
            'default_currency' => ['sometimes', 'string', 'size:3'], 'default_locale' => ['sometimes', Rule::in(['fr', 'en'])],
        ]);
        $group = $this->hotel()->group()->firstOrFail();
        $group->update($data);

        return $group->only(['id', 'name', 'legal_name', 'country', 'default_currency', 'default_locale']);
    }

    public function goLive(Request $request)
    {
        $this->can($request);
        $this->wizard->goLive($this->hotel(), $request->user());

        return $this->wizard->state($this->hotel());
    }

    public function importTemplate(Request $request, string $kind)
    {
        $this->can($request);
        $spec = Importer::KINDS[$kind] ?? abort(404);
        $cols = array_merge($spec['required'], $spec['optional']);
        $csv = "\u{FEFF}".implode(';', $cols)."\n".implode(';', array_pad($spec['sample'], count($cols), ''))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"import-$kind.csv\""]);
    }

    public function import(Request $request, Importer $importer, string $kind)
    {
        $this->can($request);
        abort_unless(isset(Importer::KINDS[$kind]), 404);
        $request->validate([
            'file' => ['required', 'file', 'max:5120'], 'dry_run' => ['sometimes', 'boolean'], 'date' => ['sometimes', 'date'],
        ]);
        $file = $request->file('file');
        try {
            $parsed = $importer->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        abort_if(count($parsed['rows']) > 5000, 422, 'At most 5000 rows per file.');
        $batch = $importer->run($kind, $parsed, $request->boolean('dry_run', true), $request->user(), $file->getClientOriginalName(),
            array_filter(['date' => $request->input('date')]));

        return response()->json($batch, $batch->status === 'rejected' ? 422 : 200);
    }

    public function imports(Request $request)
    {
        $this->can($request);

        return ImportBatch::orderByDesc('id')->limit(50)->get();
    }

    public function copy(Request $request, CopySetup $copy)
    {
        $this->can($request);
        $data = $request->validate([
            'source_hotel_id' => ['required', 'integer'],
            'parts' => ['required', 'array', 'min:1'], 'parts.*' => [Rule::in(CopySetup::PARTS)],
        ]);
        $source = Hotel::findOrFail($data['source_hotel_id']); // group + allowed-hotel scope applies

        return ['result' => $copy->copy($source, $this->hotel(), $data['parts'], $request->user())];
    }

    private function hotel(): Hotel
    {
        return Hotel::findOrFail($this->ctx->requireHotelId());
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('onboarding.manage'), 403);
    }
}
