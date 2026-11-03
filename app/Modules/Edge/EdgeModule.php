<?php

namespace App\Modules\Edge;

use App\Modules\Edge\Application\ChangeCapture;
use App\Platform\Events\EventBus;
use Illuminate\Support\Facades\Event;

/**
 * Hotel edge node (SPEC 7.5a, docs/EDGE.md). Registers change-log capture
 * on every tenant-scoped Eloquent model through the global model events,
 * so no model or shared trait needs editing.
 */
class EdgeModule
{
    public static function subscribe(EventBus $bus): void
    {
        if (! config()->has('edge.mode')) {
            config()->set('edge', require __DIR__.'/config.php');
        }
        app()->singletonIf(ChangeCapture::class);

        Event::listen('eloquent.saved: *', fn (string $event, array $data) => app(ChangeCapture::class)->saved($data[0]));
        Event::listen('eloquent.deleted: *', fn (string $event, array $data) => app(ChangeCapture::class)->deleted($data[0]));
    }
}
