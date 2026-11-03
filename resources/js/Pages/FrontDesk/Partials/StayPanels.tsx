import { Eraser, Loader2, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { activeHotelId, api, ApiError, useApi } from '@/lib/api';
import { cn, formatCurrency, formatDateTime } from '@/lib/utils';
import { useT } from '@/lib/i18n';

type Notify = (ok: boolean, text: string) => void;
const errText = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

interface Quote {
    type: 'early' | 'late' | 'on_time';
    early: boolean;
    nights_released: number;
    fee: { kind: string; charge_code: string; amount: number; label: string } | null;
    fee_posted: boolean;
}

/** Check-out with early-departure and late check-out fee handling. */
export function CheckOutPanel({ id, onDone, notify }: { id: number | string; onDone: () => void; notify: Notify }) {
    const t = useT();
    const quote = useApi<Quote>(`reservations/${id}/check-out/quote`);
    const [waive, setWaive] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const q = quote.data;

    async function post(key: string, path: string, body: object, ok: string) {
        setBusy(key);
        try {
            await api(path, { method: 'POST', body: JSON.stringify(body) });
            notify(true, ok);
            quote.reload();
            onDone();
        } catch (e) {
            notify(false, errText(e));
        } finally {
            setBusy(null);
            setConfirming(false);
        }
    }

    return (
        <div className="card px-5 py-4 space-y-3">
            <h3 className="font-semibold text-slate-800">{t('fo.stay.check_out_title')}</h3>
            {!q ? <Loader2 className="w-4 h-4 animate-spin text-slate-400" /> : (
                <>
                    {q.early && <p className="text-sm text-slate-600">{t('fo.stay.early_departure', { count: q.nights_released })}</p>}
                    {q.fee && (
                        <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            {q.fee.label}: <strong>{formatCurrency(q.fee.amount)}</strong> ({q.fee.charge_code}){q.fee_posted && t('fo.stay.posted')}
                        </div>
                    )}
                    {q.fee && !q.fee_posted && (
                        <div className="flex flex-wrap items-center gap-3">
                            <button className="btn-secondary" disabled={busy === 'fee'} onClick={() => post('fee', `reservations/${id}/check-out/fee`, {}, t('fo.stay.fee_posted'))}>
                                {busy === 'fee' && <Loader2 className="w-4 h-4 animate-spin" />} {t('fo.stay.post_fee')}
                            </button>
                            <label className="text-sm text-slate-600 flex items-center gap-2">
                                <input type="checkbox" checked={waive} onChange={(e) => setWaive(e.target.checked)} /> {t('fo.stay.waive_fee')}
                            </label>
                        </div>
                    )}
                    {confirming ? (
                        <div className="flex flex-wrap gap-2">
                            <button className="btn-primary" disabled={busy === 'out'}
                                onClick={() => post('out', `reservations/${id}/check-out`, waive ? { waive_fee: true } : {}, t('fo.stay.checked_out'))}>
                                {busy === 'out' && <Loader2 className="w-4 h-4 animate-spin" />} {t('fo.stay.confirm_check_out')}
                            </button>
                            <button className="btn-secondary" onClick={() => setConfirming(false)}>{t('common.cancel')}</button>
                        </div>
                    ) : (
                        <button className="btn-primary w-full justify-center" onClick={() => setConfirming(true)}>{t('fo.stay.check_out')}</button>
                    )}
                </>
            )}
        </div>
    );
}

interface VacantRoom { id: number; number: string; hk_status: string; room_type: { code: string; name: string } | null }

/** Move an in-house guest to a vacant room. */
export function RoomMovePanel({ id, onDone, notify }: { id: number | string; onDone: () => void; notify: Notify }) {
    const t = useT();
    const rooms = useApi<VacantRoom[]>(`reservations/${id}/vacant-rooms`);
    const [roomId, setRoomId] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <form className="card px-5 py-4 space-y-3" onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            try {
                await api(`reservations/${id}/move-room`, { method: 'POST', body: JSON.stringify({ room_id: Number(roomId), reason }) });
                notify(true, t('fo.stay.guest_moved'));
                setRoomId('');
                setReason('');
                rooms.reload();
                onDone();
            } catch (err) {
                notify(false, errText(err));
            } finally {
                setBusy(false);
            }
        }}>
            <h3 className="font-semibold text-slate-800">{t('fo.stay.move_room')}</h3>
            <select className="input" required value={roomId} onChange={(e) => setRoomId(e.target.value)}>
                <option value="" disabled>{rooms.loading ? t('common.loading') : t('fo.stay.vacant_room')}</option>
                {rooms.data?.map((r) => <option key={r.id} value={r.id}>{r.number} · {r.room_type?.code} · {t(`fo.hk.${r.hk_status}`) === `fo.hk.${r.hk_status}` ? r.hk_status.replace(/_/g, ' ') : t(`fo.hk.${r.hk_status}`)}</option>)}
            </select>
            <input className="input" required maxLength={255} placeholder={t('fo.stay.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
            <button className="btn-secondary w-full justify-center" disabled={busy}>{busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('fo.stay.move_guest')}</button>
        </form>
    );
}

