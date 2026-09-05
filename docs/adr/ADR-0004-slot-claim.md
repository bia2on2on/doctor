# ADR-0004 — Slot مادی + Claim اتمیک + Hold با TTL (ضد Double-Booking)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§43/ER-04: دو بیمار نمی‌توانند هم‌زمان یک Slot را بگیرند؛ کنترل در DB نه UI.

## Decision
- `cpms_schedule_slots` مادی (UNIQUE clinician+date+time) با `capacity/booked_count/held_count`.
- **Claim اتمیک:**
  `UPDATE cpms_schedule_slots SET held_count=held_count+1 WHERE id=? AND capacity-booked_count-held_count>0`
  → `affected_rows=0` یعنی گرفته شده (بدون Read-then-Write Race).
- Hold: `cpms_slot_holds` (token UUID, TTL 10 دقیقه)؛ Job `holds.expire` آزاد می‌کند.
- Confirm: Transaction (Row Lock) — Hold→Claim (held-1, booked+1) + INSERT Appointment.
- تولید Slot: Lazy (هنگام نمایش تقویم) + Cron روزانه (K-2 Unique → Idempotent).

## Consequences
+ بدون Lock سنگین؛ با ظرفیت N هم درست است؛ Hold = UX کاربر.
− باید Sync بین Schedule تغییر و Slotهای آینده (Job regenerate: تغییر Schedule → Cancel Slotهای تولیدشده آینده و بازتولید).

## Alternatives
- فقط Unique Constraint روی appointments (ظرفیت N را می‌شکند؛ Check-then-Act Race).
- Redis Lock (وابستگی زیرساخت اضافه برای یک مسئله قابل‌حل با SQL).
