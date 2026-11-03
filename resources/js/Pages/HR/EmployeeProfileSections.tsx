import OpsPrintButton from '@/Components/OpsPrintButton';
import { useState as useStateLocal, useState } from 'react';
import { activeHotelId } from '@/lib/api';
import { useT } from '@/lib/i18n';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/utils';
import { Badge } from './shared';

type T = ReturnType<typeof useT>;
export type Data = any; // eslint-disable-line @typescript-eslint/no-explicit-any

const money = (n: number | null | undefined) => (n == null ? '—' : formatCurrency(n));
const date = (d: string | null | undefined) => (d ? formatDate(d) : '—');

/** Flat label/value pair. */
function KV({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-sm text-slate-600">{label}</dt>
            <dd className="break-words text-base text-slate-900">{children ?? '—'}</dd>
        </div>
    );
}

function Card({ title, children, empty }: { title: string; children?: React.ReactNode; empty?: string }) {
    return (
        <section className="card p-4">
            <h3 className="mb-3 text-base font-semibold text-slate-900">{title}</h3>
            {children ?? <p className="text-sm text-slate-600">{empty}</p>}
        </section>
    );
}

/** Tables scroll inside their own container, never the page. */
function Table({ head, children }: { head: string[]; children: React.ReactNode }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[480px] text-left text-sm">
                <thead><tr className="text-slate-600">{head.map((h) => <th key={h} className="whitespace-nowrap py-2 pr-3 font-medium">{h}</th>)}</tr></thead>
                <tbody className="[&>tr]:border-t [&_td]:py-2 [&_td]:pr-3">{children}</tbody>
            </table>
        </div>
    );
}

function Pill({ tone, children }: { tone: 'ok' | 'warn' | 'bad' | 'muted'; children: React.ReactNode }) {
    const c = { ok: 'bg-emerald-50 text-emerald-800', warn: 'bg-amber-50 text-amber-800', bad: 'bg-red-50 text-red-800', muted: 'bg-slate-100 text-slate-700' }[tone];
    return <span className={`inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-sm font-medium ${c}`}>{children}</span>;
}

const expiryTone = (e: string) => (e === 'expired' ? 'bad' : e === 'soon' ? 'warn' : e === 'valid' ? 'ok' : 'muted') as 'ok' | 'warn' | 'bad' | 'muted';
const ExpiryPill = ({ t, e, days }: { t: T; e: string; days: number | null }) => (
    <Pill tone={expiryTone(e)}>{e === 'none' ? t('profile.expiry.none') : e === 'expired' ? t('profile.expiry.expired') : t('profile.expiry.days', { count: days ?? 0 })}</Pill>
);

