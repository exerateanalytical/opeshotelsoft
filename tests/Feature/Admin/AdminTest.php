<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_currency_crud_with_xaf_functional(): void
    {
        $list = $this->getJson('/api/v1/admin/currencies')->assertOk()->json();
        $this->assertSame('XAF', $list[0]['code']);
        $this->assertTrue($list[0]['is_functional']);

        $this->postJson('/api/v1/admin/currencies', ['code' => 'ngn', 'name' => 'Naira', 'decimals' => 2])->assertCreated()->assertJsonPath('code', 'NGN');
        $this->postJson('/api/v1/admin/currencies', ['code' => 'NGN', 'name' => 'Dup', 'decimals' => 2])->assertStatus(422);
        $this->patchJson('/api/v1/admin/currencies/NGN', ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->patchJson('/api/v1/admin/currencies/XAF', ['active' => false])->assertStatus(422);
        $this->deleteJson('/api/v1/admin/currencies/XAF')->assertStatus(422);

        $this->postJson('/api/v1/admin/exchange-rates', ['currency' => 'USD', 'date' => '2026-10-01', 'rate' => 600])->assertCreated();
        $this->postJson('/api/v1/admin/exchange-rates', ['currency' => 'USD', 'date' => '2026-11-01', 'rate' => 610.5])->assertCreated();
        $this->postJson('/api/v1/admin/exchange-rates', ['currency' => 'XAF', 'date' => '2026-11-01', 'rate' => 1])->assertStatus(422);
        $this->postJson('/api/v1/admin/exchange-rates', ['currency' => 'USD', 'date' => '2026-11-01', 'rate' => 0])->assertStatus(422);
        $this->getJson('/api/v1/admin/exchange-rates/lookup?currency=USD&date=2026-10-15')->assertOk()->assertJsonPath('rate', 600);
        $this->getJson('/api/v1/admin/exchange-rates/lookup?currency=EUR')->assertOk()->assertJsonPath('rate', 655.957);
        $this->getJson('/api/v1/admin/exchange-rates/lookup?currency=USD&date=2020-01-01')->assertNotFound();
        $id = $this->getJson('/api/v1/admin/exchange-rates?currency=USD')->assertOk()->json('data.0.id');
        $this->deleteJson("/api/v1/admin/exchange-rates/$id")->assertNoContent();
        $this->deleteJson('/api/v1/admin/currencies/NGN')->assertNoContent();
    }

    public function test_currency_permissions(): void
    {
        $this->actAs($this->makeUser($this->group, 'accountant', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/admin/currencies')->assertOk();
        $this->postJson('/api/v1/admin/currencies', ['code' => 'GBP', 'name' => 'Pound', 'decimals' => 2])->assertForbidden();
        $this->actAs($this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]), $this->hotel);
        $this->getJson('/api/v1/admin/currencies')->assertForbidden();
        $this->getJson('/api/v1/admin/audit')->assertForbidden();
    }

    public function test_audit_log_filters_and_group_isolation(): void
    {
        activity()->causedBy($this->user)->performedOn($this->user)->event('updated')->log('changed name');
        activity()->causedBy($this->user)->event('login')->log('signed in');
        [$other] = $this->makeHotel('Other Group', 'OTH');
        $stranger = $this->makeUser($other, 'general-manager');
        activity()->causedBy($stranger)->event('updated')->log('foreign');

        $all = $this->getJson('/api/v1/admin/audit')->assertOk()->json('data');
        $this->assertNotContains('foreign', array_column($all, 'description'));
        $this->assertSame(['signed in'], array_column($this->getJson('/api/v1/admin/audit?event=login')->json('data'), 'description'));
        $byModel = $this->getJson('/api/v1/admin/audit?subject_type='.urlencode($this->user->getMorphClass()))->json('data');
        $this->assertContains('changed name', array_column($byModel, 'description'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/audit?user_id='.$stranger->id)->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/audit?to=2000-01-01')->json('data'));
        $this->assertNotEmpty($this->getJson('/api/v1/admin/audit?from='.now()->toDateString())->json('data'));
        $f = $this->getJson('/api/v1/admin/audit/filters')->assertOk()->json();
        $this->assertContains('login', $f['events']);
        $this->assertNotContains($stranger->id, array_column($f['users'], 'id'));
    }
}
