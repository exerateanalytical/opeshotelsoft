import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';
import { useT } from '@/lib/i18n';
import { Banner, Pill, Section, TalentTabs, certTone, msg, tget, tsend, unwrap } from './TalentShared';

interface Matrix { skills: { id: number; name: string }[]; rows: { employee_id: number; name: string; cells: Record<string, { level: number | null; required: number | null; gap: boolean }> }[] }
interface Gap { department: string; employees: number; skill_gap_count: number; cert_gap_count: number; skill_gaps: { employee: string; skill: string; have: number; required: number }[]; cert_gaps: { employee: string; certificate: string; status: string }[] }
interface Cert { id: number; employee: string; type: string; issued_on: string; expires_on: string | null; status: string }
interface Catalogue { skills: { id: number; name: string }[]; cert_types: { id: number; name: string; mandatory: boolean }[] }
interface Emp { id: number; first_name: string; last_name: string }

export default function TalentSkills() {
    const t = useT();
    const main = useApi<Matrix>('hr/talent/matrix');
    const m = main.data;
    const [gaps, setGaps] = useState<Gap[]>([]);
    const [certs, setCerts] = useState<Cert[]>([]);
    const [cat, setCat] = useState<Catalogue>({ skills: [], cert_types: [] });
    const [emps, setEmps] = useState<Emp[]>([]);
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [skill, setSkill] = useState({ code: '', name: '' });
    const [ct, setCt] = useState({ code: '', name: '', validity_months: '12', mandatory: true });
    const [cf, setCf] = useState({ employee_id: '', cert_type_id: '', issued_on: '' });
    const [sf, setSf] = useState({ employee_id: '', skill_id: '', level: '3' });

    const loadAux = () => {
        tget<{ departments: Gap[] }>('gaps').then((r) => setGaps(r.departments)).catch(() => undefined);
        tget<Cert[]>('certifications').then(setCerts).catch(() => undefined);
        tget<Catalogue>('catalogue').then(setCat).catch(() => undefined);
        api<Emp[] | { data: Emp[] }>('hr/employees?status=active').then((r) => setEmps(unwrap(r))).catch(() => undefined);
    };
    const load = () => { main.reload(); loadAux(); };
    useEffect(loadAux, []);
    const run = async (fn: () => Promise<unknown>, done?: string) => { setErr(null); setOk(null); try { await fn(); if (done) setOk(done); load(); } catch (e) { setErr(msg(e)); } };

    return (
        <AppLayout title={t('talent.skills.title')} subtitle={t('talent.skills.subtitle')}>
            <Head title={t('talent.skills.title')} />
            <TalentTabs active="/hr/talent/skills" />
            <Banner error={err} ok={ok} />
            <QueryStatus query={main} variant="table" />

            <Section title={t('talent.matrix')}>
                {m && m.rows.length === 0 && <EmptyFor kind="staff" />}
                <div className="table-wrap"><table className="table-auto w-full text-sm">
                    <thead><tr className="text-left"><th className="p-2">{t('talent.employee')}</th>{m?.skills.map((s) => <th key={s.id} className="p-2">{s.name}</th>)}</tr></thead>
                    <tbody>{m?.rows.map((r) => (
                        <tr key={r.employee_id} className="border-t border-slate-100"><td className="p-2">{r.name}</td>
                            {m.skills.map((s) => { const c = r.cells[s.id]; return <td key={s.id} className="p-2">{c.level ?? '-'}{c.required ? <span className={c.gap ? 'text-red-700' : 'text-slate-500'}> / {c.required}</span> : null}</td>; })}</tr>))}</tbody>
                </table></div>
            </Section>

            <Section title={t('talent.gaps')}>
                {gaps.map((g) => (
                    <div key={g.department} className="mb-3 text-sm">
                        <p className="font-semibold">{g.department} <Pill tone={g.skill_gap_count + g.cert_gap_count ? 'warn' : 'ok'}>{g.skill_gap_count + g.cert_gap_count}</Pill></p>
                        <ul className="ml-4 list-disc">
                            {g.skill_gaps.map((x, i) => <li key={`s${i}`}>{x.employee}: {x.skill} {x.have}/{x.required}</li>)}
                            {g.cert_gaps.map((x, i) => <li key={`c${i}`}>{x.employee}: {x.certificate} ({t(`talent.cert_status.${x.status}`)})</li>)}
                        </ul>
                    </div>))}
            </Section>

            <Section title={t('talent.certifications')} right={<button className="btn-secondary" onClick={() => run(() => tsend('certifications/scan'), t('talent.scan_done'))}>{t('talent.scan')}</button>}>
                <div className="table-wrap"><table className="table-auto w-full text-sm"><tbody>{certs.map((c) => (
                    <tr key={c.id} className="border-t border-slate-100"><td className="p-2">{c.employee}</td><td>{c.type}</td><td>{c.expires_on ?? '-'}</td><td><Pill tone={certTone(c.status)}>{t(`talent.cert_status.${c.status}`)}</Pill></td></tr>))}</tbody></table></div>
            </Section>

            <Section title={t('talent.setup')}>
                <div className="grid gap-4 text-sm md:grid-cols-2">
                    <div className="flex flex-wrap gap-2">
                        <input className="input w-28" placeholder={t('talent.code')} aria-label={t('talent.code')} value={skill.code} onChange={(e) => setSkill({ ...skill, code: e.target.value })} />
                        <input className="input" placeholder={t('talent.skill_name')} aria-label={t('talent.skill_name')} value={skill.name} onChange={(e) => setSkill({ ...skill, name: e.target.value })} />
                        <button className="btn-secondary" disabled={!skill.code || !skill.name} onClick={() => run(() => tsend('skills', skill))}>{t('talent.add_skill')}</button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <input className="input w-28" placeholder={t('talent.code')} aria-label={t('talent.code')} value={ct.code} onChange={(e) => setCt({ ...ct, code: e.target.value })} />
                        <input className="input" placeholder={t('talent.cert_name')} aria-label={t('talent.cert_name')} value={ct.name} onChange={(e) => setCt({ ...ct, name: e.target.value })} />
                        <input className="input w-24" type="number" aria-label={t('ui2.hr.validity_months')} value={ct.validity_months} onChange={(e) => setCt({ ...ct, validity_months: e.target.value })} />
                        <label className="flex items-center gap-1"><input type="checkbox" checked={ct.mandatory} onChange={(e) => setCt({ ...ct, mandatory: e.target.checked })} />{t('talent.mandatory')}</label>
                        <button className="btn-secondary" disabled={!ct.code || !ct.name} onClick={() => run(() => tsend('cert-types', { ...ct, validity_months: Number(ct.validity_months) }))}>{t('talent.add_cert_type')}</button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <select className="select" aria-label={t('talent.employee')} value={sf.employee_id} onChange={(e) => setSf({ ...sf, employee_id: e.target.value })}><option value="" />{emps.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}</select>
                        <select className="select" aria-label={t('talent.skill_name')} value={sf.skill_id} onChange={(e) => setSf({ ...sf, skill_id: e.target.value })}><option value="" />{cat.skills.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
                        <select className="select" aria-label={t('talent.set_level')} value={sf.level} onChange={(e) => setSf({ ...sf, level: e.target.value })}>{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select>
                        <button className="btn-secondary" disabled={!sf.employee_id || !sf.skill_id} onClick={() => run(() => tsend(`employees/${sf.employee_id}/skills`, { skill_id: Number(sf.skill_id), level: Number(sf.level) }))}>{t('talent.set_level')}</button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <select className="select" aria-label={t('talent.employee')} value={cf.employee_id} onChange={(e) => setCf({ ...cf, employee_id: e.target.value })}><option value="" />{emps.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}</select>
                        <select className="select" aria-label={t('talent.cert_name')} value={cf.cert_type_id} onChange={(e) => setCf({ ...cf, cert_type_id: e.target.value })}><option value="" />{cat.cert_types.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
                        <input className="input" type="date" aria-label={t('ui2.talent.issued_on')} value={cf.issued_on} onChange={(e) => setCf({ ...cf, issued_on: e.target.value })} />
                        <button className="btn-secondary" disabled={!cf.employee_id || !cf.cert_type_id || !cf.issued_on} onClick={() => run(() => tsend(`employees/${cf.employee_id}/certifications`, { cert_type_id: Number(cf.cert_type_id), issued_on: cf.issued_on }))}>{t('talent.add_certification')}</button>
                    </div>
                </div>
            </Section>
        </AppLayout>
    );
}
