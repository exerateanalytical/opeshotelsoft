import { useState, type FormEvent } from 'react';
import { t } from './i18n';
import PublicLayout, { Notice, publicApi, type PublicProps } from './PublicLayout';

const L = {
    fr: { title: 'Votre avis sur votre séjour', rating: 'Note', comment: 'Commentaire', send: 'Envoyer mon avis', thanks: 'Merci, votre avis a bien été reçu.' },
    en: { title: 'Your review of your stay', rating: 'Rating', comment: 'Comment', send: 'Send my review', thanks: 'Thank you, your review was received.' },
};

/** Minimal review page: confirmation number + last name, then a rating and a comment. */
export default function Review(p: PublicProps) {
    const d = t(p.lang);
    const x = L[p.lang] ?? L.fr;
    const [f, setF] = useState({ confirmation: p.query.c ?? '', last_name: '', rating: 5, comment: '' });
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const [busy, setBusy] = useState(false);

    const submit = async (e: FormEvent) => {
        e.preventDefault(); setBusy(true); setError('');
        try { await publicApi(p.group.slug, '/manage/review', { confirmation: f.confirmation.trim(), last_name: f.last_name.trim(), rating: f.rating, comment: f.comment || null }); setDone(true); }
        catch (err) { setError((err as Error).message); }
        setBusy(false);
    };

    return (
        <PublicLayout p={p} title={x.title}>
            <div className="max-w-xl space-y-4">
                {done ? <Notice kind="ok">{x.thanks}</Notice> : (
                    <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
                        {error && <Notice kind="error">{error}</Notice>}
                        <div><label className="label" htmlFor="c">{d.confirmationNo}</label><input id="c" className="input" value={f.confirmation} onChange={(e) => setF({ ...f, confirmation: e.target.value })} required /></div>
                        <div><label className="label" htmlFor="l">{d.lastName}</label><input id="l" className="input" value={f.last_name} onChange={(e) => setF({ ...f, last_name: e.target.value })} required /></div>
                        <div><label className="label" htmlFor="r">{x.rating}</label>
                            <select id="r" className="input" value={f.rating} onChange={(e) => setF({ ...f, rating: +e.target.value })}>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>{n} / 5</option>)}</select></div>
                        <div><label className="label" htmlFor="m">{x.comment}</label><textarea id="m" rows={4} maxLength={1000} className="input" value={f.comment} onChange={(e) => setF({ ...f, comment: e.target.value })} /></div>
                        <button type="submit" disabled={busy} className="btn-primary min-h-11 px-5">{x.send}</button>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
