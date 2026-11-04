<?php

namespace Tests\Feature\Member;

use App\Models\User;
use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Facades\DB;

/** Member and guest portal presentation: language-aware numbers, hotel photo fallback data, review by booking reference. */
class PortalFormatTest extends MemberTestCase
{
    private function book(Hotel $hotel, string $last, string $email, ?User $as = null): Reservation
    {
        $this->actAs($as ?? $this->user, $hotel);
        $type = RoomType::create(['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'base_rate' => 50_000]);
        Room::create(['room_type_id' => $type->id, 'number' => '101']);
        $plan = RatePlan::create(['code' => 'BAR', 'name' => 'BAR']);
        $guest = Guest::create(['first_name' => 'Jean', 'last_name' => $last, 'email' => $email]);

        return app(ReservationService::class)->create([
            'guest_id' => $guest->id, 'room_type_id' => $type->id, 'rate_plan_id' => $plan->id,
            'arrival' => now()->addDays(3)->toDateString(), 'departure' => now()->addDays(5)->toDateString(),
        ]);
    }

    /** Two hotels of the group whose reservations share the same number and guest last name. */
    private function twoHotels(): array
    {
        [, $hotelB] = $this->makeHotel('x', 'BBB', $this->group);
        $this->provision($hotelB);
        $a = $this->book($this->hotel, 'Mballa', 'a@test.cm');
        $b = $this->book($hotelB, 'Mballa', 'b@test.cm');
        DB::table('reservations')->where('id', $b->id)->update(['number' => $a->number]);
        DB::table('reservations')->whereIn('id', [$a->id, $b->id])->update(['status' => 'checked_out']); // a review needs an arrived guest
        $b = Reservation::withoutGlobalScopes()->find($b->id);
        $this->asVisitor();

        return [$a, $b];
    }

    public function test_member_pages_use_the_shared_formatters_and_no_fixed_french_locale(): void
    {
        foreach (glob(base_path('resources/js/Pages/Member/*.tsx')) as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString("toLocaleString('fr-FR')", $src, basename($file).' formats numbers in fixed French');
        }
        $api = file_get_contents(base_path('resources/js/Pages/Member/api.ts'));
        $this->assertStringContainsString('formatCurrency', $api);
        $this->assertStringNotContainsString("toLocaleString('fr-FR')", $api);
        foreach (['Home', 'Points', 'Hotels', 'Card'] as $page) {
            $this->assertStringContainsString('formatNumber', file_get_contents(base_path("resources/js/Pages/Member/$page.tsx")), $page);
        }
    }

    public function test_hotel_star_and_photo_texts_exist_in_both_languages(): void
    {
        foreach (['fr', 'en'] as $l) {
            $j = json_decode(file_get_contents(lang_path("$l/member.json")), true);
            foreach (['member.hotels.stars_one', 'member.hotels.stars_other', 'member.hotels.unrated', 'member.hotels.photo_alt'] as $k) {
                $this->assertNotEmpty($j[$k] ?? null, "$l $k");
            }
        }
        $this->assertSame(':n étoiles', json_decode(file_get_contents(lang_path('fr/member.json')), true)['member.hotels.stars_other']);
        $this->assertSame(':n stars', json_decode(file_get_contents(lang_path('en/member.json')), true)['member.hotels.stars_other']);
    }

    public function test_hotels_page_passes_a_safe_photo_or_null_for_the_monogram_fallback(): void
    {
        [$a] = $this->member('photo@test.cm');
        $this->hotel->update(['stars' => 4]);
        $this->loginAs($a);
        $h = $this->inertia('/account/hotels')->assertOk()->json('props.data.hotels.0');
        $this->assertNull($h['photo'], 'no photo set: the page draws the monogram tile');
        $this->assertSame(4, (int) $h['stars']);

        $this->backToStaff();
        $this->hotel->update(['settings' => ['photo_url' => 'https://cdn.example.com/akwa.jpg']]);
        $this->loginAs($a);
        $this->assertSame('https://cdn.example.com/akwa.jpg', $this->inertia('/account/hotels')->json('props.data.hotels.0.photo'));

        foreach (['javascript:alert(1)', '//evil.example/x.jpg', 'data:text/html,x'] as $bad) {
            $this->backToStaff();
            $this->hotel->update(['settings' => ['photo_url' => $bad]]);
            $this->loginAs($a);
            $this->assertNull($this->inertia('/account/hotels')->json('props.data.hotels.0.photo'), $bad);
        }
    }

    public function test_review_link_uses_the_booking_reference(): void
    {
        [$a, $b] = $this->twoHotels();
        $link = app(GuestLinks::class)->review($b, 'en');
        $this->assertStringContainsString("/book/{$this->group->slug}/review", $link);
        $this->assertStringContainsString('c='.$b->public_code, $link);
        $this->assertStringContainsString('lang=en', $link);
        $this->assertStringNotContainsString('c='.$b->number, $link);
    }

    public function test_review_page_lists_the_hotels_and_the_form_posts_by_reference(): void
    {
        [$a, $b] = $this->twoHotels();
        $page = $this->inertia("/book/{$this->group->slug}/review?c={$b->public_code}")->assertOk();
        $codes = array_column($page->json('props.hotels'), 'code');
        sort($codes);
        $this->assertSame(['AKW', 'BBB'], $codes);

        $slug = $this->group->slug;
        // the unique reference alone identifies the booking
        $this->postJson("/api/v1/booking/$slug/manage/review", ['confirmation' => $b->public_code, 'last_name' => 'Mballa', 'rating' => 5, 'comment' => 'Super'])->assertStatus(201);
        $this->postJson("/api/v1/booking/$slug/manage/review", ['confirmation' => strtolower($a->public_code), 'last_name' => 'Mballa', 'rating' => 4])->assertStatus(201);
    }

    public function test_review_with_a_duplicate_number_asks_for_the_hotel_with_a_machine_readable_code(): void
    {
        [$a, $b] = $this->twoHotels();
        $slug = $this->group->slug;
        $res = $this->postJson("/api/v1/booking/$slug/manage/review", ['confirmation' => $a->number, 'last_name' => 'Mballa', 'rating' => 5])->assertStatus(422);
        $res->assertJsonPath('code', 'ambiguous_booking');
        $this->assertStringContainsString('booking reference', $res->json('message'));
        // choosing the hotel makes the number enough
        $this->postJson("/api/v1/booking/$slug/manage/review", ['confirmation' => $a->number, 'hotel' => 'BBB', 'last_name' => 'Mballa', 'rating' => 5])->assertStatus(201);
        $this->postJson("/api/v1/booking/$slug/manage/review", ['confirmation' => $a->number, 'hotel' => 'AKW', 'last_name' => 'Mballa', 'rating' => 3])->assertStatus(201);
    }

    public function test_review_page_source_explains_a_typed_number_and_asks_for_the_hotel(): void
    {
        $src = file_get_contents(base_path('resources/js/Pages/Public/Review.tsx'));
        $this->assertStringContainsString('ambiguous_booking', $src);
        $this->assertStringContainsString('numberOnly', $src);
        $this->assertStringContainsString("hotel: f.hotel || null", $src);
        $this->assertStringContainsString('Référence de réservation', $src);
        $this->assertStringContainsString('Booking reference', $src);
    }

    public function test_stay_credits_are_not_part_of_the_loyalty_data_model(): void
    {
        // Documents the finding: loyalty holds points, tiers and promotions only; no credit or free-night tables or columns exist.
        $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = 'public' and table_name like 'loyalty%'"))->pluck('table_name')->all();
        $this->assertNotEmpty($tables);
        $cols = collect(DB::select("select column_name from information_schema.columns where table_schema = 'public' and table_name like 'loyalty%'"))->pluck('column_name')->all();
        foreach ($cols as $c) {
            $this->assertDoesNotMatchRegularExpression('/free_night|stay_credit|reward_night/', $c);
        }
    }
}
