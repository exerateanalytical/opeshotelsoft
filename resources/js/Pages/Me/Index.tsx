import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import { useT } from '@/lib/i18n';

interface Profile { name: string; number: string; phone: string | null; address: string | null; bank_name: string | null; bank_account: string | null; momo_number: string | null }
interface Slip { id: number; net: number; gross: number; run: { period: string } }
interface Leave { balance: number; types: { id: number; code: string; name: string }[]; requests: { id: number; start_date: string; end_date: string; days: number; status: string }[] }
interface Shift { id: number; date: string; starts_at: string; ends_at: string }
interface Docs { documents: { id: number; title: string | null; original_name: string; type: string }[]; certificates: { id: number; type: string; issued_on: string; expires_on: string | null }[] }
interface Contract { id: number; title: string; status: string }
interface Req { id: number; kind: string; status: string; note: string | null; payload: Record<string, string> }

const TABS = ['payslips', 'leave', 'schedule', 'documents', 'contract', 'requests'] as const;
const KINDS = ['employment_certificate', 'salary_certificate', 'address_change', 'phone_change', 'bank_change', 'momo_change'];
const money = (n: number) => new Intl.NumberFormat('fr-FR').format(n);
const post = (p: string, b: unknown) => api(p, { method: 'POST', body: JSON.stringify(b) });

