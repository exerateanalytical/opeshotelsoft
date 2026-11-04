import { usePage } from '@inertiajs/react';

/** Returns can(perm): true when the signed-in user holds any of the given permissions (shared Inertia prop auth.user.permissions). UI hint only; the server enforces. */
export function useCan() {
    const perms = (usePage().props as { auth?: { user?: { permissions?: string[] } | null } }).auth?.user?.permissions ?? [];
    return (...names: string[]) => names.some((n) => perms.includes(n));
}
