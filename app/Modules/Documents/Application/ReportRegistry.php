<?php

namespace App\Modules\Documents\Application;

use App\Platform\Domain\DomainException;

/**
 * Report key => internal endpoint, how to turn its JSON into rows, and columns.
 * Permissions are those of the endpoint, which InternalApi calls as the user.
 */
final class ReportRegistry
{
    /** @return array<string, array{title: string, path: string, rows: callable, columns: ?array}> */
    public static function all(): array
    {
        // Titles follow the request locale (srv.report.<key>), falling back to the bilingual default.
        return collect(self::definitions())->map(function (array $def, string $key) {
            $t = __("srv.report.$key");

            return ['title' => $t === "srv.report.$key" ? $def['title'] : $t] + $def;
        })->all();
    }

    private static function definitions(): array
    {
        $sections = fn (array $keys) => function (array $d) use ($keys) {
            $out = [];
            foreach ($keys as $key => $label) {
                foreach ($d[$key] ?? [] as $r) {
                    $out[] = ['section' => $label] + $r;
                }
            }

            return $out;
        };

        return [
            'trial-balance' => ['title' => 'Balance générale / Trial balance', 'path' => 'accounting/reports/trial-balance', 'rows' => fn ($d) => $d['rows'] ?? [], 'columns' => null],
            'general-ledger' => ['title' => 'Grand livre / General ledger', 'path' => 'accounting/reports/general-ledger', 'rows' => fn ($d) => $d['lines'] ?? [], 'columns' => ['date', 'number', 'journal_code', 'description', 'debit', 'credit', 'balance']],
            'income-statement' => ['title' => 'Compte de résultat / Income statement', 'path' => 'accounting/reports/income-statement', 'rows' => $sections(['revenue' => 'revenue', 'expenses' => 'expenses']), 'columns' => ['section', 'account', 'name', 'amount']],
            'balance-sheet' => ['title' => 'Bilan / Balance sheet', 'path' => 'accounting/reports/balance-sheet', 'rows' => $sections(['assets' => 'assets', 'liabilities_and_equity' => 'liabilities_and_equity']), 'columns' => ['section', 'account', 'name', 'amount']],
            'vat' => ['title' => 'TVA / VAT', 'path' => 'accounting/reports/vat', 'rows' => fn ($d) => [$d], 'columns' => null],
            'ageing' => ['title' => 'Balance âgée / Receivables ageing', 'path' => 'accounting/reports/receivables-ageing', 'rows' => function ($d) {
                $out = [];
                foreach ($d as $rows) {
                    foreach ($rows as $r) {
                        $out[] = $r;
                    }
                }

                return $out;
            }, 'columns' => ['bucket', 'number', 'bill_to_name', 'issue_date', 'due_date', 'total', 'paid', 'outstanding']],
            'managers-report' => ['title' => 'Rapport du directeur / Manager\'s report', 'path' => 'reports/dashboard', 'rows' => fn ($d) => [collect($d['today'] ?? $d)->filter(fn ($v) => is_scalar($v) || $v === null)->all()], 'columns' => null],
            'forecast' => ['title' => 'Prévisions / Forecast', 'path' => 'reports/forecast', 'rows' => fn ($d) => $d, 'columns' => ['date', 'rooms_sold', 'occupancy_percent', 'room_revenue', 'adr']],
            'police-register' => ['title' => 'Registre de police / Police register', 'path' => 'police-register', 'rows' => fn ($d) => $d, 'columns' => ['room_number', 'full_name', 'nationality', 'date_of_birth', 'place_of_birth', 'profession', 'id_type', 'id_number', 'id_expiry', 'coming_from', 'going_to', 'purpose', 'arrival', 'departure', 'checked_out_at']],
            'reservations' => ['title' => 'Réservations / Reservations', 'path' => 'reservations', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($r) => [
                'number' => $r['number'] ?? null, 'guest' => trim(($r['guest']['first_name'] ?? '').' '.($r['guest']['last_name'] ?? '')),
                'room' => $r['room']['number'] ?? null, 'room_type' => $r['room_type']['code'] ?? null, 'arrival' => substr((string) ($r['arrival'] ?? ''), 0, 10),
                'departure' => substr((string) ($r['departure'] ?? ''), 0, 10), 'status' => $r['status'] ?? null, 'channel' => $r['channel'] ?? null, 'total_amount' => $r['total_amount'] ?? null,
            ])->all(), 'columns' => ['number', 'guest', 'room', 'room_type', 'arrival', 'departure', 'status', 'channel', 'total_amount']],
            'stock-balances' => ['title' => 'Stocks / Stock balances', 'path' => 'stock/balances', 'rows' => fn ($d) => $d, 'columns' => ['store', 'code', 'name', 'unit', 'quantity', 'avg_cost', 'value', 'reorder_level']],
            'payroll-run' => ['title' => 'Journal de paie / Payroll run', 'path' => 'payroll/runs/{run}', 'rows' => fn ($d) => collect($d['payslips'] ?? [])->map(fn ($s) => [
                'number' => $s['employee']['number'] ?? null,
                'employee' => trim(($s['employee']['first_name'] ?? '').' '.($s['employee']['last_name'] ?? '')),
                'gross' => $s['gross'], 'taxable' => $s['taxable'], 'employee_social' => $s['employee_social'],
                'taxes_withheld' => $s['taxes_withheld'], 'employer_social' => $s['employer_social'], 'other_employer' => $s['other_employer'], 'net' => $s['net'],
            ])->all(), 'columns' => null],
            // GAP slice (misc): list exports for pages that had none. Each runs the page's own list endpoint as the user.
            'folios' => ['title' => 'Folios / Folios', 'path' => 'folios', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['number', 'kind', 'name', 'status', 'balance', 'created_at']],
            'invoices' => ['title' => 'Factures / Invoices', 'path' => 'invoices', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['number', 'bill_to_name', 'issue_date', 'due_date', 'total', 'tax_total', 'paid', 'status']],
            'cashier-shifts' => ['title' => 'Sessions de caisse / Cashier shifts', 'path' => 'cashier-shifts', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['id', 'user_name', 'station', 'business_date', 'opened_at', 'closed_at', 'opening_float', 'expected', 'counted', 'variance', 'status']],
            'housekeeping-assignments' => ['title' => 'Feuille d\'affectation / Housekeeping assignment sheet', 'path' => 'housekeeping/tasks', 'rows' => fn ($d) => collect($d)->map(fn ($t) => [
                'room' => $t['room']['number'] ?? null, 'floor' => $t['room']['floor'] ?? null, 'kind' => $t['kind'] ?? null, 'priority' => $t['priority'] ?? null,
                'status' => $t['status'] ?? null, 'assigned_to' => $t['assigned_to'] ?? null, 'notes' => $t['notes'] ?? null,
            ])->all(), 'columns' => ['room', 'floor', 'kind', 'priority', 'status', 'assigned_to', 'notes']],
            'work-orders' => ['title' => 'Ordres de travail / Work orders', 'path' => 'maintenance/work-orders', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($w) => [
                'number' => $w['number'] ?? null, 'title' => $w['title'] ?? null, 'category' => $w['category'] ?? null, 'priority' => $w['priority'] ?? null, 'status' => $w['status'] ?? null,
                'room' => $w['room']['number'] ?? null, 'assignee' => $w['assignee']['name'] ?? null, 'due_at' => $w['due_at'] ?? null,
            ])->all(), 'columns' => ['number', 'title', 'category', 'priority', 'status', 'room', 'assignee', 'due_at']],
            'loyalty-members' => ['title' => 'Membres fidélité / Loyalty members', 'path' => 'loyalty/members', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($m) => [
                'number' => $m['number'] ?? null, 'guest' => trim(($m['guest']['first_name'] ?? '').' '.($m['guest']['last_name'] ?? '')), 'email' => $m['guest']['email'] ?? null,
                'tier' => $m['tier']['name'] ?? null, 'points_balance' => $m['points_balance'] ?? null, 'lifetime_points' => $m['lifetime_points'] ?? null, 'status' => $m['status'] ?? null,
            ])->all(), 'columns' => ['number', 'guest', 'email', 'tier', 'points_balance', 'lifetime_points', 'status']],
            'room-blocks' => ['title' => 'Blocs de groupe / Group blocks', 'path' => 'blocks', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($b) => [
                'code' => $b['code'] ?? null, 'name' => $b['name'] ?? null, 'status' => $b['status'] ?? null, 'arrival' => $b['arrival'] ?? null, 'departure' => $b['departure'] ?? null,
                'cutoff_date' => $b['cutoff_date'] ?? null, 'room_nights' => collect($b['allocations'] ?? [])->sum('rooms'), 'picked_up' => collect($b['allocations'] ?? [])->sum('picked_up'),
            ])->all(), 'columns' => ['code', 'name', 'status', 'arrival', 'departure', 'cutoff_date', 'room_nights', 'picked_up']],
            'rooming-list' => ['title' => 'Liste de rooming / Rooming list', 'path' => 'reservations', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($r) => [
                'number' => $r['number'] ?? null, 'guest' => trim(($r['guest']['first_name'] ?? '').' '.($r['guest']['last_name'] ?? '')), 'arrival' => $r['arrival'] ?? null,
                'departure' => $r['departure'] ?? null, 'room_type' => $r['room_type']['code'] ?? null, 'room' => $r['room']['number'] ?? null, 'status' => $r['status'] ?? null,
            ])->all(), 'columns' => ['number', 'guest', 'arrival', 'departure', 'room_type', 'room', 'status']],
            'staff-users' => ['title' => 'Utilisateurs / Users', 'path' => 'users', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($u) => [
                'name' => $u['name'] ?? null, 'email' => $u['email'] ?? null, 'phone' => $u['phone'] ?? null, 'status' => $u['status'] ?? null,
                'roles' => collect($u['roles'] ?? [])->pluck('name')->implode(', '), 'hotels' => ($u['all_hotels'] ?? false) ? 'ALL' : collect($u['hotels'] ?? [])->pluck('code')->implode(', '),
            ])->all(), 'columns' => ['name', 'email', 'phone', 'status', 'roles', 'hotels']],
            'audit-log' => ['title' => 'Journal d\'audit / Audit log', 'path' => 'admin/audit', 'rows' => fn ($d) => collect($d['data'] ?? [])->map(fn ($a) => [
                'created_at' => $a['created_at'] ?? null, 'user' => $a['causer']['name'] ?? null, 'event' => $a['event'] ?? null,
                'record' => class_basename((string) ($a['subject_type'] ?? '')).(($a['subject_id'] ?? null) ? ' #'.$a['subject_id'] : ''), 'description' => $a['description'] ?? null,
            ])->all(), 'columns' => ['created_at', 'user', 'event', 'record', 'description']],
            // GAP-K: lists that only had browser-built CSV. Each runs the page's own endpoint as the user (same permission).
            'departmental' => ['title' => 'Rapport par département / Departmental report', 'path' => 'accounting/reports/departmental', 'rows' => fn ($d) => collect($d)->map(fn ($r) => [
                'department' => $r['department'] ?? null, 'revenue' => $r['revenue'] ?? 0, 'cost' => $r['cost'] ?? 0, 'margin' => (float) ($r['revenue'] ?? 0) - (float) ($r['cost'] ?? 0),
            ])->all(), 'columns' => ['department', 'revenue', 'cost', 'margin']],
            'consolidated' => ['title' => 'Rapport consolidé / Consolidated report', 'path' => 'accounting/reports/consolidated', 'rows' => function ($d) {
                $out = collect($d['hotels'] ?? [])->map(fn ($h) => [
                    'hotel' => trim(($h['hotel']['code'] ?? '').' '.($h['hotel']['name'] ?? '')), 'revenue' => $h['revenue'] ?? 0, 'expenses' => $h['expenses'] ?? 0, 'result' => $h['result'] ?? 0,
                ])->all();
                if (isset($d['total'])) {
                    $out[] = ['hotel' => 'TOTAL', 'revenue' => $d['total']['revenue'] ?? 0, 'expenses' => $d['total']['expenses'] ?? 0, 'result' => $d['total']['result'] ?? 0];
                }

                return $out;
            }, 'columns' => ['hotel', 'revenue', 'expenses', 'result']],
            'chart-of-accounts' => ['title' => 'Plan comptable / Chart of accounts', 'path' => 'accounting/accounts', 'rows' => fn ($d, $p = []) => collect($d)->filter(fn ($a) => (empty($p['class']) || (string) ($a['class'] ?? '') === (string) $p['class'])
                && (empty($p['q']) || str_starts_with((string) ($a['code'] ?? ''), (string) $p['q']) || str_contains(mb_strtolower((string) ($a['name'] ?? '')), mb_strtolower((string) $p['q']))))->map(fn ($a) => [
                'code' => $a['code'] ?? null, 'name' => $a['name'] ?? null, 'class' => $a['class'] ?? null, 'type' => $a['type'] ?? null,
                'postable' => ! empty($a['postable']) ? 1 : 0, 'reconcilable' => ! empty($a['reconcilable']) ? 1 : 0, 'active' => ! empty($a['active']) ? 1 : 0,
            ])->all(), 'columns' => ['code', 'name', 'class', 'type', 'postable', 'reconcilable', 'active']],
            'stock-items' => ['title' => 'Articles de stock / Stock items', 'path' => 'stock/items', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['code', 'name', 'category', 'unit', 'purchase_unit', 'purchase_factor', 'inventory_account', 'cost_account', 'vat_rate', 'reorder_level', 'reorder_quantity', 'avg_cost', 'active']],
            'stock-stores' => ['title' => 'Magasins / Stores', 'path' => 'stock/stores', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['code', 'name', 'kind', 'department', 'active']],
            'stock-suppliers' => ['title' => 'Fournisseurs / Suppliers', 'path' => 'suppliers', 'rows' => fn ($d) => $d['data'] ?? [], 'columns' => ['code', 'name', 'tax_id', 'contact_name', 'email', 'phone', 'payment_terms_days', 'active']],
            'wastage' => ['title' => 'Rapport de pertes / Wastage report', 'path' => 'stock/reports/wastage', 'rows' => fn ($d) => $d['rows'] ?? [], 'columns' => ['reason', 'code', 'name', 'unit', 'quantity', 'value']],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new DomainException("Unknown report '$key'.", 'unknown_report');
    }

    /** Fill {placeholders} in the path from params; the rest stay as the query string. */
    public static function resolve(string $key, array $params): array
    {
        $def = self::get($key);
        $path = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $v = $params[$m[1]] ?? throw new DomainException("Missing parameter '{$m[1]}'.", 'missing_parameter');
            unset($params[$m[1]]);

            return rawurlencode((string) $v);
        }, $def['path']);

        return [$def, $path, $params];
    }
}
