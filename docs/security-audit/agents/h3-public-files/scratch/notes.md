# h3-public-files notes (source inspection only, read-only)

## Reviewed paths

Routes
- app/Modules/{Admin,Channels,Documents,Edge,GuestPortal,HR,HR/Talent,Notifications,Payments,Reservations,Tenancy}/public-routes.php
- routes/web.php (top 80 lines), routes/web/{public,landing,talent,member,documents,payments,edge,hr,messaging}.php

Controllers / services
- app/Modules/GuestPortal/Http/BookingEngineController.php, ManageController.php, GuestOrderController.php
- app/Modules/GuestPortal/Application/GuestLinks.php, PublicBookingResolver.php, ManageService.php (100-160), GuestOrderService.php (place)
- app/Modules/Reservations/Revenue/Http/PublicUpsellController.php, Application/BookingSiteRevenue.php (86-135), CorporateService.php (find, bookingFields)
- app/Modules/Reservations/Experience/Application/DigitalCheckin.php, Http/ExperienceController.php (rooming import)
- app/Modules/Reservations/Domain/Reservation.php (public_code generation)
- app/Modules/Payments/Http/PublicPaymentController.php, PublicPayLinkController.php; Application/PayLinkService.php, PaymentService.php (1-260); Drivers/{MtnMomo,OrangeMoney,Card,DryRun}Gateway.php, HmacSignature.php; Domain/PaymentLink.php (isPayable)
- app/Modules/Documents/Http/VerifyController.php, BrandingController.php; Application/{DocumentRenderer (40-80), TemplateRenderer, Letterhead (grep), Watermark (grep), IssuedDocuments (findByCode), Exporter}.php
- app/Modules/HR/Http/ContractSigningController.php; Application/Lifecycle/EContractService.php (token, otp, signature, pdf parts)
- app/Modules/HR/Talent/Http/CareersController.php, TalentController.php (cv); Application/RecruitmentService.php (apply, offers)
- app/Modules/HR/Domain/Employee.php (photo_url / photoDataUri), HR/Application/HrService.php (storeDocument)
- app/Http/Controllers/DemoRequestController.php, PublicGroupPageController.php
- app/Modules/Notifications/Http/WhatsAppWebhookController.php; Application/WhatsApp/WhatsAppClient.php (validSignature), WhatsAppMessenger.php (receive/inbound); Application/TemplateRenderer.php; Drivers/MailDriver.php
- app/Modules/Edge/Http/EdgeSyncController.php; Application/{CloudSync, Signature, TablePolicy, RowStore (grep)}.php
- app/Modules/Channels/Http/InboundController.php; Application/Drivers/BaseDriver.php (verify, xml), OtaXmlDriver.php (acknowledge)
- app/Modules/Admin/Http/TokenController.php, app/Modules/Tenancy/Http/AuthController.php (read only to classify; auth is another hunter's scope)
- app/Modules/FrontOffice/Http/FrontDeskExtrasController.php (170-260: registration card uploads/serve)
- app/Modules/Onboarding/Http/OnboardingController.php (import), app/Modules/Accounting/Support/Sheets.php (grep)
- app/Platform/Tenancy/BelongsToGroup.php; app/Modules/Guests/Domain/Guest.php (head)
- resources/views/pdf/layout.blade.php, registration-card.blade.php (signature), app/Modules/HR/Resources/views/econtract.blade.php, Documents views (grep for {!!)
- resources/js grep for dangerouslySetInnerHTML (5 uses)
- vendor/barryvdh/laravel-dompdf/config/dompdf.php defaults (no config/dompdf.php published in the app)
- app/Http/Middleware/SecurityHeaders.php (grep nosniff/CSP)

## SAFE list (one-line reasons)

- Manage-booking token (GuestLinks::manageToken): HMAC-SHA256(app.key) over group|hotel|id|number|guest_id, compared with hash_equals; unguessable. Never expires (hardening note).
- Pay link /pay/{code}/{sig}: code = 8 random alnum (Str::random), sig = first 10 hex (40 bits) of HMAC(app.key); hash_equals; expiry + status checked by isPayable(); amount bounded by balance minus pending intents; folio posting happens only in settle().
- Provider callbacks: intent looked up by UUID reference + provider; MTN requires relay X-Signature HMAC over body with hotel callback_secret (unsigned rejected); Orange compares notif_token stored at initiate; Card HMAC x-token; DryRun HMAC with app key. Replay blocked by PaymentCallback unique (provider, sha256(body)). Amount is never taken from the callback. Note: callbacks trust the signed body status rather than re-querying the provider (hardening).
- Inbound MoMo relay: HMAC over body with callback_secret (app key only outside production), hash_equals.
- WhatsApp webhook: X-Hub-Signature-256 HMAC with app_secret, hash_equals; verify handshake hash_equals; inbound messages stored, no command execution found; dedupe by wamid.
- Edge sync: HMAC over method/path/timestamp/body-hash with per-node secret, hash_equals, 300 s skew; push idempotent per batch_key; tenantViolation checks hotel_id/group_id of payload AND stored row, parent ownership for child tables, master tables rejected, financial append-only; table name regex + Schema::hasTable; columns filtered against Schema::getColumnListing (no SQL injection via column names).
- Channels inbound: per-connection uuid + HMAC(webhook_secret) with hash_equals; XML parsed with LIBXML_NONET (no external entity fetch; simplexml does not substitute entities without LIBXML_NOENT); ack XML escapes external_ref with htmlspecialchars ENT_XML1. Exception: sandbox without secret (candidate, low).
- Document verify: code = <12 alnum>-<10 hex>, regex-validated; only type/number/hotel/time/amount(non-personal types)/status + hash prefix match returned; no names.
- Careers apply: mimes pdf,doc,docx + max 5 MB; stored under talent/cv/{hotel}/{uuid}.{ext} on the private local disk; filename never from user input; download requires talent.recruit|talent.interview and uses response()->download (Symfony sanitises Content-Disposition). Duplicate check by email per post.
- Offer links: Str::random(48), stored as sha256, expiry, status 'sent' only, token cleared on decline/withdraw.
- E-contract signing: 48-char random token stored hashed, LINK_DAYS expiry, 6-digit OTP hashed, attempts cap (OTP_MAX_ATTEMPTS), OTP sent only to the employee's stored phone/email, signature must be data:image/png with PNG magic and <= 400 KB; body rendered with nl2br(e()).
- Demo request: honeypot + throttle 5/10min, validated lengths, stored only (no mail sent, no admin React page renders it with raw HTML), log entry only.
- Guest order link: Laravel 'signed' middleware with expiry (day after departure), hotel param cross-checked against reservation; items priced server-side by PosService.
- Branding: logo/watermark limited to png/jpg (mimes + getimagesize), stored on private local disk with server-generated names, embedded in PDFs as data URIs (no remote fetch); colours regex-validated #rrggbb; custom footer/bank details rendered with {{ }}.
- dompdf: app does not publish config/dompdf.php, so vendor defaults apply: enable_remote=false, enable_php=false, chroot=base_path(). No user-controlled <img src> found in templates (logo/watermark/signature are data URIs; Employee photoDataUri reads only from photo_path set by server-side code: ClockService / privacy).
- TemplateRenderer (Documents): entire body HTML-escaped first, whitelisted placeholders replaced with escaped values, {!!/@php/<? flagged by unknown(); never compiled by Blade. HR letters and template previews (Onboarding/Templates.tsx dangerouslySetInnerHTML) consume this output.
- dangerouslySetInnerHTML uses: Loyalty/Card.tsx, Member/Card.tsx, Payments/Links.tsx inject server-generated QR SVG (QrCode::svg of a URL built from server data); Onboarding/Branding.tsx injects branding-preview blade (escaped) ; Onboarding/Templates.tsx injects TemplateRenderer output. All server-generated, no raw user HTML.
- Blade {!! !!}: layout.blade.php watermark item (text passed through e(), image is data URI from private disk), templateNote/docHtml (TemplateRenderer output), econtract body nl2br(e()).
- Mail: Notifications MailDriver uses Mail::raw (plain text) to a single recipient; Symfony Mailer rejects header CR/LF; no HTML mail templates with user data found.
- No exec/shell_exec/proc_open/passthru/system/unserialize/eval/variable include in app/.
- Staff upload endpoints (accounting attachments, HR documents, people discipline docs, task photos, front-desk ID photo/signature, onboarding/rooming imports): server-generated names (uuid/random hex), private 'local' disk, mimes/finfo checks on the image paths, downloads behind permission checks (not deeply re-verified; outside the public scope).
- Public booking availability/book validation: dates bounded, counts bounded, only public+active rate plans unless member proved by number + last name, hotel code must be active, prices from Pricing service (no client-supplied amounts).

## Hardening notes (no concrete victim/resource or already covered)

- Manage token never expires and is stateless; a leaked confirmation e-mail is a permanent credential for modify/cancel/pay/check-in on that booking. Consider binding an expiry (departure + N days) into the HMAC payload.
- PublicBookingResolver: 403 vs 404 vs 422 distinctions (see informational candidate).
- Provider callbacks settle on the signed body's status; a defensive re-query of the provider status API before posting money would protect against relay/secret compromise.
- Edge: no nonce (candidate, low); a compromised node can write any group-level operational row with hotel_id NULL and group_id = its group (e.g. guests, loyalty memberships points_balance via LWW) -- by design the node is trusted for its hotel, but group-wide write scope is wider than one hotel.
- Channels: TripAdvisor meta pull builds a URL with the hotel code as the group slug segment (/book/{hotel->code}) -- functional bug, not security.
- Careers public posts expose description/requirements as text; React escapes them (no raw HTML).
- Hotel settings['photos'] are rendered as <img src> on the public site; staff-controlled only; javascript: URLs cannot execute from img src.
- Pay link show() returns the link label ("Folio 1234" / "Booking N") and hotel name to anyone holding the signed URL -- acceptable.
- DemoRequest rows are only in the DB/log today; if an admin list page is added, keep React default escaping.
- DigitalCheckin accepts a signature data URL with only a prefix check (stored as text, rendered via escaped img src in registration-card PDF); harmless but inconsistent with EContractService::assertSignature.
- Member portal public auth endpoints (account/register, login/code...) and TokenController/AuthController were not analysed (auth is another hunter's scope).
- CSV import paths (Onboarding Importer, RoomingImporter, bank statement import) were not reviewed for formula handling since they are staff-only and import, not export.
