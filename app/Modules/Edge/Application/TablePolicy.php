<?php

namespace App\Modules\Edge\Application;

/**
 * Conflict rule per table (docs/EDGE.md):
 *  - financial:   append-only. New rows are inserted; an existing row only gains values in
 *                 empty columns; a non-null value is never overwritten. Never deleted.
 *  - master:      edited in the cloud only. Cloud always wins; edge edits are rejected.
 *  - operational: last-writer-wins on the change timestamp (room HK status, reservations...).
 */
class TablePolicy
{
    public const FINANCIAL = [
        'journal_entries', 'journal_lines', 'folio_lines', 'invoices', 'pos_payments', 'payment_intents',
        'payment_callbacks', 'payment_refunds', 'loyalty_transactions', 'stock_movements', 'supplier_invoices',
        'supplier_payments', 'goods_receipts', 'goods_receipt_lines', 'guest_register_entries', 'payslips',
        'depreciation_runs', 'fx_revaluations', 'opening_balances', 'salary_advances',
    ];

    public const MASTER = [
        'groups', 'users', 'accounts', 'journals', 'fiscal_periods', 'fiscal_years', 'currencies', 'exchange_rates',
        'tax_codes', 'charge_codes', 'payment_methods', 'payment_gateways', 'rate_plans', 'rates', 'rate_plan_seasons',
        'room_types', 'room_type_inventory', 'outlets', 'pos_menu_items', 'pos_item_components', 'pos_price_lists',
        'pos_price_list_items', 'stock_items', 'stores', 'suppliers', 'supplier_prices', 'loyalty_programs', 'loyalty_tiers',
        'asset_classes', 'checkout_fee_rules', 'folio_routing_rules', 'notification_templates', 'departments', 'positions',
        'leave_types', 'public_holidays', 'payroll_rule_packs', 'channel_connections', 'channel_room_mappings',
        'channel_rate_mappings', 'webhook_subscriptions',
    ];

    /** Child tables without their own tenant columns: [parent table, foreign key]. */
    public const PARENTS = [
        'pos_check_lines' => ['pos_checks', 'pos_check_id'],
        'pos_item_components' => ['pos_menu_items', 'pos_menu_item_id'],
        'pos_price_list_items' => ['pos_price_lists', 'pos_price_list_id'],
        'purchase_order_lines' => ['purchase_orders', 'purchase_order_id'],
        'goods_receipt_lines' => ['goods_receipts', 'goods_receipt_id'],
        'stock_count_lines' => ['stock_counts', 'stock_count_id'],
        'purchase_requisition_lines' => ['purchase_requisitions', 'purchase_requisition_id'],
        'reservation_nights' => ['reservations', 'reservation_id'],
        'room_block_allocations' => ['room_blocks', 'room_block_id'],
        'loyalty_transactions' => ['loyalty_memberships', 'loyalty_membership_id'],
        'loyalty_cards' => ['loyalty_memberships', 'loyalty_membership_id'],
    ];

    /** Never replicated: platform plumbing and the edge's own bookkeeping. */
    public const EXCLUDED = [
        'edge_changes', 'edge_nodes', 'edge_batches', 'edge_conflicts', 'edge_state', 'domain_events',
        'event_consumptions', 'idempotency_keys', 'number_sequences', 'webhook_deliveries', 'api_usage',
        'notification_outbox',
    ];

    public static function rule(string $table): string
    {
        return match (true) {
            in_array($table, self::FINANCIAL, true) => 'financial',
            in_array($table, self::MASTER, true) => 'master',
            default => 'operational',
        };
    }
}
