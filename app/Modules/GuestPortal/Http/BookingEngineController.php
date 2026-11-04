<?php

namespace App\Modules\GuestPortal\Http;

use App\Modules\GuestPortal\Application\GuestLinks;
use App\Modules\GuestPortal\Application\PublicBookingResolver;
use App\Modules\GuestPortal\Domain\OnlineCheckin;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Loyalty\Domain\Membership;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Application\Inventory;
use App\Modules\Reservations\Application\Pricing;
use App\Modules\Reservations\Application\ReservationService;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Reservations\Revenue\Application\BookingSiteRevenue;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Public Booking API for hotel websites, the guest app and metasearch landing
 * pages. Scoped by group slug in the URL; only public rate plans are sold.
 * Members (loyalty card number + last name) unlock members-only rates.
 */
class BookingEngineController
{
    public function __construct(private TenantContext $ctx) {}

    public function hotels(string $group)
    {
        $g = $this->group($group);

        return [
            'group' => $g->only(['name', 'slug']),
            'hotels' => Hotel::where('status', 'active')->get(['id', 'group_id', 'code', 'name', 'city', 'country', 'stars', 'phone', 'email', 'check_in_time', 'check_out_time', 'currency', 'address', 'settings'])
                ->map(fn ($h) => collect($h->toArray())->except('settings')->all() + [
                    'description' => $h->settings['description'] ?? null,
                    'photos' => array_values(array_filter((array) ($h->settings['photos'] ?? []), 'is_string')),
                ] + ['room_types' => $this->asHotel($h, fn () => RoomType::where('active', true)->orderBy('sort')->get(['id', 'code', 'name', 'description', 'max_adults', 'max_children', 'bed_type', 'size_sqm', 'amenities']))]),
        ];
    }

    public function availability(Request $request, string $group, Inventory $inventory, Pricing $pricing)
    {
        $this->group($group);
        $data = $request->validate([
            'arrival' => ['required', 'date', 'after_or_equal:today'], 'departure' => ['required', 'date', 'after:arrival', 'before:'.now()->addYears(2)->toDateString()],
            'adults' => ['sometimes', 'integer', 'between:1,8'], 'children' => ['sometimes', 'integer', 'between:0,6'],
            'hotel_codes' => ['sometimes', 'array'], 'member_number' => ['nullable', 'string'], 'member_last_name' => ['nullable', 'string'],
            'promo_code' => ['nullable', 'string', 'max:24'], 'corporate_code' => ['nullable', 'string', 'max:24'], // R-A
        ]);
        $member = $this->member($data['member_number'] ?? null, $data['member_last_name'] ?? null);
        $arrival = Carbon::parse($data['arrival']);
        $departure = Carbon::parse($data['departure']);
        $hotels = Hotel::where('status', 'active')->when($data['hotel_codes'] ?? null, fn ($q, $v) => $q->whereIn('code', $v))->get();

        $out = [];
        foreach ($hotels as $hotel) {
            $offers = $this->asHotel($hotel, function () use ($hotel, $inventory, $pricing, $arrival, $departure, $data, $member) {
                $offers = [];
                foreach (RoomType::where('active', true)->orderBy('sort')->get() as $type) {
                    $grid = $inventory->grid($hotel->id, $type->id, $arrival->toDateString(), $departure->copy()->subDay()->toDateString());
                    $left = min(array_column($grid, 'available') ?: [0]);
                    if ($left < 1) {
                        continue;
                    }
                    $plans = RatePlan::where('active', true)->where('public', true)
                        ->where(fn ($q) => $member ? $q : $q->where('members_only', false))->get();
                    foreach ($plans as $plan) {
                        try {
                            $q = $pricing->quote($plan, $type, $arrival, $departure, $data['adults'] ?? 1, $data['children'] ?? 0);
                        } catch (DomainException) {
                            continue;
                        }
                        $offers[] = [
                            'room_type' => $type->only(['id', 'code', 'name', 'max_adults', 'max_children', 'amenities']),
                            'rate_plan' => $plan->only(['id', 'code', 'name', 'meal_plan', 'refundable', 'cancellation_hours', 'members_only', 'deposit_required', 'deposit_percent']),
                            'rooms_left' => min($left, 5), 'total' => $q['total'], 'currency' => $hotel->currency, 'nights' => $q['nights'],
                        ];
                    }
                }

                // R-A: corporate rates, promo codes and best-available-rate flags.
                return app(BookingSiteRevenue::class)->decorate($offers, $arrival, $departure, $data);
            });
            $out[] = ['hotel' => $hotel->only(['id', 'code', 'name', 'city']), 'offers' => $offers];
        }

        return ['arrival' => $data['arrival'], 'departure' => $data['departure'], 'member' => $member ? $member->tier->only(['code', 'name', 'benefits']) : null, 'results' => $out];
    }

