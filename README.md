# CPMS — سیستم مدیریت مطب (Clinic Practice Management System)

افزونه WordPress تجاری برای مدیریت کامل مطب — PHP 8.1+ / MySQL 8 / WordPress 6.7+ — تک‌کلینیک در V1.

> ## 🤖 ایجنت‌ها (AI/انسان) — قبل از هر کاری:
> **[`docs/agent-guide.md`](docs/agent-guide.md)** را کامل بخوانید — راهنمای جامع ادامه پروژه:
> وضعیت فازها، قواعد الزامی کارفرما، الگوهای کد، دام‌های شناخته‌شده، فازهای باقی‌مانده و
> **پروتکل لاگ کار (§9–10): هر ایجنت ورودی خود را در انتهای آن فایل append می‌کند.**

## ساختار Repo

| مسیر | محتوا |
|---|---|
| `clinic-practice-management/` | کد افزونه (`src/` با namespace `ClinicCore\`، تست‌ها در `tests/`، CLI در `bin/cpms`) |
| `docs/` | مستندات پروژه — شروع از [`docs/README.md`](docs/README.md) (ایندکس کامل) |
| `docs/roadmap/roadmap.md` | فازبندی و DoD هر فاز (منبع حقیقت فازها) |
| `docs/phase-reports/` | گزارش تکمیل هر فاز (F1–F4 ✅) |
| `.github/workflows/ci.yml` | CI: Unit (PHP 8.1–8.4) + Integration (WP 6.7.2 + MySQL 8) |

## وضعیت (2026-09-05)

**کامل و CI سبز:** F1 هسته | F2 احراز هویت | F2.5 پیامک | F3 نوبت‌دهی | **F4 مراجعه/صف**
**بعدی (نیازمند تأیید کارفرما):** F5 بالینی → F6 مالی → F7 دست‌خط → F8 اعلان/گزارش → F9 Hardening (Go-Live V1)

مایلستون M1 رسیده: بیمار واقعی می‌تواند آنلاین نوبت بگیرد.
