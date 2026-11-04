<?php

namespace Tests\Feature\Documents;

use App\Modules\FrontOffice\Domain\Folio;
use Tests\TestCase;

/** Exports follow every page of the filtered list (not just the first 50), up to a hard cap. */
class ExportFullSetTest extends TestCase
{
    private function seedFolios(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Folio::create([
                'group_id' => $this->group->id, 'hotel_id' => $this->hotel->id,
                'number' => sprintf('F-%05d', $i), 'kind' => 'house', 'name' => "Folio $i", 'currency' => 'XAF', 'balance' => 0, 'status' => 'open',
            ]);
        }
    }

    public function test_export_contains_every_row_beyond_the_first_page(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $this->seedFolios(120);

        $csv = $this->get('/api/v1/exports/folios?format=csv')->assertOk()->getContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(121, $lines, 'header + 120 folios');
        $this->assertStringContainsString('F-00120', $csv);
        $this->assertStringNotContainsString('TRUNCATED', $csv);
    }

    public function test_export_is_capped_and_flags_truncation(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $this->seedFolios(\App\Modules\Documents\Application\Exporter::MAX_ROWS + 10);

        $csv = $this->get('/api/v1/exports/folios?format=csv')->getContent();
        $this->assertStringContainsString('TRUNCATED', $csv);
        $this->assertCount(\App\Modules\Documents\Application\Exporter::MAX_ROWS + 2, array_filter(explode("\n", trim($csv))));
    }
}
