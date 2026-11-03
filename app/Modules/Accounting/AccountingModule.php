<?php

namespace App\Modules\Accounting;

use App\Modules\Accounting\Application\PostingRules;
use App\Modules\Accounting\Application\Provisioner;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Events\EventBus;
use App\Platform\Events\StoredEvent;

class AccountingModule
{
    public static function subscribe(EventBus $bus): void
    {
        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolveCommands([\App\Modules\Accounting\Application\Core\RunScheduledCommand::class]));
        $rules = fn () => app(PostingRules::class);

        $bus->subscribe('tenancy.hotel.created', 'accounting.provision', fn (StoredEvent $e) => app(Provisioner::class)->provision(Hotel::findOrFail($e->aggregate_id)));
        $bus->subscribe('folio.line_posted', 'accounting.folio', fn ($e) => $rules()->folioLine($e));
        $bus->subscribe('pos.check_settled', 'accounting.pos', fn ($e) => $rules()->posSettled($e));
        $bus->subscribe('stock.movement_posted', 'accounting.stock', fn ($e) => $rules()->stockMovement($e));
        $bus->subscribe('payables.invoice_approved', 'accounting.ap_invoice', fn ($e) => $rules()->supplierInvoice($e));
        $bus->subscribe('payables.payment_made', 'accounting.ap_payment', fn ($e) => $rules()->supplierPayment($e));
        $bus->subscribe('payroll.run_approved', 'accounting.payroll', fn ($e) => $rules()->payroll($e));
        $bus->subscribe('payroll.run_paid', 'accounting.payroll_paid', fn ($e) => $rules()->payrollPaid($e));
        $bus->subscribe('loyalty.points_earned', 'accounting.loyalty_earn', fn ($e) => $rules()->loyalty($e));
    }
}
