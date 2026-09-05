# گزارش فاز F5 — بالینی (Notes/نسخه/توصیه/پیگیری + Complete/Reopen + نمای بیمار + فایل‌های محافظت‌شده + جستجو + UI پزشک + بستن Capability Drift)

تاریخ: 2026-09-06 | وضعیت: **کامل — CI سبز** | محیط: PHP 8.1–8.4 / MySQL 8 / WordPress 6.7.2

> فازبندی مطابق `docs/roadmap/roadmap.md`: F5 = صفحه ویزیت + E7–E18 + C3–C7 + داشبورد پزشک (UI + Call flow) + بستن Drift ماتریس Capability (دستور صریح کارفرما) پیش از توسعه endpointهای بالینی.

## 1. خلاصه

فاز F5 هسته بالینی سیستم را کامل کرد:

- **Capability Drift بسته شد (پیش‌نیاز فاز — دستور کارفرما):** ماتریس §4 مبنا؛ ۳ قلم غیرماتریسی حذف؛ مفرغ‌های `files.*`/`search` مطابق ماتریس؛ **باگ واقعی production در `registerRole`** کشف و اصلاح شد (§3).
- **E7 پرونده کامل** — فقط پزشک (`cpms_medical_read`)، همه بخش‌ها + Audit.
- **E8/E9 Notes + نسخه‌بندی append-only** (FR-8.5/K-6) با Capability تفکیک‌شده private (`cpms_private_note_*` — P-6) و **TP-08 در سطح Query**.
- **E10/E11 نسخه دارویی** Draft→Finalize (+Void سرویسی) با «ویزیت خودش» (ماتریس 4.3).
- **E12/E13 توصیه/پیگیری**، **E14 پایان با Validation شکایت اصلی** (FR-8.7 — `clinical.require_chief_complaint`)، **E15 بازگشایی** با دلیل (FR-8.8).
- **C5/C6/C7 نمای بیمار** — Ownership (P-5/P-8) بدون Capability؛ فقط `patient_visible` و غیر-Draft.
- **E16/E17/C3/C4 فایل‌های پزشکی محافظت‌شده** (TP-06/F-1..F-5): خارج از uploads، نام تصادفی، MIME واقعی از محتوا، RateLimit، Stream مجوزیافته.
- **E18 جستجوی جامع Role-Aware** (`cpms_search`) — منشی فقط بیمار، پزشک همه؛ Snippet بدون متن کامل؛ Audit هر جستجو.
- **UI پزشک:** `DoctorDashboardPage` — صف زنده پزشک متصل (Call/Recall/Start) + صفحه ویزیت One-Page (یادداشت/نسخه/توصیه/پیگیری/فایل/پایان/بازگشایی). دست‌خط Canvas و تاریخ Jalali جزو F6 (ADR-0014) هستند و عمداً در این فاز نیستند.

## 2. ماتریس Acceptance Criteria