/** Employee self-service, designed for a phone. Everything shown belongs to the signed-in user's employee record. */
export default function MeIndex() {
    const t = useT();
    const [tab, setTab] = useState<(typeof TABS)[number]>('payslips');
    const [me, setMe] = useState<Profile | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [slips, setSlips] = useState<Slip[]>([]);
    const [leave, setLeave] = useState<Leave | null>(null);
    const [shifts, setShifts] = useState<Shift[]>([]);
    const [docs, setDocs] = useState<Docs | null>(null);
    const [contracts, setContracts] = useState<Contract[]>([]);
    const [reqs, setReqs] = useState<Req[]>([]);
    const [lf, setLf] = useState({ leave_type_id: '', start_date: '', end_date: '' });
    const [kind, setKind] = useState(KINDS[0]);
    const [val, setVal] = useState({ address: '', phone: '', bank_name: '', bank_account: '', momo_number: '', purpose: '' });

    const msg = (e: unknown) => (e instanceof Error ? e.message : String(e));
    const load = () => {
        api<Profile>('hr/me').then(setMe).catch((e) => setErr(msg(e)));
        api<Slip[]>('payroll/payslips/mine').then(setSlips).catch(() => undefined);
        api<Leave>('hr/me/leave').then(setLeave).catch(() => undefined);
        api<Shift[]>('hr/me/schedule').then(setShifts).catch(() => undefined);
        api<Docs>('hr/me/documents').then(setDocs).catch(() => undefined);
        api<Contract[]>('hr/me/contracts').then(setContracts).catch(() => undefined);
        api<Req[]>('hr/me/requests').then(setReqs).catch(() => undefined);
    };
    useEffect(load, []);
    const run = async (fn: () => Promise<unknown>, done?: string) => { setErr(null); setOk(null); try { await fn(); if (done) setOk(done); load(); } catch (e) { setErr(msg(e)); } };
    const fields: Record<string, string[]> = { address_change: ['address'], phone_change: ['phone'], bank_change: ['bank_name', 'bank_account'], momo_change: ['momo_number'], employment_certificate: ['purpose'], salary_certificate: ['purpose'] };

    return (
        <div className="min-h-screen bg-slate-50 pb-10 text-slate-900">
            <Head title={t('talent.me.title')} />
            <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
                <div><h1 className="text-lg font-semibold">{t('talent.me.title')}</h1>{me && <p className="text-sm text-slate-600">{me.name} - {me.number}</p>}</div>
                <button className="btn-secondary" onClick={() => router.visit('/dashboard')}>{t('talent.me.back')}</button>
            </header>
            <nav aria-label={t('talent.me.title')} className="flex gap-2 overflow-x-auto px-4 py-3 text-sm">
                {TABS.map((x) => <button key={x} onClick={() => setTab(x)} aria-current={tab === x ? 'page' : undefined} className={`min-h-11 whitespace-nowrap rounded-lg border px-4 py-2 ${tab === x ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white'}`}>{t(`talent.me.tab.${x}`)}</button>)}
            </nav>
            <main className="space-y-3 px-4 text-base">
                {err && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{err}</div>}
                {ok && <div role="status" className="rounded-lg border border-slate-200 bg-white p-3 text-sm">{ok}</div>}

                {tab === 'payslips' && (<div className="card divide-y divide-slate-100">{slips.map((s) => (
                    <div key={s.id} className="flex items-center justify-between p-3"><span>{s.run.period}<br /><span className="text-sm text-slate-600">{t('talent.me.net')}: {money(s.net)} XAF</span></span>
                        <a className="btn-secondary" href={`/api/v1/documents/payslips/${s.id}`} target="_blank" rel="noreferrer">PDF</a></div>))}
                    {!slips.length && <p className="p-3 text-slate-500">{t('talent.none')}</p>}</div>)}

                {tab === 'leave' && leave && (<>
                    <div className="card p-3">{t('talent.me.balance')}: <strong>{leave.balance}</strong> {t('talent.me.days')}</div>
                    <div className="card grid gap-2 p-3">
                        <select className="input" aria-label={t('talent.me.leave_type')} value={lf.leave_type_id} onChange={(e) => setLf({ ...lf, leave_type_id: e.target.value })}><option value="">{t('talent.me.leave_type')}</option>{leave.types.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
                        <input className="input" type="date" aria-label={t('talent.me.leave_start')} value={lf.start_date} onChange={(e) => setLf({ ...lf, start_date: e.target.value })} />
                        <input className="input" type="date" aria-label={t('talent.me.leave_end')} value={lf.end_date} onChange={(e) => setLf({ ...lf, end_date: e.target.value })} />
                        <button className="btn-primary py-3" disabled={!lf.leave_type_id || !lf.start_date || !lf.end_date} onClick={() => run(() => post('hr/me/leave', { ...lf, leave_type_id: Number(lf.leave_type_id) }), t('talent.me.leave_sent'))}>{t('talent.me.request_leave')}</button>
                    </div>
                    <div className="card divide-y divide-slate-100">{leave.requests.map((r) => <p key={r.id} className="p-3">{r.start_date.slice(0, 10)} - {r.end_date.slice(0, 10)} ({r.days} {t('talent.me.days')}) <span className="text-sm text-slate-600">{t(`talent.req_status.${r.status}`)}</span></p>)}</div>
                </>)}

                {tab === 'schedule' && (<div className="card divide-y divide-slate-100">{shifts.map((s) => <p key={s.id} className="p-3">{s.date.slice(0, 10)} - {String(s.starts_at).slice(0, 5)} / {String(s.ends_at).slice(0, 5)}</p>)}{!shifts.length && <p className="p-3 text-slate-500">{t('talent.none')}</p>}</div>)}

                {tab === 'documents' && docs && (<>
                    <div className="card divide-y divide-slate-100"><h2 className="p-3 font-semibold">{t('talent.me.documents')}</h2>
                        {docs.documents.map((d) => <p key={d.id} className="flex items-center justify-between p-3">{d.title || d.original_name}<a className="btn-secondary" href={`/api/v1/hr/me/documents/${d.id}/download`}>{t('talent.me.download')}</a></p>)}
                        {!docs.documents.length && <p className="p-3 text-slate-500">{t('talent.none')}</p>}</div>
                    <div className="card divide-y divide-slate-100"><h2 className="p-3 font-semibold">{t('talent.me.certificates')}</h2>
                        {docs.certificates.map((c) => <p key={c.id} className="p-3">{c.type} <span className="text-sm text-slate-600">{c.issued_on}{c.expires_on ? ` - ${c.expires_on}` : ''}</span></p>)}
                        {!docs.certificates.length && <p className="p-3 text-slate-500">{t('talent.none')}</p>}</div>
                </>)}

                {tab === 'contract' && (<div className="card divide-y divide-slate-100">{contracts.map((c) => (
                    <div key={c.id} className="flex items-center justify-between p-3"><span>{c.title}</span>
                        <button className="btn-primary" onClick={() => run(async () => { const r = (await post(`hr/me/contracts/${c.id}/link`, {})) as { url: string }; window.location.href = r.url; })}>{t('talent.me.sign')}</button></div>))}
                    {!contracts.length && <p className="p-3 text-slate-500">{t('talent.me.no_contract')}</p>}</div>)}

                {tab === 'requests' && (<>
                    <div className="card grid gap-2 p-3">
                        <select className="input" aria-label={t('talent.me.request_kind')} value={kind} onChange={(e) => setKind(e.target.value)}>{KINDS.map((k) => <option key={k} value={k}>{t(`talent.kind.${k}`)}</option>)}</select>
                        {(fields[kind] ?? []).map((f) => <input key={f} className="input" aria-label={t(`talent.me.field.${f}`)} placeholder={t(`talent.me.field.${f}`)} value={val[f as keyof typeof val]} onChange={(e) => setVal({ ...val, [f]: e.target.value })} />)}
                        <p className="text-sm text-slate-600">{t('talent.me.hr_approves')}</p>
                        <button className="btn-primary py-3" onClick={() => run(() => post('hr/me/requests', { kind, ...Object.fromEntries((fields[kind] ?? []).map((f) => [f, val[f as keyof typeof val]])) }), t('talent.me.request_sent'))}>{t('talent.me.send_request')}</button>
                    </div>
                    <div className="card divide-y divide-slate-100">{reqs.map((r) => (
                        <p key={r.id} className="flex items-center justify-between p-3"><span>{t(`talent.kind.${r.kind}`)}<br /><span className="text-sm text-slate-600">{t(`talent.req_status.${r.status}`)}{r.note ? ` - ${r.note}` : ''}</span></span>
                            {r.status === 'approved' && r.kind.endsWith('certificate') && <a className="btn-secondary" href={`/api/v1/hr/me/requests/${r.id}/certificate`} target="_blank" rel="noreferrer">PDF</a>}</p>))}</div>
                    {me && <div className="card p-3 text-sm text-slate-700"><p>{t('talent.me.current')}</p><p>{me.phone} - {me.address}</p><p>{me.bank_name} {me.bank_account} {me.momo_number}</p></div>}
                </>)}
            </main>
        </div>
    );
}
