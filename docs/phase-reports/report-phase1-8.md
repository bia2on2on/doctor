# گزارش فاز 1–8 — CPMS

تاریخ: 2026-09-05 | وضعیت: **متوقف — در انتظار تأیید کارفرما** | بعد از تأیید: **F1 (Core Architecture & Migrations)**

---

## 1. خروجی کامل فازها

| فاز | فایل(ها) |
|---|---|
| 1 — SRS + Use Cases | srs/SRS.md, srs/use-cases.md |
| 2 — Permission Matrix | permissions/permission-matrix.md |
| 3 — State Machines | state-machines/{appointment,visit-queue,payment}.md |
| 4 — ERD + Data Dictionary + Constraints | erd/erd.md, erd/data-dictionary.md |
| 5 — API Contract + AuthN/AuthZ | api/api-contract.md, security/auth-authorization.md |
| 6 — Wireframes | wireframes/{patient,secretary,doctor}.md |
| 7 — Security & Architecture | security/{threat-model,audit-strategy}.md, architecture/{file-storage,handwriting-storage,handwriting-recognition,notifications,background-jobs}.md, backup/backup-recovery.md |
| 8 — Scope/Testing/Roadmap | scope/mvp-scope.md, testing/testing-plan.md, roadmap/roadmap.md |
| ADR | adr/ADR-0001 … ADR-0015 |

