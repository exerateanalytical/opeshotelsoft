<?php

namespace Tests\Feature\Security;

use App\Modules\Admin\Application\TwoFactor;
use App\Modules\Admin\Domain\Totp;
use App\Modules\Documents\Domain\ScheduledReport;
use App\Modules\Guests\Domain\Guest;
use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Talent\Domain\ChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Guests\RepairsRoleSeed;
use Tests\TestCase;

/** SEC2 regressions: authorisation, tenancy, four-eyes and authentication fixes (medium and low findings). */
class Fix2AccessTest extends TestCase
{
    use RepairsRoleSeed;

    /** authz:scheduled-reports:runs-as-creator-after-edit-by-other-user */
    public function test_editing_a_scheduled_report_makes_the_editor_its_owner(): void
    {
        $s = $this->postJson('/api/v1/scheduled-reports', [
            'name' => 'Daily trial balance', 'report' => 'trial-balance', 'format' => 'xlsx', 'recipients' => ['dg@akwa.cm'], 'frequency' => '0 7 * * *',
        ])->assertCreated()->json();
        $this->assertSame($this->user->id, ScheduledReport::find($s['id'])->created_by);

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $this->actAs($fd, $this->hotel);
        $this->putJson("/api/v1/scheduled-reports/{$s['id']}", ['recipients' => ['fd@akwa.cm']])->assertOk();
        $this->assertSame($fd->id, ScheduledReport::find($s['id'])->created_by, 'the runner now executes with the editor rights');
    }

    /** tenancy:webhooks:hotel-id-not-checked-against-allowed-hotels */
    public function test_webhook_hotel_must_belong_to_the_group_and_the_actor_hotels(): void
    {
        [, $sister] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        [, $foreign] = $this->makeHotel('Other Group', 'OTH');
        $body = ['url' => 'https://hooks.example.com/x', 'event_types' => ['reservation.created']];

        $this->postJson('/api/v1/webhooks', $body + ['hotel_id' => $foreign->id])->assertStatus(422);
        $this->postJson('/api/v1/webhooks', $body + ['hotel_id' => $sister->id])->assertCreated(); // group-wide user

        $gm = $this->makeUser($this->group, 'general-manager', hotels: [$this->hotel]);
        $this->actAs($gm, $this->hotel);
        $this->postJson('/api/v1/webhooks', $body + ['hotel_id' => $sister->id])->assertForbidden();
        $this->postJson('/api/v1/webhooks', $body)->assertForbidden(); // whole group
        $this->postJson('/api/v1/webhooks', $body + ['hotel_id' => $this->hotel->id])->assertCreated();
    }

    private function employee(array $extra = []): Employee
    {
        $dept = Department::firstOrCreate(['code' => 'FO'], ['name' => 'Réception', 'cost_center' => 'rooms']);

        return Employee::create($extra + [
            'number' => 'E'.random_int(1000, 9999), 'first_name' => 'Aïcha', 'last_name' => 'Bello', 'hire_date' => now()->startOfMonth()->subYear()->toDateString(),
            'department_id' => $dept->id, 'payment_method' => 'bank', 'bank_name' => 'AFB', 'bank_account' => 'CM21 0001 2345', 'momo_number' => '677000999',
            'cnps_number' => 'CN123', 'tax_number' => 'NIU9', 'base_salary' => 200_000,
        ]);
    }

    /** authz:payroll:sod-check-only-against-original-creator */
    public function test_whoever_computed_the_payroll_cannot_approve_it(): void
    {
        $this->employee();
        $run = $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk()->json();

        // A payroll officer recomputes, then tries to approve: refused (computed_by), although prepared_by is someone else.
        $po = $this->makeUser($this->group, 'payroll-officer', allHotels: true);
        $po->givePermissionTo('payroll.approve');
        $this->actAs($po, $this->hotel);
        $this->postJson('/api/v1/payroll/runs', ['period' => now()->format('Y-m')])->assertOk();
        $this->assertSame($po->id, (int) DB::table('payroll_runs')->where('id', $run['id'])->value('computed_by'));
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertStatus(422);

        $fd = $this->makeUser($this->group, 'finance-director', allHotels: true);
        $fd->givePermissionTo('payroll.approve');
        $this->actAs($fd, $this->hotel);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
    }

