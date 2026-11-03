<?php

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Domain\IssuedDocument;
use Illuminate\Http\Request;

/** Register of issued documents and its reprint log. Requires documents.verify.log. Tenant-scoped. */
class IssuedController
{
    public function index(Request $request)
    {
        $this->can($request);
        $q = IssuedDocument::query()->orderByDesc('id');
        foreach (['type', 'status'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, (string) $request->query($f));
            }
        }
        if ($request->filled('q')) {
            $q->where('number', 'ilike', '%'.str_replace(['%', '_'], '', (string) $request->query('q')).'%');
        }

        return $q->paginate(min(100, max(1, $request->integer('per_page', 25))));
    }

    public function show(Request $request, int $issued)
    {
        $this->can($request);
        $d = IssuedDocument::with('reprints')->findOrFail($issued);

        return $d->toArray() + ['verify_path' => '/verify/'.$d->code];
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('documents.verify.log'), 403);
    }
}
