import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useState } from 'react';
import { api, useApi } from '@/lib/api';
import { cn } from '@/lib/utils';
import CrudTab, { Banner, Column, errMsg, Field, Page, Row, SetupTab } from './Crud';
import RatesGrid from './RatesGrid';
import { useT } from '@/lib/i18n';

const TABS = [
    ['room-types', 'prop.tabs.room-types'], ['rooms', 'prop.tabs.rooms'], ['outlets', 'prop.tabs.outlets'], ['assets', 'prop.tabs.assets'], ['rate-plans', 'prop.tabs.rate-plans'],
    ['rates', 'prop.tabs.rates'], ['taxes', 'prop.tabs.taxes'], ['charges', 'prop.tabs.charges'], ['payments', 'prop.tabs.payments'],
] as const;
type Tab = (typeof TABS)[number][0];

function Occ({ r }: { r: Row }) {
    const t = useT();
    return <>{t('prop.occ', { adults: r.max_adults ?? '-', children: r.max_children ?? 0 })}</>;
}
function Cancel({ r }: { r: Row }) {
    const t = useT();
    const v = String(r.cancellation_penalty ?? 'none');
    const k = `prop.opt.${v}`;
    return <>{r.cancellation_hours ?? 0}h · {t(k) === k ? v.replace('_', ' ') : t(k)}</>;
}

const ACTIVE: [string, string][] = [['1', 'prop.opt.active'], ['0', 'prop.opt.inactive']];
const opts = (xs: string[]): [string, string][] => xs.map((x) => [x, `prop.opt.${x}`]);

const roomTypeFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true },
    { name: 'base_rate', label: 'prop.f.base_rate', type: 'money' }, { name: 'max_adults', label: 'prop.f.max_adults', type: 'number' },
    { name: 'max_children', label: 'prop.f.max_children', type: 'number' }, { name: 'bed_type', label: 'prop.f.bed_type', nullable: true },
    { name: 'size_sqm', label: 'prop.f.size_m2', type: 'number', nullable: true }, { name: 'sort', label: 'prop.f.sort_order', type: 'number' },
    { name: 'amenities', label: 'prop.f.amenities', type: 'list' }, { name: 'active', label: 'prop.f.active', type: 'bool' },
    { name: 'description', label: 'prop.f.description', type: 'textarea', nullable: true },
];
const roomTypeCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'base_rate', label: 'prop.f.base_rate', type: 'money' },
    { key: 'occ', label: 'prop.f.occupancy', render: (r) => <Occ r={r} /> },
    { key: 'bed_type', label: 'prop.f.bed' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];

const outletFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true },
    { name: 'kind', label: 'prop.f.kind', type: 'select', required: true, options: opts(['restaurant', 'bar', 'room_service', 'spa', 'laundry', 'minibar', 'shop', 'events', 'other']) },
    { name: 'revenue_account', label: 'prop.f.revenue_account', nullable: true }, { name: 'active', label: 'prop.f.active', type: 'bool' },
];
const outletCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'kind', label: 'prop.f.kind' },
    { key: 'revenue_account', label: 'prop.f.revenue_acct' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];

const ASSET_STATUS = opts(['in_service', 'under_repair', 'retired']);
const assetFields: Field[] = [
    { name: 'name', label: 'prop.f.name', required: true }, { name: 'tag', label: 'prop.f.tag', nullable: true },
    { name: 'category', label: 'prop.f.category', nullable: true }, { name: 'location', label: 'prop.f.location', nullable: true },
    { name: 'purchased_on', label: 'prop.f.purchased_on', type: 'date', nullable: true }, { name: 'cost', label: 'prop.f.cost', type: 'money' },
    { name: 'status', label: 'prop.f.status', type: 'select', options: ASSET_STATUS },
];
const assetCols: Column[] = [
    { key: 'tag', label: 'prop.f.tag' }, { key: 'name', label: 'prop.f.name' }, { key: 'category', label: 'prop.f.category' }, { key: 'location', label: 'prop.f.location' },
    { key: 'purchased_on', label: 'prop.f.purchased', type: 'date' }, { key: 'cost', label: 'prop.f.cost', type: 'money' }, { key: 'status', label: 'prop.f.status' },
];

const ratePlanFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true },
    { name: 'meal_plan', label: 'prop.f.meal_plan', type: 'select', options: [['RO', 'prop.meal.ro'], ['BB', 'prop.meal.bb'], ['HB', 'prop.meal.hb'], ['FB', 'prop.meal.fb'], ['AI', 'prop.meal.ai']] },
    { name: 'cancellation_hours', label: 'prop.f.cancellation_hours', type: 'number' },
    { name: 'cancellation_penalty', label: 'prop.f.cancellation_penalty', type: 'select', options: opts(['none', 'first_night', 'percent', 'full']) },
    { name: 'penalty_percent', label: 'prop.f.penalty_pct', type: 'number' }, { name: 'deposit_percent', label: 'prop.f.deposit_pct', type: 'number' },
    { name: 'derived_adjust_percent', label: 'prop.f.derived_adjust_pct', type: 'number' },
    { name: 'parent_rate_plan_id', label: 'prop.f.parent_plan_id', type: 'number', nullable: true },
    { name: 'company_id', label: 'prop.f.company_id', type: 'number', nullable: true },
    { name: 'refundable', label: 'prop.f.refundable', type: 'bool' }, { name: 'deposit_required', label: 'prop.f.deposit_required', type: 'bool' },
    { name: 'members_only', label: 'prop.f.members_only', type: 'bool' }, { name: 'public', label: 'prop.f.public', type: 'bool' }, { name: 'active', label: 'prop.f.active', type: 'bool' },
];
const ratePlanCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'meal_plan', label: 'prop.f.meal' },
    { key: 'refundable', label: 'prop.f.refundable', type: 'bool' }, { key: 'cancel', label: 'prop.f.cancellation', render: (r) => <Cancel r={r} /> },
    { key: 'public', label: 'prop.f.public', type: 'bool' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];

const taxFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true },
    { name: 'kind', label: 'prop.f.kind', type: 'select', options: opts(['percent', 'fixed_per_night', 'fixed_per_unit']) },
    { name: 'rate', label: 'prop.f.rate_pct', type: 'number' }, { name: 'fixed_amount', label: 'prop.f.fixed_amount', type: 'money' },
    { name: 'account', label: 'prop.f.gl_account', required: true }, { name: 'included_in_price', label: 'prop.f.included_in_price', type: 'bool' }, { name: 'active', label: 'prop.f.active', type: 'bool' },
];
const taxCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'kind', label: 'prop.f.kind' }, { key: 'rate', label: 'prop.f.rate_pct' },
    { key: 'fixed_amount', label: 'prop.f.fixed', type: 'money' }, { key: 'account', label: 'prop.f.account' }, { key: 'included_in_price', label: 'prop.f.inclusive', type: 'bool' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];
const chargeFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true }, { name: 'department', label: 'prop.f.department', required: true },
    { name: 'revenue_account', label: 'prop.f.revenue_account', required: true }, { name: 'tax_codes', label: 'prop.f.tax_codes', type: 'list' }, { name: 'active', label: 'prop.f.active', type: 'bool' },
];
const chargeCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'department', label: 'prop.f.department' },
    { key: 'revenue_account', label: 'prop.f.revenue_acct' }, { key: 'tax_codes', label: 'prop.f.taxes' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];
