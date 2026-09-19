# DRIFT REGISTER — تعارض اسناد با معماری هدف

| | |
|---|---|
| **ایجاد** | 2026-09-08 (پایان Phase 0.5) |
| **مرجع معماری** | [`ADR-0031`](adr/ADR-0031-organization-clinic-location-scoped-authorization.md) — `Organization → Clinic → Location → Doctor → User/Staff` |
| **مرجع فازبندی** | [`roadmap.md`](roadmap/roadmap.md) §۰ — Owner-approved Phase 0..20 |
| **شواهد** | [`report-phase-0-reverification.md`](phase-reports/report-phase-0-reverification.md) — قیدهای C-1..C-9 |

---

## اصل حاکم بر این سند

> **Documentation cleanup نباید خودش پروژه را هفته‌ها متوقف کند.**
>
> هر سند در **همان فازی که کد مرتبطش اصلاح می‌شود** نهایی و **هم‌زمان با کد** به‌روز می‌گردد. این Register فهرست بدهی مستندات است، نه فهرست کار فوری.
>
> **wholesale rewrite ممنوع** — تا رسیدن فاز مالک، متن اسناد دست‌نخورده می‌ماند.

## قاعدهٔ نگاشت فاز مالک

| نوع Drift | فاز مالک |
|---|---|
| Domain / Schema / ERD / Migration | **Phase 2** — Multi-Clinic Core |
| Authorization / Roles / Capability / Scope | **Phase 3** — Role & Access Control |
| Specialty / Department / Room / داده‌های پایه | **Phase 4** — Master Data |
| Pricing / تعرفه | **Phase 5** — ✅ **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** (PR #67)؛ قلمِ «تعرفهٔ متفاوت per-Location برای همان Service» **الزامِ اثبات‌شدهٔ V1 نیست** و بدون فاز/migration باز می‌ماند (رجوع به O-06) |
| Schedule / Availability / Timezone | **Phase 6** — ✅ **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** (PRs #69–#71 + #73–#76؛ merge `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b`)؛ ردیف‌های `O-03`/`O-04` با این بستن **بسته نمی‌شوند** و باز می‌مانند؛ دو قلم hardening (ج/د) با **اسلایس hardeningِ C7 = CLOSED** (PR #78، merge `4b322d9dc8839e1efd66d712aedc06d9f22cd175`) پیاده‌سازی شدند (READ بدون Scope fail-closed + سقف `1..365` برای payload)؛ شاخه‌های سازگاریِ null-scopeٔ باقی‌ماندهٔ نوشتن‌های موجودیت طبقه‌بندی **NO CHANGE JUSTIFIED** شدند (نه patchِ C7ِ دیگری الزامی) |
| Appointment / Booking | **Phase 7** |
| Security / Authorization دیرهنگام / Rate-limit | **Phase 1** |

## وضعیت‌ها

| نماد | معنی |
|---|---|
| ✅ RESOLVED | در Phase 0.5 اصلاح شد |
| ✅ **CLOSED (PHASE)** | فاز مالک بسته/تکمیل شده است (ردیف‌های `O-*` — نمونه: `O-06` = Phase 5 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)) — متن فاز بازنویسی نمی‌شود؛ فقط وضعیت ثبت می‌گردد |
| 🕒 DEFERRED | تا فاز مالک دست‌نخورده می‌ماند |
| ⚠️ OPEN DECISION | نیازمند تصمیم Product Owner |


> **Current Phase 4 closure note (re-verified 2026-09-17):** Owner Roadmap Phase 4 is
> **CLOSED / TECHNICALLY COMPLETE in a bounded scope** on live main
> `70ace204d1524a8f5e83d33c67c1a09b7543e7a3`, through merged PRs **#59–#65**.
> The closure does not promote the remaining M-01..M-05 drift, Q11, ServiceOffering,
> Specialty/Department/Room, Iran geography master data, or any other deferred/open
> item. The documented Location-timezone source-of-truth decision and membership-based
> cross-Clinic professional model remain unchanged; the stale `clinic.phone` Patient
> Portal consumer is not claimed fixed.
>
> **Current Phase 5 closure note (re-verified 2026-09-17):** Owner Roadmap Phase 5 — Pricing Engine is
> **CLOSED / TECHNICALLY COMPLETE in a bounded scope** on live main
> `e063b42260cb5ab740acf48dcf73c6c67b11fef6`, by the merged **Phase 5 Slice 1 — PR #67**
> (approved head `fa86e41e415d1b7fcca3dec4c88fadb25da71d1a`; exact-head checks 19/19 success;
> post-merge gates on the merge SHA all success). Bounded scope: Clinic-scoped base pricing sufficient
> for the current approved V1 flow, plus the concrete Location-attribution fix (`invoice.location_id`
> derived from the validated Visit, never from payload). This closure does **not** promote the remaining
> O-06 residual item: distinct tariffs for the *same* Service across different Locations of the same
> Clinic are **not currently proven as a hard V1 requirement** — deferred on the absence of a proven
> requirement, **not** on a single-Clinic/single-Location V1 assumption (**multi-Location product
> support is not deferred**). No ServiceOffering, no `u_service_code` change, no `0021`; latest
> migration remains `0020`.
>
> **Current Phase 6 closure note (re-verified 2026-09-18):** Owner Roadmap Phase 6 — Scheduling Engine is
> **CLOSED / TECHNICALLY COMPLETE in a bounded scope** on live main
> `bd5e6a1819a838648dbdcbc6914c0d8b24bba38b`, through merged **PRs #69–#71 + #73–#76** (Slice 7 =
> concurrency-safe multi-shift conflict enforcement, **PR #76** MERGED 2026-09-18T09:59:44Z). The PR range
> also contains the documentation-only **PR #72** (owner-requirement preservation — not a slice). Post-merge
> evidence on that merge SHA: all four required workflows terminal-success (CI `35332404611` · Real WP
> `35332404609` · Pilot/Staging `35332404592` · Closure `35332404644`) and 19/19 check runs success.
> Requirement coverage preserved as established for `FR-3.1`–`FR-3.4` and `FR-3.6`–`FR-3.10`;
> **`FR-3.5` belongs to Phase 8 and is not claimed here.** This closure does **not** resolve the `O-03`
> (DST/timezone-conversion test coverage) or `O-04` drift rows, does **not** implement the two deferred
> hardening items (ج: the no-scope legacy branch in `requireClinicianWithinTrustedClinic()`; د: the
> `horizon_days` job-payload upper bound) — both are preserved for the planned C7 hardening slice
> *(historical — the planned C7 hardening slice has since been merged as **PR #78** at
> `4b322d9dc8839e1efd66d712aedc06d9f22cd175`; see the note below)* — and
> does **not** correct the inaccurate `ScheduleController.php` exception-route comment (product behavior is
> correct; comment-only debt, to be fixed in the next legitimate product slice touching that file).
> Latest migration remains `0020`; no `0021` exists or was reserved.
>
> **Current C7 hardening + Phase 7 Slice 1 / FR-4.6 closure note (re-verified 2026-09-18):** the planned C7
> hardening slice is **CLOSED** — **PR #78** MERGED 2026-09-18T15:07:23Z (merge `4b322d9dc8839e1efd66d712aedc06d9f22cd175`;
> post-merge on the merge SHA: all four required workflows terminal-success + 19/19 check runs success):
> (ج) the no-scope READ paths in `ScheduleService::list()`/`listExceptions()` now fail closed without an
> explicit trusted Clinic scope; (د) a numeric payload `horizon_days` now shares the settings path's `1..365`
> bound (`> 365` or `<= 0` fails closed as a per-clinician skip under the established invalid-horizon
> behavior). The remaining write-helper no-scope compatibility branches were reconstructed after PR #78 as
> **NO CHANGE JUSTIFIED** (production REST/wp-admin write callers establish explicit trusted scope; no
> current src/bin/job caller without scope was found; no verified compatibility consumer depends on the
> branch) — not another required C7 patch. Producer reality: `ScheduleService::regenerate()` and the
> recurring scheduler do not set `horizon_days`; `bin/cpms slots generate --days=N` does (a real
> operator/server CLI producer); no external REST job-enqueue route was verified (a limited statement, not a
> universal claim about all possible external systems). The inaccurate `ScheduleController.php` comment
> (الف) is unchanged — PR #78 did not touch that file. **Phase 7 = STARTED; Slice 1 / FR-4.6 = CLOSED (BOUNDED)**
> via **PR #79** (MERGED 2026-09-18T18:12:54Z; merge `757d7424332d87cdd3d1894ee39f9f5f01bf0a33`;
> post-merge on the merge SHA: all four required workflows terminal-success + 19/19 check runs success) —
> owner-issued product policy for the additive `nearby_slots` alternatives on `CLINIC_SLOT_TAKEN`
> (distinct from the original SRS wording of FR-4.6). **Phase 7 as a whole is NOT complete; no other
> FR-4.x requirement is claimed closed; no Slice 2.** This closure does **not** resolve the `O-03`/`O-04`
> drift rows and creates no new drift row. Latest migration remains `0020`; no `0021` exists or was reserved.
>
> **Current Phase 7 Slice 2 closure note (re-verified 2026-09-19):** Owner Roadmap Phase 7 — Appointment Engine
> remains **STARTED / IN PROGRESS**; **Slice 2 = CLOSED (bounded technical closure)** on live main
> `e60c62428e6109e7182d04d3266f0d15b12b0d2f`, through merged **PR #81** (MERGED 2026-09-19T03:08:27Z). Scope:
> enforcement of appointment invariant **I-3** while a **genuinely active Visit** exists (the connected Visit in
> one of the established live `ACTIVE_STATUSES` with `active = 1`) for **T5 patient cancel**, **T6 staff
> cancel**, **T7 reschedule** — rejected with error code `HAS_ACTIVE_VISIT` / **HTTP 409**; **check-in now
> serializes against these appointment mutations using the existing appointment-row locking architecture**; a
> **stale `active_visit_id` pointer by itself is NOT treated as a genuinely active Visit**. No migration/schema
> change was required; latest migration remains `0020`. Accepted **final RED =
> `29bd8c36a263fc25b62a0f29554a7ce3224415a3`** (exact-head RED CI run `35398347887` = `completed/failure` at
> that head); **GREEN product commit = `6c16e296d73eb6caf7271d49634de58ca0e6b1c6`**; post-merge on the merge
> SHA: all four required workflows terminal-success (CI `35417703302` · Real WordPress Acceptance `35417703278`
> · Closure Gate `35417703283` · Pilot/Staging Readiness Gate `35417703280`) and 19/19 check runs success.
> Process history preserved, not rewritten: the earlier RED attempt `14319d0fd38dae9fd55ff36e13b301a658934c9d`
> carried **collateral failures caused by test queue pollution** — test/harness hygiene defects, corrected
> before the accepted final RED; that earlier attempt is **not** recorded as clean evidence. **Phase 7 as a
> whole is NOT complete; no other FR-4.x requirement is claimed closed; no Slice 3 exists or is claimed.** The
> "no Slice 2" wording in the preceding note is the state of that checkpoint and is superseded by this note
> (historical text preserved, not rewritten). This closure does **not** resolve the `O-03`/`O-04` drift rows
> and creates no new drift row. Latest migration remains `0020`; no `0021` exists or was reserved.
>
> **Current Phase 7 bounded closure note (re-verified 2026-09-19):** Owner Roadmap Phase 7 — Appointment Engine
> is **CLOSED / TECHNICALLY COMPLETE (BOUNDED)** on live main
> `d7484ceddf698898483122cf52bb5abb55718744` (PR #85 MERGED 2026-09-19T12:04:04Z; open PRs at
> re-verification = 0), in the bounded scope of the merged work: the existing `FR-4.x` booking primitives
> (slot holds with TTL + automatic expiry, idempotency, atomic claim — found already present by the read-only
> Phase 7 scoping); **Slice 1 / FR-4.6 (PR #79)**; **Slice 2 — I-3 active-Visit protection (T5/T6/T7) +
> check-in serialization (PR #81)**; **FR-5.3 staff/secretary reschedule (PR #83, MERGED 2026-09-19T07:19:56Z;
> merge `cf1ace137922d84b7042841e68629aedfb8334a8`)** — shared reschedule core + REST
> `POST /appointments/{id}/reschedule` (established `clinic/v1` namespace) with `cpms_appt_reschedule` and
> trusted Clinic scope; **owner-issued product policy (NOT original SRS wording):** staff bypasses the patient
> 24h reschedule deadline and the patient destination min-lead restriction, and a successful staff reschedule
> produces both the internal patient notification and the existing reschedule SMS/change notification (patient
> B5 deadline/min-lead/ownership unchanged); **FR-5.5 manual + automatic no-show completion (PR #85, MERGED
> 2026-09-19T12:04:04Z; merge `d7484ceddf698898483122cf52bb5abb55718744`)** — manual staff REST surface +
> existing sweep after the grace period (default 30 min, configurable) + stale-pointer correction (a stale
> `active_visit_id` pointing at an inactive/terminal Visit no longer blocks the sweep and is cleared on
> success; a genuinely active Visit still blocks — I-3) + concurrency/ER-06 compatibility (check-in can never
> bind a genuinely active Visit to a terminal `no_show` appointment). **FR-5.4 bounded non-claim:** SRS FR-5.4
> uses `pending` with TTL and gives doctor-approval as an illustrative example; the current architecture does
> not persist appointment `pending` (unconfirmed booking capacity = slot holds with TTL + automatic expiry;
> staff create collapses create→pending→confirm in one operation, persisting `confirmed`); Phase 7 does
> **not** claim a doctor-approval workflow, none is implemented, and none may be invented as a closure
> requirement without future owner-directed product scope; **no persisted pending-appointment expiry job
> exists**. Post-merge evidence on both merge SHAs: four required workflows terminal-success + 19/19 check
> runs success (PR #83: CI `35429028253` · Real WordPress Acceptance `35429028209` · Closure Gate
> `35429028230` · Pilot/Staging Readiness Gate `35429028233`; PR #85: CI `35441813653` · Real WordPress
> Acceptance `35441813668` · Closure Gate `35441813683` · Pilot/Staging Readiness Gate `35441813696`).
> Exact-head evidence: 19/19 check runs success at PR #83 head `54612ab8…` and PR #85 head `2bbb6085…`.
> The "Phase 7 as a whole is NOT complete" wording in the preceding notes is the state of those checkpoints
> and is superseded by this note (historical text preserved, not rewritten). This closure does **not** resolve
> the `O-03`/`O-04` drift rows (and no drift row is promoted by it) and creates no new drift row. Latest
> migration remains `0020`; no `0021` exists or was reserved (re-verified on live main `d7484ce…`: 20
> migration files `0001`..`0020`).

---

## بخش ۱ — اسناد فعال و پرریسک: ✅ RESOLVED در Phase 0.5

| # | سند | Drift | اقدام انجام‌شده |
|---|---|---|---|
| R-01 | `docs/roadmap/roadmap.md` | دو نظام فازبندی موازی (`F0..F10` در برابر Phase 0..20) | ✅ بخش ۰ به‌عنوان AUTHORITATIVE اضافه شد؛ `F*`/`V1.5`/`V2`/`Doc-Phase` صریحاً **Legacy** نام گرفتند + جدول نگاشت |
| R-02 | `docs/roadmap/roadmap.md` | «Multi-clinic/Team» و «Branch» در `V2` | ✅ `V2` منحل شد؛ محتوایش به Phase 2/3/4 بازتوزیع شد |
| R-03 | `docs/roadmap/roadmap.md` | تأیید حکم «۰ FOUNDATIONAL» بازبینی آمادگی | ✅ خط با ارجاع به C-1..C-9 خنثی شد |
| R-04 | `docs/agent-guide.md` | «تک‌کلینیک در V1» | ✅ اصلاح شد |
| R-05 | `docs/agent-guide.md` §3.1 | «منبع حقیقت فاز = `roadmap.md`» بدون ذکر اقتدار Owner | ✅ به «Owner-approved Phase 0..20 = authoritative» تغییر یافت |
| R-06 | `docs/agent-guide.md` §2 | «۳۹ جدول (مهاجرت‌های 0001–0003)» | ✅ → ۴۱ جدول، `0001..0009` |
| R-07 | `docs/agent-guide.md` §2 | «PR #1: OPEN» | ✅ → MERGED؛ ۹ PR |
| R-08 | `docs/agent-guide.md` §2/§0 | شاخهٔ منسوخ `arena/01a071c4-doctor` در چک‌لیست شروع کار | ✅ پارامتریک شد |
| R-09 | `docs/agent-guide.md` §3.4 | «Authorization = فقط `user_can('cpms_…')` (+ Scope در V2)» | ✅ با ارجاع به AD-06 و منع فراخوانی مستقیم به‌روز شد |
| R-10 | `docs/README.md` | «F1..F8 کامل — F9 منتظر تأیید» (سه فاز عقب) | ✅ به‌روز شد |
| R-11 | `docs/README.md` | نظام سوم شماره‌گذاری «Phase 1..8» برای مستندسازی | ✅ به `Doc-Phase 1..8` تغییر نام یافت + هشدار صریح |
| R-12 | `ADR-0003` | «`clinic_id` … V1 مقدار ثابت 1» + ۳ ادعای اثبات‌نشده | ✅ **Superseded کامل** توسط ADR-0031؛ متن تاریخی حفظ شد |
| R-13 | `ADR-0027` | مدل `Clinic → Branch → Department → Clinician` بدون Organization | ✅ **Superseded جزئی**؛ بندهای معتبر صریحاً فهرست شدند |
| R-14 | `ADR-0026` | D-4 (مدل Scope)، D-15 (زمان‌بندی V2)، بند `clinic_id=1` | ✅ **Superseded جزئی**؛ D-1 و D-5..D-14 معتبر ماندند |
| R-15 | `ADR-0002` | `AccessPolicy` به‌گونه‌ای نوشته شده که وجودش را القا می‌کند؛ «۳ نقش» | ✅ یادداشت **NOT IMPLEMENTED** + تصحیح به ۵ نقش |
| R-16 | `ADR-0012` | «Dangling-Check Job در F1 ساخته شود» | ✅ یادداشت **NOT IMPLEMENTED** + شمارش صحیح FK |
| R-17 | `ADR-0015` | `resolvePatient` و `Repository Base` القای وجود | ✅ یادداشت **NOT IMPLEMENTED** + «merge = schema-only» |
| R-18 | `ADR-0013` | منطقهٔ زمانی فقط در `clinics.timezone` | ✅ یادداشت گسترش AD-08 |
| R-19 | `phase0.5-target-model.md` | ادعای غلط «hardcodeها عمدتاً خارج Repository» | ✅ حذف و با ۵۴ / ۲۴ / ۳۰ / ۱۳-از-۲۳ جایگزین شد |
| R-20 | *(سند وجود نداشت)* | گزارش Phase 0 هیچ ردپای رسمی در مخزن نداشت | ✅ `report-phase-0-reverification.md` ایجاد شد |

---

## بخش ۲ — Domain / Schema → 🕒 **Phase 2 (Multi-Clinic Core)**

| # | سند | Current Claim | Actual Reality | اقدام در Phase 2 |
|---|---|---|---|---|
| D-01 | `erd/erd.md:62` | «فهرست جداول (**۳۶ جدول**)» | **۴۱** | تصحیح عدد |
| D-02 | `erd/erd.md:10` D-2 | «`clinic_id` (FK→clinics) در **همه** جداول پزشکی → (V1: مقدار 1)» | ۲۵ جدول ستون دارند؛ **۴ FK**؛ ۲۱ بدون FK؛ ۵۴ hardcode | بازنویسی D-2 مطابق AD-13 |
| D-03 | `erd/erd.md` دیاگرام Mermaid | ریشه = `CLINICS`؛ بدون `ORGANIZATIONS` و `LOCATIONS` | معماری ۵ لایه (ADR-0031) | ERD نسخهٔ ۲ — پیش‌نویسش در `phase0.5-target-model.md` §ب آماده است |
| D-04 | `erd/erd.md:14` D-5 | منطقهٔ زمانی فقط `clinics.timezone` | AD-08: `locations.timezone NOT NULL` | افزودن (هم‌راستا با Phase 6) |
| D-05 | `erd/data-dictionary.md:62` | `cpms_patient_user_links.clinic_id` → **FK** | **FK ندارد** | حذف نشان FK کاذب |
| D-06 | `erd/data-dictionary.md:110, 392` | `cpms_schedule` و `cpms_services` بدون نشان FK | **هر دو FK دارند** | افزودن نشان FK جاافتاده |
| D-07 | `erd/data-dictionary.md:339` | `cpms_drug_reference.clinic_id` DF=**1** با توضیح «global» | الگوی مضر «سراسری با مقدار ۱» | بازتعریف مفهوم «سراسری» |
| D-08 | `erd/data-dictionary.md` کل سند | بدون `organizations` / `locations` / `clinic_memberships` | ۸ جدول جدید | افزودن ۸ جدول |
| D-09 | `srs/SRS.md:17` | «خارج محدوده V1: **شعبه فعال** … فعال‌سازی در **V2**» | Location = Phase 2 | بازنویسی دامنه |
| D-10 | `srs/SRS.md:69` A-1 | «یک شعبه و عموماً یک پزشک فعال» + «Migration **کم‌خطر**» | ۸ جدول + ۲۱ FK + ۲ UNIQUE برگشت‌ناپذیر | بازنویسی A-1 — **کم‌برآورد جدی** |
| D-11 | `srs/SRS.md:392` NFR-SCA-1 | «`clinic_id` روی **همه** جداول پزشکی (تک-شعبه V1، چند-شعبه V2)» | ۲۵ از ۴۱ | تصحیح + حذف «V2» |
| D-12 | `srs/SRS.md` کل سند | صفر ذکر Organization/Location (`grep -ci` = ۰) | معماری ۵ لایه | افزودن FRهای جدید |
| D-13 | `srs/SRS.md:109` FR-2.1 | «MRN منحصربه‌فرد **در شعبه**» | واقعیت: `U(clinic_id, mrn)` = per-Clinic | تفکیک واژگان Clinic از Location |
| D-14 | `architecture/multi-doctor-readiness-review.md` سرصفحه | «ممیزی **۳۷ جدول**» | **۴۱** | تصحیح یا بایگانی |
| D-15 | همان §1.1 | «`clinic_id = 1` (**~۴۴ نقطه**) — ثابت Tenant، نه فرض تک‌پزشکی» | **۵۴** (+۳ default-param) | تصحیح + ابطال توجیه |
| D-16 | همان §0 | «**۰ FOUNDATIONAL CHANGE REQUIRED**» | ۹ قید C-1..C-9 | 🔴 **پرریسک‌ترین Drift باقی‌مانده** — حکم باید `SUPERSEDED BY PHASE 0` شود |
| D-17 | همان §1.10 Minor #7 | «`clinic_id=1` → Seam مرکزی هنگام نیاز … **خارج تصمیم فعلی**» | `ClinicContext` = C-3، اجباری در Phase 2 | ارتقا از Minor به بلاکر |
| D-18 | همان §1.6 | «افزودن `branch_id` … Migration افزایشی، **بدون rework**» | شکستن `u_sched_day`/`u_slot` = برگشت‌ناپذیر | ثبت ریسک |
| D-19 | همان §1.3 | «Merge (`cpms_patient_merges`) clinic-level است» | schema-only، پیاده‌سازی صفر | تصحیح |
| D-20 | `scope/mvp-scope.md:33` | «چند شعبه فعال + چند پزشک تیمی → **V2**» | Phase 2 | نگاشت مجدد |
| D-21 | `docs/README.md` جدول اسناد | ستون «وضعیت» = «منتظر تأیید» برای ۶ سند پایه | وضعیت تأیید از مخزن قابل استخراج نیست — **نمی‌دانم** | ⚠️ نیازمند تصریح Owner |

---

## بخش ۳ — Authorization / Roles → 🕒 **Phase 3 (Role & Access Control)**

| # | سند | Current Claim | Actual Reality | اقدام در Phase 3 |
|---|---|---|---|---|
| A-01 | `permissions/permission-matrix.md:8` P-2 | «نقش‌های WordPress: `cpms_patient`، `cpms_secretary`، `cpms_doctor`» | **۵ نقش** — `cpms_accountant` و `cpms_manager` هم ثبت می‌شوند | افزودن ۲ نقش |
| A-02 | همان `:153` | «نقش پنجم `clinic_manager` … **گسترش آماده**» | از قبل با slug `cpms_manager` ساخته شده | تصحیح — «آینده‌ای که گذشته است» |
| A-03 | همان `:18` P-12 | «Scope … تا **V2** عملیاتی = CLINIC (**`clinic_id=1`**)» | AD-13 و AD-06 | بازنویسی کامل P-12 |
| A-04 | همان `:242` | «② Scope + جدول `cpms_staff_assignments` (V2)» | نام هدف = `cpms_clinic_memberships` | یکسان‌سازی نام‌گذاری |
| A-05 | همان §۲ | ماتریس Capability | ۳ Cap به هیچ نقشی نگاشت نشده: `cpms_patient_archive`, `cpms_patient_merge`, `cpms_audit_read` | تعیین‌تکلیف |
| A-06 | `security/auth-authorization.md:84` | «Scope … **V2** … تا آن فاز Scope عملیاتی = مطب (`clinic_id=1`)» | AD-06/AD-13 | بازنویسی §2.5 |
| A-07 | `security/auth-authorization.md` | مدل ۵ لایه بدون `AuthorizationService` و بدون ScopeContext | طراحی در `phase0.5-target-model.md` §ج | جایگزینی مدل |
| A-08 | `api/api-contract.md:127` G5 | Scope گزارش‌ها بر پایهٔ `clinician_id` بدون بُعد Clinic/Location | Scope باید `ScopeContext` بگیرد | افزودن هدر `X-CPMS-Clinic-Id` به قرارداد |
| A-09 | `permissions/permission-matrix.md` | «۴۶ Capability» | **۴۶ ✅ درست** | — (بدون Drift) |

---

## بخش ۴ — Remaining Master Data drift after the bounded **Phase 4** closure

| # | سند | Drift | اقدام |
|---|---|---|---|
| M-01 | `multi-doctor-readiness-review.md` §1.10 | Specialty = `cpms_clinicians.specialty VARCHAR(190)` تک‌مقداری | نرمال‌سازی به `cpms_specialties` + `cpms_clinician_specialties` (M:N) |
| M-02 | همان | Department وجود ندارد | مدل‌سازی |
| M-03 | همان | Room = `cpms_clinicians.room` (فیلد آزاد) | مدل‌سازی به‌عنوان فرزند Location |
| M-04 | `ADR-0027` بند ۴ | «Doctor↔Specialty باید چندتایی باشد» | پیاده‌سازی M:N |
| M-05 | ⚠️ | حذف ستون legacy `clinicians.specialty` | **OPEN DECISION (Q11)** — پیشنهاد: نه در Phase 2 |

---

## بخش ۵ — سایر فازها

| # | سند | Drift | فاز مالک |
|---|---|---|---|
| O-01 | `ADR-0012` | Dangling-Check Job وجود ندارد | 🕒 **Phase 2** — بخشی از نیازش با ۲۱ FK جدید برطرف می‌شود |
| O-02 | `ADR-0015` | `resolvePatient()` و پیاده‌سازی Merge وجود ندارند | 🕒 **Phase 2/4** — وابسته به تصمیم Q2 |
| O-03 | `ADR-0013` / `state-machines/` | DST و تبدیل منطقهٔ زمانی پوشش تست ندارند | 🕒 **Phase 6** — Scheduling Engine — ⚠️ **باز می‌ماند**: Phase 6 در `bd5e6a18` بسته شد (BOUNDED) اما این قلمِ پوشش تستِ DST/تبدیل منطقهٔ زمانی **با آن بستن، بسته نشده است**؛ فاز مالکِ سلول تاریخی حفظ می‌شود و قلم برای کار hardening بعدی باز است |
| O-04 | `ADR-0004` / `ADR-0017` | Slot و Duration بدون بُعد Location | 🕒 **Phase 6** — ⚠️ **باز می‌ماند**: بُعد Location در مرزهای Scheduling (برنامه/استثنا/تولید Slot) در PRs #69–#71 + #73–#76 ادغام شد، اما خودِ ردیفِ drift (بُعد Location در Slot **و Duration** طبق `ADR-0004`/`ADR-0017`) با بستن BOUNDED فاز ۶ **بسته اعلام نمی‌شود**؛ بدون ادعای تکمیل، باز می‌ماند |
| O-05 | `state-machines/appointment.md` | بدون بُعد Location | 🕒 **Phase 7** |
| O-06 | `scope/mvp-scope.md` | تعرفه/Pricing بدون سطح Organization یا per-Location | ✅ **Phase 5 = CLOSED / TECHNICALLY COMPLETE (BOUNDED)** — **PR #67 MERGED** 2026-09-17T15:36:54Z (merge = `e063b42260cb5ab740acf48dcf73c6c67b11fef6`؛ head = `fa86e41e415d1b7fcca3dec4c88fadb25da71d1a`). **Pricing پایه Clinic-scoped است و برای جریان تأییدشدهٔ فعلی V1 کافی است**؛ Slice 1 شکافِ مشخصِ انتساب Location را با استخراج `invoice.location_id` از Visit معتبر بست (هرگز از payload) و `invoiceView()` مقدار ذخیره‌شده را برمی‌گرداند. **قلمِ باقی‌ماندهٔ باز (بخشی از این بستن نیست):** «تعرفهٔ متفاوت برای *همان* Service در Locationهای متفاوتِ یک Clinic» **به‌عنوان الزام سختِ V1 اثبات نشده** است ⇒ بدون ServiceOffering، بدون تغییر `u_service_code`، و بدون migration جدید برای فرضِ per-Location pricing (آخرین migration = `0020`؛ `0021` ساخته/رزرو نشده). ⚠️ **تصریح:** این تعلیق بر پایهٔ **نبودِ الزامِ اثبات‌شده** است، **نه** فرضِ تک‌Clinic/تک‌Location — **پشتیبانی چند Location در V1 به‌تعویق نیفتاده است** (معماری مرجع `Organization → Clinic → Location`)؛ آنچه اثبات نشده فقط «تعرفهٔ متفاوت per-Location برای همان Service» است. اگر الزامِ تأییدشدهٔ آینده‌ای چنین چیزی بخواهد، تصمیمِ scoped جداگانه با بازبینی schema/uniqueness لازم است (قابل‌ردیابی به ردیف ۲۲ — `services` — در `phase0.5-target-model.md` §د‑۶‑۲: «location_id NULL = همهٔ محل‌ها» + گامِ برنامهٔ `M-08`؛ این یک ردیفِ **برنامه** است، نه migration تأییدشده/ساخته‌شده). سلول «فاز مالک» تاریخی این سطر (**Phase 5**) محفوظ است؛ این سطر بستنِ فاز مالک را ثبت می‌کند، نه حذف Drift را |
| O-07 | `testing/testing-plan.md` TP-15 | تست Dangling/Migration به مکانیزم ناموجود ارجاع می‌دهد | 🕒 **Phase 2** |
| O-08 | `phase-reports/report-pilot-gate.md` §1 | «Fresh Install — **۳۷+ جدول**» | 🕒 بایگانی — سند تاریخی، در زمان خود درست بود |
| O-09 | `docs/commercial-gap-audit.md` | untracked؛ P0-1..P0-6 با Phase 1 هم‌پوشانی دارد | 🕒 **Phase 1** — طبق دستور Owner دست‌نخورده می‌ماند |

---

## بخش ۶ — ⚠️ OPEN DECISIONS

| # | موضوع | وضعیت |
|---|---|---|
| **OD-1** | عناوین Phase 8..20 | ✅ **بسته شد 2026-09-08** — هر ۲۱ فاز در [`roadmap.md`](roadmap/roadmap.md) §۰ تصریح شد |
| **Q2** | مدل Patient Identity | ✅ **بسته شد — AD-14** — Identity سطح Organization، Record ایزوله سطح Clinic، immutable internal ID، موبایل فقط lookup |
| **Q8** | هر Clinic حداقل یک Location | ✅ **بسته شد — AD-15** — بله؛ بدون special-case «بدون Location» |
| **Q10** | Namespace قابلیت سازمان | ✅ **بسته شد — AD-16** — `cpms_org_*`؛ بدون capability همه‌کاره؛ ماتریس نهایی Phase 3 |
| **OD-2** | نگاشت اقلام `V1.5` و باقیماندهٔ `V2` (OCR، 2FA، Online Payment، Insurance/Lab، Push، Mobile API/JWT) | ⚠️ باز — بدون فاز |
| **OD-3** | **ناهماهنگی نسخه** — ریشه‌یابی‌شده در §۷ | ⚠️ باز — پیش از پایان Phase 1 |
| **OD-4** | وضعیت واقعی تأیید ۶ سند پایه | ⚠️ **نمی‌دانم** — از مخزن قابل استخراج نیست |
| **OD-5** | Pepper زنجیرهٔ Audit (`AuditLogger::pepper()`) هنوز مقدار ثابت درونِ کد را دارد | ⚠️ **باز — جدید در Phase 1A** — تعویض، زنجیرهٔ رکوردهای Audit موجود را نامعتبر می‌کند |
| **OD-6** | رمزنگاری فایل بالینی و بکاپ در حالت سکون | ⚠️ **باز — جدید در Phase 1A** — تصمیم محصولی (مدیریت کلید/Restore) |
| **OD-7** | انتقال اجباری `clinic-files` و `cpms-backups` به خارج از DocumentRoot | ✅ **بسته — تصمیم مالک: گزینهٔ A، پیاده‌سازی‌شده در Phase 1A.** ریشهٔ پیش‌فرض به `…/cpms-private/` بیرون از DocumentRoot منتقل شد + مهاجرت idempotent با تأیید sha256 پیش از حذف مبدأ |
| **OD-8** | `verify_mobile` از Endpoint عمومی می‌تواند حساب `cpms_patient` بسازد | ✅ **بسته — تصمیم مالک: گزینهٔ الف، پیاده‌سازی‌شده در Phase 1A.** `PROVISIONING_PURPOSES = [LOGIN]`؛ `verify_mobile` از مسیر فقط‌خواندنی `findExistingUser()` می‌رود و شمارهٔ بی‌صاحب `user_id = 0` می‌گیرد |
| **OD-9** | Fail-Closed کردن ریشهٔ بکاپ داخل DocumentRoot | ✅ **بسته شد — تصمیم مالک (گزینهٔ C سخت‌گیرانه)، پیاده‌سازی‌شده.** ریشهٔ فعال بکاپ Fail-Closed رد می‌شود (`CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT`)؛ ریشهٔ داخل webroot فقط مبدأ legacy فقط‌خواندنی است؛ Safety Backup پیش از Restore فقط به مقصد امن خصوصی هدایت می‌شود (restore قفل نمی‌شود)؛ مهاجرت idempotent ریشهٔ ناامن به ریشهٔ خصوصی. تفصیل: [`phase1-current-security-model.md`](security/phase1-current-security-model.md) §۵-۴ · گزارش: [`report-od9-closure.md`](phase-reports/report-od9-closure.md) |
| **Q6** | Scope جدول `cpms_rate_limits` (سراسری / per-Clinic / دوسطحی) | ⚠️ باز — **مرتبط با Phase 1A** (rate limiting سراسری بدون آن کار می‌کند) |
| **Q11** | حذف ستون legacy `clinicians.specialty` | ⚠️ باز — Phase 4 |
| **Q12** | UX تعویض Clinic | ⚠️ باز — بدون اثر بر Backend |

*(Q1..Q13 کامل در [`phase0.5-target-model.md`](architecture/phase0.5-target-model.md) §و — Decision Register)*


---

## بخش ۷ — OD-3: ریشه‌یابی ناهماهنگی نسخه (فقط‌خواندنی، بدون تغییر)

### واقعیت‌های اثبات‌شده

| # | یافته | شاهد |
|---|---|---|
| ۱ | **دو فایل CHANGELOG جداگانه و متفاوت وجود دارد** | `CHANGELOG.md` (۱۰٬۱۷۰ بایت) و `clinic-practice-management/CHANGELOG.md` (۴۳٬۲۱۴ بایت) — `diff -q` → `differ` |
| ۲ | **CHANGELOG افزونه صریحاً می‌گوید ۱.۰.۲ منتشر نشده** | `clinic-practice-management/CHANGELOG.md:7` → `## [1.0.2] — unreleased` |
| ۳ | **CHANGELOG ریشه هیچ نشانهٔ «unreleased» ندارد** | `CHANGELOG.md:5` → `## [1.0.2] — 2026-09-07 (…)` — یعنی مثل نسخهٔ منتشرشده خوانده می‌شود |
| ۴ | **دو CHANGELOG تاریخچهٔ متفاوتی دارند** | ریشه: `1.0.2` → `1.0.1` → `1.0.0` → `[Unreleased]`. افزونه: `1.0.2` → `1.0.0` (**فاقد `1.0.1`**) |
| ۵ | نسخهٔ کد در **دو جا** و هماهنگ است | `clinic-practice-management.php:6` (`Version: 1.0.0`) و `:18` (`define('CPMS_VERSION', '1.0.0')`) |
| ۶ | **دو گیت CI مقدار `1.0.0` را hardcode assert می‌کنند** | `.github/workflows/closure-gate.yml:130` و `:460` → `CPMS_VERSION === '1.0.0'` |
| ۷ | نام artifact انتشار از همین ثابت مشتق می‌شود | `bin/build-release.sh:16` → `VERSION` از `CPMS_VERSION` استخراج می‌شود |
| ۸ | هیچ workflow خودکاری نسخه را bump نمی‌کند | جست‌وجو در `.github/workflows/*.yml` — فقط دو assert بالا |

### تحلیل ریشه

این **باگ در ثابت نسخه نیست؛ شکاف در فرایند انتشار است.**

`1.0.0` نسخهٔ **منتشرشده** است. `1.0.1` و `1.0.2` مجموعه‌تغییراتی هستند که پس از آن merge شدند ولی **هرگز به‌عنوان یک release بسته نشدند** — CHANGELOG افزونه این را با برچسب `unreleased` درست ثبت کرده. یعنی هدر افزونه در واقع **درست** است.

مشکل واقعی دو چیز دیگر است:

1. **دو CHANGELOG موازی و واگرا** — یکی می‌گوید `1.0.2` منتشر شده، دیگری می‌گوید نشده. هیچ سندی نمی‌گوید کدام مرجع است.
2. **قفل بودن نسخه در CI** — دو گیت `closure-gate.yml` مقدار `1.0.0` را عیناً assert می‌کنند. یعنی **هر bump نسخه، بدون تغییر هم‌زمان workflow، CI را قرمز می‌کند.** این یک تلهٔ فرایندی برای همهٔ فازهای بعدی است.

### پیامد عملی برای Phase 1 به بعد

Phase 1 و Phase 2 هر دو تغییر Schema/Migration دارند و طبق **AD-12** مسیر ارتقا باید سالم بماند. اگر نسخه bump نشود، مسیر `Upgrade path (main → RC)` نمی‌تواند یک ارتقای واقعی نسخه‌به‌نسخه را بیازماید. پس این تصمیم **پیش از پایان Phase 1** لازم می‌شود، نه فوراً امروز.

### گزینه‌ها (تصمیم با Product Owner — هیچ‌کدام اعمال نشد)

| گزینه | شرح | پیامد |
|---|---|---|
| **الف** | ثابت نگه‌داشتن `1.0.0`؛ اصلاح `CHANGELOG.md` ریشه به `[1.0.2] — unreleased` تا با CHANGELOG افزونه هماهنگ شود | کم‌ریسک‌ترین. CI دست‌نخورده. فقط یک تصحیح سند |
| **ب** | bump به `1.0.2` در هدر + `CPMS_VERSION` + به‌روزرسانی دو assert در `closure-gate.yml` | نسخه با تاریخچه هم‌راستا می‌شود، ولی **تغییر کد و workflow** لازم دارد ⇒ خارج از دامنهٔ مستندسازی Phase 0/0.5 |
| **ج** | ادغام دو CHANGELOG در یکی و ثبت مرجع بودنش | مستقل از الف/ب؛ ریشهٔ واگرایی را می‌بندد |

**توصیهٔ مهندسی (بدون اعمال):** «الف + ج» حالا، و «ب» در Gate پایانی Phase 1 هم‌زمان با اولین Migration جدید. دلیل: bump نسخه بدون یک release واقعی معنا ندارد، و دست زدن به `closure-gate.yml` در فاز مستندسازی نقض قاعدهٔ «هیچ فایل application/runtime تغییر نکند» است.

---

## بخش ۸ — ثبت‌های Phase 2 (Recovery، 2026-09-09)

### ۸-۱ — ⚠️ ابزار کیفیت: گیت خودکار WordPress Coding Standards وجود ندارد

**وضعیت:** GAP ثبت‌شده؛ ادعای «WPCS PASS» تا پیش از پیاده‌سازی گیت **معتبر نیست**.
کد فعلی از قراردادهای دستی (phpcs:ignore comments، prepared SQL) پیروی می‌کند ولی
هیچ ابزاری آن را به‌صورت خودکار تجزیه نمی‌کند. تنها گیت استاتیک فعلی: PHPStan
level 3 (src+bin).

**پیشنهاد حداقلی برای Phase 2 End Gate (پیاده‌سازی فقط پس از GREEN شدن
migration foundation — بازنویسی/فرمت دستی کد در میانهٔ recovery انجام نمی‌شود):**

1. **Dependency** (فقط در CI، بدون تغییر composer.lock محلی):
   `composer require --dev wp-coding-standards/wpcs:^3.1` داخل job (نصب
   runtime در CI؛ پین نسخه در همان خط). WPCS 3.x با PHP_CodeSniffer ^3.9.
2. **`phpcs.xml.dist`** (committed): ruleset اولیه = زیرمجموعهٔ کم‌سروصدا اما
   پربار `WordPress-Core` + `WordPress.WP.PreparedSQL` + sniffs امنیتی
   (escaping/sanitization)؛ scope: `src/`، `bin/`، فایل ورودی افزونه،
   `uninstall.php`. تست‌ها فاز بعدی اضافه می‌شوند.
3. **ضد mass-churn:** اجرای اول به‌صورت report-only؛ تولید
   `phpcs-baseline.xml` (یا `--ignore` روی legacy files)؛ گیت فقط
   **رگرسیون** (نقض جدید نسبت به baseline) را قرمز می‌کند. فرمت‌سازی
   دستی/gutters ممنوع تا پایان Phase 2.
4. **CI job مستقل** (`wpcs`) — سبک، بدون نیاز به MySQL؛ fail فقط روی نقض
   جدید. سطح قواعد در Phases بعدی به‌تدریج بالا می‌رود (بدون پایین آوردن).

**وضعیت: پیاده‌سازی شد (کامیت `chore(quality): enforce WordPress coding
standards on changed code`؛ بر مبنای SHA پیاده‌سازی f23e0c5 — همان
کامیت پذیرش Migration Foundation).** جزئیات نهایی نسبت به پیشنهاد:
- استراتژی = **changed-lines** نسبت به `WPCS_BASELINE` (SHA کامل در env آن
  job در `ci.yml`)؛ نه baseline-file و نه mass-format. bump آن دستی +
  مستند است. ارتقا از changed-files به changed-lines (در کامیت WPCS-cleanup
  مربوط به C4): اولین اجرای موفق sniff (run 34318905233 روی 91e5fe4) نشان
  داد فایل‌های legacy با نقض تاریخی (مثلاً App.php با ~۶۵۰ مورد) به محض
  تغییرِ چندخطی کل تاریخچه‌شان گیت را می‌شکنند؛ قاعدهٔ مصوب مالک
  («new/touched code نباید violation جدید وارد کند») دقیقاً با خط-level
  پیاده می‌شود: فقط نقض روی «خطوط add شده» نسبت به baseline می‌شکند و
  فایل کاملاً جدید = تمام خطوطش. پیاده‌سازی: hunk headerهای `git diff -U0`
  → مجموعهٔ خطوط add، خروجی JSON از phpcs → intersect در python؛ گزارشِ
  نقض‌های خطِ add شده به‌صورت کامنت PR.
- وابستگی: ephemeral در CI با دامنهٔ `wpcs:^3.1` (بدون exact-pin) —
  مطابق الگوی موجود job integration (نصب runtime PHPUnit)؛ چون repo بدون
  composer.lock است، هیچ تغییری در dependency graph پروژه لازم نشد و
  runtime dependency جدیدی اضافه نشد. دلیل عدم pin دقیق (evidence:
  runs 34315163722/34315474820): هم `php_codesniffer 3.11.x` و هم خودِ
  `wpcs 3.1.0` در رجیستری security advisory دارند و composer آن‌ها را از
  resolution حذف می‌کند؛ بنابراین فیلتر امنیتی composer، مرجع انتخاب
  جدیدترین نسخهٔ سالمِ سازگار با major 3.x است. suppress نکردن advisoryها
  عمداً انتخاب شد (امنیت > قطعیتِ نسخه). resolution مورد انتظار =
  wpcs 3.4.1 + phpcs 3.13.6 + PHPCSUtils 1.2.3 + PHPCSExtra 1.5.1 (درخت
  وابستگیِ wpcs 3.4.1: `^3.13.5` phpcs + `^1.2.3` phpcsutils + `^1.5.1`
  phpcsextra؛ standardهای `Universal.*`/`NormalizedArrays.*` از PHPCSExtra
  می‌آیند، نه از خود phpcs). از کامیت WPCS-cleanup به بعد step نصب،
  `phpcs --version` + `composer show` را چاپ می‌کند (evidence پایدار).
  سه خطای ruleset پیش از اولین sniff موفق (Class D، runs 34318212809 /
  34318535964 / و اولین اجرای محلی): (۱) property آرایه‌ای با comma-string
  از PHPCS 3.3+ deprecated → `<element>`؛ (۲) reference به sniff ناموجود =
  abort کل ruleset — sniffهای alignment در آن resolution هنوز ناموجود بودند
  و بعداً با PHPCSExtra موجود شدند؛ (۳) exclusion باید sniff/کدِ موجود باشد.
- `phpcs.xml.dist` = canonical: `WordPress-Extra` (شامل Core + sniffs
  امنیتی/PreparedSQL/I18n) + text domain `cpms` + PrefixAllGlobals
  (`cpms, CPMS, ClinicCore` — پیشوند namespace رسمی PSR-4 افزونه) و
  **هفت اعمال‌نکردنِ مستند**:
  - سبکی/ایدیوم پروژه (پایگاه کد PSR-style است و «هم‌خوانی درون-فایلی»
    با کد مجاور مقدم): FileName (تعارض با PSR-4)، SpaceIndent (۴-space)،
    Yoda، `Universal.Arrays.DisallowShortArraySyntax` (کل پایگاه کد
    `[]`)، `WordPress.Arrays.ArrayDeclarationSpacing` (آرایه‌های تک‌خطی)،
    `NormalizedArrays.Arrays.ArrayBraceSpacing` (فاصلهٔ داخل براکت).
  - با استدلال امنیتی/معماری: `WordPress.Security.EscapeOutput.ExceptionNotEscaped`
    — پیام Exceptionها domain-payload هستند که در مرز REST به envelope
    ساختاریافتهٔ JSON تبدیل می‌شوند (الگوی مستقر BookingException)؛ escape
    در محل throw منجر به double-encoding در پاسخ API می‌شود؛ مسئولیت
    خروجی با مرز controller است. بقیهٔ EscapeOutput فعال می‌ماند.
  هیچ sniff امنیتی/SQL/i18n دیگری غیرفعال نیست. scope = `src/`, `bin/`،
  فایل ورودی افزونه، `uninstall.php` (تست‌ها فاز بعدی).
- **Runner لوکال (validation بدون CI-roundtrip):** phpcs/phpcbf داخل
  php-wasm (`@php-wasm/node` PHP 8.2) با همان درخت وابستگی resolution CI
  (tarballهای codeload از GitHub tags)؛ نکات پیاده‌سازی: `--runtime-set
  installed_paths` (نه `--installed_paths`)، تعریف `STDOUT/STDERR` در
  bootstrap (SAPI وب)، غیرفعال‌سازی spawnها با `disable_functions` در
  php.ini روی VFS (`stty size` برای ابعاد ترمینال و `php -l` در
  Generic.PHP.Syntax → exclude لوکال معادل، lint جداگانه)، و خروجی از طریق
  `--report-file` (stdout در wasm قابل‌اتکا نیست). recipe کامل در حافظهٔ
  جلسه ثبت شد؛ ابزار disposable در /tmp است و هرگز commit نمی‌شود.
- PHPCompatibilityWP فعلاً کنار گذاشته شد (مستند): حداقل PHP افزونه 8.1
  است و ماتریس Unit 8.1–8.4 رفتار را واقعاً می‌آزماید؛ سود اضافی آن بر
  این پروژه ناچیز و بار نصب/عدم‌قطعیت job بیشتر بود.

### ۸-۲ — قرارداد Migration Down: production فقط forward-only است

**راستی‌آزمایی (grep روی src/ و bin/):** `MigrationRunner::rollbackOne()`
هیچ caller تولیدی ندارد — نه در `App`، نه در REST/Admin، نه در `bin/cpms`
(تنها زیرفرمان migration در CLI: `migrate`). اجرای down فقط در تست‌ها
(MigrationTest/Phase2SchemaTest) اتفاق می‌افتد.

**نتیجهٔ معماری:** down() یک **قرارداد نظم/reversibility برای تست و توسعه** است،
نه مسیر اجرای production. اولویت صحت: **forward migration** (اثبات‌شده با
Real-WP Acceptance ×2 روی 5379860/d443e3b — نصب واقعی تا `2026_09_09_0018`).
تست‌های rollback برای schema discipline باقی می‌مانند و زیرساخت تست آنها
(real-table isolation — `RealTableMigrations` trait) نباید معیار طراحی
production باشد.

### ۸-۳ — فایل‌های محافظت‌شده: LOST DUE TO SANDBOX REBUILD — NOT DELETED BY AGENT

`.download/` و `docs/commercial-gap-audit.md` طبق قرارداد untracked بودند
(هرگز commit نشدند). بازسازی sandbox (بین دو نوبت کاری) workspace را از snapshot
بازیابی کرد و این دو مسیر — که خارج از Git بودند — **از بین رفتند**. توسط
Agent حذف/تغییر داده نشده‌اند؛ نسخهٔ حدسی بازسازی نمی‌شوند. اگر مالک محتوای
آنها را لازم دارد، باید از منبع اصلی مجدداً تأمین شوند. Phase 2 را بلاک
نمی‌کند.

---

## بخش ۹ — ثبت‌های Phase 2 (بازبینیِ مستقلِ معماریِ طراحیِ Tenant Context، 2026-09-12)

> **دامنهٔ این ثبت‌ها:** سه driftِ **کوچک و شاهددار** که حینِ بازبینیِ فقط‌خواندنیِ
> [`architecture/phase2-tenant-context-remediation-design.md`](architecture/phase2-tenant-context-remediation-design.md)
> (Draft PR #26) راستی‌آزمایی شدند. **هیچ فایل PHP‌ای تغییر نکرد**، هیچ بازنویسیِ انبوهی انجام نشد و
> هیچ سندِ تاریخی بازنویسی نشد — طبق اصلِ حاکمِ همین Register («wholesale rewrite ممنوع»).

### ۹-A — 🕒 `settings-reference.md`: تناقضِ داخلی دربارهٔ محلِ نگهداریِ Secretِ SMS

| | |
|---|---|
| **سند/محل** | `docs/settings-reference.md:12` در برابرِ **همان سند** `:56` و §Secrets `:102-106` |
| **متنِ ناسازگار** | `:12` — «Secret/API Key در این جدول ذخیره نمی‌شود — فقط از `wp-config.php`/Environment (تصمیم F1-D3)» |
| **واقعیتِ راستی‌آزمایی‌شده** | `:56` و `:106` (و `ADR-0025-provider-agnostic-sms.md:44-45`) صریحاً می‌گویند credentialِ پنلِ SMS در **`cpms_settings.sms.auth`** ذخیره می‌شود — **فقط Ciphertext/‏nonce/‏tag + `last4`** (AES-256-GCM). کد نیز همین است: نوشتن `SmsService.php:348`، خواندن `:646-660`، رمزگشایی فقط در لحظهٔ call `:667+`؛ `CredentialVault.php:7-15`؛ redactionِ کپیِ audit در `Settings.php:145-147`/`:239-243` |
| **داوری** | جملهٔ `:12` روایتِ **کهنهٔ** «قاعدهٔ D3» است: قاعدهٔ درست **«plaintext ممنوع»** است، نه **«ذخیره در settings ممنوع»**. کلیدِ Vault **سطحِ نصب** است (`CPMS_SECRET_KEY` یا Saltهای نصب) ⇒ **رمزنگاری به‌خودیِ خود مرزِ tenant نیست** |
| **اثر** | سندِ طراحیِ Tenant Context (§۵-D-2) همین جملهٔ کهنه را به ارث برده بود ⇒ در همان سند **تصحیح شد** (C-1). خودِ `settings-reference.md` **دست‌نخورده** ماند |
| **فاز مالک / اقدام** | 🕒 **DEFERRED** — اصلاحِ جملهٔ `:12` (یک خط) هم‌زمان با اولین لمسِ مستنداتِ Settings در فازِ مالک؛ **بدونِ تغییرِ رفتار/کد** |

### ۹-B — 🕒 `background-jobs.md` §۲ در برابرِ registry واقعیِ runtime

| | |
|---|---|
| **سند/محل** | `docs/architecture/background-jobs.md` §۲ («فهرست Jobها») |
| **منبعِ حقیقتِ runtime** | `App::dispatcher()` + `RECURRING_JOBS` در `clinic-practice-management/src/Bootstrap/App.php:1002+` و `:1045-1065` ⇒ **۱۵ نوعِ ثبت‌شده** (۱۳ نوع زمان‌بندی‌شده + دو نوعِ رویدادمحورِ `sms.send` و `report.export`) |
| **نام‌های موجود در سند ولی ثبت‌نشده در کد** | `appt.expire_pending`، `ocr.recognize`، `cleanup.holds`، `audit.chain_verify`، `temp.cleanup` |
| **نام‌های ناهمسان** | `no_show.detect` ↔ **`visits.no_show`** · `cleanup.idempotency` ↔ **`cleanup.idem`** |
| **انواعِ ثبت‌شدهٔ غایب از سند** | `cleanup.oplog`، `cleanup.rate_limits`، `sms.send` |
| **جزئیاتِ رفتاریِ ناسازگار** | سند برای `slots.generate` «روزانه (۰۲:۰۰)» و «۳۰ روز آینده» ثبت کرده؛ رفتارِ واقعی: `scheduleRecurringJobs()` در **هر tick** هر نوعِ زمان‌بندی‌نشده را با **payloadِ تهی** enqueue می‌کند (`App.php:1114-1128`) و افق از `booking.max_future_days` با پیش‌فرضِ **۶۰** روز می‌آید (`Settings.php:42`) |
| **داوری** | این سند **مرجعِ مفهومی/تاریخی** است و **منبعِ حقیقتِ runtime نیست**؛ هر طبقه‌بندی/allowlistِ Job باید از **کدِ dispatcher** ساخته شود |
| **اثر** | در `background-jobs.md` یک **یادداشتِ drift** (بدونِ بازنویسیِ جدول) و در سندِ طراحی §A-3 «منبعِ حقیقتِ registry» صریح شد |
| **فاز مالک / اقدام** | 🕒 **DEFERRED** — هم‌ترازیِ کاملِ جدولِ §۲ با registry در فازِ مالکِ Jobs (بدونِ حذفِ محتوای تاریخی) |

### ۹-C — 🕒 docblockِ کهنهٔ `ClinicianRepository.php:17`

| | |
|---|---|
| **محل** | `clinic-practice-management/src/Infrastructure/Repository/ClinicianRepository.php:17` |
| **متنِ کهنه** | «همه کوئری‌ها `clinic_id=1` (V1 تک-کلینیک — ADR-0003)» |
| **واقعیتِ راستی‌آزمایی‌شده** | کلاس `int $clinic_id` **صریح** می‌گیرد و کوئری‌ها parameterized‌اند (`listAll():30/39/41`، `create():76/80`)؛ **هیچ literalِ tenant‌ای در این کلاس نیست** — هم‌راستا با Tenant Tripwire (`hardcodes: 0`) |
| **اقدامِ انجام‌شده** | **هیچ** — این تسک فقط‌مستندات است و **فایلِ PHP تغییر نکرد**. این قلم پیش‌تر نیز به‌عنوانِ cleanupِ **فقط‑کامنتیِ** آینده ثبت شده بود (`CHANGELOG.md`، ورودیِ 2026-09-12 «آشتی‌دهیِ ۵۴ تاریخی») و اینجا به‌عنوانِ driftِ مستقلِ قابلِ ردیابی ثبت می‌شود |
| **فاز مالک / اقدام** | 🕒 **DEFERRED** — اصلاحِ یک خطِ کامنت، بدونِ تغییرِ رفتار/تست/اثرِ tripwire |

> **صریح‌سازی:** هیچ‌یک از این سه مورد **نقصِ امنیتیِ جاری** یا **نشتِ cross-clinic** ثبت نمی‌شود.
> به‌طورِ خاص، دربارهٔ `cpms_operational_logs` **هیچ مسیرِ خواندنِ production‌ای وجود ندارد**
> (فقط `OpLogger` می‌نویسد و `OpLogCleanupHandler` بر پایهٔ retention حذف می‌کند) ⇒ **ادعای نشتی
> ثبت نمی‌شود**. این سه ردیف فقط **بدهیِ مستنداتی/کامنتی** هستند.
