export interface Intent {
    id: number;
    reference: string;
    provider: 'mtn_momo' | 'orange_money' | 'card';
    driver: string;
    amount: number;
    currency: string;
    folio_id: number | null;
    reservation_id: number | null;
    payer_phone: string | null;
    payer_email: string | null;
    status: 'pending' | 'succeeded' | 'failed' | 'expired';
    provider_reference: string | null;
    redirect_url: string | null;
    failure_reason: string | null;
    refunded_amount: number;
    refundable?: number;
    channel: string;
    created_at: string;
    completed_at: string | null;
    expires_at: string | null;
    folio?: { id: number; number: string; name: string; balance?: number; status?: string } | null;
    reservation?: { id: number; number: string } | null;
    refunds?: { id: number; amount: number; reason: string; status: string; provider_reference: string | null; created_at: string }[];
    callbacks?: { id: number; signature_valid: boolean; outcome: string | null; created_at: string }[];
}

/** Translation keys for the provider labels (display with t()). */
export const PROVIDERS: Record<Intent['provider'], string> = {
    mtn_momo: 'payments.provider.mtn_momo',
    orange_money: 'payments.provider.orange_money',
    card: 'payments.provider.card',
};

export const STATUS_BADGE: Record<Intent['status'], string> = {
    pending: 'badge-yellow',
    succeeded: 'badge-green',
    failed: 'badge-red',
    expired: 'badge-slate',
};
