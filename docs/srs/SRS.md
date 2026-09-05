# SRS — سیستم مدیریت مطب و پرونده الکترونیک بیمار

نسخه: 1.0 | تاریخ: 2026-09-05 | وضعیت: **منتظر تأیید کارفرما** | مرجع: Master Prompt (Sections 1–53)

---

## 1. مقدمه

### 1.1 هدف
طراحی و پیاده‌سازی یک افزونه اختصاصی WordPress برای مدیریت کلانژیک/مطب پزشک شامل: احراز هویت بیمار (Mobile+OTP)، رزرو نوبت آنلاین با کنترل Race Condition، پرونده الکترونیک بیمار، مدیریت مراجعه و صف مطب، ویزیت و یادداشت پزشکی (به‌همراه دست‌خط قلم و تشخیص متن)، نسخه، توصیه و Follow-Up، امور مالی (Invoice/Payment)، اعلان، Audit و گزارش‌گیری — با معماری Production-Ready و قابل توسعه برای چند پزشک/منشی/شعبه.

### 1.2 محدوده
- **داخل محدوده (سیستم):** تمام فرایند Section 51 Master Prompt (سناریوی پذیرش اصلی).
- **خارج محدوده V1:** پرداخت آنلاین، بیمه، اتصال آزمایشگاهی، نسخه الکترونیک ملی، اپ موبیو Native، چند شعبه فعال (معماری آماده است، فعال‌سازی در V2).
- WordPress مسئول: CMS، احراز هویت پایه، مدیریت کاربران، Cron، Mediator صفحه‌های عمومی. داده‌های پزشکی **صرفاً** در جداول اختصاصی (`cpms_*`) ذخیره می‌شوند و نه wp_posts/wp_postmeta.

### 1.3 واژه‌نامه

| اصطلاح | تعریف |
|---|---|
| **Appointment** | رزرو زمان (نه مراجعه). Entity مستقل. |
| **Visit** | مراجعه واقعی بیمار (Scheduled یا Walk-in). Entity مستقل. |
| **Slot** | یک برش زمانی از برنامه پزشک (تاریخ + ساعت شروع + مدت). |
| **MRN** | Medical Record Number / شماره پرونده بیمار (منحصر‌به‌فرد در شعبه). |
| **PHI** | اطلاعات قابل‌شناسایی بیمار (Protected Health Information). |
| **Hold** | رزرو موقت Slot در جریان رزرو آنلاین (با TTL) برای جلوگیری از Race Condition. |
| **Hold ≠ Appointment** | Hold رکورد سبک روی Slot است؛ Appointment پس از تأیید نهایی ساخته می‌شود. |
| **Visit Status History** | تاریخچه تغییر وضعیت صف با Actor و Timestamp (append-only). |
| **Adjustment** | اصلاح مالی بدون حذف تراکنش (Credit/Debit Note با ارجاع). |

### 1.4 نقشه‌های مرجع
Section 54 Master Prompt → پوشه‌های /docs: SRS(1)، Use Cases(2)، Permission(3)، State Machines(4)، ERD(5)، API(6)، Wireframes(7)، Security(8)، Backup/DR(9)، Testing(1)، MVP(1)، Roadmap(1).

---

## 2. شرح کلی

### 2.1 بازیگران (Actors)

| نقش | توصیف | سطح دسترسی PHI |
|---|---|---|
| **Patient** | صاحب نوبت و پرونده؛ وارد با Mobile+OTP | فقط اطلاعات مجاز خودش (Policy) |
| **Secretary** | منشی مطب؛ Dashboard Front-end اختصاصی | PHI بیماران کل مطب؛ **بدون** Doctor Private Notes |
| **Doctor** | پزشک؛ Dashboard Tablet/Pen-First | PHI کامل + Private Notes (کل مطب در V1؛ در V2 محدود به پزشک/تیم) |
| **WP Administrator** | امور فنی وردپرس | **به‌صورت پیش‌فرض هیچ PHI ندارد**؛ دسترسی پزشکی فقط با Capability صریح (`cpms_medical_read` و...) |
| **System** | Cron/Job: انقضای Hold، no-show، ارسال اعلان، نوبت‌های pending | سیستمی، فقط Log |
| **External** | دروازه SMS، Provider تشخیص دست‌خط، (آینده: POS، بانک) | حداقل‌سازی داده |

### 2.2 محیط اجرا
- WordPress ≥ 6.4، PHP ≥ 8.1، MySQL ≥ 8.0 (InnoDB، utf8mb4)، HTTPS اجباری.
- مرورگرهای مدرن (Chrome/Safari/Edge/Firefox) + تبلت (iPad/Samsung) با Pointer Events برای ویرایشگر دست‌خط.
- زیرساخت V1: تک سرور با PHP-FPM + MySQL + Cron سیستمی (نه فقط WP-Cron).

### 2.3 محدودیت‌های طراحی
- **L-1** داده پزشکی در جداول اختصاصی؛ wp_posts فقط برای صفحه‌های عمومی وب‌سایت.
- **L-2** زمان در دیتابیس: `UTC` + نوع `DATETIME(3)` / `TIMESTAMP`؛ Jalali فقط Presentation.
- **L-3** زبان UI: فارسی، RTL، فونت فارسی (Vazirmatn پیشنهادی)، تقویم Jalali در UI.
- **L-4** Authorization سرور-based؛ هیچ Endpointی فقط با Login بودن معتبر نیست.
- **L-5** هر عمل حساس → Audit Log؛ OTP/رمز/Token در هیچ Log و جداول عمومی ذخیره نمی‌شود (مستثنا: hash code در `cpms_otp_tokens` طبق ADR-0006).
- **L-6** حالت‌های داده با State Machine کنترل‌شده؛ تغییر وضعیت بدون بررسی Transition ممنوع.
- **L-7** حذف فیزیکی عمومی وجود ندارد: Archive / Soft Delete / Void / Correction.

