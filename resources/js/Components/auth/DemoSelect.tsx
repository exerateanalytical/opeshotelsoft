import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useId, useState } from 'react';
import { useT } from '@/lib/i18n';

export interface DemoOption { value: string; label: string; group?: string }

interface Props {
    options: DemoOption[];
    /** URL the chosen option is POSTed to, e.g. v => `/demo-login/${v}`. */
    endpoint: (value: string) => string;
    label?: string;
    placeholder?: string;
}

/** Accessible native dropdown of demo accounts (optgroup per section) with an explicit sign-in button. */
export default function DemoSelect({ options, endpoint, label, placeholder }: Props) {
    const t = useT();
    const id = useId();
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [missing, setMissing] = useState(false);
    const groups = [...new Set(options.map((o) => o.group ?? ''))];

    const go = (e: React.FormEvent) => {
        e.preventDefault();
        if (busy) return;
        if (!value) { setMissing(true); return; }
        router.post(endpoint(value), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    };

    return (
        <form onSubmit={go} className="space-y-3">
            <label htmlFor={id} className="label text-slate-300">{label ?? t('brand.demo.select_label')}</label>
            <select id={id} value={value} onChange={(e) => { setValue(e.target.value); setMissing(false); }} aria-invalid={missing || undefined}
                className="select bg-navy-800 border-slate-500 text-white focus:border-brand-500">
                <option value="">{placeholder ?? t('brand.demo.select_placeholder')}</option>
                {groups.map((g) => {
                    const items = options.filter((o) => (o.group ?? '') === g);
                    const opts = items.map((o) => <option key={o.value} value={o.value}>{o.label}</option>);
                    return g ? <optgroup key={g} label={g}>{opts}</optgroup> : opts;
                })}
            </select>
            {missing && <p role="alert" className="text-sm text-red-300">{t('brand.demo.select_placeholder')}</p>}
            <button type="submit" disabled={busy}
                className="btn-accent w-full min-h-11 text-base sm:text-sm">
                {busy && <Loader2 className="w-4 h-4 animate-spin" />}{t('brand.demo.submit')}
            </button>
        </form>
    );
}
