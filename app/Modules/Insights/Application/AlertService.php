<?php

namespace App\Modules\Insights\Application;

use App\Modules\Insights\Domain\Alert;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;

class AlertService
{
    public function __construct(private TenantContext $ctx) {}

    /**
     * Raise an alert once per dedupe key (re-running a scan or replaying an event never duplicates).
     *
     * @param  array<int, array{label:string, type:string, id:int|string|null, url?:string|null}>  $evidence
     */
    public function raise(string $rule, string $key, string $title, array $data = []): Alert
    {
        $cfg = Rules::ALL[$rule];

        return Alert::firstOrCreate(['dedupe_key' => "$rule:$key"], [
            'rule' => $rule, 'severity' => $data['severity'] ?? $cfg['severity'], 'status' => 'open', 'title' => $title,
            'detail' => $data['detail'] ?? null, 'amount' => (int) ($data['amount'] ?? 0), 'evidence' => $data['evidence'] ?? [],
            'business_date' => $data['business_date'] ?? null, 'source_event_id' => $data['source_event_id'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);
    }

    public function acknowledge(Alert $a, ?string $note): Alert
    {
        if ($a->status !== 'open') {
            throw new DomainException('Only open alerts can be acknowledged.', 'alert-not-open');
        }
        $a->update(['status' => 'acknowledged', 'acknowledged_by' => $this->ctx->actor()?->id, 'acknowledged_at' => now(), 'acknowledge_note' => $note]);

        return $a;
    }

    public function resolve(Alert $a, string $note): Alert
    {
        if ($a->status === 'resolved') {
            throw new DomainException('This alert is already resolved.', 'alert-resolved');
        }
        $a->update([
            'status' => 'resolved', 'resolved_by' => $this->ctx->actor()?->id, 'resolved_at' => now(), 'resolution_note' => $note,
            'acknowledged_by' => $a->acknowledged_by ?? $this->ctx->actor()?->id, 'acknowledged_at' => $a->acknowledged_at ?? now(),
        ]);

        return $a;
    }
}
