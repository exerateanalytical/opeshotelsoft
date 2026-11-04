import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { useT } from '@/lib/i18n';

type Kind = 'success' | 'error' | 'warning' | 'info';
interface Item { id: number; kind: Kind; text: string }

let items: Item[] = [];
let next = 1;
const subs = new Set<() => void>();
const emit = () => subs.forEach((f) => f());
const subscribe = (f: () => void) => { subs.add(f); return () => { subs.delete(f); }; };
const snapshot = () => items;

/** Show a toast from anywhere: toast.success('Saved'). Errors persist until dismissed; the rest vanish after 5 s. */
export const toast = {
    show(kind: Kind, text: string) {
        const id = next++;
        items = [...items, { id, kind, text }];
        emit();
        if (kind !== 'error') setTimeout(() => toast.dismiss(id), 5000);
    },
    success: (t: string) => toast.show('success', t),
    error: (t: string) => toast.show('error', t),
    warning: (t: string) => toast.show('warning', t),
    info: (t: string) => toast.show('info', t),
    dismiss(id: number) { items = items.filter((i) => i.id !== id); emit(); },
};

const STYLE: Record<Kind, { cls: string; icon: React.ElementType }> = {
    success: { cls: 'border-emerald-700 text-emerald-700', icon: CheckCircle2 },
    error: { cls: 'border-red-700 text-red-700', icon: XCircle },
    warning: { cls: 'border-amber-700 text-amber-700', icon: AlertTriangle },
    info: { cls: 'border-navy-500 text-navy-500', icon: Info },
};

/** Mount once (AppLayout does). A polite live region: screen readers announce each toast; icon plus text, never colour alone. */
export function ToastRegion() {
    const t = useT();
    const list = useSyncExternalStore(subscribe, snapshot, snapshot);
    return (
        <div aria-live="polite" aria-atomic="false" role="status"
            className="pointer-events-none fixed inset-x-0 top-[calc(var(--bar-top-h)+var(--safe-top)+8px)] z-[70] flex flex-col items-center gap-2 px-4 md:items-end md:px-6">
            {list.map((i) => {
                const S = STYLE[i.kind];
                return (
                    <div key={i.id} className={`pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border-2 bg-white px-4 py-3 text-sm font-medium ${S.cls}`}>
                        <S.icon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                        <span className="flex-1 text-slate-900">{i.text}</span>
                        <button type="button" onClick={() => toast.dismiss(i.id)} aria-label={t('ui.toast.dismiss')} className="hit-44 -m-2 p-2 text-slate-600">
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
