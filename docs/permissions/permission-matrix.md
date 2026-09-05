# Permission Matrix — CPMS

نسخه 1.4 | 2026-09-06 | فاز 2 (تصمیم F1-D2) + **F2.5**: `cpms_sms_config` (ADR-0025) + **F5 هم‌ترازی** (تفکیک سطر Patient §3) + **ADR-0026**: نقش‌های پویا + Scope + Capهای حساس (P-9..P-12، §6)

## 1. اصول

- **P-1** `Authorization` در سمت Server روی هر Endpoint (Backend Enforcement — نه Frontend).
- **P-2** نقش‌های WordPress: `cpms_patient`، `cpms_secretary`، `cpms_doctor` (+ `administrator` وردپرس).
- **P-3** **Administrator وردپرس به‌طور پیش‌فرض هیچ دسترسی پزشکی ندارد.** Medical Access باید **Explicit** باشد (اعطای Capability به کاربر خاص، نه نقش).
- **P-4** Naming Convention ثابت و Namespaced: **`cpms_{resource}_{action}`** — یک Capability کلی (مثل `manage_clinic`) **ممنوع** است.
- **P-5** Patient فقط روی داده‌های **خودش** (Ownership Check با `cpms_patient_user_links`) — بدون نیاز به Capability.
- **P-6** `doctor_private` در سطح Query فیلتر می‌شود + Capability مجزا (دو لایه: Capability → Data-Access → Field/Row Filter).
- **P-7** هر عدم مجوز → 403/404 + Audit `FORBIDDEN_ACCESS_ATTEMPT`.
- **P-8** Ownership/Resource-Level Authorization **علاوه بر** WordPress Capability بررسی می‌شود (دو لایه مستقل).
- **P-9** (ADR-0026) **نام نقش هرگز مکانیزم Authorization نیست** — تصمیم فقط بر پایه Capability (+ Scope در V2). نقش‌های سفارشی ستادی (دستیار/حسابدار/مدیر مطب/…) با هر زیرمجموعه `cpms_*` معتبرند؛ نقش‌های V1 فقط Template پیش‌فرض‌اند.
- **P-10** (ADR-0026) حوزه‌های دسترسی **مستقل**اند: مالی ⊥ بالینی ⊥ هویت/دموگرافیک ⊥ یادداشت خصوصی ⊥ فایل. Cap مالی هرگز Cap بالینی را ضمنی نمی‌دهد و بالعکس.
- **P-11** (ADR-0026) Capهای **حساس** هرگز در Template عمومی «راحت» قرار نمی‌گیرند و در UI مدیریت نقش (V2) با هشدار جدا نمایش داده می‌شوند: `cpms_private_note_*`، `cpms_export`، `cpms_audit_read`، `cpms_payment_void`، `cpms_payment_refund`، `cpms_invoice_void`، `cpms_invoice_adjust`، `cpms_patient_archive`، `cpms_patient_merge`، `cpms_consult_reopen`، `cpms_config`، `cpms_sms_config` (+ آینده: Cap مدیریت نقش).
- **P-12** (ADR-0026) **Scope منبع** (V2): `OWN`/`ASSIGNED_DOCTORS`/`BRANCH`/`CLINIC`/`ALL_ALLOWED` — بعد از Capability و قبل از Data-Access ارزیابی می‌شود؛ تا V2 عملیاتی = CLINIC (`clinic_id=1`، ADR-0003).

## 2. فهرست نهایی Capability Slugها (تأییدشده 2026-09-05)

### Patient / Profile
| Slug | عمل |
|---|---|
| `cpms_patient_read` | خواندن پروفایل/پرونده بیمار |
| `cpms_patient_create` | ساخت بیمار جدید |
| `cpms_patient_update` | ویرایش فیلدهای مجاز |
| `cpms_patient_archive` | آرشیو (حساس؛ بدون نقش پیش‌فرض — اعطای موردی) |
| `cpms_patient_merge` | ادغام رکورد تکراری (حساس؛ V1.5) |

