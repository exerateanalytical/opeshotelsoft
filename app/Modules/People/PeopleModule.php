<?php

namespace App\Modules\People;

use App\Modules\People\Application\PeopleService;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Support\Facades\View;

class PeopleModule
{
    /**
     * Guest reviews naming staff become feedback on each named employee.
     * Expected payload: {id, rating (1-5), comment, guest_id, staff_employee_ids: int[], date?}.
     */
    public const REVIEW_EVENTS = ['guests.review_received', 'messaging.review_received'];

    public static function subscribe(EventBus $bus): void
    {
        View::addNamespace('people', __DIR__.'/Resources/views');

        foreach (self::REVIEW_EVENTS as $type) {
            $bus->subscribe($type, 'people.feedback_from_review', function (StoredEvent $e) {
                if (! empty($e->payload['staff_employee_ids'])) {
                    app(PeopleService::class)->feedbackFromReview($e->payload);
                }
            });
        }
    }
}
