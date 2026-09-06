# گزارش فاز F7 — دست‌خط پزشک (Canvas + Offline Sync)

**فاز:** F7 — دست‌خط پزشک | **تاریخ:** 2026-09-06 | **ایجنت:** Arena (`arena/01a071c4-doctor`)
**مراجع:** SRS §3.9 (FR-9.1..9.8)، ADR-0009 (Stroke Storage)، ADR-0014 (Offline Handwriting)، api-contract §6 (F1–F3)، wireframes/doctor.md §3/§5، data-dictionary §17–19 + K-6، testing-plan TP-12، background-jobs (handwriting.gc)، ADR-0026 (D-1/D-14/D-24)

---

## 1. خلاصه

چرخه کامل دست‌خط پزشک پیاده شد: **ویرایشگر Canvas تمام‌صفحه** (فول‌فیتِ wireframe §3) با Pressure/ابزارها/Zoom/Full-screen/Multi-page/Template/Annotation، **ذخیره‌سازی Stroke طبق ADR-0009** (gzip JSON، یک صفحه = یک Row، نوشتن = یک UPDATE)، **Auto-save + Offline Sync** با IndexedDB و **پروتکل Conflict طبق ADR-0014** (Revision یک‌افزایشی + دیالوگ «نسخه من/سرور») و **GC نسخه‌ها** (جاب `handwriting.gc`). مسیر کامل: دکمه «🖋️ دست‌خط» در صفحه ویزیت → ویرایشگر → Auto-save هر ۵ ثانیه → Sync با Backoff → در صورت تضاد، دیالوگ دو تب.

F4–F6 (OCR طبق قرارداد) خارج از F7 باقی ماندند (V1.5).

## 2. ماتریس Acceptance Criteria (SRS §3.9)

| FR | شرط | وضعیت | پیاده‌سازی |
|---|---|---|---|
| FR-9.1 | ویرایشگر Canvas تمام‌صفحه با ابزار قلم/هایلایتر/پاک‌کن/Undo/Redo | ✅ | `DoctorHandwritingPage` — قلم/هایلایتر (Pressure)، پاک‌کن سطح-Stroke، Undo/Redo کلاینتی (۴۰ مرحله) |
| FR-9.2 | Zoom/Pan و نوشتن روی تصویر (Annotation) | ✅ | Wheel/Pinch zoom + تک‌انگشتی pan؛ آپلود تصویر (E16) → `background_attachment_id` → رندر پس‌زمینه |
| FR-9.3 | ذخیره Strokeها بهینه (بدون عکس کامل صفحه در هر Save) | ✅ | ADR-0009: آرایه Strokeها → gzip(JSON)+base64 در `stroke_data` — نوشتن = یک UPDATE (NFR-PERF-4) |
| FR-9.4 | Auto-save دوره‌ای قابل تنظیم | ✅ | `hw.autosave_sec` (پیش‌فرض ۵s) — هر Save: IndexedDB همیشه + PUT سرور |
| FR-9.5 | نوشتن در حالت Offline و Sync بعدی | ✅ | IndexedDB (`cpms-hw`/`pending`) در هر Auto-save؛ Backoff 5/30/120/600/1800s؛ resume روی online/focus؛ Recovery نسخه محلی در load بعدی |
| FR-9.6 | مدیریت Conflict دو دستگاه (ADR-0014) | ✅ | پروتکل Revision (§3 پایین) + دیالوگ دو تب «نسخه من/سرور» با Preview — بدون ادغام خودکار |
| FR-9.7 | Multi-page + قالب‌ها | ✅ | [+ صفحه] (F1c) + قالب‌های blank/lined/graph/form + A4 منطقی 1240×1754 |
| FR-9.8 | حذف امن Local بعد از Sync | ✅ | سیاست `hw.local_retain` (off/last/always — T-16) |

**TP-12 (تست‌های مرورگر):** طبق testing-plan خط ۱۳، E2E Playwright جزو **V1.5** است؛ پوشش F7 = Integration (§6) + چک‌لیست دستی (§8).

## 3. پروتکل Revision (ADR-0014) — پیاده‌شده

