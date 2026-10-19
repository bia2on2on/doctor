# راه‌اندازی یکپارچه بر اساس نوع مرکز (Onboarding Facility Wizard)

Receipt: 2026-10-15T07:22:10Z. Base: 08c64134a8547b967ba889d59d5e0eca0981f5b5. No open PRs at start.

## رفتار
- تنها ورودی قابل مشاهده: ویزارد `cpms-wizard`. نخستین صفحه (پیش از bootstrap و پس از آن گام «نوع مرکز») می‌پرسد: «چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟» و هر ۱۲ نوع مصوب را فارسی نشان می‌دهد (`FacilityTypeCatalog`).
- نوع مرکز فقط انتخاب نمایشی/پیکربندی است؛ مجوز نیست و مرز مستأجر (Organization → Clinic → Location) را تعریف نمی‌کند.
- انتخاب پیش از bootstrap فقط در user-meta همان مدیر وردپرس (POST + nonce + `manage_options`) ذخیره می‌شود؛ هیچ Clinic/عضویت/داده‌ای ساخته نمی‌شود. پس از bootstrap همان انتخاب در گام ویزارد با `setup.facility_type` (Clinic-scoped Settings، با احراز Clinic-local CONFIG) ذخیره و از user-meta قابل ازسرگیری است. GET هیچ نوشتنی ندارد.
- جریان‌ها: مطب ساده (نام، مکان اصلی/بیشتر، پزشک، منشی اختیاری، برنامه، رزرو، بازبینی)؛ درمانگاه چندپزشکه (هویت، تعداد پزشکان، مکان‌ها، پذیرش/مالی، برنامه، رزرو، بازبینی)؛ ساختمان پزشکی (هویت، مطب‌های مستقل، مدیریت مستقل، مرز درمانگاه، انتساب کارکنان، مکان‌ها، محدوده جداگانه)؛ بیمارستان/شبکه (ساختار سازمان، درمانگاه‌های سرپایی، مدیران مجاز، مکان‌ها، فرایندهای سرپایی).
- گام «مکان‌ها» به مدیریت مکان موجود پیوند می‌دهد و منطقهٔ زمانی IANA صریح برای هر مکان را الزام می‌داند (بدون پیش‌فرض، بدون fallback ردیف اول).

## محدودیت‌های صریح (blocker)
- ساخت چند Clinic مستقل: هیچ مدل اختیار سازمانیِ مصوب/منجمدی در مخزن یافت نشد؛ بنابراین ویزارد با اختیار مدیر وردپرس چند Clinic نمی‌سازد و این جریان با پیام `data-cpms-multi-clinic-blocked` مسدود و شفاف اعلام می‌شود. Bootstrap موجود فقط تک‌Clinic، یک‌باره، با تأیید صریح، nonce، قفل ردیف، audit اتمیک و بدون هویت پزشک خودکار است.
- بیمارستان: بخش بستری و HIS پشتیبانی نمی‌شود (`data-cpms-inpatient-unsupported`)؛ فقط فرایندهای سرپایی موجود پوشش داده می‌شود. ماژول ساختگی ساخته نشده است.

## امنیت
Nonce/CSRF، `manage_options` برای انتخاب پیش از bootstrap، Clinic-local CONFIG برای ذخیره در ویزارد، whitelist سمت سرور برای نوع/ساختار، عدم اعتماد به clinic_id ارسالی، عدم ساخت هویت پزشک، عدم اختیار بالینی سراسری، و حفظ سرویس‌های موجود (`FirstPracticeSetupService`, `LocationService`, Schedule/Booking موجود) بدون سرویس موازی یا migration جدید.

## سازوکار تطبیقی واقعی (PR #230، ادامه)
- `FacilityTypeCatalog::flow_step_ids/flow_required_step_ids` گام‌های واقعاً نمایش‌داده‌شده و الزامی/اختیاری هر جریان را تعیین می‌کند؛ `CpmsSetupWizard::active_steps` ناوبری، پیشرفت و گام بعدی را از همان فهرست می‌سازد (بیمارستان «سلامت سیستم» دارد، مطب ساده ندارد؛ ساختمان پزشکی گام رزرو ندارد).
- کامل‌بودن گام‌ها با دادهٔ واقعی راستی‌آزمایی می‌شود: نام canonical کلینیک، مکان فعال دارای منطقهٔ زمانی، تعداد پزشک فعال ≥ تعداد موردنظر (`setup.intended_clinicians`؛ چندپزشکه دست‌کم ۲)، و وجود برنامهٔ کاری واقعی (`schedule_days`). اعلام عدد به‌تنهایی گام را کامل نمی‌کند.
- ذخیرهٔ ویزارد برای گام الزامیِ ناقص جلو نمی‌رود و خطای فارسی نشان می‌دهد؛ اتمام (finish) برای جریان انتخاب‌شده همهٔ گام‌های الزامیِ باقی‌مانده را نام می‌برد و برای ساختمان پزشکی fail-closed است. نصب‌های قدیمیِ بدون انتخاب نوع مرکز، رفتار قبلی (نام کلینیک + یک پزشک) را حفظ می‌کنند.

## Organization Manager provisioning (2026-10-18)

