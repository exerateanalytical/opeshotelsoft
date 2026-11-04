import Alert from '@/Components/ui/Alert';
export interface Outlet { id: number; code: string; name: string; kind: string; active: boolean }
export interface MenuItem {
    id: number; code: string; name: string; name_en: string | null; outlet_id: number | null; category: string | null;
    price: number; charge_code: string; kitchen_station: string | null; active: boolean;
}
export interface CheckLine {
    id: number; pos_menu_item_id: number; name: string; quantity: number; unit_price: number; amount: number;
    notes: string | null; fired_at: string | null; voided: boolean; void_reason: string | null;
    modifiers?: { name: string; price?: number }[]; list_price?: number | null; comp_status?: 'pending' | 'approved' | null; comp_reason?: string | null;
}
export interface Payment { id: number; method_code: string; amount: number; tendered: number; change_given: number; reference: string | null }
export interface Check {
    id: number; outlet_id: number; number: string; table_number: string | null; covers: number; status: string; business_date: string;
    reservation_id: number | null; subtotal: number; comp_total?: number; pos_table_id?: number | null; discount: number; discount_reason: string | null; total: number;
    settlement: string | null; created_at: string; lines: CheckLine[]; payments?: Payment[];
}
export interface Paginated<T> { data: T[]; total: number; current_page: number; last_page: number }
export interface Code { code: string; name: string; kind?: string; active?: boolean }

export function errText(e: unknown): string {
    if (e && typeof e === 'object' && 'problem' in e) {
        const p = (e as { problem: { errors?: Record<string, string[]>; detail?: string; title: string } }).problem;
        if (p.errors) return Object.values(p.errors).flat().join(' ');
        return p.detail ?? p.title;
    }
    return e instanceof Error ? e.message : String(e);
}
export type Msg = { ok: boolean; text: string } | null;

export function Banner({ msg }: { msg: Msg }) {
    if (!msg) return null;
    return (
        <Alert variant={msg.ok ? 'success' : 'danger'} className="mb-4">
            {msg.text}
        </Alert>
    );
}
