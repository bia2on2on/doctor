# C6 — Tenant Hardcode Census (canonical inventory)

**مبنا:** HEAD = `415238769f40eaf447d6d249664aba72cd415572` (پذیرفتهٔ مالک، C5 closed).
**ابزار:** `bin/tenant-tripwire.py` (+ allowlist خالی در زمان census) — خروجی کامل در PR کامنت‌ها.
**تاریخ:** 2026-09-09 — C6-A.

## خلاصهٔ baseline

| دسته | تعداد |
|---|---|
| A) tenant hardcode اجرایی (production) | **68** |
| E) occurrence در کامنت/داک (غیر اجرایی) | 8 |
| D) تست‌ها (fixture — خارج از اسکن production) | جداگانه (tests/) |
| C) literal های غیر tenant (مثلاً `is_primary=1`, `capacity=1`) | در اسکن نیستند (الگوها دقیق‌اند) |
| B) ابزار pilot (bin/pilot-seed, pilot-smoke) | 3 (اجرا در محیط pilot تک‌کلینیکی) |

## معماری context (مصوب — از foundation موجود فاز ۲)

زیرساخت از قبل ساخته شده و تست دارد (`ScopeContextTest`)؛ C6 آن را «مصرف» می‌کند:

```
Boundary (REST/Admin/Job/Command)
   ↓ ScopeContext::set(ClinicScope)  — فقط اگر scope صریح داریم
Application Service  → App::scope()  = ScopeContext صریح → SystemClinicResolver (دقیقاً ۱ کلینیک؛
                                                  صفر/چند = CLINIC_SCOPE_REQUIRED، fail-closed)
   ↓ clinic صریح (پارامتر متد)
Repository (predicate صریح، prepared، indexable)
```

انواع flow و منبع scope:

| Flow | منبع scope |
|---|---|
| REST staff (patients/reports/queue/schedule/…) | `clinic_id` صریح درخواست × verify سمت سرور (Membership فعال C4) → ScopeContext؛ نبود → SystemClinicResolver |
| Booking عمومی بیمار | **derive از Slot** (ردیف slot ستون clinic_id دارد) — رابطهٔ domain سمت سرور، نه ورودی client |
| عملیات بالینی/ویزیت/فایل | derive از رکورد مرجع (appointment/visit/patient) |
| Jobهای background (reminderها) | اسکن due-work بدون predicate کلینیک (سیستمی)؛ هر ردیف clinic خودش را حمل می‌کند |
| dispatch/purge اعلان‌ها (سیستمی) | بدون predicate کلینیک (batch سیستمی روی همهٔ کلینیک‌ها) |
| Public/OTP | `Settings::clinicId()` = configured-clinic semantic (B-15؛ صریح optional context طبق §۹ دستور C6) — نه clinic=1 هاردکد |
| Migration/activation | خارج از بحث (تاریخی) |

ممنوع‌ها (پابرجا از دستور): fallback «اولین کلینیک» جز از طریق SystemClinicResolverِ count=1؛ static state قابل leak بین request/job/test (→ فیکس Settings::$cache استاتیک + flush در App::resetScope).

## Inventory و disposition (هر ۶۸ مورد)

کلاس A = tenant hardcode واقعی. «منبع» = intended context source پس از refactor.

### Admin (۱)
| فایل:خط | الگو | منبع |
|---|---|---|
| Admin/SecretaryQueuePage.php:46 | SQL | App::scope() |

### Application — Booking (۱۴)
| فایل:خط | الگو | منبع |
|---|---|---|
| BookingService.php:87 | availability(1,…) | بدون predicate کلینیک (availability عمومیِ clinician — رابطهٔ domain) |
| BookingService.php:139,164,482,587 | findByClinicianSlot(1,…) | lookup با کلید business (clinician,date,time) → clinic از خود slot |
| BookingService.php:278 | findByMobile(1,…) | clinic از slot در جریان confirm |
| BookingService.php:192,309,510,609,864,874 | 'clinic_id'=>1 (insert appointment/hold/patient) | `$slot['clinic_id']` (سمت سرور) |
| BookingService.php:934 | generateMrn SQL | clinic پارامتری از جریان |
| ScheduleService.php:80,221 | insert schedule/exception | App::scope() (عملیات staff) |

