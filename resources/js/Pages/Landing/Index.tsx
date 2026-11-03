import { Head, Link, useForm } from '@inertiajs/react';
import { Award, BellRing, Boxes, Building2, Calculator, Calendar, Check, FileCheck, Gauge, MessageCircle, QrCode, Receipt, Rocket, ShieldCheck, Smartphone, UtensilsCrossed, Users, WifiOff } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import BrandLogo from '@/Components/ui/BrandLogo';
import { setLocale, useLocale, useT } from '@/lib/i18n';

const MODULES = [
    { icon: Calendar, soon: false }, { icon: Receipt, soon: false }, { icon: UtensilsCrossed, soon: false }, { icon: Boxes, soon: false },
    { icon: Calculator, soon: false }, { icon: Users, soon: false }, { icon: QrCode, soon: false }, { icon: MessageCircle, soon: false },
    { icon: Gauge, soon: false }, { icon: Building2, soon: false }, { icon: Award, soon: false }, { icon: ShieldCheck, soon: false },
    { icon: FileCheck, soon: true }, { icon: Smartphone, soon: true },
];
const DIFF_ICONS = [WifiOff, QrCode, BellRing, Rocket];
const NAV = [['problems', 'nav.problems'], ['modules', 'nav.modules'], ['difference', 'nav.difference'], ['pricing', 'nav.pricing'], ['faq', 'nav.faq']] as const;
const range = (n: number) => Array.from({ length: n }, (_, i) => i + 1);

function Section({ id, tone, children }: { id?: string; tone: 'white' | 'soft' | 'dark'; children: ReactNode }) {
    const bg = tone === 'dark' ? 'bg-navy-900 text-white' : tone === 'soft' ? 'bg-slate-50' : 'bg-white';
    return (
        <section id={id} className={`${bg} scroll-mt-16 py-16 lg:py-20`}>
            <div className="mx-auto max-w-6xl px-4 sm:px-6">{children}</div>
        </section>
    );
}

function Heading({ title, sub, dark }: { title: string; sub?: string; dark?: boolean }) {
    return (
        <div className="mb-10 max-w-3xl">
            <h2 className="text-3xl font-bold leading-tight md:text-4xl">{title}</h2>
            {sub && <p className={`mt-3 text-lg ${dark ? 'text-navy-100' : 'text-slate-600'}`}>{sub}</p>}
        </div>
    );
}

