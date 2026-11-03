<?php

namespace App\Modules\Accounting\Tax;

use App\Modules\Accounting\Support\Scoped;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Country tax parameters for the six CEMAC states, kept as configuration (code defaults, overridable per hotel in tax_profiles.config).
 *
 * GATE: every figure below is an indicative starting point, not legal advice. Rates, form names, filing days, withholding rates
 * and payroll-form availability change with each finance law: the chartered accountant must validate the profile before the first filing.
 */
class CountryProfiles
{
    use Scoped;

    public const COUNTRIES = ['CM', 'GA', 'CG', 'TD', 'CF', 'GQ'];

    public const DEFAULTS = [
        'CM' => ['name' => 'Cameroun', 'vat_rate' => 19.25, 'vat_form' => 'DGI - Déclaration mensuelle de TVA', 'filing_day' => 15, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retenue à la source - prestations de services', 'rate' => 5.5, 'base' => 'net', 'threshold' => 0], ['code' => 'RAS_SANS_NIU', 'name' => 'Retenue - fournisseur sans NIU', 'rate' => 10.0, 'base' => 'net', 'threshold' => 0]],
            'payroll_forms' => ['cnps' => 'CNPS - Déclaration nominative des salaires (DIPE)', 'irpp' => 'DGI - État des retenues IRPP/CAC', 'cfc_fne_cac' => 'DGI/CFC/FNE - Déclaration mensuelle']],
        'GA' => ['name' => 'Gabon', 'vat_rate' => 18.0, 'vat_form' => 'DGI - Déclaration mensuelle de TVA (CA3)', 'filing_day' => 15, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retenue à la source - prestations', 'rate' => 10.0, 'base' => 'net', 'threshold' => 0]], 'payroll_forms' => []],
        'CG' => ['name' => 'Congo', 'vat_rate' => 18.9, 'vat_form' => 'DGID - Déclaration mensuelle de TVA', 'filing_day' => 20, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retenue à la source - prestations', 'rate' => 7.5, 'base' => 'net', 'threshold' => 0]], 'payroll_forms' => []],
        'TD' => ['name' => 'Tchad', 'vat_rate' => 18.0, 'vat_form' => 'DGI - Déclaration de TVA', 'filing_day' => 15, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retenue à la source - prestations', 'rate' => 5.0, 'base' => 'net', 'threshold' => 0]], 'payroll_forms' => []],
        'CF' => ['name' => 'Centrafrique', 'vat_rate' => 19.0, 'vat_form' => 'DGID - Déclaration de TVA', 'filing_day' => 15, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retenue à la source - prestations', 'rate' => 5.0, 'base' => 'net', 'threshold' => 0]], 'payroll_forms' => []],
        'GQ' => ['name' => 'Guinée équatoriale', 'vat_rate' => 15.0, 'vat_form' => 'DGI - Declaración de IVA', 'filing_day' => 15, 'frequency' => 'monthly',
            'wht' => [['code' => 'RAS_SERV', 'name' => 'Retención en la fuente - servicios', 'rate' => 6.5, 'base' => 'net', 'threshold' => 0]], 'payroll_forms' => []],
    ];

    public function __construct(TenantContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function country(): string
    {
        $row = $this->table('tax_profiles')->first();

        return $row?->country ?? (Hotel::find($this->hotelId())->country ?: 'CM');
    }

    /** Effective profile: defaults overlaid with the hotel's saved overrides. */
    public function profile(): array
    {
        $country = $this->country();
        $base = self::DEFAULTS[$country] ?? throw new DomainException("No tax profile for country $country.", 'unknown-country');
        $row = $this->table('tax_profiles')->first();
        $over = $row ? (json_decode($row->config, true) ?: []) : [];

        return ['country' => $country, 'overridden' => array_keys($over), 'gate' => 'Indicative configuration: to be validated by the chartered accountant before filing.']
            + array_replace($base, $over, ['collected_accounts' => $over['collected_accounts'] ?? ['443100'], 'deductible_accounts' => $over['deductible_accounts'] ?? ['445200']]);
    }

    public function save(string $country, array $config): array
    {
        if (! isset(self::DEFAULTS[$country])) {
            throw new DomainException("Unsupported country $country.", 'unknown-country');
        }
        $allowed = array_intersect_key($config, array_flip(['vat_rate', 'vat_form', 'filing_day', 'frequency', 'collected_accounts', 'deductible_accounts', 'wht', 'payroll_forms']));
        DB::table('tax_profiles')->updateOrInsert(['hotel_id' => $this->hotelId()], ['group_id' => $this->ctx->groupId(), 'country' => $country,
            'config' => json_encode($allowed), 'updated_at' => now(), 'created_at' => now()]);
        app(\App\Modules\Accounting\Application\Core\AuditTrail::class)->record('tax_profile_saved', 'tax_profile', $country, $allowed);

        return $this->profile();
    }

    public function countries(): array
    {
        return collect(self::DEFAULTS)->map(fn ($d, $c) => ['code' => $c, 'name' => $d['name'], 'vat_rate' => $d['vat_rate'], 'vat_form' => $d['vat_form'], 'payroll_forms' => array_keys($d['payroll_forms'])])->values()->all();
    }
}
