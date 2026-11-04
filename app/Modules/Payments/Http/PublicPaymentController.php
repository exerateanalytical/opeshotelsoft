<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\PaymentGatewaySetting;
use App\Modules\Payments\Domain\PaymentIntent;
use App\Modules\Reservations\Domain\Reservation;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public endpoints: provider callbacks (authenticated by signature) and the
 * booking-site deposit (authenticated by the manage-booking token).
 */
class PublicPaymentController
{
    public function __construct(private PaymentService $payments, private TenantContext $ctx) {}

    public function callback(Request $request, string $provider, string $reference)
    {
        abort_unless(in_array($provider, PaymentGatewaySetting::PROVIDERS, true), 404);
        [$status, $outcome] = $this->payments->handleCallback($provider, $reference, $request);

        return response()->json(['outcome' => $outcome], $status);
    }

    public function deposit(Request $request, string $group, string $confirmation)
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'provider' => ['required', Rule::in(PaymentGatewaySetting::PROVIDERS)],
            'payer_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9]{8,15}$/'], 'payer_email' => ['nullable', 'email', 'max:150'],
            'amount' => ['nullable', 'integer', 'min:1'], 'return_url' => ['nullable', 'url', 'max:500'],
        ]);
        $r = $this->reservation($group, $confirmation, $data['token']);
        $due = max(0, (int) $r->deposit_required - (int) $r->deposit_paid);
        $amount = $data['amount'] ?? ($due ?: (int) $r->total_amount);
        if ($amount > max($due, (int) $r->total_amount - (int) $r->deposit_paid)) {
            throw new DomainException('The amount exceeds the balance of the booking.');
        }

        $intent = $this->asHotel($r->hotel_id, fn () => $this->payments->create([
            'provider' => $data['provider'], 'amount' => $amount, 'reservation_id' => $r->id,
            'payer_phone' => $data['payer_phone'] ?? null, 'payer_email' => $data['payer_email'] ?? $r->guest?->email,
            'return_url' => $data['return_url'] ?? null,
        ], 'public'));

        return response()->json($this->present($intent), 201);
    }

    public function status(Request $request, string $group, string $confirmation, string $reference)
    {
        $r = $this->reservation($group, $confirmation, (string) $request->query('token'));
        $intent = $this->asHotel($r->hotel_id, function () use ($r, $reference) {
            $i = PaymentIntent::where('reference', $reference)->where('reservation_id', $r->id)->firstOrFail();

            return $this->payments->refresh($i)->fresh();
        });

        return $this->present($intent);
    }

    private function present(PaymentIntent $i): array
    {
        return $i->only(['reference', 'provider', 'amount', 'currency', 'status', 'redirect_url', 'failure_reason', 'expires_at']);
    }

    private function reservation(string $slug, string $confirmation, string $token): Reservation
    {
        $g = Group::where('slug', $slug)->where('status', 'active')->firstOrFail();
        // $confirmation is the booking reference (preferred) or a confirmation number; the token disambiguates and binds to hotel + id.
        return app(\App\Modules\GuestPortal\Application\PublicBookingResolver::class)->resolve($g->id, $confirmation, request()->input('hotel', request()->query('hotel')), null, $token);
    }

    /** Same stateless manage-booking token the GuestPortal booking API issues. */
    public static function manageToken(Reservation $r): string
    {
        return \App\Modules\GuestPortal\Application\GuestLinks::manageToken($r);
    }

    private function asHotel(int $hotelId, callable $fn): mixed
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $hotel = $this->ctx->withoutScope(fn () => Hotel::findOrFail($hotelId));
        $this->ctx->set($hotel->group_id, $hotel->id, [$hotel->id]);
        try {
            return $fn();
        } finally {
            $this->ctx->set(...$saved);
        }
    }
}
