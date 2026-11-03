import { router, usePage } from '@inertiajs/react';

/**
 * UI translations. The server shares lang/<locale>.json as `translations` and the active
 * `locale` (fr by default) with every Inertia page. See docs/I18N.md.
 */
export type Locale = 'fr' | 'en';

interface I18nProps {
    locale?: Locale;
    translations?: Record<string, string>;
    [key: string]: unknown;
}

/** Replace :name placeholders (Laravel style). */
function fill(text: string, params?: Record<string, string | number>): string {
    if (!params) return text;
    return Object.entries(params).reduce((s, [k, v]) => s.split(`:${k}`).join(String(v)), text);
}

/** The t(key, params?) function for the current page; falls back to the key itself. */
export function useT() {
    const { translations = {} } = usePage<I18nProps>().props;
    return (key: string, params?: Record<string, string | number>) => fill(translations[key] ?? key, params);
}

export function useLocale(): Locale {
    return usePage<I18nProps>().props.locale ?? 'fr';
}

/** Save the language (on the user when signed in) and reload the page props. */
export function setLocale(locale: Locale) {
    router.post('/locale', { locale }, { preserveScroll: true, preserveState: false });
}