### Appointment
| Slug | عمل |
|---|---|
| `cpms_appt_read` | خواندن نوبت‌ها |
| `cpms_appt_create` | ساخت نوبت (حضوری/فوری) |
| `cpms_appt_confirm` | تأیید نوبت `pending` |
| `cpms_appt_cancel` | لغو نوبت (با دلیل/Policy) |
| `cpms_appt_reschedule` | جابه‌جایی نوبت |
| `cpms_appt_no_show` | علامت no-show |

### Visit / Queue
| Slug | عمل |
|---|---|
| `cpms_visit_read` | خواندن داده ویزیت/صف (غیر بالینی) |
| `cpms_queue_read` | خواندن صف/داشبورد امروز |
| `cpms_queue_checkin` | Check-in / Walk-in |
| `cpms_queue_advance` | تغییر وضعیت مجاز صف (enqueue/cancel visit) |
| `cpms_queue_call` | فراخوان/بازگشت/رد بیمار (پزشک) |
| `cpms_queue_checkout` | خروج نهایی بیمار |

### Consultation
| Slug | عمل |
|---|---|
| `cpms_consult_start` | شروع ویزیت (called→in_consultation) |
| `cpms_consult_complete` | پایان ویزیت (یک‌بار) |
| `cpms_consult_reopen` | بازگشت Complete اشتباه (حساس، با دلیل) |

### Clinical Record
| Slug | عمل |
|---|---|
| `cpms_medical_read` | خواندن پرونده کامل (بیماری) — **Explicit برای Admin** |
| `cpms_note_create` | ساخت یادداشت (هر visibility مجاز برای نقش) |
| `cpms_note_update` | ویرایش/Correction یادداشت |
| `cpms_rec_create` | ثبت توصیه/Follow-up |

### Private Clinical Note (جدا از یادداشت عمومی)
| Slug | عمل |
|---|---|
| `cpms_private_note_read` | خواندن `doctor_private` — **فقط پزشک** |
| `cpms_private_note_create` | ساخت `doctor_private` |
| `cpms_private_note_update` | ویرایش `doctor_private` |

### Prescription
| Slug | عمل |
|---|---|
| `cpms_rx_read` | خواندن نسخه‌ها |
| `cpms_rx_create` | ساخت/نهایی‌سازی نسخه |
| `cpms_rx_void` | ابطال نسخه (حساس، با دلیل) |

### Medical Attachment
| Slug | عمل |
|---|---|
| `cpms_file_upload` | آپلود فایل |
| `cpms_file_read` | خواندن/دانلود (فیلتر visibility) |

### Invoice
| Slug | عمل |
|---|---|
| `cpms_invoice_read` | خواندن فاکتور |
| `cpms_invoice_create` | صدور فاکتور |
| `cpms_invoice_adjust` | اصلاح Credit/Debit (حساس) |
| `cpms_invoice_void` | ابطال فاکتور (حساس؛ فقط بدون پرداخت) |

### Payment (ثبت عادی جدا از عملیات حساس)
| Slug | عمل |
|---|---|
| `cpms_payment_create` | ثبت پرداخت عادی (Cash/POS/...) |
| `cpms_payment_void` | ابطال پرداخت (حساس؛ جدا از ثبت عادی) |
| `cpms_payment_refund` | بازپرداخت (حساس؛ جدا از ثبت عادی) |

### Finance / Reports / Export / Audit
| Slug | عمل |
|---|---|
| `cpms_finance_read` | خلاصه مالی (Revenue، Open Balances) |
| `cpms_report_read` | گزارش‌ها (جدا از Export) |
| `cpms_export` | خروجی داده حساس (جدا؛ Audit + Watermark) |
| `cpms_audit_read` | مشاهده Audit Log (جدا؛ Explicit) |

### Search / Settings / SMS
| Slug | عمل |
|---|---|
| `cpms_search` | جستجوی جامع (Role-Aware) |
| `cpms_config` | تنظیمات سیستم (فنی؛ **جدا از عملیات روزانه**) |
| `cpms_sms_config` | **تنظیمات/تست/Log پیامک** (فنی؛ ADR-0025 — جدا از `cpms_config` به‌منظور Least Privilege) |

**مجموع: 46 Capability.** (پیاده‌سازی: `src/Auth/RolesAndCapabilities.php`)

