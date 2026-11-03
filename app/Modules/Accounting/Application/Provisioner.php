<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\ChartOfAccounts as C;
use App\Modules\Accounting\Domain\Journal;
use App\Modules\FrontOffice\Domain\ChargeCode;
use App\Modules\FrontOffice\Domain\PaymentMethod;
use App\Modules\FrontOffice\Domain\TaxCode;
use App\Modules\Tenancy\Domain\Hotel;

/**
 * Seeds a new hotel's chart of accounts, journals, taxes, charge codes and
 * payment methods. Country tax defaults are configuration, not law: they
 * must be validated by the hotel's accountant (see docs/BLOCKERS.md).
 */
class Provisioner
{
    /** Country tax defaults (percent VAT incl. surcharges; tourist tax per night). */
    public const COUNTRY_TAX = [
        'CM' => ['vat' => 19.25, 'vat_name' => 'TVA 19,25% (17,5% + CAC)', 'tourist_tax' => 1000],
        'GA' => ['vat' => 18.0, 'vat_name' => 'TVA 18%', 'tourist_tax' => 0],
        'CG' => ['vat' => 18.9, 'vat_name' => 'TVA 18,9% (18% + CAC)', 'tourist_tax' => 0],
        'TD' => ['vat' => 18.0, 'vat_name' => 'TVA 18%', 'tourist_tax' => 0],
        'CF' => ['vat' => 19.0, 'vat_name' => 'TVA 19%', 'tourist_tax' => 0],
        'GQ' => ['vat' => 15.0, 'vat_name' => 'IVA 15%', 'tourist_tax' => 0],
    ];

    public function provision(Hotel $hotel): void
    {
        foreach (C::ACCOUNTS as $code => $a) {
            Account::firstOrCreate(['code' => (string) $code], [
                'name' => $a[0], 'class' => $a[1], 'type' => $a[2], 'reconcilable' => $a[3] ?? false,
            ]);
        }
        foreach (C::JOURNALS as $code => $j) {
            Journal::firstOrCreate(['code' => $code], ['name' => $j[0], 'kind' => $j[1], 'default_account' => $j[2] ?? null]);
        }

        $tax = self::COUNTRY_TAX[$hotel->country] ?? self::COUNTRY_TAX['CM'];
        TaxCode::firstOrCreate(['code' => 'VAT'], ['name' => $tax['vat_name'], 'kind' => 'percent', 'rate' => $tax['vat'], 'included_in_price' => true, 'account' => C::role('vat_collected')]);
        if ($tax['tourist_tax'] > 0) {
            TaxCode::firstOrCreate(['code' => 'TSJ'], ['name' => 'Taxe de séjour', 'kind' => 'fixed_per_night', 'fixed_amount' => $tax['tourist_tax'], 'included_in_price' => false, 'account' => C::role('tourist_tax')]);
        }
        $roomTaxes = $tax['tourist_tax'] > 0 ? ['VAT', 'TSJ'] : ['VAT'];

        $charges = [
            'ROOM' => ['Hébergement', 'rooms', '706100', $roomTaxes],
            'FNB' => ['Restauration', 'fnb', '706200', ['VAT']],
            'BAR' => ['Bar et boissons', 'beverage', '706300', ['VAT']],
            'MINI' => ['Minibar', 'beverage', '706300', ['VAT']],
            'LNDY' => ['Blanchisserie', 'laundry', '706400', ['VAT']],
            'SPA' => ['Spa et loisirs', 'spa', '706500', ['VAT']],
            'EVT' => ['Séminaires et événements', 'events', '706600', ['VAT']],
            'MISC' => ['Divers', 'misc', '706800', ['VAT']],
            'CXL' => ['Frais d\'annulation / no-show', 'rooms', '706900', ['VAT']],
        ];
        foreach ($charges as $code => [$name, $dept, $account, $taxes]) {
            ChargeCode::firstOrCreate(['code' => $code], ['name' => $name, 'department' => $dept, 'revenue_account' => $account, 'tax_codes' => $taxes]);
        }

        $methods = [
            'CASH' => ['Espèces', 'cash', C::role('cash'), null],
            'CARD' => ['Carte bancaire', 'card', C::role('card_clearing'), null],
            'MTN' => ['MTN Mobile Money', 'mobile_money', C::role('mobile_money'), 'mtn_momo'],
            'OM' => ['Orange Money', 'mobile_money', C::role('mobile_money'), 'orange_money'],
            'XFER' => ['Virement bancaire', 'bank_transfer', C::role('bank'), null],
            'CL' => ['Compte débiteur (city ledger)', 'city_ledger', C::role('city_ledger'), null],
            'PTS' => ['Points fidélité', 'loyalty_points', C::role('loyalty_liability'), null],
        ];
        foreach ($methods as $code => [$name, $kind, $account, $provider]) {
            PaymentMethod::firstOrCreate(['code' => $code], ['name' => $name, 'kind' => $kind, 'account' => $account, 'provider' => $provider]);
        }
    }
}
