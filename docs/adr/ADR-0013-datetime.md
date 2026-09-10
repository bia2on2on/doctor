# ADR-0013 — زمان: ذخیره UTC، نمایش Jalali (Presentation فقط)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§44: تاریخ در Backend استاندارد و Timezone-aware؛ Jalali لایه نمایش.

## Decision
- ذخیره: `DATETIME(3)` **UTC** (همه جداول)؛ `TIME` برای Slotها (ساعت محلی کلینیک — Slot ذاتاً محلی است؛ در Display با timezone کلینیک).
- `clinics.timezone` (IANA، پیش‌فرض `Asia/Tehran`).
- کلاینت: تبدیل UTC → Jalali (کتابخانه `jalali`/`jdf` — انتخاب در F1)؛ API همزمان ISO-8601 UTC + فیلدهای `_jalali` برای UI.
- Comparison/Query: همیشه UTC (Jobها با `now_utc` + آستانه‌های محلی محاسبه‌شده).

> ### گسترش — [ADR-0031](ADR-0031-organization-clinic-location-scoped-authorization.md) AD-08 (2026-09-08)
>
> این ADR **نقض نشده؛ گسترش یافته است.** `Location` منطقهٔ زمانی مستقل دارد: `locations.timezone NOT NULL`. `clinics.timezone` به‌عنوان مقدار پیش‌فرض باقی می‌ماند و `locations.timezone` مرجع مؤثر می‌شود.
>
> ✅ **پایهٔ فعلی درست است:** `CpmsDb::now()` از `gmdate()` استفاده می‌کند ⇒ زمان‌ها از قبل UTC ذخیره می‌شوند؛ `Settings.php:268` منطقهٔ زمانی را از `cpms_clinics.timezone` با fallback `'Asia/Tehran'` می‌خواند. پس این یک **توسعه** است نه بازنویسی. پوشش تست DST و تبدیل منطقهٔ زمانی در **Phase 2/6** الزامی است.

## Consequences
+ DST/تغییر timezone بدون Data Migration؛ Report‌ها یکپارچه.
− کلاینت باید تبدیل را درست کند (تست واحد Conversion —TP-17).
