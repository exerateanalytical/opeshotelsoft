<?php

namespace Tests;

use App\Models\User;
use App\Modules\Accounting\Application\Provisioner;
use App\Modules\Guests\Domain\Guest;
use App\Modules\Property\Domain\Room;
use App\Modules\Property\Domain\RoomType;
use App\Modules\Reservations\Domain\RatePlan;
use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Group $group;

    protected Hotel $hotel;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->group, $this->hotel] = $this->makeHotel('Akwa Test', 'AKW');
        $this->user = $this->makeUser($this->group, 'general-manager', allHotels: true);
        $this->actAs($this->user, $this->hotel);
        $this->provision($this->hotel);
    }

    /** Assert the hotel's general ledger balances and return balances by account. */
    protected function assertBooksBalance(): array
    {
        $tb = $this->getJson('/api/v1/accounting/reports/trial-balance')->assertOk()->json();
        $this->assertSame($tb['total_debit'], $tb['total_credit'], 'Trial balance must balance');

        return collect($tb['rows'])->keyBy('account')->map(fn ($r) => $r['balance'])->all();
    }

    /** @return array{0: Group, 1: Hotel} */
    protected function makeHotel(string $groupName, string $code, ?Group $group = null): array
    {
        $ctx = app(TenantContext::class);

        return $ctx->withoutScope(function () use ($groupName, $code, $group) {
            $group ??= Group::create(['name' => $groupName, 'slug' => str()->slug($groupName).'-'.uniqid()]);
            $hotel = new Hotel(['code' => $code, 'name' => "$groupName $code", 'country' => 'CM', 'city' => 'Douala']);
            $hotel->group_id = $group->id;
            $hotel->business_date = now()->toDateString();
            $hotel->save();

            return [$group, $hotel];
        });
    }

    /** Seed chart of accounts, taxes, charge codes and payment methods for a hotel. */
    protected function provision(Hotel $hotel): void
    {
        $ctx = app(TenantContext::class);
        $saved = [$ctx->groupId(), $ctx->hotelId(), $ctx->allowedHotelIds(), $ctx->actor()];
        $ctx->set($hotel->group_id, $hotel->id, [$hotel->id], $saved[3]);
        app(Provisioner::class)->provision($hotel);
        $ctx->set(...$saved);
    }

    protected function makeUser(Group $group, string $role, bool $allHotels = false, array $hotels = []): User
    {
        $user = User::create([
            'name' => ucfirst($role).' '.uniqid(), 'email' => uniqid().'@test.cm', 'password' => 'password-123',
            'group_id' => $group->id, 'all_hotels' => $allHotels,
        ]);
        $user->assignRole($role);
        $user->hotels()->sync(collect($hotels)->pluck('id'));

        return $user;
    }

    protected function actAs(User $user, ?Hotel $hotel = null): static
    {
        Passport::actingAs($user, [], 'api');
        $this->withHeaders(['X-Hotel-Id' => $hotel?->id, 'Accept' => 'application/json']);
        app(TenantContext::class)->set($user->group_id, $hotel?->id, $user->allowedHotelIds(), $user);

        return $this;
    }

    /** Room type with N rooms, a BAR rate plan, and a base rate. */
    protected function seedInventory(int $rooms = 3, int $rate = 50_000): array
    {
        $type = RoomType::create(['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'base_rate' => $rate]);
        foreach (range(1, $rooms) as $i) {
            Room::create(['room_type_id' => $type->id, 'number' => (string) (100 + $i)]);
        }
        $plan = RatePlan::create(['code' => 'BAR', 'name' => 'Best Available Rate']);
        $guest = Guest::create(['first_name' => 'Jean', 'last_name' => 'Mballa', 'email' => 'jean@test.cm']);

        return [$type, $plan, $guest];
    }
}