## 3. نقش‌ها → Capabilities (V1)

| Capability | بیمار | منشی | پزشک | Admin (WP) |
|---|:-:|:-:|:-:|:-:|
| cpms_patient_read / update | — | ✅ | ✅ | ❌ |
| cpms_patient_create | — | ✅ | ❌ | ❌ |
| cpms_patient_archive / merge | — | ❌ | ❌ | ❌ (اعطای موردی) |
| cpms_appt_read / create | — | ✅ | ✅ | ❌ |
| cpms_appt_confirm / cancel / reschedule / no_show | — | ✅ | ✅ | ❌ |
| cpms_visit_read | — | ✅ | ✅ | ❌ |
| cpms_queue_read | — | ✅ | ✅ | ❌ |
| cpms_queue_checkin / advance / checkout | — | ✅ | ❌ | ❌ |
| cpms_queue_call | — | ❌ | ✅ | ❌ |
| cpms_consult_start / complete / reopen | — | ❌ | ✅ (reopen با دلیل) | ❌ |
| cpms_medical_read | — | ❌ | ✅ | ❌ **مگر Explicit** |
| cpms_note_create / update | — | ❌ | ✅ | ❌ |
| cpms_rec_create | — | ❌ | ✅ | ❌ |
| cpms_private_note_read / create / update | — | ❌ | ✅ | ❌ |
| cpms_rx_read / create / void | — | ❌ | ✅ | ❌ |
| cpms_file_upload / read | (خودش) | ✅ | ✅ | ❌ |
| cpms_invoice_read / create | — | ✅ | ✅ | ❌ |
| cpms_invoice_adjust / void | — | ✅ | ❌ | ❌ |
| cpms_payment_create | — | ✅ | ❌ | ❌ |
| cpms_payment_void / refund | — | ✅ | ✅ | ❌ |
| cpms_finance_read | — | ✅ | ✅ | ❌ |
| cpms_report_read | — | ❌ | ✅ | ❌ |
| cpms_export | — | ❌ | ❌ | ❌ (اعطای موردی) |
| cpms_audit_read | — | ❌ | ❌ | ❌ **مگر Explicit** |
| cpms_search | — | ✅ | ✅ | ❌ |
| cpms_config | — | ❌ | ❌ | ✅ |
| cpms_sms_config | — | ❌ | ❌ | ✅ |

> **بیمار:** بدون Capability — دسترسی فقط از طریق Ownership (`cpms_patient_user_links`) و فیلتر `patient_visible`.
> **Admin:** فقط `cpms_config` + `cpms_sms_config` (فنی). `cpms_medical_read`/`cpms_audit_read`/`cpms_export` فقط با اعطای صریح به کاربر.
> **منشی/پزشک:** `cpms_sms_config` ندارند (در صورت نیاز به مدیریت پنل، اعطای موردی به کاربر خاص).
> نقش پنجم `clinic_manager` (مدیر مطب) به‌عنوان گسترش آماده — بدون تغییر مدل.
>
> **یادداشت هم‌ترازی F5 (2026-09-05 — TP-10):** سطر قبلی «`cpms_patient_read / create / update`» به‌صورت گروهی برای پزشک ✅ بود و با **§4.3 (مرجع)** تناقض داشت (Create برای پزشک: ❌؛ Update: «فیلدهای پزشکی» ✅). طبق §4.3 + اصل Least Privilege تفکیک شد: `cpms_patient_update` برای پزشک ✅ (الزام FR-8.1 — ویرایش فیلدهای پزشکی پروفایل در صفحه ویزیت)، `cpms_patient_create` فقط منشی. کد (`DOCTOR_CAPS`) هم مطابق همین هم‌تراز شد؛ تست TP-10 (`tests/Integration/PermissionMatrixTest.php`) از این پس تطابق سند↔کد را قفل می‌کند. اگر کارفرما Create برای پزشک را بخواهد، هر دو (سند+کد) با هم تغییر می‌کنند.

## 4. ماتریس Resource × نقش (مرجع)

> R=Read، C=Create، U=Update، D=Delete(Soft/Void)، E=Export. «Self»=فقط رکورد خودش.