    /** authz:hr:self-modification-of-own-salary-bank-and-advances */
    public function test_hr_staff_cannot_change_their_own_pay_grant_themselves_an_advance_or_decide_their_own_request(): void
    {
        $officer = $this->makeUser($this->group, 'hr-officer', hotels: [$this->hotel]);
        $officer->givePermissionTo('payroll.manage');
        $self = $this->employee(['user_id' => $officer->id]);
        $other = $this->employee();
        $this->actAs($officer, $this->hotel);

        $this->patchJson("/api/v1/hr/employees/{$self->id}", ['base_salary' => 900_000])->assertStatus(422);
        $this->patchJson("/api/v1/hr/employees/{$self->id}", ['bank_account' => 'MINE-0001'])->assertStatus(422);
        $this->patchJson("/api/v1/hr/employees/{$self->id}", ['momo_number' => '699999999'])->assertStatus(422);
        $this->patchJson("/api/v1/hr/employees/{$self->id}", ['phone' => '699000000'])->assertOk(); // non-pay data stays editable
        $this->postJson("/api/v1/hr/employees/{$self->id}/advances", ['amount' => 100_000, 'monthly_deduction' => 10_000])->assertStatus(422);
        $this->assertSame(200_000, (int) $self->fresh()->base_salary);
        $this->assertSame('CM21 0001 2345', $self->fresh()->bank_account);

        $req = ChangeRequest::create(['employee_id' => $self->id, 'kind' => 'bank_change', 'payload' => ['bank_name' => 'UBA', 'bank_account' => 'MINE-0002'], 'status' => 'pending']);
        $this->postJson("/api/v1/hr/talent/requests/{$req->id}/decide", ['approve' => true])->assertStatus(422);
        $this->assertSame('CM21 0001 2345', $self->fresh()->bank_account);

        // A colleague's record is still managed normally.
        $this->patchJson("/api/v1/hr/employees/{$other->id}", ['base_salary' => 210_000])->assertOk();
        $this->postJson("/api/v1/hr/employees/{$other->id}/advances", ['amount' => 50_000, 'monthly_deduction' => 10_000])->assertCreated();
    }

    /** pii:hr:employee-model-returns-bank-tax-ids-to-view-roles */
    public function test_bank_and_tax_numbers_are_hidden_from_view_only_roles(): void
    {
        $e = $this->employee();
        $auditor = $this->makeUser($this->group, 'auditor', allHotels: true);
        $this->actAs($auditor, $this->hotel);
        $row = $this->getJson("/api/v1/hr/employees/{$e->id}")->assertOk()->json();
        foreach (Employee::SENSITIVE as $k) {
            $this->assertArrayNotHasKey($k, $row);
        }
        $this->assertSame('Bello', $row['last_name']);

        $po = $this->makeUser($this->group, 'payroll-officer', allHotels: true);
        $this->actAs($po, $this->hotel);
        $row = $this->getJson("/api/v1/hr/employees/{$e->id}")->assertOk()->json();
        $this->assertSame('CM21 0001 2345', $row['bank_account']);
        $this->assertSame('NIU9', $row['tax_number']);
    }

