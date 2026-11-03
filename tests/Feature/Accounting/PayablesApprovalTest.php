<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Payables\InvoiceExtractor;
use App\Modules\Stock\Domain\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PayablesApprovalTest extends TestCase
{
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::create(['code' => 'S1', 'name' => 'Fournisseur Un', 'tax_id' => 'M0123']);
    }

    private function invoice(int $net, string $ref = 'F-1', array $extra = []): array
    {
        return $this->postJson('/api/v1/payables/invoices', $extra + ['supplier_id' => $this->supplier->id, 'reference' => $ref, 'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'net_total' => $net, 'tax_total' => 0, 'expense_lines' => [['account' => '632000', 'amount' => $net, 'description' => 'Honoraires']]])->assertCreated()->json();
    }

    private function chain(array $extra = []): array
    {
        return $this->postJson('/api/v1/accounting3/payables/approval-chains', $extra + ['name' => 'Gros montants', 'min_amount' => 100_000,
            'steps' => [['role' => 'accountant', 'label' => 'Comptable'], ['role' => 'finance-director', 'label' => 'DAF']]])->assertCreated()->json();
    }

    public function test_chain_is_chosen_by_amount_and_blocks_the_legacy_approval_until_complete(): void
    {
        $this->chain();
        $this->chain(['name' => 'Très gros', 'min_amount' => 1_000_000, 'steps' => [['role' => 'finance-director']]]);
        $small = $this->invoice(50_000, 'SMALL');
        $big = $this->invoice(200_000, 'BIG');
        $huge = $this->invoice(2_000_000, 'HUGE');

        // Below every threshold the normal approval still works.
        $this->postJson("/api/v1/payables/invoices/{$small['id']}/approve")->assertOk();
        // At 200k a chain applies: the single approval is refused and the chain is used.
        $this->postJson("/api/v1/payables/invoices/{$big['id']}/approve")->assertStatus(422);
        $this->postJson("/api/v1/accounting3/payables/invoices/{$small['id']}/submit")->assertStatus(422); // already approved
        $wf = $this->postJson("/api/v1/accounting3/payables/invoices/{$big['id']}/submit")->assertCreated()->json();
        $this->assertCount(2, $wf['steps']);
        $this->assertCount(1, $this->postJson("/api/v1/accounting3/payables/invoices/{$huge['id']}/submit")->json('steps'));
        $this->postJson("/api/v1/accounting3/payables/invoices/{$big['id']}/submit")->assertStatus(422);
        $this->postJson("/api/v1/payables/invoices/{$big['id']}/approve")->assertStatus(422); // chain pending

        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $step = fn ($n) => $wf['steps'][$n - 1]['id'];

        // The second step cannot be decided before the first, and the wrong role is refused.
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$step(2)}/decide", ['approve' => true])->assertStatus(422);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$step(1)}/decide", ['approve' => true])->assertStatus(422);
        $this->assertCount(0, array_filter($this->getJson('/api/v1/accounting3/payables/approvals/inbox')->json(), fn ($a) => $a['supplier_invoice_id'] === $big['id']));

        $this->actAs($acc, $this->hotel);
        $this->assertCount(1, array_filter($this->getJson('/api/v1/accounting3/payables/approvals/inbox')->json(), fn ($a) => $a['supplier_invoice_id'] === $big['id']));
        $this->postJson("/api/v1/accounting3/payables/approvals/{$step(1)}/decide", ['approve' => true, 'note' => 'ok'])->assertOk();
        $this->assertSame('pending', DB::table('supplier_invoices')->where('id', $big['id'])->value('status'));
        $this->assertSame(0, (int) DB::table('journal_entries')->where('source_type', 'supplier_invoice')->where('source_id', $big['id'])->count());

        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$step(2)}/decide", ['approve' => true])->assertOk();
        $this->assertSame('approved', DB::table('supplier_invoices')->where('id', $big['id'])->value('status'));
        $this->assertGreaterThan(0, DB::table('journal_entries')->where('source_type', 'supplier_invoice')->count());
        $b = $this->assertBooksBalance();
        $this->assertSame(-250_000, $b['401100']);

        $log = $this->getJson('/api/v1/accounting/core/audit-log?subject_type=supplier_invoice')->json();
        $actions = array_column($log, 'action');
        foreach (['invoice_submitted', 'invoice_step_approved', 'invoice_approved'] as $a) {
            $this->assertContains($a, $actions);
        }
    }

    public function test_segregation_of_duties_and_rejection(): void
    {
        $this->chain(['steps' => [['role' => 'accountant'], ['role' => 'accountant']]]);
        $inv = $this->invoice(150_000);
        $wf = $this->postJson("/api/v1/accounting3/payables/invoices/{$inv['id']}/submit")->assertCreated()->json(); // submitted by GM ($this->user)
        $a1 = $this->makeUser($this->group, 'accountant', allHotels: true);
        $a2 = $this->makeUser($this->group, 'accountant', allHotels: true);

        // Submitter holds every permission but not the accountant role.
        $this->postJson("/api/v1/accounting3/payables/approvals/{$wf['steps'][0]['id']}/decide", ['approve' => true])->assertStatus(422);

        // One person cannot take both steps.
        $this->actAs($a1, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$wf['steps'][0]['id']}/decide", ['approve' => true])->assertOk();
        $r = $this->postJson("/api/v1/accounting3/payables/approvals/{$wf['steps'][1]['id']}/decide", ['approve' => true])->assertStatus(422);
        $this->assertStringContainsString('Segregation', $r->json('detail') ?? $r->json('title') ?? json_encode($r->json()));

        // A rejection needs a reason and stops the workflow.
        $this->actAs($a2, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$wf['steps'][1]['id']}/decide", ['approve' => false])->assertStatus(422);
        $this->postJson("/api/v1/accounting3/payables/approvals/{$wf['steps'][1]['id']}/decide", ['approve' => false, 'note' => 'Prix excessif'])->assertOk();
        $this->assertSame('rejected', DB::table('supplier_invoices')->where('id', $inv['id'])->value('status'));
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/payables/invoices/{$inv['id']}/approve")->assertStatus(422);
        $this->assertSame(0, (int) DB::table('journal_entries')->where('source_type', 'supplier_invoice')->count());
        $this->assertBooksBalance();
    }

    public function test_capturer_cannot_approve_and_delegation_widens_but_never_bypasses(): void
    {
        $this->chain(['steps' => [['role' => 'accountant']]]);
        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $other = $this->makeUser($this->group, 'receptionist', allHotels: true); // no accountant role
        $this->actAs($acc, $this->hotel);
        $inv = $this->invoice(300_000, 'CAP');
        $wf = $this->postJson("/api/v1/accounting3/payables/invoices/{$inv['id']}/submit")->assertCreated()->json();
        $step = $wf['steps'][0]['id'];
        // The accountant who submitted cannot approve their own invoice.
        $this->postJson("/api/v1/accounting3/payables/approvals/$step/decide", ['approve' => true])->assertStatus(422);

        // A delegate of the submitter inherits the bar (segregation applies to the delegator as well).
        $this->postJson('/api/v1/accounting3/payables/delegations', ['to_user_id' => $other->id, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(5)->toDateString()])->assertCreated();
        $this->actAs($other, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/approvals/$step/decide", ['approve' => true])->assertForbidden(); // receptionist lacks payables.approve

        // A different accountant delegates to the FD who has payables.approve: audit shows the delegator.
        $acc2 = $this->makeUser($this->group, 'accountant', allHotels: true);
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($acc2, $this->hotel);
        $d = $this->postJson('/api/v1/accounting3/payables/delegations', ['to_user_id' => $fd->id, 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(), 'reason' => 'Congés'])->assertCreated()->json();
        $this->actAs($fd, $this->hotel);
        $this->assertCount(1, $this->getJson('/api/v1/accounting3/payables/approvals/inbox')->json());
        $this->postJson("/api/v1/accounting3/payables/approvals/$step/decide", ['approve' => true])->assertOk();
        $this->assertSame($acc2->id, (int) DB::table('invoice_approvals')->where('id', $step)->value('delegated_from'));
        $this->assertSame('approved', DB::table('supplier_invoices')->where('id', $inv['id'])->value('status'));

        // Revoked delegations stop working.
        $this->actAs($acc2, $this->hotel);
        $this->postJson("/api/v1/accounting3/payables/delegations/{$d['id']}/revoke")->assertOk()->assertJsonPath('active', false);
        $this->assertBooksBalance();
    }

    public function test_chain_configuration_is_permissioned_and_validated(): void
    {
        $acc = $this->makeUser($this->group, 'accountant', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->postJson('/api/v1/accounting3/payables/approval-chains', ['name' => 'X', 'min_amount' => 1, 'steps' => [['role' => 'accountant']]])->assertForbidden();
        $this->actAs($this->user, $this->hotel);
        $this->postJson('/api/v1/accounting3/payables/approval-chains', ['name' => 'X', 'min_amount' => 1, 'steps' => [['role' => 'no-such-role']]])->assertStatus(422);
        $this->postJson('/api/v1/accounting3/payables/approval-chains', ['name' => 'X', 'min_amount' => 500, 'max_amount' => 100, 'steps' => [['role' => 'accountant']]])->assertStatus(422);
        $id = $this->chain()['id'];
        $this->putJson("/api/v1/accounting3/payables/approval-chains/$id", ['name' => 'Renamed', 'min_amount' => 200_000, 'steps' => [['role' => 'finance-director']]])->assertOk()->assertJsonPath('name', 'Renamed');
    }

    public function test_capture_with_a_pluggable_extractor_confirm_and_duplicate_detection(): void
    {
        Storage::fake('local');
        $file = fn () => UploadedFile::fake()->createWithContent('facture.pdf', '%PDF-1.4 fake invoice one');

        // Default engine is manual: nothing suggested.
        $c = $this->postJson('/api/v1/accounting3/payables/captures', ['file' => $file()])->assertCreated()->assertJsonPath('status', 'uploaded')->assertJsonPath('engine', 'manual')->json();
        $this->postJson('/api/v1/accounting3/payables/captures', ['file' => $file()])->assertStatus(422); // same document
        $this->getJson("/api/v1/accounting3/payables/captures/{$c['id']}/file")->assertOk();

        // A bound engine pre-fills the fields; the clerk still confirms them.
        $sid = $this->supplier->id;
        app()->bind(InvoiceExtractor::class, fn () => new class($sid) implements InvoiceExtractor
        {
            public function __construct(private int $sid) {}

            public function name(): string
            {
                return 'fake-ocr';
            }

            public function extract(string $absolutePath, string $mime): array
            {
                return ['supplier_id' => $this->sid, 'reference' => 'OCR-77', 'date' => now()->toDateString(), 'net_total' => 80_000, 'tax_total' => 0];
            }
        });
        $c2 = $this->postJson('/api/v1/accounting3/payables/captures', ['file' => UploadedFile::fake()->createWithContent('f2.pdf', '%PDF-1.4 other')])->assertCreated()
            ->assertJsonPath('status', 'extracted')->assertJsonPath('extracted.reference', 'OCR-77')->json();

        $this->postJson("/api/v1/accounting3/payables/captures/{$c2['id']}/convert", ['supplier_id' => $sid, 'reference' => 'OCR-77', 'date' => now()->toDateString(), 'net_total' => 90_000, 'tax_total' => 0, 'total' => 1,
            'expense_lines' => [['account' => '632000', 'amount' => 90_000]]])->assertStatus(422); // total mismatch
        $r = $this->postJson("/api/v1/accounting3/payables/captures/{$c2['id']}/convert", ['supplier_id' => $sid, 'reference' => 'OCR-77', 'date' => now()->toDateString(), 'net_total' => 90_000, 'tax_total' => 0,
            'expense_lines' => [['account' => '632000', 'amount' => 90_000]]])->assertCreated()->json();
        $this->assertSame('converted', $r['capture']['status']);
        $this->assertSame(90_000, $r['invoice']['net_total']);
        $this->assertSame($this->user->id, DB::table('supplier_invoice_captures')->where('id', $c2['id'])->value('captured_by'));
        $this->postJson("/api/v1/accounting3/payables/captures/{$c2['id']}/convert", ['supplier_id' => $sid, 'reference' => 'OCR-78', 'date' => now()->toDateString(), 'net_total' => 1, 'expense_lines' => [['account' => '632000', 'amount' => 1]]])->assertStatus(422);

        // Whoever captured the invoice cannot approve it through the chain.
        $this->chain(['min_amount' => 50_000, 'steps' => [['role' => 'general-manager']]]);
        $wf = $this->postJson("/api/v1/accounting3/payables/invoices/{$r['invoice']['id']}/submit")->assertCreated()->json();
        $this->assertSame($this->user->id, (int) $wf['workflow']['captured_by']);
    }
}
