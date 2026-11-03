<?php

namespace App\Modules\Accounting\Payables;

use App\Models\User;
use App\Modules\Accounting\Application\Core\AuditTrail;
use App\Modules\Accounting\Support\Scoped;
use App\Modules\Stock\Application\ProcurementService;
use App\Modules\Stock\Domain\SupplierInvoice;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Supplier-invoice approval by amount. A chain is an ordered list of roles; the chain with the highest
 * min_amount not above the invoice total applies. Segregation of duties: whoever captured or submitted an invoice
 * cannot approve it, and nobody approves two steps of the same invoice (a delegate counts as both himself and the delegator).
 * With no chain configured for the amount the legacy single approval still works. Final approval posts the invoice
 * through the existing ProcurementService (which raises payables.invoice_approved -> GL).
 */
class InvoiceApprovals
{
    use Scoped;

    public function __construct(TenantContext $ctx, private AuditTrail $audit, private ProcurementService $procurement)
    {
        $this->ctx = $ctx;
    }

    public function chains(): array
    {
        return $this->table('invoice_approval_chains')->orderBy('min_amount')->get()
            ->map(fn ($c) => (array) $c + ['steps_list' => json_decode($c->steps, true)])->all();
    }

    public function saveChain(array $d, ?int $id = null): array
    {
        $steps = array_values(array_map(fn ($s) => ['role' => (string) $s['role'], 'label' => $s['label'] ?? null], $d['steps']));
        foreach ($steps as $s) {
            if (! DB::table('roles')->where('name', $s['role'])->exists()) {
                throw new DomainException("Unknown role {$s['role']}.", 'unknown-role');
            }
        }
        if (isset($d['max_amount']) && $d['max_amount'] < $d['min_amount']) {
            throw new DomainException('max_amount must not be below min_amount.', 'bad-range');
        }
        $row = ['name' => $d['name'], 'min_amount' => $d['min_amount'], 'max_amount' => $d['max_amount'] ?? null, 'steps' => json_encode($steps),
            'active' => $d['active'] ?? true, 'updated_at' => now()];
        if ($id) {
            $this->table('invoice_approval_chains')->where('id', $id)->exists() || throw new DomainException('Chain not found.', 'not-found');
            $this->table('invoice_approval_chains')->where('id', $id)->update($row);
        } else {
            $id = DB::table('invoice_approval_chains')->insertGetId($row + $this->tenant() + ['created_at' => now()]);
        }
        $this->audit->record('approval_chain_saved', 'approval_chain', $id, ['name' => $d['name'], 'min' => $d['min_amount'], 'steps' => $steps]);

        return (array) DB::table('invoice_approval_chains')->find($id);
    }

