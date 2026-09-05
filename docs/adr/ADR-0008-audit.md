# ADR-0008 — Audit: Append-only + Hash Chain + تفکیک از Operational

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§32/49: Audit حیاتی، دست‌نیافتنی برای کاربران عادی؛ Operational جدا.

## Decision
- `cpms_audit_logs`: فقط INSERT در اپ؛ Trigger DB در Production (اختیاری/توصیه)؛ هیچ Endpoint UPDATE/DELETE.
- **Hash Chain:** `row_hash = SHA-256(prev_hash + canonical(row_fields))`؛ Job روزانه Verify (شکست → Alert).
- PHI در Audit: حداقل + Masking (متن کامل Note نمی‌رود — فقط id + change-set فیلدها).
- `cpms_operational_logs`: خطا/Job/SMS/OCR — بدون PHI (T-14)؛ Retention 90 روز (قابل تنظیم).
- دسترسی Read: فقط `cpms_audit_read` (اعطای صریح).

## Consequences
+ جعل تک‌رکورد → شکست زنجیر (در Verify بعدی).
− Verify زنجیر کامل O(n) — نسخه V1: نمونه‌گیری + آخر؛ آرشیو با Chain break-check در مرز.

## Alternatives
- WORM Storage/Block-chain خارجی (هزینه و پیچیدگی بیشتر؛ V2).
- فقط Trigger بدون Chain (جعل با دسترسی DB بدون ردپا می‌ماند).
