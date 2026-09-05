# ADR-0005 — جداسازی Appointment از Visit (Nullable دوطرفه)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§7: Appointment != Visit. بیمار می‌تواند نوبت داشته ولی نرود (no_show)؛ یا بیاید بدون نوبت (Walk-in).

## Decision
- `cpms_appointments.active_visit_id` (NULL) و `cpms_visits.appointment_id` (NULL) — هر دو Nullable.
- Visit با `source = scheduled|walk_in`.
- `completed` روی Appointment فقط از مسیر Checkout Visit (مسیر تکی → Anti Double-Complete).
- no_show فقط وقتی Visit فعال وجود نداشته باشد.

## Consequences
+ هر دو مدل با هم سازگارند (Scheduled-arrive, Scheduled-no-show, Walk-in).
− باید Invariant «یک نوبت فعال = حداکثر یک Visit فعال» در Transaction نگه‌دارد.
