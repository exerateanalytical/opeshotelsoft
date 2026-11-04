# h1-tenancy notes (source-only review, read-only)

## Architecture confirmed
- `TenantContext` is a scoped container binding; `BelongsToHotel` filters group_id + (hotel_id when active, else whereIn allowedHotelIds when not null); `BelongsToGroup` filters group_id; both stamp on create. `Hotel` has its own group+allowed scope; `MemberAccount` group scope only when a group is set.
- `ResolveTenant` (alias `tenant`) validates X-Hotel-Id against the user's group and allow-list, and is prepended before SubstituteBindings so route-model binding is scoped. Applied only to `app/Modules/*/routes.php` (/api/v1). `public-routes.php` and web routes get no tenant unless the controller sets it (all public controllers reviewed do set it from a signed/credentialed record).
- Web (session) feature routes under routes/web/*.php are Inertia page renders only, except: /verify/{code} (HMAC code), /demo-*-login (config gated, not production), /g/order/{reservation} (signed URL), /account/* (MemberAuth sets group ctx from the member account and nulls the user resolver).
- `User::allowedHotelIds()` returns null for all_hotels, else pivot ids (empty array = no hotel -> X-Hotel-Id always 403, group-only reads return nothing for hotel tables).
- Mass assignment: every create/update reviewed uses `$request->validate()` arrays (validated() whitelist) or explicit key lists; group_id/hotel_id are guarded on the models that matter and stamped by the traits. No `$request->all()` into create/update (the two `->all()` hits are payload logging). `$request->except('format')` in ExportController only becomes query params of an InternalApi sub-request.
- SQL: every raw fragment with interpolation uses whitelisted values (`DimensionDefaults::KINDS`, fixed column list) or bound parameters; no sort column from input (`sort` hits are data fields). JSON path queries: none from input.

## Reviewed paths (repo-relative)
app/Platform/Tenancy/{BelongsToGroup,BelongsToHotel,ResolveTenant,TenantContext}.php, app/Platform/Http/Idempotency.php, app/Platform/ModuleServiceProvider.php, bootstrap/app.php, routes/api.php, routes/web.php, routes/web/*.php (route lists), app/Models/User.php, app/Http/Middleware/HandleInertiaRequests.php (110-150), app/Http/Controllers/ProfileController.php (update/password), app/Http/Controllers/DemoRequestController.php (grep), app/Http/Controllers/Auth/{DemoLoginController,DemoMemberLoginController}.php,
app/Modules/Tenancy/** (all), app/Modules/*/public-routes.php (all), app/Modules/Admin/Http/AuditController.php (grep), app/Modules/Platform/Http/WebhookController.php, app/Modules/Platform/Application/QueueWebhookDeliveries.php, app/Modules/Platform/Domain/WebhookSubscription.php,
app/Modules/MemberPortal/** (all files), app/Modules/GuestPortal/Http/{BookingEngineController,GuestOrderController,ManageController}.php, app/Modules/GuestPortal/Application/{PublicBookingResolver,GuestLinks,GuestOrderService(15-45)}.php,
app/Modules/Payments/Http/{PublicPayLinkController,PublicPaymentController(55-110)}.php, app/Modules/Payments/Application/{PayLinkService(110-135,255-275),PaymentService(148-185)}.php, app/Modules/Payments/public-routes.php,
app/Modules/Edge/** (CloudSync, RowStore, TablePolicy, EdgeSyncController, EdgeController 40-75, routes, ChangeFeed grep), app/Modules/Channels/Http/InboundController.php,
app/Modules/Documents/Http/{VerifyController,DocumentController(55-110),ExportController,ScheduledReportController}.php, app/Modules/Documents/Application/{IssuedDocuments(70-110),Exporter(1-80),InternalApi,ScheduledReportRunner,Console/SendScheduledReportsCommand}.php, app/Modules/Documents/Operations/{HrSheets(35-180 grep),OpsController(show)}.php, app/Modules/Documents/routes.php,
app/Modules/Insights/Http/InsightsController.php, app/Modules/Insights/Application/OwnerMetrics.php, app/Modules/Insights/routes.php, app/Modules/Reports/Http/ReportsController.php (1-130), app/Modules/Reports/routes.php,
app/Modules/Accounting/Application/Core/{Budgets,Deferrals,Intercompany,RecurringJournals,ManualEntries,PeriodLocking,DimensionDefaults,AuditTrail}.php, app/Modules/Accounting/Payables/{InvoiceApprovals,Dunning,BankFeed,InvoiceCapture}.php, app/Modules/Accounting/Close/MonthEndClose.php, app/Modules/Accounting/Tax/{VatReturns,Withholding,PayrollFilings,StampDuty,CountryProfiles}.php (grep + find helpers), app/Modules/Accounting/Support/Scoped.php, app/Modules/Accounting/Http/{AccountingController(125-145),Core/CoreController(280-310),FinanceExtrasController(280-295)}.php,
app/Modules/Guests/Http/{GuestController(85-150),GuestProfileController}.php, app/Modules/HR/Http/LifecycleController.php (235-260), app/Modules/HR/Application/Lifecycle/{LifecycleService(225-300),EContractService(118-150)}.php, app/Modules/HR/SelfService/SelfService.php (95-125), app/Modules/HR/Talent/public-routes.php, app/Modules/HR/Talent/Application/RecruitmentService.php (55-100), app/Modules/HR/Profile/Application/ProfileService.php (raw query lines),
app/Modules/Loyalty/Application/{CardService(12-35),LoyaltyService(90-116)}.php, app/Modules/Loyalty/Http/LoyaltyCardController.php (signatures), app/Modules/Loyalty/Domain/{Card,Membership}.php (traits),
app/Modules/Onboarding/Http/OnboardingController.php (35-55,95-115), app/Modules/Notifications/Http/NotificationController.php (115-135), app/Modules/POS/Http/PosController.php (190-210,255-270), app/Modules/Stock/Http/StockController.php (20-35), app/Modules/Stock/Application/PurchasingExtrasService.php (105-130), app/Modules/ServiceRequests/Application/ServiceRequestService.php (25-60),
tests/Feature/Platform/{TenancyIsolationTest,ArchitectureTest}.php (test list, scope test body), docs/SPEC.md (tenancy grep).
Plus repo-wide greps: all 60 withoutScope( / withoutGlobalScope(s) sites (listed and triaged), all DB::table/DB::select/raw sites (90+, triaged by file), ->all()/->except(/fill( sinks, hotel_id/group_id in validation rules, unscoped-model usages in Http layers, sort/orderBy from input, raw SQL interpolation.

## Checked and found SAFE (one line why)
- ResolveTenant X-Hotel-Id: hotel must be in user's group AND in allowedHotelIds; strict int compare on ints.
- HotelController show/update: Hotel global scope (group + allowed); group_id/business_date guarded; validation whitelists.
- UserController index/update: explicit group_id equality; hotel_ids filtered through scoped Hotel query (but see candidate on all_hotels/roles).
- StaffController/StaffDirectory: group_id + hotel pivot filter; returns id/name/roles only.
- Insights owner/group/alerts: hotel from ctx->requireHotelId, group() passes user->allowedHotelIds; Alert route bindings scoped; OwnerMetrics raw queries all filter hotel_id.
- Reports dashboard/group/forecast/channels: hotel from ctx or allowed list; raw queries filter r.hotel_id.
- Accounting raw DB::table users: every find-by-id goes through `Scoped::table()` (hotel_id) or `find()` helpers with hotel_id; Intercompany validates both hotels in group + allowed list; Consolidation requires all_hotels; DimensionDefaults $by whitelisted.
- Budgets/Deferrals/PeriodLocking/RecurringJournals/ManualEntries/InvoiceApprovals/Dunning/BankFeed/MonthEndClose/VatReturns: id lookups include hotel_id; unqualified `DB::table(...)->find($id)` calls only re-read a row just created or already tenant-checked.
- FinanceExtrasController::line: BankStatementLine unscoped but BankAccount::findOrFail (hotel-scoped) re-checks.
- CardService::find / LoyaltyService::findByCard: Card unscoped but Membership (BelongsToGroup) re-check -> other group's card = 404.
- MemberPortal: every read keyed on account->guest_id; cards via membership; invoices/folios filtered by guest_id; HotelScope::hotel uses group-scoped Hotel; chat conversation filtered by guest_id within hotel ctx; MemberAuth nulls staff user resolver; MemberAccount group_id guarded; register/verify scoped to the slug's group (cross-group account enumeration prevented by silent responses).
- GuestPortal booking API: group from slug; Hotel reads scoped by group ctx; manage token = HMAC(group|hotel|id|number|guest) so cross-group reservations cannot be opened; ManageController re-resolves with token and runs in the booking's hotel; GuestOrder uses signed URL bound to reservation+hotel.
- Public pay links: HMAC signature over code; intents filtered by payment_link_id; provider callbacks verified by driver signature; inbound MoMo HMAC with hotel callback secret.
- Documents verify: code = base36 id + HMAC(id|type|number) -> no enumeration; exposes only number/hotel name/amount/status (by design).
- DocumentController PDFs: data fetched through InternalApi as the current user (permission + tenant enforced by the resource endpoint); Payslip::findOrFail only after that succeeded.
- HR e-contract/offer public pages: 48-char token hash lookup, OTP; HrSheets unscoped Employee only after group-scoped Transfer/CompensationChange lookup (employee moved hotels).
- Lifecycle transfer: destination hotel scoped + allowed check; department/position verified against destination hotel.
- Onboarding copy: source hotel through scoped Hotel::findOrFail; group update whitelisted fields.
- Channels inbound: connection by uuid + driver signature verification; ctx set to the connection's hotel.
- Edge: node auth by HMAC over method/path/ts/body; pull filtered by node hotel/group; master tables rejected; 'hotels' row restricted to own id; rows with hotel_id/group_id keys checked (see candidates for the two gaps).
- GuestProfileController history/folios/loyalty: scoped() applies group + allowedHotelIds.
- NotificationController outbox: group + hotel/allowed filtering.
- PurchasingExtrasService requisition lines: ids come from scoped PurchaseRequisition rows.
- Demo logins: gated by DEMO_LOGINS and APP_ENV !== production.
- Audit log: causer filtered to users of the actor's group.
- Hotel::update('settings' array) and Group update: validated keys only; settings used only for description/photos/branding (authz only, not tenancy).

## Not fully traced (parent may want a look)
- Withholding::issue uses DB::table('suppliers')->find($supplierId) without hotel filter; only caller found is ProcurementService (supplier from a scoped invoice) - safe as used.
- ChangeCapture (edge) coverage of `users` rows not read; candidate marked low confidence.
- ProfileService (HR 360) raw lookups of reference tables (leave_types, tal_*) are id->name maps after scoped employee lookup; employee allowed-hotel enforcement relies on Employee BelongsToHotel.
