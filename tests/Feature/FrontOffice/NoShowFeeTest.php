<?php

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\NightAudit;
use App\Modules\FrontOffice\Application\NoShowFee;
use App\Modules\FrontOffice\Domain\FolioLine;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Experience\Application\DepositAutomation;
use App\Modules\Reservations\Experience\Domain\ExperienceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** S2: the no-show fee is posted to the guest folio (CXL), only when the hotel turns it on, never twice, GL balanced. */
class NoShowFeeTest extends TestCase
{
    private $type;

    private $plan;

    private $guest;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->type, $this->plan, $this->guest] = $this->seedInventory(rooms: 3, rate: 40_000);
        $this->plan->update(['cancellation_penalty' => 'first_night']);
    }

    private function booking(int $startOffset = 0): Reservation
    {
        return app(ReservationService::class)->create([
            'guest_id' => $this->guest->id, 'room_type_id' => $this->type->id, 'rate_plan_id' => $this->plan->id,
            'arrival' => Carbon::today()->addDays($startOffset)->toDateString(), 'departure' => Carbon::today()->addDays($startOffset + 2)->toDateString(),
        ]);
    }

    public function test_off_by_default_marks_status_but_posts_nothing(): void
    {
        $r = $this->booking();
        app(NightAudit::class)->run($this->hotel);
        $this->assertSame('no_show', $r->refresh()->status);
        $this->assertSame(0, FolioLine::where('source_type', 'no_show_fee')->count());
        $this->assertSame(40_000, (int) $r->cancellation_fee);
    }

    public function test_night_audit_posts_fee_once_with_balanced_books(): void
    {
        ExperienceSetting::forCurrentHotel()->update(['no_show_fee' => true]);
        $r = $this->booking();
        app(NightAudit::class)->run($this->hotel);

        $lines = FolioLine::where('source_type', 'no_show_fee')->get();
        $this->assertCount(1, $lines);
        $this->assertSame('CXL', $lines[0]->code);
        $this->assertSame(40_000, (int) $lines[0]->amount);
        $this->assertStringContainsString($r->number, $lines[0]->description);
        $this->assertSame(1, (int) DB::table('journal_entries')->where('source_type', 'folio_line')->where('source_id', (string) $lines[0]->id)->count());
        $this->assertBooksBalance();

        // a second pass (automation or a repeat) never charges again
        app(NoShowFee::class)->post($r->refresh());
        $this->assertSame(1, FolioLine::where('source_type', 'no_show_fee')->count());
        $this->assertBooksBalance();
    }

    public function test_automation_posts_for_late_audit_hotels_and_no_double_charge(): void
    {
        ExperienceSetting::forCurrentHotel()->update(['no_show_fee' => true, 'auto_no_show' => true]);
        $r = $this->booking(-1);

        app(DepositAutomation::class)->run();
        $this->assertSame('no_show', $r->refresh()->status);
        $this->assertSame(1, FolioLine::where('source_type', 'no_show_fee')->count());

        app(DepositAutomation::class)->run();
        app(NoShowFee::class)->post($r);
        $this->assertSame(1, FolioLine::where('source_type', 'no_show_fee')->count());
        $this->assertBooksBalance();
    }

    public function test_free_cancellation_plan_posts_nothing(): void
    {
        $this->plan->update(['cancellation_penalty' => 'none']);
        ExperienceSetting::forCurrentHotel()->update(['no_show_fee' => true]);
        $this->booking();
        app(NightAudit::class)->run($this->hotel);
        $this->assertSame(0, FolioLine::where('source_type', 'no_show_fee')->count());
    }
}
