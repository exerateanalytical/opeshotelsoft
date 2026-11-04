<?php

namespace Tests\Feature\Stock;

/** GAPJ: pages that gain permission-gated buttons and the camera scan still render 200 for permitted roles. */
class GapjGatedPagesTest extends OpsTestCase
{
    public function test_gated_and_scan_pages_render_for_permitted_roles(): void
    {
        $cases = [
            'general-manager' => ['/revenue/suggestions', '/revenue/offers', '/revenue/rules', '/finance/accruals', '/stock/scan'],
            'storekeeper' => ['/stock/scan'],
        ];
        foreach ($cases as $role => $pages) {
            $u = $this->makeUser($this->group, $role, allHotels: true);
            foreach ($pages as $page) {
                $this->actingAs($u)->get($page)->assertOk();
            }
        }
    }
}
