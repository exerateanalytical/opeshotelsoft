import { usePage } from '@inertiajs/react';
import { ApiError } from '@/lib/api';

export const fcfa = (v: number | null | undefined) => `${new Intl.NumberFormat('fr-FR').format(Math.round(v ?? 0))} FCFA`;
export const num = (v: number | null | undefined, d = 3) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: d }).format(v ?? 0);
export const today = () => new Date().toISOString().slice(0, 10);
export const errMsg = (e: unknown) => {
    if (e instanceof ApiError) {
        const fields = e.problem.errors ? Object.values(e.problem.errors).flat().join(' ') : '';
        return [e.message, fields].filter(Boolean).join(' - ');
    }
    return String(e);
};
export const usePerms = (): string[] => (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
export interface Ref { id: number; code: string; name: string; unit?: string }
export interface Paged<T> { data: T[] }