// ---------------- Overview ----------------
export function Overview({ d, t }: { d: Data; t: T }) {
    const c = d.completeness;
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Card title={t('profile.overview.identity')}>
                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <KV label={t('profile.f.number')}>{d.number}</KV>
                    <KV label={t('profile.f.status')}><Badge s={d.status} /></KV>
                    <KV label={t('profile.f.hotel')}>{d.hotel}</KV>
                    <KV label={t('profile.f.department')}>{d.department}</KV>
                    <KV label={t('profile.f.position')}>{d.position}</KV>
                    <KV label={t('profile.f.manager')}>{d.manager?.name}</KV>
                    <KV label={t('profile.f.hire_date')}>{date(d.hire_date)}</KV>
                    <KV label={t('profile.f.tenure')}>{t('profile.tenure', { years: d.tenure.years, months: d.tenure.months })}</KV>
                    {d.termination_date && <KV label={t('profile.f.terminated')}>{date(d.termination_date)} · {d.termination_reason}</KV>}
                    <KV label={t('profile.f.dob')}>{date(d.date_of_birth)}</KV>
                    <KV label={t('profile.f.marital')}>{d.marital_status ?? '—'}{d.children ? ` · ${t('profile.children', { count: d.children })}` : ''}</KV>
                </dl>
            </Card>
            <Card title={t('profile.overview.contact')}>
                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <KV label={t('profile.f.phone')}>{d.phone ? <a className="underline" href={`tel:${d.phone}`}>{d.phone}</a> : null}</KV>
                    <KV label={t('profile.f.email')}>{d.email ? <a className="underline" href={`mailto:${d.email}`}>{d.email}</a> : null}</KV>
                    <KV label={t('profile.f.address')}>{d.address}</KV>
                    <KV label={t('profile.f.emergency')}>{d.emergency_contact ?? t('profile.not_stored')}</KV>
                </dl>
            </Card>
            <Card title={t('profile.overview.admin')}>
                <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <KV label={t('profile.f.id_number')}>{d.id_number}</KV>
                    <KV label={t('profile.f.cnps')}>{d.cnps_number}</KV>
                    <KV label={t('profile.f.tax')}>{d.tax_number}</KV>
                    <KV label={t('profile.f.payment_method')}>{t(`hr.emp.pay.${d.payment_method}`)}</KV>
                    {(d.bank_name || d.bank_account) && <KV label={t('profile.f.bank')}>{[d.bank_name, d.bank_account].filter(Boolean).join(' · ')}</KV>}
                    {d.momo_number && <KV label={t('profile.f.momo')}>{d.momo_number}</KV>}
                </dl>
            </Card>
            <Card title={t('profile.overview.completeness', { percent: c.percent })}>
                <div className="mb-3 h-3 w-full rounded bg-slate-200" role="progressbar" aria-valuenow={c.percent} aria-valuemin={0} aria-valuemax={100} aria-label={t('profile.overview.completeness', { percent: c.percent })}>
                    <div className="h-3 rounded bg-emerald-700" style={{ width: `${c.percent}%` }} />
                </div>
                {c.missing.length === 0 ? <p className="text-sm text-slate-700">{t('profile.complete')}</p> : (
                    <>
                        <p className="mb-2 text-sm text-slate-700">{t('profile.missing')}</p>
                        <ul className="flex flex-wrap gap-2">{c.missing.map((m: string) => <li key={m}><Pill tone="warn">{t(`profile.check.${m}`)}</Pill></li>)}</ul>
                    </>
                )}
            </Card>
        </div>
    );
}

