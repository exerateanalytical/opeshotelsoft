import { Head } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { useT } from '@/lib/i18n';
import Alert from '@/Components/ui/Alert';

interface Post { id: number; title: string; description: string | null; requirements: string | null; location: string | null; contract_kind: string; hotel: string | null; closes_on: string | null }
interface Props { group: { slug: string; name: string }; postId: number | null }

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** Public careers page of a hotel group: open jobs and the application form with CV upload. No login. */
export default function CareersIndex({ group, postId }: Props) {
    const t = useT();
    const [posts, setPosts] = useState<Post[] | null>(null);
    const [open, setOpen] = useState<number | null>(postId);
    const [err, setErr] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        fetch(`/api/v1/careers/${group.slug}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((r) => setPosts(r.posts)).catch(() => setErr(t('talent.careers.error')));
    }, [group.slug]);

    const submit = async (e: FormEvent<HTMLFormElement>, id: number) => {
        e.preventDefault();
        setErr(null); setBusy(true);
        const fd = new FormData(e.currentTarget);
        fd.set('consent', fd.get('consent') ? '1' : '');
        try {
            const res = await fetch(`/api/v1/careers/${group.slug}/posts/${id}/apply`, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, credentials: 'same-origin' });
            const j = await res.json().catch(() => ({}));
            if (!res.ok) { const first = j.errors ? (Object.values(j.errors)[0] as string[])[0] : undefined; throw new Error(first ?? j.detail ?? j.message ?? t('talent.careers.error')); }
            setDone(j.reference);
        } catch (x) { setErr(x instanceof Error ? x.message : String(x)); } finally { setBusy(false); }
    };

    return (
        <div className="min-h-screen bg-[#faf8f3] text-slate-900">
            <Head title={`${t('talent.careers.title')} - ${group.name}`} />
            <header className="border-b border-slate-200 bg-white px-4 py-5"><div className="mx-auto max-w-3xl"><h1 className="font-serif text-3xl">{group.name}</h1><p className="text-base text-slate-600">{t('talent.careers.title')}</p></div></header>
            <main className="mx-auto max-w-3xl space-y-4 px-4 py-6">
                {done && <Alert variant="success">{t('talent.careers.received', { ref: done })}</Alert>}
                {err && <Alert variant="danger">{err}</Alert>}
                {posts && !posts.length && <p className="text-base text-slate-600">{t('talent.careers.none')}</p>}
                {posts?.map((p) => (
                    <article key={p.id} className="rounded-lg border border-slate-200 bg-white p-4">
                        <h2 className="text-xl font-semibold">{p.title}</h2>
                        <p className="text-sm text-slate-600">{[p.hotel, p.location, p.contract_kind].filter(Boolean).join(' - ')}</p>
                        {open === p.id ? (
                            <>
                                {p.description && <p className="mt-3 whitespace-pre-line text-base">{p.description}</p>}
                                {p.requirements && <p className="mt-2 whitespace-pre-line text-base text-slate-700">{p.requirements}</p>}
                                {!done && (
                                    <form className="mt-4 grid gap-3 text-base" onSubmit={(e) => submit(e, p.id)}>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <label className="grid gap-1">{t('talent.careers.first_name')}<input name="first_name" required className="input w-full" /></label>
                                            <label className="grid gap-1">{t('talent.careers.last_name')}<input name="last_name" required className="input w-full" /></label>
                                            <label className="grid gap-1">{t('talent.careers.email')}<input name="email" type="email" required className="input w-full" /></label>
                                            <label className="grid gap-1">{t('talent.careers.phone')}<input name="phone" type="tel" className="input w-full" /></label>
                                        </div>
                                        <label className="grid gap-1">{t('talent.careers.cover_note')}<textarea name="cover_note" rows={4} className="input w-full" /></label>
                                        <label className="grid gap-1">{t('talent.careers.cv')}<input name="cv" type="file" accept=".pdf,.doc,.docx" className="input w-full" /></label>
                                        <label className="flex items-start gap-3 text-base"><input name="consent" type="checkbox" required className="mt-1 h-5 w-5 shrink-0" />{t('talent.careers.consent')}</label>
                                        <button className="btn-primary min-h-12 text-base" disabled={busy}>{t('talent.careers.apply')}</button>
                                    </form>)}
                            </>
                        ) : <button className="btn-secondary mt-3 min-h-11" onClick={() => setOpen(p.id)}>{t('talent.careers.view')}</button>}
                    </article>))}
            </main>
        </div>
    );
}
