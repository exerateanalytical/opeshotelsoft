<?php

namespace App\Modules\Accounting\Domain;

/**
 * SYSCOHADA révisé — hotel subset of the chart of accounts, journals and
 * the account roles used by automatic posting rules. Codes are editable
 * per hotel; posting rules look accounts up by role, not by hard-coded code.
 *
 * Must be reviewed by a chartered accountant before production (BLOCKERS.md).
 */
final class ChartOfAccounts
{
    /** role => account code */
    public const ROLES = [
        'guest_ledger' => '411100',        // Clients - hébergés (in-house guests)
        'city_ledger' => '411200',         // Clients - comptes débiteurs
        'customer_deposits' => '419100',   // Clients - avances et acomptes reçus
        'supplier' => '401100',            // Fournisseurs
        'supplier_accrual' => '408100',    // Fournisseurs - factures non parvenues
        'vat_collected' => '443100',       // TVA facturée
        'vat_deductible' => '445200',      // TVA récupérable sur achats
        'tourist_tax' => '447300',         // Taxe de séjour à reverser
        'income_tax_withheld' => '447200', // IRPP / impôts retenus à la source
        'wages_payable' => '422100',       // Personnel - rémunérations dues
        'staff_advances' => '421100',      // Personnel - avances et acomptes
        'social_security' => '431100',     // CNPS
        'cash' => '571100',
        'bank' => '521100',
        'mobile_money' => '521500',
        'card_clearing' => '585100',       // Virements de fonds / cartes en attente
        'inventory_food' => '311100',
        'inventory_beverage' => '311200',
        'inventory_supplies' => '322100',
        'cost_food' => '603110',
        'cost_beverage' => '603120',
        'cost_supplies' => '603200',
        'stock_variance' => '603900',
        'salaries' => '661100',
        'employer_charges' => '664100',
        'rooms_revenue' => '706100',
        'fnb_revenue' => '706200',
        'beverage_revenue' => '706300',
        'other_revenue' => '706800',
        'cancellation_revenue' => '706900',
        'loyalty_liability' => '419800',
        'loyalty_expense' => '623800',
        'rounding' => '658900',
        'comp_expense' => '658100', // POS comps (offerts); VAT treatment to be confirmed by the accountant
        'fx_gain' => '776000',
        'fx_loss' => '676000',
        'retained_earnings' => '121000',
        'suspense' => '471000',
        'result_profit' => '131000',
        'result_loss' => '139000',
        'bank_charges' => '631100',
        'other_income' => '758000',
        'other_expense' => '658000',
        'depreciation_expense' => '681000',
        'asset_supplier' => '481000',
        'disposal_receivable' => '485000',
        'disposal_book_value' => '812000',
        'disposal_proceeds' => '822000',
    ];