### 2.4 فرض‌ها (Assumptions)
| ID | فرض | ریسک اگر برقرار نباشد |
|---|---|---|
| A-1 | سررسید V1: یک شعبه، یک پزشک (معماری چند-پزشک/شعبه از روز اول). | نیاز به Migration کم‌خطر (ستون clinic_id از قبل وجود دارد). |
| A-2 | دروازه SMS ایرانی با API REST/JSON موجود است؛ انتخاب Provider تصمیم کارفرما است. | لایه Adapter آماده است؛ بدون تغییر معماری. |
| A-3 | ظرفیت پیش‌فرض هر Slot = 1 بیمار. | مدل Hold/Claim ظرفیت N را پشتیبانی می‌کند. |
| A-4 | OCR دست‌خط فارسی: در V1.5 با Provider خارجی/محلی؛ دقت Provider باید روی نمونه فارسی واقعی ارزیابی شود. | خروجی OCR تا تأیید پزشک هیچ اعتبار بالینی ندارد (قانون سفت). |
| A-5 | در V1 پزشک می‌تواند به Private Notes سایر پزشکان هم دسترسی داشته باشد (تیم واحد). | در V2 مدل دسترسی تیمی اعمال می‌شود. |
| A-6 | ویزیت‌های یک پزشک در یک شعبه؛ Room در V1 فقط یک فیلد اختیاری. | — |
| A-7 | نرخ تعرفه در جدول `cpms_services`؛ فاکتور در V1 دستی (توسط منشی/پزشک) ساخته می‌شود؛ در V2 خودکار از خدمات. | — |

### 2.5 وابستگی‌ها
- SMS Gateway (انتخاب کارفرما)، OCR Provider (V1.5)، وب‌سرا/SSL، MySQL 8، Cron OS-level.

---

## 3. نیازمندی‌های عملکردی

> اولویت: **M** = باید (V1)، **H** = باید (V1.5+، معماری آماده)، **C** = ممکن (بعداً).

### 3.1 احراز هویت و دسترسی

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-1.1 | ورود بیمار با Mobile Number + OTP (6 رقم). شماره موبایل فرمت استاندارد ایران (`09xxxxxxxxx` / `+989xxxxxxxxx`) اعتبارسنجی می‌شود. | M |
| FR-1.2 | OTP: مدت اعتبار **5 دقیقه**، حداکثر **5 بار** تلاش نادرست، **Cooldown ارسال مجدد 60 ثانیه**، حداکثر **3 کد در روز** برای هر شماره. پس از 5 شکست، قفل تا 15 دقیقه. | M |
| FR-1.3 | OTP خام هرگز ذخیره نمی‌شود؛ فقط `SHA-256(code + server_pepper)`. (ADR-0006) | M |
| FR-1.4 | هر رویداد OTP (درخواست، ارسال موفق/ناموفق، تأیید، قفل) در Audit/Operational Log ثبت می‌شود بدون خود کد. | M |
| FR-1.5 | دروازه SMS با Interface قابل تعویض (`SmsGateway`)؛ Provider از تنظیمات. | M |
| FR-1.6 | بیمار جدید پس از تأیید OTP، حساب WordPress (`role=patient`) ساخته و به Patient متصل می‌شود (`cpms_patient_user_links`). | M |
| FR-1.7 | بیمار قبلی (شماره موجود در `patients.mobile`) به Patient موجود متصل می‌شود؛ اگر >1 Patient با همان موبایل باشد، لیست انتخاب نشان داده می‌شود. | M |
| FR-1.8 | Session امن: Cookie HttpOnly/Secure، محدود به دسکاپراتور (Site Binding)، مهلت نشست. | M |
| FR-1.9 | 2FA (TOTP) برای Doctor/Secretary: معماری و فیلدها آماده؛ فعال‌سازی در V1.5. (H) | H |
| FR-1.10 | ورود با نام کاربری/رمز برای بیمار **غیرفعال** است (فقط OTP). | M |
| FR-1.11 | حساب‌های تکراری: قابلیت ادغام (Merge) بیمار با نگاشت کامل رکوردها (MVP: ابزار داخلی، V1.5 UI؛ ADR-0015). | H |
| FR-1.12 | Rate Limit کلی روی Endpointهای احراز هویت (مثلاً 10 OTP/ساعت/IP+Mobile). | M |

### 3.2 مدیریت بیمار

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-2.1 | Patient Entity مستقل با `MRN` (شماره پرونده) منحصربه‌فرد در شعبه؛ فرمت `P-000123`. | M |
| FR-2.2 | فیلدهای پایه: نام، نام خانوادگی، موبایل، کد ملی (اختیاری، اعتبارسنجی حروف‌ملی 10 رقمی + checksum)، تاریخ تولد (valid، سن >18 یا قابل تنظیم)، جنسیت، آدرس، تلفن ثابت، شخص/تلفن تماس اضطراری. | M |
| FR-2.3 | فیلدهای پزشکی پایه: گروه خونی، آلرژی‌های دارویی، آلرژی‌های دیگر، بیماری‌های زمینه‌ای، سوابق پزشکی مهم، سوابق جراحی، داروهای مصرفی فعلی. همه اختیاری. ذخیره به‌صورت لیست ساختاریافته (JSON با validation). | M |
| FR-2.4 | بیمار فقط فیلدهای مجاز Policy را می‌تواند ویرایش کند (پیش‌فرض: موبایل/آدرس/تلفن/تماس اضطراری؛ فیلدهای پزشکی **فقط** توسط Secretary/Doctor). | M |
| FR-2.5 | هر تغییر روی فیلدهای حساس: Audit Log با before/after change-set؛ overwrite بی‌ردپ ممنوع. | M |
| FR-2.6 | تکراری‌یابی: هشدار هنگام ثبت Patient با همان موبایل/کد ملی؛ ابزار ادغام (فرزند به مادر) با نگاشت `cpms_patient_merges`. | H |
| FR-2.7 | جستجوی بیمار برای Doctor/Secretary: نام، موبایل، کد ملی، MRN (Role-Aware، فقط موارد مجاز). | M |
| FR-2.8 | Patient قابل Soft-Delete/Archive نیست مگر با جریان ادغام؛ حذف عمومی = Archive با دلیل. | M |

