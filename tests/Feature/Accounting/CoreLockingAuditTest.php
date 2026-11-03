<?php

namespace Tests\Feature\Accounting;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoreLockingAuditTest extends TestCase
{
    private function entry(string $date, int $amount = 1_000): array
    {
        return $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'T', 'lines' => [
            ['account' => '521100', 'debit' => $amount], ['account' => '706800', 'credit' => $amount],
        ]])->assertCreated()->json();
    }

    public function test_period_close_and_reopen_need_a_second_approver_and_leave_an_audit_trail(): void
    {
        $this->entry('2026-01-10');
        $req = $this->postJson('/api/v1/accounting/core/period-locks', ['year' => 2026, 'month' => 1, 'action' => 'close'])->assertCreated()->json();
        $this->assertSame('pending', $req['status']);
        // Duplicate pending request refused, and the requester cannot approve their own request.
        $this->postJson('/api/v1/accounting/core/period-locks', ['year' => 2026, 'month' => 1, 'action' => 'close'])->assertStatus(422);
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => true])->assertStatus(422);
        $this->entry('2026-01-12'); // still open

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $accountant = $this->makeUser($this->group, 'accountant', allHotels: true);
        $this->actAs($accountant, $this->hotel);
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => true])->assertForbidden(); // no accounting.period_approve
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => true, 'note' => 'ok'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2026-01-20', 'description' => 'late', 'lines' => [['account' => '521100', 'debit' => 5], ['account' => '706800', 'credit' => 5]]])->assertStatus(422);

        // Reopen needs a reason, then a different approver.
        $this->actAs($this->user, $this->hotel);
        $this->postJson('/api/v1/accounting/core/period-locks', ['year' => 2026, 'month' => 1, 'action' => 'reopen'])->assertStatus(422);
        $reopen = $this->postJson('/api/v1/accounting/core/period-locks', ['year' => 2026, 'month' => 1, 'action' => 'reopen', 'reason' => 'Facture oubliee'])->assertCreated()->json();
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting/core/period-locks/{$reopen['id']}/decide", ['approve' => true])->assertOk();
        $this->entry('2026-01-20');

        $log = $this->getJson('/api/v1/accounting/core/audit-log?subject_type=fiscal_period')->assertOk()->json();
        $actions = array_column($log, 'action');
        foreach (['lock_requested', 'period_closed', 'period_reopened'] as $a) {
            $this->assertContains($a, $actions);
        }
        $reopened = collect($log)->firstWhere('action', 'period_reopened');
        $this->assertSame('Facture oubliee', $reopened['payload']['reason']);
        $this->assertSame($this->user->id, $reopened['payload']['requested_by']);
        $this->assertBooksBalance();
    }

    public function test_rejected_request_changes_nothing(): void
    {
        $req = $this->postJson('/api/v1/accounting/core/period-locks', ['year' => 2026, 'month' => 2, 'action' => 'close'])->assertCreated()->json();
        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => false, 'note' => 'not yet'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertFalse(DB::table('fiscal_periods')->where(['year' => 2026, 'month' => 2, 'status' => 'closed'])->exists());
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => true])->assertStatus(422);
    }

    public function test_manual_entry_requires_attachment_and_has_a_full_trail_and_no_delete(): void
    {
        Storage::fake('local');
        $lines = [['account' => '521100', 'debit' => 40_000], ['account' => '706800', 'credit' => 40_000]];
        $base = ['journal' => 'OD', 'date' => '2026-02-02', 'description' => 'Correction', 'lines' => $lines];
        $this->postJson('/api/v1/accounting/core/entries', $base)->assertStatus(422);

        $res = $this->post('/api/v1/accounting/core/entries', $base + ['attachments' => [UploadedFile::fake()->create('facture.pdf', 20, 'application/pdf')]], ['Accept' => 'application/json'])->assertCreated()->json();
        $entry = $res['id'];
        $att = $this->getJson("/api/v1/accounting/core/entries/$entry/attachments")->assertOk()->json();
        $this->assertCount(1, $att);
        $this->assertSame(64, strlen($att[0]['sha256']));
        $this->post("/api/v1/accounting/core/entries/$entry/attachments", ['file' => UploadedFile::fake()->image('scan.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->get("/api/v1/accounting/core/attachments/{$att[0]['id']}")->assertOk();

        $this->postJson("/api/v1/accounting/core/entries/$entry/reverse", ['reason' => 'erreur de saisie'])->assertCreated();
        $trail = $this->getJson("/api/v1/accounting/core/entries/$entry/audit")->assertOk()->json();
        $this->assertSame(['posted', 'attachment_added', 'attachment_added', 'attachment_downloaded', 'reversed'], array_column($trail['trail'], 'action'));
        $this->assertCount(2, $trail['attachments']);
        $this->assertSame($this->user->id, $trail['entry']['created_by']);

        // Reversal only: the DELETE route does not exist, and the audit log is append-only at database level.
        $this->deleteJson("/api/v1/accounting/entries/$entry")->assertStatus(405);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('accounting_audit_log')->delete()));
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('accounting_audit_log')->update(['action' => 'x'])));
        $this->assertBooksBalance();
    }

    public function test_every_automatic_posting_is_audited_too(): void
    {
        $e = $this->entry('2026-03-03');
        $trail = $this->getJson("/api/v1/accounting/core/entries/{$e['id']}/audit")->assertOk()->json('trail');
        $this->assertSame('posted', $trail[0]['action']);
        $this->assertSame($this->user->id, $trail[0]['actor_id']);
    }
}
