<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A-C: the legacy accounting endpoints enforce the same controls as accounting/core. */
class ControlsHardeningTest extends TestCase
{
    private function lines(int $amount = 1_000): array
    {
        return [['account' => '521100', 'debit' => $amount], ['account' => '706800', 'credit' => $amount]];
    }

    private function legacy(string $date = '2026-01-10', ?array $lines = null)
    {
        return $this->postJson('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => $date, 'description' => 'T', 'lines' => $lines ?? $this->lines()]);
    }

    private function strict(bool $strict = true): void
    {
        $this->hotel->settings = ['accounting.attachments_optional' => ! $strict];
        $this->hotel->save();
    }

    public function test_attachment_rule_follows_the_hotel_setting(): void
    {
        Storage::fake('local');
        $this->strict();
        $this->legacy()->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/attachment-required');
        $this->assertSame(0, JournalEntry::count());

        // Multipart with a document is accepted on the strict hotel, and the document is kept with the entry.
        $r = $this->post('/api/v1/accounting/entries', ['journal' => 'OD', 'date' => '2026-01-10', 'description' => 'Avec piece',
            'lines' => json_encode($this->lines()), 'attachments' => [UploadedFile::fake()->create('facture.pdf', 20, 'application/pdf')]])->assertCreated()->json();
        $this->assertSame(1, DB::table('journal_attachments')->where('journal_entry_id', $r['id'])->count());

        $this->strict(false);
        $this->legacy()->assertCreated();
        $this->assertTrue(DB::table('accounting_audit_log')->where(['action' => 'posted', 'subject_type' => 'journal_entry'])->count() >= 2);
        $this->assertBooksBalance();
    }

    public function test_hotels_created_without_the_setting_are_strict(): void
    {
        $this->hotel->settings = [];
        $this->hotel->save();
        $this->legacy()->assertStatus(422);
    }

    public function test_unbalanced_and_unauthorised_entries_are_refused(): void
    {
        $this->legacy('2026-01-10', [['account' => '521100', 'debit' => 1_000], ['account' => '706800', 'credit' => 900]])
            ->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/unbalanced-entry');
        $this->assertSame(0, JournalEntry::count());

        $auditor = $this->makeUser($this->group, 'auditor', allHotels: true);
        $this->actAs($auditor, $this->hotel);
        $this->legacy()->assertForbidden();
        $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_locked_period_refuses_postings_and_reversals(): void
    {
        $e = $this->legacy('2026-01-10')->assertCreated()->json();
        $this->postJson('/api/v1/accounting/periods/close', ['year' => 2026, 'month' => 1])->assertOk();
        $this->assertTrue(DB::table('accounting_audit_log')->where(['action' => 'period_closed', 'subject_id' => '2026-1'])->exists());
        $this->legacy('2026-01-20')->assertStatus(422)->assertJsonPath('type', 'https://docs.opeshotelsoft.test/problems/period-closed');
        // Reversal dated inside the closed period is refused and the entry stays un-reversed.
        $this->postJson("/api/v1/accounting/entries/{$e['id']}/reverse", ['reason' => 'erreur'])->assertStatus(422);
        $this->assertFalse((bool) JournalEntry::find($e['id'])->reversed);
        // Reversal dated in an open period is the correct accounting answer.
        $this->postJson("/api/v1/accounting/entries/{$e['id']}/reverse", ['reason' => 'erreur', 'date' => '2026-02-02'])->assertCreated();
        $this->assertBooksBalance();
    }

    public function test_reversal_edge_cases(): void
    {
        $e = $this->legacy('2026-01-10')->assertCreated()->json();
        $rev = $this->postJson("/api/v1/accounting/entries/{$e['id']}/reverse", ['reason' => 'x'])->assertCreated()->json();
        $this->postJson("/api/v1/accounting/entries/{$e['id']}/reverse", ['reason' => 'again'])->assertStatus(422); // double reversal
        $this->postJson("/api/v1/accounting/core/entries/{$e['id']}/reverse", ['reason' => 'again'])->assertStatus(422);
        $this->postJson("/api/v1/accounting/entries/{$rev['id']}/reverse", ['reason' => 'rev of rev'])->assertStatus(422);
        $this->postJson('/api/v1/accounting/entries/'.$e['id'].'/reverse', [])->assertStatus(422); // reason required
        $this->assertSame(2, JournalEntry::count());
        $balances = $this->assertBooksBalance();
        $this->assertSame(0, $balances['521100'] ?? 0);
    }

    public function test_posted_entries_cannot_be_edited_or_deleted_through_the_api(): void
    {
        $e = $this->legacy()->assertCreated()->json();
        $this->putJson("/api/v1/accounting/entries/{$e['id']}", ['description' => 'x'])->assertStatus(405);
        $this->deleteJson("/api/v1/accounting/entries/{$e['id']}")->assertStatus(405);
        $this->deleteJson("/api/v1/accounting/core/attachments/1")->assertStatus(405);
    }

    public function test_reopen_needs_reason_permission_and_a_second_approver(): void
    {
        $this->legacy('2026-01-10')->assertCreated();
        $this->postJson('/api/v1/accounting/periods/close', ['year' => 2026, 'month' => 1])->assertOk();

        $fd1 = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd2 = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($fd1, $this->hotel);
        $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1])->assertStatus(422); // reason required
        $req = $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1, 'reason' => 'Facture oubliee'])
            ->assertStatus(202)->assertJsonPath('status', 'pending')->json('request');
        $this->assertSame('closed', DB::table('fiscal_periods')->where(['hotel_id' => $this->hotel->id, 'year' => 2026, 'month' => 1])->value('status'));
        // The requester cannot approve their own request, by either path.
        $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1, 'reason' => 'again'])->assertStatus(422);
        $this->postJson("/api/v1/accounting/core/period-locks/{$req['id']}/decide", ['approve' => true])->assertStatus(422);
        $this->legacy('2026-01-20')->assertStatus(422);

        // An accountant has no accounting.period_approve.
        $this->actAs($this->makeUser($this->group, 'accountant', allHotels: true), $this->hotel);
        $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1, 'reason' => 'x'])->assertForbidden();

        $this->actAs($fd2, $this->hotel);
        $this->postJson('/api/v1/accounting/periods/reopen', ['year' => 2026, 'month' => 1, 'reason' => 'Vu'])->assertOk()
            ->assertJsonPath('status', 'open')->assertJsonPath('request.decided_by', $fd2->id);
        $this->assertSame('open', DB::table('fiscal_periods')->where(['hotel_id' => $this->hotel->id, 'year' => 2026, 'month' => 1])->value('status'));
        $this->assertTrue(DB::table('accounting_audit_log')->where(['action' => 'period_reopened', 'subject_id' => '2026-1'])->exists());
        $this->legacy('2026-01-20')->assertCreated();
        $this->assertBooksBalance();
    }

    public function test_cross_hotel_posting_and_tenant_isolation(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $this->provision($other);
        [$g2, $foreign] = $this->makeHotel('Other Group', 'OTH');
        $this->provision($foreign);

        $local = $this->makeUser($this->group, 'accountant', hotels: [$this->hotel]);
        $this->actAs($local, $this->hotel);
        $this->legacy()->assertCreated();
        // Same user, header pointing at a hotel they are not assigned to, then at another group's hotel.
        $this->withHeaders(['X-Hotel-Id' => $other->id]);
        $this->assertContains($this->legacy()->status(), [400, 403, 404]);
        $this->withHeaders(['X-Hotel-Id' => $foreign->id]);
        $this->assertContains($this->legacy()->status(), [400, 403, 404]);

        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('journal_entries')->whereIn('hotel_id', [$other->id, $foreign->id])->count());

        // A user of the other hotel neither sees nor reverses this hotel's entry.
        $id = DB::table('journal_entries')->value('id');
        $fd = $this->makeUser($this->group, 'finance-director', hotels: [$other]);
        $this->actAs($fd, $other);
        $this->getJson('/api/v1/accounting/entries')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/accounting/entries/$id/reverse", ['reason' => 'x'])->assertNotFound();
    }

    public function test_replayed_source_events_post_once(): void
    {
        $ledger = app(Ledger::class);
        $meta = ['source_type' => 'payroll_run', 'source_id' => 7, 'source_event' => 'evt-replay-1'];
        $first = $ledger->post('PA', '2026-01-31', 'Paie', [['521100', -500], ['661100', 500]], $meta);
        $this->assertNotNull($first);
        $this->assertNull($ledger->post('PA', '2026-01-31', 'Paie', [['521100', -500], ['661100', 500]], $meta));
        $this->assertSame(1, JournalEntry::where('source_event', 'evt-replay-1')->count());
        $this->assertSame('payroll_run', $first->source_type);
        $this->assertSame('7', $first->source_id);
        $this->assertBooksBalance();
    }
}