### Application — Clinical/Patients/Visits/Files (۸)
| فایل:خط | الگو | منبع |
|---|---|---|
| ClinicalService.php:883,889 | search(1,…)/notes->search(1,…) | App::scope() |
| MedicalFileService.php:272 | store(…,1,…) | clinic از رکورد patient/visit |
| PatientService.php:139,177,296,182,399 | search/findByMobile/insert/generateMrn | App::scope() |
| VisitService.php:556 | insert visit | clinic از appointment/patient مرجع |

### Application — Jobs (۲)
| فایل:خط | الگو | منبع |
|---|---|---|
| ApptReminderHandler.php:51 | a.clinic_id=1 | حذف predicate (اسکن due سیستمی) + clinic از ردیف appointment |
| FollowUpReminderHandler.php:49 | f.clinic_id=1 | همان |

### Application — Notifications/SMS/Reports/Export (۱۴)
| فایل:خط | الگو | منبع |
|---|---|---|
| SmsService.php:134 | insert sms_messages | پارامتر clinic از caller (scope یا ردیف مرجع) |
| ExportService.php:121 | store(csv,1,…) | App::scope() |
| ExportService.php:245 | purge SQL | App::scope() |
| ReportService.php:197,229,271,325,368,400,432,502,538,602 | SQL×10 | App::scope() (گزارش staff/admin) |

### Infrastructure — Repositories (۲۳)
| فایل:خط | الگو | منبع |
|---|---|---|
| NotificationRepository.php:26 (insert),69,98,119,128,137,146 | SQL/insert | پارامتر clinic صریح متد (staff→scope؛ patient→کلینیک patient) |
| NotificationRepository.php:208,221,236 | dispatch/cancel/purge | حذف predicate (سیستمی؛ cancel از appointment-id دقیق scoped است) |
| ClinicianRepository.php:39,79 | SQL/insert | پارامتر clinic صریح (App::scope() از caller) |
| ClinicalNoteRepository.php:35 | insert default | پارامتر clinic (از visit) |
| FollowUpRepository.php:27 | insert default | پارامتر clinic (از visit) |
| HandwritingRepository.php:27 | insert default | پارامتر clinic (scope) |
| InvoiceRepository.php:28 | insert default | پارامتر clinic (از visit) |
| MedicalFileRepository.php:26 | insert default | پارامتر clinic (از patient) |
| PaymentRepository.php:28 | insert default | پارامتر clinic (از invoice) |
| PrescriptionRepository.php:34 | insert default | پارامتر clinic (از visit) |
| RecommendationRepository.php:26 | insert default | پارامتر clinic (از visit) |
| ServiceRepository.php:52,69 | insert/update | پارامتر clinic (scope) |
| VisitRepository.php:73 | insert default | پارامتر clinic (از appointment) |

### Infrastructure — Audit/Idempotency/Settings (۳)
| فایل:خط | الگو | منبع |
|---|---|---|
| AuditLogger.php:45 | ?int $clinicId = 1 | `= null` + Migration (nullable) برای eventهای system-level؛ callerها scope/entity می‌دهند |
| Idempotency.php:37 | ?int $clinicId = 1 | پارامتر ضروری + Migration: افزودن clinic_id به UNIQUE (tenant-safe key) |
| Settings.php:145 | int $clinicId = 1 | حذف default (اجباری) + cache instance-level (حذف static cache مشترک) |

### bin — ابزار pilot (۳ — کلاس B)
| فایل:خط | منبع |
|---|---|
| pilot-seed.php:52,129 / pilot-smoke.php:115 | resolve داینامیک کلینیکِ تنها (مثل SystemClinicResolver) — pilot تک‌کلینیکی؛ بدون literal |

