import { Head } from '@inertiajs/react';
import { useState } from 'react';
import BrandLogo from '@/Components/ui/BrandLogo';

interface Result {
    type_label: string; number: string; hotel: string | null; issued_at_local: string; issued_at_utc: string; timezone: string;
    amount: number | null; currency: string | null; status: 'valid' | 'voided' | 'replaced'; replaced_by: { number: string; code: string } | null;
    hash_match: boolean | null;
}
interface Props { result: Result | null; code: string; lang: 'fr' | 'en'; labels: Record<string, string> }

const TONE: Record<string, string> = { valid: '#166534', voided: '#991b1b', replaced: '#92400e', unknown: '#991b1b' };

/** Public document verification page (QR target). Flat, mobile first, no app chrome, no login. */
export default function Show({ result, code, lang, labels: l }: Props) {
    const [hash, setHash] = useState('');
    const status = result?.status ?? 'unknown';
    const other = lang === 'fr' ? 'en' : 'fr';
    const money = (n: number) => new Intl.NumberFormat(lang === 'fr' ? 'fr-FR' : 'en-GB').format(n);

    return (
        <div style={{ minHeight: '100vh', background: '#f9fafb', color: '#111827', fontFamily: 'system-ui, sans-serif' }}>
            <Head title={l.title} />
            <main style={{ maxWidth: 480, margin: '0 auto', padding: '16px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                    <h1 style={{ fontSize: 16, margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}><BrandLogo variant="emblem" size={24} />{l.title}</h1>
                    <a href={`?lang=${other}`} style={{ fontSize: 14, color: '#374151', display: 'inline-block', padding: '12px 4px' }}>{l.other_lang}</a>
                </div>
                <div role="status" style={{ background: '#fff', border: `2px solid ${TONE[status]}`, padding: 16, marginBottom: 12 }}>
                    <div style={{ fontSize: 20, fontWeight: 700, color: TONE[status] }}>{l[status] ?? l.unknown}</div>
                    {!result && <p style={{ margin: '8px 0 0', fontSize: 16 }}>{l.unknown_help}</p>}
                    {result?.status === 'replaced' && result.replaced_by && (
                        <p style={{ margin: '8px 0 0', fontSize: 16 }}>
                            {l.replaced_by} <a href={`/verify/${result.replaced_by.code}?lang=${lang}`}>{result.replaced_by.number}</a>
                        </p>
                    )}
                </div>
                {result && (
                    <>
                        <dl style={{ background: '#fff', border: '1px solid #e5e7eb', padding: 16, margin: '0 0 12px', fontSize: 16 }}>
                            {[
                                [l.type, result.type_label], [l.number, result.number], [l.hotel, result.hotel ?? ''],
                                [l.issued, `${result.issued_at_local} (${result.timezone}) / ${result.issued_at_utc.slice(0, 16).replace('T', ' ')} UTC`],
                                ...(result.amount !== null ? [[l.amount, `${money(result.amount)} ${result.currency ?? ''}`]] : []),
                            ].map(([k, v]) => (
                                <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '6px 0', borderBottom: '1px solid #f3f4f6' }}>
                                    <dt style={{ color: '#4b5563' }}>{k}</dt>
                                    <dd style={{ margin: 0, textAlign: 'right', fontWeight: 600 }}>{v}</dd>
                                </div>
                            ))}
                        </dl>
                        <form method="get" style={{ background: '#fff', border: '1px solid #e5e7eb', padding: 16, fontSize: 16 }}>
                            <input type="hidden" name="lang" value={lang} />
                            <label htmlFor="h" style={{ display: 'block', color: '#4b5563', marginBottom: 6 }}>{l.hash_check}</label>
                            <input id="h" name="h" value={hash} onChange={(e) => setHash(e.target.value)} inputMode="text" autoComplete="off" placeholder="a1b2c3d4e5f6..."
                                style={{ width: '100%', boxSizing: 'border-box', padding: 10, minHeight: 44, border: '1px solid #6b7280', fontFamily: 'monospace', fontSize: 16 }} />
                            <button type="submit" style={{ marginTop: 8, padding: '10px 18px', minHeight: 44, borderRadius: 8, background: '#0f2044', color: '#fff', border: 0, fontSize: 16 }}>{l.check}</button>
                            <p style={{ margin: '10px 0 0', color: result.hash_match === false ? '#991b1b' : result.hash_match ? '#166534' : '#6b7280', fontWeight: result.hash_match === null ? 400 : 700 }}>
                                {result.hash_match === null ? l.hash_none : result.hash_match ? l.hash_ok : l.hash_bad}
                            </p>
                        </form>
                    </>
                )}
                <p style={{ fontSize: 14, color: '#4b5563', marginTop: 12 }}>{l.privacy} <span style={{ fontFamily: 'monospace' }}>{code}</span></p>
            </main>
        </div>
    );
}
