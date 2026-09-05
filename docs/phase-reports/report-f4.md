# گزارش فاز F4 — مراجعه/صف (Check-in/Walk-in + Queue SM/History + Real-time Polling + داشبورد منشی)

تاریخ: 2026-09-05 | وضعیت: **کامل — CI سبز** | محیط: PHP 8.1–8.4 / MySQL 8 / WordPress 6.7.2

> فازبندی مطابق `docs/roadmap/roadmap.md` (منبع حقیقت): F4 = مراجعه/صف؛ مالی (D12–D18) = F6.
> گزارش F3 §6 پیش‌تر به‌اشتباه «F4 = مالی/ویزیت» خوانده بود — اصلاح doc-sync شد (commit `5d46a11`).

## 1. خلاصه

فاز F4 جریان حضور بیمار در کلینیک را از «نوبت» تا «خروج» کامل کرد:

- **Check-in/Walk-in** (D6/D7): ساخت Visit با Enqueue خودکار (FR-6.1، تنظیم `queue.auto_enqueue`)، رابطه دوطرفه D-6 (`appointments.active_visit_id`)، و مسیر ER-06 دیرهنگام (نوبت past-grace → `no_show` + Visit فوری Walk-in-like با حفظ ارجاع).
- **Queue State Machine + History**: تمام V1–V15 ماشین `VisitMachine` (F1) در سرویس اجرا می‌شود — هر Transition با Row Lock (J-1) داخل Transaction و ردیف append-only در `cpms_visit_status_history` (J-3) که همزمان Feed رئال‌تایم R1 است.
- **Real-time Polling** (R1 — ADR-0007): `GET /rt/queue?since=` با ETag/304، Rate limit per-user (60/min)، محدود به رویدادهای امروز؛ UI با Page Visibility توقف خودکار.
- **داشبورد منشی** (roadmap: امروز/Drawer/Walk-in/Keyboard): صفحه WP-Admin با آمار زنده، صف FIFO، Drawer گردش کار (تاریخچه از feed R1 بدون endpoint جدید)، جستجوی بیمار (D2) برای Walk-in/Check-in (نوبت‌های امروز از D9)، اکشن‌های مجاز منشی (D8/D16) و میانبرهای W/C/R//Esc.
- **No-show خودکار** (FR-5.5): sweep داخلی lazy در Check-in + جاب تکرارشونده `visits.no_show` (هر دقیقه)؛ فقط نوبت‌های `confirmed` بدون Visit فعال و پس از `queue.no_show_grace_minutes` (پیش‌فرض 30).

تست‌های نقشه راه همه سبز: **TP-19** (جریان no-show/دیرهنگام)، **TP-03b** (دو complete هم‌زمان: fork موازی DB-level + سرویس + Row-Lock)، **TP-07** (IDOR بخش ویزیت/صف).

**تکمیلات پس از تأیید اولیه (دستور ۳۴-بندی کارفرما):** ممیزی کامل PR #2 (Agent دوم — شاخه موازی، §5)؛ پیاده‌سازی **سیاست لایسنس §18** روی صف/مراجعه (`LicenseGate` تزریقی در `VisitService` — Walk-in مستقل در Read-Only = `CLINIC_LICENSE_BLOCKED/503`؛ Check-in نوبت از پیش موجود و Transitionهای ویزیت در جریان = مجاز) با تست تزریق Gate (`VisitLicenseGateTest`)؛ غنی‌سازی آمار داشبورد امروز (`appointments_today/appointments_no_show/walk_in_today`)؛ حذف PHI غیرضروری از SELECT صف؛ Fallback `Throwable` در guard صف (بند ۵ §5).

## 2. ماتریس Acceptance Criteria