| # | معیار | وضعیت | شاهد |
|---|---|---|---|
| 1 | Drift Capability بسته شود پیش از endpointهای بالینی (دستور کارفرما) | ✅ | §3 + PermissionMatrixTest (۱۰ تست) |
| 2 | E7 `GET /visits/{id}/record` (`cpms_medical_read`، فقط پزشک) | ✅ | ClinicalService::record + تست (منشی 403) |
| 3 | E8 `POST /visits/{id}/notes` (category/visibility) | ✅ | addNote + ۸ دسته enum |
| 4 | E9 `PUT /notes/{id}` Correction با change_reason الزامی + Snapshot | ✅ | updateNote + K-6 تست نسخه‌ها |
| 5 | ویرایش یادداشت فقط نویسنده (ماتریس 4.3) + IDOR→404+Audit | ✅ | pre-check خارج تراکنش + تست |
| 6 | TP-08: Patient/Secretary هرگز doctor_private نمی‌بینند (Query-level) | ✅ | `ClinicalNoteRepository` فیلتر visibility در WHERE + تست سطح سرویس و REST |
| 7 | E10 `POST /visits/{id}/prescriptions` Draft (+«ویزیت خودش») | ✅ | createPrescription + requireOwnVisit |
| 8 | E11 `POST /prescriptions/{id}/finalize` (تکرار 409) | ✅ | finalizePrescription + تست |
| 9 | E12/E13 توصیه/پیگیری با Validation (تاریخ یا بازه) | ✅ | addRecommendations/addFollowUp |
| 10 | E14 Complete با Validation FR-8.7 (تنظیم‌پذیر) | ✅ | completeConsultation + تست 422/موفق |
| 11 | E15 Reopen با دلیل + Audit | ✅ | reopenConsultation + تست |
| 12 | C5/C6/C7 نمای بیمار فقط patient_visible + Ownership + IDOR | ✅ | patientVisits/Detail/Prescriptions + تست‌ها |
| 13 | E16 `POST /files` کارکنان (cap `cpms_file_upload`) | ✅ | MedicalFileService::upload |
| 14 | E17 `GET /files/{id}/stream` باینری + ماتریس دسترسی + Audit حساس | ✅ | stream + تست (بیمار/پزشک/منشی/ناشناس) |
| 15 | C3/C4 آپلود/لیست فایل بیمار (Ownership، همیشه patient_visible) | ✅ | patientUpload/patientFiles + REST تست |
| 16 | TP-06: خارج uploads + گاردهای سرور + نام تصادفی | ✅ | LocalFileStorage + تست گاردها |
| 17 | F-3: MIME واقعی (finfo) + Whitelist + سقف حجم → `CLINIC_FILE_INVALID` بدون ذخیره | ✅ | sniffMime + تست polyglot/mismatch/size |
| 18 | RateLimit آپلود 10/hr (هر دو مسیر) | ✅ | FilesController::guardUploadRate |
| 19 | E18 `GET /search` Role-Aware (`cpms_search`) | ✅ | globalSearch + ۳ تست |
| 20 | Audit رویدادهای F5 (FR-21.1) | ✅ | ۱۵ Action code (§5) |
| 21 | داشبورد پزشک UI + Call flow (E1/E3–E5) | ✅ | DoctorDashboardPage |
| 22 | صفحه ویزیت One-Page (E7–E15/E16 در UI) | ✅ | DoctorDashboardPage (visit view) |
| 23 | TP-10 (Permission Matrix) سبز | ✅ | PermissionMatrixTest ۱۰ تست |
| 24 | F3/F4 Regression سبز | ✅ | CI سبز (با adaptation مستند §7.3) |
| 25 | Documentation sync | ✅ | §8 |

## 3. Capability Drift — تحلیل، تصمیم و باگ کشف‌شده

**دستور:** drift ۴۹ ثابت کد / ۴۶ ماتریس طبق Permission Matrix، ADRها و Least Privilege بسته شود؛ ماتریس مبنا؛ اگر نیازمند تغییر واقعی Permission Model/Product Decision بود → STOP. **نباید → چنین تغییری لازم نشد؛ Technical Alignment کافی بود.**

### 3.1 تصمیم تعارض §3 (فهرست Capability) ↔ §4.3 (ماتریس Endpoint)

`permission-matrix.md` §3 فهرست تعریفی capabilityها را می‌دهد و §4.3 ماتریس نقش×endpoint را. سه قلم فقط در §3 تعریف شده بودند و در هیچ ردیف §4 نقشی آن‌ها را نداشت (بدون Use Case واقعی): `cpms_slot_manage` (جریان F3 اسلات‌ها را با `cpms_config` مدیریت می‌کند)، `cpms_queue_manage` (صف با `cpms_queue_call/advance/checkin` اداره می‌شود)، `cpms_report_read` (گزارش‌ها فاز F9). **تصمیم (Least Privilege + مبنای ماتریس):** حذف سه قلم از کد؛ ماتریس سند مبنا ماند و اصلاح نشد (ماتریس از قبل درست بود — درِ drift سمت کد بود). تست PermissionMatrixTest مطابق ماتریس بازنویسی/تثبیت شد.

### 3.2 مفرغ‌های واقعی (Use Case موجود)

`cpms_file_upload/cpms_file_read` (E16/E17/C3/C4) و `cpms_search` (E18) در ماتریس §4 هستند و نقش‌های مجاز در آن تعیین شده (منشی: file upload/read محدود به patient_visible؛ search؛ پزشک: همه) — پیاده‌سازی دقیقاً مطابق ماتریس.

### 3.3 باگ واقعی production: `registerRole` (کشف با تست، نه تست‌ساخته)

حلقه sync نقش‌ها فقط روی `ALL_CAPS` می‌چرخید؛ هر capability با پیشوند `cpms_*` که **خارج** از آن فهرست بود، هنگام ثبت/به‌روزرسانی نقش هرگز حذف نمی‌شد — یعنی حذف یک cap از تعریف نقش، نقش‌های موجود را اصلاح نمی‌کرد (نقض پایدار Least Privilege در upgradeها). تست `testRegisterRemovesStrayCapabilityFromRole` این را گرفت. **Fix (998ee81):** حلقه دوم — هر cap `cpms_*` خارج از فهرست مجاز نقش → remove. خودشفاکننده برای upgradeهای آینده.

