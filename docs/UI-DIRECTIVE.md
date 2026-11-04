# OPES Hôtels Soft: complete UI/UX audit directive (owner, 2026-11-04)

This is the owner's full directive, condensed section by section. It extends docs/PLAN-UI-AUDIT.md and docs/UI-STANDARD.md. Preserve the brand identity (logo, navy and gold palette); do not redesign arbitrarily. Audit first, fix systematically, re-audit, mark a page PASS only when every applicable criterion holds. Do not stop after finding problems; cover every discoverable route; no blocker may be silently ignored.

The product: hotels, groups, chains and networks; owners, managers, reception, cashiers, housekeeping, maintenance, restaurant and bar, inventory, procurement, finance, HR, concierge, guests, loyalty members; customer web/mobile app; employee web/mobile app; loyalty and membership cards usable across participating hotels. Serious hospitality software: no gaming or neon look.

## Rules by topic
1. **Scope.** Every page, screen, route, form, modal, table, card, menu, dashboard, filter, button, icon, chart, dropdown and responsive state. Fix where possible, do not only report.
2. **Colour collisions (first priority).** Check text, buttons, icons, badges, nav active states, disabled, hover, focus, validation, table rows, charts, tooltips, dropdowns, modals, alerts, toasts, KPI cards, sidebar, top and bottom nav, dark and light sections, hotel cards, loyalty and VIP. Keep the palette; fix with semantic usage, shades, tones, opacity, surfaces. WCAG AA for text, strong contrast for critical actions, clear surface separation, nothing by colour alone.
3. **No glow.** No neon, outer glow, colour halos, glowing icons, buttons, borders, badges, cards, saturated gradients as fake glow. Icons are clean vector, consistent stroke, coloured intentionally.
4. **Mobile responsiveness.** Test 320, 360, 375, 390, 412, 430, 480 px. No horizontal overflow, no compressed desktop, no tiny controls, no desktop tables forced in, at most two columns for simple cards, single column when complex, drawers, sheets and progressive disclosure. Touch targets about 44 px. Reflow, do not shrink.
5. **Mobile native feel.** Sticky nav, bottom nav of 4 to 5 destinations plus More, touch feedback, scrolling, safe areas, sheets, full-screen forms where apt, native-feeling tabs, swipe-friendly cards where apt, proper viewport, purpose-built phone screens.
6. **Desktop responsiveness.** Test 1024x768, 1280x720, 1366x768, 1440x900, 1536x864, 1600x900, 1920x1080, 2560x1440. Sensible maximum content widths (readable line length, forms not stretched, cards not oversized); operational screens (POS, reservations, front desk, room boards, inventory, housekeeping boards) may use full width.
7. **Desktop native feel.** Persistent navigation, keyboard navigation and shortcuts, focus states, dense operational screens, table interactions, master-detail, context menus where apt, split panels, sticky headers and table controls, column resizing where useful, search-first, batch actions. Operational software, not a marketing site.
8. **Cramping.** Text touching borders, icons near text, buttons touching, cramped forms, table rows too small, modals pressed to edges, overcrowded KPIs. Spacing scale 4, 8, 12, 16, 24, 32, 48.
9. **Overlap.** Text over icons or images, badges over titles, dropdowns under others, sticky headers covering content, modals behind nav, tooltip and select clipping, chart labels, floating buttons covering content, bottom bars covering the last content. Fix z-index and layout.
10. **Distortion.** Logos, hotel photos, avatars, icons, QR codes, charts, thumbnails keep aspect ratio and correct object-fit; circles stay circles.
11. **Typography.** Keep the approved families. Mobile: body 16, supporting 14, caption 12 to 13, section title 18 to 20, page title 24 to 28, KPI 26 to 32. Desktop: body 14 to 16, supporting 13 to 14, table 13 to 14, section 18 to 22, page 26 to 32, KPI 28 to 40. Never tiny operational text. Audit line height, letter spacing, weight, truncation, wrapping, tabular figures for money and numbers.
12. **Eye strain.** Tiny fonts, weak contrast, excessive uppercase, long text, glaring backgrounds, oversaturated colours, density, thin weights, poor line spacing, unreadable charts. Designed for full shifts.
13. **Accessibility (WCAG 2.2 AA).** Contrast, keyboard, tab order, visible focus, screen reader labels, form labels, ARIA, button and icon names, error announcements, required indication, modal focus traps, skip navigation, reduced motion, colour-blind usability, zoom 100, 125, 150 and 200 percent without broken workflows, text scaling, touch size.
14. **Visual impairment.** Low vision, reduced contrast sensitivity, colour blindness, presbyopia, fatigue. Critical status uses icon plus text plus colour (for example Overdue plus warning icon plus semantic colour).
15. **Buttons.** One consistent system: height, radius, padding, font, icon position, hover, active, focus, disabled, loading. Primary, secondary, tertiary, ghost, destructive. No gradients, glow, extreme shadows, odd icon backgrounds, needless pills.
16. **Forms.** Labels, placeholders, helper text, required, validation, error position without layout jump, input heights, select, date, currency, phone, search, textarea, checkbox, radio, switch, file upload, multi-select; never cramped, labels never only placeholders.
17. **Filters.** Desktop: efficient toolbar. Mobile: filter sheet or drawer with active-filter count, never eight desktop filters on one row, apply and reset always visible, date ranges that do not wrap badly.
18. **Tables.** Desktop: alignment, widths, sticky headers, sorting, filtering, pagination, row actions, bulk selection, empty, loading and error states. Mobile: cards, expandable rows, priority columns, horizontal scroll only where truly needed; important actions stay reachable.
19. **Navigation.** Desktop sidebar hierarchy, collapsed state, active, hover, icons, labels, nested modules, breadcrumbs. Mobile: 4 to 5 bottom destinations plus More.
20. **Icons.** One family (Lucide), same weight and stroke, correct size and alignment, semantic meaning, accessible labels; no mixing of filled, outline, 3D or neon.
21. **Cards.** Not too many borders, shadows, nested cards or decorative backgrounds; consistent radius, padding and hierarchy.
22. **Shadows.** Only for meaningful elevation, subtle and neutral; never coloured, gold, blue or halo.
23. **Borders.** Thickness, radius, dividers; not a border around everything.
24. **Charts.** Readable, accessible colours, axis labels, tooltips, responsive, legends, currency, percentage and date formatting; simplified on phones.
25. **Empty states.** Professional and explanatory for every data page.
26. **Loading states.** Skeletons, progress and button loading; no blank screens or layout jumps.
27. **Error states.** API, validation, timeout, authentication, permission, offline, server; understandable, never raw exceptions.
28. **Offline.** Surfaces that work offline show Offline, Syncing, Synced, Failed, Conflict clearly, not by colour alone.
29. **Brand.** Keep logo and palette, premium but not excessive, subtle African hospitality heritage only where appropriate, photography only where meaningful; functionality first.
30. **Images.** Sharp, correct ratio, efficient, responsive sizes, no stretching or awkward crops, alt text.
31. **Loyalty and membership UX.** VIP pages, wallet, digital card, network membership, benefits, points, tiers, expiry, cross-hotel access, stay credits; premium but the same design system.
32. **Role-based UI.** Owner, manager, receptionist, cashier, housekeeping, maintenance, restaurant, bar, inventory, procurement, finance, HR, concierge, guest, loyalty member each see what fits their duties; no owner-level analytics for everyone.
33. **Customer app.** Hotel discovery, search, booking, room selection, checkout, payments, reservations, loyalty card, benefits, membership, requests, room service, restaurant, spa, concierge, folio; mobile first.
34. **Employee app.** Task-first: staff home, assigned tasks and status, notifications, guest requests, housekeeping and maintenance jobs, room service, escalations, supervisor approvals, offline sync; uncluttered.
35. **Performance UX.** Image weight, table and chart rendering, sluggish transitions, needless animation, mobile browsers.
36. **Animation.** Subtle only; no pulsing, glowing or distracting motion; honour prefers-reduced-motion.
37. **Page fit.** No horizontal scroll, nothing hidden behind nav, footer or sticky elements, safe areas, correct sidebar width, correct tablet behaviour.
38. **Tablet.** Test 768, 820, 834, 1024; do not blindly inherit phone or desktop.
39. **Modals and drawers.** Width, height, scroll, close, Escape, focus trap, full-screen on phones for complex ones, footer buttons, destructive actions.
40. **Consistency matrix.** Buttons, inputs, selects, cards, tabs, tables, modals, badges, alerts, typography, icons, spacing, colours, shadows, radius, navigation, page headers: find every variant that does not belong and consolidate.
41. **Design tokens.** Colours, surfaces, text, borders, font family, size, weight, line height, radius, spacing, shadows, breakpoints, z-index; no one-off CSS.
42. **Responsive rule.** Compose, do not shrink. Phone 1 column (max 2 for simple cards), tablet 2 to 3, desktop 3 to 4 or more.

