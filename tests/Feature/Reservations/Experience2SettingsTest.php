<?php

namespace Tests\Feature\Reservations;

use App\Modules\FrontOffice\Domain\CheckoutFeeRule;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use Tests\TestCase;

/** S2: experience settings screen API (settings, services, fee rules, reviews filter). */
class Experience2SettingsTest extends TestCase
{
    public function test_page_renders_for_manager(): void
    {
        $this->get('/reservations/experience-settings')->assertOk();
    }

    public function test_settings_switches_and_no_show_fee_default_off(): void
    {
        $s = $this->getJson('/api/v1/experience/settings')->assertSuccessful()->json();
        $this->assertFalse($s['no_show_fee']);
        $this->assertTrue($s['early_checkin_enabled']);
        $this->putJson('/api/v1/experience/settings', ['no_show_fee' => true, 'early_checkin_enabled' => false, 'deposit_deadline_hours' => 24])->assertOk()
            ->assertJsonPath('no_show_fee', true)->assertJsonPath('early_checkin_enabled', false);
        $this->assertSame(24, ExperienceSetting::forCurrentHotel()->deposit_deadline_hours);
    }

    public function test_service_with_french_and_english_names(): void
    {
        $id = $this->postJson('/api/v1/experience/services', ['code' => 'PICKUP', 'name' => 'Navette aeroport', 'name_en' => 'Airport pickup', 'price' => 15000, 'charge_code' => 'ROOM'])
            ->assertCreated()->assertJsonPath('name_en', 'Airport pickup')->json('id');
        $this->patchJson("/api/v1/experience/services/$id", ['active' => false])->assertOk()->assertJsonPath('active', false);
    }

    public function test_early_checkin_fee_rule_create_edit_deactivate(): void
    {
        $id = $this->postJson('/api/v1/experience/fee-rules', ['kind' => 'early_checkin', 'after_hour' => 10, 'charge_code' => 'ROOM', 'fee_type' => 'percent_of_night', 'value' => 30])
            ->assertCreated()->json('id');
        $this->patchJson("/api/v1/experience/fee-rules/$id", ['value' => 40, 'active' => false])->assertOk()->assertJsonPath('value', 40)->assertJsonPath('active', false);
        $this->assertSame(1, CheckoutFeeRule::where('kind', 'early_checkin')->count());
        $this->getJson('/api/v1/experience/fee-rules')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/experience/fee-rules', ['kind' => 'late', 'after_hour' => 14, 'charge_code' => 'ROOM', 'fee_type' => 'percent_of_night', 'value' => 150])->assertStatus(422);
        $this->postJson('/api/v1/experience/fee-rules', ['kind' => 'early', 'after_hour' => 14, 'charge_code' => 'ROOM', 'fee_type' => 'fixed', 'value' => 5])->assertStatus(422);
    }

    public function test_front_desk_replace_keeps_early_checkin_rules(): void
    {
        CheckoutFeeRule::create(['kind' => 'early_checkin', 'after_hour' => 10, 'charge_code' => 'ROOM', 'fee_type' => 'fixed', 'value' => 5000]);
        $this->putJson('/api/v1/front-desk/checkout-fee-rules', ['rules' => [['kind' => 'late', 'after_hour' => 14, 'charge_code' => 'ROOM', 'fee_type' => 'fixed', 'value' => 9000]]])->assertOk();
        $this->assertSame(1, CheckoutFeeRule::where('kind', 'early_checkin')->count());
        $this->assertSame(1, CheckoutFeeRule::where('kind', 'late')->count());
    }

    public function test_reviews_filter_by_rating(): void
    {
        $this->getJson('/api/v1/experience/reviews?rating=5')->assertOk();
    }
}