### 3.3 برنامه کاری و در دسترس‌یابی (Availability)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-3.1 | تعریف برنامه هفتگی پزشک: روزهای کاری (0–6)، شروع/پایان، استراحت، مدت هر Appointment (پیش‌فرض 20 دقیقه). | M |
| FR-3.2 | استثنائات: تعطیل رسمی، مرخصی، Block اختصاصی، باز بودن خارج از برنامه (override) — جدول `cpms_schedule_exceptions` با نوع. | M |
| FR-3.3 | محاسبه Slotها: تولید از برنامه + استثنائات − رزروها. **Slot مادی (Materialized)** در `cpms_schedule_slots` با `capacity / booked_count / held_count` (ADR-0004). | M |
| FR-3.4 | بازه‌های قابل رزرو: حداقل فاصله تا رزرو (پیش‌فرض 2 ساعت) و حداکثر افق آینده (پیش‌فرض 30 روز)؛ قابل تنظیم. | M |
| FR-3.5 | بیمار **پیش از Login** می‌تواند تقویم و Slotهای آزاد را ببیند (Read-only، بدون داده PHI). | M |
| FR-3.6 | نمایش تاریخ: Jalali در UI، ذخیره UTC. | M |
| FR-3.7 | رزرو حضوری توسط منشی خارج از بازه آنلاین (هر زمانی تا پایان کار روز). | M |
| FR-3.8 | نوبت فوری: منشی می‌تواند Slot فوری (همین لحظه/بعدی خالی) ثبت کند؛ ثبت با دلیل. | M |
| FR-3.9 | علت مراجعه (اختیاری) هنگام رزرو. | M |

### 3.4 رزرو نوبت (Booking Flow)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-4.1 | جریان: انتخاب روز → Slotهای آزاد → انتخاب ساعت → تأیید اولیه → درخواست Login/Register → Mobile → OTP → (بیمار جدید: پروفایل / قبلی: انتخاب/تأیید Patient) → **بازبررسی آزاد بودن Slot سمت Server** → ثبت نهایی → نمایش کد نوبت → SMS تأیید. | M |
| FR-4.2 | **Hold:** هنگام انتخاب Slot، سمت Server یک Hold (TTL پیش‌فرض **10 دقیقه**) ثبت می‌شود: `cpms_slot_holds` + افزایش اتمیک `held_count`. | M |
| FR-4.3 | دو بیمار نمی‌توانند یک Slot با ظرفیت 1 را هم‌زمان رزرو کنند: کنترل **در سطح دیتابیس** — UPDATE شرطی اتمیک (`... WHERE capacity - booked - held > 0`) + Transaction + Unique Constraintهای bổ trợ (ADR-0004). | M |
| FR-4.4 | انقضای Hold: Background Job هر دقیقه Holdهای منقضی را آزاد می‌کند (idempotent). | M |
| FR-4.5 | دو بار ارسال (Double Submit) رزرو: با Idempotency-Key + Hold token منحصربه‌فرد فقط یک Appointment ساخته می‌شود. | M |
| FR-4.6 | اگر Slot بین انتخاب و تأیید پر شود: پیام واضح + نمایش Slotهای نزدیک خالی. | M |
| FR-4.7 | اگر اینترنت بیمار وسط رزرو قطع شود: Hold سمت Server باقی است تا TTL؛ با بازگشت، بیمار «ادامه نوبت» (با token) می‌بیند یا Slot آزاد می‌ماند. | M |
| FR-4.8 | رمز نوبت (Reference Code) قابل‌پیگیری: فرمت خوانا (مثلاً `AP-260405-12`) + نمایش تاریخ/ساعت Jalali. | M |
| FR-4.9 | لغو آنلاین توسط بیمار در محدوده Policy (حداقل X ساعت قبل؛ پیش‌فرض 24 ساعت؛ آزاد = بدون هزینه؛ V1 جریمه مالی ندارد فقط محدودیت زمانی). | M |
| FR-4.10 | جابه‌جایی (Reschedule) آنلاین با همان قوانین + انتخاب Slot جدید (سریع‌تر: انتقال Hold). | M |

### 3.5 مدیریت Appointment

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-5.1 | وضعیت‌ها: `pending, confirmed, cancelled_by_patient, cancelled_by_staff, rescheduled, completed, no_show` (State Machine — docs/state-machines/appointment.md). | M |
| FR-5.2 | هر Transition: Actor، شرط، Side-Effects (آزادسازی Slot، اعلان، Audit). Transition نامعتبر = خطای `CLINIC_INVALID_TRANSITION`. | M |
| FR-5.3 | منشی می‌تواند Appointment بسازد/لغو/جابه‌جا کند (در محدوده مجوز). | M |
| FR-5.4 | `pending` (مثلاً ساخته‌شده توسط منشی نیازمند تأیید پزشک) با TTL؛ Job انقضا. | M |
| FR-5.5 | no-show: دستی توسط منشی (با دلیل) یا خودکار پس از Grace Period (پیش‌فرض 30 دقیقه بعد از Slot، قابل تنظیم) — فقط اگر Visit فعال وجود نداشته باشد. | M |
| FR-5.6 | هر Appointment حداکثر یک Visit فعال دارد؛ هر Visit حداکثر یک Appointment (nullable). | M |
| FR-5.7 | تاریخچه تغییرات Appointment در Audit (change-set). | M |