    /** authz:tenancy:hotels-and-staff-directory-without-permission */
    public function test_hotel_list_is_limited_for_users_without_hotels_view(): void
    {
        $this->hotel->forceFill(['settings' => ['secret_note' => 'x'] + ($this->hotel->settings ?? [])])->save();
        $receptionist = $this->makeUser($this->group, 'receptionist', hotels: [$this->hotel]);
        $this->actAs($receptionist, $this->hotel);
        $row = $this->getJson('/api/v1/hotels')->assertOk()->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'city'], array_keys($row));
        $one = $this->getJson("/api/v1/hotels/{$this->hotel->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('settings', $one);
        $this->assertSame($this->hotel->name, $one['name']);

        $this->actAs($this->user, $this->hotel);
        $this->assertArrayHasKey('settings', $this->getJson('/api/v1/hotels')->assertOk()->json('data.0'));
    }

    /** tenancy:guests:export-and-merge-ignore-allowed-hotels */
    public function test_guest_export_and_merge_respect_allowed_hotels(): void
    {
        [$type, $plan, $guest] = $this->seedInventory(rooms: 1);
        [, $sister] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        foreach ([[$this->hotel->id, 'R-HERE'], [$sister->id, 'R-THERE']] as [$hid, $num]) {
            DB::table('reservations')->insert(['group_id' => $this->group->id, 'hotel_id' => $hid, 'number' => $num, 'booking_ref' => $num, 'guest_id' => $guest->id,
                'room_type_id' => $type->id, 'rate_plan_id' => $plan->id, 'arrival' => now()->toDateString(), 'departure' => now()->addDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $dup = Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'jean2@test.cm']);
        DB::table('folios')->insert(['group_id' => $this->group->id, 'hotel_id' => $sister->id, 'number' => 'F-SIS-1', 'kind' => 'guest', 'guest_id' => $dup->id,
            'name' => 'Jean Mballa', 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);

        $gm = $this->makeUser($this->group, 'general-manager', hotels: [$this->hotel]);
        $this->actAs($gm, $this->hotel);
        $export = $this->getJson("/api/v1/guests/{$guest->id}/export")->assertOk()->json();
        $this->assertSame(['R-HERE'], array_column($export['reservations'], 'number'));
        $this->postJson("/api/v1/guests/{$guest->id}/merge", ['duplicate_id' => $dup->id])->assertStatus(422);
        $this->assertNull(Guest::find($dup->id)->merged_into_id);

        $this->actAs($this->user, $this->hotel); // group-wide
        $this->assertCount(2, $this->getJson("/api/v1/guests/{$guest->id}/export")->assertOk()->json('reservations'));
        $this->postJson("/api/v1/guests/{$guest->id}/merge", ['duplicate_id' => $dup->id])->assertOk();
    }

    /** business-logic:loyalty:unbounded-manual-point-credit */
    public function test_large_manual_point_adjustments_need_the_dedicated_permission(): void
    {
        $this->putJson('/api/v1/loyalty/program', ['spend_per_point' => 1000, 'point_value' => 10])->assertOk()->assertJsonPath('max_manual_adjustment', 5000);
        $guest = Guest::create(['first_name' => 'Amina', 'last_name' => 'Ngono', 'email' => 'amina@test.cm']);
        $m = $this->postJson('/api/v1/loyalty/members', ['guest_id' => $guest->id])->assertCreated()->json();

        $fom = $this->makeUser($this->group, 'front-office-manager', hotels: [$this->hotel]);
        $fom->givePermissionTo('loyalty.adjust_points');
        $this->actAs($fom, $this->hotel);
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 5000, 'reason' => 'Goodwill'])->assertCreated();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 5001, 'reason' => 'Goodwill'])->assertForbidden();
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => -5001, 'reason' => 'Correction'])->assertForbidden();
        $fom->givePermissionTo('loyalty.adjust_large');
        $this->postJson("/api/v1/loyalty/members/{$m['id']}/adjust", ['points' => 6000, 'reason' => 'Complaint settlement'])->assertCreated();
    }

    /** authn:totp:no-replay-protection */
    public function test_a_totp_code_is_accepted_once_and_older_steps_are_refused(): void
    {
        $mfa = app(TwoFactor::class);
        $secret = $mfa->begin($this->user);
        $mfa->confirm($this->user, Totp::at($secret, time()));
        $now = Totp::at($secret, time());
        $this->assertTrue($mfa->check($this->user->fresh(), $now));
        $this->assertFalse($mfa->check($this->user->fresh(), $now), 'same code replayed');
        $this->assertFalse($mfa->check($this->user->fresh(), Totp::at($secret, time() - 30)), 'older step');
        $this->assertTrue($mfa->check($this->user->fresh(), Totp::at($secret, time() + 30)), 'next step still fine');
    }

    /** authz:passport:oauth-management-routes-open-to-all-staff */
    public function test_only_the_passport_token_endpoints_are_registered(): void
    {
        $this->assertTrue(Route::has('passport.token'));
        $this->assertTrue(Route::has('passport.token.refresh'));
        $this->assertFalse(Route::has('passport.authorizations.authorize'));
        $this->assertFalse(Route::has('passport.device.code'));
        $this->assertContains('throttle', Route::getRoutes()->getByName('passport.token')->gatherMiddleware());
        $this->postJson('/oauth/token', ['grant_type' => 'password'])->assertStatus(400)->assertHeader('X-RateLimit-Limit');
    }

    /** rate-limit:trust-proxies-star-xff-spoof */
    public function test_forwarded_for_is_ignored_unless_the_proxy_is_configured(): void
    {
        Route::get('/_sec2/ip', fn (Request $r) => response()->json(['ip' => $r->ip()]));
        $spoof = ['HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => '198.51.100.7'];
        config(['trustedproxy.proxies' => null]);
        $this->call('GET', '/_sec2/ip', [], [], [], $spoof)->assertJsonPath('ip', '198.51.100.7');
        config(['trustedproxy.proxies' => '198.51.100.7']);
        $this->call('GET', '/_sec2/ip', [], [], [], $spoof)->assertJsonPath('ip', '203.0.113.9');
    }

    /** config:demo-logins:env-gate-and-mfa-bypass */
    public function test_demo_logins_stay_off_in_production_without_the_explicit_override(): void
    {
        $eval = function (array $vars): bool {
            $saved = [];
            foreach ($vars as $k => $v) {
                $saved[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
                $_ENV[$k] = $_SERVER[$k] = $v;
                putenv("$k=$v");
            }
            try {
                return (require config_path('app.php'))['demo_logins'];
            } finally {
                foreach ($saved as $k => [$e, $s, $g]) {
                    $e === null ? Arr::forget($_ENV, $k) : $_ENV[$k] = $e;
                    $s === null ? Arr::forget($_SERVER, $k) : $_SERVER[$k] = $s;
                    putenv($g === false ? $k : "$k=$g");
                }
            }
        };
        $this->assertFalse($eval(['DEMO_LOGINS' => 'true', 'APP_ENV' => 'production', 'ALLOW_DEMO_LOGINS_IN_PRODUCTION' => 'false']));
        $this->assertTrue($eval(['DEMO_LOGINS' => 'true', 'APP_ENV' => 'production', 'ALLOW_DEMO_LOGINS_IN_PRODUCTION' => 'true']));
        $this->assertTrue($eval(['DEMO_LOGINS' => 'true', 'APP_ENV' => 'staging', 'ALLOW_DEMO_LOGINS_IN_PRODUCTION' => 'false']));
        $this->assertFalse($eval(['DEMO_LOGINS' => 'false', 'APP_ENV' => 'local', 'ALLOW_DEMO_LOGINS_IN_PRODUCTION' => 'false']));
    }
}