interface RoutingRule { id: number; match_type: 'charge_code' | 'department' | 'all'; match_value: string | null; target: { number: string; name: string; status: string } | null }
interface FolioRow { id: number; number: string; name: string; kind: string; status: string }

const DEPARTMENTS = ['rooms', 'fnb', 'beverage', 'laundry', 'spa', 'telephone', 'events', 'misc'];

/** Route charge codes or departments to another folio (e.g. room to the company master folio). */
export function RoutingPanel({ id, companyId, chargeCodes, notify }: { id: number | string; companyId: number | null; chargeCodes: { code: string; name: string }[]; notify: Notify }) {
    const t = useT();
    const label = (prefix: string, v: string) => (t(`${prefix}.${v}`) === `${prefix}.${v}` ? v : t(`${prefix}.${v}`));
    const rules = useApi<RoutingRule[]>(`reservations/${id}/routing-rules`);
    const folios = useApi<{ data: FolioRow[] }>('folios?status=open');
    const [form, setForm] = useState({ match_type: 'department', match_value: 'rooms', target_folio_id: '' });
    const [busy, setBusy] = useState(false);
    const targets = (folios.data?.data ?? []).filter((f) => f.kind !== 'guest');

    async function call(path: string, init: RequestInit, ok: string) {
        setBusy(true);
        try {
            await api(path, init);
            notify(true, ok);
            rules.reload();
        } catch (e) {
            notify(false, errText(e));
        } finally {
            setBusy(false);
        }
    }

    async function newMaster() {
        await call('folios', { method: 'POST', body: JSON.stringify({ kind: companyId ? 'company' : 'master', name: t('fo.stay.master_name', { id }), company_id: companyId }) }, t('fo.stay.master_opened'));
        folios.reload();
    }

    return (
        <div className="card px-5 py-4 space-y-3">
            <h3 className="font-semibold text-slate-800">{t('fo.stay.routing')}</h3>
            {rules.data?.length === 0 && <p className="text-sm text-slate-500">{t('fo.stay.no_rules')}</p>}
            <ul className="space-y-1">
                {rules.data?.map((r) => (
                    <li key={r.id} className="flex items-center gap-2 text-sm">
                        <span className="badge-blue">{r.match_type === 'all' ? t('fo.stay.all_charges_badge') : r.match_type === 'department' ? t('fo.stay.dept_badge', { value: label('fo.dept', r.match_value ?? '') }) : t('fo.stay.code_badge', { value: r.match_value ?? '' })}</span>
                        <span className="truncate">→ {r.target ? `${r.target.number} ${r.target.name}` : t('fo.stay.missing_folio')}</span>
                        <button type="button" title={t('fo.stay.remove_rule')} className="ml-auto text-slate-400 hover:text-red-600" disabled={busy}
                            onClick={() => call(`reservations/${id}/routing-rules/${r.id}`, { method: 'DELETE' }, t('fo.stay.rule_removed'))}>
                            <Trash2 className="w-4 h-4" />
                        </button>
                    </li>
                ))}
            </ul>
            <form className="space-y-2" onSubmit={(e) => {
                e.preventDefault();
                call(`reservations/${id}/routing-rules`, { method: 'POST', body: JSON.stringify({ ...form, target_folio_id: Number(form.target_folio_id), match_value: form.match_type === 'all' ? null : form.match_value }) }, t('fo.stay.rule_added'));
            }}>
                <div className="flex gap-2">
                    <select className="input" value={form.match_type} onChange={(e) => setForm({ ...form, match_type: e.target.value, match_value: e.target.value === 'department' ? 'rooms' : e.target.value === 'charge_code' ? (chargeCodes[0]?.code ?? '') : '' })}>
                        <option value="department">{t('fo.stay.department')}</option>
                        <option value="charge_code">{t('fo.stay.charge_code')}</option>
                        <option value="all">{t('fo.stay.all_charges')}</option>
                    </select>
                    {form.match_type === 'department' && (
                        <select className="input" value={form.match_value} onChange={(e) => setForm({ ...form, match_value: e.target.value })}>
                            {DEPARTMENTS.map((d) => <option key={d} value={d}>{label('fo.dept', d)}</option>)}
                        </select>
                    )}
                    {form.match_type === 'charge_code' && (
                        <select className="input" value={form.match_value} onChange={(e) => setForm({ ...form, match_value: e.target.value })}>
                            {chargeCodes.map((c) => <option key={c.code} value={c.code}>{c.code}</option>)}
                        </select>
                    )}
                </div>
                <select className="input" required value={form.target_folio_id} onChange={(e) => setForm({ ...form, target_folio_id: e.target.value })}>
                    <option value="" disabled>{folios.loading ? t('common.loading') : t('fo.stay.route_to')}</option>
                    {targets.map((f) => <option key={f.id} value={f.id}>{f.number} · {f.name} ({label('fo.folio_kind', f.kind)})</option>)}
                </select>
                <div className="flex gap-2">
                    <button className="btn-secondary flex-1 justify-center" disabled={busy}>{t('fo.stay.add_rule')}</button>
                    <button type="button" className="btn-secondary" disabled={busy} onClick={newMaster}>{t('fo.stay.new_master')}</button>
                </div>
            </form>
        </div>
    );
}

