<?php

namespace App\Modules\Notifications;

use App\Modules\FrontOffice\Domain\Invoice;
use App\Modules\Guests\Domain\Company;
use App\Modules\Notifications\Application\ChannelManager;
use App\Modules\Notifications\Application\Console\RetryNotificationsCommand;
use App\Modules\Notifications\Application\Console\SendPreArrivalCommand;
use App\Modules\Notifications\Application\Notifier;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;
use Illuminate\Console\Application as Artisan;

class NotificationsModule
{
    public static function subscribe(EventBus $bus): void
    {
        app()->singletonIf(ChannelManager::class);
        Artisan::starting(fn (Artisan $a) => $a->resolveCommands([SendPreArrivalCommand::class, RetryNotificationsCommand::class]));

        $reservation = fn (string $template) => function (StoredEvent $e) use ($template) {
            $p = $e->payload;
            app(Notifier::class)->notify($template, ['guest_id' => $p['guest_id'] ?? null], [
                'number' => $p['number'] ?? '', 'booking_ref' => $p['booking_ref'] ?? '', 'arrival' => $p['arrival'] ?? '',
                'departure' => $p['departure'] ?? '', 'total_amount' => number_format((int) ($p['total_amount'] ?? 0), 0, ',', ' '),
                'currency' => $p['currency'] ?? 'XAF', 'reason' => $p['reason'] ?? '',
            ], ['type' => 'reservation', 'id' => $p['id'] ?? null, 'source_event_id' => $e->id]);
        };
        $bus->subscribe('reservation.created', 'notifications.reservation_created', $reservation('reservation.created'));
        $bus->subscribe('reservation.cancelled', 'notifications.reservation_cancelled', $reservation('reservation.cancelled'));

        $bus->subscribe('invoice.issued', 'notifications.invoice_issued', function (StoredEvent $e) {
            $invoice = Invoice::withoutGlobalScopes()->find($e->payload['id']);
            $company = $invoice?->company_id ? Company::withoutGlobalScopes()->find($invoice->company_id) : null;
            $to = $invoice?->guest_id ? ['guest_id' => $invoice->guest_id] : ['email' => $company?->email, 'name' => $company?->name];
            if (empty($to['guest_id']) && empty($to['email'])) {
                return;
            }
            app(Notifier::class)->notify('invoice.issued', $to, [
                'number' => $e->payload['number'], 'currency' => $e->payload['currency'] ?? 'XAF',
                'total' => number_format((int) $e->payload['total'], 0, ',', ' '),
            ], ['type' => 'invoice', 'id' => $e->payload['id'], 'source_event_id' => $e->id]);
        });

        // Emitted by GuestPortal / Identity once they adopt it (see report). Payload carries the code or URL, so
        // emitters may prefer calling Notifier::notify() directly to keep secrets out of the event store.
        $bus->subscribe('guest_portal.otp_requested', 'notifications.portal_otp', function (StoredEvent $e) {
            $p = $e->payload;
            app(Notifier::class)->notify('guest_portal.otp', array_intersect_key($p, array_flip(['guest_id', 'email', 'phone', 'locale'])),
                ['code' => $p['code'] ?? '', 'minutes' => $p['minutes'] ?? 10], ['source_event_id' => $e->id]);
        });
        $bus->subscribe('identity.password_reset_requested', 'notifications.password_reset', function (StoredEvent $e) {
            $p = $e->payload;
            app(Notifier::class)->notify('staff.password_reset', ['email' => $p['email'] ?? null, 'locale' => $p['locale'] ?? null, 'name' => $p['name'] ?? ''],
                ['name' => $p['name'] ?? '', 'url' => $p['url'] ?? ''], ['type' => 'user', 'id' => $p['user_id'] ?? null, 'source_event_id' => $e->id]);
        });
    }
}
