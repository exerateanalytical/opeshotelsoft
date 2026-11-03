<?php

namespace App\Modules\Accounting\Application\Core;

use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Append-only accounting audit log (a database trigger rejects UPDATE/DELETE). */
class AuditTrail
{
    public function __construct(private TenantContext $ctx) {}

    public function record(string $action, string $subjectType, int|string $subjectId, array $payload = []): void
    {
        $hotelId = $this->ctx->hotelId();
        if (! $hotelId) {
            return;
        }
        DB::table('accounting_audit_log')->insert([
            'group_id' => $this->ctx->groupId(), 'hotel_id' => $hotelId, 'actor_id' => $this->ctx->actor()?->id,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => (string) $subjectId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }

    public function for(string $subjectType, int|string $subjectId): array
    {
        return DB::table('accounting_audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.hotel_id', $this->ctx->requireHotelId())->where(['a.subject_type' => $subjectType, 'a.subject_id' => (string) $subjectId])
            ->orderBy('a.id')->get(['a.id', 'a.action', 'a.actor_id', 'u.name as actor', 'a.payload', 'a.created_at'])
            ->map(fn ($r) => $this->decode($r))->all();
    }

    public function recent(int $limit = 100, ?string $subjectType = null): array
    {
        return DB::table('accounting_audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.hotel_id', $this->ctx->requireHotelId())->when($subjectType, fn ($q, $v) => $q->where('a.subject_type', $v))
            ->orderByDesc('a.id')->limit($limit)
            ->get(['a.id', 'a.action', 'a.subject_type', 'a.subject_id', 'u.name as actor', 'a.payload', 'a.created_at'])
            ->map(fn ($r) => $this->decode($r))->all();
    }

    private function decode(object $r): array
    {
        $r = (array) $r;
        $r['payload'] = json_decode($r['payload'] ?? 'null', true);

        return $r;
    }
}