### 4.1 Patient
| Resource | R | C | U | D | E |
|---|---|---|---|---|---|
| Patient Profile (خودش) | Self | — | Self (فیلدهای مجاز) | — | — |
| Appointment (خودش) | Self | Self (آنلاین) | Self (لغو/جابه‌جا در Policy 12h) | Self (cancel) | — |
| Visit (خودش) | Self (فقط `patient_visible`) | — | — | — | — |
| Doctor Private Note | **—** | — | — | — | — |
| Patient Visible Note | Self | — | — | — | — |
| Prescription | Self | — | — | — | Self (PDF) |
| Recommendation/Follow-Up | Self | — | — | — | — |
| Attachment | Self (مجاز) | Self (مجاز) | — | — | Self |
| Invoice/Receipt | Self (اگر Setting فعال) | — | — | — | Self |
| Audit / Settings | **—** | — | — | — | — |

### 4.2 Secretary
| Resource | R | C | U | D | E |
|---|---|---|---|---|---|
| Patient Profile | کل مطب | ✅ | محدود (PHI حساس: ❌) | — (Archive: ❌) | ❌ (با `cpms_export` اگر داده شود) |
| Appointment | روز کاری | حضوری/فوری | لغو/جابه‌جا/تأیید/no-show | Void (لغو+دلیل) | ❌ |
| Visit/Queue | روز | Check-in/Walk-in | Transitionهای مجاز | Cancel (دلیل+Audit) | ❌ |
| Doctor Private Note | **—** | — | — | — | — |
| Patient Visible Note | ✅ | — | — | — | — |
| Prescription | **—** (V1) | — | — | — | — |
| Recommendation/Follow-Up | ✅ (کار روز) | — | — | — | — |
| Attachment | `patient_visible` + اپلودهای خودش | ✅ | — | Soft (با مجوز) | ❌ |
| Invoice | ✅ | ✅ | Adjust/Discount | Void (دلیل+مجوز) | ❌ |
| Payment | ✅ | ✅ (Cash/POS) | — | Void/Refund (مجوز+دلیل) | ❌ |
| Audit | **—** | — | — | — | — |
| Schedule/Settings | خواندن برنامه | استثنائات روزانه | — | — | — |

### 4.3 Doctor
| Resource | R | C | U | D | E |
|---|---|---|---|---|---|
| Patient Profile | کل مطب (V1) | — | فیلدهای پزشکی | — | با مجوز |
| Appointment | ✅ | (فوری) | تأیید/لغو/جابه‌جا/no-show | Void (دلیل) | با مجوز |
| Visit | ✅ | — | بالینی (call/start/complete/reopen) | — | با مجوز |
| Doctor Private Note | ✅ (کل مطب V1) | ✅ | ✅ (خودش + نسخه) | Archive | با مجوز |
| Patient Visible Note | ✅ | ✅ | ✅ (خودش) | Archive | با مجوز |
| Prescription | ✅ | ✅ (ویزیت خودش) | Correction (نسخه جدید) | Void (دلیل) | با مجوز |
| Recommendation/Follow-Up | ✅ | ✅ | — | — | — |
| Handwriting Document/Page (F7) | ✅ `cpms_medical_read` | ✅ `cpms_note_create` (سند/صفحه/ذخیره) | ✅ ذخیره صفحه فقط با پروتکل Revision (ADR-0014) | — (نسخه‌ها append-only؛ GC خودکار) | با مجوز |
| Attachment | ✅ (هر visibility) | ✅ | — | Soft (با مجوز) | با مجوز |
| Invoice | ✅ | ✅ (ثبت خدمات) | — | ❌ | با مجوز |
| Payment | مبلغ | ❌ (ثبت عادی با منشی) | — | Void/Refund (با مجوز) | با مجوز |
| Audit | ❌ (مگر `cpms_audit_read` صریح) | — | — | — | — |
| Schedule/Settings | برنامه خودش | برنامه خودش | استثنائات خودش | — | — |