### 3.6 ورود بیمار (Check-In) و Walk-in

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-6.1 | منشی بیمار را با جستجو پیدا می‌کند → Check-In: **Visit ساخته می‌شود** (`source=scheduled` یا `walk_in`)، `check_in_at` ثبت، Visit → `checked_in` → (خودکار) `waiting`. | M |
| FR-6.2 | Walk-in: منشی بیمار را جستجو/ساخت → Visit جدید بدون Appointment. | M |
| FR-6.3 | جلوگیری از Visit تکراری فعال: یک بیمار برای یک پزشک در یک روز فقط یک Visit فعال (چک Transaction + Unique نرم‌افزاری). | M |
| FR-6.4 | Check-In اشتباه: منشی می‌تواند Visit را `cancelled` کند (دلیل + Audit) و مجدداً درست Check-In کند. | M |
| FR-6.5 | بیمار دیررس: تا پایان Grace Period قابل Check-In است؛ بعد از آن no-show + در صورت مراجعه، Visit فوری توسط منشی (Walk-in-like) با ارجاع به Appointment. | M |

### 3.7 صف (Queue)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-7.1 | State Machine صف: `checked_in → waiting → called → in_consultation → consultation_completed → awaiting_payment → paid → checked_out` + حالت‌های `cancelled, skipped, recalled(event)` (docs/state-machines/visit-queue.md). | M |
| FR-7.2 | هر تغییر وضعیت → `cpms_visit_status_history` (from, to, timestamp, actor, role, note) — append-only. | M |
| FR-7.3 | ترتیب صف: FIFO بر مبنای زمان ورود به `waiting` + Priority (نوبت فوری = head-of-queue، قابل تنظیم). | M |
| FR-7.4 | «فراخوان» توسط پزشک: `waiting → called` + **اعلان Real-Time به منشی** (بیمار، پزشک، Room، زمان). | M |
| FR-7.5 | «بازگشت به صف» (Recall): `called → waiting` با شمارش تکرار (Audit). | M |
| FR-7.6 | Skipped: پزشک بیمار را رد می‌کند (دلیل الزامی). | M |
| FR-7.7 | نمایش زمان انتظار زنده در داشبورد منشی. | M |

### 3.8 ویزیت و پرونده بیمار

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-8.1 | صفحه ویزیت پزشک: یک صفحه واحد (Tab/Section) با: مشخصات بیمار + سن (محاسبه‌شده از تاریخ تولد)، آلرژی‌ها، بیماری‌های زمینه‌ای، داروهای جاری، سوابق ویزیت‌های قبلی، نسخه‌های قبلی، آزمایش‌ها/فایل‌ها، فرم ثبت Visit جاری. | M |
| FR-8.2 | فیلدهای ثبت Visit: Chief Complaint، History، Examination، Diagnosis، Clinical Notes، Prescription، Recommendations، Follow-up، Attachments، Private Notes. | M |
| FR-8.3 | یادداشت‌ها با حداقل دو سطح `visibility`: `patient_visible` و `doctor_private`. | M |
| FR-8.4 | **Backend Enforcement:** `GET` note/visit برای Secretary و Patient هرگز `doctor_private` برنمی‌گرداند — فیلتر در Repository/Query (نه فقط UI). تست اجباری (TP-08). | M |
| FR-8.5 | Correction یادداشت: نسخه جدید + `change_reason` + حفظ نسخه قبلی (`cpms_clinical_note_versions`). | M |
| FR-8.6 | Visit بدون Appointment (Walk-in) کاملاً قابل ویزیت است. | M |
| FR-8.7 | Complete Consultation: Validation قابل تنظیم (پیش‌فرض: Chief Complaint غیرخالی)؛ پس از آن Visit → `awaiting_payment` (یا مستقیم Fاکتور). | M |
| FR-8.8 | Complete اشتباه: بازگردانی به `in_consultation` فقط با مجوز بالا (Doctor) + دلیل + Audit. | M |

### 3.9 دست‌خط پزشک (Clinical Handwriting)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-9.1 | ویرایشگر Canvas با Pointer Events؛ Pressure Sensitivity در دستگاه‌های پشتیبانی‌شده؛ Palm Rejection تا حد امکان (stylus button / touch vs pen). | M |
| FR-9.2 | ابزار: Undo/Redo، پاک‌کن (stroke-level)، اندازه/رنگ قلم، Zoom، Pan، Full-Screen، صفحات متعدد، Template پس‌زمینه (خط‌دار/خالی/فرم)، نوشتن روی تصویر (Annotation). | M |
| FR-9.3 | هر Handwriting Document به Patient + Visit + Doctor متصل؛ Timestamp و Version History. | M |
| FR-9.4 | **ذخیره داده خام Stroke** (x, y, pressure, t, tool) — **نه فقط** تصویر (ADR-0009: صفحه = یک رکورد با JSON فشرده Strokeها؛ نقطه‌به‌نقطه به‌صورت Row جدا ممنوع). | M |
| FR-9.5 | تولید Preview (PNG) + (اختیاری) PDF/SVG. | M |
| FR-9.6 | Auto-save: هر N ثانیه (پیش‌فرض 5 ثانیه، با تغییر) → Sync به Server. | M |
| FR-9.7 | **آفلاین:** ذخیره موقت Local (IndexedDB) + وضعیت `Saving/Saved/Offline/Sync Failed` + Retry (Exponential Backoff) + Conflict Resolution (ADR-0014). | M |
| FR-9.8 | ویرایشگر باید حداقل روی iPad (Safari, Pencil) و Samsung (Chrome, S Pen) کار کند. | M |

