# ADR-0011 — شناسه‌ها: BIGINT PK + Reference Code خوانا (بدون GUID در PK)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
انتخاب PK برای 36 جدول + کدهای قابل نمایش برای بیمار (نوبت، نسخه، فاکتور).

## Decision
- PK: `BIGINT UNSIGNED AUTO_INCREMENT` (Index فشرده، Join سریع).
- GUID/UUID فقط در جایی که هویت Client-side لازم است: `slot_holds.token`، `Idempotency-Key`.
- کدهای قابل نمایش (مرجع): `reference_code` (نوبت `AP-YYMMDD-##`)، `prescription_number` (`RX-######`)، `invoice_number`، `payment_number` — یکتا + Index؛ جدا از PK.
- شماره‌ساز: Sequence ساده (MAX+1 با Row Lock روی کلینیک/روز) — بدون شکاف بحرانی (شکاف مجاز است؛ یکتایی مهم است).

## Consequences
+ عملکرد/خوانایی دوستانه؛ Logs قابل خواندن.
− اگر روزی Merge DB شد، Collision Sequence باید مدیریت شود (V2 — با `clinic_id` در کلید یکتا).