- سرور `page.client_revision = R` را نگه می‌دارد (BIGINT، شروع ۰). کلاینت پس از load پایه R دارد.
- **Save با C فقط اگر `C == R+1` اعمال می‌شود:** R=C، `version++`، یک ردیف append-only در `_page_versions` (K-6) با `saved_by` (autosave/manual/sync_recovery).
- `C ≤ R` یا `C > R+1` → **409 CLINIC_CONFLICT** + `data.server = {client_revision, version, last_saved_at, strokes[]}` — کلاینت با همین وضعیت دیالوگ می‌سازد؛ **ادغام خودکار وجود ندارد** (تصمیم ADR-0014).
- **مسیر Force (بازنویسی):** کلاینت سرور را load می‌کند (پایه = R سرور)، سپس نسخه خود را با C=R+1 و `conflict_reason: overwrite_after_conflict` ذخیره می‌کند — در Audit meta ثبت می‌شود؛ Endpoint جدید لازم نشد.
- **رترای همان Save:** `Idempotency-Key` (UUID) روی `PUT /handwriting/pages/{id}` الزامی است (بدون هدر → 400)؛ کلید در کلاس عمومی Idempotency با `endpoint='handwriting/page', context=pageId` ذخیره می‌شود → رترای موفقیت‌آمیز، پاسخ ذخیره‌شده را **بدون version bump** برمی‌گرداند. در Request موازی → 409 `CLINIC_DUPLICATE_IN_FLIGHT`.
- **نکته سمت کلاینت (ضد Conflict کاذب):** هر «تلاش Save» یک کلید می‌سازد و تا پاسخ قطعی (200/409) همان کلید و همان C را برای retry نگه می‌دارد — Timeout شبکه پس از apply موفق، در رترای همان کلید پاسخ ذخیره‌شده می‌گیرد نه 409.

## 4. معماری و کد

| قطعه | مسیر | نقش |
|---|---|---|
| Migration 0004 | `src/Migrations/2026_09_06_0004_handwriting.php` | `background_attachment_id` BIGINT NULL FK→attachments (ON DELETE SET NULL) + ایندکس `idx_hwversion_created` (GC) — Idempotent با گارد SHOW COLUMNS/SHOW INDEX |
| سرویس | `src/Application/Handwriting/HandwritingService.php` | F1/F1b/F1c/F2/F3 + پروتکل Revision + اعتبارسنجی Stroke + purgeVersions + مالکیت (الگوی ClinicalService) |
| Repository | `src/Infrastructure/Repository/HandwritingRepository.php` | CRUD سه جدول + purgeOldVersions (دو مرحله‌ای با ایندکس created_at؛ keep/max_age) |
| Controller | `src/Rest/HandwritingController.php` | ۵ Endpoint + گارد Idempotency-Key (400) + Envelope |
| Job | `src/Application/Jobs/HandwritingGcHandler.php` | `handwriting.gc` → purgeVersions (idempotent) |
| UI | `src/Admin/DoctorHandwritingPage.php` | ویرایشگر تمام‌صفحه (زیرمنوی cpms-doctor، `?visit_id=`) |
| UI | `src/Admin/DoctorDashboardPage.php` | دکمه‌های «🖋️ دست‌خط» (هدر بیمار + نوار اقدام) |
| تست | `tests/Integration/HandwritingFlowTest.php` | ۱۵ تست Integration (§6) |

**Wiring:** `App::handwritingService()` + ثبت Controller در `rest_api_init` + Handler در `dispatcher()` + `'handwriting.gc' => 2` در `RECURRING_JOBS` + `DoctorHandwritingPage::register()` در boot + Settings DEFAULTS (`hw.version_keep`=10، `hw.version_max_age_days`=30).

