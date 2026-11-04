import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from 'react-hot-toast';

createInertiaApp({
    title: (title) => title ? `${title} — OPES Hôtels Soft` : 'OPES Hôtels Soft',
    resolve: (name) =>
        resolvePageComponent<any>(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        if (!el) return;
        // Keep <html lang> in step with the chosen language so number and date formatting follow it
        const syncLang = (p: { props?: Record<string, unknown> }) => { const l = p?.props?.locale; if (typeof l === 'string') document.documentElement.lang = l; };
        syncLang(props.initialPage);
        router.on('navigate', (e) => syncLang(e.detail.page));
        const root = createRoot(el);
        root.render(
            <>
                <App {...props} />
                <Toaster
                    position="top-right"
                    toastOptions={{
                        duration: 4000,
                        style: {
                            borderRadius: '10px',
                            background: '#1e293b',
                            color: '#f1f5f9',
                            fontSize: '0.875rem',
                            border: '1px solid #334155',
                        },
                        success: { iconTheme: { primary: '#10b981', secondary: '#fff' } },
                        error: { iconTheme: { primary: '#ef4444', secondary: '#fff' } },
                    }}
                />
            </>,
        );
    },
    progress: {
        color: '#10b981',
        showSpinner: false,
    },
});
