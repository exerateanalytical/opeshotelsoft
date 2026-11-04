import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { api, useApi } from '@/lib/api';
import { EmptyFor } from '@/Components/ui/EmptyStates';
import QueryStatus from '@/Components/ui/QueryStatus';
import { useT } from '@/lib/i18n';
import { Banner, Pill, Section, TalentTabs, money, msg, tget, tsend, unwrap } from './TalentShared';

interface Course { id: number; title: string; default_cost: number; duration_hours: number }
interface Att { id: number; employee_id: number; attendance: string; result: string | null; cost: number }
interface Session { id: number; course_id: number; starts_on: string; status: string; cost: number; attendances: Att[] }
interface Emp { id: number; first_name: string; last_name: string }

export default function TalentTraining() {
    const t = useT();
    const main = useApi<Course[]>('hr/talent/courses');
    const courses = main.data ?? [];
    const [sessions, setSessions] = useState<Session[]>([]);
    const [emps, setEmps] = useState<Emp[]>([]);
    const [cost, setCost] = useState<{ total: number; by_department: { department: string; cost: number; attendances: number }[] } | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [course, setCourse] = useState({ title: '', duration_hours: '4', default_cost: '0' });
    const [sess, setSess] = useState({ course_id: '', starts_on: '' });
    const [enroll, setEnroll] = useState<Record<number, string>>({});

    const loadAux = () => {
        tget<Session[]>('sessions').then(setSessions).catch(() => undefined);
        tget<typeof cost>('training-cost').then(setCost).catch(() => undefined);
        api<Emp[] | { data: Emp[] }>('hr/employees?status=active').then((r) => setEmps(unwrap(r))).catch(() => undefined);
    };
    const load = () => { main.reload(); loadAux(); };
    useEffect(loadAux, []);
    const run = async (fn: () => Promise<unknown>) => { setErr(null); try { await fn(); load(); } catch (e) { setErr(msg(e)); } };
    const name = (id: number) => { const e = emps.find((x) => x.id === id); return e ? `${e.first_name} ${e.last_name}` : `#${id}`; };
    const title = (id: number) => courses.find((c) => c.id === id)?.title ?? `#${id}`;

    return (
        <AppLayout title={t('talent.training.title')} subtitle={t('talent.training.subtitle')}>
            <Head title={t('talent.training.title')} />
            <TalentTabs active="/hr/talent/training" />
            <Banner error={err} />
            <QueryStatus query={main} variant="list" />
            <Section title={t('talent.courses')}>
                <div className="mb-3 flex flex-wrap gap-2 text-sm">
                    <input className="input" placeholder={t('talent.course_title')} aria-label={t('talent.course_title')} value={course.title} onChange={(e) => setCourse({ ...course, title: e.target.value })} />
                    <input className="input w-24" type="number" aria-label={t('ui2.hr.course_hours')} value={course.duration_hours} onChange={(e) => setCourse({ ...course, duration_hours: e.target.value })} />
                    <input className="input w-32" type="number" aria-label={t('ui2.hr.course_cost')} value={course.default_cost} onChange={(e) => setCourse({ ...course, default_cost: e.target.value })} />
                    <button className="btn-primary" disabled={!course.title} onClick={() => run(() => tsend('courses', { title: course.title, duration_hours: Number(course.duration_hours), default_cost: Number(course.default_cost) }))}>{t('talent.add_course')}</button>
                </div>
                {main.data && courses.length === 0 && <EmptyFor kind="generic" />}
                <ul className="text-sm">{courses.map((c) => <li key={c.id} className="border-t border-slate-100 py-1">{c.title} - {c.duration_hours} h - {money(c.default_cost)} XAF</li>)}</ul>
            </Section>
            <Section title={t('talent.sessions')}>
                <div className="mb-3 flex flex-wrap gap-2 text-sm">
                    <select className="select" aria-label={t('ui2.hr.course')} value={sess.course_id} onChange={(e) => setSess({ ...sess, course_id: e.target.value })}><option value="" />{courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select>
                    <input className="input" type="date" aria-label={t('ui2.hr.session_start')} value={sess.starts_on} onChange={(e) => setSess({ ...sess, starts_on: e.target.value })} />
                    <button className="btn-primary" disabled={!sess.course_id || !sess.starts_on} onClick={() => run(() => tsend('sessions', { course_id: Number(sess.course_id), starts_on: sess.starts_on }))}>{t('talent.add_session')}</button>
                </div>
                {sessions.map((s) => (
                    <div key={s.id} className="mb-3 rounded-lg border border-slate-200 p-3 text-sm">
                        <p className="font-semibold">{title(s.course_id)} - {s.starts_on.slice(0, 10)} <Pill tone={s.status === 'done' ? 'ok' : 'muted'}>{t(`talent.session_status.${s.status}`)}</Pill> {money(s.cost)} XAF</p>
                        {s.attendances.map((a) => (
                            <p key={a.id} className="flex flex-wrap items-center gap-2 py-1">{name(a.employee_id)} <Pill tone="muted">{t(`talent.attendance.${a.attendance}`)}</Pill>{a.result && <Pill tone={a.result === 'passed' ? 'ok' : 'bad'}>{t(`talent.result.${a.result}`)}</Pill>}
                                {s.status === 'planned' && <>
                                    <button className="btn-secondary" onClick={() => run(() => tsend(`sessions/${s.id}/record`, { employee_id: a.employee_id, attendance: 'attended', result: 'passed' }))}>{t('talent.passed')}</button>
                                    <button className="btn-secondary" onClick={() => run(() => tsend(`sessions/${s.id}/record`, { employee_id: a.employee_id, attendance: 'attended', result: 'failed' }))}>{t('talent.failed')}</button>
                                    <button className="btn-secondary" onClick={() => run(() => tsend(`sessions/${s.id}/record`, { employee_id: a.employee_id, attendance: 'absent' }))}>{t('talent.absent')}</button></>}</p>))}
                        {s.status === 'planned' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                <select className="select" aria-label={t('ui2.hr.employee')} value={enroll[s.id] ?? ''} onChange={(e) => setEnroll({ ...enroll, [s.id]: e.target.value })}><option value="" />{emps.map((e) => <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>)}</select>
                                <button className="btn-secondary" disabled={!enroll[s.id]} onClick={() => run(() => tsend(`sessions/${s.id}/enroll`, { employee_ids: [Number(enroll[s.id])] }))}>{t('talent.enroll')}</button>
                                <button className="btn-primary" onClick={() => run(() => tsend(`sessions/${s.id}/complete`))}>{t('talent.complete')}</button>
                                <button className="btn-secondary" onClick={() => { if (window.confirm(t('gapd.session.cancel_confirm'))) run(() => tsend(`sessions/${s.id}/cancel`)); }}>{t('gapd.session.cancel')}</button>
                            </div>)}
                    </div>))}
            </Section>
            <Section title={t('talent.cost_report')}>
                <p className="mb-2 text-sm">{t('talent.total')}: <strong>{money(cost?.total)} XAF</strong></p>
                <ul className="text-sm">{cost?.by_department.map((d) => <li key={d.department}>{d.department}: {money(d.cost)} XAF ({d.attendances})</li>)}</ul>
            </Section>
        </AppLayout>
    );
}