| # | معیار | وضعیت | شاهد |
|---|---|---|---|
| 1 | D1 `GET /secretary/today` (داشبورد + آمار) | ✅ | QueueController + RestQueueTest |
| 2 | D6 `POST /visits/checkin` | ✅ | VisitService::checkIn + تست‌ها |
| 3 | D7 `POST /visits/walk-in` (clinician_id الزامی — GAP-1/G-3) | ✅ | VisitService::walkIn + تست‌ها |
| 4 | D8 `POST /visits/{id}/status` (نگاشت to_status→event منشی) | ✅ | SECRETARY_STATUS_EVENTS |
| 5 | D16 `POST /visits/{id}/checkout` (+waive) | ✅ | VisitService::checkout |
| 6 | E1 `GET /doctor/today` / E2 `GET /queue` | ✅ | QueueController |
| 7 | E3–E6 call/recall/start/skip | ✅ | doctorAction + cap متمایز |
| 8 | R1 `GET /rt/queue` + ETag/304 + Rate limit | ✅ | rtQueue + RestQueueTest |
| 9 | Enqueue خودکار (FR-6.1) + `queue.*` settings | ✅ | createVisit/applyEnqueue |
| 10 | J-1 یکتایی V10 با Row Lock | ✅ | TP-03b (۳ لایه) |
| 11 | J-3 History append-only | ✅ | هر transition → insertHistory |
| 12 | J-4 ترتیب صف (فوری اول، سپس FIFO) | ✅ | queueFor ORDER BY express, waiting_since |
| 13 | J-5 ویزیت فعال واحد (بیمار×پزشک×روز) | ✅ | guardDuplicateActiveVisit + lock بیمار |
| 14 | J-6 سقف recall از `queue.max_recalls` | ✅ | patchForEvent |
| 15 | FR-5.5 no-show خودکار (تنظیم‌پذیر، فقط بدون Visit فعال) | ✅ | processNoShows + VisitsNoShowHandler |
| 16 | ER-06 دیرهنگام: no_show + Visit فوری Walk-in-like با ارجاع | ✅ | TP-19 در VisitFlowTest |
| 17 | T9 خروج → نوبت مرجع completed (check_out و waive) | ✅ | completeReferencedAppointment |
| 18 | TP-19 | ✅ | VisitFlowTest (۴ تست no-show/دیرهنگام) |
| 19 | TP-03b | ✅ | VisitConcurrencyTest (fork+service+lock) |
| 20 | TP-07 (بخش Visit) | ✅ | RestQueueTest::testPatientCannotSeeQueueOfOthers |
| 21 | داشبورد منشی UI (امروز/Drawer/Walk-in/Keyboard) | ✅ | SecretaryQueuePage |

## 3. تغییرات کلیدی (اختلاف با F3)

- `src/Application/Visits/VisitService.php` (جدید) — قلب فاز: Check-in/Walk-in/Transitionها/Checkout/Sweep با تضمین‌های J-1..J-6 و ER-06/FR-5.5/T9.
- `src/Infrastructure/Repository/VisitRepository.php` (جدید — ADR-0021) — فقط Data-Access؛ صف/آمار/feed با ایندکس‌های موجود `idx_visit_day/queue/patient`؛ **بدون تغییر Schema**.
- `src/Rest/QueueController.php` (جدید) — D1/D6/D7/D8/D16 + E1–E6 + E14 + R1 با Nonce+Capability و envelope `CLINIC_*`.
- `src/Admin/SecretaryQueuePage.php` (جدید) — داشبورد منشی (UI).
- `src/Application/Jobs/VisitsNoShowHandler.php` (جدید) + ثبت `visits.no_show` در Dispatcher/RECURRING_JOBS.
- `src/Rest/RestBase.php` — رفع تداخل کلید `status` در envelope خطا (§4 بند ۱).
- `src/Bootstrap/App.php` — Container `visitService()`، ثبت Controller/Menu/Job + رفع باگ one-shot جاب‌های تکرارشونده (§4 بند ۴).
- `docs/api/error-codes.md` — `+CLINIC_INVALID_APPOINTMENT_STATE`, `+CLINIC_RECALL_LIMIT_REACHED`.

### 3.1 کامیتهای F4 (شاخه `arena/01a071c4-doctor`)

| Commit | شرح |
|---|---|
| `0e77f6a` | feat(visits): هسته F4 — سرویس/ریپو/REST/no-show job + تست‌ها |
| `5d46a11` | docs(f3): اصلاح §6 — F4=صف/مراجعه طبق roadmap (F6=مالی) |
| `b5c1eb7` | fix(visits): دور اول CI — envelope/E14/T9/waive/presenters |
| `a79f5c3` | feat(admin): داشبورد صف منشی (امروز/Drawer/Walk-in/Keyboard) |
| `39b8d7b` | fix(jobs): جاب‌های تکرارشونده یک‌باره بودند — زمان‌بندی Idempotent در هر tick |
| `9078ef0` + `349d557` | fix: typo FQCN |
| `4eb8eaa` | feat(visits): LicenseGate §18 + اتخاذ یافته‌های Audit PR #2 (§5) |

**زنجیره CI:** run 33985879588 (5d46a11، قرمز — 6F/2E همه تشخیص و ریشه‌یابی شد) → **33986638561 (b5c1eb7 ✓)** → **33986863341 (a79f5c3 ✓)** → 33986951258 (39b8d7b، قرمز — typo FQCN در تست) → 33987173206 (9078ef0، قرمز — همان typo در App) → **33987189105 (349d557 ✓ هر ۵ job: Unit 8.1–8.4 + Integration WP 6.7/MySQL 8 — ۱۳۳ تست/۵۷۵ assertion)** → **33987457187 (73390d3 ✓ = HEAD نهایی، docs-only)**. PR #1 rollup: ۵/۵ SUCCESS.

## 4. تصمیمات مهندسی درون‌فازی (بدون تغییر Scope)

