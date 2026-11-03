<?php

namespace Tests\Feature\HR;

use App\Models\User;
use App\Modules\HR\Domain\Contract;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Application\Lifecycle\EContractService;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    private Employee $emp;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $dept = Department::create(['code' => 'HK', 'name' => 'Housekeeping', 'cost_center' => 'rooms']);
        $this->staff = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->emp = Employee::create(['number' => 'E1', 'first_name' => 'Rose', 'last_name' => 'Etoa', 'hire_date' => now()->subYears(2)->toDateString(), 'department_id' => $dept->id,
            'user_id' => $this->staff->id, 'phone' => '690000000', 'payment_method' => 'bank', 'bank_name' => 'AFB', 'bank_account' => '123456789012', 'base_salary' => 120000]);
        Contract::create(['employee_id' => $this->emp->id, 'kind' => 'CDI', 'start_date' => $this->emp->hire_date, 'base_salary' => 120000, 'hours_per_week' => 40]);
    }

    private function asStaff(): void
    {
        $this->actAs($this->staff, $this->hotel);
    }

    public function test_profile_masks_bank_details(): void
    {
        $this->asStaff();
        $p = $this->getJson('/api/v1/hr/me')->assertOk()->json();
        $this->assertSame('Rose Etoa', $p['name']);
        $this->assertSame('********9012', $p['bank_account']);
    }

    public function test_user_without_employee_link_is_refused(): void
    {
        $nobody = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($nobody, $this->hotel);
        $this->getJson('/api/v1/hr/me')->assertForbidden();
        $this->getJson('/api/v1/hr/me/leave')->assertForbidden();
    }

    public function test_leave_balance_and_request(): void
    {
        $this->actAs($this->user, $this->hotel);
        $this->getJson('/api/v1/hr/leave-types')->assertOk(); // seeds the types
        $this->asStaff();
        $l = $this->getJson('/api/v1/hr/me/leave')->assertOk()->json();
        $this->assertGreaterThan(0, $l['balance']);
        $annual = collect($l['types'])->firstWhere('code', 'ANNUAL');
        $this->postJson('/api/v1/hr/me/leave', ['leave_type_id' => $annual['id'], 'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(12)->toDateString()])->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertCount(1, $this->getJson('/api/v1/hr/me/leave')->json('requests'));
    }

    public function test_data_change_request_applies_only_after_hr_approval(): void
    {
        $this->asStaff();
        $id = $this->postJson('/api/v1/hr/me/requests', ['kind' => 'phone_change', 'phone' => '655000111'])->assertCreated()->json('id');
        $this->assertSame('690000000', $this->emp->fresh()->phone); // unchanged while pending
        $this->postJson('/api/v1/hr/me/requests', ['kind' => 'phone_change', 'phone' => '655000222'])->assertUnprocessable(); // one pending per kind
        $this->postJson("/api/v1/hr/talent/requests/$id/decide", ['approve' => true])->assertForbidden(); // staff cannot decide

        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/hr/talent/requests/$id/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertSame('655000111', $this->emp->fresh()->phone);
        $this->postJson("/api/v1/hr/talent/requests/$id/decide", ['approve' => false])->assertUnprocessable();
    }

    public function test_bank_and_momo_changes_and_rejection(): void
    {
        $this->asStaff();
        $bank = $this->postJson('/api/v1/hr/me/requests', ['kind' => 'bank_change', 'bank_name' => 'UBA', 'bank_account' => '999888777666'])->assertCreated()->json('id');
        $momo = $this->postJson('/api/v1/hr/me/requests', ['kind' => 'momo_change', 'momo_number' => '677112233'])->assertCreated()->json('id');
        $this->postJson('/api/v1/hr/me/requests', ['kind' => 'address_change'])->assertUnprocessable(); // address is required
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/hr/talent/requests/$bank/decide", ['approve' => true])->assertOk();
        $this->postJson("/api/v1/hr/talent/requests/$momo/decide", ['approve' => false, 'note' => 'Number not registered'])->assertOk();
        $e = $this->emp->fresh();
        $this->assertSame('999888777666', $e->bank_account);
        $this->assertSame('UBA', $e->bank_name);
        $this->assertNull($e->momo_number);
        $this->assertCount(2, $this->getJson('/api/v1/hr/talent/requests')->json());
    }

    public function test_certificate_requests_issue_a_pdf_only_when_approved_and_only_to_the_owner(): void
    {
        $this->asStaff();
        $id = $this->postJson('/api/v1/hr/me/requests', ['kind' => 'salary_certificate', 'purpose' => 'Bank loan'])->assertCreated()->json('id');
        $this->get("/api/v1/hr/me/requests/$id/certificate")->assertStatus(404); // not approved yet
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/hr/talent/requests/$id/decide", ['approve' => true])->assertOk();
        $this->asStaff();
        $res = $this->get("/api/v1/hr/me/requests/$id/certificate")->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('%PDF', substr($res->getContent(), 0, 4));

        $other = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        Employee::create(['number' => 'E2', 'first_name' => 'Paul', 'last_name' => 'Zoa', 'hire_date' => now()->subYear()->toDateString(), 'user_id' => $other->id]);
        $this->actAs($other, $this->hotel);
        $this->get("/api/v1/hr/me/requests/$id/certificate")->assertNotFound();
    }

    public function test_contract_to_sign_gives_a_fresh_signing_link(): void
    {
        $this->actAs($this->user, $this->hotel);
        $doc = app(EContractService::class)->generate($this->emp, 'CDI');
        $this->asStaff();
        $list = $this->getJson('/api/v1/hr/me/contracts')->assertOk()->json();
        $this->assertSame($doc->id, $list[0]['id']);
        $link = $this->postJson("/api/v1/hr/me/contracts/$doc->id/link")->assertOk()->json('url');
        $this->assertStringContainsString('/sign/contract/', $link);
        $this->assertSame('sent', EContract::find($doc->id)->status);
    }

    public function test_my_documents_and_schedule_list_only_my_data(): void
    {
        $this->asStaff();
        $d = $this->getJson('/api/v1/hr/me/documents')->assertOk()->json();
        $this->assertSame([], $d['documents']);
        $this->getJson('/api/v1/hr/me/schedule')->assertOk();
        $this->getJson('/api/v1/payroll/payslips/mine')->assertOk(); // payslips come from the payroll endpoint
    }
}