## 2. تصمیمات معماری کلیدی (خلاصه ADRها)
1. **لایه‌بندی DDD** (`ClinicCore\`) — Domain خالص بدون WP (ADR-0001).
2. **Admin فنی ≠ دسترسی پزشکی** — Capability صریح به کاربر (ADR-0002).
3. **`clinic_id`/`clinician_id` از روز اول** (ADR-0003).
4. **Slot مادی + Claim اتمیک + Hold 10 دقیقه** — ضد Double-Booking در DB (ADR-0004).
5. **Appointment ≠ Visit** — Nullable دوطرفه (ADR-0005).
6. **OTP Hash-only** + Limits (ADR-0006).
7. **Real-time V1 = Controlled Polling** با Transport قابل تعویض (ADR-0007).
8. **Audit Append-only + Hash Chain** + Operational جدا (ADR-0008).
9. **دست‌خط: یک صفحه = یک Row (JSON فشرده Strokeها)** — نه Row به‌ازای هر نقطه (ADR-0009).
10. **OCR: Provider قابل تعویض + تأیید پزشک اجباری** (ADR-0010).
11. **PK BIGINT + Reference Code خوانا** (ADR-0011).
12. **FK فیزیکی برای حیاتی / منطقی برای High-Volume** (ADR-0012).
13. **UTC ذخیره / Jalali نمایش** (ADR-0013).
14. **Offline دست‌خط: IndexedDB + Sync State + Conflict Policy** (ADR-0014).
15. **Merge بیمار: Soft + نگاشت + بدون حذف** (ADR-0015).

## 3. فرض‌های انجام‌شده (مهم‌ها)
- A-1: تک‌شعبه/تک‌پزشک در V1 (معماری آماده).
- A-2: دروازه SMS با API REST موجود (انتخاب کارفرما).
- A-3: ظرفیت پیش‌فرض Slot = 1.
- A-4: OCR در V1.5 + ارزیابی دقت فارسی روی نمونه واقعی.
- A-5: در V1 پزشکان به Private Notes هم‌مطب دسترسی دارند.
- A-6: Room در V1 فیلد اختیاری.
- A-7: فاکتورسازی V1 دستی از Services.
- RPO 24h / RTO 4h برای V1 (قابل سخت‌تر کردن — R-05).

## 4. موارد مبهم (نیازمند شفاف‌سازی)
1. **جریمه مالی لغو/No-Show:** در V1 فقط محدودیت زمانی — آیا در آینده مبلغی هم لازم است؟
2. **نمایش Invoice به بیمار:** پیش‌فرض Xیر (Setting) — آیا کارفرما در V1 می‌خواهد بیمار فاکتور/رسید را ببیند؟
3. **نسخه به منشی:** در V1 منشی نسخه را **نمی‌بیند** (Matrix) — آیا برای «تحویل نسخه حضوری/چاپ» خواندن لازم است؟
4. **دسترسی Doctor به بیماران سایر پزشکان (V1):** فعلاً کل مطب (A-5) — تأیید می‌کنید؟
5. **تعداد Slot/ظرفیت چندتایی:** آیا هرگز Slot با ظرفیت >1 (مثلاً ویزیت گروهی) لازم است؟ (معماری پشتیبانی می‌کند.)
6. **دستگاه دقیق پزشک** (iPad چه نسل/کدام مرورگر؛ Samsung چه مدل) — برای Spike F7.

## 5. ریسک‌های شناسایی‌شده
| R# | ریسک | احتمال | اثر | مهارت |
|---|---|---|---|---|
| R-01 | در دسترس‌نبودن/ضعف SMS Provider | M | H | Adapter + پشتیبانی 2 Provider (F2) + Alert (ER-02) |
| R-02 | دقت OCR فارسی پایین (نام دارو/عدد) | H | M | Human-in-the-loop اجباری (ADR-0010) + Acceptance Test (R-04) + OCR خارج V1 |
| R-03 | سازگاری Pointer/Pressure روی همه تبلت‌ها | M | M | Spike 2 روزه در ابتدای F7 روی 2 دستگاه واقعی |
| R-04 | انتخاب Provider OCR بدون نمونه واقعی | M | H | گزارش دقت الزامی پیش از فعال‌سازی |
| R-05 | RPO 24h برای کلینیک پرترافیک کافی نیست | L | H | ریسک‌پذیری کارفرما: binlog/ساعتی (هزینه کم) |
| R-06 | نشت از Local Storage Tablet (T-16) | M | M | حذف Local بعد Sync + Encryption V1.5 + هشدار UI |
| R-07 | تغییر Schedule در میانه ماه → Sync Slotهای آینده | M | M | Job regenerate (ADR-0004 Consequences) + تست TP-15 |
| R-08 | حجم Audit/HHistory در بلندمدت | L | M | آرشیو + Index (docs/erd) + Report Volume در F9 |

## 6. موارد نیازمند تصمیم کارفرما
| # | تصمیم | پیش‌فرض فعلی |
|---|---|---|
| D1 | انتخاب **SMS Provider** (و کلیدهای API) | Adapter آماده؛ بدون انتخاب |
| D2 | **RPO/RTO** نهایی | 24h/4h |
| D3 | نمایش **Invoice/Receipt به بیمار** در V1 | خیر (Setting) |
| D4 | مجوز **خواندن نسخه برای منشی** | خیر (V1) |
| D5 | **دسترسی بین‌پزشکی** (Private Notes/پرونده) | کل مطب (V1) |
| D6 | **رمزنگاری At-rest فایل‌ها** (هر-فایل AES-GCM) | توصیه می‌شود؛ Setting |
| D7 | **Retention** نهایی (Audit 10 سال / پرونده 15 سال) — تابع قانون محل | پیش‌فرض‌های SRS |
| D8 | **دستگاه تبلت پزشک** (مدل دقیق) | — |
| D9 | **ظرفیت >1** برای Slotها | خیر (پشتیبانی معماری) |
| D10 | **OCR Provider** (فهرست کاندیدها در F5/V1.5) | — |

## 7. Acceptance Criteria این بسته (فاز 1–8)

| # | معیار | وضعیت |
|---|---|---|
| AC-1 | هر Section Master Prompt (1–53) یا پوشش‌شده در SRS (FR/NFR/ER) یا در Out-of-Scope صریح | ✅ — نقشه‌برداری در SRS §1.4/§8 |
| AC-2 | Permission Matrix کامل (4 نقش × 10 Resource × R/C/U/D/E) + مدل Capability | ✅ |
| AC-3 | هر State Machine: حالت‌ها، Transitionها (Actor/شرط/Side-effect)، Invariants، کدهای خطا + دیاگرام | ✅ |
| AC-4 | ERD: 36 جدول + FK + Unique + Index + Data Dictionary کامل + Transaction Notes | ✅ |
| AC-5 | API Contract: همه Endpointهای SRS با Auth/Cap/Payload/خطاها + کنواسیون‌ها | ✅ |
| AC-6 | Wireframe سه نقش برای همه صفحه‌های اصلی + الگوهای خطا/خالی | ✅ |
| AC-7 | Threat Model: 24 تهدید با کنترل + تست مربوطه | ✅ |
| AC-8 | Backup/DR با RPO/RTO + فرایند Restore + تست فصلی | ✅ |
| AC-9 | Test Plan: 20 تست الزامی شامل TP-01..TP-10 (سناریوهای Section 50) | ✅ |
| AC-10 | MVP Scope + Roadmap با مایلستون و ریسک برنامه | ✅ |
| AC-11 | هیچ کد اصلی تولید نشده است (طبق Gate Section 56) | ✅ |

## 8. گام بعدی (بعد از تأیید شما)
**F1 — Core Architecture & Migrations:**
Skeleton افزونه (ADR-0001) → Migration System + مigrations 36 جدول → Roles/Capabilities → Settings → Audit/Operational Base → Rate Limit/Idempotency → Job Queue → CI (PHPStan + PHPUnit) — خروجی: TP-13/14/15 سبز.

---
> یادآوری: هر تغییر در تصمیمات فازهای قبل (بعد از شروع F1) نیاز به Impact Analysis + Version مجدد مستند دارد (Section 56).