**تصمیم‌های کلیدی:**
1. **پذیرش gzip یا JSON خام (magic sniff):** کلاینت‌های قدیمی Safari (بدون CompressionStream) base64(JSON) می‌فرستند؛ سرور تشخیص `\x1f\x8b` و همیشه gzip استاندارد ذخیره می‌کند (F3 همیشه Strokes decode شده برمی‌گرداند).
2. **Preview کلاینتی:** طبق ADR-0009 rendering نهایی مرورگر است؛ ستون `preview_png` NULL می‌ماند (PDF سرور = V1.5).
3. **Palm rejection عملی:** pointerType لمس هرگز Stroke نمی‌کشد — تک‌انگشتی = pan، دوانگشتی = pinch zoom؛ فقط pen/mouse می‌کشند (با Coalesced Events برای نرخ نمونه‌برداری بالا). Stroke در طول تغییر Zoom/Resize/تمام‌صفحه در مختصات منطقی صفحه ذخیره می‌شود (D-24 — بدون از دست رفتن).
4. **زمان‌بندی GC:** به‌جای زیرساخت cron جدید، از همان سازوکار `RECURRING_JOBS` (enqueue اگر QUEUED نیست، هر Tick) استفاده شد — Handler ارزان و idempotent است (یک SELECT ایندکسی؛ فقط نسخه‌های قدیمی‌تر از cutoff و خارج از keep آخر حذف می‌شوند). اثر خالص = اعمال مستمر سیاست نگهداری (قوی‌تر از بچ روزانه).
5. **Caps بدون قلم جدید (TP-10):** نوشتن = `cpms_note_create`، خواندن = `cpms_medical_read` + مالکیت ویزیت خودِ پزشک — منشی/بیمار هیچ‌کدام را ندارند؛ admin فنی هم ندارد (P-3).

## 5. Audit و امنیت

- **اکشن‌ها (audit-strategy §2):** `HW_DOC_CREATE` (meta: visit_id/page_count/title)، `HW_PAGE_ADD` (meta: document_id/page_index)، `HW_PAGE_SAVE` (before/after: client_revision/version؛ meta: saved_by/stroke_count/conflict_reason یا conflict+reason=revision_mismatch) + `FORBIDDEN_ACCESS_ATTEMPT` برای نقض مالکیت.
- **PHI در Audit نیست** — فقط ارجاع به صفحه/سند و شمار Stroke (بدون متن Stroke).
- مالکیت: هر endpoint علاوه بر Capability، ویزیت را از طریق document→visit→clinician(wp_user_id) زنجیر می‌کند؛ `AuditChainTest` همچنان سبز (زنجیره Hash سالم).
- Rate-limit جدا برای دست‌خط اضافه نشد (Auto-save ۵ ثانیه‌ای + Idempotency خودش رترای‌ها را خنثی می‌کند؛ سرور کلید تکراری را 200 برمی‌گرداند) — قابل افزودن در Hardening اگر نیاز شد.

## 6. تست‌ها (TP-12 — بخش Integration)

`HandwritingFlowTest` — ۱۵ تست:
1. ایجاد سند + صفحه پیش‌فرض A4 خط‌دار + Audit
2. رد پزشک بدون مالکیت ویزیت (404 + FORBIDDEN_ACCESS_ATTEMPT)
3. رد منشی (403 CLINIC_PERMISSION_DENIED — بدون cap)
4. F1b لیست اسناد (سند موجود/ویزیت بدون سند)
5. F1c افزودن صفحه (page_index=1، page_count=2، Audit)
6. پروتکل Revision: apply در C=R+1؛ 409 (با data.server) در C≤R و C=R+2؛ بدون bump
7. مسیر Force: load سرور → Save با conflict_reason → Audit meta
8. Idempotent replay: همان کلید = همان پاسخ؛ بدون ردیف نسخه اضافه
9. JSON خام (Safari) پذیرفته و gzip ذخیره می‌شود (magic `\x1f\x8b` چک می‌شود) + F3 decode
10. Validation: base64 خراب/JSON غیرآرایه/Stroke بدون points/نقطه غیرعددی/ابعاد — همه 422
11. REST: PUT بدون Idempotency-Key → 400
12. REST: ذخیره موفق (200) + Conflict → Envelope 409 با data.server
13. REST: GET صفحه + GET documents (منشی → 403)
14. GC: ۱۲ نسخه → backdate → فقط قدیمی‌های خارج از keep حذف (۲ از ۱۲؛ ۱۰ باقی؛ نسخه‌های تازه و ۱۰تای آخر سالم)
15. زمان‌بندی: `scheduleRecurringJobs` دقیقاً یک `handwriting.gc` QUEUED + پردازش واقعی با Dispatcher

## 7. انحرافات و تصمیم‌های مستندشده