### کامنت‌ها (۸ — کلاس E؛ با بازنویسی داک پاک می‌شوند)
OtpService:321، PatientIdentityService:23 (نقل قول قاعده)، ClinicScope:12 (نقل قول AD-13)، VisitService:326، ClinicianRepository:17، MembershipRepository:14، NotificationRepository:13، LoginRateLimiter:135.

### خارج از اسکن (تصمیم مستند)
- `src/Migrations/**` — تاریخچهٔ schema/seed (مثلاً 0010 seed سازمان از کلینیک موجود). بازنویسی = بازنویسی تاریخچه. کلاس migration-historical.
- `tests/**` — fixture (کلاس D)؛ ماتریس multi-tenant جدید در C6-F اضافه می‌شود.
- `SystemClinicResolver` (ORDER BY id LIMIT 1 داخل count==1) — sanction شده؛ خودِ مکانیزم fail-closed است.

## باگ‌های مرتبط که census کشف کرد (fix در C6)

1. **Settings::$cache استاتیک مشترک** بین instanceهای clinic مختلف = leak تنظیمات بین tenantها (تک-instance فعلی mask کرده). فیکس: cache instance-level + flush در `App::resetScope()`.
2. **Idempotency UNIQUE بدون clinic** (`u_idem_scope=key,endpoint,wp_user_id,context_id`) — کلید یکسان در دو کلینیک = collision/replay → leak پاسخ. فیکس: Migration 0020 (UNIQUE پنج‌ستونه) + پارامتر clinic از scope/entity.
3. **Jobهای reminder با clinic=1** — در نصب multi-clinic، نوبت‌های کلینیک‌های دیگر هرگز یادآوری نمی‌گرفتند (silent miss). فیکس: حذف predicate.
4. **cancelQueuedForAppointment با clinic=1** — لغو اعلان‌های نوبت کلینیک دیگر بی‌اثر. فیکس: حذف predicate (appointment-id دقیق است).
5. **audit_logs.clinic_id NOT NULL** اما eventهای system-level clinic ندارند → فیکس: Migration (nullable) + resolve صریح.
6. **publishToStaff broadcast نقشِ سراسری** (کشف حین C6-B): `staffUsersWithCapability` با `get_users(role__in)` کار می‌کرد و بدون فیلتر کلینیک به منشی‌های همهٔ کلینیک‌ها اعلان می‌داد (leak در multi-clinic). فیکس (در C6-B): منبع recipient = عضویت فعال C4 (`MembershipRepository::active_member_user_ids_for_clinic` جدید) ∩ همان `has_cap` موجود؛ broadcast بدون عضویت = صفر اعلان (fail-safe). فیکسچر NotificationFlowTest عضویت ساخت.

## وضعیت C6-B (اجراشده)

**فیکس‌ها (۱۵ مورد اجرایی + ۱ الگوی جدید):**

