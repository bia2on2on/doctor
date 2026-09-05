# گزارش شروع F4 — Visit / Queue

تاریخ شروع: 2026-09-05
وضعیت: **در حال انجام — Slice 1 پیاده‌سازی اولیه**

## وابستگی

F4 به داده‌های Appointment و Patient از F3 وابسته است. در این شاخه، پیاده‌سازی F4 به‌صورت مرحله‌ای و روی Schema موجود `cpms_visits` و `cpms_visit_status_history` شروع شده است. تغییرات F3 موجود در Pull Request شماره 1 باید قبل از بستن نهایی F4 با این شاخه Merge و دوباره تست شوند.

## Slice 1 انجام‌شده

- `VisitRepository` برای دسترسی محدود به `cpms_visits` و `cpms_visit_status_history`
- `VisitService` برای:
  - Check-in نوبت تأییدشده
  - Walk-in
  - جلوگیری از Visit فعال تکراری برای بیمار/پزشک/روز
  - Enqueue خودکار بعد از Check-in و Walk-in
  - Transitionهای صف با `VisitMachine`
  - الزام دلیل برای Skip و Cancel
  - سقف سه Recall
  - ثبت Append-only History برای هر Transition
  - Audit و Operational Log
  - Queue مرتب‌شده بر اساس زمان ورود
- `VisitController` با Endpointهای اولیه:
  - `POST /visits/checkin`
  - `POST /visits/walk-in`
  - `POST /visits/{id}/status`
  - `GET /queue`
- اتصال اولیه Service و Controller به `Bootstrap/App.php`

## موارد باقی‌مانده F4

1. افزودن Argument Schema و Validation کامل REST.
2. تست Integration برای Check-in، Walk-in، Duplicate Active Visit، History و IDOR.
3. تکمیل Today Dashboard منشی.
4. تکمیل Data-Access بر اساس Clinic/Clinician و نقش.
5. تکمیل Doctor Queue API و تفکیک Transitionهای Doctor/Secretary.
6. Real-time Polling endpoint مطابق `GET /rt/queue?since=`.
7. اعلان داخلی Queue.
8. No-show و ارتباط دقیق Appointment/Visit.
9. E2E سناریوی منشی تا صف.
10. بازبینی با تغییرات F3 و اجرای کامل CI.

## محدودیت این Slice

Invoice، Payment، Clinical Note، Prescription، File و Checkout عمداً در F4 کامل نشده‌اند و به F5/F6 مربوط هستند. `VisitMachine` آن Transitionها را تعریف می‌کند، اما اجرای کامل آن‌ها باید در فازهای مربوط انجام شود.