    /** code => [name, class, type, reconcilable] */
    public const ACCOUNTS = [
        '101000' => ['Capital social', 1, 'equity'],
        '121000' => ['Report à nouveau', 1, 'equity'],
        '131000' => ['Résultat net de l\'exercice', 1, 'equity'],
        '139000' => ['Résultat net : perte', 1, 'equity'],
        '162000' => ['Emprunts auprès des établissements de crédit', 1, 'liability'],
        '231000' => ['Bâtiments', 2, 'asset'],
        '241000' => ['Matériel et outillage', 2, 'asset'],
        '244000' => ['Matériel et mobilier de bureau et d\'hébergement', 2, 'asset'],
        '245000' => ['Matériel de transport', 2, 'asset'],
        '283100' => ['Amortissements des bâtiments', 2, 'asset'],
        '284400' => ['Amortissements du mobilier', 2, 'asset'],
        '284100' => ['Amortissements du matériel et outillage', 2, 'asset'],
        '284500' => ['Amortissements du matériel de transport', 2, 'asset'],
        '311100' => ['Stock - denrées alimentaires', 3, 'asset'],
        '311200' => ['Stock - boissons', 3, 'asset'],
        '322100' => ['Stock - fournitures hôtelières et produits d\'accueil', 3, 'asset'],
        '401100' => ['Fournisseurs', 4, 'liability', true],
        '408100' => ['Fournisseurs - factures non parvenues', 4, 'liability', true],
        '411100' => ['Clients - hébergés', 4, 'asset', true],
        '411200' => ['Clients - comptes débiteurs (city ledger)', 4, 'asset', true],
        '419100' => ['Clients - avances et acomptes reçus', 4, 'liability', true],
        '419800' => ['Engagements fidélité (points)', 4, 'liability'],
        '421100' => ['Personnel - avances et acomptes', 4, 'asset', true],
        '422100' => ['Personnel - rémunérations dues', 4, 'liability', true],
        '431100' => ['CNPS', 4, 'liability'],
        '443100' => ['TVA facturée', 4, 'liability'],
        '445200' => ['TVA récupérable sur achats', 4, 'asset'],
        '447200' => ['État - impôts retenus à la source (IRPP, CAC, CFC, RAV, TDL)', 4, 'liability'],
        '447300' => ['Taxe de séjour à reverser', 4, 'liability'],
        '471000' => ['Compte d\'attente', 4, 'asset'],
        '481000' => ["Fournisseurs d'investissements", 4, 'liability', true],
        '485000' => ["Créances sur cessions d'immobilisations", 4, 'asset', true],
        '521100' => ['Banque', 5, 'asset', true],
        '521500' => ['Mobile Money', 5, 'asset', true],
        '571100' => ['Caisse', 5, 'asset', true],
        '585100' => ['Virements de fonds - cartes bancaires', 5, 'asset', true],
        '601100' => ['Achats de marchandises', 6, 'expense'],
        '603110' => ['Variation de stocks - denrées (coût des ventes)', 6, 'expense'],
        '603120' => ['Variation de stocks - boissons (coût des ventes)', 6, 'expense'],
        '603200' => ['Variation de stocks - fournitures', 6, 'expense'],
        '603900' => ['Écarts d\'inventaire', 6, 'expense'],
        '605100' => ['Eau', 6, 'expense'],
        '605200' => ['Électricité', 6, 'expense'],
        '622000' => ['Locations et charges locatives', 6, 'expense'],
        '623800' => ['Coût programme de fidélité', 6, 'expense'],
        '624000' => ['Entretien, réparations et maintenance', 6, 'expense'],
        '631100' => ['Frais bancaires', 6, 'expense'],
        '632000' => ['Commissions d\'agences et OTA', 6, 'expense'],
        '658000' => ['Charges diverses', 6, 'expense'],
        '658100' => ['Charges diverses - offerts', 6, 'expense'],
        '658900' => ['Charges diverses - arrondis', 6, 'expense'],
        '661100' => ['Rémunérations du personnel', 6, 'expense'],
        '664100' => ['Charges sociales patronales', 6, 'expense'],
        '676000' => ['Pertes de change', 6, 'expense'],
        '681000' => ['Dotations aux amortissements', 6, 'expense'],
        '706100' => ['Produits - hébergement', 7, 'income'],
        '706200' => ['Produits - restauration', 7, 'income'],
        '706300' => ['Produits - bar et boissons', 7, 'income'],
        '706400' => ['Produits - blanchisserie', 7, 'income'],
        '706500' => ['Produits - spa et loisirs', 7, 'income'],
        '706600' => ['Produits - séminaires et événements', 7, 'income'],
        '706800' => ['Produits - autres services', 7, 'income'],
        '706900' => ['Produits - frais d\'annulation et no-show', 7, 'income'],
        '758000' => ['Produits divers', 7, 'income'],
        '776000' => ['Gains de change', 7, 'income'],
        '812000' => ["Valeurs comptables des cessions d'immobilisations", 8, 'expense'],
        '822000' => ["Produits des cessions d'immobilisations", 8, 'income'],
    ];

    public const JOURNALS = [
        'VT' => ['Ventes (main courante)', 'sales'],
        'AC' => ['Achats', 'purchases'],
        'CA' => ['Caisse', 'cash', '571100'],
        'BQ' => ['Banque', 'bank', '521100'],
        'MM' => ['Mobile Money', 'mobile_money', '521500'],
        'PA' => ['Paie', 'payroll'],
        'ST' => ['Stocks', 'inventory'],
        'OD' => ['Opérations diverses', 'general'],
        'AN' => ['À nouveaux', 'general'],
    ];

    public static function role(string $role): string
    {
        return self::ROLES[$role] ?? throw new \InvalidArgumentException("Unknown account role $role");
    }
}
