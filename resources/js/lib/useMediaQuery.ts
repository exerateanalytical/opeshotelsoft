import { useEffect, useState } from 'react';

/**
 * Reactive CSS media query for non-layout concerns (reduced motion, print, colour scheme).
 * Layout decisions (phone, tablet, desktop) come from useDevice() only: see lib/useDevice.ts.
 */
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

/** Kept for existing imports: both now read the single device source of truth. */
export { useIsPhone, useIsTablet } from './useDevice';
