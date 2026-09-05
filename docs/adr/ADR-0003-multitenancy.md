# ADR-0003 — چند-شعبه/چند-پزشک از روز اول (clinic_id/clinician_id)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§48: V1 تک‌پزشک/تک‌شعبه ولی معماری بن‌بست نسازد.

## Decision
- `clinic_id` (FK→cpms_clinics) در تمام جداول پزشکی؛ V1 مقدار ثابت 1.
- `clinician_id` در Schedule/Slot/Appointment/Visit/Note/RX/FollowUp.
- همه Queryها فیلتر `clinic_id` (در Repository Base) — بدون هزینه عملکردی (Index).
- Data-Scoping تیمی (پزشک فقط بیماران تیمش) در V2 — همین ستون‌ها کافیه.

## Consequences
+ Zero-cost extensibility؛ Reports از ابتدا per-clinic.
− یک ستون اضافه در Queryها (قابل‌حمل).