1. **Envelope خطا — کلید `status` رزرو شد:** `RestBase::error()` با `array_merge` اجازه می‌داد Data خطا (مثلاً `status: 'waiting'`) روی HTTP Status بنشیند (پاسخ 0/نامعتبر). ترتیب merge برعکس شد تا Status رسمی Envelope همیشه اولویت داشته باشد؛ Dataهای سرویس به `visit_status/appointment_status` تغییر کلید دادند. (باگ واقعی که CI دور اول آشکار کرد.)
2. **E14 (`POST /visits/{id}/complete`) در F4 ثبت شد:** D16 (Checkout — قرارداد F4) بدون مسیر رسیدن به `consultation_completed` غیرقابل استفاده بود. Validation فیلدهای بالینی و E15 (Reopen/Correction) طبق roadmap در **F5** می‌ماند. سرویس منطق V10/V15 را از حالا کامل دارد (برای TP-03b).
3. **T9 در waive هم:** خروج با معافیت هم مسیر نهایی نوبت است — نوبت مرجع در هر دو مسیر رسیدن به `checked_out` بسته می‌شود. نوبت‌های Terminal دیگر (مثل `no_show` دیرهنگام) فقط رابطه را آزاد می‌کنند تا تاریخچه وضعیت نوبت حفظ شود.
4. **رفع باگ زیرساختی جاب‌های تکرارشونده (میراث F1):** `scheduleRecurringJobs()` فقط در Activate اجرا می‌شد و Dispatcher دوباره زمان‌بندی نمی‌کرد — `holds.expire`/`slots.generate` عملاً یک‌باره بودند. حالا `cpms_jobs_tick` پیش از Dispatch دوباره (Idempotent، بدون نسخه Queued تکراری) زمان‌بندی می‌کند؛ برای FR-5.5 حیاتی بود. تست چرخه (زمان‌بندی→dedup→اجرا→زمان‌بندی) اضافه شد.
5. **اولویت صف (J-4) بدون تغییر Schema:** ستون `priority` روی Visit وجود ندارد (ERD) — نوبت فوری از `appointments.is_walkin_express` (F3، D10) در همان Query صف مشتق می‌شود: `ORDER BY (express) DESC, waiting_since ASC`. سوال باز فاز (ستون جدید؟) با این مشتق‌سازی حل شد.
6. **No-show فقط روی `confirmed`:** ماشین Appointment ترنزیشن `PENDING → no_show` ندارد (فقط T8 از CONFIRMED) — نوبت‌های PENDING مسیر انقضای خودشان (Hold/Cron F3) را دارند. Check-in روی نوبت PENDING آن را از طریق T3 (`confirm`) نهایی می‌کند تا مسیر T9 کامل شود.
7. **Feed R1 محدود به امروز:** فید «صف امروز» است نه تاریخچه — JOIN روی `visit_date = امروز`؛ به UI اجازه می‌دهد Drawer گردش کار را از تجمع رویدادها بسازد **بدون endpoint جدید** (قانون §56: endpoint فقط با API Contract).
8. **Race-safety جاب/چک‌این:** sweep داخل Row Lock نوبت دوباره وضعیت را چک می‌کند (`active_visit_id` ست شده → skip)؛ Check-in با قفل رکورد بیمار serialize می‌شود (J-5) و هر دو مسیر با ماشین سازگار می‌مانند.
9. **داشبورد منشی — بدون endpoint جدید:** لیست پزشکان server-side رندر شد؛ جستجوی بیمار از D2، نوبت‌های امروز از D9 (هر دو موجود از F3). هیچ PHI در HTML اولیه نیست — همه داده‌ها از REST با همان Authorization لایه‌بندی‌شده.

## 5. Audit PR #2 (Agent دوم — شاخه موازی `arena/01a07281-doctor` @ b9467da) و ادغام ایده‌های خوب

کارفرما Audit کامل PR #2 را به‌عنوان بخش تکمیلی F4 واگذار کرد. یافته‌ها:

### 5.1 وضعیت شاخه