### 3.10 تشخیص دست‌خط (Handwriting Recognition)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-10.1 | Workflow: دست‌خط ذخیره → Job تشخیص → متن استخراج‌شده → **بازبینی پزشک** → تأیید/ویرایش → ذخیره متن قابل‌جستجو. | H (V1.5) |
| FR-10.2 | **قانون سفت:** متن OCR تا تأیید پزشک هیچ‌جای سیستم (به‌ویژه نسخه) اعتبار ندارد. | H |
| FR-10.3 | هر نتیجه: `status, confidence(اختیاری), provider/model, timestamp, reviewed_by, confirmed_text`. | H |
| FR-10.4 | Provider قابل تعویض با Interface واحد؛ ارزیابی دقت فارسی روی ≥200 نمونه واقعی قبل از انتخاب (Acceptance Test). | H |
| FR-10.5 | دست‌نویس اصلی (Source) هیچ‌گاه با متن استخراجی جایگزین نمی‌شود. | H |
| FR-10.6 | ارسال تصویر به Provider خارجی: فقط با Consent/Policy و حداقل‌سازی داده (بدون نام بیمار روی تصویر — Watermark/PII Removal). | H |

### 3.11 نسخه و دارو

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-11.1 | Prescription به Visit + Patient متصل؛ شماره نسخه (مثلاً `RX-000123`). | M |
| FR-11.2 | Itemها: نام ژنریک، برند، دوز/Strength، فرم (قرص/سیرپ/آب‌سوز...)، مقدار مصرف، تکرار/فروسیتگی، مسیر مصرف (خوراکی/تزریقی/موضعی...)، مدت، دستور/یادداشت. | M |
| FR-11.3 | دیکشنری دارویی مرجع (`cpms_drug_reference`) اختیاری؛ ورود دستی آزاد است. | M |
| FR-11.4 | نسخه‌هایی که Itemشان از OCR تأیید‌شده آمده: `source=ocr_confirmed` + ارجاع به OCR Job. | H |
| FR-11.5 | تغییر نسخه بعد از نهایی‌شدن: نسخه جدید (نه ویرایش خام) یا Correction با نسخه‌بندی + Audit. | M |
| FR-11.6 | تاریخچه نسخه‌ها برای بیمار (در صورت `patient_visible`) و پزشک/منشی (طبق Matrix). | M |
| FR-11.7 | اتصال نسخه الکترونیک ملی: فقط Interface آماده؛ V1 خیر. | C |

### 3.12 توصیه و Follow-Up

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-12.1 | Recommendation: نوع (رژیم، استراحت، فعالیت، مراقبت، آزمایش، مراجعه مجدد، دیگر) + متن + `patient_visible` flag. | M |
| FR-12.2 | Follow-Up: نیاز؟ + تاریخ پیشنهادی + بازه + دلیل → Recall بیمار (SMS/Internal) + لینک به نوبت جدید در صورت رزرو. | M |
| FR-12.3 | بیمار در Dashboard خود توصیه‌ها/Follow-Upهای مجاز را می‌بیند. | M |

### 3.13 فایل‌های پزشکی

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-13.1 | آپلود: PDF، تصویر (JPG/PNG/WEBP)، اسکن، سند پزشکی — با `category` و `visibility`. | M |
| FR-13.2 | اعتبارسنجی: MIME (finfo، نه فقط Extension)، Whitelist Extension، حداکثر حجم (پیش‌فرض 20MB)، نام فایل تصادفی (بدون نام اصلی). | M |
| FR-13.3 | **خروجی فایل فقط از طریق Endpoint مجوزیافته** (Stream با Permission Check)؛ URL عمومی مستقیم ممنوع (docs/architecture/file-storage.md). | M |
| FR-13.4 | هر دسترسی (Read/Download) فایل با `visibility=doctor_private` یا آزمایش، در Audit ثبت می‌شود. | M |
| FR-13.5 | Malware Scan: Hook آماده؛ فعال‌سازی اختیاری (ClamAV) V1.5. | H |
| FR-13.6 | حذف فایل = Soft Delete + نگهداری فیزیکی طبق Retention. | M |

### 3.14 مالی: Invoice / Payment

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-14.1 | **Invoice ≠ Payment** (قانون سفت). Invoice: بیمار، Visit، شماره یکتا، Items (Service/توضیح/تعداد/قیمت)، تخفیف، مالیات (اختیاری)، جمع، پرداخت‌شده، باقی‌مانده، وضعیت. | M |
| FR-14.2 | Payment: Invoice، مبلغ، روش (Cash / Card-POS / Online / Other)، Reference/Transaction ID، تاریخ، دریافت‌کننده، وضعیت. | M |
| FR-14.3 | Partial Payment: Invoice `partial` تا تسویه کامل. | M |
| FR-14.4 | **Idempotency:** `Idempotency-Key` یکتا روی (Invoice, Key) — تکرار POST Payment = همان پاسخ، بدون تراکنش دوم. | M |
| FR-14.5 | Void Payment: فقط با مجوز + دلیل + بازه زمانی (پیش‌فرض همان روز)؛ تراکنش **حذف نمی‌شود** (Void با Audit). | M |
| FR-14.6 | Correction: Amendment (Credit/Debit Note) با ارجاع به تراکنش اصلی؛ پرداخت‌ها **Immutable**. | M |
| FR-14.7 | Refund: روی Payment با مجوز + دلیل + (اختیاری) تایید دوم. | M |
| FR-14.8 | Debt/باقی‌مانده: قابل‌مشاهده در داشبورد مالی + گزارش «Open Balances». | M |
| FR-14.9 | تعرفه‌ها: جدول `cpms_services` (نام، کد، قیمت، فعال)؛ فاکتورسازی سریع از خدمات. | M |
| FR-14.10 | پیش‌فاکتور/فاکتور دستی توسط منشی (مبلغ قابل دریافت نمایش داده می‌شود). | M |
| FR-14.11 | پرداخت آنلاین: معماری آماده (Webhook + Reconciliation)؛ V1 خیر. | C |