    public function book(Request $request, string $group, ReservationService $reservations)
    {
        $this->group($group);
        $data = $request->validate([
            'hotel_code' => ['required', 'string'], 'room_type_id' => ['required', 'integer'], 'rate_plan_id' => ['required', 'integer'],
            'arrival' => ['required', 'date', 'after_or_equal:today'], 'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,8'], 'children' => ['sometimes', 'integer', 'between:0,6'],
            'guest' => ['required', 'array'], 'guest.first_name' => ['required', 'string', 'max:100'], 'guest.last_name' => ['required', 'string', 'max:100'],
            'guest.email' => ['required', 'email'], 'guest.phone' => ['required', 'string', 'max:30'], 'guest.country' => ['nullable', 'string', 'size:2'],
            'special_requests' => ['nullable', 'string', 'max:1000'], 'member_number' => ['nullable', 'string'],
            'consent_marketing' => ['sometimes', 'boolean'], 'accept_terms' => ['accepted'],
            'promo_code' => ['nullable', 'string', 'max:24'], 'corporate_code' => ['nullable', 'string', 'max:24'], // R-A
        ]);
        $hotel = Hotel::where('code', $data['hotel_code'])->where('status', 'active')->firstOrFail();

        $reservation = $this->asHotel($hotel, function () use ($data, $reservations) {
            $revenue = app(BookingSiteRevenue::class);
            $prepared = $revenue->prepare($data); // R-A: public plan or corporate plan, promo discount
            $plan = $prepared['plan'];

            return DB::transaction(function () use ($data, $plan, $reservations, $revenue, $prepared) {
                // An existing profile is reused only when the booker also knows its last name: an email or a
                // member number alone must not attach a stranger's booking to a guest's profile, loyalty
                // account and stored ID data (SEC1 audit). Otherwise the booking gets its own new profile.
                $sameName = fn ($q) => $q->whereRaw('lower(last_name) = ?', [mb_strtolower(trim($data['guest']['last_name']))]);
                $member = ! empty($data['member_number']) ? Membership::where('number', $data['member_number'])->first() : null;
                $memberGuest = $member?->guest;
                if ($memberGuest && mb_strtolower(trim($memberGuest->last_name)) !== mb_strtolower(trim($data['guest']['last_name']))) {
                    $memberGuest = null;
                }
                $guest = $memberGuest
                    ?? Guest::where('email', $data['guest']['email'])->where($sameName)->first()
                    ?? Guest::create([
                        'first_name' => $data['guest']['first_name'], 'last_name' => $data['guest']['last_name'],
                        'email' => $data['guest']['email'], 'phone' => $data['guest']['phone'], 'country' => $data['guest']['country'] ?? null,
                        'marketing_consent' => $data['consent_marketing'] ?? false, 'consent_at' => now(),
                    ]);

                $reservation = $reservations->create(array_merge([
                    'guest_id' => $guest->id, 'room_type_id' => $data['room_type_id'], 'rate_plan_id' => $plan->id,
                    'arrival' => $data['arrival'], 'departure' => $data['departure'], 'adults' => $data['adults'] ?? 1,
                    'children' => $data['children'] ?? 0, 'channel' => 'website', 'special_requests' => $data['special_requests'] ?? null,
                    'status' => $plan->deposit_required ? 'tentative' : 'confirmed', 'guarantee' => $plan->deposit_required ? 'deposit' : 'none',
                ], $prepared['fields']));
                $revenue->finish($reservation, $prepared);

                return $reservation;
            });
        });

