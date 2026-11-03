<?php

namespace App\Modules\Edge\Http;

use App\Modules\Edge\Application\CloudSync;
use Illuminate\Http\Request;

/** Cloud endpoints called by edge nodes. Authenticated by signed edge tokens, not user tokens. */
class EdgeSyncController
{
    public function __construct(private CloudSync $sync) {}

    public function push(Request $request)
    {
        $node = $this->sync->authenticate($request);
        $data = $request->validate([
            'batch_key' => ['required', 'string', 'max:80'],
            'from_cursor' => ['required', 'integer', 'min:0'],
            'to_cursor' => ['required', 'integer', 'gte:from_cursor'],
            'changes' => ['present', 'array', 'max:1000'],
            'changes.*.seq' => ['required', 'integer'],
            'changes.*.table' => ['required', 'string', 'max:80'],
            'changes.*.row_id' => ['required', 'integer'],
            'changes.*.op' => ['required', 'in:upsert,delete'],
            'changes.*.changed_at' => ['required', 'string'],
            'changes.*.hotel_id' => ['nullable', 'integer'],
            'changes.*.group_id' => ['nullable', 'integer'],
            'changes.*.data' => ['nullable', 'array'],
        ]);
        // Keep the raw row payload (validation drops nothing, but be explicit about data).
        $data['changes'] = $request->input('changes');

        return response()->json($this->sync->push($node, $data));
    }

    public function pull(Request $request)
    {
        $node = $this->sync->authenticate($request);
        $data = $request->validate([
            'cursor' => ['required', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json($this->sync->pull($node, (int) $data['cursor'], (int) ($data['limit'] ?? 200)));
    }
}
