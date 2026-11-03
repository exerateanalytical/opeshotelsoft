<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Domain\Department;
use App\Modules\HR\Domain\Employee;
use App\Modules\HR\Domain\Lifecycle\EContract;
use App\Modules\HR\Domain\Position;
use App\Modules\HR\Talent\Application\RecruitmentService;
use App\Modules\HR\Talent\Domain\Applicant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TalentRecruitmentTest extends TestCase
{
    private function openPost(): array
    {
        $dept = Department::create(['code' => 'FB', 'name' => 'Food and beverage', 'cost_center' => 'fb']);
        $pos = Position::create(['department_id' => $dept->id, 'title' => 'Waiter']);
        $id = $this->postJson('/api/v1/hr/talent/posts', ['title' => 'Waiter', 'department_id' => $dept->id, 'position_id' => $pos->id, 'contract_kind' => 'CDI', 'description' => 'Serve guests'])->assertCreated()->json('id');
        $this->postJson("/api/v1/hr/talent/posts/$id/status", ['status' => 'open'])->assertOk();

        return [$id, $dept, $pos];
    }

    private function apply(int $postId, string $email = 'ada@example.cm')
    {
        return $this->post('/api/v1/careers/'.$this->group->slug."/posts/$postId/apply", [
            'first_name' => 'Ada', 'last_name' => 'Ngo', 'email' => $email, 'phone' => '699000111', 'consent' => true,
            'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
        ]);
    }

    public function test_public_careers_page_lists_only_open_posts(): void
    {
        [$id] = $this->openPost();
        $this->postJson('/api/v1/hr/talent/posts', ['title' => 'Draft job'])->assertCreated();
        $list = $this->getJson('/api/v1/careers/'.$this->group->slug)->assertOk()->json('posts');
        $this->assertCount(1, $list);
        $this->assertSame($id, $list[0]['id']);
    }

    public function test_application_with_cv_requires_consent_and_blocks_duplicates(): void
    {
        Storage::fake('local');
        [$id] = $this->openPost();
        $this->postJson('/api/v1/careers/'.$this->group->slug."/posts/$id/apply", ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.cm'])->assertUnprocessable();
        $this->apply($id)->assertCreated();
        $this->assertNotNull(Applicant::first()->cv_path);
        Storage::disk('local')->assertExists(Applicant::first()->cv_path);
        $this->apply($id)->assertUnprocessable();
        $this->getJson('/api/v1/hr/talent/applicants/'.Applicant::first()->id.'/cv')->assertOk();
    }

    public function test_pipeline_notes_and_interview_scoring_panel_only(): void
    {
        Storage::fake('local');
        [$id] = $this->openPost();
        $this->apply($id)->assertCreated();
        $a = Applicant::first();
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/stage", ['stage' => 'screening', 'note' => 'Good CV'])->assertOk();
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/notes", ['body' => 'Called, available'])->assertCreated();
        $other = $this->makeUser($this->group, 'fnb-manager', hotels: [$this->hotel]);
        $iv = $this->postJson("/api/v1/hr/talent/applicants/$a->id/interviews", ['scheduled_at' => now()->addDays(2)->toDateTimeString(), 'panel' => [$this->user->id]])->assertCreated()->json('id');
        $this->assertSame('interview', $a->fresh()->stage);
        $this->postJson("/api/v1/hr/talent/interviews/$iv/scores", ['scores' => [['criterion' => 'Attitude', 'score' => 5], ['criterion' => 'Experience', 'score' => 3]], 'recommendation' => 'hire'])->assertSuccessful();
        $card = $this->getJson("/api/v1/hr/talent/interviews/$iv/scorecard")->assertOk()->json();
        $this->assertEquals(4.0, $card['overall']);
        $this->assertSame(1, $card['tally']['hire']);
        $this->actAs($other, $this->hotel);
        $this->postJson("/api/v1/hr/talent/interviews/$iv/scores", ['scores' => [['criterion' => 'Attitude', 'score' => 2]], 'recommendation' => 'no'])->assertForbidden();
        $this->actAs($this->user, $this->hotel);
        $this->postJson("/api/v1/hr/talent/interviews/$iv/scores", ['scores' => [['criterion' => 'Bogus', 'score' => 2]], 'recommendation' => 'no'])->assertUnprocessable();
    }

    public function test_accepting_an_offer_creates_the_employee_and_econtract(): void
    {
        Storage::fake('local');
        [$id, $dept, $pos] = $this->openPost();
        $this->apply($id)->assertCreated();
        $a = Applicant::first();
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/stage", ['stage' => 'screening'])->assertOk();
        $offer = $this->postJson("/api/v1/hr/talent/applicants/$a->id/offers", ['base_salary' => 150000, 'start_date' => now()->addMonth()->toDateString()])->assertCreated()->json('id');
        $this->assertSame('offer', $a->fresh()->stage);
        $url = $this->postJson("/api/v1/hr/talent/offers/$offer/send")->assertOk()->json('url');
        $token = basename($url);
        $this->getJson("/api/v1/careers/offers/$token")->assertOk()->assertJsonPath('base_salary', 150000);
        $this->postJson("/api/v1/careers/offers/$token/accept")->assertOk()->assertJsonPath('status', 'accepted');
        $this->actAs($this->user, $this->hotel);

        $e = Employee::where('first_name', 'Ada')->first();
        $this->assertNotNull($e);
        $this->assertSame($pos->id, $e->position_id);
        $this->assertSame(150000, (int) $e->contracts()->first()->base_salary);
        $this->assertSame(1, EContract::where('employee_id', $e->id)->count());
        $this->assertSame('hired', $a->fresh()->stage);
        $this->getJson("/api/v1/careers/offers/$token")->assertNotFound(); // link is single use
    }

    public function test_declined_offer_and_fixed_term_needs_end_date(): void
    {
        Storage::fake('local');
        [$id] = $this->openPost();
        $this->apply($id)->assertCreated();
        $a = Applicant::first();
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/stage", ['stage' => 'screening'])->assertOk();
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/offers", ['base_salary' => 100000, 'start_date' => now()->addMonth()->toDateString(), 'contract_kind' => 'CDD'])->assertUnprocessable();
        $offer = $this->postJson("/api/v1/hr/talent/applicants/$a->id/offers", ['base_salary' => 100000, 'start_date' => now()->addMonth()->toDateString()])->json('id');
        $token = basename($this->postJson("/api/v1/hr/talent/offers/$offer/send")->json('url'));
        $this->postJson("/api/v1/careers/offers/$token/decline")->assertOk()->assertJsonPath('status', 'declined');
        $this->actAs($this->user, $this->hotel);
        $this->assertSame('withdrawn', $a->fresh()->stage);
        $this->assertSame(0, Employee::count());
    }

    public function test_talent_pool_and_retention_anonymises_expired_applicants(): void
    {
        Storage::fake('local');
        [$id] = $this->openPost();
        $this->apply($id)->assertCreated();
        $this->apply($id, 'bob@example.cm')->assertCreated();
        [$a, $b] = Applicant::orderBy('id')->get()->all();
        $this->postJson("/api/v1/hr/talent/applicants/$b->id/pool", ['in' => true])->assertOk();
        $this->assertTrue($b->fresh()->retain_until->gt($a->fresh()->retain_until)); // pool keeps data longer
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/stage", ['stage' => 'rejected'])->assertOk();
        $path = $a->fresh()->cv_path;
        $a->update(['retain_until' => now()->subDay()->toDateString()]);
        $this->postJson('/api/v1/hr/talent/applicants/purge-expired')->assertOk()->assertJsonPath('anonymised', 1);
        $a = $a->fresh();
        $this->assertNotNull($a->anonymised_at);
        $this->assertNull($a->email);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($b->fresh()->anonymised_at);
        $this->postJson("/api/v1/hr/talent/applicants/$a->id/stage", ['stage' => 'screening'])->assertUnprocessable();
    }

    public function test_recruitment_requires_permission_and_is_tenant_scoped(): void
    {
        [$id] = $this->openPost();
        $outsider = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $this->actAs($outsider, $this->hotel);
        $this->getJson('/api/v1/hr/talent/posts')->assertForbidden();
        $this->postJson('/api/v1/hr/talent/posts', ['title' => 'X'])->assertForbidden();
        [$g2, $h2] = $this->makeHotel('Other Group', 'OTH');
        $u2 = $this->makeUser($g2, 'general-manager', allHotels: true);
        $this->provision($h2);
        $this->actAs($u2, $h2);
        $this->assertCount(0, $this->getJson('/api/v1/hr/talent/posts')->assertOk()->json());
        $this->assertSame(RecruitmentService::STAGES[0], 'applied');
    }
}