        return response()->json([
            'confirmation' => $reservation->number, 'reference' => $reservation->public_code, 'hotel_code' => $hotel->code, 'booking_ref' => $reservation->booking_ref, 'status' => $reservation->status,
            'total' => $reservation->total_amount, 'currency' => $reservation->currency, 'deposit_required' => $reservation->deposit_required,
            'manage_token' => $this->token($reservation),
            'upsell_offers' => $reservation->getAttribute('upsell_offers') ?? [],
        ], 201);
    }

    /** Guest looks up their booking with confirmation number + last name. */
    public function lookup(Request $request, string $group)
    {
        $this->group($group);
        $data = $request->validate(['confirmation' => ['required', 'string'], 'last_name' => ['required', 'string'], 'hotel' => ['nullable', 'string', 'max:20']]);
        $r = $this->findBooking($data['confirmation'], $data['last_name'], $data['hotel'] ?? null);

        $plan = $this->ctx->withoutScope(fn () => RatePlan::find($r->rate_plan_id));
        $membership = Membership::with('tier')->where('guest_id', $r->guest_id)->where('status', 'active')->first();
        $checkin = OnlineCheckin::where('reservation_id', $r->id)->first();

        return $r->only(['number', 'public_code', 'status', 'arrival', 'departure', 'adults', 'children', 'total_amount', 'currency', 'special_requests', 'deposit_required', 'deposit_paid'])
            + [
                'hotel' => Hotel::withoutGlobalScopes()->find($r->hotel_id)?->only(['code', 'name', 'phone', 'check_in_time']),
                'room_type' => $r->roomType?->name, 'manage_token' => $this->token($r),
                'guest' => $r->guest->only(['first_name', 'last_name']),
                'policy' => $plan ? ['refundable' => (bool) $plan->refundable, 'cancellation_hours' => $plan->cancellation_hours, 'rate_plan' => $plan->name] : null,
                'can_cancel' => in_array($r->status, ['tentative', 'confirmed'], true),
                'loyalty' => $membership ? ['number' => $membership->number, 'tier' => $membership->tier?->name, 'points' => $membership->points_balance, 'qr_text' => 'OPES-LOYALTY:'.$membership->number] : null,
                'checkin' => $checkin?->only(['id_type', 'eta', 'submitted_at']),
            ];
    }

    /** Online check-in: ID document and ETA, submitted before arrival. */
    public function checkIn(Request $request, string $group)
    {
        $this->group($group);
        $data = $request->validate([
            'confirmation' => ['required', 'string'], 'token' => ['required', 'string'], 'hotel' => ['nullable', 'string', 'max:20'],
            'id_type' => ['required', 'in:passport,national_id,residence_permit,driving_licence'], 'id_number' => ['required', 'string', 'max:60'],
            'nationality' => ['nullable', 'string', 'size:2'], 'eta' => ['required', 'date_format:H:i'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $r = $this->tokenBooking($data['confirmation'], $data['token'], $data['hotel'] ?? null);
        if (! in_array($r->status, ['tentative', 'confirmed'], true)) {
            throw new DomainException('Online check-in is only open for upcoming bookings.', 'checkin-closed');
        }
        $checkin = OnlineCheckin::updateOrCreate(['reservation_id' => $r->id], [
            'group_id' => $r->group_id, 'hotel_id' => $r->hotel_id, 'id_type' => $data['id_type'], 'id_number' => $data['id_number'],
            'nationality' => $data['nationality'] ?? null, 'eta' => $data['eta'], 'notes' => $data['notes'] ?? null, 'submitted_at' => now(),
        ]);
        $this->ctx->withoutScope(fn () => $r->forceFill(['eta' => $data['eta']])->save());

        return response()->json(['status' => 'received', 'submitted_at' => $checkin->submitted_at], 201);
    }

    private function tokenBooking(string $ref, string $token, ?string $hotel = null): Reservation
    {
        // Booking reference or number; the token is bound to group + hotel + reservation id, so the right booking is the one it opens.
        return app(PublicBookingResolver::class)->resolve($this->ctx->groupId(), $ref, $hotel, null, $token);
    }

    public function cancel(Request $request, string $group, ReservationService $reservations)
    {
        $this->group($group);
        $data = $request->validate(['confirmation' => ['required', 'string'], 'token' => ['required', 'string'], 'hotel' => ['nullable', 'string', 'max:20']]);
        $r = $this->tokenBooking($data['confirmation'], $data['token'], $data['hotel'] ?? null);
        $hotel = Hotel::withoutGlobalScopes()->findOrFail($r->hotel_id);
        $fee = $this->asHotel($hotel, fn () => $reservations->cancel($r, 'Cancelled by guest online')->cancellation_fee);

        return ['status' => 'cancelled', 'cancellation_fee' => $fee];
    }

    private function group(string $slug): Group
    {
        $g = Group::where('slug', $slug)->where('status', 'active')->firstOrFail();
        $this->ctx->set($g->id, null, null);

        return $g;
    }

    private function asHotel(Hotel $hotel, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }

    private function member(?string $number, ?string $lastName): ?Membership
    {
        if (! $number || ! $lastName) {
            return null;
        }
        $m = Membership::with('guest', 'tier')->where('number', $number)->where('status', 'active')->first();

        return $m && strcasecmp($m->guest->last_name, $lastName) === 0 ? $m : null;
    }

    private function findBooking(string $ref, string $lastName, ?string $hotel = null): Reservation
    {
        // Booking reference (unique per group) or confirmation number (per hotel): several matches are refused with a 422, never guessed.
        $r = app(PublicBookingResolver::class)->resolve($this->ctx->groupId(), $ref, $hotel, $lastName);
        $r->load('roomType');

        return $r;
    }

    /** Stateless manage-booking token. */
    private function token(Reservation $r): string
    {
        return GuestLinks::manageToken($r);
    }
}
