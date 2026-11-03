# Brand: OPES Hôtels Soft

Sources (never edited): `logo/`. Generated assets: `public/brand/*`, `public/favicon*`, `public/apple-touch-icon.png`,
`public/icon-*.png`, `public/site.webmanifest`.

## Regenerate

```
php scripts/build-brand-assets.php      # needs PHP GD; trims margins, rewrites every file listed above
npm run build
```

Tests: `DB_DATABASE=opeshotelsoft_test_br1 php artisan test --filter=BrandTest`.

## Files

| File | Use |
|---|---|
| `brand/emblem.png` (512), `emblem-128`, `emblem-64` | Icon only, transparent. Fine on dark or light. |
| `brand/logo-wordmark.png` (1200), `logo-wordmark-small.png` (480) | Full logo, dark letters: light backgrounds or a white plate only. |
| `brand/og-image.png` | 1200x630 social card, wordmark on white. |
| `favicon.ico` (16/32/48), `favicon-32.png`, `apple-touch-icon.png`, `icon-192/512.png`, `site.webmanifest` | Browser and install icons. Theme colour `#0b1a33`. |

React: `resources/js/Components/ui/BrandLogo.tsx` with `variant` = `emblem` | `wordmark` | `lockup` (emblem + HTML text for dark headers).

## Rules

- Dark backgrounds: emblem or lockup. Wordmark never on dark navy; put it on a white plate (padding at least 12 px) if the area is dark.
- Minimum sizes: emblem 16 px; wordmark 160 px wide; PDF footer mark 2.6 mm.
- Clear space: at least half the emblem height on every side.
- Never recolour, stretch, rotate, add glow, shadow, outline or gradient.
- Hotel branding (per-hotel logo and letterhead) is separate and is never replaced: on PDFs the platform mark is only a tiny "Powered by" line in the footer.
