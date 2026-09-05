# ADR-0017 — Resolution مدت Appointment (لایه‌بندی + Snapshot در زمان رزرو)

وضعیت: Accepted | تاریخ: 2026-09-05 | تأیید کارفرما: 2026-09-05 (تصمیم F1-D4)

## Context
مدت ویزیت نباید Hard-Code باشد. سلسله‌مراتب Resolution آینده: 1) پیش‌فرض کلینیک، 2) Override پزشک، 3) Override Service، 4) Override نوبت تکی. تغییر پیش‌فرض‌ها نباید اثر After-the-fact روی نوبت‌های موجود بگذارد. Buffer پیش/بعدی آینده بدون Redesign DB.

## Decision
1. **Resolution (Domain خالص — `DurationResolver`):**
   `appointment_override ?? service.duration_min ?? schedule(clinician).appointment_duration_min ?? settings('booking.duration_default_min')`
   (اولین مقدار معتبر >0 برنده است).
2. **Snapshot در زمان رزرو (قانون سفت):** `cpms_appointments.duration_min` و `slot_end_time` **در لحظه Booking** ذخیره می‌شوند. تغییر تنظیمات/برنامه پزشک/تعرفه‌ها فقط روی رزروهای جدید اثر می‌گذارد (Regression Test: TP-21).
3. **جداول (Migration 0002 — تفریقی، بدون Redesign):**
   - `cpms_appointments`: + `duration_min` (snapshot) + `slot_end_time` (snapshot).
   - `cpms_schedule`: + `buffer_pre_min` / `buffer_post_min` (NULL = غیرفعال؛ آماده V2).
   - `cpms_services`: + `duration_min` (NULL = استفاده از لایه بالاتر).
   - Backfill یک‌باره برای رکوردهای موجود (از Slot).
4. **Slot Grid:** تولید Slotها با مدت پزشک (برنامه هفتگی)؛ اگر Resolution نهایی (مثلاً Service) متفاوت بود، `slot_end_time` نوبت امتداد می‌یابد — تداخل با Slot بعد در Booking Service (F3) بررسی و در صورت تداخل رد می‌شود (V1).
5. **Buffer:** V1 مقدارها 0 (غیرفعال)؛ ستون‌ها و Settingها (`booking.buffer_pre_default_min`, `booking.buffer_post_default_min`) از روز اول موجودند → بدون Redesign آینده.

## Consequences
+ تغییر تنظیمات بی‌خطر (بدون Mutation تاریخی)؛ انعطاف 4 لایه بدون Redesign.
− باید هر Booking Service حتماً Resolver را صدا بزند (Code Review + تست TP-21).

## Impact Analysis (مطابق Section 56)
- تغییر نسبت به ERD v1: **افزودن** 4 ستون (۲ جدول) — Breaking برای داده موجود نیست (Backfill).
- مستندات: erd.md / data-dictionary.md با v1.2 به‌روزرسانی شد؛ SRS §3.3 یک جمله اضافه شد (FR-3.10 جدید: مدت قابل تنظیم + Snapshot).