/** Decorative room-chart preview (not real data). */
function Preview() {
    const t = useT();
    const rows = [
        [[0, 3, 'bg-navy-600'], [4, 6, 'bg-brand-500']], [[1, 5, 'bg-brand-500']], [[0, 2, 'bg-navy-300'], [3, 7, 'bg-navy-600']], [[2, 4, 'bg-navy-600'], [5, 9, 'bg-brand-500']],
        [[0, 6, 'bg-navy-600']], [[3, 8, 'bg-navy-300']], [[0, 4, 'bg-brand-500'], [5, 8, 'bg-navy-600']],
    ] as [number, number, string][][];
    return (
        <div aria-hidden="true" className="rounded-lg border border-navy-600 bg-white p-4 text-slate-900">
            <div className="mb-3 flex items-center justify-between text-sm">
                <span className="font-semibold">{t('landing.hero.preview.title')}</span>
                <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{t('landing.hero.preview')}</span>
            </div>
            <div className="space-y-1.5">
                {rows.map((bars, r) => (
                    <div key={r} className="relative h-6 rounded bg-slate-100">
                        <span className="absolute left-2 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-500">{101 + r}</span>
                        {bars.map(([from, to, color], i) => (
                            <span key={i} className={`absolute top-1 h-4 rounded ${color}`} style={{ left: `${12 + from * 8.5}%`, width: `${(to - from) * 8.5}%` }} />
                        ))}
                    </div>
                ))}
            </div>
            <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                {([['landing.hero.preview.occupancy', '78 %'], ['landing.hero.preview.cash', '1 240 000'], ['landing.hero.preview.alerts', '2']] as const).map(([k, v]) => (
                    <div key={k} className="rounded border border-slate-200 p-2">
                        <div className="text-lg font-bold">{v}</div>
                        <div className="text-xs text-slate-500">{t(k)}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DemoForm() {
    const t = useT();
    const [sent, setSent] = useState(false);
    const f = useForm({ name: '', hotel: '', city: '', rooms: '', phone: '', email: '', message: '', website: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        f.post('/demo-request', { preserveScroll: true, onSuccess: () => { setSent(true); f.reset(); } });
    };
    const field = (k: 'name' | 'hotel' | 'city' | 'rooms' | 'phone' | 'email', label: string, type = 'text', required = false) => (
        <label className="block text-sm font-medium text-slate-700">
            {label}{required && ' *'}
            <input className="input mt-1" type={type} required={required} value={f.data[k]} onChange={(e) => f.setData(k, e.target.value)} inputMode={k === 'rooms' ? 'numeric' : undefined} />
            {f.errors[k] && <span className="mt-1 block text-sm text-red-600">{f.errors[k]}</span>}
        </label>
    );

    if (sent) {
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-8 text-slate-900">
                <Check className="mb-3 h-8 w-8 text-emerald-600" />
                <h3 className="text-xl font-bold">{t('landing.form.sent.title')}</h3>
                <p className="mt-2 text-slate-600">{t('landing.form.sent.text')}</p>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4 rounded-lg border border-slate-200 bg-white p-6 text-slate-900">
            <div className="grid gap-4 sm:grid-cols-2">
                {field('name', t('landing.form.name'), 'text', true)}
                {field('hotel', t('landing.form.hotel'))}
                {field('city', t('landing.form.city'))}
                {field('rooms', t('landing.form.rooms'), 'number')}
                {field('phone', t('landing.form.phone'), 'tel')}
                {field('email', t('landing.form.email'), 'email')}
            </div>
            <p className="text-sm text-slate-500">{t('landing.form.contact_hint')}</p>
            <label className="block text-sm font-medium text-slate-700">
                {t('landing.form.message')}
                <textarea className="input mt-1 min-h-24" value={f.data.message} onChange={(e) => f.setData('message', e.target.value)} />
            </label>
            {/* Honeypot: hidden from people, filled by bots. */}
            <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" value={f.data.website} onChange={(e) => f.setData('website', e.target.value)} />
            <button type="submit" disabled={f.processing} className="w-full btn-accent min-h-12 text-base">
                {f.processing ? t('landing.form.sending') : t('landing.form.submit')}
            </button>
        </form>
    );
}

export default function Landing() {
    const t = useT();
    const locale = useLocale();
    const demoLink = '#contact';
    const faqSchema = {
        '@context': 'https://schema.org', '@type': 'FAQPage',
        mainEntity: range(6).map((i) => ({ '@type': 'Question', name: t(`landing.faq.${i}.q`), acceptedAnswer: { '@type': 'Answer', text: t(`landing.faq.${i}.a`) } })),
    };
    const orgSchema = { '@context': 'https://schema.org', '@type': 'SoftwareApplication', name: 'OPES Hôtels Soft', applicationCategory: 'BusinessApplication', description: t('landing.meta.description') };

    return (
        <div className="min-h-screen bg-white text-slate-900">
            <Head title={t('landing.meta.title')}>
                <meta name="description" content={t('landing.meta.description')} />
                <meta property="og:title" content={t('landing.meta.title')} />
                <meta property="og:description" content={t('landing.meta.description')} />
                <script type="application/ld+json">{JSON.stringify(faqSchema)}</script>
                <script type="application/ld+json">{JSON.stringify(orgSchema)}</script>
            </Head>

            <header className="sticky top-0 z-30 border-b border-navy-700 bg-navy-900 text-white">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                    <a href="#top" aria-label="OPES Hôtels Soft" className="flex min-w-11 min-h-11 shrink-0 items-center"><BrandLogo variant="lockup" size={36} textClassName="hidden whitespace-nowrap text-lg sm:inline" /></a>
                    <nav className="hidden items-center gap-6 text-[0.9375rem] text-navy-100 lg:flex" aria-label="Main">
                        {NAV.map(([id, key]) => <a key={id} href={`#${id}`} className="hover:text-white">{t(`landing.${key}`)}</a>)}
                    </nav>
                    <div className="flex items-center gap-2 sm:gap-3">
                        <div className="flex overflow-hidden rounded-md border border-navy-600 text-sm" role="group" aria-label={t('landing.nav.lang')}>
                            {(['fr', 'en'] as const).map((l) => (
                                <button key={l} onClick={() => l !== locale && setLocale(l)} aria-pressed={locale === l} className={`min-h-11 min-w-11 px-2.5 font-semibold uppercase ${locale === l ? 'bg-white text-navy-900' : 'text-navy-100 hover:text-white'}`}>{l}</button>
                            ))}
                        </div>
                        <Link href="/login" className="hidden text-[0.9375rem] text-navy-100 hover:text-white sm:inline">{t('landing.nav.login')}</Link>
                        <a href={demoLink} className="btn-accent whitespace-nowrap px-3 sm:px-4">{t('landing.nav.demo')}</a>
                    </div>
                </div>
            </header>

            <main id="top">
                <section className="bg-navy-900 py-16 text-white lg:py-24">
                    <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2">
                        <div>
                            <div className="mb-6 inline-block rounded-md bg-white p-3"><BrandLogo variant="wordmark" size={260} /></div>
                            <p className="mb-4 text-sm font-semibold uppercase tracking-wide text-brand-300">{t('landing.hero.eyebrow')}</p>
                            <h1 className="text-4xl font-bold leading-tight md:text-5xl">{t('landing.hero.title')}</h1>
                            <p className="mt-5 text-lg text-navy-100">{t('landing.hero.sub')}</p>
                            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                <a href={demoLink} className="btn-accent min-h-12 px-6 text-base">{t('landing.hero.cta')}</a>
                                <Link href="/login" className="inline-flex items-center justify-center min-h-12 rounded-lg border border-navy-300 px-6 text-center text-base font-semibold text-white hover:bg-navy-800">{t('landing.hero.cta2')}</Link>
                            </div>
                            <p className="mt-4 text-sm text-navy-200">{t('landing.hero.note')}</p>
                        </div>
                        <Preview />
                    </div>
                </section>

                <section className="border-b border-slate-200 bg-white">
                    <dl className="mx-auto grid max-w-6xl grid-cols-2 gap-6 px-4 py-8 sm:px-6 lg:grid-cols-4">
                        {range(4).map((i) => (
                            <div key={i}>
                                <dt className="text-3xl font-bold text-navy-800">{t(`landing.facts.${i}.value`)}</dt>
                                <dd className="mt-1 text-[0.9375rem] text-slate-600">{t(`landing.facts.${i}.label`)}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <Section id="problems" tone="soft">
                    <Heading title={t('landing.problems.title')} sub={t('landing.problems.sub')} />
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {range(5).map((i) => (
                            <div key={i} className="rounded-lg border border-slate-200 bg-white p-6">
                                <div className="mb-3 flex h-8 w-8 items-center justify-center rounded bg-red-50 text-sm font-bold text-red-700">{i}</div>
                                <h3 className="text-lg font-semibold">{t(`landing.problems.${i}.title`)}</h3>
                                <p className="mt-2 text-slate-600">{t(`landing.problems.${i}.text`)}</p>
                            </div>
                        ))}
                    </div>
                </Section>

                <Section id="modules" tone="white">
                    <Heading title={t('landing.modules.title')} sub={t('landing.modules.sub')} />
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {MODULES.map(({ icon: Icon, soon }, idx) => (
                            <div key={idx} className="rounded-lg border border-slate-200 p-6">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-md bg-navy-50 text-navy-700"><Icon className="h-5 w-5" /></span>
                                    {soon && <span className="rounded bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-800">{t('landing.modules.soon')}</span>}
                                </div>
                                <h3 className="text-lg font-semibold">{t(`landing.modules.${idx + 1}.title`)}</h3>
                                <p className="mt-2 text-slate-600">{t(`landing.modules.${idx + 1}.text`)}</p>
                            </div>
                        ))}
                    </div>
                </Section>

                <Section id="difference" tone="dark">
                    <Heading title={t('landing.diff.title')} dark />
                    <div className="grid gap-6 md:grid-cols-2">
                        {range(4).map((i) => {
                            const Icon = DIFF_ICONS[i - 1];
                            return (
                                <div key={i} className="rounded-lg border border-navy-600 bg-navy-800 p-7">
                                    <span className="mb-4 flex h-11 w-11 items-center justify-center rounded-md bg-brand-500 text-navy-900"><Icon className="h-6 w-6" /></span>
                                    <h3 className="text-xl font-semibold">{t(`landing.diff.${i}.title`)}</h3>
                                    <p className="mt-2 text-navy-100">{t(`landing.diff.${i}.text`)}</p>
                                </div>
                            );
                        })}
                    </div>
                </Section>

                <Section tone="white">
                    <Heading title={t('landing.roles.title')} sub={t('landing.roles.sub')} />
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {range(7).map((i) => (
                            <div key={i} className="rounded-lg border border-slate-200 p-5">
                                <h3 className="font-semibold">{t(`landing.roles.${i}.title`)}</h3>
                                <p className="mt-1 text-[0.9375rem] text-slate-600">{t(`landing.roles.${i}.text`)}</p>
                            </div>
                        ))}
                    </div>
                </Section>

                <Section tone="soft">
                    <Heading title={t('landing.trust.title')} />
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {range(6).map((i) => (
                            <li key={i} className="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-4">
                                <Check className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                                <span>{t(`landing.trust.${i}`)}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-5 max-w-3xl text-sm text-slate-500">{t('landing.trust.note')}</p>
                </Section>

                <Section id="pricing" tone="white">
                    <Heading title={t('landing.pricing.title')} sub={t('landing.pricing.sub')} />
                    <div className="grid gap-6 lg:grid-cols-3">
                        {range(3).map((i) => (
                            <div key={i} className={`flex flex-col rounded-lg p-7 ${i === 2 ? 'border-2 border-brand-500' : 'border border-slate-200'}`}>
                                <h3 className="text-xl font-bold">{t(`landing.pricing.${i}.name`)}</h3>
                                <p className="mt-1 text-slate-600">{t(`landing.pricing.${i}.text`)}</p>
                                <ul className="mt-5 flex-1 space-y-2.5">
                                    {range(4).map((j) => <li key={j} className="flex items-start gap-2.5"><Check className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />{t(`landing.pricing.${i}.f${j}`)}</li>)}
                                </ul>
                                <a href={demoLink} className={`mt-6 rounded-md px-5 py-3 text-center font-semibold ${i === 2 ? 'bg-brand-500 text-navy-900 hover:bg-brand-400' : 'border border-navy-700 text-navy-800 hover:bg-navy-50'}`}>{t('landing.pricing.cta')}</a>
                            </div>
                        ))}
                    </div>
                </Section>

                <Section id="faq" tone="soft">
                    <Heading title={t('landing.faq.title')} />
                    <div className="max-w-3xl divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                        {range(6).map((i) => (
                            <details key={i} className="group p-5">
                                <summary className="cursor-pointer list-none text-lg font-semibold marker:hidden">{t(`landing.faq.${i}.q`)}</summary>
                                <p className="mt-3 text-slate-600">{t(`landing.faq.${i}.a`)}</p>
                            </details>
                        ))}
                    </div>
                </Section>

                <Section id="contact" tone="dark">
                    <div className="grid items-start gap-10 lg:grid-cols-2">
                        <div>
                            <h2 className="text-3xl font-bold leading-tight md:text-4xl">{t('landing.cta.title')}</h2>
                            <p className="mt-4 text-lg text-navy-100">{t('landing.cta.sub')}</p>
                        </div>
                        <DemoForm />
                    </div>
                </Section>
            </main>

            <footer className="border-t border-navy-700 bg-navy-950 py-10 text-navy-100">
                <div className="mx-auto flex max-w-6xl flex-col gap-4 px-4 sm:px-6 md:flex-row md:items-center md:justify-between">
                    <div>
                        <BrandLogo variant="lockup" size={28} className="mb-1" />
                        <p className="text-sm">{t('landing.footer.tagline')}</p>
                    </div>
                    <div className="flex flex-wrap gap-5 text-[0.9375rem]">
                        <Link href="/login" className="inline-flex items-center min-h-11 hover:text-white">{t('landing.footer.login')}</Link>
                        <a href="/book/opes-demo" className="inline-flex items-center min-h-11 hover:text-white">{t('landing.footer.book')}</a>
                    </div>
                    <p className="text-sm">© {new Date().getFullYear()} OPES Hôtels Soft. {t('landing.footer.rights')}</p>
                </div>
            </footer>
        </div>
    );
}
