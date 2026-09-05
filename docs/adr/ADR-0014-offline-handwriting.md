# ADR-0014 — Offline Persistence دست‌خط: IndexedDB + Sync State Machine + Conflict Policy

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§53: از دست رفتن یادداشت در قطع اینترنت غیرقابل قبول است.

## Decision
- **Local:** IndexedDB (Draft به‌ازای هر Page) + Autosave Local دائمی (هر تغییر).
- **Sync State (UI واضح):** `Saving… / Saved / Offline / Sync Failed` (به‌ازای هر Document).
- **Retry:** Queue با Backoff (5s→30s→2m→10m→30m)؛ Resume هنگام آنلاین شدن (online event + Fokas صفحه).
- **Conflict:** `client_revision` vs `version` سرور → `409 CLINIC_CONFLICT` → UI: (الف) همگام روی سرور (ب) جایگزینی با Local (با Reason) — انتخاب پزشک + Audit.
- **Local Security (T-16):** حذف Local بعد از Sync موفق (پیش‌فرض `hw.local_retain=off`)؛ گزینه `last` (یک Draft)؛ Encryption Local AES-GCM (Key از Session) → V1.5.
- **Threat Note:** Tablet مشترک/قابل‌دزدی → Local فقط موقت + هشدار در UI (قبل از شروع نوشتن روی دستگاه تازه).

## Consequences
+ صفر Data Loss در سناریوی ER-14/15.
− پیچیدگی Sync (کدری قابل‌حمل؛ Test TP-12 اجباری).

## Alternatives
- فقط LocalStorage (سقف 5MB — ناکافی برای Stroke JSON).
- Cloud Push فقط بدون Local (مردود: نقض §53).