- `NotificationRepository`: `insert/forUser/forPatient/lastIdForUser/lastIdForPatient/unreadCountForUser/unreadCountForPatient` همگی clinic-first صریح؛ `dispatchQueued/cancelQueuedForAppointment/purgeArchived` بدون predicate کلینیک (batch سیستمی؛ appointment-id/created_at دقیق‌اند). باگ‌های ۳ و ۴ census فیکس شدند.
- `NotificationService`: `publishToStaff/publishToPatient/publishToUser/insertNotification` clinic-first؛ `inbox/since/lastId` clinic را از رابطهٔ domain مشتق می‌کنند (بیمار لینک‌شده → `p.clinic_id`؛ staff → `App::scope()`).
- `SmsService.sendEvent(int $clinic_id, …)` — clinic از caller؛ `testSend/testTemplate` از `Settings::clinicId()`.
- `ApptReminderHandler`/`FollowUpReminderHandler`: حذف predicate کلینیک از اسکن due (باگ ۳)؛ `a.clinic_id`/`f.clinic_id` در SELECT؛ sendEvent/publishToPatient/vars با clinic ردیف.
- Callerها: BookingService (cancel-flow publishToPatient + clinic-name ×2 + sendEvent + publish نتیجه) از `$appt['clinic_id']`؛ OtpService از `Settings::clinicId()` (identity-level، AD-15)؛ ExportService از `App::scope()` (publishToUser + listFor)؛ VisitService از `$visit['clinic_id']`.
- تست‌ها: SmsFlowTest×3، NotificationFlowTest×2 — امضاها به‌روز شدند (clinic fixture = 1).
- **الگوی جدید tripwire** (`clinics-table-id-1` — کشف حین C6-B): `cpms_clinics … WHERE id = 1` — ۴ مورد: سه‌تا در همین batch فیکس شدند (BookingService:984، هر دو handler)، ClinicalService:696 → C6-D.
- شمارش tripwire بعد از C6-B: **57 violation** (56 از الگوهای اصلی [68−12 فیکس C6-B] + 1 الگوی جدید ClinicalService).
- باگ ۶ census (broadcast سراسری) هم در همین batch فیکس شد: `NotificationService::staffUsersWithCapability` اکنون clinic-first است؛ ctor سرویس `MembershipRepository` گرفت و `App::notificationService()` wiring شد.
- **فیکس Class C بعد از CI** (کامیت `fb269ab`): rename جاافتادهٔ `linkedPatientId` در `markRead` (خطای undefined-method در Integration 34341027517) + دو caller قدیمی در `bin/pilot-smoke.php` (S5 + Pilot Gate SMS).
- **سبز C6-B = HEAD `fb269ab`** (هر ۵ گیت): CI `34341646430` · Real-WP `34341646541` + `34341640968` · Pilot `34341640995` · Closure `34341641000`. (والد `711f870` → CI `34341027517` شکست Class C؛ بقیهٔ گیت‌های آن superseded.)

## Batch plan (اجرای اتمیک)

- **C6-A** (کامیت `17d7d10`): census + tripwire (لوکال) + allowlist خالی. CI wiring در C6-F وقتی production=0.
- **C6-B** ✅: Notifications+SMS+Jobs (repo/service/handlers) — جزئیات زیر.
- **C6-C** ✅: Booking+Schedule — جزئیات در «وضعیت C6-C».
- **C6-D** ✅: Patients+Clinical+Visits+Files — جزئیات در «وضعیت C6-D».

## وضعیت C6-C (اجراشده)

منبع clinic در همهٔ جریان‌ها relation دامنه است (نه scope نه client):

- `requireClinician` (Booking+Schedule) اکنون `clinic_id` پزشک را برمی‌گرداند — منبع واحد جریان‌های availability/quote/hold/reschedule/createByStaff/listForClinician/create-schedule/create-exception.
- hold: clinic از ردیف slot (`slot_holds.clinic_id` = clinic اسلات)؛ confirm: patient lookup/minimal-patient با clinic خود hold، appointment با clinic اسلات.
- reschedule: appointment جدید با clinic اسلات مقصد؛ createByStaff: appointment با clinic اسلات.
- `createMinimalPatient(clinic_id,…)` و `generateMrn(clinic_id)` — MRN یکتا در کلینیک بیمار.
- **verify سمت سرور جدید**: createByStaff اگر بیمار به کلینیک دیگری تعلق داشته باشد → `CLINIC_VALIDATION_FAILED` 422 (جلوگیری از cross-clinic patient/slot mix).
- شمارش tripwire بعد از C6-C: **42 violation** (۴۴→۴۲؛ ۱۳ مورد Booking + ۲ مورد Schedule فیکس شدند). SecretaryQueuePage:46 طبق plan به C6-E (Admin) منتقل شد.

## وضعیت C6-D (اجراشده)