const payFields: Field[] = [
    { name: 'code', label: 'prop.f.code', required: true }, { name: 'name', label: 'prop.f.name', required: true },
    { name: 'kind', label: 'prop.f.kind', type: 'select', required: true, options: opts(['cash', 'card', 'mobile_money', 'bank_transfer', 'cheque', 'city_ledger', 'voucher', 'loyalty_points']) },
    { name: 'account', label: 'prop.f.gl_account', required: true }, { name: 'provider', label: 'prop.f.provider', nullable: true }, { name: 'active', label: 'prop.f.active', type: 'bool' },
];
const payCols: Column[] = [
    { key: 'code', label: 'prop.f.code' }, { key: 'name', label: 'prop.f.name' }, { key: 'kind', label: 'prop.f.kind' },
    { key: 'account', label: 'prop.f.account' }, { key: 'provider', label: 'prop.f.provider' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
];

function BulkRooms({ types, onDone }: { types: [string, string][]; onDone: () => void }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [f, setF] = useState({ room_type_id: '', from: '', to: '', floor: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const [skipped, setSkipped] = useState<{ number: string; reason: string }[]>([]);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        setSuccess(null);
        setSkipped([]);
        try {
            const r = await api<{ created: string[]; skipped: { number: string; reason: string }[] }>('rooms/bulk', {
                method: 'POST',
                body: JSON.stringify({ room_type_id: Number(f.room_type_id), from: Number(f.from), to: Number(f.to), floor: f.floor || null }),
            });
            setSuccess(t('prop.bulk.created', { count: r.created.length, list: r.created.length ? `: ${r.created.join(', ')}` : '' }));
            setSkipped(r.skipped);
            onDone();
        } catch (err) {
            setError(errMsg(err));
        } finally {
            setBusy(false);
        }
    }

    if (!open) return <button className="btn-secondary mb-4" onClick={() => setOpen(true)}><Layers className="h-4 w-4" /> {t('prop.bulk.title')}</button>;
    return (
        <form onSubmit={submit} className="card mb-4 p-4">
            <h3 className="mb-3 font-semibold text-slate-800">{t('prop.bulk.title')}</h3>
            <Banner error={error} success={success} />
            {skipped.length > 0 && (
                <div className="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <div className="mb-1 font-medium">{t('prop.bulk.skipped', { count: skipped.length })}</div>
                    <ul className="list-disc pl-5">{skipped.map((s) => <li key={s.number}>{s.number}: {s.reason}</li>)}</ul>
                </div>
            )}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                <select className="input" required value={f.room_type_id} onChange={(e) => setF({ ...f, room_type_id: e.target.value })} aria-label={t('prop.bulk.room_type_pick')}>
                    <option value="">{t('prop.bulk.room_type_pick')}</option>
                    {types.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <input className="input" type="number" min={1} required placeholder={t('prop.bulk.from_ph')} aria-label={t('prop.bulk.from_ph')} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <input className="input" type="number" min={1} required placeholder={t('prop.bulk.to_ph')} aria-label={t('prop.bulk.to_ph')} value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <input className="input" placeholder={t('prop.bulk.floor_ph')} aria-label={t('prop.bulk.floor_ph')} value={f.floor} onChange={(e) => setF({ ...f, floor: e.target.value })} />
            </div>
            <div className="mt-3 flex gap-2">
                <button className="btn-primary" disabled={busy}>{t('prop.bulk.create')}</button>
                <button type="button" className="btn-secondary" onClick={() => setOpen(false)}>{t('prop.bulk.close')}</button>
            </div>
        </form>
    );
}

export default function PropertyIndex() {
    const t = useT();
    const [tab, setTab] = useState<Tab>('room-types');
    const rt = useApi<Page<Row>>('room-types?per_page=100', [tab]);
    const typeOpts: [string, string][] = (rt.data?.data ?? []).map((x) => [String(x.id), `${x.code} · ${x.name}`]);
    const tf = (xs: Field[]): Field[] => xs.map((f) => ({ ...f, label: t(f.label), options: f.options?.map(([v, l]) => [v, t(l)] as [string, string]) }));
    const tc = (xs: Column[]): Column[] => xs.map((c) => ({ ...c, label: t(c.label) }));
    const tfl = (xs: { name: string; label: string; options: [string, string][] }[]) => xs.map((f) => ({ ...f, label: t(f.label), options: f.options.map(([v, l]) => [v, t(l)] as [string, string]) }));

    const roomFields: Field[] = [
        { name: 'room_type_id', label: 'prop.f.room_type', type: 'select', required: true, options: typeOpts },
        { name: 'number', label: 'prop.f.number', required: true }, { name: 'floor', label: 'prop.f.floor', nullable: true },
        { name: 'wing', label: 'prop.f.wing', nullable: true }, { name: 'active', label: 'prop.f.active', type: 'bool' },
        { name: 'notes', label: 'prop.f.notes', type: 'textarea', nullable: true },
    ];
    const roomCols: Column[] = [
        { key: 'number', label: 'prop.f.room' }, { key: 'type', label: 'prop.f.type', render: (r) => r.room_type?.code ?? '-' },
        { key: 'floor', label: 'prop.f.floor' }, { key: 'wing', label: 'prop.f.wing' }, { key: 'hk_status', label: 'prop.f.hk_status' },
        { key: 'occupied', label: 'prop.f.occupied', type: 'bool' }, { key: 'active', label: 'prop.f.active', type: 'bool' },
    ];

    return (
        <AppLayout title={t('prop.title')} subtitle={t('prop.subtitle')}>
            <Head title={t('prop.title')} />
            <div className="tabs mb-5">
                {TABS.map(([k, l]) => (
                    <button key={k} onClick={() => setTab(k)}
                        className={cn('tab', tab === k && 'active')} aria-current={tab === k ? 'page' : undefined}>
                        {t(l)}
                    </button>
                ))}
            </div>

            {tab === 'room-types' && <CrudTab path="room-types" noun={t('prop.noun.room_type')} columns={tc(roomTypeCols)} fields={tf(roomTypeFields)} filters={tfl([{ name: 'active', label: 'prop.f.status', options: ACTIVE }])} />}
            {tab === 'rooms' && (
                <CrudTab path="rooms" noun={t('prop.noun.room')} columns={tc(roomCols)} fields={tf(roomFields)}
                    filters={tfl([{ name: 'room_type_id', label: 'prop.f.type', options: typeOpts }, { name: 'active', label: 'prop.f.status', options: ACTIVE }])}
                    extra={(reload) => <BulkRooms types={typeOpts} onDone={reload} />} />
            )}
            {tab === 'outlets' && <CrudTab path="outlets" noun={t('prop.noun.outlet')} columns={tc(outletCols)} fields={tf(outletFields)} filters={tfl([{ name: 'kind', label: 'prop.f.kind', options: outletFields[2].options! }, { name: 'active', label: 'prop.f.status', options: ACTIVE }])} />}
            {tab === 'assets' && <CrudTab path="assets" noun={t('prop.noun.asset')} columns={tc(assetCols)} fields={tf(assetFields)} deleteLabel={t('prop.retire')} filters={tfl([{ name: 'status', label: 'prop.f.status', options: ASSET_STATUS }])} />}
            {tab === 'rate-plans' && <CrudTab path="rate-plans" noun={t('prop.noun.rate_plan')} columns={tc(ratePlanCols)} fields={tf(ratePlanFields)} filters={tfl([{ name: 'active', label: 'prop.f.status', options: ACTIVE }, { name: 'public', label: 'prop.f.public', options: [['1', 'prop.opt.public'], ['0', 'prop.opt.private']] }])} />}
            {tab === 'rates' && <RatesGrid />}
            {tab === 'taxes' && <SetupTab kind="tax-codes" noun={t('prop.noun.tax_code')} columns={tc(taxCols)} fields={tf(taxFields)} />}
            {tab === 'charges' && <SetupTab kind="charge-codes" noun={t('prop.noun.charge_code')} columns={tc(chargeCols)} fields={tf(chargeFields)} />}
            {tab === 'payments' && <SetupTab kind="payment-methods" noun={t('prop.noun.payment_method')} columns={tc(payCols)} fields={tf(payFields)} />}
        </AppLayout>
    );
}
