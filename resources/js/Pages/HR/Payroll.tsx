import { api, useApi } from '@/lib/api';
import QueryStatus from '@/Components/ui/QueryStatus';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { AlertTriangle, BadgeCheck, Calculator, Download, FileText, Loader2, X } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Badge, Banner, downloadCsv, downloadJson, errMsg, Field, Paged } from './shared';
import { useT } from '@/lib/i18n';
import OpsPrintButton from '@/Components/OpsPrintButton';
import Alert from '@/Components/ui/Alert';

interface Run {
    id: number; period: string; status: string; payroll_rule_pack_id: number; gross: number; net: number;
    employee_social: number; employer_social: number; taxes_withheld: number; other_employer: number; advances_recovered: number;
    prepared_by: number | null; approved_by: number | null; approved_at: string | null; paid_at: string | null;
    payslips?: Payslip[];
}
interface Line { section: string; code: string; label: string; amount: number; rate: number | null; basis: number | null }
interface Payslip {
    id: number; payroll_run_id: number; employee_id: number; gross: number; taxable: number; net: number;
    employee_social: number; employer_social: number; taxes_withheld: number; other_employer: number; advances_recovered: number;
    lines: Line[]; inputs: Record<string, number>; created_at?: string;
    employee?: { id: number; number: string; first_name: string; last_name: string };
    run?: { id: number; period: string; status: string; period_start: string; period_end: string };
}
interface Pack { id: number; country: string; version: string; effective_from: string; certified: boolean; certified_by: string | null; certified_at: string | null; params: Record<string, unknown> }

export function PayrollTab() {
    const t = useT();
    const [view, setView] = useState<'runs' | 'mine' | 'packs'>('runs');
    return (
        <div>
            <div className="mb-4 flex flex-wrap gap-2">
                {([['runs', 'hr.pay.runs'], ['mine', 'hr.pay.mine'], ['packs', 'hr.pay.packs']] as const).map(([k, l]) => (
                    <button key={k} className={view === k ? 'btn-primary' : 'btn-secondary'} onClick={() => setView(k)}>{t(l)}</button>
                ))}
            </div>
            {view === 'runs' && <Runs />}
            {view === 'mine' && <Mine />}
            {view === 'packs' && <Packs />}
        </div>
    );
}

