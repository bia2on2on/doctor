# سیستم مدیریت مطب — Clinical Practice Management System (CPMS)

مستندات معماری و نیازمندی‌ها — نسخه 1.0 — 2026-09-05

> **وضعیت:** Phase 1–8 (مستندات) تأیید شد (2026-09-05) → **F1 (Core & Migrations) انجام و تأیید شد** (157 تست / 232 assertion سبز) → **F2 (احراز هویت OTP) در حال ساخت**

## شاخه‌بندی مستندات (مطابق Section 56 Master Prompt)

| مسیر | محتوا | فاز | وضعیت |
|---|---|---|---|
| [srs/SRS.md](srs/SRS.md) | نیازمندی‌های نرم‌افزار، شماره‌گذاری‌شده | 1 | منتظر تأیید |
| [srs/use-cases.md](srs/use-cases.md) | فهرست Use Case | 1 | منتظر تأیید |
| [permissions/permission-matrix.md](permissions/permission-matrix.md) | ماتریس دسترسی + مدل Capability | 2 | منتظر تأیید |
| [state-machines/appointment.md](state-machines/appointment.md) | State Machine نوبت | 3 | منتظر تأیید |
| [state-machines/visit-queue.md](state-machines/visit-queue.md) | State Machine مراجعه/صف | 3 | منتظر تأیید |
| [state-machines/payment.md](state-machines/payment.md) | State Machine مالی | 3 | منتظر تأیید |
| [erd/erd.md](erd/erd.md) | ERD کامل + تصمیمات ساختاری | 4 | منتظر تأیید |
| [erd/data-dictionary.md](erd/data-dictionary.md) | Data Dictionary + Indexes/Constraints | 4 | منتظر تأیید |
| [api/api-contract.md](api/api-contract.md) | قرارداد REST API | 5 | منتظر تأیید |
| [security/auth-authorization.md](security/auth-authorization.md) | طراحی احراز هویت/مجوز + OTP | 5/7 | منتظر تأیید |
| [wireframes/patient.md](wireframes/patient.md) | وایرفریم بیمار (Mobile-First) | 6 | منتظر تأیید |
| [wireframes/secretary.md](wireframes/secretary.md) | وایرفریم منشی | 6 | منتظر تأیید |
| [wireframes/doctor.md](wireframes/doctor.md) | وایرفریم پزشک (Tablet/Pen) | 6 | منتظر تأیید |
| [security/threat-model.md](security/threat-model.md) | Threat Model (STRIDE) | 7 | منتظر تأیید |
| [security/audit-strategy.md](security/audit-strategy.md) | استراتژی Audit Log | 7 | منتظر تأیید |
| [architecture/file-storage.md](architecture/file-storage.md) | استراتژی نگهداری فایل | 7 | منتظر تأیید |
| [architecture/handwriting-storage.md](architecture/handwriting-storage.md) | معماری ذخیره دست‌خط | 7 | منتظر تأیید |
| [architecture/handwriting-recognition.md](architecture/handwriting-recognition.md) | معماری تشخیص دست‌خط | 7 | منتظر تأیید |
| [architecture/notifications.md](architecture/notifications.md) | معماری اعلان | 7 | منتظر تأیید |
| [architecture/background-jobs.md](architecture/background-jobs.md) | معماری Background Jobs | 7 | منتظر تأیید |
| [backup/backup-recovery.md](backup/backup-recovery.md) | برنامه Backup/DR | 7 | منتظر تأیید |
| [testing/testing-plan.md](testing/testing-plan.md) | برنامه تست | 8 | منتظر تأیید |
| [scope/mvp-scope.md](scope/mvp-scope.md) | محدوده MVP/V1 | 8 | منتظر تأیید |
| [roadmap/roadmap.md](roadmap/roadmap.md) | نقشه راه توسعه | 8 | منتظر تأیید |
| [adr/](adr/) | Architecture Decision Records | 1-8 | Accepted (قابل بازبینی) |
| [phase-reports/report-phase1-8.md](phase-reports/report-phase1-8.md) | گزارش فاز + تصمیمات + ریسک‌ها + آیتم‌های تصمیم‌گیری کارفرما | — | — |

## قوانین

- هیچ کد اصلی (Phase 9+) بدون تأیید شش فاز بالانی (SRS، Permission، State Machines، ERD، API، Wireframes) شروع نمی‌شود.
- هر تغییر فاز قبل: Impact Analysis + Version مجدد مستند.
- کدگذاری نیازمندی‌ها: `FR-x.y` (عملکردی)، `NFR-x` (غیرعملکردی)، `ER-x` (Edge Case)، `UC-x` (Use Case).
