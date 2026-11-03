import { useEffect, useState } from 'react';

/** Reactive CSS media query. Returns `fallback` on the server and before mount. */
export function useMediaQuery(query: string, fallback = false): boolean {
    const [matches, setMatches] = useState(() => (typeof window === 'undefined' ? fallback : window.matchMedia(query).matches));
    useEffect(() => {
        const mq = window.matchMedia(query);
        const on = () => setMatches(mq.matches);
        on();
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, [query]);
    return matches;
}

/** True under 768px: the phone layout (bottom tab bar, bottom sheets, card lists). */
export const useIsPhone = () => useMediaQuery('(max-width: 767px)');
/** True from 768px up to 1023px: the tablet icon rail. */
export const useIsTablet = () => useMediaQuery('(min-width: 768px) and (max-width: 1023px)');
