<?php

namespace App\Modules\GuestPortal\Application;

use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Resolves ONE reservation of a group from what a guest typed or clicked: the booking reference (public_code, unique per
 * group) or a confirmation number (sequenced per hotel, so it may exist in several hotels), optionally narrowed by hotel
 * code and proven by the manage token or the guest's last name. Never picks the first of several matches.
 */
class PublicBookingResolver
{
    public function __construct(private TenantContext $ctx) {}

    public function resolve(int $groupId, string $ref, ?string $hotelCode = null, ?string $lastName = null, ?string $token = null, int $nameMismatchStatus = 404): Reservation
    {
        $ref = trim($ref);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '');

        $c = $this->ctx->withoutScope(function () use ($groupId, $ref, $code) {
            $byCode = $code !== '' && strlen($code) <= 12
                ? Reservation::with('guest')->where('group_id', $groupId)->where('public_code', $code)->get()
                : collect();

            return $byCode->isNotEmpty()
                ? $byCode
                : Reservation::with('guest')->where('group_id', $groupId)->whereIn('number', array_unique([$ref, strtoupper($ref)]))->get();
        });

        if ($hotelCode !== null && $hotelCode !== '') {
            $hotelId = $this->ctx->withoutScope(fn () => Hotel::where('group_id', $groupId)->where('code', $hotelCode)->value('id'));
            $c = $c->where('hotel_id', $hotelId)->values();
        }
        if ($c->isEmpty()) {
            $this->fail(404, 'Booking not found.');
        }

        if ($token !== null && $token !== '') {
            $c = $c->filter(fn (Reservation $r) => hash_equals(GuestLinks::manageToken($r), $token))->values();
            if ($c->isEmpty()) {
                $this->fail(403, 'Invalid link.');
            }
        }
        if ($lastName !== null && $lastName !== '' && ($token === null || $token === '')) {
            $c = $c->filter(fn (Reservation $r) => $r->guest && strcasecmp($r->guest->last_name, $lastName) === 0)->values();
            if ($c->isEmpty()) {
                $this->fail($nameMismatchStatus, $nameMismatchStatus === 403 ? 'Invalid link.' : 'Booking not found.');
            }
        }

        if ($c->count() > 1) {
            $this->fail(422, 'Several bookings share this confirmation number. Please enter your booking reference (8 characters, shown on your confirmation) or select the hotel.', 'ambiguous_booking');
        }

        return $c->first();
    }

    private function fail(int $status, string $message, string $code = 'booking_error'): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
