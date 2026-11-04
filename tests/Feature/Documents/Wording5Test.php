<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Application\DocWords;
use App\Modules\Documents\Application\DocumentRenderer;
use App\Modules\Documents\Application\TemplateRenderer;
use App\Modules\Documents\Money\Sheets;
use App\Modules\Documents\Operations\HrSheets;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\Transfer;
use App\Modules\HR\Domain\Position;
use App\Modules\Reservations\Experience\Application\PurchaseService;
use App\Modules\Reservations\Experience\Domain\ExperienceService;
use App\Modules\Tenancy\Domain\Hotel;
use Tests\TestCase;

/** Document wording: a French document has no English status words and an English one no French ones; fallbacks for empty values. */
class Wording5Test extends TestCase
{
    /** English tokens that must never appear in a French render. */
    private const EN_TOKENS = ['tentative', 'draft', 'counting', 'returned', 'confirmed', 'month(s)', 'day(s)', 'year(s)', 'banded', 'beverage', 'pending', 'approved', 'cancelled', 'received', 'stored'];

    private function hire(array $extra = []): array
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);
        $pos = Position::firstOrCreate(['title' => 'Réceptionniste'], ['department_id' => $dept->id, 'category' => '5A']);
        $hired = now()->startOfMonth()->subYears(3)->toDateString();

        return $this->postJson('/api/v1/hr/employees', $extra + [
            'first_name' => 'Aïcha', 'last_name' => 'Bello'.uniqid(), 'hire_date' => $hired, 'department_id' => $dept->id, 'position_id' => $pos->id,
            'phone' => '+237677000111', 'email' => 'aicha@test.cm', 'id_number' => 'CNI123', 'cnps_number' => 'CNPS777',
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => 250_000, 'probation_end' => now()->subMonths(30)->toDateString()],
        ])->assertCreated()->json();
    }

    private static function col(string $k, string $fr, string $en): array
    {
        return ['k' => $k, 'fr' => $fr, 'en' => $en, 't' => 'text'];
    }

    /** Render a sheet through the engine's layout to HTML in the document language. */
    private function html(string $view, array $sheet, string $lang): string
    {
        $hotel = Hotel::withoutGlobalScopes()->find($this->hotel->id);
        $t = fn (string $fr, string $en) => $lang === 'en' ? $en : $fr;

        return view($view, ['sheet' => $sheet, 'hotel' => $hotel, 'lang' => $lang, 't' => $t, 'engine' => DocumentRenderer::bareEngine($hotel, $lang)])->render();
    }

    private function assertNoTokens(string $html, array $tokens, string $what): void
    {
        $text = mb_strtolower(html_entity_decode(strip_tags(preg_replace('#<style.*?</style>#s', '', $html))));
        foreach ($tokens as $tok) {
            $this->assertStringNotContainsString($tok, $text, "$what contains '$tok'");
        }
    }

    public function test_final_settlement_basis_and_labels_follow_the_language(): void
    {
        $id = $this->hire()['id'];
        $x = $this->postJson('/api/v1/people/exits', ['employee_id' => $id, 'kind' => 'termination', 'notice_date' => now()->toDateString(), 'last_day' => now()->addMonth()->toDateString(), 'notice_worked' => false])->assertCreated()->json();
        $hr = app(HrSheets::class);

        $fr = $this->html('documents::operations.sheet', $hr->settlement($x['id'], 'fr', 'fr')['data']['sheet'], 'fr');
        $this->assertStringContainsString('mois', $fr);
        $this->assertStringContainsString('barème 20/25/30/35 %', $fr);
        $this->assertStringContainsString('salaire mensuel', $fr);
        $this->assertNoTokens($fr, self::EN_TOKENS, 'FR settlement');

        $en = $this->html('documents::operations.sheet', $hr->settlement($x['id'], 'en', 'fr')['data']['sheet'], 'en');
        $this->assertStringContainsString('banded 20/25/30/35 %', $en);
        $this->assertStringContainsString('monthly salary', $en);
        $this->assertNoTokens($en, ['mois ×', 'jours ×', 'barème', 'ans,'], 'EN settlement');
    }

    public function test_basis_strings_are_translated_with_plural_and_decimal_by_language(): void
    {
        $this->assertSame('0,5 mois × 250 000', DocWords::basis('0.5 month(s) × 250000', 'fr'));
        $this->assertSame('1 month × 250 000', DocWords::basis('1 month(s) × 250000', 'en'));
        $this->assertSame('2 months × 250 000 owed by the employee', DocWords::basis('2 month(s) × 250000 owed by employee', 'en'));
        $this->assertSame('1 jour × salaire mensuel / 30', DocWords::basis('1 day(s) × monthly / 30', 'fr'));
        $this->assertSame('3 ans, barème 20/25/30/35 %', DocWords::basis('3 year(s), banded 20/25/30/35 %', 'fr'));
        $this->assertSame('unknown text', DocWords::basis('unknown text', 'fr'));
    }

    public function test_status_and_department_words_are_translated_in_sheets_and_unknown_values_pass_through(): void
    {
        $sheet = ['title' => ['Inventaire', 'Stocktake'], 'number' => 'X-1',
            'meta' => [['Statut', 'Status', 'counting'], ['Service', 'Department', 'beverage'], ['Motif', 'Reason', 'draft sheet by Bob']],
            'blocks' => [['type' => 'table', 'cols' => [self::col('status', 'Statut', 'Status'), self::col('department', 'Service', 'Department'), self::col('name', 'Nom', 'Name')],
                'rows' => [['status' => 'tentative', 'department' => 'beverage', 'name' => 'draft'], ['status' => 'returned', 'department' => 'tourist_tax', 'name' => 'confirmed']]]]];
        foreach (['documents::operations.sheet', 'documents::money.sheet'] as $view) {
            $fr = $this->html($view, $sheet, 'fr');
            foreach (['Comptage en cours', 'Boissons', 'Provisoire', 'Restitué', 'Taxe de séjour'] as $w) {
                $this->assertStringContainsString($w, $fr);
            }
            // free text stays untouched (never translated by guesswork)
            $this->assertStringContainsString('draft sheet by Bob', $fr);
            $en = $this->html($view, $sheet, 'en');
            foreach (['Counting', 'Beverage', 'Tentative', 'Returned', 'Tourist tax'] as $w) {
                $this->assertStringContainsString($w, $en);
            }
        }
        $this->assertSame('mystery_code', DocWords::word('mystery_code', 'fr'));
        $this->assertSame('Free Text', DocWords::word('Free Text', 'fr'));
    }

    public function test_guest_statement_renders_in_each_language_without_the_other(): void
    {
        $g = Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'jm@test.cm']);
        $sheet = app(Sheets::class)->guestStatement($g->id, null, null)['sheet'] + ['number' => 'RG-1'];
        $fr = $this->html('documents::money.sheet', $sheet, 'fr');
        $en = $this->html('documents::money.sheet', $sheet, 'en');
        $this->assertStringContainsString('Relevé de compte client', $fr);
        $this->assertStringContainsString('Guest statement of account', $en);
        $this->assertNoTokens($fr, ['statement of account', 'balance due', 'nothing to show', 'description'], 'FR statement');
        $this->assertNoTokens($en, ['relevé', 'solde dû', 'aucune ligne', 'libellé'], 'EN statement');
    }

    public function test_transfer_letter_without_a_position_uses_a_phrase_by_language(): void
    {
        $id = $this->hire()['id'];
        [, $hotel2] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $tr = Transfer::create(['employee_id' => $id, 'from_hotel_id' => $this->hotel->id, 'to_hotel_id' => $hotel2->id, 'new_base_salary' => 260_000,
            'effective_date' => now()->addMonth()->toDateString(), 'reason' => 'Besoin', 'status' => 'approved', 'requested_by' => $this->user->id]);
        Employee::whereKey($id)->update(['position_id' => null, 'department_id' => null]);
        $hr = app(HrSheets::class);

        $fr = $hr->transferLetter($tr->id, 'fr', 'fr')['data']['docHtml'];
        $en = $hr->transferLetter($tr->id, 'en', 'fr')['data']['docHtml'];
        $this->assertStringNotContainsString('****', $fr);
        $this->assertStringNotContainsString('****', $en);
        $this->assertStringContainsString('votre fonction actuelle', $fr);
        $this->assertStringContainsString('votre service actuel', $fr);
        $this->assertStringContainsString('your current position', $en);
        $this->assertStringContainsString('your current department', $en);
        $this->assertStringNotContainsString('your current', $fr);
        $this->assertStringNotContainsString('votre fonction', $en);

        // an empty bold value never prints as ****, even with a hotel-edited template
        $this->assertSame('<p>a  b</p>', TemplateRenderer::render('hr_transfer', 'a **{{ transfer.to_position }}** b', ['transfer.to_position' => '']));
    }

    public function test_warning_and_suspension_letters_without_facts_print_a_clear_line(): void
    {
        $id = $this->hire()['id'];
        $hr = app(HrSheets::class);
        $w = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $id, 'kind' => 'written_warning', 'incident_date' => now()->subDay()->toDateString(), 'reason' => 'Retards'])->assertCreated()->json();
        $s = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $id, 'kind' => 'suspension', 'incident_date' => now()->subDay()->toDateString(), 'reason' => 'Faute',
            'suspension_start' => now()->addDay()->toDateString(), 'suspension_end' => now()->addDays(3)->toDateString()])->assertCreated()->json();

        foreach ([$w['id'], $s['id']] as $sid) {
            $fr = $hr->warningLetter($sid, 'fr', 'fr')['data']['docHtml'];
            $en = $hr->warningLetter($sid, 'en', 'fr')['data']['docHtml'];
            $this->assertStringContainsString('Aucun fait détaillé n&#039;a été renseigné.', $fr);
            $this->assertStringContainsString('No detailed facts were recorded.', $en);
            $this->assertStringNotContainsString('Aucun fait', $en);
            $this->assertNoTokens($fr, ['no detailed facts', 'your current'], 'FR sanction letter');
        }
        // with facts entered, the entered text is printed as is
        $f = $this->postJson('/api/v1/people/sanctions', ['employee_id' => $id, 'kind' => 'written_warning', 'incident_date' => now()->subDay()->toDateString(), 'reason' => 'X', 'facts' => 'Trois retards'])->assertCreated()->json();
        $html = $hr->warningLetter($f['id'], 'fr', 'fr')['data']['docHtml'];
        $this->assertStringContainsString('Trois retards', $html);
        $this->assertStringNotContainsString('Aucun fait', $html);
    }

    public function test_contract_kind_in_letters_is_not_mixed_language(): void
    {
        $id = $this->hire()['id'];
        \App\Modules\HR\Domain\Contract::where('employee_id', $id)->update(['kind' => 'internship']);
        $hr = app(HrSheets::class);
        $this->assertStringNotContainsString('Internship', $hr->attestation($id, 'fr', 'fr')['data']['docHtml']);
        $this->assertStringNotContainsString('Stage', $hr->attestation($id, 'en', 'fr')['data']['docHtml']);
    }

    public function test_guest_services_show_the_name_for_the_language_with_french_fallback(): void
    {
        ExperienceService::create(['code' => 'SPA1', 'name' => 'Massage relaxant', 'name_en' => 'Relaxing massage', 'price' => 20_000, 'charge_code' => 'SPA', 'active' => true]);
        ExperienceService::create(['code' => 'LAV1', 'name' => 'Blanchisserie express', 'name_en' => null, 'price' => 5_000, 'charge_code' => 'LAUNDRY', 'active' => true]);
        $svc = app(PurchaseService::class);
        $en = collect($svc->services('en'))->keyBy('code');
        $fr = collect($svc->services('fr'))->keyBy('code');
        $this->assertSame('Relaxing massage', $en['SPA1']['name']);
        $this->assertSame('Blanchisserie express', $en['LAV1']['name'], 'no English name: fall back to French');
        $this->assertSame('Massage relaxant', $fr['SPA1']['name']);
        $this->assertSame('Massage relaxant', collect($svc->services())->keyBy('code')['SPA1']['name']);
    }
}
