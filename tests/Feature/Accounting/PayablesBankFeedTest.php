<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\BankAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayablesBankFeedTest extends TestCase
{
    private BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = BankAccount::create(['name' => 'Afriland', 'kind' => 'bank', 'gl_account' => '521100', 'currency' => 'XAF']);
    }

    private function feed(string $format, string $content, array $extra = [])
    {
        return $this->post("/api/v1/accounting3/bank-accounts/{$this->bank->id}/feed", $extra + ['format' => $format, 'file' => UploadedFile::fake()->createWithContent('stmt.txt', $content)], ['Accept' => 'application/json']);
    }

    private const MT940 = ":20:STMT1\n:25:CM21 10005 00001 12345678901 23\n:28C:1/1\n:60F:C261101XAF1000000,\n"
        .":61:2611030103C250000,NTRFINV2026-001//BK1\n:86:VIREMENT RECU SOCIETE ALPHA\n"
        .":61:2611040104D15000,NCHGNONREF\n:86:FRAIS DE TENUE DE COMPTE\n"
        .":61:2611050105D90000,NTRFPAY-77//BK3\n:86:/BENM/ELECTRICITE ENEO\n:86:FACTURE OCTOBRE\n"
        .":62F:C261105XAF1145000,\n-";

    public function test_mt940_import_parses_signs_dedupes_and_applies_rules(): void
    {
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'Frais bancaires', 'pattern' => 'frais de tenue', 'direction' => 'out', 'account_code' => '631100', 'auto_post' => true, 'priority' => 10])->assertCreated();
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'Eau et électricité', 'pattern' => 'eneo', 'account_code' => '632000', 'auto_post' => false, 'amount_max' => 200000])->assertCreated();

        $r = $this->feed('mt940', self::MT940)->assertCreated()->json();
        $this->assertSame(3, $r['import']['rows']);
        $this->assertSame(1_000_000, $r['import']['opening_balance']);
        $this->assertSame(1_145_000, $r['import']['closing_balance']);
        $this->assertSame(['posted' => 1, 'suggested' => 1], $r['rules']);
        $lines = DB::table('bank_statement_lines')->orderBy('date')->get();
        $this->assertSame([250000, -15000, -90000], $lines->pluck('amount')->map(fn ($v) => (int) $v)->all());
        $this->assertStringContainsString('ELECTRICITE ENEO', $lines[2]->description);

        // The auto-post rule booked the bank charge and matched the line.
        $charge = $lines[1];
        $this->assertNotNull($charge->journal_line_id);
        $this->assertSame('rule', $charge->match_kind);
        $b = $this->assertBooksBalance();
        $this->assertSame(15_000, $b['631100']);
        $this->assertSame(-15_000, $b['521100']);

        // The other rule only suggests; accepting it posts and matches.
        $sug = $this->getJson("/api/v1/accounting3/bank-accounts/{$this->bank->id}/rule-suggestions")->assertOk()->json();
        $this->assertCount(1, $sug);
        $this->assertSame('632000', $sug[0]['account_code']);
        $this->postJson("/api/v1/accounting3/statement-lines/{$sug[0]['line_id']}/accept-rule")->assertOk();
        $this->postJson("/api/v1/accounting3/statement-lines/{$sug[0]['line_id']}/accept-rule")->assertStatus(422);
        $b = $this->assertBooksBalance();
        $this->assertSame(90_000, $b['632000']);
        $this->assertSame(0, (int) DB::table('bank_rule_hits')->where('status', 'suggested')->count());

        // Re-importing the same file adds nothing (existing duplicate protection).
        $again = $this->feed('mt940', self::MT940)->assertCreated()->json();
        $this->assertSame(0, $again['import']['rows']);
        $this->assertSame(3, $again['import']['skipped']);
    }

    public function test_camt053_import_and_rule_matching_by_regex_amount_and_direction(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>S1</Id>'
            .'<Acct><Id><IBAN>CM2110005000011234567890123</IBAN></Id></Acct>'
            .'<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="XAF">500000</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .'<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="XAF">620000</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>'
            .'<Ntry><Amt Ccy="XAF">150000</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-11-02</Dt></BookgDt><AcctSvcrRef>REF-1</AcctSvcrRef><NtryDtls><TxDtls><RmtInf><Ustrd>Reglement chambre MTN MOMO 6551</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
            .'<Ntry><Amt Ccy="XAF">30000</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-11-03</Dt></BookgDt><AcctSvcrRef>REF-2</AcctSvcrRef><AddtlNtryInf>COMMISSION VIREMENT</AddtlNtryInf></Ntry>'
            .'</Stmt></BkToCstmrStmt></Document>';
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'MoMo', 'match_mode' => 'regex', 'pattern' => 'momo\s+\d+', 'direction' => 'in', 'account_code' => '411200', 'partner_type' => 'company', 'partner_id' => 1, 'auto_post' => true])->assertCreated();
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'Gros débits', 'pattern' => 'commission', 'direction' => 'in', 'account_code' => '631100', 'auto_post' => true])->assertCreated(); // wrong direction: never fires

        $r = $this->feed('camt053', $xml)->assertCreated()->json();
        $this->assertSame(2, $r['import']['rows']);
        $this->assertSame(['posted' => 1, 'suggested' => 0], $r['rules']);
        $this->assertSame(620_000, $r['closing_balance']);
        $lines = DB::table('bank_statement_lines')->orderBy('date')->get();
        $this->assertSame([150000, -30000], $lines->pluck('amount')->map(fn ($v) => (int) $v)->all());
        $posted = DB::table('journal_lines')->where('account_code', '411200')->first();
        $this->assertSame('company', $posted->partner_type);
        $this->assertSame(150_000, (int) $posted->credit);
        $this->assertNull($lines[1]->journal_line_id);
        $this->assertBooksBalance();
    }

    public function test_csv_goes_through_the_existing_mapping_import_and_bad_files_are_refused(): void
    {
        $csv = "Date;Libelle;Montant;Ref\n2026-11-03;Depot espèces;75000;D-1\n2026-11-04;Frais;-2500;D-2\n";
        $r = $this->feed('csv', $csv, ['mapping' => ['date' => 'Date', 'amount' => 'Montant', 'reference' => 'Ref', 'description' => 'Libelle']])->assertCreated()->json();
        $this->assertSame(2, $r['import']['rows']);
        $this->feed('csv', $csv)->assertStatus(422); // mapping missing
        $this->feed('mt940', 'not a statement')->assertStatus(422);
        $this->feed('camt053', '<Document><Foo/></Document>')->assertStatus(422);
        $acc = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($acc, $this->hotel);
        $this->feed('mt940', self::MT940)->assertForbidden();
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'x', 'pattern' => 'x', 'account_code' => '631100'])->assertForbidden();
    }

    public function test_rule_validation_rejects_bad_regex_and_unknown_accounts(): void
    {
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'x', 'match_mode' => 'regex', 'pattern' => '([', 'account_code' => '631100'])->assertStatus(422);
        $this->postJson('/api/v1/accounting3/bank-rules', ['name' => 'x', 'pattern' => 'abc', 'account_code' => '999999'])->assertStatus(422);
    }
}