// ---------------- Employment ----------------
export function Employment({ d, t, canPay }: { d: Data; t: T; canPay: boolean }) {
    return (
        <div className="grid gap-4">
            <Card title={t('profile.employment.contracts')} empty={t('profile.none')}>
                {d.contracts.length > 0 && (
                    <ol className="space-y-3 border-l-2 border-slate-300 pl-4">
                        {d.contracts.map((c: Data) => (
                            <li key={c.id}>
                                <p className="font-medium">{c.kind} <Pill tone={c.status === 'active' ? 'ok' : 'muted'}>{c.status}</Pill></p>
                                <p className="text-sm text-slate-700">{date(c.start_date)} → {c.end_date ? date(c.end_date) : t('profile.open_ended')}{c.probation_end ? ` · ${t('profile.probation_end')} ${date(c.probation_end)}` : ''}</p>
                                {canPay && <p className="text-sm text-slate-700">{money(c.base_salary)}</p>}
                            </li>
                        ))}
                    </ol>
                )}
            </Card>
            <Card title={t('profile.employment.econtracts')} empty={t('profile.none')}>
                {d.econtracts.length > 0 && (
                    <Table head={[t('profile.f.number'), t('profile.f.kind'), t('profile.f.status'), t('profile.employment.employee_signed'), t('profile.employment.employer_signed'), '']}>
                        {d.econtracts.map((c: Data) => (
                            <tr key={c.id}>
                                <td className="font-mono text-sm">{c.number}</td><td>{c.kind}</td>
                                <td><Pill tone={c.status === 'signed' || c.status === 'sealed' ? 'ok' : 'muted'}>{c.status}</Pill></td>
                                <td>{c.employee_signed_at ? formatDate(c.employee_signed_at) : '—'}</td><td>{c.employer_signed_at ? formatDate(c.employer_signed_at) : '—'}</td>
                                <td><PdfLink url={c.pdf_url} label={t('profile.pdf')} /></td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title={t('profile.employment.positions')} empty={t('profile.none')}>
                    {d.position_history.length > 0 && <ul className="space-y-2">{d.position_history.map((p: Data, i: number) => <li key={i} className="text-sm"><span className="font-medium">{p.position ?? '—'}</span> · {date(p.effective_date)} <span className="text-slate-600">({t(`profile.source.${p.source}`)})</span></li>)}</ul>}
                </Card>
                <Card title={t('profile.employment.departments')} empty={t('profile.none')}>
                    {d.department_history.length > 0 && <ul className="space-y-2">{d.department_history.map((p: Data, i: number) => <li key={i} className="text-sm">{p.from ?? '—'} → <span className="font-medium">{p.to ?? '—'}</span> · {date(p.effective_date)}</li>)}</ul>}
                </Card>
            </div>
            <Card title={t('profile.employment.transfers')} empty={t('profile.none')}>
                {d.transfers.length > 0 && (
                    <Table head={[t('profile.f.date'), t('profile.employment.from'), t('profile.employment.to'), t('profile.employment.seniority'), t('profile.f.status')]}>
                        {d.transfers.map((x: Data) => (
                            <tr key={x.id}>
                                <td>{date(x.effective_date)}</td><td>{x.from_hotel}{x.from_position ? ` · ${x.from_position}` : ''}</td><td>{x.to_hotel}{x.to_position ? ` · ${x.to_position}` : ''}</td>
                                <td>{date(x.seniority_date)}</td><td><Pill tone={x.status === 'completed' ? 'ok' : 'muted'}>{x.status}</Pill></td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
        </div>
    );
}

/** Opens a PDF served by an existing endpoint (payslip, e-contract) in a new tab. */
function PdfLink({ url, label }: { url: string; label: string }) {
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);
    const open = async () => {
        setBusy(true);
        setErr(null);
        try {
            const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const hotel = activeHotelId();
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, ...(hotel ? { 'X-Hotel-Id': hotel } : {}) } });
            if (!res.ok) throw new Error(res.statusText);
            const href = URL.createObjectURL(await res.blob());
            window.open(href, '_blank');
            setTimeout(() => URL.revokeObjectURL(href), 60_000);
        } catch (e) {
            setErr(String((e as Error).message));
        } finally {
            setBusy(false);
        }
    };
    return (
        <span className="inline-flex flex-col">
            <button type="button" className="btn-secondary inline-flex min-h-[44px] items-center gap-1 text-sm" onClick={open} disabled={busy}>{label}</button>
            {err && <span className="text-sm text-red-700" role="alert">{err}</span>}
        </span>
    );
}

// ---------------- Pay ----------------
function SalaryChart({ rows, t }: { rows: Data[]; t: T }) {
    if (rows.length < 1) return null;
    const max = Math.max(...rows.map((r) => r.to));
    const w = 100 / rows.length;
    return (
        <svg role="img" aria-label={t('profile.pay.chart_label')} viewBox="0 0 100 40" preserveAspectRatio="none" className="mb-3 h-24 w-full rounded border border-slate-200 bg-slate-50">
            {rows.map((r, i) => {
                const h = Math.max(2, (r.to / max) * 36);
                return <rect key={r.id} x={i * w + w * 0.15} y={40 - h} width={w * 0.7} height={h} fill="#1d4e89" />;
            })}
        </svg>
    );
}

export function Pay({ d, t }: { d: Data; t: T }) {
    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label={t('profile.pay.base')} value={money(d.base_salary)} />
                <Stat label={t('profile.pay.ytd_net', { year: d.ytd.year })} value={money(d.ytd.net)} sub={t('profile.pay.ytd_gross', { amount: money(d.ytd.gross) })} />
                <Stat label={t('profile.pay.paid')} value={money(d.paid.amount)} sub={d.last_payout ? t('profile.pay.last_payout', { status: t(`profile.payout.${d.last_payout.status}`), period: d.last_payout.period ?? '—' }) : t('profile.pay.no_payout')} />
                <Stat label={t('profile.pay.owing')} value={money(d.owing.advances_balance + d.owing.unpaid_net)} sub={t('profile.pay.owing_split', { advances: money(d.owing.advances_balance), net: money(d.owing.unpaid_net) })} />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title={t('profile.pay.allowances')} empty={t('profile.none')}>
                    {(d.allowances.length > 0 || d.pay_elements.length > 0) && (
                        <ul className="space-y-1 text-sm">
                            {d.allowances.map((a: Data, i: number) => <li key={`a${i}`} className="flex justify-between gap-3"><span>{a.name}</span><span>{money(a.amount)}</span></li>)}
                            {d.pay_elements.map((p: Data) => <li key={p.id} className="flex justify-between gap-3"><span>{p.name} <span className="text-slate-600">({t(`profile.element.${p.kind}`)})</span></span><span>{money(p.amount)}</span></li>)}
                        </ul>
                    )}
                </Card>
                <Card title={t('profile.pay.owing_title')} empty={t('profile.pay.nothing_owing')}>
                    {d.owing.advances_balance + d.owing.unpaid_net + d.owing.payout_pending.length > 0 && (
                        <ul className="space-y-1 text-sm">
                            {d.owing.advances_balance > 0 && <li className="flex justify-between gap-3"><span>{t('profile.pay.advances_balance')}</span><span>{money(d.owing.advances_balance)}</span></li>}
                            {d.owing.unpaid_payslips.map((p: Data) => <li key={p.id} className="flex justify-between gap-3"><span>{t('profile.pay.unpaid_net', { period: p.period })}</span><span>{money(p.net)}</span></li>)}
                            {d.owing.payout_pending.map((p: Data) => <li key={p.id} className="flex justify-between gap-3"><span>{t('profile.pay.payout_item', { status: t(`profile.payout.${p.status}`) })}</span><span>{money(p.amount)}</span></li>)}
                        </ul>
                    )}
                </Card>
            </div>
            <Card title={t('profile.pay.history')} empty={t('profile.none')}>
                {d.salary_history.length > 0 && (
                    <>
                        <SalaryChart rows={d.salary_history} t={t} />
                        <Table head={[t('profile.f.date'), t('profile.pay.from'), t('profile.pay.to'), '%', t('profile.pay.reason'), t('profile.pay.approver')]}>
                            {[...d.salary_history].reverse().map((h: Data) => (
                                <tr key={h.id}>
                                    <td>{date(h.date)}</td><td>{money(h.from)}</td><td className="font-medium">{money(h.to)}</td>
                                    <td>{h.percent == null ? '—' : `${h.percent > 0 ? '+' : ''}${h.percent}%`}</td><td>{h.reason ?? t(`profile.source.${h.source}`)}</td><td>{h.approved_by ?? '—'}</td>
                                </tr>
                            ))}
                        </Table>
                    </>
                )}
            </Card>
            <Card title={t('profile.pay.payslips')} empty={t('profile.none')}>
                {d.payslips.length > 0 && (
                    <Table head={[t('profile.pay.period'), t('profile.pay.gross'), t('profile.pay.deductions'), t('profile.pay.net'), t('profile.f.status'), '']}>
                        {d.payslips.map((p: Data) => (
                            <tr key={p.id}>
                                <td>{p.period}</td><td>{money(p.gross)}</td><td>{money(p.deductions)}</td><td className="font-medium">{money(p.net)}</td>
                                <td><Pill tone={p.run_status === 'paid' ? 'ok' : 'muted'}>{p.run_status}</Pill></td><td><PdfLink url={p.pdf_url} label={t('profile.pdf')} /></td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
            <Card title={t('profile.pay.advances')} empty={t('profile.none')}>
                {d.advances.length > 0 && (
                    <Table head={[t('profile.f.kind'), t('profile.f.date'), t('profile.pay.amount'), t('profile.pay.monthly'), t('profile.pay.balance'), t('profile.f.status')]}>
                        {d.advances.map((a: Data) => (
                            <tr key={a.id}><td>{a.kind}</td><td>{date(a.granted_on)}</td><td>{money(a.amount)}</td><td>{money(a.monthly_deduction)}</td><td className="font-medium">{money(a.balance)}</td><td>{a.status}</td></tr>
                        ))}
                    </Table>
                )}
            </Card>
        </div>
    );
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="card p-4">
            <p className="text-sm text-slate-600">{label}</p>
            <p className="text-xl font-semibold text-slate-900">{value}</p>
            {sub && <p className="mt-1 text-sm text-slate-600">{sub}</p>}
        </div>
    );
}

// ---------------- Time ----------------
const hhmm = (m: number) => `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`;
export function Time({ d, t }: { d: Data; t: T }) {
    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <Stat label={t('profile.time.leave_balance')} value={t('hr.emp.days', { count: d.leave_balance })} />
                <Stat label={t('profile.time.late')} value={String(d.late_count)} sub={t('profile.time.last30')} />
                <Stat label={t('profile.time.missing_out')} value={String(d.missing_clock_out.length)} sub={d.missing_clock_out.map((x: string) => formatDate(x)).join(', ')} />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title={t('profile.time.shifts')} empty={t('profile.none')}>
                    {d.shifts.length > 0 && <ul className="space-y-1 text-sm">{d.shifts.map((s: Data, i: number) => <li key={i} className="flex justify-between gap-3"><span>{date(s.date)}</span><span>{s.starts_at}–{s.ends_at}{s.role ? ` · ${s.role}` : ''}</span></li>)}</ul>}
                </Card>
                <Card title={t('profile.time.leave_ledger')} empty={t('profile.none')}>
                    {d.leave_ledger.length > 0 && <ul className="space-y-1 text-sm">{d.leave_ledger.map((l: Data, i: number) => <li key={i} className="flex justify-between gap-3"><span>{l.leave_type}</span><span>{t('hr.emp.days', { count: l.days })}</span></li>)}</ul>}
                </Card>
            </div>
            <Card title={t('profile.time.overtime')} empty={t('profile.none')}>
                {d.overtime_by_month.length > 0 && (
                    <Table head={[t('profile.time.month'), t('profile.time.ot_approved'), t('profile.time.ot_pending')]}>
                        {d.overtime_by_month.map((o: Data) => <tr key={o.month}><td>{o.month}</td><td>{o.approved_hours} h</td><td>{o.pending_hours} h</td></tr>)}
                    </Table>
                )}
            </Card>
            <Card title={t('profile.time.attendance')} empty={t('profile.none')}>
                {d.attendance.length > 0 && (
                    <Table head={[t('profile.f.date'), t('profile.time.in'), t('profile.time.out'), t('profile.time.worked'), t('profile.time.ot')]}>
                        {d.attendance.map((a: Data) => (
                            <tr key={a.date + a.clock_in}>
                                <td>{date(a.date)}</td><td>{a.clock_in ? formatDateTime(a.clock_in).slice(-5) : '—'}</td>
                                <td>{a.clock_out ? formatDateTime(a.clock_out).slice(-5) : <Pill tone="warn">{t('profile.time.no_out')}</Pill>}</td>
                                <td>{hhmm(a.worked_minutes)}</td><td>{a.overtime_minutes ? hhmm(a.overtime_minutes) : '—'}</td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
            <Card title={t('profile.time.leaves')} empty={t('profile.none')}>
                {d.leaves.length > 0 && (
                    <Table head={[t('profile.f.kind'), t('profile.time.from'), t('profile.time.to'), t('profile.time.days'), t('profile.f.status')]}>
                        {d.leaves.map((l: Data) => <tr key={l.id}><td>{l.type}</td><td>{date(l.start_date)}</td><td>{date(l.end_date)}</td><td>{l.days}</td><td><Badge s={l.status} /></td></tr>)}
                    </Table>
                )}
            </Card>
        </div>
    );
}

// ---------------- Documents ----------------
export function Documents({ d, t, onUploaded }: { d: Data; t: T; onUploaded: () => void }) {
    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap gap-2">
                <OpsPrintButton path={d.id_card_print} label={t('profile.docs.id_card')} className="btn-secondary inline-flex min-h-[44px] items-center gap-1 text-sm" />
            </div>
            <Card title={t('profile.docs.files')} empty={t('profile.none')}>
                {d.documents.length > 0 && (
                    <Table head={[t('profile.docs.title'), t('profile.f.kind'), t('profile.docs.expires'), '']}>
                        {d.documents.map((x: Data) => (
                            <tr key={x.id}>
                                <td>{x.title}</td><td>{x.type}</td><td>{x.expires_on ? <><span className="mr-2">{date(x.expires_on)}</span><ExpiryPill t={t} e={x.expiry} days={x.days_left} /></> : '—'}</td>
                                <td><a className="btn-secondary inline-flex min-h-[44px] items-center text-sm" href={x.url}>{t('profile.download')}</a></td>
                            </tr>
                        ))}
                    </Table>
                )}
                {d.can_upload && <Upload api={d.upload_api} t={t} onDone={onUploaded} />}
            </Card>
            <Card title={t('profile.docs.econtracts')} empty={t('profile.none')}>
                {d.econtracts.length > 0 && <ul className="space-y-2 text-sm">{d.econtracts.map((c: Data) => <li key={c.id} className="flex flex-wrap items-center justify-between gap-2"><span>{c.number} · {c.kind} · <Pill tone={c.status === 'signed' || c.status === 'sealed' ? 'ok' : 'muted'}>{c.status}</Pill></span><PdfLink url={c.pdf_url} label={t('profile.pdf')} /></li>)}</ul>}
            </Card>
            <Card title={t('profile.docs.issued')} empty={t('profile.none')}>
                {d.issued.length > 0 && (
                    <Table head={[t('profile.docs.type'), t('profile.f.number'), t('profile.f.date'), t('profile.f.status'), '']}>
                        {d.issued.map((x: Data) => (
                            <tr key={x.id}>
                                <td>{t(`profile.letter.${x.type}`)}</td><td className="font-mono text-sm">{x.number}</td><td>{formatDate(x.issued_at)}</td>
                                <td><Pill tone={x.status === 'valid' ? 'ok' : 'muted'}>{x.status}</Pill></td>
                                <td>{x.print && <OpsPrintButton path={x.print} label={t('profile.pdf')} className="btn-secondary inline-flex min-h-[44px] items-center gap-1 text-sm" />}</td>
                            </tr>
                        ))}
                    </Table>
                )}
            </Card>
        </div>
    );
}

function Upload({ api: path, t, onDone }: { api: string; t: T; onDone: () => void }) {
    const [busy, setBusy] = useStateLocal(false);
    const [err, setErr] = useStateLocal<string | null>(null);
    const [form, setForm] = useStateLocal({ type: 'contract', title: '', expires_on: '' });
    const [file, setFile] = useStateLocal<File | null>(null);
    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!file) return;
        setBusy(true);
        setErr(null);
        try {
            const fd = new FormData();
            fd.append('type', form.type);
            fd.append('title', form.title);
            if (form.expires_on) fd.append('expires_on', form.expires_on);
            fd.append('file', file);
            await uploadForm(path, fd);
            setFile(null);
            setForm({ type: 'contract', title: '', expires_on: '' });
            onDone();
        } catch (x) {
            setErr(String((x as Error).message ?? x));
        } finally {
            setBusy(false);
        }
    }
    return (
        <form onSubmit={submit} className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-5">
            <label className="block text-sm"><span className="mb-1 block text-slate-600">{t('profile.docs.type')}</span>
                <select className="input" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                    {['contract', 'id', 'diploma', 'medical', 'permit', 'other'].map((k) => <option key={k} value={k}>{k}</option>)}
                </select></label>
            <label className="block text-sm"><span className="mb-1 block text-slate-600">{t('profile.docs.title')}</span><input className="input" required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} /></label>
            <label className="block text-sm"><span className="mb-1 block text-slate-600">{t('profile.docs.expires')}</span><input type="date" className="input" value={form.expires_on} onChange={(e) => setForm({ ...form, expires_on: e.target.value })} /></label>
            <label className="block text-sm"><span className="mb-1 block text-slate-600">{t('profile.docs.file')}</span><input type="file" required className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></label>
            <div className="flex items-end"><button className="btn-primary min-h-[44px] w-full" disabled={busy}>{t('profile.docs.upload')}</button></div>
            {err && <p className="text-sm text-red-700 sm:col-span-2 lg:col-span-5" role="alert">{err}</p>}
        </form>
    );
}


async function uploadForm(path: string, fd: FormData) {
    const xsrf = decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
    const hotel = activeHotelId();
    const res = await fetch(`/api/v1/${path}`, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf, ...(hotel ? { 'X-Hotel-Id': hotel } : {}) },
    });
    if (!res.ok) {
        const b = await res.json().catch(() => ({}));
        throw new Error(b.detail ?? b.message ?? res.statusText);
    }
}

// ---------------- Growth ----------------
export function Growth({ d, t }: { d: Data; t: T }) {
    const noAccess = (x: unknown) => x === null;
    return (
        <div className="grid gap-4">
            {!noAccess(d.skills) && (
                <Card title={t('profile.growth.skills')} empty={t('profile.none')}>
                    {d.skills.length > 0 && <ul className="grid gap-2 sm:grid-cols-2">{d.skills.map((s: Data, i: number) => (
                        <li key={i} className="flex items-center justify-between gap-3 text-sm"><span>{s.skill}</span>
                            <span className="flex gap-0.5" role="img" aria-label={t('profile.growth.level', { level: s.level })}>{[1, 2, 3, 4, 5].map((n) => <span key={n} className={`h-3 w-5 rounded-sm ${n <= s.level ? 'bg-navy-700' : 'bg-slate-300'}`} style={n <= s.level ? { background: '#1d4e89' } : undefined} />)}</span></li>
                    ))}</ul>}
                </Card>
            )}
            {!noAccess(d.certificates) && (
                <Card title={t('profile.growth.certificates')} empty={t('profile.none')}>
                    {d.certificates.length > 0 && (
                        <Table head={[t('profile.docs.title'), t('profile.growth.issued'), t('profile.docs.expires'), t('profile.f.status')]}>
                            {d.certificates.map((c: Data) => <tr key={c.id}><td>{c.name}</td><td>{date(c.issued_on)}</td><td>{date(c.expires_on)}</td><td><ExpiryPill t={t} e={c.expiry} days={c.days_left} /></td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
            {!noAccess(d.training) && (
                <Card title={t('profile.growth.training')} empty={t('profile.none')}>
                    {d.training.length > 0 && (
                        <Table head={[t('profile.growth.course'), t('profile.f.date'), t('profile.growth.attendance'), t('profile.growth.result')]}>
                            {d.training.map((x: Data, i: number) => <tr key={i}><td>{x.course}</td><td>{date(x.starts_on)}</td><td>{x.attendance ?? '—'}</td><td>{x.result ?? '—'}{x.score != null ? ` (${x.score})` : ''}</td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
            {!noAccess(d.reviews) && (
                <Card title={t('profile.growth.reviews')} empty={t('profile.none')}>
                    {d.reviews.length > 0 && (
                        <Table head={[t('profile.growth.cycle'), t('profile.f.status'), t('profile.growth.self'), t('profile.growth.manager'), t('profile.growth.final')]}>
                            {d.reviews.map((r: Data) => <tr key={r.id}><td>{r.cycle}</td><td>{r.status}</td><td>{r.self_rating ?? '—'}</td><td>{r.manager_rating ?? '—'}</td><td className="font-medium">{r.calibrated_rating ?? '—'}</td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
            {!noAccess(d.objectives) && (
                <Card title={t('profile.growth.objectives')} empty={t('profile.none')}>
                    {d.objectives.length > 0 && (
                        <Table head={[t('profile.docs.title'), t('profile.growth.weight'), t('profile.growth.self'), t('profile.growth.manager')]}>
                            {d.objectives.map((o: Data) => <tr key={o.id}><td>{o.title}</td><td>{o.weight}%</td><td>{o.self_score ?? '—'}</td><td>{o.manager_score ?? '—'}</td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
            {!noAccess(d.feedback) && (
                <Card title={t('profile.growth.feedback')} empty={t('profile.none')}>
                    {d.feedback.length > 0 && <ul className="space-y-2 text-sm">{d.feedback.map((f: Data) => <li key={f.id}><Pill tone={f.sentiment === 'positive' ? 'ok' : f.sentiment === 'negative' ? 'bad' : 'muted'}>{f.sentiment}</Pill> {f.comment} <span className="text-slate-600">· {date(f.received_on)}</span></li>)}</ul>}
                </Card>
            )}
        </div>
    );
}

// ---------------- Conduct ----------------
export function Conduct({ d, t }: { d: Data; t: T }) {
    return (
        <div className="grid gap-4">
            {d.warnings !== null && (
                <Card title={t('profile.conduct.warnings')} empty={t('profile.none')}>
                    {d.warnings.length > 0 && (
                        <Table head={[t('profile.f.date'), t('profile.f.kind'), t('profile.pay.reason'), t('profile.f.status')]}>
                            {d.warnings.map((w: Data) => <tr key={w.id}><td>{date(w.incident_date)}</td><td>{w.kind}</td><td>{w.reason}</td><td><Pill tone={w.acknowledged_at ? 'ok' : 'warn'}>{w.status}</Pill></td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
            {d.tasks !== null && (
                <Card title={t('profile.conduct.tasks')} empty={t('profile.none')}>
                    {d.tasks.length > 0 && (
                        <Table head={[t('profile.docs.title'), t('profile.conduct.priority'), t('profile.conduct.due'), t('profile.f.status')]}>
                            {d.tasks.map((x: Data) => <tr key={x.id}><td>{x.title}</td><td>{x.priority}</td><td>{x.due_at ? formatDateTime(x.due_at) : '—'}</td><td><Pill tone={x.overdue ? 'bad' : 'muted'}>{x.overdue ? t('profile.overdue') : x.status}</Pill></td></tr>)}
                        </Table>
                    )}
                </Card>
            )}
        </div>
    );
}

// ---------------- Assets ----------------
export function Assets({ d, t }: { d: Data; t: T }) {
    const rows = (list: Data[]) => (
        <Table head={[t('profile.assets.item'), t('profile.assets.qty'), t('profile.assets.issued_on'), t('profile.assets.due_back'), t('profile.f.status')]}>
            {list.map((r) => (
                <tr key={r.id}>
                    <td>{r.name}{r.serial ? ` · ${r.serial}` : ''}{r.size ? ` · ${r.size}` : ''}</td><td>{r.quantity}</td><td>{date(r.issued_on)}</td><td>{date(r.due_back_on)}</td>
                    <td>{r.overdue ? <Pill tone="bad">{t('profile.overdue')}</Pill> : <Pill tone={r.status === 'issued' ? 'warn' : 'ok'}>{r.status}</Pill>}</td>
                </tr>
            ))}
        </Table>
    );
    return (
        <div className="grid gap-4">
            <Card title={t('profile.assets.current', { count: d.current.length })} empty={t('profile.none')}>{d.current.length > 0 && rows(d.current)}</Card>
            <Card title={t('profile.assets.history')} empty={t('profile.none')}>{d.history.length > 0 && rows(d.history)}</Card>
        </div>
    );
}

// ---------------- Timeline ----------------
export function Timeline({ d, t }: { d: Data; t: T }) {
    if (d.events.length === 0) return <p className="text-sm text-slate-600">{t('profile.none')}</p>;
    return (
        <ol className="space-y-3 border-l-2 border-slate-300 pl-4">
            {d.events.map((e: Data, i: number) => (
                <li key={i}>
                    <p className="text-sm text-slate-600">{formatDate(e.date)} · {t(`profile.tab.${e.section}`)}</p>
                    <p className="text-base text-slate-900">{t(`profile.tl.${e.kind}`, { ...e.params, to: typeof e.params.to === 'number' ? formatCurrency(e.params.to) : e.params.to, net: typeof e.params.net === 'number' ? formatCurrency(e.params.net) : e.params.net })}</p>
                </li>
            ))}
        </ol>
    );
}
