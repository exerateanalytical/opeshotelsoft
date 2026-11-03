<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    public function test_staff_lists_users_of_current_hotel_without_sensitive_fields(): void
    {
        [, $other] = $this->makeHotel('Akwa Test', 'BON', $this->group);
        $here = $this->makeUser($this->group, 'room-attendant', hotels: [$this->hotel]);
        $there = $this->makeUser($this->group, 'room-attendant', hotels: [$other]);
        [$otherGroup] = $this->makeHotel('Other Group', 'OTH');
        $foreign = $this->makeUser($otherGroup, 'room-attendant', allHotels: true);

        $this->actAs($here, $this->hotel);
        $rows = $this->getJson('/api/v1/staff')->assertOk()->json();
        $ids = array_column($rows, 'id');
        $this->assertContains($here->id, $ids);
        $this->assertContains($this->user->id, $ids); // all_hotels
        $this->assertNotContains($there->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame(['id', 'name', 'roles'], array_keys($rows[0]));

        $att = $this->getJson('/api/v1/staff?role=room-attendant')->assertOk()->json();
        $this->assertSame([$here->id], array_column($att, 'id'));
        $this->assertSame(['room-attendant'], $att[0]['roles']);

        $this->assertSame([], $this->getJson('/api/v1/staff?q=zzznomatch')->assertOk()->json());
        $this->getJson('/api/v1/users')->assertForbidden();
    }
}
