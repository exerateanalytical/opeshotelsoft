export interface AlertRow {
    id: number; hotel_id: number; rule: string; severity: 'low' | 'medium' | 'high'; status?: string; title: string; amount: number;
    occurred_at: string; detail?: string | null; evidence?: { label: string; type: string; id: number | string; url?: string | null }[];
    acknowledge_note?: string | null; resolution_note?: string | null;
}
export interface Kpis {
    hotel_id: number; date: string; revenue: number; room_revenue: number; pos_revenue: number; rooms: number; occupied: number;
    occupancy: number; adr: number; revpar: number; cash_by_method: { method: string; amount: number }[]; open_alerts: number; top_alerts: AlertRow[];
}

export const fcfa = (v: number) => `${new Intl.NumberFormat('fr-FR').format(v)} FCFA`;

export const severityClass: Record<string, string> = {
    high: 'bg-red-50 text-red-700 border-red-200',
    medium: 'bg-amber-50 text-amber-700 border-amber-200',
    low: 'bg-slate-50 text-slate-600 border-slate-200',
};
