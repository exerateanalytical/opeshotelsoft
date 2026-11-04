import AppLayout from '@/Layouts/AppLayout';
import { Head, Link } from '@inertiajs/react';
import { Award, Loader2, Save, Users } from 'lucide-react';
import ErrorState from '@/Components/ui/ErrorState';
import LoadingState from '@/Components/ui/LoadingState';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import { useEffect, useState } from 'react';
import { api, ApiError, useApi } from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Tier {
    id: number;
    code: string;
    name: string;
    min_qualifying_points: number;
    min_qualifying_nights: number;
    earn_multiplier: number | string;
    benefits: string[];
    rank: number;
    active?: boolean;
}

interface Program {
    id: number;
    name: string;
    spend_per_point: number;
    point_value: number;
    expiry_months: number;
    enrolment_bonus: number;
    eligible_departments: string[];
    active: boolean;
    tiers: Tier[];
}

const DEPARTMENTS = ['rooms', 'fnb', 'beverage', 'spa', 'laundry', 'events'];

export default function LoyaltyIndex() {
    const t = useT();
    const program = useApi<Program | null>('loyalty/program');
    const [form, setForm] = useState({ name: '', spend_per_point: 1000, point_value: 10, expiry_months: 24, enrolment_bonus: 0, eligible_departments: DEPARTMENTS });
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);

    useEffect(() => {
        const p = program.data;
        if (p) setForm({ name: p.name, spend_per_point: p.spend_per_point, point_value: p.point_value, expiry_months: p.expiry_months, enrolment_bonus: p.enrolment_bonus, eligible_departments: p.eligible_departments ?? [] });
    }, [program.data]);

    async function save(e: React.FormEvent) {
        e.preventDefault();
        setSaving(true);
        setError(null);
        setSuccess(null);
        try {
            await api('loyalty/program', { method: 'PUT', body: JSON.stringify(form) });
            setSuccess(program.data ? t('loyalty.saved') : t('loyalty.created'));
            program.reload();
        } catch (err) {
            setError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setSaving(false);
        }
    }

    const toggleDept = (d: string) =>
        setForm((f) => ({ ...f, eligible_departments: f.eligible_departments.includes(d) ? f.eligible_departments.filter((x) => x !== d) : [...f.eligible_departments, d] }));

    const p = program.data;
    const tiers = [...(p?.tiers ?? [])].sort((a, b) => a.rank - b.rank);

    return (
        <AppLayout
            title={t('loyalty.title')}
            subtitle={t('loyalty.subtitle')}
            actions={
                <Link href="/loyalty/members" className="btn-primary inline-flex items-center gap-2">
                    <Users className="w-4 h-4" /> {t('loyalty.members')}
                </Link>
            }
        >
            <Head title={t('loyalty.title')} />
            {error && <Alert variant="danger" className="mb-4">{error}</Alert>}
            {success && <Alert variant="success" className="mb-4">{success}</Alert>}
            {program.error && !program.loading ? (
                <div className="card"><ErrorState error={program.error} onRetry={program.reload} /></div>
            ) : program.loading && !p ? (
                <LoadingState variant="cards" rows={3} />
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <form onSubmit={save} className="card p-5 space-y-4 lg:col-span-1">
                        <h2 className="font-semibold text-slate-800">{t('loyalty.settings')}</h2>
                        {!p && <p className="text-sm text-amber-700 bg-amber-50 rounded px-3 py-2">{t('loyalty.no_program')}</p>}
                        <label className="block text-sm">{t('loyalty.name')}
                            <input className="input w-full mt-1" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={100} />
                        </label>
                        <label className="block text-sm">{t('loyalty.spend_per_point')}
                            <input type="number" min={1} className="input w-full mt-1" value={form.spend_per_point} onChange={(e) => setForm({ ...form, spend_per_point: +e.target.value })} />
                        </label>
                        <label className="block text-sm">{t('loyalty.point_value')}
                            <input type="number" min={1} className="input w-full mt-1" value={form.point_value} onChange={(e) => setForm({ ...form, point_value: +e.target.value })} />
                        </label>
                        <label className="block text-sm">{t('loyalty.expiry')}
                            <input type="number" min={1} max={120} className="input w-full mt-1" value={form.expiry_months} onChange={(e) => setForm({ ...form, expiry_months: +e.target.value })} />
                        </label>
                        <label className="block text-sm">{t('loyalty.bonus')}
                            <input type="number" min={0} className="input w-full mt-1" value={form.enrolment_bonus} onChange={(e) => setForm({ ...form, enrolment_bonus: +e.target.value })} />
                        </label>
                        <div className="text-sm">
                            <div className="mb-1">{t('loyalty.departments')}</div>
                            <div className="flex flex-wrap gap-2">
                                {DEPARTMENTS.map((d) => (
                                    <label key={d} className="inline-flex items-center gap-1 rounded border px-2 py-1">
                                        <input type="checkbox" checked={form.eligible_departments.includes(d)} onChange={() => toggleDept(d)} /> {t(`loyalty.dept.${d}`)}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <p className="text-xs text-slate-500">
                            {t('loyalty.rule', { spend: formatCurrency(form.spend_per_point || 0), value: formatCurrency(form.point_value || 0) })}
                        </p>
                        <button type="submit" disabled={saving} className="btn-primary inline-flex items-center gap-2">
                            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />} {p ? t('common.save') : t('loyalty.create')}
                        </button>
                    </form>

                    <div className="card p-5 lg:col-span-2">
                        <h2 className="font-semibold text-slate-800 mb-3 flex items-center gap-2"><Award className="w-4 h-4 text-emerald-600" /> {t('loyalty.tiers')}</h2>
                        {!p ? (
                            <p className="text-sm text-slate-500">{t('loyalty.no_tiers')}</p>
                        ) : (
                            <TierEditor tiers={tiers} onSaved={(m) => { setSuccess(m); setError(null); program.reload(); }} onError={(m) => { setError(m); setSuccess(null); }} />
                        )}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

type TierDraft = { code: string; name: string; min_qualifying_points: number; min_qualifying_nights: number; earn_multiplier: number; benefits: string; rank: number; active: boolean };
const toDraft = (t?: Tier): TierDraft => ({
    code: t?.code ?? '', name: t?.name ?? '', min_qualifying_points: t?.min_qualifying_points ?? 0, min_qualifying_nights: t?.min_qualifying_nights ?? 0,
    earn_multiplier: Number(t?.earn_multiplier ?? 1), benefits: (t?.benefits ?? []).join(', '), rank: t?.rank ?? 0, active: t?.active ?? true,
});
const toPayload = (d: TierDraft) => ({ ...d, code: d.code.trim(), benefits: d.benefits.split(',').map((b) => b.trim()).filter(Boolean) });

function TierEditor({ tiers, onSaved, onError }: { tiers: Tier[]; onSaved: (t: string) => void; onError: (t: string) => void }) {
    const t = useT();
    const [drafts, setDrafts] = useState<Record<number, TierDraft>>({});
    const [fresh, setFresh] = useState<TierDraft>(() => toDraft());
    const [busy, setBusy] = useState<number | 'new' | null>(null);

    useEffect(() => {
        setDrafts(Object.fromEntries(tiers.map((x) => [x.id, toDraft(x)])));
        const max = tiers.reduce((m, x) => Math.max(m, x.rank), 0);
        setFresh({ ...toDraft(), rank: max + 1 });
    }, [tiers]);

    async function submit(id: number | 'new') {
        setBusy(id);
        try {
            if (id === 'new') await api('loyalty/tiers', { method: 'POST', body: JSON.stringify(toPayload(fresh)) });
            else await api(`loyalty/tiers/${id}`, { method: 'PATCH', body: JSON.stringify(toPayload(drafts[id])) });
            onSaved(id === 'new' ? t('loyalty.tier_added') : t('loyalty.tier_saved'));
        } catch (err) {
            onError(err instanceof ApiError ? err.message : String(err));
        } finally {
            setBusy(null);
        }
    }

    const row = (d: TierDraft, set: (d: TierDraft) => void, key: number | 'new') => (
        <tr key={key} className={`border-t align-top ${d.active ? '' : 'opacity-60'}`}>
            <td className="py-2 pr-2"><input className="input w-14" aria-label={t('loyalty.col.order')} type="number" min={0} max={255} value={d.rank} onChange={(e) => set({ ...d, rank: +e.target.value })} /></td>
            <td className="py-2 pr-2"><input className="input w-20" maxLength={16} value={d.code} onChange={(e) => set({ ...d, code: e.target.value })} placeholder="CODE" aria-label={t('loyalty.col.code')} /></td>
            <td className="py-2 pr-2"><input className="input w-28" maxLength={100} value={d.name} onChange={(e) => set({ ...d, name: e.target.value })} placeholder={t('loyalty.name')} aria-label={t('loyalty.name')} /></td>
            <td className="py-2 pr-2"><input className="input w-24" aria-label={t('loyalty.col.qual_points')} type="number" min={0} value={d.min_qualifying_points} onChange={(e) => set({ ...d, min_qualifying_points: +e.target.value })} /></td>
            <td className="py-2 pr-2"><input className="input w-16" aria-label={t('loyalty.col.nights')} type="number" min={0} value={d.min_qualifying_nights} onChange={(e) => set({ ...d, min_qualifying_nights: +e.target.value })} /></td>
            <td className="py-2 pr-2"><input className="input w-16" aria-label={t('loyalty.col.earn')} type="number" min={0} max={99.99} step={0.05} value={d.earn_multiplier} onChange={(e) => set({ ...d, earn_multiplier: +e.target.value })} /></td>
            <td className="py-2 pr-2"><input className="input w-48" value={d.benefits} onChange={(e) => set({ ...d, benefits: e.target.value })} placeholder="welcome_drink, late_checkout_14h" aria-label={t('loyalty.col.benefits')} /></td>
            <td className="py-2 pr-2"><input type="checkbox" aria-label={t('guests.status.active')} checked={d.active} onChange={(e) => set({ ...d, active: e.target.checked })} /></td>
            <td className="py-2">
                <button type="button" className={key === 'new' ? 'btn-primary' : 'btn-secondary'} disabled={busy !== null || !d.code || !d.name} onClick={() => submit(key)}>
                    {busy === key ? <Loader2 className="w-4 h-4 animate-spin" /> : key === 'new' ? t('common.add') : t('common.save')}
                </button>
            </td>
        </tr>
    );

    return (
        <div className="overflow-x-auto">
            <table className="table-auto w-full text-sm">
                <thead>
                    <tr className="text-left text-slate-500">
                        <th className="py-2 pr-2">{t('loyalty.col.order')}</th>
                        <th className="py-2 pr-2">{t('loyalty.col.code')}</th>
                        <th className="py-2 pr-2">{t('loyalty.name')}</th>
                        <th className="py-2 pr-2">{t('loyalty.col.qual_points')}</th>
                        <th className="py-2 pr-2">{t('loyalty.col.nights')}</th>
                        <th className="py-2 pr-2">{t('loyalty.col.earn')}</th>
                        <th className="py-2 pr-2">{t('loyalty.col.benefits')}</th>
                        <th className="py-2 pr-2">{t('guests.status.active')}</th>
                        <th className="py-2" />
                    </tr>
                </thead>
                <tbody>
                    {tiers.length === 0 && <tr><td colSpan={8} className="p-0"><EmptyFor kind="loyalty" /></td></tr>}
                    {tiers.map((x) => drafts[x.id] && row(drafts[x.id], (d) => setDrafts((all) => ({ ...all, [x.id]: d })), x.id))}
                    {row(fresh, setFresh, 'new')}
                </tbody>
            </table>
            <p className="text-xs text-slate-500 mt-3">{t('loyalty.tiers_hint')}</p>
        </div>
    );
}
