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

**زنجیره CI:** run 33985879588 (5d46a11، قرمز — 6F/2E همه تشخیص و ریشه‌یابی شد) → **33986638561 (b5c1eb7 ✓)** → **33986863341 (a79f5c3 ✓)** → 33986951258 (39b8d7b، قرمز — typo FQCN در تست) → 33987173206 (9078ef0، قرمز — همان typo در App) → **33987189105 (349d557 ✓ هر ۵ job: Unit 8.1–8.4 + Integration WP 6.7/MySQL 8 — ۱۳۳ تست/۵۷۵ assertion)**. PR #1 rollup: ۵/۵ SUCCESS.

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

## 5. موارد باز / نیازمند تصمیم کارفرما (پیش از F5)

1. ~~Availability UI~~ — تصمیم قبلی: فاز UI مستقل پس از فازهای Backend (مطبق تأیید ورود F4).
2. ~~Drift ماتریس Capability (49 ثابت/46 ماتریس)~~ — طبق تأیید، بازبینی در همین فاز انجام نشد و به‌عنوان تصمیم باز باقی است؛ پیشنهاد: در F5 قبل از endpoints بالینی بسته شود (E7–E13 به capهای دقیق حساس‌اند).
3. **PHPStan** — همان توصیه F3: فاز بعدی با baseline و وابستگی composer.
4. **`SmsController::can()`** — بدهی F2.5 (F8/patch).
5. **UI پزشک (E1 dashboard/Call):** roadmap آن را در F5 (داشبورد پزشک) می‌خواهد — Backend E1/E2/E3 از همین فاز آماده است.

## 6. گام بعدی (F5 — بالینی؛ پس از تأیید این گزارش)

- صفحه ویزیت، Notes+Versions (E8/E9)، Prescriptions (E10/E11)، Recommendations (E12)، Follow-ups (E13)، E7 پرونده کامل، **E15 Reopen/Correction**، File Upload/Stream (E16/E17)، جستجوی جامع (E18)، داشبورد پزشک (E1 UI + Call flow)
- Validation فیلدهای بالینی روی E14 (Chief Complaint پیش‌فرض — FR-8.x)
- تست‌های نقشه راه: TP-06، TP-08، TP-10
