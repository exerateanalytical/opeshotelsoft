<?php

namespace Tests\Feature\Documents;

use Tests\TestCase;

/** GAP-K: departmental, consolidated, chart of accounts, stock lists and wastage export server-side, permission-checked. */
class GapkExportsTest extends TestCase
{
    private const KEYS = ['departmental', 'consolidated', 'chart-of-accounts', 'stock-items', 'stock-stores', 'stock-suppliers', 'wastage'];

    public function test_permitted_role_gets_csv_xlsx_pdf(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $q = 'from=2026-01-01&to=2026-12-31';
        foreach (self::KEYS as $key) {
            foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml', 'pdf' => 'application/pdf'] as $fmt => $mime) {
                $res = $this->get("/api/v1/exports/$key?format=$fmt&$q");
                $res->assertOk();
                $this->assertStringContainsString($mime, (string) $res->headers->get('Content-Type'), "$key $fmt");
            }
        }
    }

    public function test_room_attendant_is_refused(): void
    {
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $q = 'from=2026-01-01&to=2026-12-31';
        foreach (self::KEYS as $key) {
            foreach (['csv', 'xlsx', 'pdf'] as $fmt) {
                $this->get("/api/v1/exports/$key?format=$fmt&$q")->assertForbidden();
            }
        }
    }
}