    public function chainFor(int $total): ?object
    {
        return $this->table('invoice_approval_chains')->where('active', true)->where('min_amount', '<=', $total)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $total))->orderByDesc('min_amount')->first();
    }

    public function submit(SupplierInvoice $inv): array
    {
        if ($inv->status !== 'pending') {
            throw new DomainException('Only pending invoices can be submitted for approval.', 'invalid-state');
        }
        if ($this->table('invoice_workflows')->where('supplier_invoice_id', $inv->id)->exists()) {
            throw new DomainException('This invoice is already in an approval workflow.', 'already-submitted');
        }
        $chain = $this->chainFor((int) $inv->total) ?? throw new DomainException('No approval chain applies to this amount.', 'no-chain');
        $captured = $this->table('supplier_invoice_captures')->where('supplier_invoice_id', $inv->id)->value('captured_by');

        return DB::transaction(function () use ($inv, $chain, $captured) {
            $wf = DB::table('invoice_workflows')->insertGetId($this->tenant() + [
                'supplier_invoice_id' => $inv->id, 'chain_id' => $chain->id, 'captured_by' => $captured, 'submitted_by' => $this->actorId(), ...$this->stamp(),
            ]);
            foreach (json_decode($chain->steps, true) as $i => $s) {
                DB::table('invoice_approvals')->insert($this->tenant() + [
                    'workflow_id' => $wf, 'supplier_invoice_id' => $inv->id, 'step_no' => $i + 1, 'role' => $s['role'], 'label' => $s['label'], ...$this->stamp(),
                ]);
            }
            $this->audit->record('invoice_submitted', 'supplier_invoice', $inv->id, ['chain' => $chain->id, 'total' => $inv->total]);

            return $this->show($inv->id);
        });
    }

    public function show(int $invoiceId): array
    {
        $wf = $this->table('invoice_workflows')->where('supplier_invoice_id', $invoiceId)->first();

        return [
            'workflow' => $wf ? (array) $wf : null,
            'steps' => $wf ? $this->table('invoice_approvals')->leftJoin('users as u', 'u.id', '=', 'invoice_approvals.decided_by')
                ->where('workflow_id', $wf->id)->orderBy('step_no')->get(['invoice_approvals.*', 'u.name as decider'])->map(fn ($r) => (array) $r)->all() : [],
        ];
    }

    /** Pending steps (current step of each workflow) this user may decide, delegations included. */
    public function inbox(User $user): array
    {
        $out = [];
        $current = $this->table('invoice_approvals')->join('invoice_workflows as w', 'w.id', '=', 'invoice_approvals.workflow_id')
            ->join('supplier_invoices as i', 'i.id', '=', 'invoice_approvals.supplier_invoice_id')->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('w.status', 'pending')->where('invoice_approvals.status', 'pending')
            ->get(['invoice_approvals.*', 'i.reference', 'i.total', 'i.date', 's.name as supplier']);
        foreach ($current as $a) {
            if ($this->isCurrent($a) && $this->eligibility($user, $a, $this->workflow($a->workflow_id))['ok']) {
                $out[] = (array) $a;
            }
        }

        return $out;
    }

    public function decide(int $approvalId, bool $approve, ?string $note, User $actor): array
    {
        $a = $this->table('invoice_approvals')->where('id', $approvalId)->first() ?? throw new DomainException('Approval step not found.', 'not-found');
        $wf = $this->workflow($a->workflow_id);
        if ($wf->status !== 'pending' || $a->status !== 'pending') {
            throw new DomainException('This step was already decided.', 'already-decided');
        }
        if (! $this->isCurrent($a)) {
            throw new DomainException('An earlier step is still pending.', 'out-of-order');
        }
        if (! $approve && ! trim((string) $note)) {
            throw new DomainException('A reason is required to reject.', 'reason-required');
        }
        $e = $this->eligibility($actor, $a, $wf);
        if (! $e['ok']) {
            throw new DomainException($e['reason'], $e['code']);
        }

        return DB::transaction(function () use ($a, $wf, $approve, $note, $actor, $e) {
            DB::table('invoice_approvals')->where('id', $a->id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'delegated_from' => $e['delegated_from'], 'decided_at' => now(), 'note' => $note, 'updated_at' => now(),
            ]);
            $this->audit->record($approve ? 'invoice_step_approved' : 'invoice_step_rejected', 'supplier_invoice', $a->supplier_invoice_id,
                ['step' => $a->step_no, 'role' => $a->role, 'delegated_from' => $e['delegated_from'], 'note' => $note]);
            $inv = SupplierInvoice::findOrFail($a->supplier_invoice_id);
            if (! $approve) {
                DB::table('invoice_workflows')->where('id', $wf->id)->update(['status' => 'rejected', 'completed_at' => now(), 'updated_at' => now()]);
                $inv->update(['status' => 'rejected']);
            } elseif (! $this->table('invoice_approvals')->where('workflow_id', $wf->id)->where('status', 'pending')->exists()) {
                DB::table('invoice_workflows')->where('id', $wf->id)->update(['status' => 'approved', 'completed_at' => now(), 'updated_at' => now()]);
                $this->procurement->approveInvoice($inv);
                $this->audit->record('invoice_approved', 'supplier_invoice', $inv->id, ['workflow' => $wf->id]);
            }

            return $this->show($a->supplier_invoice_id);
        });
    }

    /** Called by ProcurementService::approveInvoice: the legacy single approval is refused while a chain applies and is not cleared. */
    public function assertCleared(SupplierInvoice $inv): void
    {
        $wf = DB::table('invoice_workflows')->where(['hotel_id' => $inv->hotel_id, 'supplier_invoice_id' => $inv->id])->first();
        if ($wf) {
            if ($wf->status !== 'approved') {
                throw new DomainException('The approval chain for this invoice is not complete.', 'approval-required');
            }

            return;
        }
        if ($this->chainFor((int) $inv->total)) {
            throw new DomainException('This amount requires the approval chain; submit the invoice for approval.', 'approval-required');
        }
    }

    // ---- delegations ----

    public function delegations(): array
    {
        return $this->table('approval_delegations')->join('users as f', 'f.id', '=', 'from_user_id')->join('users as t', 't.id', '=', 'to_user_id')
            ->orderByDesc('approval_delegations.id')->get(['approval_delegations.*', 'f.name as from_name', 't.name as to_name'])->map(fn ($r) => (array) $r)->all();
    }

    public function delegate(int $to, string $from, string $until, ?string $reason, User $actor, ?int $fromUser = null): array
    {
        $fromUser ??= $actor->id;
        if ($fromUser === $to) {
            throw new DomainException('Cannot delegate to yourself.', 'self-delegation');
        }
        if ($until < $from) {
            throw new DomainException('The end date is before the start date.', 'bad-range');
        }
        $id = DB::table('approval_delegations')->insertGetId($this->tenant() + ['from_user_id' => $fromUser, 'to_user_id' => $to, 'starts_on' => $from, 'ends_on' => $until,
            'reason' => $reason, 'created_by' => $actor->id, ...$this->stamp()]);
        $this->audit->record('delegation_created', 'user', $fromUser, ['to' => $to, 'from' => $from, 'until' => $until]);

        return (array) DB::table('approval_delegations')->find($id);
    }

    public function revokeDelegation(int $id): array
    {
        $this->table('approval_delegations')->where('id', $id)->exists() || throw new DomainException('Delegation not found.', 'not-found');
        $this->table('approval_delegations')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
        $this->audit->record('delegation_revoked', 'delegation', $id);

        return (array) DB::table('approval_delegations')->find($id);
    }

    // ---- internals ----

    private function workflow(int $id): object
    {
        return DB::table('invoice_workflows')->find($id);
    }

    private function isCurrent(object $a): bool
    {
        return ! DB::table('invoice_approvals')->where('workflow_id', $a->workflow_id)->where('step_no', '<', $a->step_no)->where('status', '!=', 'approved')->exists();
    }

    /** @return array{ok: bool, reason?: string, code?: string, delegated_from: ?int} */
    private function eligibility(User $user, object $a, object $wf): array
    {
        $date = $this->ctx->hotelId() ? (string) (DB::table('hotels')->where('id', $this->ctx->hotelId())->value('business_date') ?? now()->toDateString()) : now()->toDateString();
        $via = null;
        if (! $user->roles()->where('name', $a->role)->exists()) {
            $via = DB::table('approval_delegations')->where(['hotel_id' => $a->hotel_id, 'to_user_id' => $user->id, 'active' => true])
                ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->pluck('from_user_id')
                ->first(fn ($from) => DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_id', $from)->where('roles.name', $a->role)->exists());
            if (! $via) {
                return ['ok' => false, 'reason' => "Step requires the role {$a->role}.", 'code' => 'wrong-role', 'delegated_from' => null];
            }
        }
        $identities = array_filter([$user->id, $via]);
        $barred = array_filter([$wf->captured_by, $wf->submitted_by]);
        if (array_intersect($identities, $barred)) {
            return ['ok' => false, 'reason' => 'Segregation of duties: the person who captured or submitted the invoice cannot approve it.', 'code' => 'segregation-of-duties', 'delegated_from' => $via];
        }
        $prior = DB::table('invoice_approvals')->where('workflow_id', $wf->id)->where('status', 'approved')->get(['decided_by', 'delegated_from']);
        $priorIds = $prior->flatMap(fn ($p) => [$p->decided_by, $p->delegated_from])->filter()->all();
        if (array_intersect($identities, $priorIds)) {
            return ['ok' => false, 'reason' => 'Segregation of duties: one person cannot approve two steps of the same invoice.', 'code' => 'segregation-of-duties', 'delegated_from' => $via];
        }

        return ['ok' => true, 'delegated_from' => $via];
    }
}
