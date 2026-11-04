import { useSyncExternalStore } from 'react';

/**
 * The one device source of truth (docs/DEVICE-CERTIFICATION.md).
 * The class is computed by the inline script in resources/views/app.blade.php before first paint and written to
 * <html data-device="phone|tablet|desktop" data-density="regular|compact">:
 *   - under 768 CSS px: phone (bottom tab bar, sheets, card lists);
 *   - mouse or trackpad (hover + fine pointer) from 900 px: desktop (sidebar, tables, modals, shortcuts), compact density under 1100 px;
 *   - touch from 1280 px: desktop; everything else: tablet (icon rail).
 * CSS reads the attribute through the Tailwind variants md:/lg: (redefined in app.css) and phone:/tablet:/desktop:;
 * React reads it through useDevice(). The server sends a User-Agent hint (Inertia prop `device`) that only seeds the
 * attribute for the first render; the client rule always wins.
 */
export type Device = 'phone' | 'tablet' | 'desktop';
export type Density = 'regular' | 'compact';

const EVENT = 'opes:device';

export function currentDevice(): Device {
    if (typeof document === 'undefined') return 'desktop';
    const v = document.documentElement.dataset.device;
    return v === 'phone' || v === 'tablet' ? v : 'desktop';
}

export function currentDensity(): Density {
    if (typeof document === 'undefined') return 'regular';
    return document.documentElement.dataset.density === 'compact' ? 'compact' : 'regular';
}

function subscribe(cb: () => void) {
    // The inline script recomputes on resize and pointer changes and fires `opes:device` when the class changes.
    window.addEventListener(EVENT, cb);
    window.addEventListener('resize', cb);
    return () => { window.removeEventListener(EVENT, cb); window.removeEventListener('resize', cb); };
}

/** Current device class: 'phone' | 'tablet' | 'desktop'. */
export function useDevice(): Device {
    return useSyncExternalStore(subscribe, currentDevice, () => 'desktop');
}

export function useDensity(): Density {
    return useSyncExternalStore(subscribe, currentDensity, () => 'regular');
}

export const useIsPhone = () => useDevice() === 'phone';
export const useIsTablet = () => useDevice() === 'tablet';
export const useIsDesktop = () => useDevice() === 'desktop';

/** Desktop and at least `min` CSS px wide (master-detail split uses 1280). */
export function useDesktopAtLeast(min: number): boolean {
    const desktop = useIsDesktop();
    const wide = useSyncExternalStore(subscribe, () => window.innerWidth >= min, () => true);
    return desktop && wide;
}
