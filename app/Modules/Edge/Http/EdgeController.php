<?php

namespace App\Modules\Edge\Http;

use App\Modules\Edge\Application\CloudSync;
use App\Modules\Edge\Application\EdgeSyncer;
use App\Modules\Edge\Domain\EdgeConflict;
use App\Modules\Edge\Domain\EdgeNode;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Staff API behind the sync status screen. Requires edge.manage. */
class EdgeController
{
    public function __construct(private TenantContext $ctx) {}

    public function status(Request $request)
    {
        $this->can($request);
        $edge = (bool) config('edge.mode');
        $out = ['mode' => $edge ? 'edge' : 'cloud', 'hotel_id' => $this->ctx->hotelId()];

        if ($edge) {
            $push = (int) EdgeSyncer::get('push_cursor', 0);
            $out['edge'] = [
                'node_id' => config('edge.node_id'), 'hotel_id' => config('edge.hotel_id'), 'cloud_url' => config('edge.cloud_url'),
                'push_cursor' => $push, 'pull_cursor' => (int) EdgeSyncer::get('pull_cursor', 0),
                'pending' => DB::table('edge_changes')->where('id', '>', $push)->whereNull('origin_node_id')->count(),
                'last_sync_at' => EdgeSyncer::get('last_sync_at'), 'last_attempt_at' => EdgeSyncer::get('last_attempt_at'),
                'last_error' => EdgeSyncer::get('last_error'), 'last_result' => json_decode((string) EdgeSyncer::get('last_result'), true),
                'number_range_start' => (int) config('edge.number_range_start'),
            ];
        } else {
            $out['nodes'] = EdgeNode::orderBy('id')->get()->map(fn (EdgeNode $n) => $n->only([
                'id', 'hotel_id', 'name', 'status', 'push_cursor', 'pull_cursor', 'last_push_at', 'last_pull_at', 'created_at',
            ]) + ['lag' => DB::table('edge_changes')->where('hotel_id', $n->hotel_id)->where('id', '>', $n->pull_cursor)
                ->where(fn ($q) => $q->whereNull('origin_node_id')->orWhere('origin_node_id', '!=', $n->id))->count()]);
        }
        $out['conflicts'] = EdgeConflict::orderByDesc('id')->limit(50)->get(['id', 'side', 'table_name', 'row_id', 'rule', 'detail', 'created_at']);

        return $out;
    }

    /** Register this hotel's edge node (cloud only). The secret is returned once. */
    public function register(Request $request, CloudSync $sync)
    {
        $this->can($request);
        abort_if(config('edge.mode'), 409, 'Nodes are registered on the cloud.');
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $hotel = Hotel::findOrFail($this->ctx->requireHotelId());
        abort_if(EdgeNode::where('hotel_id', $hotel->id)->exists(), 409, 'This hotel already has an edge node.');
        [$node, $secret] = $sync->register($hotel, $data['name']);

        return response()->json(['id' => $node->id, 'name' => $node->name, 'hotel_id' => $node->hotel_id, 'secret' => $secret], 201);
    }

    public function revoke(Request $request, int $node)
    {
        $this->can($request);
        $n = EdgeNode::findOrFail($node);
        $n->update(['status' => 'revoked']);

        return $n->only(['id', 'status']);
    }

    /** Run a sync now (edge only). */
    public function syncNow(Request $request, EdgeSyncer $syncer)
    {
        $this->can($request);
        abort_unless(config('edge.mode'), 409, 'Sync runs on the edge node.');
        try {
            return ['ok' => true, 'result' => $syncer->run()];
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->can('edge.manage'), 403);
    }
}