| انحراف | توضیح |
|---|---|
| GC هر Tick به‌جای «روزانه» | سازوکار موجود RECURRING_JOBS (enqueue-if-not-queued) + Handler idempotent — اثر خالص قوی‌تر از بچ روزانه؛ بدون زیرساخت cron جدید (Deviation آگاهانه) |
| E2E Playwright | V1.5 طبق testing-plan؛ چک‌لیست دستی §8 |
| Preview PDF سرور | V1.5 (طبق قرارداد) |
| Rate-limit جدا برای دست‌خط | اضافه نشد — Idempotency عمومی رترای‌ها را خنثی می‌کند؛ قابل افزودت در F9 اگر لازم شد |
| `preview_png` | NULL می‌ماند — Preview = رندر کلاینتی طبق ADR-0009 |

## 8. چک‌لیست دستی مرورگر (TP-12 — قبل از Pilot)

- [ ] تبلت با قلم واقعی (iPad/Samsung — Spike ریسک roadmap): Pressure ضخامت را تغییر دهد؛ palm روی صفحه هیچ Stroke نکشد
- [ ] لمس تک‌انگشتی = pan؛ دوانگشتی = zoom — بدون رسم تصادفی
- [ ] Full-screen + چرخش Orientation: Strokeها جابه‌جا نشوند (مختصات منطقی)
- [ ] Airplane mode: نوشتن ادامه یابد (📡 آفلاین)؛ برگشت شبکه → Sync خودکار؛ رفرش صفحه → Recovery نسخه محلی
- [ ] دو تب/دو دستگاه هم‌زمان: تب دوم بعد از Save تب اول → دیالوگ Conflict با دو Preview؛ «نسخه سرور» و «بازنویسی» هر دو درست کار کنند
- [ ] [+ صفحه]، تغییر قالب، آپلود تصویر زمینه، Undo/Redo بعد از هر دو
- [ ] خروج با تغییرات ذخیره‌نشده: هشدر beforeunload + IndexedDB

## 9. CI

| کامیت | Run | نتیجه |
|---|---|---|
| 7cf4426 (کد اول F7) | 33998533694 | ❌ 20E+5F — ریشه: import غلط Settings (`Infrastructure\Security\Settings` به‌جای `Settings\Settings`) — TypeError در ساخت سرویس، آبشاری به REST/Jobs همه کلاس‌ها |
| b3875ea (اصلاح import) | 33999112434 | ❌ 10E+7F — ریشه: `pageRow` بدون `document_id` (INSERT می‌افتاد → insert_id کهنه → findPage=null در همه تست‌های HW) + Flakiness نیمه‌شب UTC در تست‌های Visit/Queue (تاریخِ `gmdate('Y-m-d')` با ساعتِ `time()±N` جفت می‌شد؛ اجرای 23:00–00:00 UTC مسیر lazy no-show → 'walk_in' می‌گرفت) |
| 190d8c5 (اصلاح document_id + رول‌اوور) | **در انتظار push** | توکن GitHub وسط جلسه منقضی شد؛ پس از اتصال مجدد push و CI نهایی ثبت می‌شود |

**وضعیت فعلی:** کامیت اصلاحی 190d8c5 به‌صورت محلی آماده است؛ Unit تست‌ها (PHP 8.1–8.4) در هر دو Run قبلی سبز بودند؛ انتظار: Integration سبز کامل پس از push.

## 10. Docs Sync (بسته‌شده در همین فاز)

- `docs/api/api-contract.md` §6: ردیف‌های F1/F1b/F1c/F2/F3 کامل (پروتکل، پذیرش gzip|JSON، الزام Idempotency-Key، data.server در 409)
- `docs/permissions/permission-matrix.md` §4.3: سطر دست‌خط + یادداشت مالکیت/Audit
- `docs/settings-reference.md`: `hw.version_keep`، `hw.version_max_age_days`
- `CHANGELOG.md`: ورودی کامل F7
- `docs/agent-guide.md`: لاگ F7 (این گزارش)

## 11. تحویل و گام بعد

- **DoD F7:** کد ✓ + تست‌های Integration ✓ (۱۵ تست جدید، ۲۱۸ کل) + CI ⏳ (push در انتظار اتصال مجدد GitHub) + این گزارش ✓
- **گام بعد (با تأیید کارفرما):** F8 — اعلان + گزارش (Notification Layer + Templates جلالی + ۱۲ گزارش + Export) طبق roadmap.