### 3.4 تصحیح ادعای قبلی «drift صفر»

گزارش‌های قبلی (قبل از دستور F5) ادعای «drift صفر» داشتند؛ بررسی واقعی نشان داد ۳ قلم اضافه + مفرغ‌ها + باگ registerRole. این گزارش جایگزین آن ادعاست.

## 4. معماری و فایل‌های کلیدی

| فایل | نقش |
|---|---|
| `src/Application/Clinical/ClinicalService.php` | قلب فاز: E7–E15 + C5–C7 + E18 (Repositoryها + Machine delegation) |
| `src/Application/Clinical/MedicalFileService.php` | E16/E17/C3/C4: اعتبارسنجی MIME/سقف + ماتریس دسترسی Stream |
| `src/Infrastructure/Storage/LocalFileStorage.php` | TP-06: ذخیره محافظت‌شده خارج uploads + گاردها |
| `src/Infrastructure/Repository/{ClinicalNote,Prescription,Recommendation,FollowUp,MedicalFile}Repository.php` | ADR-0021 — Data-Access با فیلتر Visibility در Query |
| `src/Rest/ClinicalController.php` | E7–E15 + C5–C7 + E18 (guardهای ۵ لایه) |
| `src/Rest/FilesController.php` | E16/E17/C3/C4 + RateLimit + پاسخ باینری Stream |
| `src/Admin/DoctorDashboardPage.php` | UI پزشک: صف + صفحه ویزیت |
| `src/Bootstrap/App.php` | Factories (`clinicalService`, `medicalFileService` بدون کش) |

## 5. Audit (FR-21.1) — کدهای جدید F5

`NOTE_CREATED`, `NOTE_UPDATED` (version/length — بدون متن PHI)، `MEDICAL_RECORD_VIEWED`، `PRESCRIPTION_CREATED/FINALIZED/VOIDED`، `RECOMMENDATIONS_CREATED`، `FOLLOW_UP_CREATED`، `CONSULTATION_COMPLETED/REOPENED`، `FILE_UPLOADED/FILE_READ (فقط doctor_private\|lab_result)/FILE_SOFT_DELETED`، `SEARCH_EXECUTED` (q/type/range/شمار نتایج)، `FORBIDDEN_ACCESS_ATTEMPT` (هر IDOR). سند audit-strategy.md همگام شد (نام‌گذاری past-tense مطابق قرارداد برقرار F2–F4).

## 6. باگ‌های واقعی که در چرخه CI کشف و رفع شدند (بدون skip/weaken تست)

| باگ | ریشه | Fix |
|---|---|---|
| FORBIDDEN Audit با Rollback از بین می‌رفت | auditAndThrow داخل transactional | pre-check خارج تراکنش (created_by تغییرناپذیر) + قفل FOR UPDATE داخل |
| استایل PHPUnit 9: assertNotContains با haystack رشته‌ای | API حذف‌شده | assertStringNotContainsString |
| تست F4 checkout شکست خورد بعد از E14 | پیش‌نیاز جدید (FR-8.7) | helper تست، شکایت اصلی می‌سازد — intent تست دست‌نخورده |
| singleton `medicalFileService` مسیر اولین boot را قفل می‌کرد | کش factory وابسته به Setting | حذف کش — خواندن مسیر در هر ساخت |
| Stream ناشناس 404 می‌داد نه 401 | نبود لایه auth قبل از resource check | user-exists → 401 |
| `prescriptions.clinic_id` implicit 0 | ستون NOT NULL بدون default + insert بدون مقدار | default صریح 1 (ADR-0003) + regression assert |
| placeholder assertion در تست جستجو | خطای نگارش تست | اصلاح assertion |

بهبود زیرساخت: کامنت شکست PR اکنون بخش کامل جزئیات PHPUnit را می‌آورد (test + message + location) — چرخه دیباگ CI از ~۲ iteration تشخیصی به ۱ کاهش یافت.

## 7. تصمیمات، انحراف‌ها و اقلام باز