function Runs() {
    const t = useT();
    const runs = useApi<Paged<Run>>('payroll/runs');
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [period, setPeriod] = useState(() => new Date().toISOString().slice(0, 7));
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState<number | null>(null);

    async function compute(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        try {
            const r = await api<Run>('payroll/runs', { method: 'POST', body: JSON.stringify({ period }) });
            setOk(t('hr.pay.computed', { period: r.period, count: r.payslips?.length ?? 0, net: formatCurrency(r.net) })); setErr(null);
            setOpen(r.id); runs.reload();
        } catch (x) { setErr(errMsg(x)); setOk(null); } finally { setBusy(false); }
    }

    return (
        <div>
            <Banner ok={ok} err={err} />
            <QueryStatus query={runs} variant="table" rows={3} />
            <form onSubmit={compute} className="card mb-5 flex flex-wrap items-end gap-3">
                <Field label={t('hr.pay.period')}><input type="month" className="input" required value={period} onChange={(e) => setPeriod(e.target.value)} /></Field>
                <button className="btn-primary" disabled={busy}>{busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Calculator className="h-4 w-4" />} {t('hr.pay.compute')}</button>
                <p className="text-xs text-slate-500">{t('hr.pay.compute_hint')}</p>
            </form>
            {open && <RunDetail id={open} onClose={() => setOpen(null)} onChanged={(m) => (setOk(m), setErr(null), runs.reload())} onError={(m) => (setErr(m), setOk(null))} />}
            <div className="card overflow-x-auto p-0">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('hr.pay.period')}</th><th>{t('hr.common.status')}</th><th>{t('hr.pay.gross')}</th><th>{t('hr.pay.employee_social')}</th><th>{t('hr.pay.taxes')}</th><th>{t('hr.pay.employer_cost')}</th><th>{t('hr.pay.net')}</th></tr></thead>
                    <tbody>
                        {runs.data?.data.length === 0 && <tr><td colSpan={7} className="p-6 text-center text-slate-500">{t('hr.pay.no_runs')}</td></tr>}
                        {runs.data?.data.map((r) => (
                            <tr key={r.id} className="cursor-pointer border-t hover:bg-slate-50" onClick={() => setOpen(r.id)}>
                                <td className="p-3 font-medium">{r.period}</td><td><Badge s={r.status} /></td>
                                <td>{formatCurrency(r.gross)}</td><td>{formatCurrency(r.employee_social)}</td><td>{formatCurrency(r.taxes_withheld)}</td>
                                <td>{formatCurrency(r.employer_social + r.other_employer)}</td><td className="font-semibold">{formatCurrency(r.net)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function RunDetail({ id, onClose, onChanged, onError }: { id: number; onClose: () => void; onChanged: (m: string) => void; onError: (m: string) => void }) {
    const t = useT();
    const run = useApi<Run>(`payroll/runs/${id}`);
    const [slip, setSlip] = useState<Payslip | null>(null);
    const [account, setAccount] = useState('521');
    const [paying, setPaying] = useState(false);
    const [busy, setBusy] = useState(false);
    const r = run.data;

    async function act(path: string, body: unknown, msg: string) {
        setBusy(true);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            onChanged(msg); setPaying(false); run.reload();
        } catch (x) { onError(errMsg(x)); } finally { setBusy(false); }
    }
    async function download(kind: 'payment-file' | 'declaration') {
        if (!r) return;
        try {
            const d = await api<{ period: string; rows: Record<string, unknown>[] } & Record<string, unknown>>(`payroll/runs/${r.id}/${kind}`);
            if (kind === 'payment-file') downloadCsv(`payroll-${r.period}-payments.csv`, d.rows);
            else downloadJson(`payroll-${r.period}-declaration.json`, d);
        } catch (x) { onError(errMsg(x)); }
    }

    return (
        <div className="card mb-5">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="flex flex-wrap items-center gap-2 font-semibold">{t('hr.pay.title', { period: r?.period ?? '' })} {r && <Badge s={r.status} />} {r && <OpsPrintButton path={`payroll-journal/${r.id}`} label={t('documents4.journal')} />}</h3>
                <button className="btn-ghost btn-icon btn-sm" onClick={onClose} aria-label={t('ui.nav.close')}><X className="h-4 w-4" /></button>
            </div>
            {run.loading && !r && <Loader2 className="h-5 w-5 animate-spin text-slate-500" />}
            {run.error && <p className="text-sm text-red-600">{run.error.message}</p>}
            {r && (
                <>
                    <div className="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><p className="text-slate-500">{t('hr.pay.gross')}</p><p className="font-semibold">{formatCurrency(r.gross)}</p></div>
                        <div><p className="text-slate-500">{t('hr.pay.net_to_pay')}</p><p className="font-semibold text-emerald-700">{formatCurrency(r.net)}</p></div>
                        <div><p className="text-slate-500">{t('hr.pay.withheld')}</p><p>{formatCurrency(r.employee_social + r.taxes_withheld)}</p></div>
                        <div><p className="text-slate-500">{t('hr.pay.advances_recovered')}</p><p>{formatCurrency(r.advances_recovered)}</p></div>
                        {r.approved_at && <div><p className="text-slate-500">{t('hr.status.approved')}</p><p>{formatDateTime(r.approved_at)}</p></div>}
                        {r.paid_at && <div><p className="text-slate-500">{t('hr.status.paid')}</p><p>{formatDateTime(r.paid_at)}</p></div>}
                    </div>
                    <div className="mb-4 flex flex-wrap gap-2">
                        {r.status === 'draft' && <button className="btn-primary" disabled={busy} onClick={() => act(`payroll/runs/${r.id}/approve`, {}, t('hr.pay.approved', { period: r.period }))}><BadgeCheck className="h-4 w-4" /> {t('hr.common.approve')}</button>}
                        {r.status === 'approved' && !paying && <button className="btn-primary" onClick={() => setPaying(true)}>{t('hr.pay.mark_paid')}</button>}
                        <button className="btn-secondary" onClick={() => download('payment-file')}><Download className="h-4 w-4" /> {t('hr.pay.payment_file')}</button>
                        <button className="btn-secondary" onClick={() => download('declaration')}><FileText className="h-4 w-4" /> {t('hr.pay.declaration')}</button>
                    </div>
                    {r.status === 'draft' && <p className="mb-4 text-xs text-amber-700">{t('hr.pay.approval_hint')}</p>}
                    {paying && (
                        <form className="mb-4 flex flex-wrap items-end gap-2 rounded border p-3" onSubmit={(e) => { e.preventDefault(); act(`payroll/runs/${r.id}/pay`, { account }, t('hr.pay.paid_msg', { period: r.period, account })); }}>
                            <Field label={t('hr.pay.paid_from')}><input className="input" required value={account} onChange={(e) => setAccount(e.target.value)} /></Field>
                            <button className="btn-primary" disabled={busy}>{t('hr.pay.confirm_payment', { amount: formatCurrency(r.net) })}</button>
                            <button type="button" className="btn-secondary" onClick={() => setPaying(false)}>{t('common.cancel')}</button>
                        </form>
                    )}
                    <div className="overflow-x-auto">
                        <table className="table-auto w-full text-sm">
                            <thead><tr className="text-left text-slate-500"><th>{t('hr.emp.employee')}</th><th>{t('hr.pay.gross')}</th><th>{t('hr.pay.social')}</th><th>{t('hr.pay.tax')}</th><th>{t('hr.pay.advances')}</th><th>{t('hr.pay.net')}</th></tr></thead>
                            <tbody>{r.payslips?.map((s) => (
                                <tr key={s.id} className="cursor-pointer border-t hover:bg-slate-50" onClick={() => setSlip(s)}>
                                    <td className="py-2">{s.employee ? `${s.employee.number} · ${s.employee.last_name} ${s.employee.first_name}` : `#${s.employee_id}`}</td>
                                    <td>{formatCurrency(s.gross)}</td><td>{formatCurrency(s.employee_social)}</td><td>{formatCurrency(s.taxes_withheld)}</td>
                                    <td>{formatCurrency(s.advances_recovered)}</td><td className="font-semibold">{formatCurrency(s.net)}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                    {slip && <SlipView slip={slip} onClose={() => setSlip(null)} />}
                </>
            )}
        </div>
    );
}

function SlipView({ slip, onClose }: { slip: Payslip; onClose: () => void }) {
    const t = useT();
    const sections = [...new Set(slip.lines.map((l) => l.section))];
    return (
        <div className="mt-4 rounded-lg border p-4">
            <div className="mb-2 flex items-center justify-between">
                <h4 className="font-semibold">{t('hr.pay.payslip')} {slip.employee ? `${slip.employee.last_name} ${slip.employee.first_name}` : `#${slip.id}`}</h4>
                <button className="btn-ghost btn-icon btn-sm" onClick={onClose} aria-label={t('ui.nav.close')}><X className="h-4 w-4" /></button>
            </div>
            <div className="overflow-x-auto">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th>{t('hr.common.code')}</th><th>{t('hr.pay.label')}</th><th>{t('hr.pay.basis')}</th><th>{t('hr.pay.rate')}</th><th className="text-right">{t('hr.common.amount')}</th></tr></thead>
                    {sections.map((sec) => (
                        <tbody key={sec}>
                            <tr><td colSpan={5} className="pt-3 text-sm font-semibold text-slate-600">{t(`hr.pay.section.${sec}`) === `hr.pay.section.${sec}` ? sec.replace('_', ' ') : t(`hr.pay.section.${sec}`)}</td></tr>
                            {slip.lines.filter((l) => l.section === sec).map((l, i) => (
                                <tr key={i} className="border-t"><td className="font-mono text-xs">{l.code}</td><td>{l.label}</td><td>{l.basis != null ? formatCurrency(l.basis) : ''}</td><td>{l.rate != null ? `${+(l.rate * 100).toFixed(3)}%` : ''}</td><td className="text-right">{formatCurrency(l.amount)}</td></tr>
                            ))}
                        </tbody>
                    ))}
                </table>
            </div>
            <p className="mt-3 text-right font-semibold">{t('hr.pay.net_pay', { amount: formatCurrency(slip.net) })}</p>
        </div>
    );
}

function Mine() {
    const t = useT();
    const mine = useApi<Payslip[]>('payroll/payslips/mine');
    const [slip, setSlip] = useState<Payslip | null>(null);
    if (mine.error) return <Banner err={mine.error.problem.status === 404 ? t('hr.pay.not_linked') : mine.error.message} />;
    return (
        <div className="card overflow-x-auto">
            <table className="table-auto w-full text-sm">
                <thead><tr className="text-left text-slate-500"><th>{t('hr.pay.period')}</th><th>{t('hr.common.status')}</th><th>{t('hr.pay.gross')}</th><th>{t('hr.pay.deductions')}</th><th>{t('hr.pay.net')}</th></tr></thead>
                <tbody>
                    {mine.loading && <tr><td colSpan={6} className="p-4 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-slate-500" /></td></tr>}
                    {mine.data?.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500">{t('hr.pay.no_slips')}</td></tr>}
                    {mine.data?.map((s) => (
                        <tr key={s.id} className="cursor-pointer border-t hover:bg-slate-50" onClick={() => setSlip(s)}>
                            <td className="py-2">{s.run ? `${formatDate(s.run.period_start)} – ${formatDate(s.run.period_end)}` : t('hr.pay.run_no', { id: s.payroll_run_id })}</td><td>{s.run && <Badge s={s.run.status} />}</td>
                            <td>{formatCurrency(s.gross)}</td><td>{formatCurrency(s.employee_social + s.taxes_withheld + s.advances_recovered)}</td><td className="font-semibold">{formatCurrency(s.net)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {slip && <SlipView slip={slip} onClose={() => setSlip(null)} />}
        </div>
    );
}

function Packs() {
    const t = useT();
    const packs = useApi<Pack[]>('payroll/rule-packs');
    const [ok, setOk] = useState<string | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [certifying, setCertifying] = useState<number | null>(null);
    const [by, setBy] = useState('');
    const [show, setShow] = useState<number | null>(null);

    async function certify(p: Pack) {
        try {
            await api(`payroll/rule-packs/${p.id}/certify`, { method: 'POST', body: JSON.stringify({ certified_by: by }) });
            setOk(t('hr.pay.certified_msg', { pack: `${p.country} ${p.version}`, by })); setErr(null); setCertifying(null); setBy(''); packs.reload();
        } catch (x) { setErr(errMsg(x)); setOk(null); }
    }

    return (
        <div>
            <Banner ok={ok} err={err} />
            <QueryStatus query={packs} variant="list" rows={3} />
            <Alert variant="warning" title={t('hr.pay.cm_uncertified')} className="mb-4">
                <p>{t('hr.pay.cm_uncertified_text')}</p>
            </Alert>
            <div className="card overflow-x-auto p-0">
                <table className="table-auto w-full text-sm">
                    <thead><tr className="text-left text-slate-500"><th className="p-3">{t('hr.pay.country')}</th><th>{t('hr.pay.version')}</th><th>{t('hr.pay.effective')}</th><th>{t('hr.pay.certification')}</th><th></th></tr></thead>
                    <tbody>
                        {packs.data?.map((p) => (
                            <Fragment key={p.id}>
                                <tr className="border-t">
                                    <td className="p-3 font-medium">{p.country}</td><td>{p.version}</td><td>{formatDate(p.effective_from)}</td>
                                    <td>{p.certified
                                        ? <span className="text-emerald-700">{t('hr.pay.certified_by', { by: p.certified_by ?? '' })}{p.certified_at ? t('hr.pay.certified_on', { date: formatDate(p.certified_at) }) : ''}</span>
                                        : <span className="inline-flex items-center gap-1 font-medium text-amber-700"><AlertTriangle className="h-4 w-4" /> {t('hr.pay.uncertified')}</span>}</td>
                                    <td className="whitespace-nowrap pr-3 text-right">
                                        <button className="btn-secondary mr-1 px-2 py-1 text-xs" onClick={() => setShow(show === p.id ? null : p.id)}>{t('hr.pay.parameters')}</button>
                                        {!p.certified && <button className="btn-secondary px-2 py-1 text-xs" onClick={() => setCertifying(p.id)}>{t('hr.pay.record_cert')}</button>}
                                    </td>
                                </tr>
                                {certifying === p.id && (
                                    <tr key={`c${p.id}`}><td colSpan={5} className="p-3">
                                        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); certify(p); }}>
                                            <Field label={t('hr.pay.certified_by_label')}><input className="input w-80 max-w-full" required maxLength={150} value={by} onChange={(e) => setBy(e.target.value)} /></Field>
                                            <button className="btn-primary">{t('hr.pay.certify')}</button>
                                            <button type="button" className="btn-secondary" onClick={() => setCertifying(null)}>{t('common.cancel')}</button>
                                            <span className="text-xs text-slate-500">{t('hr.pay.cert_who')}</span>
                                        </form>
                                    </td></tr>
                                )}
                                {show === p.id && <tr key={`p${p.id}`}><td colSpan={5} className="p-3"><pre className="max-h-80 overflow-auto rounded bg-slate-50 p-3 text-xs">{JSON.stringify(p.params, null, 2)}</pre></td></tr>}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
