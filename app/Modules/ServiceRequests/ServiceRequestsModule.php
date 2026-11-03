<?php

namespace App\Modules\ServiceRequests;

use App\Modules\ServiceRequests\Application\Console\EscalateCommand;
use App\Modules\ServiceRequests\Domain\Setting;
use App\Modules\ServiceRequests\Domain\VoiceEvent;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Console\Application as Artisan;

/** Staff service requests and the live voice feed. */
class ServiceRequestsModule
{
    public static function subscribe(EventBus $bus): void
    {
        Artisan::starting(fn ($artisan) => $artisan->resolveCommands([EscalateCommand::class]));

        // From the member portal web chat: "Message de la chambre 204" on the front-office voice.
        $bus->subscribe('messaging.guest_message', 'servicerequests.guest_message', function (StoredEvent $e) {
            $p = $e->payload;
            $room = trim((string) ($p['room_number'] ?? ''));
            if (! $e->hotel_id || $room === '' || ! (Setting::first()?->voice_messages ?? true)) {
                return;
            }
            VoiceEvent::create([
                'kind' => 'message', 'room_number' => $room, 'department' => 'front_office',
                'payload' => ['preview' => mb_substr((string) ($p['text'] ?? $p['preview'] ?? ''), 0, 120), 'conversation_id' => $p['conversation_id'] ?? null],
            ]);
        });
    }
}