- **PatientService** (staff flows): `search/create/validateForUpdate/generateMrn` از `App::scope()->clinicId` (mobile-dup و MRN یکتا در کلینیک scope)؛ `get/update` scope-verify (بیمار کلینیک دیگر = 404 anti-enum). `me/updateMe` (بیمار، identity-level از links) دست‌نخورده.
- **ClinicalService**: clinic-info summary از `$visit['clinic_id']` (فیکس الگوی جدید clinics-table-id-1)؛ unified search (patients/notes/prescriptions) از scope.
- **MedicalFileService**: clinic فایل از ردیف بیمار + existence-verify بیمار در upload (staff آپلود برای بیمار ناموجود → 404).
- **VisitService**: `createVisit(clinic_id صریح)`؛ checkIn از clinic نوبت؛ walk-in از کلینیک پزشک + **verify سمت سرور** تطبیق کلینیک بیمار/پزشک (422)؛ `requireClinician` کلینیک برمی‌گرداند.
- شمارش tripwire بعد از C6-D: **32 violation** (۴۲→۳۲؛ ۱۰ مورد فیکس).
- نکتهٔ بازیابی: کامیت اولیهٔ C6-D به‌دلیل بازسازی sandbox از git لوکال حذف شد؛ working tree دست‌نخورده ماند و همین کامیت بازسازی همان تغییرات است (history سرور از `0d8d9d1` پیوسته).
- **فیکس‌های Class C/D بعد از CI**: (۱) caller جاافتادهٔ `createVisit` در checkIn (ArgumentCountError — CI 34347272618) → کامیت `e23e626`؛ (۲) Closure Gate فلک زیرساختی (curl exit 35 هنگام دانلود wp-cli، قبل از هر step ساختی) → retrigger با کامیت خالی `6dcee7d` (tree یکسان `0a5bd4a`).
- **سبز C6-D = `6dcee7d`** (هر ۵ گیت): CI `34348322104` · Real-WP `34348315960` + `34348322007` · Pilot `34348315942` · Closure `34348315964`. (`346849f` → CI شکست Class C؛ `e23e626` → ۴/۵ سبز + Closure فلک Class D.)

- **C6-E**: Reports+Export+Admin + Infra (Audit/Idempotency/Settings + Migrationها 0020/0021) + REST boundary (resolveScope×membership).

## وضعیت C6-E1 — Infra (اجراشده)

- **Settings** (باگ ۱ census): cache استاتیک مشترک → per-clinic keyed؛ ctor بدون `= 1` (تنها construction-site یعنی `App::settings()` از `App::scope()->clinicId` پاس می‌دهد)؛ `App::resetScope()` اکنون `Settings::flushCache()` + بازسازی instance را هم انجام می‌دهد.
- **AuditLogger** (باگ ۵ — بخش migration آن از قبل در 0016 nullable شده بود): `?int $clinicId = 1` → `null` + resolution نرم: scope فعال درخواست؛ Scope مبهم/ناموجود → NULL ثبت می‌شود (event سیستمی). ۷۵+ caller بدون تغییرِ امضا scope-correct شدند.
- **Idempotency** (باگ ۲ census): `check/complete/release/find` همگی clinic در دامنه (پارامتر اجباری)؛ **Migration 0020**: `u_idem_scope` چهارستونه → پنج‌ستونه (+clinic_id) با Preflight تکراری + backfill امنِ clinic-1 برای ردیف‌های legacy (فقط در نصب تک‌کلینیکی) + down() کامل.
- Callers: BookingService confirm (hold قبل از claim خوانده می‌شود — clinic خود hold؛ ضمناً 404 برای token نامعتبر دیگر claim نمی‌سازد) و reschedule (pre-fetch نوبت)؛ HandwritingService (clinic از visit)؛ IdempotencyTest امضاها.
- Checklist نسخه: MigrationTest/Phase2SchemaTest/TempTableIsolationTest/closure-gate/pilot-gate/real-wp-acceptance → 0020.
- **C6-F**: tripwire→CI + MultiTenantIsolationTest (ماتریس ۱۴بندی).
- **C6-G**: docs + state.
