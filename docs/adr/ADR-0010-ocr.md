# ADR-0010 — OCR: Provider قابل تعویض + Human-in-the-loop اجباری

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§19: معماری Provider قابل تعویض؛ متن OCR تا تأیید پزشک معتبر نیست؛ فارسی Requirement.

## Decision
- `HandwritingProvider` Interface + `HttpProvider` (هر API با JSON)؛ تنظیمات endpoint/key/model.
- Job-based (خارج از Request)؛ Retry 3 بار؛ `cpms_ocr_jobs` با `review_status` و `confirmed_text`.
- **قانون:** `confirmed_text` فقط با `PUT /ocr/jobs/{id}/review` (پزشک)؛ نسخه/یادداشت ساخته‌شده از آن → `source=ocr_confirmed` + ارجاع Job.
- تصویر فرستاده‌شده: بدون PII (سربرگ/نام/تاریخ بریده/محوشده) + Consent/Policy (T-13).
- Acceptance: قبل از انتخاب Provider، تست دقت روی ≥200 نمونه فارسی واقعی (CER + خطای دارو/عدد) — گزارش به کارفرما (R-04).

## Consequences
+ تعویض Provider = تغییر Setting؛ هیچ خروجی خودکار = داده بالینی.
− دقت فارسی بازار محدود است → R-04 ریسک باز (مهارت: Human-in-the-loop).