### 3.15 تسویه و خروج (Checkout)

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-15.1 | پس از پرداخت کامل: Visit `awaiting_payment → paid → checked_out` (دو Transition مجزا؛ `checked_out_at` ذخیره می‌شود). | M |
| FR-15.2 | Receipt: قابل چاپ/پی‌دی‌آف با شماره فاکتور، آیتم‌ها، پرداخت‌ها، تاریخ Jalali. | M |
| FR-15.3 | Visit بدون فاکتور (ویزیت رایگان/داخلی): `awaiting_payment` رد می‌شود (منشی/پزشک با مجوز + دلیل). | M |
| FR-15.4 | بعد از Checkout، Slot/صف آماده بیمار بعدی؛ سیستم حالت Clean دارد. | M |

### 3.16 Dashboard بیمار

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-16.1 | Mobile-First، فارسی/RTL. | M |
| FR-16.2 | مشاهده/ویرایش پروفایل مجاز، رزرو نوبت، نوبت‌های آینده/قبلی، لغو/جابه‌جا در Policy. | M |
| FR-16.3 | تاریخچه ویزیت‌های مجاز: فقط فیلدهای `patient_visible` (یادداشت‌های patient_visible، نسخه، توصیه، Follow-Up، فایل‌های مجاز). | M |
| FR-16.4 | Invoice/Payment/Receipt: فقط اگر کارفرما فعال کند (Setting) — پیش‌فرض **خیر** در V1 برای ساده‌سازی (تصمیم کارفرما). | H |
| FR-16.5 | **ممنوع:** Private Note، Audit، اطلاعات بیمار دیگر (IDOR Test اجباری TP-07). | M |
| FR-16.6 | اعلان‌های Internal (لیست در داشبورد + Badge). | M |

### 3.17 Dashboard منشی

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-17.1 | Front-end اختصاصی (نه wp-admin)؛ Desktop/Tablet؛ فرایند سریع (کلیدهای میان‌بر). | M |
| FR-17.2 | نمای «امروز»: نوبت‌های امروز، حاضرین، منتظر، فراخوان‌شده، در اتاق ویزیت، ویزیت‌شده، منتظر پرداخت، تسویه‌شده، No-Show، Walk-in. | M |
| FR-17.3 | هر ردیف: نام، MRN، ساعت نوبت، زمان ورود، وضعیت، زمان انتظار زنده. | M |
| FR-17.4 | عملیات: Check-In، ساخت Walk-in، تغییر وضعیت مجاز، جستجو، ساخت/لغو/جابه‌جا نوبت، مشاهده مبلغ، ثبت Payment، صدور Receipt، Checkout. | M |
| FR-17.5 | منشی **هرگز** Doctor Private Note را نمی‌بیند (Backend Enforced + Test). | M |
| FR-17.6 | اعلان Real-Time هنگام فراخوان بیمار (صفحه‌نمایش + صوت اختیاری). | M |

### 3.18 Dashboard پزشک

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-18.1 | Tablet/Pen-First؛ Targets بزرگ لمس. | M |
| FR-18.2 | نمای اولیه: آمار امروز (نوبت، حاضر، منتظر، ویزیت‌شده، No-Show) + صف زنده. | M |
| FR-18.3 | انتخاب بیمار از صف → پرونده + Visit جاری → «Call Patient». | M |
| FR-18.4 | صفحه ویزیت (FR-8.x) + ویرایشگر دست‌خط + نسخه + توصیه + Follow-Up + Complete. | M |

### 3.19 جستجو و گزارش

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-19.1 | جستجوی جامع (نام/موبایل/کدملی/MRN/تاریخ/تشخیص/متن OCR تأیید‌شده) — Role-Aware. | M |
| FR-19.2 | گزارش‌ها: نوبت‌های امروز/هفته، ویزیت، لغو، No-Show، Walk-in، میانگین انتظار، مدت ویزیت، درآمد روز/ماه، باقی‌مانده، روش پرداخت، Follow-Upهای سررسید. | M |
| FR-19.3 | Export کنترل‌شده (CSV/PDF؛ Excel H): مجوز + Audit (Who/What/Filters/When) + Watermark روی خروجی حساس. | M |

### 3.20 اعلان و Real-Time

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-20.1 | لایه Notification مستقل: رویداد → Queue → Channel Adapter (Internal / SMS / Email / Push(H)). | M |
| FR-20.2 | رویدادها: OTP، تأیید نوبت، یادآوری نوبت (پیش‌فرض 1 روز قبل + تنظیم)، تغییر/لغوشدن نوبت، فراخوان بیمار، آماده‌شدن برای پرداخت، یادآوری Follow-Up. | M |
| FR-20.3 | وضعیت اعلان: `queued → sent → delivered(H) / failed` + Retry با Backoff + شمارش تلاش. | M |
| FR-20.4 | ارسال اعلان **Blocking** درخواست اصلی نیست (enqueue آسان، ارسال Async). | M |
| FR-20.5 | Real-Time V1: **Controlled Polling** (3–5 ثانیه روی صف داشبوردها) با Lایه Transport قابل تعویض (WebSocket/SSE بعداً). (ADR-0007) | M |
| FR-20.6 | یادآوری نوبت: Job شب قبل + صبح روز؛ انصراف خودکار پس از لغو. | M |

### 3.21 Audit و پایش

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-21.1 | Audit Log برای: Login/Failed Login، مشاهده PHI حساس (طبق Policy)، Update بیمار/پرونده، Note (ایجاد/تغییر)، نسخه، نوبت، وضعیت صف، فاکتور/پرداخت/Void، دسترسی فایل، Export، تغییر مجوز. | M |
| FR-21.2 | ساختار Audit: Actor، Role، Action، Target، Target ID، Timestamp، Request/Session Context، before/after. | M |
| FR-21.3 | **ممنوع** در Audit/Log: OTP، رمز عبور، Token، Secret. | M |
| FR-21.4 | Audit غیرقابل ویرایش/حذف برای کاربران عادی؛ Append-only + Hash Chain (ADR-0008). | M |
| FR-21.5 | Operational Log جدا از Audit (خطای اپ، Job، SMS، OCR) بدون PHI غیرضروری. | M |
| FR-21.6 | خطاهای API: کد خطای استاندارد + Request ID + Log سرور (بدون PHI در Body خطا). | M |

