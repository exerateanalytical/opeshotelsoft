<?php

namespace Tests\Feature\Gapf;

use Tests\TestCase;

/** GAP slice (misc): new export reports and the endpoints the new screens call stay permission-checked. */
class MiscScreensTest extends TestCase
{
    public function test_exports_are_allowed_for_a_permitted_user_as_csv(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        foreach (['folios', 'invoices', 'cashier-shifts', 'housekeeping-assignments', 'work-orders', 'loyalty-members', 'room-blocks', 'rooming-list', 'audit-log', 'staff-users'] as $report) {
            $res = $this->get("/api/v1/exports/$report?format=csv");
            $res->assertOk();
            $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'), $report);
        }
    }

    public function test_exports_are_refused_to_staff_without_the_underlying_permission(): void
    {
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        foreach (['folios', 'invoices', 'cashier-shifts', 'loyalty-members', 'audit-log', 'staff-users', 'room-blocks'] as $report) {
            $this->get("/api/v1/exports/$report?format=csv")->assertForbidden();
        }
    }

    public function test_messaging_accounts_need_messaging_manage(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/messaging/accounts')->assertOk();
        $this->postJson('/api/v1/messaging/accounts', ['phone_number_id' => 'pn-1', 'display_phone' => '+237600000000'])->assertCreated();

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/messaging/accounts')->assertForbidden();
    }

    public function test_quick_reply_edit_and_delete_need_messaging_manage(): void
    {
        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $id = $this->postJson('/api/v1/messaging/quick-replies', ['title' => 'Hi', 'body' => 'Hello'])->assertSuccessful()->json('id');
        $this->putJson("/api/v1/messaging/quick-replies/$id", ['title' => 'Hi 2', 'body' => 'Hello 2'])->assertOk()->assertJsonPath('title', 'Hi 2');

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->putJson("/api/v1/messaging/quick-replies/$id", ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->deleteJson("/api/v1/messaging/quick-replies/$id")->assertForbidden();

        $this->actAs($this->makeUser($this->group, 'general-manager', allHotels: true), $this->hotel);
        $this->deleteJson("/api/v1/messaging/quick-replies/$id")->assertNoContent();
    }

    public function test_owner_digest_history_is_readable_by_owner_only(): void
    {
        $this->actAs($this->makeUser($this->group, 'hotel-owner', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/insights/digests')->assertOk();

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/insights/digests')->assertForbidden();
    }

    public function test_user_can_be_suspended_and_reactivated_by_user_manager(): void
    {
        $owner = $this->makeUser($this->group, 'general-manager', allHotels: true);
        $target = $this->makeUser($this->group, 'receptionist', allHotels: true);
        $this->actAs($owner, $this->hotel);
        $this->patchJson("/api/v1/users/{$target->id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');
        $this->patchJson("/api/v1/users/{$target->id}", ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');

        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->patchJson("/api/v1/users/{$target->id}", ['status' => 'suspended'])->assertForbidden();
    }

    public function test_property_detail_endpoints_and_payment_link_detail_are_guarded(): void
    {
        $this->actAs($this->makeUser($this->group, 'room-attendant', allHotels: true), $this->hotel);
        $this->getJson('/api/v1/payments/links/1')->assertForbidden();
        $this->postJson('/api/v1/payments/payouts/1/reject', ['item_ids' => [1], 'reason' => 'x'])->assertForbidden();
    }

    public function test_page_routes_render_for_permitted_roles(): void
    {
        $owner = $this->makeUser($this->group, 'hotel-owner', allHotels: true);
        foreach (['/owner' => 'Insights/Owner', '/messaging' => 'Messaging/Inbox', '/admin/audit' => 'Admin/Audit', '/settings/users' => 'Settings/Users', '/billing/invoices' => 'Billing/Invoices'] as $url => $component) {
            $res = $this->actingAs($owner)->get($url);
            $res->assertOk()->assertInertia(fn ($p) => $p->component($component));
        }
    }
}
