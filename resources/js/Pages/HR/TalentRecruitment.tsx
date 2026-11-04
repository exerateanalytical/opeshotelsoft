import AppLayout from '@/Layouts/AppLayout';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import { Banner, Pill, Section, TalentTabs, money, msg, tget, tsend } from './TalentShared';

interface Post { id: number; title: string; status: string; contract_kind: string; applicants_count: number; openings: number }
interface Applicant { id: number; first_name: string; last_name: string; email: string | null; phone: string | null; stage: string; talent_pool: boolean; cv_name: string | null; cover_note: string | null }
interface Score { user_id: number; name: string; average: number; recommendation: string; comment: string | null }
interface Card { overall: number | null; tally: Record<string, number>; scores: Score[]; criteria: { criterion: string; average: number }[] }
interface Detail { applicant: Applicant; notes: { id: number; body: string; kind: string; created_at: string }[]; interviews: { id: number; scheduled_at: string; criteria: string[]; scorecard: Card }[]; offers: { id: number; status: string; base_salary: number }[] }

const STAGES = ['applied', 'screening', 'interview', 'offer', 'rejected', 'withdrawn'];

export default function TalentRecruitment() {
    const t = useT();
    const me = usePage<{ auth?: { user?: { id: number } } }>().props.auth?.user?.id;
    const [posts, setPosts] = useState<Post[]>([]);
    const [apps, setApps] = useState<Applicant[]>([]);
    const [stage, setStage] = useState('');
    const [sel, setSel] = useState<Detail | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const [ok, setOk] = useState<string | null>(null);
    const [title, setTitle] = useState('');
    const [note, setNote] = useState('');
    const [when, setWhen] = useState('');
    const [salary, setSalary] = useState('');
    const [start, setStart] = useState('');
    const [marks, setMarks] = useState<Record<string, number>>({});

    const load = () => {
        tget<Post[]>('posts').then(setPosts).catch((e) => setErr(msg(e)));
        tget<Applicant[]>(`applicants${stage ? `?stage=${stage}` : ''}`).then(setApps).catch((e) => setErr(msg(e)));
    };
    useEffect(load, [stage]);
    const open = (id: number) => tget<Detail>(`applicants/${id}`).then(setSel).catch((e) => setErr(msg(e)));
    const run = async (fn: () => Promise<unknown>, done?: string) => {
        setErr(null); setOk(null);
        try { await fn(); if (done) setOk(done); load(); if (sel) open(sel.applicant.id); } catch (e) { setErr(msg(e)); }
    };

    return (
        <AppLayout title={t('talent.recruitment.title')} subtitle={t('talent.recruitment.subtitle')}>
            <Head title={t('talent.recruitment.title')} />
            <TalentTabs active="/hr/talent" />
            <Banner error={err} ok={ok} />

            <Section title={t('talent.posts')} right={<a className="inline-flex min-h-11 items-center text-sm underline" href="/careers" onClick={(e) => { e.preventDefault(); const s = (groupSlug()); if (s) window.open(`/careers/${s}`, '_blank'); }}>{t('talent.careers_page')}</a>}>
                <div className="mb-3 flex flex-wrap gap-2">
                    <input className="input" placeholder={t('talent.post_title')} aria-label={t('talent.post_title')} value={title} onChange={(e) => setTitle(e.target.value)} />
                    <button className="btn-primary" disabled={!title} onClick={() => run(async () => { await tsend('posts', { title }); setTitle(''); })}>{t('talent.add_post')}</button>
                </div>
                <div className="table-wrap"><table className="table-auto w-full text-sm">
                    <thead><tr className="text-left"><th className="p-2">{t('talent.post_title')}</th><th>{t('talent.status')}</th><th>{t('talent.applicants')}</th><th /></tr></thead>
                    <tbody>{posts.map((p) => (
                        <tr key={p.id} className="border-t border-slate-100">
                            <td className="p-2">{p.title}</td><td><Pill tone={p.status === 'open' ? 'ok' : 'muted'}>{t(`talent.post_status.${p.status}`)}</Pill></td><td>{p.applicants_count}</td>
                            <td className="text-right">{p.status !== 'open' ? <button className="btn-secondary" onClick={() => run(() => tsend(`posts/${p.id}/status`, { status: 'open' }))}>{t('talent.publish')}</button>
                                : <button className="btn-secondary" onClick={() => run(() => tsend(`posts/${p.id}/status`, { status: 'closed' }))}>{t('talent.close')}</button>}</td>
                        </tr>))}</tbody>
                </table></div>
            </Section>

            <Section title={t('talent.pipeline')} right={
                <select className="select" aria-label={t('ui2.hr.filter_stage')} value={stage} onChange={(e) => setStage(e.target.value)}>
                    <option value="">{t('talent.all_stages')}</option>{STAGES.concat('hired').map((s) => <option key={s} value={s}>{t(`talent.stage.${s}`)}</option>)}
                </select>}>
                <div className="grid gap-4 md:grid-cols-2">
                    <ul className="divide-y divide-slate-100 text-sm">
                        {apps.map((a) => (
                            <li key={a.id}><button className="flex w-full items-center justify-between py-2 text-left" onClick={() => open(a.id)}>
                                <span>{a.first_name} {a.last_name}{a.talent_pool && <Pill tone="muted">{t('talent.pool')}</Pill>}</span><Pill tone="muted">{t(`talent.stage.${a.stage}`)}</Pill>
                            </button></li>))}
                        {!apps.length && <li className="py-2 text-slate-500">{t('talent.none')}</li>}
                    </ul>
                    {sel && (
                        <div className="space-y-3 text-sm">
                            <h3 className="text-base font-semibold">{sel.applicant.first_name} {sel.applicant.last_name}</h3>
                            <p>{sel.applicant.email} {sel.applicant.phone}</p>
                            {sel.applicant.cv_name && <a className="underline" href={`/api/v1/hr/talent/applicants/${sel.applicant.id}/cv`}>{t('talent.download_cv')}</a>}
                            <div className="flex flex-wrap gap-2">
                                {STAGES.filter((s) => s !== sel.applicant.stage).map((s) => <button key={s} className="btn-secondary" onClick={() => run(() => tsend(`applicants/${sel.applicant.id}/stage`, { stage: s }))}>{t(`talent.stage.${s}`)}</button>)}
                                <button className="btn-secondary" onClick={() => run(() => tsend(`applicants/${sel.applicant.id}/pool`, { in: !sel.applicant.talent_pool }))}>{sel.applicant.talent_pool ? t('talent.pool_remove') : t('talent.pool_add')}</button>
                            </div>
                            <div className="flex gap-2"><input className="input flex-1" placeholder={t('talent.note')} aria-label={t('talent.note')} value={note} onChange={(e) => setNote(e.target.value)} />
                                <button className="btn-secondary" disabled={!note} onClick={() => run(async () => { await tsend(`applicants/${sel.applicant.id}/notes`, { body: note }); setNote(''); })}>{t('talent.add')}</button></div>
                            <ul className="space-y-1">{sel.notes.map((n) => <li key={n.id} className="text-slate-700">- {n.body}</li>)}</ul>

                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="mb-2 font-semibold">{t('talent.interviews')}</h4>
                                <div className="mb-2 flex flex-wrap gap-2"><input type="datetime-local" className="input" aria-label={t('ui2.hr.interview_at')} value={when} onChange={(e) => setWhen(e.target.value)} />
                                    <button className="btn-secondary" disabled={!when || !me} onClick={() => run(() => tsend(`applicants/${sel.applicant.id}/interviews`, { scheduled_at: when.replace('T', ' '), panel: [me] }))}>{t('talent.schedule_me')}</button></div>
                                {sel.interviews.map((i) => (
                                    <div key={i.id} className="mb-2 border-t border-slate-100 pt-2">
                                        <p>{i.scheduled_at.slice(0, 16).replace('T', ' ')} - {t('talent.overall')}: {i.scorecard.overall ?? '-'} ({i.scorecard.tally.hire}/{i.scorecard.tally.maybe}/{i.scorecard.tally.no})</p>
                                        <div className="grid grid-cols-2 gap-2">{i.criteria.map((c) => (
                                            <label key={c}>{c}<select className="select w-full" value={marks[`${i.id}-${c}`] ?? ''} onChange={(e) => setMarks({ ...marks, [`${i.id}-${c}`]: Number(e.target.value) })}>
                                                <option value="" />{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select></label>))}</div>
                                        <div className="mt-2 flex gap-2">{['hire', 'maybe', 'no'].map((r) => (
                                            <button key={r} className="btn-secondary" onClick={() => run(() => tsend(`interviews/${i.id}/scores`, { recommendation: r, scores: i.criteria.filter((c) => marks[`${i.id}-${c}`]).map((c) => ({ criterion: c, score: marks[`${i.id}-${c}`] })) }), t('talent.score_saved'))}>{t(`talent.rec.${r}`)}</button>))}</div>
                                    </div>))}
                            </div>

                            <div className="rounded-lg border border-slate-200 p-3">
                                <h4 className="mb-2 font-semibold">{t('talent.offers')}</h4>
                                {sel.offers.map((o) => (
                                    <p key={o.id} className="mb-1">{money(o.base_salary)} XAF <Pill tone={o.status === 'accepted' ? 'ok' : 'muted'}>{t(`talent.offer_status.${o.status}`)}</Pill>
                                        {o.status === 'draft' || o.status === 'sent' ? <button className="btn-secondary ml-2" onClick={() => run(() => tsend(`offers/${o.id}/send`), t('talent.offer_sent'))}>{t('talent.send_offer')}</button> : null}</p>))}
                                <div className="flex flex-wrap gap-2"><input className="input" type="number" placeholder={t('talent.salary')} aria-label={t('talent.salary')} value={salary} onChange={(e) => setSalary(e.target.value)} />
                                    <input className="input" type="date" aria-label={t('ui2.hr.start_date')} value={start} onChange={(e) => setStart(e.target.value)} />
                                    <button className="btn-primary" disabled={!salary || !start} onClick={() => run(() => tsend(`applicants/${sel.applicant.id}/offers`, { base_salary: Number(salary), start_date: start }))}>{t('talent.make_offer')}</button></div>
                            </div>
                        </div>)}
                </div>
            </Section>
        </AppLayout>
    );
}

/** Group slug for the public careers link, taken from the shared network prop. */
function groupSlug(): string | undefined {
    return (window.history.state?.page?.props?.network?.slug as string | undefined);
}
