<?php

namespace Tests\Feature\Stock;

use App\Modules\HR\Domain\Department;

class OpsIssuesTest extends OpsTestCase
{
    private function hire(): array
    {
        $dept = Department::firstOrCreate(['code' => 'FB'], ['name' => 'Restaurant', 'cost_center' => 'fnb']);
        $hired = now()->subYear()->toDateString();

        return $this->postJson('/api/v1/hr/employees', [
            'first_name' => 'Paul', 'last_name' => 'Ndzana'.uniqid(), 'hire_date' => $hired, 'department_id' => $dept->id,
            'contract' => ['kind' => 'CDI', 'start_date' => $hired, 'base_salary' => 100_000],
        ])->assertCreated()->json();
    }

    private function shirt(): array
    {
        $item = $this->item('CHM', 'Chemise serveur', 'pc', ['category' => 'supplies']);
        $this->receive($item, $this->main, 20, 5000);
        $issuable = $this->postJson('/api/v1/stock-ops/issuables', ['code' => 'U-CHM', 'name' => 'Chemise serveur', 'kind' => 'uniform', 'stock_item_id' => $item->id, 'unit_value' => 5000])->assertCreated()->json();

        return [$item, $issuable];
    }

    public function test_uniform_issue_deducts_stock_and_good_return_restores_it(): void
    {
        $e = $this->hire();
        [$item, $issuable] = $this->shirt();
        $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => $e['id'], 'issuable_id' => $issuable['id'], 'quantity' => 2, 'size' => 'M'])->assertStatus(422); // store required
        $rec = $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => $e['id'], 'issuable_id' => $issuable['id'], 'quantity' => 2, 'size' => 'M', 'store_id' => $this->main->id])->assertCreated()->json();
        $this->assertSame(18.0, $this->onHand($this->main, $item));

        $this->postJson("/api/v1/stock-ops/issues/{$rec['id']}/return", ['quantity' => 3])->assertStatus(422);
        $this->postJson("/api/v1/stock-ops/issues/{$rec['id']}/return", ['quantity' => 1, 'condition' => 'good'])->assertOk()->assertJsonPath('status', 'issued');
        $this->assertSame(19.0, $this->onHand($this->main, $item));
        $this->postJson("/api/v1/stock-ops/issues/{$rec['id']}/return", ['quantity' => 1, 'condition' => 'damaged'])->assertOk()->assertJsonPath('status', 'returned');
        $this->assertSame(19.0, $this->onHand($this->main, $item)); // damaged is not restocked

        $list = $this->getJson("/api/v1/stock-ops/issues/employee/{$e['id']}")->assertOk()->json();
        $this->assertSame('Chemise serveur', $list[0]['issuable_name']);
        $this->assertStringContainsString('Paul', $list[0]['employee_name']);
        $this->assertBooksBalance();
    }

    public function test_lost_items_overdue_list_and_unknown_employee(): void
    {
        $e = $this->hire();
        [$item, $issuable] = $this->shirt();
        $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => 999999, 'issuable_id' => $issuable['id'], 'store_id' => $this->main->id])->assertStatus(422);

        $past = now()->subDays(3)->toDateString();
        $rec = $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => $e['id'], 'issuable_id' => $issuable['id'], 'store_id' => $this->main->id, 'due_back_on' => $past])->assertCreated()->json();
        $this->getJson('/api/v1/stock-ops/issues?overdue=1')->assertOk()->assertJsonCount(1)->assertJsonPath('0.overdue', true);
        $this->postJson("/api/v1/stock-ops/issues/{$rec['id']}/return", ['condition' => 'lost'])->assertOk()->assertJsonPath('status', 'lost');
        $this->getJson('/api/v1/stock-ops/issues?overdue=1')->assertOk()->assertJsonCount(0);
        $this->postJson("/api/v1/stock-ops/issues/{$rec['id']}/return")->assertStatus(422);
        $this->assertSame(19.0, $this->onHand($this->main, $item));
    }

    public function test_asset_serial_cannot_be_issued_twice_and_assets_need_no_stock(): void
    {
        $e1 = $this->hire();
        $e2 = $this->hire();
        $asset = $this->postJson('/api/v1/stock-ops/issuables', ['code' => 'RAD', 'name' => 'Radio talkie', 'kind' => 'asset'])->assertCreated()->json();
        $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => $e1['id'], 'issuable_id' => $asset['id'], 'serial' => 'SN-77'])->assertCreated();
        $this->postJson('/api/v1/stock-ops/issues', ['employee_id' => $e2['id'], 'issuable_id' => $asset['id'], 'serial' => 'SN-77'])->assertStatus(422);
        $this->getJson('/api/v1/stock-ops/issues?status=issued')->assertOk()->assertJsonCount(1);
    }

    public function test_issue_actions_need_the_issues_permission(): void
    {
        $this->as('room-attendant');
        $this->postJson('/api/v1/stock-ops/issuables', ['code' => 'X', 'name' => 'X', 'kind' => 'asset'])->assertForbidden();
        $this->getJson('/api/v1/stock-ops/issues')->assertForbidden();
    }
}