### 7.1 تصمیمات پیاده‌سازی (بدون توقف — Technical Alignment)
- **E18 Role-Aware:** منشی فقط نتایج بیمار می‌گیرد (ماتریس: فاقد note/rx/medical read)؛ نتایج note/rx برایش آرایه خالی است نه 403 (جستجو نقش‌محور است، نه endpoint محدود). یادداشت‌ها با Snippet ۱۲۰ کاراکتری برمی‌گردند (حداقل افشا).
- **رکورد E7 شامل فایل‌ها** (قرارداد: «فایل‌ها») — پزشک همه Visibilityها.
- **`files.max_upload_bytes`** (پیش‌فرض ۱۰MB مطابق settings-reference) ملاک است؛ سند file-storage.md که «20MB» گفته بود تصحیح شد (تضاد داخلی اسناد — مرجع: settings-reference).

### 7.2 اقلام باز (بدون Blocker فاز — برای فازهای بعد)
1. **Void نسخه endpoint REST ندارد** (سرویسی + تست هست؛ قرارداد F5 endpointای برای void تعریف نکرده — در صورت نیاز در فاز بعدی).
2. **«ویزیت خودش» فقط به clinician متصل به WP user چک می‌شود** — پزشک A نمی‌تواند برای ویزیت پزشک B نسخه بنویسد؛ کاملاً مطابق ماتریس 4.3. محدودیت: اگر دو پزشک در یک ویزیت مشارکت داشته باشند (کاربرد V2).
3. **F-1 nginx:** گارد `.htaccess` فقط Apache است؛ در استقرار nginx باید `location wp-content/clinic-files { deny all; }` تنظیم شود (به file-storage.md اضافه شد؛ استقرار فاز 9).
4. **PHPStan** طبق دستور: Blocker نبود و اضافه نشد (بدهی roadmap).
5. **SmsController::can()** (بدهی F2.5 → F8).
6. **دست‌خط/Jalali/OCR** → F6 (ADR-0014).

### 7.3 Adaptation تست F4 (شفاف)
`RestQueueTest::advanceToAwaitingPayment` اکنون قبل از `POST /visits/{id}/complete` یک یادداشت «شکایت اصلی» می‌سازد — چون E14 از این فاز FR-8.7 را enforce می‌کند. **هیچ assertion حذف/ضعیف/skip نشد؛** intent تست (جریان checkout) دست‌نخورده است و رفتار جدید محصول رعایت می‌شود.

## 8. Documentation Sync (همزمان)

- `docs/settings-reference.md`: `files.storage_path`، `clinical.require_chief_complaint` جدید؛ توضیح `files.max_upload_bytes` دقیق شد.
- `docs/security/audit-strategy.md`: کدهای Action مطابق پیاده‌سازی (بالینی/فایل/جستجو).
- `docs/architecture/file-storage.md`: نسخه 1.1 — پیاده‌سازی F5، سقف ۱۰MB، نکته nginx.
- `docs/agent-guide.md`: وضعیت، نقشه قابلیت‌ها، بسته‌شدن drift، درس‌های فنی برای F6.
- `clinic-practice-management/CHANGELOG.md`: ورودی کامل F5.
- `docs/api/api-contract.md` و `error-codes.md`: از قبل مطابق (E7–E18/C3–C7 و همه کدها از قبل مستند بودند؛ هیچ endpoint خارج قرارداد اضافه نشد).

## 9. تست و CI

- **Integration (WP 6.7.2 + MySQL 8):** **۱۸۴ تست / ۸۳۵ assertion — ۰ skip، ۰ failure** — شامل ClinicalFlowTest (۲۵) و MedicalFilesTest (۱۲) و تمام رگرسیون F2–F4 (Booking/Queue/SMS/OTP/PermissionMatrix/Concurrency با fork واقعی).
- **Unit:** PHP 8.1/8.2/8.3/8.4 سبز.
- **Run سبز نهایی:** 33993154803 روی `7866c39` (هر ۵ job) — HEAD فاز؛ tree clean و push شده.

## 10. جمع‌بندی

F5 با تمام Acceptance Criteria فاز (E7–E18، C3–C7، TP-06/TP-08/TP-10، UI پزشک، بستن Drift) کامل شد؛ CI روی HEAD نهایی سبز؛ مستندات همگام. سه باگ واقعی production (registerRole، clinic_id=0، از-بین-رفتن Audit در Rollback) در مسیر کشف و رفع شدند — بدون توقف Policy چون هیچ تغییر Permission Model/Product Decision لازم نشد. طبق دستور، در اینجا STOP می‌کنم و بدون تأیید کارفرما وارد F6 نمی‌شوم.