The Classic wizard links multi-Clinic users to Organization Manager provisioning for the narrow transaction that creates a Clinic and its primary Location. Access is checked against an active Organization and an explicit Organization Manager membership; the selected Organization ID is context, not authority. The Owner has separately approved a narrow first-manager bootstrap: a currently authenticated WordPress Administrator may use the Classic RTL bootstrap screen to select an existing active Organization and existing WordPress user, confirm the choice, and submit a valid nonce. Bootstrap creates only that Organization membership and its audit event; it does not attach users to Clinics or grant clinical/financial/PHI access, and Administrator status does not bypass normal Organization or Clinic DENY checks. There is no implicit first-row selection or install/upgrade/login bootstrap. The 12-item facility catalogue is unchanged. Adaptive configuration and completion for facility types were unimplemented at the time of that review; they are now implemented in the adaptive facility-type slice below (Draft PR #246, unmerged), while multi-Clinic creation itself remains behind Organization Manager provisioning and no unsupported inpatient workflow is claimed anywhere.

## Post-onboarding growth (2026-10-18 slice)

Facility growth no longer requires reinstalling the plugin or restarting the wizard:

- **Another Clinic** (a treatment center adding a Clinic): *سازمان‌ها → مدیریت سازمان و درمانگاه‌ها* — Organization Manager authority, atomic Clinic + primary Location.
- **Clinic settings / disable / reactivate**: *سازمان‌ها → مدیریت درمانگاه‌ها* — Organization Manager authority resolved **per operation** (`cpms_org_clinic_create` / `cpms_org_clinic_update` / `cpms_org_clinic_lifecycle`, each `DENY > GRANT > Organization-Manager preset`); the Organization selector admits an Organization for which the actor holds **any** of the three, and profile/lifecycle controls are rendered only for the capabilities the actor actually holds. Explicit Organization and Clinic selectors, no first-row default. Suspension preserves medical, financial, audit and backup history: `TrustedClinicEstablisher` deliberately does **not** gate on `cpms_clinics.status` (an earlier blanket `clinic_suspended` scope block was reverted), so existing care/finance/export/backup continuity survives while **new demand** is refused operation-by-operation (hold, confirm, reschedule — patient and staff, staff-created appointment, patient self-enrollment and enrollment destination offers, slot generation, public availability/quote/shortcode advertisement, reception slot advertising, booking-selection OTP).
- **Another Location** (a doctor adding a second practice): *مدیریت مطب → شعبه‌ها* — Clinic-scoped `cpms_config` with an explicitly selected authorized Clinic.

Schema head is now `2026_10_18_0029_clinic_lifecycle.php` (adds `cpms_clinics.status`, `status_updated_at`, `status_updated_by_wp_user_id`). The twelve-type catalogue is unchanged and facility-type transitions remain unsupported (see [`post-onboarding-clinic-location-management.md`](post-onboarding-clinic-location-management.md)).

## Adaptive facility-type slice (2026-10-18, Writing Agent B — Draft, unmerged)

Receipt: 2026-10-18T17:18:36Z. Base: 32be1053b24b1bc359416991123030918a8fdb80 (live `main` verified identical at start; no open PRs at start).

- **Adaptive Persian presentation profiles:** `FacilityTypeCatalog::type_profile()` maps each of the 12 approved types (unchanged ids/labels/flows) to Persian `unit` / `clinician` / `clinicians` / `name_label` wording (e.g. dental → «دندانپزشک(ان)», doctor practice → «مطب», hospital → «نام بیمارستان (فقط محدودهٔ سرپایی)»). The wizard clinic/doctors/review steps render these labels RTL. Presentation only: no authorization, no tenant semantics, no change to flow-level validation (`save_doctors` still enforces ≥2 for multi-doctor flows, completion still verified against real data). Unknown/empty ids fall back to the generic legacy profile, so installations without a facility selection keep the exact previous wording («نام کلینیک» + «پزشکان»).
- **Structure persisted in Clinic scope:** `save_facility` now stores the whitelisted structure override in `setup.facility_structure` (Clinic-scoped Settings) next to `setup.facility_type`; `selected_structure()` falls back to it when the per-admin user-meta is empty. Every authorized manager of the same Clinic therefore resumes the same adaptive flow. GET remains strictly read-only.
- **Safe configuration evolution (non-destructive):** the facility/structure selection may be revisited at any time — including cross-flow evolution after completion, which is the established browser-acceptance journey (`admin.multi.facility_resumes`: a finished doctor-practice setup evolves into a multi-doctor clinic). Evolution writes ONLY the Clinic-scoped presentation/config keys and the acting admin's user-meta: tenant rows (Clinic/Location/Membership/Clinician) are never touched, the completion marker is never silently deleted, and the newly selected flow reports its own missing required steps honestly. Invalid type/structure input is rejected with zero partial state (settings and user-meta untouched). An earlier draft of this slice refused cross-flow changes after completion; that refusal was removed because it contradicted the sanctioned acceptance journey — recorded here as evidence, not hidden.
- **Invariants preserved:** medical-building completion remains fail-closed in the wizard and routes Clinic creation to Organization Manager provisioning (`cpms-organizations`) without guessing tenant scope; hospital remains outpatient-only (`data-cpms-inpatient-unsupported`, `flow_supports_inpatient()` false); multi-Clinic creation via the wizard stays unsupported; Organization → Clinic → Location boundaries untouched.
- **Tests:** `tests/Integration/FacilityAdaptiveFlowEvolutionTest.php` (catalog coverage for all 12 types, adaptive RTL render for dental/doctor-practice, hospital outpatient-only render, cross-admin structure resumability without writes, same-flow evolution keeping completion + tenant rows, refused cross-flow transitions with no partial write, invalid-input rollback, `manage_options`+nonce authorization for the pre-bootstrap selection, medical-building fail-closed finish + provisioning disclosure) and one added unit method in `tests/Unit/Onboarding/FacilityTypeCatalogTest.php`. Existing wizard contracts (`SetupWizardTest`, `FacilityOnboardingWizardTest`, acceptance scripts) are untouched.

### Product-review corrective batch (2026-10-18, same Draft PR — verdict: PRODUCT REJECT addressed)

Receipt: 2026-10-18T18:26:38Z. Head before batch: `13bf677` (26/26 checks green). Coordination: PR #247 (Agent A) owns LocationAdminPage/StaffManagementPage/CustomRoleDefinitionsPage and Location/Role services — this batch touches none of them; wizard handoff links were deliberately left without clinic_id parameters because the destination screens resolve Clinic through their own explicit selectors.

- **Adaptive beyond labels (all 12 types):**
  - Per-type honest operational `guidance` in `type_profile()` rendered in the flow summary (`data-cpms-facility-guidance`); guidance states no clinical/regulatory requirement and no booking default is invented. Legacy/unselected installs get no guidance line (previous wording preserved).
  - Intended-clinician input `min` now matches server validation: 2 for multi-provider flows, 1 for the dedicated doctor-practice flow (secretary/users stay optional).
  - **Contradictory required-users semantics fixed by making it explicitly optional** (not by inventing staff requirements): `users` is removed from `flow_required_step_ids('multi_doctor_clinic')`, the flow summary says «کارکنان پذیرش و مالی (اختیاری)», and the step renders `data-cpms-users-step-optional` — an informational link step is never a fake "required" gate.
  - **Hospital outpatient-only made explicit and enforced in-flow:** the hospital `health` step is conditional — it renders the inpatient/HIS disclosure plus an explicit acknowledgment checkbox persisted Clinic-scoped (`setup.outpatient_only_ack`); `step_is_complete('health')` and `save_health()` require it for `hospital_network` only. Non-hospital/legacy flows keep the informational health step unchanged. (Superseded by the corrective batch below: the acknowledgment must now be freshly affirmed on every health-step submission and is reset on any type/structure change or progress restart — rejected submissions still never destroy stored history.)
  - **Medical-building handoff:** the review step renders an explicit Organization Manager provisioning handoff card (`data-cpms-building-handoff`): the wizard configures only the explicitly-scoped authorized Clinic, never creates or infers tenants, and Clinic creation happens only at «سازمان‌ها → مدیریت سازمان و درمانگاه‌ها». Finish stays fail-closed.
- **Cross-admin resumability fixed:** `selected_facility_type()` now falls back to the persisted Clinic-scoped `setup.facility_type` (same pattern as structure). A second authorized admin with explicit membership and fresh ScopeContext sees the checked radio, the flow summary and the hospital/medical-building disclosures in real rendered HTML; GET still performs zero writes (settings and user-meta), and zero-membership actors get no inferred Clinic (TrustedClinicEstablisher `no_membership` throw is test-pinned).
- **Evolution semantics (corrected documentation):** cross-flow facility/structure evolution after completion is **ALLOWED and non-destructive** — it is the sanctioned acceptance journey (`admin.multi.facility_resumes`). Writes are limited to Clinic-scoped config keys + acting-admin user-meta; tenant rows and the completion marker are never touched; invalid input is rejected preserving previously stored non-empty configuration.
- **Tests:** `FacilityAdaptiveFlowEvolutionTest` expanded to 16 DB-backed methods, including real-HTML positive controls for cross-admin continuation, denied post-bootstrap writes for a membership-less administrator, zero-inference scope establishment, exact `missing_required_steps` after cross-flow evolution (`['سلامت سیستم']` → `[]` after the real acknowledgment), per-type adaptive inputs/validation (dental `min="2"`, rejected count writes nothing), explicit-optional users step, and non-destructive preservation snapshots. Unit contract extended (guidance key, users-optional for every flow).

### Product-review corrective batch 3 (2026-10-18, same Draft PR — verdict: PRODUCT REJECT addressed)

Receipt: 2026-10-18T19:24:29Z. Head before batch: `d1c04ec` (26/26 checks green). Coordination: PR #247 (Agent A) untouched — this batch changes only the wizard, the onboarding catalog, their tests and docs.

- **Hospital outpatient safety can never be bypassed (MAJOR 1):** `FacilityTypeCatalog::structure_is_compatible()` is the single compatibility policy — the `hospital_network` structure is reserved for the `hospital` type, and a `hospital` only accepts `hospital_network` or the type default (`''`); `single`/`multi_doctor`/`medical_building` are refused for it. Enforced three times over: `save_facility()` and the pre-bootstrap `save_facility_selection()` reject incompatible combinations with zero writes; the picker disables incompatible options server-rendered (plus a machine-readable `data-cpms-structure-policy` and inline JS; without JS the server stays authoritative); and `resolve_flow()` ignores an incompatible stored override as defense in depth, so even hand-crafted user-meta (`hospital`+`single`) still resolves `hospital_network` with its disclosure, conditional health step and acknowledgment. Medical-building multi-Clinic provisioning stays fail-closed; no inpatient/HIS support is invented; no tenant scope changes silently.
- **Fresh-acknowledgment semantics (MAJOR 2):** `save_health()` now requires the affirmative `outpatient_only_ack=1` on the **current** POST — a stored `true` is never a substitute. The stored flag is reset to `false` whenever a successful `save_facility()` changes the (type, structure) pair, and by the extracted public `CpmsSetupWizard::reset_progress()` used by the explicit restart (current_step → welcome, completion flag → false, ack → false). Rejected submissions never destroy stored values, and clinical/financial/tenant history is untouched throughout.
- **Truthful completion state (MAJOR 3):** the historical `setup.completed` flag is preserved in storage across every evolution, but the header banner, `step_is_complete('finish')` and the finish card now share one invariant — completion is displayed only when the **current** flow supports provisioning **and** every **current** required step is verifiably complete (`current_flow_is_complete()`); legacy installs without a facility selection keep the previous semantics. The finish card renders `data-cpms-finish-ready="1|0"` and, when a stored flag is not currently truthful, `data-cpms-stale-completion="1"` with an honest Persian explanation (missing steps listed; building flow: safely blocked). A completed doctor_practice evolving to general_clinic now honestly re-reports `['پزشکان']` (multi-provider minimum 2 vs one real clinician), to hospital `['سلامت سیستم']` after the stale-ack reset, and to medical_building never-complete — pinned by `testTruthfulCompletionStateAcrossFlowEvolutionIsDisplayedOnlyWhenCurrentlyTrue`.
- **Hospital clinician-count alignment:** the `hospital_network` flow is multi-provider — displayed input `min="2"`, `save_doctors()` rejects <2 with a hospital-specific Persian message, and `step_is_complete('doctors')` uses `max(stored intended, flow minimum)` when a facility selection exists (a stale intended=1 from a previous single-provider flow can no longer understate the current requirement). Legacy installs without a selection keep their exact previous semantics.
- **Defense-in-depth authorization:** the public `save_facility()`/`save_doctors()`/`save_health()` methods enforce the same Clinic-local CONFIG gate (`has_clinic_local_config`) as every other wizard write — checked **before** any flow inspection or payload processing, with no writes on denial. The pre-bootstrap entry point `save_facility_selection()` intentionally remains `manage_options` + nonce only (it runs before any Clinic scope exists); `save()` keeps its own double gate.
- **Tests:** `FacilityAdaptiveFlowEvolutionTest` expanded from 16 to 20 DB-backed methods (hospital override refusals + positive controls + disabled-option UI + pre-bootstrap refusal; fresh-ack unchecked/stale/reset/re-ack/restart with tenant snapshots; truthful completion across doctor_practice → general_clinic → hospital → medical_building; public-save auth denials before flow logic). Unit: `testStructureCompatibilityPolicyProtectsHospitalOutpatientScope`. Two pre-existing test setups that hand-crafted now-incompatible combinations were corrected (`FacilityOnboardingWizardTest` notice test: `hospital`+`medical_building` → `other`+`medical_building`; cross-admin structure resumability: `general_clinic`+`hospital_network` → `other`+`medical_building`) — disclosed, not hidden; the hospital evolution test now expects `['پزشکان','سلامت سیستم']` and re-satisfies doctors with a real second clinician. No test was skipped, weakened or removed.

## Corrective batch (2026-10-19) — facility-aware wizard chrome, Jalali date UX, and a reverted first-run redirect attempt

Receipt: 2026-10-19T00:44:22Z. Base: `e92f4056ebed37004e986353e367e38dea69e130` (live `main`; PR #246/#247/#249 already merged). PR #248 (Doctor Portal/bookings Location-scoping) inspected and left untouched — no overlapping files (`BookingService.php`, `VisitService.php`, `DoctorPortalController.php`, `QueueController.php`, `RestClinicContext.php`, `doctor-portal-shell.php` and their listed tests).

**Real-world finding (static code audit + reasoning about rendered output, NOT a live browser session — see Phase 1 limitation note in the PR):** the per-step card content (`renderClinic`, `renderDoctors`, review table, guidance) was already facility-aware via `FacilityTypeCatalog::type_profile()`, but the page `<h1>` heading, the step-nav pills, the "missing required steps" list and several prerequisite/validation messages still used the generic hardcoded `self::steps()` titles — reproducing exactly the reported defect ("2. اطلاعات کلینیک (الزامی)" for a selected hospital).

**Fix — single source of truth, no scattered string replacement:**
- `FacilityTypeCatalog::step_title(facility_id, step_id, fallback)` and `::wizard_heading(facility_id)` are the only two new functions; every nav/heading/message site now calls them (or the existing `type_profile()`), nothing is duplicated.
- An empty/invalid facility id always returns the previous generic string — legacy installations without a facility selection are byte-for-byte unaffected (pinned by `FacilityAwareStepTitlesAndHeadingTest::testUnselectedFacilityKeepsLegacyGenericWording` and `::testInvalidFacilityIdFallsBackSafely`).

### Terminology coverage matrix — all 12 approved facility types

Only the `clinic` (identity) and `doctors` (clinician roster) step titles/wording change by type; every other step id (`locations`, `users`, `schedules`, `booking`, `health`, `review`, `finish`) intentionally keeps identical generic wording for every type (confirmed in the catalog and pinned by test).

| # | Facility type (id) | Unit (`unit`) | Clinician / Clinicians | `clinic` step & heading | `doctors` step title | Hospital/inpatient note |
|---|---|---|---|---|---|---|
| 1 | `doctor_practice` | مطب | پزشک / پزشکان | اطلاعات مطب — راه‌اندازی مطب | پزشکان | — |
| 2 | `general_clinic` | درمانگاه | پزشک / پزشکان | اطلاعات درمانگاه — راه‌اندازی درمانگاه | پزشکان | — |
| 3 | `specialized_clinic` | درمانگاه | پزشک متخصص / پزشکان متخصص | اطلاعات درمانگاه — راه‌اندازی درمانگاه | پزشکان متخصص | — |
| 4 | `dental_clinic` | کلینیک دندانپزشکی | دندانپزشک / دندانپزشکان | اطلاعات کلینیک دندانپزشکی — راه‌اندازی کلینیک دندانپزشکی | دندانپزشکان | — |
| 5 | `diagnostic_center` | مرکز تشخیصی | پزشک / پزشکان | اطلاعات مرکز تشخیصی — راه‌اندازی مرکز تشخیصی | پزشکان | سرپایی فقط (guidance) |
| 6 | `rehabilitation_center` | مرکز توانبخشی | پزشک/درمانگر / پزشکان/درمانگران | اطلاعات مرکز توانبخشی — راه‌اندازی مرکز توانبخشی | پزشکان/درمانگران | — |
| 7 | `mental_health_center` | مرکز سلامت روان | پزشک/درمانگر / پزشکان/درمانگران | اطلاعات مرکز سلامت روان — راه‌اندازی مرکز سلامت روان | پزشکان/درمانگران | سرپایی فقط (guidance) |
| 8 | `specialized_outpatient_center` | مرکز درمان سرپایی | پزشک / پزشکان | اطلاعات مرکز درمان سرپایی — راه‌اندازی مرکز درمان سرپایی | پزشکان | سرپایی فقط (guidance) |
| 9 | `limited_surgery_center` | مرکز جراحی محدود | پزشک / پزشکان | اطلاعات مرکز جراحی محدود — راه‌اندازی مرکز جراحی محدود | پزشکان | بدون بستری شبانه/HIS (guidance) |
| 10 | `hospital` | مرکز درمانی *(picker label stays `بیمارستان`)* | پزشک / پزشکان | اطلاعات مرکز درمانی — راه‌اندازی مرکز درمانی | پزشکان | **فقط سرپایی**، گام سلامت + تأیید صریح الزامی (`health` اجباری)، بدون HIS/بستری |
| 11 | `comprehensive_health_center` | مرکز جامع سلامت | پزشک / پزشکان | اطلاعات مرکز جامع سلامت — راه‌اندازی مرکز جامع سلامت | پزشکان | — |
| 12 | `other` | مرکز | پزشک / پزشکان | اطلاعات مرکز — راه‌اندازی مرکز | پزشکان | — |

No row implies inpatient/HIS support anywhere (`FacilityTypeCatalog::flow_supports_inpatient()` is `false` for every flow, pinned by the existing `FacilityAdaptiveFlowEvolutionTest`); medical-building multi-Clinic provisioning stays fail-closed and routes to explicit Organization Manager provisioning regardless of type (unchanged by this batch).

### First-run experience: an activation redirect was attempted, then reverted — an equivalent, already-tested mechanism already existed

> **Status as of this paragraph only — SUPERSEDED later in this same document.** The redirect-removed state described immediately below was true for THIS batch only. The "Continuation batch (2026-10-19T04:33:29Z)" section further down **re-adds `CpmsActivationRedirect`** with a structural fix for the exact regression that caused this revert, and it has stayed re-added (and further hardened) in every batch since, including the current one. Do not cite this paragraph alone as evidence that CPMS has no activation redirect — read forward to the batch that re-adds it, and to the top of this file / `CHANGELOG.md` for the current state.

A first draft of this batch added a new `CpmsActivationRedirect` class: a one-shot, Administrator-only, state-aware `admin_init` redirect to the wizard after a genuine single-site activation, reusing `CpmsSetupWizard::onboarding_panel_applies()`.

**This was reverted before merge, based on real evidence, not a hunch.** Pushing it to this PR's branch triggered the project's real-browser CI (`First-practice browser (mariadb)`/`(mysql)`), and that run failed two assertions: `admin.onboarding_notice.visible` and `admin.onboarding_notice.points_to_unified_wizard`. Investigating showed `CpmsAdminMenu::onboardingNotice()` (registered on `admin_notices`, already shipped and already covered by this exact browser test from prior merged work) **already fully implements** the Phase 3 requirement: it is capability-gated (`current_user_can(RolesAndCapabilities::CONFIG)`), one-time (`cpms_onboarding_seen` transient, `DAY_IN_SECONDS`), state-aware (`setup_completed_flag()`), skips the plugins-list screen, and renders a dismissible notice with a direct "شروع راه‌اندازی" (start setup) button to the wizard — without ever forcibly navigating the admin away from the page they landed on.

The new hard `wp_safe_redirect()` silently won the race against that notice (it fires on `admin_init`, before `admin_notices`), so the admin was redirected straight to the wizard before the existing notice could ever render — which is exactly what the failing assertions caught. Since the underlying requirement ("professional, admin-only, one-time, state-aware route back to the wizard, no interference with AJAX/cron/REST/CLI/activation plumbing") was already satisfied by tested, shipped code, adding a second, conflicting implementation would have been pure scope duplication with a real regression attached. `CpmsActivationRedirect.php` and its test were removed; `CpmsSetupWizard::onboarding_panel_applies()` was reverted back to `private` (its only caller is the wizard's own embedded panel).

**Takeaway recorded for future agents:** before adding first-run/activation UX to this plugin, check `CpmsAdminMenu::onboardingNotice()` and the `First-practice browser` CI job first — the notice-based design is the project's chosen, already-verified pattern; a redirect-based alternative is a regression, not an enhancement, even though each one reads as "safe" in isolation.

### Jalali (Shamsi) date UX — delivered vs. deferred

> **Status as of this paragraph only — SUPERSEDED later in this same document.** "Display only, typing deferred" was true for THIS batch only. The "Continuation batch (2026-10-19T04:33:29Z)" section further down ("3) Real Jalali date entry") **adds the interactive typed-entry widget and the accessible popup picker**, and the current cycle (see `CHANGELOG.md` and this file's top-most entries) further hardens that entry widget's validation (supported year window, non-leap/leap-Esfand correctness, `setCustomValidity()`-based submission blocking). Do not cite this paragraph alone as evidence that Jalali support in CPMS is read-only — read forward.

**Delivered:** a local, network-free companion display (`assets/js/cpms-jalali-date.js` + `.css`, enqueued by the existing `CpmsAssets` allowlist) that shows the live Jalali equivalent next to every real `input[type="date"]` on a CPMS admin screen (e.g. the clinician schedule-exception date, finance report date filters, patient birth date) — using the exact same Nowruz table/algorithm as the canonical PHP `ClinicCore\Domain\Time\Jalali`, so client display and server `_jalali` fields never disagree. The native input's value, validation, `required`/`min`/`max` and keyboard behaviour are completely untouched — this is strictly additive (display only), which is also why it required **zero changes** to any scheduling PHP file (`ClinicianAdminPage.php` and friends were not touched at all — avoiding any overlap with PR #248's booking/schedule work).

**Explicitly NOT converted (by design):** weekday selectors and `input[type="time"]` clock ranges used by weekly recurring schedules — the script's selector is scoped to `input[type="date"]` only and has no code path that reads weekday/time values, so it cannot misinterpret them as calendar dates.

**Deferred to a follow-up slice:** bidirectional Jalali *typing* (entering a date in Shamsi and having it set the native Gregorian input) — the conversion primitive (`window.CpmsJalali.jalaliTextToIso`) is already implemented and unit-cross-checked against the PHP table (Node-side manual verification only; no JS test runner exists in this repo's CI), but the interactive widget itself was deferred to keep this slice's surface area minimal and risk-free within the review window.

### Tests added

`FacilityAwareStepTitlesAndHeadingTest`, `CpmsJalaliDateAssetsTest` (both Integration, real WordPress Test Suite/MySQL — no code-path was skipped or weakened; both are part of the `Full Integration suite` run that passed with 0 failures/errors on this PR's final head). As with every prior slice in this document: **no PHP/MySQL runtime exists in the agent sandbox**; PHP syntax of every touched file was verified locally with `php-parser` (Node, no PHP interpreter available), and the Jalali JS↔PHP conversion was cross-checked locally with Node for multiple anchor dates (round-trip + Nowruz boundary) — but the authoritative PHPUnit/WPCS/PHPStan/real-browser results (including the one that caught and led to reverting the activation-redirect attempt above) come only from this repository's own CI on the pushed head.

## Continuation batch (2026-10-19T04:33:29Z) — exact hospital wording, re-scoped automatic first-run redirect, real Jalali date entry

Receipt: 2026-10-19T04:33:29Z. Base for this batch: the previous corrective batch's final head (`05b588a069900339ed5fb7869b5a7dab11e2e4a6`), itself based on `main` `e92f4056ebed37004e986353e367e38dea69e130`. `origin/main` unchanged; PR #248 file list re-checked and still has zero overlap with everything touched below.

This batch responds to an explicit owner finding that "green CI does not mean the product mission is complete" and asks for three concrete, previously-incomplete items.

### 1) Hospital wording: "اطلاعات مرکز درمانی", not "اطلاعات کلینیک" and not the bare "بیمارستان"

`FacilityTypeCatalog::type_profile('hospital')['unit']` changed from `'بیمارستان'` to `'مرکز درمانی'`. This is the single source of truth for the `clinic` step's nav label/page content and the wizard's `<h1>` heading, so the hospital flow now shows exactly **"اطلاعات مرکز درمانی"** (step heading) and **"راه‌اندازی مرکز درمانی — قدم N از M"** (page heading) — matching the owner's required string verbatim. The 12-type *picker* label (`FacilityTypeCatalog::all()`, what the administrator selects from) deliberately still reads `"بیمارستان"` so the real-world type stays recognizable; only the *derived* onboarding strings (heading, nav, the `نام ... الزامی است.` validation message) use the neutral outpatient-facility wording, consistent with this product never operating an inpatient/HIS hospital. `name_label` was updated in lockstep to `'نام مرکز درمانی (فقط محدودهٔ سرپایی)'`. The terminology coverage matrix table (row 10) and three existing assertions (`FacilityTypeCatalogTest`, `FacilityAdaptiveFlowEvolutionTest`, `FacilityAwareStepTitlesAndHeadingTest`) were updated to the new exact strings — no assertion was weakened, only its expected value corrected to the new intended wording.

### 2) Automatic first-run wizard open — re-implemented with the exact fix for the prior regression

The prior batch's `CpmsActivationRedirect` was reverted because it ran on `admin_init` for **any** wp-admin page, which fires before `admin_notices` and silently prevented `CpmsAdminMenu::onboardingNotice()` from ever rendering on the dashboard landing the browser test checks.

This batch re-adds `CpmsActivationRedirect` with one structural change that removes the conflict entirely rather than attempting to race or coordinate with the notice: `maybe_redirect()` now **only acts when `$pagenow === 'plugins.php'`** — the literal page WordPress itself returns the admin to immediately after clicking "Activate". This is safe for two independent reasons, both verified:
- `CpmsAdminMenu::onboardingNotice()` already has its own `if ($screen->id === 'plugins') { return; }` guard, i.e. it **never** renders on `plugins.php` regardless of this redirect. The two features literally cannot compete for the same page.
- Every other admin page load (dashboard, wizard, any CPMS screen) is now completely untouched by `CpmsActivationRedirect` — confirmed by a new dedicated regression test (`testNonPluginsPageLoadNeverRedirectsAndLeavesTheFlagArmedForTheRealLanding`) that simulates a non-`plugins.php` admin_init first (asserts no redirect, flag still armed) and then the real `plugins.php` landing (asserts the redirect still fires there).

All other original safety properties are unchanged: one-shot (transient consumed on first observation, win or lose — no loop possible), Administrator-capability-gated at redirect time, same-admin-only, state-aware via the shared `CpmsSetupWizard::onboarding_panel_applies()` predicate (now `public` again — its only other caller, the wizard's embedded panel, is unaffected), never armed for network-wide/bulk activation, and defensive bail-outs for AJAX/REST/cron/CLI/autosave/iframe contexts.

**Real-browser evidence (Chromium/Playwright, not code inspection):** `bin/rwp-install-zip.py` — the script that drives the actual "upload ZIP → click Activate" flow in CI — now asserts `install.auto_redirect_to_wizard`: immediately after `activate.click()`'s navigation completes, `page.url` must already contain `page=cpms-wizard`. This is real evidence that the wizard *opens automatically*, not merely that a notice with a link to it exists. The script still performs its own separate, fresh navigation back to `plugins.php` afterward and still asserts the plugin shows as active there — unaffected, because the one-shot transient is consumed by the first (redirected) landing.

`tests/Integration/CpmsActivationRedirectTest.php` was restored with the original 9 methods (fixtures/assertions updated to simulate `$pagenow` explicitly, matching the existing pattern already used by `WooCommerceAdminAccessCoexistenceTest`) plus the new regression test above — 11 methods total.

### 3) Real Jalali date entry (not a read-only companion label)

The previous batch's `assets/js/cpms-jalali-date.js` only added a **read-only** live Jalali-equivalent display next to `input[type="date"]`; it explicitly deferred the interactive typing widget. That is no longer accurate to this product's requirement ("a dismissible notice/label alone is not the same as actually entering a date").

`cpms-jalali-date.js` now adds, next to every real calendar-date `input[type="date"]` on a CPMS admin screen (unchanged scope — weekday/time-range weekly schedule fields are never targeted):
- A Jalali text-entry field (`type="text"`, RTL, Persian-digit placeholder) the administrator can type or paste a Shamsi date into (`۱۴۰۴/۰۷/۲۷` or ASCII `1404-07-27`), live-validated on every keystroke and on blur; a successfully parsed date writes the converted Gregorian ISO value into the native input and dispatches real `input`/`change` events, so every existing listener/validator on that field keeps working unmodified. An unparseable value is flagged (`aria-invalid="true"` + a visible Persian error message) **without** touching the native input's existing value.
- An accessible popup calendar grid (`role="dialog"` + `role="grid"`, a labelled trigger button, arrow-key day navigation that crosses month boundaries, Enter/Space to pick, Escape to close and return focus to the trigger) that picks a day and performs the same native-input write-back.
- The native `input[type="date"]` is never hidden, disabled, or intercepted — editing it directly still works exactly as before and now also re-syncs the Jalali entry field's text (bidirectional).

Canonical storage stays exactly as before: the server only ever sees the native input's Gregorian ISO value; no PHP validation/storage/timezone code changed, and zero scheduling/booking files were touched (no overlap with PR #248). The first concrete real-calendar-date target for this widget is the clinician schedule-**exception** date field in `ClinicianAdminPage.php` (`cpms-clinicians`, an already-allowlisted asset page) — the weekly weekday/time-range schedule UI in the same file was not touched at all.

**Evidence of correctness:** `node --check` (syntax) plus a real, executed functional test of the shipped script against a genuine DOM (Node + `jsdom`, run in this sandbox, not part of CI) exercising: typing a valid Jalali date → correct native ISO value; typing an invalid value → `aria-invalid` set, native value left untouched; editing the native input directly → Jalali entry text re-syncs; opening the popup, rendering ≥28 day cells, clicking a day → correct ISO + popup closes; `Escape` closes the popup. **This is real JS execution against a DOM, not a Chromium/Playwright browser session** — it is disclosed as such and must not be read as CI browser acceptance. `CpmsJalaliDateAssetsTest` (unchanged scope: local-file/no-network-marker + allowlisted-page enqueue) still passes against the updated file.

### Honest status of "real Administrator acceptance, 12 facility types"

This continuation batch did not add a new exhaustive Playwright walkthrough of all 12 facility types end-to-end (time-boxed). What is real, executed-in-CI Chromium evidence after this batch:
- The existing `First-practice browser` job's personal-practice (`doctor_practice`/`simple_practice`) walkthrough, onboarding-notice checks, and the new `install.auto_redirect_to_wizard` assertion above.
- The existing `FacilityAdaptiveFlowEvolutionTest`/`FacilityAwareStepTitlesAndHeadingTest`/`FacilityTypeCatalogTest` Integration coverage (real WordPress Test Suite/MySQL in CI, not a browser) exercises all 12 types' terminology, structure compatibility and flow resolution.
- Hospital outpatient-only wording and medical-building explicit-provisioning-only behavior are covered at the Integration level (DB-backed, real WordPress bootstrap) but **not** independently re-verified via a dedicated new Chromium session in this batch.

Anything beyond the above (e.g. an administrator clicking through all 12 types end-to-end in a live Chromium tab across mobile/tablet/desktop viewports) remains a gap for a future slice and must not be reported as executed browser evidence.

### Follow-up fix within this same batch: a second real regression caught by CI, root-caused and fixed

Pushing the batch above to this PR's branch re-triggered real CI, which caught **the same two assertions failing again**: `admin.onboarding_notice.visible` / `admin.onboarding_notice.points_to_unified_wizard` on the `First-practice browser` job — even though `CpmsActivationRedirect` was now scoped to `plugins.php` only and `install.auto_redirect_to_wizard` itself **passed** (real evidence the automatic open works).

**Root cause (confirmed, not guessed):** scoping the redirect to `plugins.php` stopped it from racing `admin_notices` on `plugins.php`, but the redirect's *destination* — `admin.php?page=cpms-wizard` — is itself a normal wp-admin page render, so `admin_notices` (and therefore `CpmsAdminMenu::onboardingNotice()`) still fires there. `onboardingNotice()`'s one-shot `cpms_onboarding_seen` transient was being silently consumed by this very first landing on the wizard (even though its `$screen->id === 'plugins'` guard correctly kept it from rendering on `plugins.php` itself) — so by the time the browser test logs in fresh afterward and lands on the dashboard, the flag was already spent and the notice never showed.

**Fix:** `CpmsAdminMenu::onboardingNotice()` gained one more early-return guard, parallel to its existing `plugins` screen check: it also returns (without rendering **or** consuming the transient) when `$_GET['page']` is the wizard's own slug (`CpmsSetupWizard::PAGE_SLUG`). This is a reasonable UX rule on its own merits (a "click here to start setup" banner is redundant while already standing on the setup wizard) and it is what makes the automatic-redirect and the existing notice fully compatible: the one-shot flag is now only ever consumed by a *real* subsequent landing the admin actually looks at (the dashboard, or any other CPMS/non-CPMS admin page), never by the redirect's own landing.

Added `CpmsActivationRedirectTest::testLandingOnTheWizardPageNeverConsumesTheSeparateOnboardingNoticeFlag` — calls `onboardingNotice()` with `$_GET['page']` set to the wizard slug first (asserts empty output + flag still unset), then again without it (asserts the notice renders and the flag is now consumed) — a direct, minimal regression proof of this exact defect and fix, independent of the full browser suite.

## Remediation batch (2026-10-19T06:36:47Z) — independent-review defect fixes (P1–P4)

Receipt: 2026-10-19T06:36:47Z. Base for this batch: the prior batch's final head (`050ecfb046686439359a58d6ab3f4a1a2e58f21e`), based on `main` `e92f4056ebed37004e986353e367e38dea69e130`. This batch responds to an independent REJECT verdict issued despite a prior exact-head 26/26 CI SUCCESS, with four concrete, prioritized defects.

### P1 — Jalali data-integrity regression: typing an invalid date could submit a stale valid Gregorian value

**Confirmed root cause:** `cpms-jalali-date.js`'s entry field wrote to the native `input[type="date"]` (and dispatched real `input`/`change` events) on **every keystroke**, via `applyEntryToNative()`. While the admin was still typing a date such as `1405/12/30` (Esfand 1405 has only 29 days — 1405 is not a leap Jalali year in this repository's own Nowruz table — so `30` is invalid), an earlier, syntactically-complete-but-different prefix typed along the way (e.g. `1405/12/3`) had already been committed to the native input as its own valid date. The widget never called `setCustomValidity()`, so nothing blocked a form submission that happened to catch the field in that intermediate committed state.

**Fix (`assets/js/cpms-jalali-date.js`):**
- The native input is now written to **only** on an explicit commit: `blur`, `Enter` (with `preventDefault()`so Enter never falls through to an implicit submit first), or a popup day-pick. Every keystroke in between runs a **live, read-only** classifier (`liveValidateEntry`) that updates the accessible status text and `input.setCustomValidity(...)` but never touches `input.value` and never dispatches `input`/`change`.
- `setCustomValidity()` is kept **non-empty** for every state except exactly-synced-with-the-committed-value — including a syntactically complete *and numerically valid* typed date that has not yet been confirmed by blur/Enter/popup. This means the browser's native HTML5 constraint validation blocks submission for the entire window between "user finished typing" and "user confirmed", not just for outright-invalid text.
- `jalaliTextToIso()` (the typed-entry parser only — not `isoToJalaliText`/display, not popup navigation, not the PHP `Jalali.php` canonical converter) now rejects years outside a new `SUPPORTED_MIN_YEAR = 1300` / `SUPPORTED_MAX_YEAR = 1499` window, per the brief.
- A new `classifyCompleteJalaliEntry()` helper returns a precise reason (`malformed`/`year`/`month`/`day`) for a complete-shaped entry, driving specific Persian messages instead of one generic string.
- Committing (`commitEntry()`) an incomplete or invalid entry leaves the native value **untouched** (never overwrites a prior valid committed date with a guess) and keeps the field `aria-invalid="true"` + `setCustomValidity(...)` non-empty.

**Tests added:** `clinic-practice-management/tests/js/cpms-jalali-date.spec.js` — a plain-Node harness that executes the **real, shipped** `cpms-jalali-date.js` (not a reimplementation) against a minimal DOM stub and asserts the exact regression (`1405/12/30` rejected, reason `day`), the positive counterpart (`1401/12/30` — a real leap Jalali year in this table — accepted), the supported year window (1300/1499 inclusive boundaries, 1299/1500 rejected), malformed/partial shapes, and short-month day-31 rejection. Wired into PHPUnit via `tests/Integration/CpmsJalaliDateLogicTest.php`, which shells out to `node` (available by default on the `ubuntu-latest` CI runners this suite already runs on; skips gracefully if `node` is absent rather than failing the whole suite).

### P2 — Invalid nested `<label>` HTML + a dynamically-injected date field left un-enhanced

**Confirmed defects:** `ClinicianAdminPage.php`'s schedule-exception date field and `SecretaryFinancePage.php`'s finance from/to date fields each wrapped their native `<input type="date">` **inside** a `<label>…</label>` text wrapper. `cpms-jalali-date.js` moves the native input (plus its own `شمسی:` companion `<label>`) into a new wrapper placed at the input's original position — which, when that position was inside another `<label>`, produced an invalid `<label>` nested inside a `<label>`. Separately, `DoctorDashboardPage.php` builds its "pigiri" follow-up form (`#cpms-doc-fu-date`) entirely client-side after the page's initial `DOMContentLoaded` Jalali-enhancement pass already ran, so that field was never enhanced at all.

**Fix:** both admin pages now use a sibling `<label for="id">text</label> <input id="id" ...>` pattern instead of wrapping. `cpms-jalali-date.js` now exports `window.CpmsJalali.enhanceAll(root)` / `enhanceField(input)` (both idempotent, safe to call repeatedly) specifically so other admin screens can re-scan after injecting markup client-side; `DoctorDashboardPage.php`'s `renderVisit()` now calls `window.CpmsJalali.enhanceAll(view)` immediately after setting `view.innerHTML`.

**Tests added:** a new assertion block in the existing `Phase6ScheduleExceptionLocationTest::testWpAdminFormOffersClinicLocationsAndListingShowsThem` (already renders `ClinicianAdminPage::render()` under a real trusted-scope fixture) parses the actual rendered HTML and asserts the date `<label>` closes **before** the `<input>` appears (sibling, not parent). A new `tests/Integration/JalaliDateInputMarkupTest.php` renders `SecretaryFinancePage::render()` under a real Secretary-role fixture and asserts the same structural invariant for both `#cpms-fin-from` and `#cpms-fin-to`.

### P3 — Real Administrator browser coverage for all 12 facility types + the Jalali widget

`bin/rwp-first-practice.py` previously only drove two of the twelve facility types (`doctor_practice`, `general_clinic`) through a real Chromium session; the rest were integration-tested (real WordPress/MySQL, not a browser) but not independently exercised with Playwright. This batch adds, in the same authenticated Administrator session already used by Journeys A/B:
- A loop over **all twelve** facility types that selects each one, submits, and asserts the exact facility-aware `مشخصات <واحد>` step-2 heading (hospital's is asserted to literally read `مشخصات مرکز درمانی`), then revisits the facility step and asserts the **same** type resumes (no silent fallback to a different type), plus a Clinic-count invariant across the whole loop (no incorrect automatic Clinic creation/selection).
- A hospital-specific block that submits the health step **without** checking the outpatient-only acknowledgment (asserts the exact Persian rejection message and that the step does not advance), then checks it and resubmits (asserts it is accepted).
- A medical-building block that selects the `medical_building` structure, reaches the finish step, asserts `data-cpms-finish-ready="0"` and the fail-closed denial message, then performs a **real submit** of the finish form and asserts `setup.completed` is still not true afterward (server-side fail-closed, not just a UI label).
- A Jalali-widget block on the real clinician schedule-exception field created in Journey B: types the exact regression date `1405/12/30` and asserts the native input stays empty and `checkValidity()` is false; types the positive leap-Esfand-30 date `1401/12/30` and asserts the native input commits exactly `2023-03-20` and `checkValidity()` is true; opens the accessible popup and asserts picking a day commits a valid ISO date independently.
- A representative responsive/RTL check: asserts the admin `.wrap` declares `dir="rtl"` and that a 390px mobile viewport reload produces no material horizontal overflow.

**Honest scope:** this is still a *representative-depth* pass (selection + heading + resume + one deep hospital/medical-building/Jalali journey each), not an exhaustive full-journey walkthrough of all twelve types through every step — the two full multi-step journeys (Journeys A/B, `doctor_practice`/`general_clinic`) remain the only types driven end-to-end through doctor registration and completion. This script runs only in CI (`first-practice-acceptance.yml`, Playwright/Chromium is not available in the authoring sandbox); its pass/fail status on the pushed head is the authoritative evidence, not this description.

### P4 — Facility-aware secondary copy + documentation corrections

`CpmsSetupWizard.php`'s shared page shell and several step bodies hardcoded generic words (`مطب`, `کلینیک`, `پزشک`) regardless of the selected facility type, even though the step-2 `<h2>` right next to them already used `FacilityTypeCatalog`'s facility-aware `unit`/`clinician`/`clinicians` terminology. The welcome paragraph, the completion banner, the clinic-info description, the weekly-schedule description, the finish-step heading, and the finish-step unmet-prerequisites warning now all interpolate `current_facility_profile()`/`$facility_profile` the same way the rest of the file already does; for an unselected facility (or a pre-existing install with no facility type), the defaults (`کلینیک`/`پزشک`) are unchanged byte-for-byte from before this batch, so no existing test asserting the generic wording was weakened — only the facility-aware case (previously silently wrong) was fixed.

The two paragraphs above in this same document (the ones headed "an activation redirect was attempted, then reverted" and "Jalali (Shamsi) date UX — delivered vs. deferred") were flagged as contradictory when read in isolation, since both were later superseded by subsequent batches further down this same file. Rather than rewriting history, each now carries an explicit **"SUPERSEDED later in this same document"** callout at its top, pointing forward to the batch that re-added the redirect and the batch that added real Jalali entry, so a reader stopping at either paragraph cannot mistake a since-reversed interim state for the current one.

### Multi-Clinic installs — explicit per-Clinic configuration (PR #253)

**Before:** once an installation had more than one Clinic, the wizard stopped at the "ambiguous Clinic" notice
(`data-cpms-scope-required="1"`), and `save()` fataled with `CLINIC_SCOPE_REQUIRED`.

**Now:**

- The same notice also offers an explicit Clinic choice (`#cpms_wizard_clinic`). It lists only Clinics with an
  ACTIVE membership and scoped `cpms_config`, and nothing is pre-selected.
- After a verified choice, the wizard runs its existing facility-adaptive steps for that Clinic only.
  `data-cpms-wizard-clinic` names that Clinic, and every step link, form, redirect and per-Clinic deep link
  (clinicians, Locations, staff) carries the selected `clinic_id`.
- `save()` and `restart()` re-verify the selector and refuse a foreign one with 403. Without a selector they
  redirect with guidance and never infer a Clinic.

**Single-Clinic installs** behave exactly as before.

**Organization-level journey:** see the next section. The per-Clinic wizard links hospital and medical-building flows
to it (`data-cpms-org-facility-journey="1"`). Existing markers and the «مدیریت سازمان و درمانگاه‌ها» entry point stay.

### Top-down Organization facility setup — hospital outpatient network and medical building (PR #253)

Page: «سازمان‌ها ← راه‌اندازی مرکز چنددرمانگاهی» (`admin.php?page=cpms-facility-setup`), service:
`FacilitySetupService`. The facility type is chosen first and adapts the remaining steps:

| Step | Hospital (outpatient only) | Medical building | Authority |
|---|---|---|---|
| 1 Facility type + identity | `hospital` → `hospital_network`; explicit outpatient-only acknowledgment | type + structure `medical_building` | Organization (`cpms_org_clinic_create` + `cpms_org_clinic_lifecycle`) |
| 2 Physical sites | 1..20 sites | same | Organization |
| 3 Clinics (repeatable) | outpatient Clinics | independent Clinics | Organization (create + lifecycle) |
| 4 Clinic manager | explicit user (may be self) | a distinct explicit manager per Clinic | Organization (`cpms_org_clinic_manager_assign`) |
| 5 Rooms | Locations of that Clinic | same | the actor's own Clinic membership + `cpms_config` |
| 6 Clinicians | one profile per user; other Clinics get an explicit `cpms_doctor` membership; additive Location assignment | — (each Clinic's own manager) | Clinic |
| 7 Staff roles | reception (`cpms_secretary`) or finance (`cpms_accountant`) membership; explicit access scope: the whole Clinic, or an explicit subset of its active Locations (location-scoped membership); optional explicit DENY rows from the role preset only; the per-Clinic «کاربران و دسترسی‌ها» link stays for later changes | — | Clinic |
| 8 Weekly schedules | per clinician × Clinic × Location via `ScheduleService` | — | Clinic |
| 9 Booking policy | per Clinic, same keys/bounds as the wizard booking step | — | Clinic |
| 10 Review + activation | live-row review; explicit confirmation | same | Organization (lifecycle) |

**Entities and boundaries**

- Clinics are created by `OrganizationClinicProvisioningService::provision` and always belong to the chosen
  Organization; each has its primary Location at the chosen site.
- Rooms are created by `LocationService::createLocation` inside their Clinic.
- No new tenant layer is added: Organization → Clinic → Location is unchanged.

**Physical sites (documented limitation)**

- A site is descriptive metadata in the setup plan (label + address).
- Its address is copied onto the Locations created at that site.
- It is not a Clinic, not a Location and not a tenant boundary.
- There is no site entity outside the plan.

**Holding and activation**

- New Clinics are held suspended (the existing lifecycle control: no new demand) until the consolidated review is
  ready and activation is explicitly confirmed.
- Setup and Location management continue while a Clinic is held, as documented in
  `post-onboarding-clinic-location-management.md`.

**Retry, resume and authority**

- *Idempotency and resume:*
  - every create form carries a server-issued `request_key`;
  - Clinic and room slugs derive from it deterministically;
  - a retry, including after a lost plan write, adopts the existing row (only inside the same Organization or Clinic)
    instead of duplicating it;
  - duplicate names are refused;
  - the plan (`cpms_facility_setup_plan_{organization}`, non-autoload) is written under the Organization row lock;
  - Clinic and room creation is one transaction under that lock: the request-key, duplicate-name and limit checks
    run against the locked plan, and the Clinic (with its primary Location and hold) or room Location is written
    together with its plan entry. Concurrent submissions for one Organization therefore serialize, a duplicate name
    cannot slip past a stale check, and a failure at any point leaves no Clinic or Location outside the plan;
  - an unexpected internal failure is shown as a Persian error notice, never as a fatal error page; the operational
    log keeps only ids, the step and the exception class;
  - every real plan change (identity, outpatient acknowledgment, sites, Clinic/room records, activation) is audited
    as `FACILITY_SETUP_PLAN_CHANGED` in the same transaction, listing only the changed plan sections; an identical
    retry is not re-audited.
- *Organization vs. Clinic authority:*
  - Organization authority never configures a Clinic;
  - steps 5–9 need the actor's own active membership and `cpms_config` in that Clinic;
  - WordPress Administrator status grants nothing;
  - DENY wins for every Organization capability;
  - existing memberships are never overwritten or reactivated;
  - inactive Locations and suspended memberships fail closed;
  - a suspended Organization refuses every step (reads included) and changes nothing; setup resumes after
    explicit reactivation.
- *Staff roles (step 7):*
  - only the built-in staff roles are offered (manager and clinician have their own steps; custom roles stay
    on the existing per-Clinic page);
  - a WordPress Administrator account is not a staff account and is refused;
  - Location-scoped access uses the existing membership model (`scope_mode = location` +
    `cpms_membership_locations`), enforced by `TrustedClinicEstablisher` at every staff entry point;
  - DENY rows can only remove capabilities of the chosen role preset (DENY > GRANT > preset); the step never
    writes a GRANT, so it cannot widen access;
  - membership, Location scope and DENY rows are written in one transaction (no Clinic-wide window after a
    partial failure) and audited as `FACILITY_STAFF_ROLE_ASSIGNED`;
  - an identical retry is a no-op; any other existing membership (role, status, scope or overrides differ) is
    refused, never overwritten or reactivated.
- *Admin entry vs. authority (WordPress roles):*
  - Clinic memberships created here (manager, clinician, staff) are the only source of Clinic authority; no facility
    step adds, removes or replaces a WordPress role — the same rule as attaching an existing account in
    «کاربران و دسترسی‌ها»;
  - the CPMS screens themselves still need the matching WordPress role (entry capability: `cpms_config` for
    managers, `cpms_queue_read` for clinicians and reception, `cpms_finance_read` for finance), so an existing
    subscriber who received a membership cannot open them yet;
  - the review therefore reports, per Clinic and as counts only, how many active members (and managers) still lack
    that WordPress role (`data-cpms-facility-entry-missing`), and the staff roster flags such accounts; this is
    informational and never blocks activation;
  - the Clinic's manager assigns the role from «کاربران و دسترسی‌ها» (edit user); for the delegated Clinic manager
    themself it is a technical WordPress step (Users screen) — a WordPress role still grants no Clinic authority.
- *Out of scope:* inpatient wards, beds, inpatient OR and HIS are excluded.

**Dates.** The journey has no calendar-date fields: weekly schedules use weekdays and times, and the booking policy
uses durations. Schedule exceptions with Jalali dates remain on each Clinic's «پزشکان» page.

**Tests and browser evidence**

- `tests/Integration/FacilitySetupJourneyTest.php` (integration).
- `bin/rwp-first-practice.py`, the "facility" journeys: hospital and medical-building setup (including a
  location-scoped reception role with an explicit DENY, a refused room of the other Clinic and a no-op retry),
  explicit Clinic → Location switching for the shared clinician on the «پزشکان» page, forged Organization,
  WordPress Administrator and subscriber refusal, and 390px RTL.
- Real concurrency (a third Organization): two tabs of the same session submit, at the same time, two separately
  rendered Clinic forms with the same name, one Clinic form twice (same request key), and two room forms with the
  same name (real browser form POSTs against MySQL/MariaDB); each case yields exactly one Clinic or room Location
  and one plan entry.
- Delegated manager hand-off (medical building): the review reports both subscriber managers without admin entry;
  after the technical WordPress role step (WP-CLI, outside CPMS) only that Clinic turns ready; the delegated manager
  then logs in, manages their own Clinic on «پزشکان» with an explicit `clinic_id`, is refused for the other
  independent Clinic, and gets no Organization setup.
- **Known limitation:** the hospital journey designates the Organization Manager as manager of each outpatient Clinic.
  Delegated per-Clinic managers are exercised in the browser only in the medical-building hand-off above.