> **دست‌خط (F7 — 2026-09-06):** همه عملیات دست‌خط (ایجاد سند/صفحه، ذخیره، خواندن) علاوه بر Capability فقط روی **ویزیت خودِ پزشک** (clinician متصل به wp_user — الگوی §4.3) مجاز است؛ نقض → 404 + `FORBIDDEN_ACCESS_ATTEMPT`. منشی/بیمار هیچ Capability دست‌خط ندارند. Audit: `HW_DOC_CREATE` / `HW_PAGE_ADD` / `HW_PAGE_SAVE` (+ meta تضاد/`conflict_reason`).

### 4.4 WP Administrator (فنی)
| دسته | پیش‌فرض | با اعطای صریح |
|---|---|---|
| تنظیمات فنی (OTP، Policy، Schedule، خدمات) | ✅ `cpms_config` | — |
| تنظیمات/تست/Log پیامک | ✅ `cpms_sms_config` | — |
| PHI هر نوع | ❌ | ✅ `cpms_medical_read` |
| Audit | ❌ | ✅ `cpms_audit_read` |
| Export حساس | ❌ | ✅ `cpms_export` |
| عملیات بالینی/مالی روزانه | ❌ | ❌ (رنگ `clinic_manager` جدا) |

## 5. آزمون مربوط
- TP-07: IDOR (Patient A → Patient B)
- TP-08: Secretary/Patient هرگز `doctor_private` (Query + API + Capability — سه لایه)
- TP-09: Admin بدون Capability صریح → 403 روی PHI/Audit
- TP-10: Matrix Parametrized (تولید خودکار از این فایل)
- TP-11: Audit Integrity

## 6. نقش‌های پویا، Scope و گزارشگری (ADR-0026 — تعریف، پیاده‌سازی فاز V2)

| موضوع | تعریف |
|---|---|
| نقش سفارشی | هر زیرمجموعه از ۴۶ Capability بالا (مثال «حسابدار»: `invoice_read`+`payment_create`+`finance_read`، بدون هیچ Cap بالینی؛ مثال «دستیار»: `patient_read`+`appt_*`+`queue_*`، بدون `medical_read`) |
| Templateها | Doctor/Secretary/Assistant/Accountant/Clinic Manager = بسته‌های پیش‌نهادی UI (نرمال‌کننده نیستند)؛ شروع از Clone و سپس تنظیم مجاز است |
| عملیات نقش (V2) | Create/Rename/Assign-permissions/Modify/Assign-to-user/Archive (ایمن، بدون یتیم‌کردن کاربر یا اعطای خاموش)؛ نقش‌های سیستم حساس محافظت‌شده |
| Scope (V2) | `OWN` (پزشک: درآمد/ویزیت‌های خودش) / `ASSIGNED_DOCTORS` / `BRANCH` / `CLINIC` / `ALL_ALLOWED` — Enforcement در Backend روی `clinic_id`/`clinician_id` موجود؛ فیلتر Frontend ملاک نیست |
| گزارش مالی (F8/V2) | per-doctor (درآمد/ویزیت/باقیمانده)، per-service، بازه‌ای، Methods، Refund/Void — تفکیک **Aggregate** (مدیر: جمع مطب + تفکیک پزشک، بدون پرونده بیمار) از **Detail** (نیازمند Cap جزئیات) |
| Escalation | اعطای Cap فقط توسط `cpms_config`-دار و فقط زیرمجموعه Capهای خودش (Server-side) |
| Audit نقش | `ROLE_CREATED`/`ROLE_UPDATED`/`ROLE_ARCHIVED`/`ROLE_ASSIGNED`/`ROLE_REMOVED`/`PERMISSION_GRANTED`/`PERMISSION_REVOKED`/`STAFF_SCOPE_CHANGED` (before/after بدون PHI) |
| 2FA | Privileged = دارنده هر Cap حساس (P-11) — ADR-0020 (Access-based، نه نام نقش) |

> **وضعیت کد (بررسی ADR-0026، 2026-09-06):** لایه REST و سرویس‌های مالی (F6) کاملاً Capability-محورند؛ Debt نقش-محور باقی‌مانده (بازیگر ماشین Visit/Clinical + `requireRole` بالینی + انشعاب visibility فایل) و نقشه مهاجرت آن در ADR-0026 ثبت شد. Blocker معماری وجود ندارد.