- Base = `210d437` (main اولیه) — **بدون هیچ fix فاز F3** (زنجیره 0e77f6a…349d557 در آن نیست).
- ۲ کامیت: `7b7131a` (فقط `AGENTS.md` ریشه ۳۰۳ خطی) + `b9467da` (کد F4 اولیه: `VisitService` ۲۲۵ خط، `VisitRepository` ۱۴۲، `VisitController` ۱۲۵، wiring در `App.php`، گزارش f4 اولیه).
- همپوشانی کامل با F4 تکمیل‌شده این شاخه (PR #1) — به‌صورت مجموعه‌ای ناقص‌تر.

### 5.2 باگ‌های واقعی در کد PR #2 (دلایل عدم merge)

1. **permission_callback معیوب:** الگوی `requireCap(...) === null` — متد bool|WP_Error برمی‌گرداند و هرگز null نیست → هر ۴ endpoint همیشه 403.
2. **نگاشت نقش سرور غلط:** doctor→`doctor`، بقیه همه (حتی patient)→`secretary` از WP roles — نقض مدل نقش افزونه.
3. **نقض قرارداد duplicate visit:** بازگرداندن existing به‌جای خطای `409 CLINIC_DUPLICATE_ACTIVE_VISIT` (قرارداد §10).
4. **Race در Walk-in:** بدون قفل رکورد بیمار → double-submit = دو ویزیت؛ recall هاردکد 3 (نابل به تنظیم)؛ enqueue بدون machineCheck؛ بدون args schema در REST؛ **بدون هیچ تستی**؛ R1/D1/D16/E1–E6/no-show/ER-06/T9/express پوشش داده نشده بودند.

**تصمیم:** merge نشد (base غلط + همپوشان + ناقص)؛ PR با کامنت Audit بسته شد (شاخه حفظ شد). بخش‌های Correct/Secure/Architecturally-compatible حفظ و در شاخه اصلی اتخاذ شد.

### 5.3 ایده‌های خوب اتخاذشده از PR #2

1. **Fallback `catch (Throwable)` → `CLINIC_INTERNAL_ERROR/500`** در guard صف (`QueueController::guard`) — Envelope استاندارد بدون نشت جزئیات داخلی؛ کلاس Exception فقط در error_log سرور.
2. **لاگ‌های صادقانه کار ایجنت** — دو ورودی لاگ Agent-2 (بازبینی اولیه + F4 slice 1) با نسبت‌دهی به `docs/agent-guide.md §10` منتقل شد؛ `AGENTS.md` ریشه به فایل پوینتر کوتاه به راهنمای واحد تبدیل شد.
3. **اشاره صریح F10 در فازها** — الگوی ذکر فازهای وابسته (مثل Licensing) در مستندات فاز.

### 5.4 تغییرات تکمیلی این بخش (شاخه اصلی)

| فایل | تغییر |
|---|---|
| `VisitService.php` | پارامتر ششم `LicenseGate` + `assertLicense()` (الگوی BookingService، بدون op-log؛ **بدون Network Call** — قرارداد ADR-0023)؛ `walkIn()` → `assertLicense(OP_VISIT_CHECKIN)` |
| `App.php` | wiring `self::licenseGate()` در `visitService()` |
| `VisitRepository.php` | `statsFor`: `+appointments_today/appointments_no_show/walk_in_today`؛ `queueFor`: حذف `patient_mobile/patient_national_id` از SELECT (PHI غیرضروری — مجوز Field-Access لایه ۴) |
| `QueueController.php` | `catch (Throwable)` → `CLINIC_INTERNAL_ERROR/500` |
| `tests/Integration/VisitLicenseGateTest.php` | ۵ تست: Walk-in در Read-Only → 503 + بدون نوشتن؛ کنترل Walk-in با Gate فعال؛ Check-in نوبت موجود در Read-Only مجاز؛ Transitionهای ویزیت در جریان (call/start/complete) در Read-Only مجاز |

## 6. موارد باز / نیازمند تصمیم کارفرما (پیش از F5)

1. ~~Availability UI~~ — تصمیم قبلی: فاز UI مستقل پس از فازهای Backend (مطبق تأیید ورود F4).
2. ~~Drift ماتریس Capability (49 ثابت/46 ماتریس)~~ — **دستور F5 کارفرما:** پیش از توسعه endpoints بالینی، drift طبق Permission Matrix و Least Privilege بسته شود (Technical Alignment؛ در صورت نیاز به تغییر واقعی Permission Model → STOP Policy).
3. **PHPStan** — طبق دستور F5: Blocker نیست؛ در صورت امکان بدون اختلاف اضافه شود ولی توسعه بالینی متوقف نشود.
4. **`SmsController::can()`** — بدهی F2.5 (F8/patch).
5. **UI پزشک (E1 dashboard/Call):** بخشی از F5 (داشبورد پزشک) — Backend E1/E2/E3 از همین فاز آماده است.

## 7. گام بعدی (F5 — بالینی؛ تأیید ورود دریافت شد)

- صفحه ویزیت، Notes+Versions (E8/E9)، Prescriptions (E10/E11)، Recommendations (E12)، Follow-ups (E13)، E7 پرونده کامل، **E15 Reopen/Correction**، File Upload/Stream (E16/E17)، جستجوی جامع (E18)، داشبورد پزشک (E1 UI + Call flow)
- Validation فیلدهای بالینی روی E14 (Chief Complaint پیش‌فرض — FR-8.x)
- تست‌های نقشه راه: TP-06، TP-08، TP-10
