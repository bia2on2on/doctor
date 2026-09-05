# محدوده MVP / V1 — CPMS

نسخه 1.0 | 2026-09-05 | فاز 8 | هدف: «کمترین سیستم کامل» که سناریوی Section 51 را **به‌جز OCR** اجرا کند، با معماری آماده برای بقیه.

## 1. داخل محدوده V1 (M)

| Module | موارد |
|---|---|
| هسته | افزونه لایه‌ای، Migration، 36 جدول، Settings، Roles/Capabilities، Audit (Hash Chain)، Operational Log، Jobs + Cron، Rate Limit، Idempotency |
| احراز هویت | Mobile+OTP (کامل: TTL/Attempts/Cooldown/Lock)، Session، (2FA فقط معماری) |
| بیمار | CRUD پروفایل (فیلدهای SRS)، جستجو، Ownership، فیلدهای پزشکی پایه، Merge (API داخلی؛ UI در V1.5) |
| نوبت‌دهی | Schedule هفتگی + Exceptions، Slot generation (lazy+cron)، Hold+Claim (Race-safe)، رزرو آنلاین کامل (بدون Login → OTP)، لغو/جابه‌جا در Policy، نوبت حضوری/فوری، no-show (دستی+خودکار) |
| مراجعه/صف | Check-in، Walk-in، Queue کامل (State Machine)، Call + Recall + Skip، History |
| داشبوردها | بیمار (Mobile-First)، منشی (Desktop/Tablet)، پزشک (Tablet) — همه Front-end (نه wp-admin) |
| ویزیت | صفحه ویزیت یک‌صفحه‌ای، Notes (2 visibility) + Versioning، Prescription (+items)، Recommendations، Follow-up، Complete + Validation + Reopen (Correction) |
| دست‌خط | Canvas (Pointer/Pressure/Undo/Redo/Eraser/Zoom/pan/Full-screen/Multi-page/Template)، ذخیره Stroke Data، Auto-save، **Offline + Sync (IndexedDB)**، Preview PNG — **بدون OCR** |
| فایل | Upload/Stream مجوزیافته (MIME/Size/Extension)، Visibility، Audit دسترسی |
| مالی | Services، Invoice (+items/discount/tax)، Payment (Cash/POS/Other + Online=فقط Field)، Partial، Idempotency، Void/Refund/Adjustment، Receipt (PDF)، Open Balances |
| اعلان | Internal + SMS (Adapter) + Email (اختیاری)، رویدادهای SRS، Real-time Polling |
| گزارش | 12 گزارش SRS §3.19 + Export CSV/PDF (مجوز+Audit+Watermark) |
| UI | RTL، Jalali (UI)، فونت فارسی، Accessibility پایه، Persian Font |
| امن/DR | Threat Model Controls، Backup/Restore (RPO24/RTO4h) + تست فصلی، Security Checklist |
| تست | TP-01..TP-20 (به‌جز OCR/Load) + Coverage Gate |

## 2. خارج از محدوده V1 (معماری آماده، پیاده‌سازی بعد)

| مورد | فاز |
|---|---|
| OCR/تشخیص دست‌خط (فارسی) + Review UI | **V1.5** (معماری + Job + UI Review آماده؛ Provider انتخابی) |
| 2FA TOTP | V1.5 |
| Merge UI + تکراری‌یابی پیشرفته | V1.5 |
| Pay Online + Webhook + Insurance | V2 |
| چند شعبه فعال + چند پزشک تیمی (Data-Scoping) | V2 |
| Lab Integration، نسخه الکترونیک ملی، Mobile App، Push | V2+ |
| Load Testing کامل + Autoscale | V2 |
| ClamAV، Encryption Local/At-rest فایل | V1.5 (تصمیم کارفرما R-06) |
| جریمه مالی لغو/No-Show | C (Setting آماده) |

## 3. Definition of Done — V1
1. TP-01 (سناریوی پذیرش) سبز در CI + اجرای دستی روی مستقیم.
2. TP-02..TP-10، 12..15 سبز.
3. Security Checklist + Threat Model Review امضا.
4. Backup/Restore Test (TP-16) موفق + مستند.
5. Performance: NFR-PERF-1 اندازه‌گیری‌شده (دستورالعمل بار).
6. مستندات: این /docs + راهنمای نصب + راهنمای کاربری (فارسی) سه نقش.
