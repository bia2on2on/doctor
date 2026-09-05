# ADR-0013 — زمان: ذخیره UTC، نمایش Jalali (Presentation فقط)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§44: تاریخ در Backend استاندارد و Timezone-aware؛ Jalali لایه نمایش.

## Decision
- ذخیره: `DATETIME(3)` **UTC** (همه جداول)؛ `TIME` برای Slotها (ساعت محلی کلینیک — Slot ذاتاً محلی است؛ در Display با timezone کلینیک).
- `clinics.timezone` (IANA، پیش‌فرض `Asia/Tehran`).
- کلاینت: تبدیل UTC → Jalali (کتابخانه `jalali`/`jdf` — انتخاب در F1)؛ API همزمان ISO-8601 UTC + فیلدهای `_jalali` برای UI.
- Comparison/Query: همیشه UTC (Jobها با `now_utc` + آستانه‌های محلی محاسبه‌شده).

## Consequences
+ DST/تغییر timezone بدون Data Migration؛ Report‌ها یکپارچه.
− کلاینت باید تبدیل را درست کند (تست واحد Conversion —TP-17).
