import { Loader2, Users } from 'lucide-react';
import { useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';

export interface FloorTable {
    id: number; name: string; seats: number; pos_x: number; pos_y: number;
    checks: { id: number; number: string; covers: number; total: number }[];
}
interface Floor { tables: FloorTable[]; unseated: { id: number; number: string; table_number: string | null; total: number }[] }

/** Visual floor grid: tap a table to open or resume its check. Busy tables show their open check. */
export default function FloorPlan({ outletId, refresh, selectedId, onTable, onCheck }: {
    outletId: number; refresh: number; selectedId: number | null; onTable: (t: FloorTable) => void; onCheck: (id: number) => void;
}) {
    const t = useT();
    const floor = useApi<Floor>(`pos/outlets/${outletId}/floor`, [refresh]);
    const tables = floor.data?.tables ?? [];
    const cols = Math.max(4, ...tables.map((tb) => tb.pos_x + 1));
    const rows = Math.max(1, ...tables.map((tb) => tb.pos_y + 1));

    if (floor.loading && !floor.data) return <Loader2 className="w-5 h-5 animate-spin text-slate-400" />;
    if (floor.error) return <p className="text-sm text-red-600">{floor.error.message}</p>;
    if (!tables.length) return <p className="text-sm text-slate-500">{t('pos.floor.no_tables')}</p>;

    return (
        <div>
            <div className="overflow-x-auto">
                <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${cols}, minmax(64px, 1fr))`, gridTemplateRows: `repeat(${rows}, minmax(64px, auto))` }}>
                    {tables.map((tb) => {
                        const ck = tb.checks[0];
                        const active = ck && ck.id === selectedId;
                        return (
                            <button
                                key={tb.id}
                                onClick={() => onTable(tb)}
                                style={{ gridColumnStart: tb.pos_x + 1, gridRowStart: tb.pos_y + 1 }}
                                className={`rounded-lg border p-2 text-left min-h-[64px] ${active ? 'border-emerald-600 bg-emerald-100' : ck ? 'border-amber-400 bg-amber-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
                            >
                                <div className="font-semibold text-sm text-slate-800">{tb.name}</div>
                                {ck ? (
                                    <div className="text-xs text-amber-800">{formatCurrency(ck.total)}<br />{t('pos.common.covers_short', { count: ck.covers })}{tb.checks.length > 1 ? ` · +${tb.checks.length - 1}` : ''}</div>
                                ) : (
                                    <div className="text-xs text-slate-500 flex items-center gap-1"><Users className="w-3 h-3" />{tb.seats}</div>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>
            {floor.data && floor.data.unseated.length > 0 && (
                <div className="mt-3">
                    <p className="text-xs text-slate-500 mb-1">{t('pos.floor.unseated')}</p>
                    <div className="flex flex-wrap gap-2">
                        {floor.data.unseated.map((c) => (
                            <button key={c.id} onClick={() => onCheck(c.id)} className="btn-secondary text-sm">{c.table_number ?? c.number} · {formatCurrency(c.total)}</button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
