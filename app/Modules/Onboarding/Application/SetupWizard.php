<?php

namespace App\Modules\Onboarding\Application;

use App\Models\User;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\FrontOffice\Domain\TaxCode;
use App\Modules\Onboarding\Domain\OnboardingProgress;
use App\Modules\Property\Domain\Outlet;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use Illuminate\Validation\ValidationException;

/**
 * Hotel setup wizard: group → hotel → rooms → rates → outlets → taxes → users → go-live.
 * Each step is written through the owning module's API by the screen; this service
 * keeps the resumable progress and computes what is actually in place.
 */
final class SetupWizard
{
    public const STEPS = ['group', 'hotel', 'rooms', 'rates', 'outlets', 'taxes', 'users', 'watermark', 'golive'];

    public function __construct(private EventBus $bus) {}

    public function progress(Hotel $hotel): OnboardingProgress
    {
        return OnboardingProgress::firstOrCreate([], ['current_step' => 'group', 'completed' => [], 'data' => []]);
    }

    public function state(Hotel $hotel): array
    {
        $p = $this->progress($hotel);
        $detected = $this->detected($hotel);
        $steps = [];
        foreach (self::STEPS as $s) {
            $steps[] = ['key' => $s, 'detected' => $detected[$s] ?? false, 'completed_at' => $p->completed[$s] ?? null,
                'done' => isset($p->completed[$s]) || ($detected[$s] ?? false)];
        }

        return [
            'hotel' => $hotel->only(['id', 'code', 'name', 'legal_name', 'tax_id', 'trade_register', 'address', 'city', 'phone', 'email', 'currency', 'timezone', 'locale', 'check_in_time', 'check_out_time']),
            'group' => $hotel->group()->first()?->only(['id', 'name', 'legal_name', 'country', 'default_currency', 'default_locale']),
            'current_step' => $p->current_step, 'steps' => $steps, 'data' => $p->data ?: (object) [],
            'checklist' => $this->checklist($hotel), 'went_live_at' => $p->went_live_at?->toIso8601String(),
            'counts' => [
                'room_types' => RoomType::count(), 'rooms' => Room::count(), 'rate_plans' => RatePlan::count(),
                'outlets' => Outlet::count(), 'tax_codes' => TaxCode::where('active', true)->count(), 'users' => $this->users($hotel),
            ],
        ];
    }

    public function save(Hotel $hotel, array $input): OnboardingProgress
    {
        $p = $this->progress($hotel);
        $completed = $p->completed ?? [];
        foreach ((array) ($input['complete'] ?? []) as $step) {
            $completed[$step] ??= now()->toIso8601String();
        }
        foreach ((array) ($input['reopen'] ?? []) as $step) {
            unset($completed[$step]);
        }
        $p->update([
            'current_step' => $input['current_step'] ?? $p->current_step,
            'completed' => $completed ?: (object) [],
            'data' => array_replace($p->data ?? [], (array) ($input['data'] ?? [])) ?: (object) [],
        ]);

        return $p;
    }

    public function goLive(Hotel $hotel, User $user): OnboardingProgress
    {
        $blocking = collect($this->checklist($hotel))->where('blocking', true)->where('ok', false)->pluck('key')->all();
        if ($blocking) {
            throw ValidationException::withMessages(['checklist' => ['Not ready: '.implode(', ', $blocking)]]);
        }
        $p = $this->progress($hotel);
        $completed = $p->completed ?? [];
        $completed['golive'] = now()->toIso8601String();
        $p->update(['went_live_at' => $p->went_live_at ?? now(), 'went_live_by' => $p->went_live_by ?? $user->id, 'completed' => $completed, 'current_step' => 'golive']);
        $this->bus->record('onboarding.hotel.went_live', ['hotel_id' => $hotel->id], 'hotel', $hotel->id);

        return $p;
    }

    /** @return list<array{key: string, ok: bool, blocking: bool}> */
    public function checklist(Hotel $hotel): array
    {
        $items = [
            ['hotel_legal', filled($hotel->legal_name) && filled($hotel->tax_id) && filled($hotel->trade_register), true],
            ['hotel_contact', filled($hotel->address) && filled($hotel->phone), true],
            ['chart_of_accounts', Account::count() > 0, true],
            ['rooms', Room::where('active', true)->count() > 0, true],
            ['rates', RatePlan::where('active', true)->count() > 0 && RoomType::where('base_rate', '>', 0)->count() > 0, true],
            ['taxes', TaxCode::where('active', true)->count() > 0, true],
            ['payment_methods', PaymentMethod::where('active', true)->count() > 0, true],
            ['business_date', $hotel->business_date !== null, true],
            ['outlets', Outlet::count() > 0, false],
            ['users', $this->users($hotel) > 1, false],
            ['logo', filled($hotel->logo_path), false],
            ['opening_balances', JournalEntry::where('journal_code', 'AN')->exists(), false],
        ];

        return array_map(fn ($i) => ['key' => $i[0], 'ok' => (bool) $i[1], 'blocking' => $i[2]], $items);
    }

    private function detected(Hotel $hotel): array
    {
        $check = collect($this->checklist($hotel))->pluck('ok', 'key');

        return [
            'group' => true, 'hotel' => $check['hotel_legal'] && $check['hotel_contact'], 'rooms' => $check['rooms'],
            'rates' => $check['rates'], 'outlets' => $check['outlets'], 'taxes' => false, 'users' => $check['users'],
            'watermark' => \App\Modules\Documents\Application\Watermark::settings($hotel->group()->first())['mode'] !== 'none',
            'golive' => (bool) $this->progress($hotel)->went_live_at,
        ];
    }

    private function users(Hotel $hotel): int
    {
        return User::where('group_id', $hotel->group_id)
            ->where(fn ($q) => $q->where('all_hotels', true)->orWhereHas('hotels', fn ($h) => $h->where('hotels.id', $hotel->id)))->count();
    }
}