interface Card {
    full_name: string;
    room_number: string;
    id_type: string | null;
    id_number: string | null;
    nationality: string | null;
    has_id_photo: boolean;
    has_signature: boolean;
    id_photo_url: string | null;
    signature_url: string | null;
    card_captured_at: string | null;
}

/** Load an authorised image endpoint as a blob URL (the API needs the hotel header). */
function useBlobUrl(url: string | null, version: unknown) {
    const [src, setSrc] = useState<string | null>(null);
    useEffect(() => {
        if (!url) return setSrc(null);
        let objectUrl: string | null = null;
        const hotel = activeHotelId();
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', ...(hotel ? { 'X-Hotel-Id': hotel } : {}) } })
            .then((r) => (r.ok ? r.blob() : null))
            .then((b) => b && setSrc((objectUrl = URL.createObjectURL(b))))
            .catch(() => setSrc(null));
        return () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [url, version]);
    return src;
}

function readAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(String(r.result));
        r.onerror = () => reject(r.error);
        r.readAsDataURL(file);
    });
}

/** Registration card: ID photo upload and guest signature pad. */
export function RegistrationCardPanel({ id, editable, notify }: { id: number | string; editable: boolean; notify: Notify }) {
    const t = useT();
    const card = useApi<Card>(`reservations/${id}/registration-card`);
    const c = card.data;
    const photo = useBlobUrl(c?.id_photo_url ?? null, c?.card_captured_at);
    const signature = useBlobUrl(c?.signature_url ?? null, c?.card_captured_at);
    const canvas = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const [signed, setSigned] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);

    function point(e: React.PointerEvent<HTMLCanvasElement>) {
        const el = canvas.current!;
        const rect = el.getBoundingClientRect();
        return [((e.clientX - rect.left) * el.width) / rect.width, ((e.clientY - rect.top) * el.height) / rect.height] as const;
    }
    function down(e: React.PointerEvent<HTMLCanvasElement>) {
        const ctx = canvas.current?.getContext('2d');
        if (!ctx) return;
        canvas.current!.setPointerCapture(e.pointerId);
        drawing.current = true;
        const [x, y] = point(e);
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#0f172a';
        ctx.beginPath();
        ctx.moveTo(x, y);
    }
    function move(e: React.PointerEvent<HTMLCanvasElement>) {
        if (!drawing.current) return;
        const ctx = canvas.current!.getContext('2d')!;
        const [x, y] = point(e);
        ctx.lineTo(x, y);
        ctx.stroke();
        setSigned(true);
    }
    function clear() {
        const el = canvas.current;
        el?.getContext('2d')?.clearRect(0, 0, el.width, el.height);
        setSigned(false);
    }

    async function save() {
        setBusy(true);
        try {
            const body: Record<string, string> = {};
            if (file) body.id_photo_data = await readAsDataUrl(file);
            if (signed && canvas.current) body.signature = canvas.current.toDataURL('image/png');
            await api(`reservations/${id}/registration-card`, { method: 'POST', body: JSON.stringify(body) });
            notify(true, t('fo.stay.card_saved'));
            setFile(null);
            clear();
            card.reload();
        } catch (e) {
            notify(false, errText(e));
        } finally {
            setBusy(false);
        }
    }

    if (card.error?.problem.status === 404) return null;

    return (
        <div className="card px-5 py-4 space-y-3">
            <h3 className="font-semibold text-slate-800">{t('fo.stay.card')}</h3>
            {!c ? <Loader2 className="w-4 h-4 animate-spin text-slate-400" /> : (
                <>
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                        <dt className="label">{t('fo.stay.name')}</dt><dd>{c.full_name}</dd>
                        <dt className="label">{t('fo.stay.id')}</dt><dd>{[c.id_type, c.id_number].filter(Boolean).join(' ') || '—'}</dd>
                        <dt className="label">{t('fo.stay.room')}</dt><dd>{c.room_number}</dd>
                        {c.card_captured_at && <><dt className="label">{t('fo.stay.captured')}</dt><dd>{formatDateTime(c.card_captured_at)}</dd></>}
                    </dl>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <div className="label mb-1">{t('fo.stay.id_photo')}</div>
                            {photo ? <img src={photo} alt={t('fo.stay.id_photo_alt')} className="w-full rounded border border-slate-200 object-contain max-h-40" />
                                : <div className="text-sm text-slate-400">{t('fo.stay.none')}</div>}
                        </div>
                        <div>
                            <div className="label mb-1">{t('fo.stay.signature')}</div>
                            {signature ? <img src={signature} alt={t('fo.stay.signature_alt')} className="w-full rounded border border-slate-200 bg-white object-contain max-h-40" />
                                : <div className="text-sm text-slate-400">{t('fo.stay.none')}</div>}
                        </div>
                    </div>
                    {editable && (
                        <div className="space-y-2 border-t border-slate-100 pt-3">
                            <label className="label block" htmlFor="id-photo">{t('fo.stay.upload_id')}</label>
                            <input id="id-photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" className="text-sm w-full"
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                            <div className="flex items-center">
                                <span className="label">{t('fo.stay.guest_signature')}</span>
                                <button type="button" className="ml-auto text-xs text-slate-500 hover:text-slate-800 inline-flex items-center gap-1" onClick={clear}>
                                    <Eraser className="w-3 h-3" /> {t('fo.stay.clear')}
                                </button>
                            </div>
                            <canvas ref={canvas} width={600} height={200} aria-label={t('fo.stay.signature_pad')}
                                className={cn('w-full h-32 rounded border border-slate-300 bg-white touch-none', signed && 'border-slate-500')}
                                onPointerDown={down} onPointerMove={move} onPointerUp={() => (drawing.current = false)} onPointerLeave={() => (drawing.current = false)} />
                            <button className="btn-primary w-full justify-center" disabled={busy || (!file && !signed)} onClick={save}>
                                {busy && <Loader2 className="w-4 h-4 animate-spin" />} {t('fo.stay.save_card')}
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