### 3.22 تاریخچه، حذف و Retention

| ID | نیازمندی | اولویت |
|---|---|---|
| FR-22.1 | داده‌های حساس با Version History (Note: `cpms_clinical_note_versions`؛ Handwriting: Version؛ Payment: Adjustment؛ Patient: Audit change-set). | M |
| FR-22.2 | Correction پزشکی: Reason الزامی + نگه‌داری نسخه اصلی. | M |
| FR-22.3 | Hard Delete عمومی ممنوع؛ `archive / soft delete / void / correction`. | M |
| FR-22.4 | Retention Policy قابل تنظیم از تنظیمات (حداقل نگهداری پیش‌فرض: Audit 10 سال، پرونده 15 سال — **تصمیم نهایی کارفرما/قانون محل**). | M |
| FR-22.5 | انقضای داده موقت: Holdها، Idempotency Keys (90 روز)، Local Draftها. | M |

---

## 4. نیازمندی‌های غیرعملکردی

### 4.1 امنیت

| ID | نیازمندی |
|---|---|
| NFR-SEC-1 | HTTPS اجباری (HSTS). TLS ≥ 1.2. |
| NFR-SEC-2 | Least Privilege: هر Endpoint Capability Check + Ownership/Data-Access Check جدا از Authentication. |
| NFR-SEC-3 | تمام SQL با Prepared Statements (Query Builder/WordPress DB API) — هیچ Interpolation. |
| NFR-SEC-4 | Sanitization ورودی + Escaping خروجی؛ Rich Text فقط با Whitelist تگ (WP KSES). |
| NFR-SEC-5 | CSRF: Nonce (`X-WP-Nonce`) روی مутانت‌های Authenticated؛ Same-Site Cookie. |
| NFR-SEC-6 | Rate Limit: OTP، Booking، Login، Upload (Lایه Middleware متمرکز). |
| NFR-SEC-7 | Secrets (App Key، Pepper، SMS Key) خارج از Git؛ از Environment/Config امن. |
| NFR-SEC-8 | رمزنگاری: فقط الگوریتم‌های استاندارد (AES-256-GCM برای رمزنگاری داده؛ SHA-256/HMAC) — **هیچ Custom Crypto**. |
| NFR-SEC-9 | Session Hijacking: Cookie Secure/HttpOnly/SameSite=Lax، Site Binding، مهلت و Invalidation. |
| NFR-SEC-10 | API Error Response بدون افشای جزئیات داخلی (Stack/SQL). |
| NFR-SEC-11 | Dependency Updates + آفلاین‌کردن Endpointهای غیرضروری (XMLRPC غیرفعال در Production). |
| NFR-SEC-12 | Security Review قبل از Production (فاز 13) شامل:渗透(تست نفوذ) محدود، Review Threat Model، Checklist. |

### 4.2 عملکرد (Performance)

| ID | نیازمندی |
|---|---|
| NFR-PERF-1 | P95 پاسخ API عمومی (تقویم، داشبورد) < 500ms در 50 کاربر هم‌زمان (سرور مرجع: 4 vCPU/8GB). |
| NFR-PERF-2 | رندر صف و داشبوردها بدون N+1 (Join/Batch/ eager load). |
| NFR-PERF-3 | Pagination اجباری روی لیست‌ها (حداکثر 200/صفحه) + Index مناسب (مطابق ERD). |
| NFR-PERF-4 | ذخیره Handwriting: یک Save صفحه ≈ یک UPDATE (نه N Insert)؛ JSON فشرده. |
| NFR-PERF-5 | Jobهای سنگین (OCR، Export، Backup) Async — بدون Block درخواست کاربر. |
| NFR-PERF-6 | Caching فقط برای داده‌های غیر-PHI (تقویم آزاد) و با Key نام‌برده + TTL کوتاه + **هرگز** روی پاسخ‌های شامل داده بیمار مشخص. |

### 4.3 دسترس‌پذیری و قابلیت اعتماد

| ID | نیازمندی |
|---|---|
| NFR-AV-1 | RPO ≤ 24h، RTO ≤ 4h (V1) — جزئیات: docs/backup/backup-recovery.md. |
| NFR-AV-2 | Jobها Idempotent و Retryable؛ شکست Job → Alert در Operational Log. |
| NFR-AV-3 | Transaction روی عملیات حیاتی (رزرو، Payment، Complete Visit، Checkout). |
| NFR-AV-4 | هر صفحه داشبورد باید با اتصال ضعیف/قطع قابل بارگذاری باشد (داده Cache‌شده + پیام Sync). |

### 4.4 گسترش‌پذیری
| ID | نیازمندی |
|---|---|
| NFR-SCA-1 | `clinic_id` روی همه جداول پزشکی (تک-شعبه V1، چند-شعبه V2). |
| NFR-SCA-2 | `clinician_id` روی Schedule/Slot/Appointment/Visit (چند-پزشک). |
| NFR-SCA-3 | Adapterها (SMS، OCR، Realtime Transport، JobQueue) با Interface — تعویض بدون تغییر Domain. |
| NFR-SCA-4 | Room، Service، Insurance، Lab: فیلد/جدول آماده در معماری (نه فعال). |

### 4.5 UI/i18n/دسترس‌پذیری
| ID | نیازمندی |
|---|---|
| NFR-UI-1 | RTL کامل + فونت فارسی + اعداد/تاریخ Jalali در UI. |
| NFR-UI-2 | Three Dashboard جدا (بیمار/منشی/پزشک) — wp-admin محیط کار روزمره نیست. |
| NFR-UI-3 | Accessibility: Keyboard Nav، کنتراست AA، Touch Target ≥ 44px، پیام خطای واضح. |
| NFR-UI-4 | i18n-ready (fa پیش‌فرض، en آتی). |

