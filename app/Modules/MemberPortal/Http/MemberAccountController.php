<?php

namespace App\Modules\MemberPortal\Http;

use App\Modules\MemberPortal\Application\MemberBookings;
use App\Modules\MemberPortal\Application\MemberChat;
use App\Modules\MemberPortal\Application\MemberPortalService;
use App\Modules\MemberPortal\Application\MemberRequests;
use App\Modules\MemberPortal\Domain\MemberAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The /account area. Every action works on the signed-in member's own guest profile and group only. */
class MemberAccountController
{
    public function __construct(private MemberPortalService $portal, private MemberBookings $bookings, private MemberChat $chat, private MemberRequests $requests) {}

    private function me(): MemberAccount
    {
        return Auth::guard('member')->user();
    }

    private function lang(Request $r): string
    {
        return str_starts_with((string) ($r->query('lang') ?: $this->me()->locale), 'en') ? 'en' : 'fr';
    }

    // ---- pages
    public function home() { return Inertia::render('Member/Home', ['data' => $this->portal->home($this->me())]); }

    public function card() { return Inertia::render('Member/Card', ['data' => $this->portal->card($this->me())]); }

    public function points(Request $r)
    {
        $q = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return Inertia::render('Member/Points', ['data' => $this->portal->points($this->me(), $q['from'] ?? null, $q['to'] ?? null)]);
    }

    public function bookings() { return Inertia::render('Member/Bookings', ['data' => $this->bookings->list($this->me())]); }

    public function hotels() { return Inertia::render('Member/Hotels', ['data' => $this->portal->hotels($this->me())]); }

    public function bookPage(Request $r)
    {
        return Inertia::render('Member/Book', ['hotels' => $this->portal->hotels($this->me())['hotels'], 'hotel' => $r->query('hotel')]);
    }

    public function chatPage(Request $r)
    {
        $hotels = $this->portal->hotels($this->me())['hotels'];
        $hotel = $this->chat->hotelFor($this->me(), $r->integer('hotel') ?: null);

        return Inertia::render('Member/Chat', ['hotels' => $hotels, 'thread' => $this->chat->thread($this->me(), $hotel)]);
    }

    public function requestsPage()
    {
        return Inertia::render('Member/Requests', [
            'categories' => MemberRequests::CATEGORIES, 'list' => $this->requests->list($this->me()), 'home' => $this->portal->home($this->me())['current_stay'],
        ]);
    }

    public function profilePage() { return Inertia::render('Member/Profile', ['data' => $this->portal->profile($this->me())]); }

    // ---- loyalty card
    public function join() { return $this->portal->join($this->me()); }

    public function blockCard(int $card) { return $this->portal->blockCard($this->me(), $card); }

    public function replaceCard(int $card) { return $this->portal->replaceCard($this->me(), $card); }

    public function cardPdf(Request $r, int $card) { return $this->portal->cardPdf($this->me(), $card, $this->lang($r)); }

    public function pointsPdf(Request $r)
    {
        $q = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return $this->portal->statementPdf($this->me(), $q['from'] ?? null, $q['to'] ?? null, $this->lang($r));
    }

    // ---- bookings
    public function bookingDetail(string $number) { return $this->bookings->detail($this->me(), $number); }

    public function modify(Request $r, string $number)
    {
        $d = $r->validate(['arrival' => ['required', 'date'], 'departure' => ['required', 'date'], 'apply' => ['sometimes', 'boolean']]);

        return $this->bookings->modify($this->me(), $number, $d['arrival'], $d['departure'], (bool) ($d['apply'] ?? false));
    }

    public function cancel(string $number) { return $this->bookings->cancel($this->me(), $number); }

    public function pay(Request $r, string $number)
    {
        $d = $r->validate(['amount' => ['nullable', 'integer', 'min:1']]);

        return $this->bookings->payLink($this->me(), $number, $d['amount'] ?? null);
    }

    public function invoicePdf(int $id) { return $this->bookings->invoicePdf($this->me(), $id); }

    public function receiptPdf(Request $r, int $id) { return $this->bookings->receiptPdf($this->me(), $id, $this->lang($r)); }

    public function search(Request $r)
    {
        $d = $r->validate([
            'arrival' => ['required', 'date', 'after_or_equal:today'], 'departure' => ['required', 'date', 'after:arrival', 'before:'.now()->addYears(2)->toDateString()],
            'adults' => ['sometimes', 'integer', 'between:1,8'], 'children' => ['sometimes', 'integer', 'between:0,6'],
            'hotel_code' => ['nullable', 'string', 'max:12'], 'promo_code' => ['nullable', 'string', 'max:24'],
        ]);

        return $this->bookings->search($this->me(), $d);
    }

    public function book(Request $r)
    {
        $d = $r->validate([
            'hotel_code' => ['required', 'string', 'max:12'], 'room_type_id' => ['required', 'integer'], 'rate_plan_id' => ['required', 'integer'],
            'arrival' => ['required', 'date', 'after_or_equal:today'], 'departure' => ['required', 'date', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'between:1,8'], 'children' => ['sometimes', 'integer', 'between:0,6'],
            'special_requests' => ['nullable', 'string', 'max:1000'], 'promo_code' => ['nullable', 'string', 'max:24'],
        ]);

        return response()->json($this->bookings->book($this->me(), $d), 201);
    }

    // ---- chat and requests
    public function chatMessages(Request $r)
    {
        $q = $r->validate(['hotel' => ['nullable', 'integer'], 'after' => ['nullable', 'integer', 'min:0']]);

        return $this->chat->thread($this->me(), $this->chat->hotelFor($this->me(), $q['hotel'] ?? null), (int) ($q['after'] ?? 0));
    }

    public function chatSend(Request $r)
    {
        $d = $r->validate(['text' => ['required', 'string', 'max:1000'], 'hotel' => ['nullable', 'integer']]);

        return response()->json($this->chat->send($this->me(), $this->chat->hotelFor($this->me(), $d['hotel'] ?? null), trim($d['text'])), 201);
    }

    public function requestCreate(Request $r)
    {
        $d = $r->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(MemberRequests::CATEGORIES))],
            'items' => ['present', 'array', 'max:10'], 'items.*' => ['string', 'max:40'], 'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->requests->create($this->me(), $d['category'], $d['items'], $d['note'] ?? null), 201);
    }

    // ---- profile
    public function profileUpdate(Request $r)
    {
        $d = $r->validate(['locale' => ['sometimes', 'in:fr,en'], 'marketing_consent' => ['sometimes', 'boolean']]);
        $out = $this->portal->updateProfile($this->me(), $d);
        if (isset($d['locale'])) {
            $r->session()->put('locale', $d['locale']);
        }

        return $out;
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
        $me = $this->me();
        if (! Hash::check($d['current_password'], (string) $me->password)) {
            throw ValidationException::withMessages(['current_password' => __('member.auth.failed')]);
        }
        $me->update(['password' => $d['password']]);

        return ['status' => 'password_changed'];
    }
}
