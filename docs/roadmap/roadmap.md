# نقشه راه توسعه — CPMS

نسخه 1.0 | 2026-09-05 | فاز 8 | پیش‌فرض: تیم 2 (1 PHP/WP Senior + 1 Frontend)؛ زمان‌بندی تقریبی

> **قانون Gate (Section 56):** هر فاز فقط بعد از تأیید خروجی+Acceptance Criteria فاز قبل.

## فازها

| فاز | محتوا | خروجی/DoD | تخمین |
|---|---|---|---|
| **F0** (حاضر) | مستندات Phase 1–8 (این بسته) | تأیید کارفرما | 1 هفته (مسلم) |
| **F1** | Core Architecture: Skeleton افزونه، لایه‌ها، Migration System + مigrations اولیه، Roles/Capabilities، Settings، Audit/Operational Base، Rate Limit/Idempotency Middleware، Job Queue + Dispatcher، CI | تست Migration + Audit + Queue سبز | 2 هفته |
| **F2** ✅ | احراز هویت: OTP کامل، Session/Security، Patient User Links، Rate Limit (157/232 سبز؛ + 4 تصمیم کارفرما D1–D4) | TP-04, TP-05, TP-17 | 1.5 هفته |
| **F2.5** ✅ | ماژول پیامک Provider-Agnostic (ADR-0025 — تأییدشده): Settings/Templates/Test/Log/Balance + Generic API (SSRF) + Vault + Queue SMS (187/346 سبز) | SmsFlowTest (CI) + 30 تست Unit | 1 هفته |
| **F3** ✅ کامل شد — CI سبز (گزارش: [report-f3.md](phase-reports/report-f3.md)) | نوبت‌دهی: Schedule/Exceptions، Slot generation، Hold/Claim، Booking API (A/B)، Patient Profile CRUD — **Availability UI = تصمیم محصول (گزارش §5-1)** | TP-03, TP-14, TP-15, TP-20 + سناریوی رزرو E2E | 2.5 هفته |
| **F4** ✅ کامل شد — CI سبز (گزارش: [report-f4.md](phase-reports/report-f4.md)) | مراجعه/صف: Check-in/Walk-in، Queue State Machine + History، Real-time Polling، داشبورد منشی (امروز/Drawer/Walk-in/Keyboard) | TP-19 + TP-03b + TP-07 | 2.5 هفته |
| **F5** | بالینی: صفحه ویزیت، Notes+Versions، Prescriptions، Recommendations، Follow-ups، Complete/Reopen، File Upload/Stream، داشبورد پزشک (امروز/صف/Call) | TP-06, TP-08, TP-10 | 3 هفته |
| **F6** | مالی: Services، Invoice/Payment/Adjustment/Void/Refund، Receipt، داشبورد مالی منشی، Checkout Flow | TP-02, TP-18 + TP-01 (بخش مالی) | 2 هفته |
| **F7** | دست‌خط: Canvas کامل (Pressure/Tools/Zoom/Full-screen/Multi-page/Template)، Stroke Storage، Auto-save + **Offline Sync** (IndexedDB) + Conflict | TP-12 | 2.5 هفته |
| **F8** | اعلان + گزارش: Notification Layer کامل + Templates (Jalali)، 12 گزارش + Export (Watermark/Audit) | TP-13 + Report Tests | 1.5 هفته |
| **F9** | Hardening: Security Review (تهدیدها T-01..T-24)، Performance (NFR-PERF-1)، Backup/Restore Test (TP-16)، Accessibility Pass، مستندات کاربری، Pilot | Security Checklist امضا + TP-16 + DoD V1 | 2 هفته |
| **V1.5** | OCR (انتخاب Provider + Acceptance Test فارسی)، 2FA، Merge UI، ClamAV/Encryption (تصمیم R-06) | TP-OCR + 2FA Tests | 3–4 هفته |
| **V2** | Multi-clinic/Team، Online Payment، Insurance/Lab، Push، Mobile API (JWT) | — | بر اساس نیاز |

## مایلستون‌ها
- **M1 (پایان F3):** بیمار واقعی می‌تواند آنلاین نوبت بگیرد (به‌صورت داخلی).
- **M2 (پایان F5+F6):** چرخه کامل مطب بدون OCR (منشی→پزشک→مالی) — **Pilot داخلی**.
- **M3 (پایان F9):** **Go-Live V1** + بکاپ/DR تست‌شده.

## ریسک‌های برنامه (و مهارت)
| ریسک | مهارت |
|---|---|
| دقت OCR فارسی (V1.5) | Provider Selection با Acceptance Test واقعی (R-04) — OCR در V1 نیست |
| دست‌خط روی iPad/Samsung (Pointer/Pressure) | Spike در ابتدای F7 (2 روز) روی 2 دستگاه واقعی |
| SMS Provider نامناسب | Adapter + 2 Provider در F2 (تصمیم R-01) |
| حجم استوری Stroke | ADR-0009 + Load Test نمونه (F7) |