## Required outputs
- **Screen register** (every route): screen name, route, role, desktop status, tablet status, mobile status, colour, typography, spacing, responsiveness, accessibility, component inconsistencies, overlap or distortion, severity P0 (unusable or accessibility or functional blocker), P1 (major), P2 (moderate), P3 (polish), recommended fix, fixed or not, remaining blocker.
- **Final report:** A executive summary (screens audited, passed per form factor, screens needing correction, P0 to P3 counts); B design-system findings; C responsive findings (mobile, tablet, desktop, ultra-wide); D accessibility findings; E page register; F fix register; G remaining blockers.
- **Non-negotiables:** no branding change without need, no random colours, no glow, no shrunk fonts to fit, no hidden mobile function without an alternative, no accidental horizontal overflow, no overlap, no stretched images, no unreadable charts, no touch targets below safe size, no unclear active states, no colour-only status, no mixed icon families, no tiny operational text, no compressed desktop on phones, no completion claim without covering all discoverable routes.

## Coverage so far (before this directive)
Done and verified: token system and contrast pairs, glow removal with guard test, shared components, phone shell with bottom tab bar and More sheet, Lucide only, harness at 375, 768 and 1280, page audits of all folders, QA sweep of 114 staff routes, role menus, public and member pages. Gaps this directive adds: the wider width matrix (320 to 480, 820, 834, 1024 to 2560) and zoom levels; ultra-wide content width rules; typography baseline and tabular figures and uppercase reduction; chart audit; desktop-native features (shortcuts, command palette, context menus, column controls, batch actions, master-detail); standard loading, empty, error and offline states; task-first staff home and role-fitted dashboards; loyalty and membership UX audit; consistency matrix, token audit and the full register with severities.