### 4.6 نگهداری و ساختار کد
| ID | نیازمندی |
|---|---|
| NFR-MAINT-1 | معماری لایه‌ای: Domain / Application(Service) / Infrastructure(Repository, Gateway) / REST / Authorization / Frontend / Jobs (docs/adr/ADR-0001). |
| NFR-MAINT-2 | Business Logic در Template ممنوع؛ DB Access فقط در Repository. |
| NFR-MAINT-3 | PSR-12، Prefix namespaces `ClinicCore\`، Table prefix `cpms_`. |
| NFR-MAINT-4 | Migration System با版本号 (Schema Version) + Backup قبل از Migration حساس. |
| NFR-MAINT-5 | پوشش تست (تصمیم فاز 10): هسته Business Rules ≥ 80% Branch Coverage. |

---

## 5. رابط‌ها

### 5.1 کاربری
- عمومی: صفحه «دریافت نوبت» + «ورود به حساب» (Section 3 Master Prompt).
- بیمار: Dashboard موبایل‌محور. منشی: Dashboard دسکتاپ. پزشک: Dashboard تبلت.

### 5.2 خارجی
| سیستم | جهت | توضیح |
|---|---|---|
| SMS Gateway | خروجی | Interface `SmsGateway::send(to, template)`؛ Provider از تنظیمات؛ Retry در Job. |
| OCR Provider | خروجی | Interface `HandwritingRecognizer::recognize(pagePayload)`؛ فقط تصویر (بدون PII)؛ V1.5. |
| (آینده) POS/بانک | دوطرفه | Webhook Payment + Reconciliation. |

### 5.3 با WordPress
- کاربران/نقش‌ها/قابلیت‌ها، Session/Cookie، Cron (tick Jobها — با Cron OS-level مکمل)، WP REST API plumbing.

---

## 6. Edge Cases و Recovery (ER-x)

| ID | سناریو | Recovery |
|---|---|---|
| ER-01 | OTP منقضی شد | پیام + دکمه «ارسال مجدد» (Cooldown)؛ شمارش تلاش باقی‌مانده. |
| ER-02 | SMS ارسال نشد | Retry Job (3 بار/Backoff) + Operational Log + Alert؛ پیام خطا به کاربر با Retry. |
| ER-03 | اینترنت بیمار وسط رزرو قطع شد | Hold با TTL باقی می‌ماند؛ بازگشت → «ادامه نوبت» (token) یا آزادسازی خودکار. |
| ER-04 | Slot هم‌زمان رزرو شد | UPDATE شرطی اتمیک → یکی موفق؛ دیگری `CLINIC_SLOT_TAKEN` + پیشنهاد Slot جایگزین. |
| ER-05 | Double Submit رزرو | Idempotency-Key + Hold token → یک Appointment. |
| ER-06 | بیمار دیررس | Grace Period → بعد از آن no-show (خودکار/دستی)؛ مراجعه بعدی = Visit فوری با ارجاع. |
| ER-07 | بیمار نرسید | no-show + آزادسازی؛ گزارش No-Show؛ (سیاست جریمه: C). |
| ER-08 | مراجعه بدون نوبت | Walk-in Visit (فرایند منشی). |
| ER-09 | Call اشتباه بیمار | Recall (called→waiting) یا Skip با دلیل؛ Audit؛ اعلان اصلاح به منشی. |
| ER-10 | Check-In اشتباه | Visit→cancelled (دلیل+Audit) + Check-In مجدد. |
| ER-11 | Complete اشتباه | بازگشت در مجوز بالا + دلیل + Audit (FR-8.8). |
| ER-12 | Payment اشتباه | Void (همان روز، مجوز، دلیل) یا Amendment؛ حذف تراکنش ممنوع. |
| ER-13 | OCR دارو اشتباه تشخیص داد | متن تا تأیید پزشک نامعتبر؛ نسخه فقط از فیلدهای تأیید‌شده ساخته می‌شود. |
| ER-14 | تبلت وسط نوشتن قطع شد | IndexedDB Local + Sync Status + Retry + Merge؛ **از دست رفتن داده غیرقابل‌قبول است**. |
| ER-15 | Auto-save دست‌خط Fail شد | نوبت بعدی Retry + وضعیت `Sync Failed` + نگهداری Local تا موفقیت. |
| ER-16 | فایل نامعتبر آپلود شد | رد با کد `CLINIC_FILE_INVALID` (MIME/اندازه/تصویر) + Log (نام اصلی بدون محتوا). |
| ER-17 | ID بیمار دیگر در API | 403/404 استاندارد + Audit `FORBIDDEN_ACCESS_ATTEMPT` + Rate Limit تشدید. |
| ER-18 | Hold منقضی شد ولی کاربر برمی‌گردد | پیام «زمان رزرو تمام شد» + انتخاب Slot جدید. |
| ER-19 | Payment Double (شبکه تکرار) | Idempotency-Key یکتا + Duplicate Detection روی (invoice, ref, amount). |
| ER-20 | Complete Visit دوبار اجرا شد | State Machine → `CLINIC_INVALID_TRANSITION` + Lock Row (For Update) — یک‌بار فقط. |

---

## 7. سناریوی پذیرش (Acceptance)
دقیقاً مطابق Section 51 Master Prompt — مسیر کامل از رزرو آنلاین تا Dashboard بیمار نهایی + Audit تمام رویدادها. پوشش در Test Plan (TP-01..TP-12).

## 8. خارج از محدوده V1
پرداخت آنلاین، بیمه، Lab Integration، نسخه الکترونیک ملی، Mobile App Native، چند شعبه فعال، جریمه مالی لغو، گزارش‌های تحلیلی پیشرفته، 2FA فعال (معماری آماده)، OCR فعال (معماری آماده)، Push Notification.
