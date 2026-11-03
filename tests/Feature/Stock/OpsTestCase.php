<?php

namespace Tests\Feature\Stock;

use App\Models\User;
use App\Platform\Events\StoredEvent;

/** Fixtures for the I-B inventory operations tests. */
abstract class OpsTestCase extends ControlTestCase
{
    protected function as(string $role): User
    {
        $u = $this->makeUser($this->group, $role, allHotels: true);
        $this->actAs($u, $this->hotel);

        return $u;
    }

    protected function events(string $type)
    {
        return StoredEvent::where('type', $type)->get();
    }
}
