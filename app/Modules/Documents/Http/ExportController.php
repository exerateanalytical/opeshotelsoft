<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Application\Exporter;
use App\Modules\Documents\Application\ReportRegistry;
use Illuminate\Http\Request;

class ExportController
{
    /** Report keys available for export; each is still permission-checked when run. */
    public function index()
    {
        return collect(ReportRegistry::all())->map(fn ($d, $k) => ['key' => $k, 'title' => $d['title']])->values();
    }

    /** GET /exports/{report}?format=xlsx|csv|pdf&<the report's own params> */
    public function export(Request $request, Exporter $exporter, string $report)
    {
        $request->validate(['format' => ['required', 'in:xlsx,csv,pdf']]);
        $file = $exporter->render($report, $request->except('format'), $request->query('format'));

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
