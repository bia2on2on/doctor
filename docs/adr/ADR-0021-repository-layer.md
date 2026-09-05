# ADR-0021 — لایه Repository (Domain-Focused، از F3)

تاریخ: 2026-09-05 | وضعیت: **تأیید نهایی کارفرما** | منبع: Engineering Baseline §25 + تصمیم F2

## تصمیم

1. **از F3 به بعد، لایه Repository الزامی است** — کد جدید Business/Service مستقیماً Query نمی‌زند؛ از Repository می‌گذرد.
2. **کدهای سالم F1/F2 صرفاً برای یکدستی معماری Refactor نمی‌شوند.**
   - در صورتی که در آینده کد F1/F2 باعث Duplication، Tight Coupling، مشکل Testability، Security، Transaction یا Concurrency شود → **Refactor محدود + Impact Analysis** (نه پروژه‌ای).
3. **Domain-Focused، نه God Repository:**
   - هر Repository فقط روی یک Aggregate/Entity (Patient، Appointment+Hold، Slot، Visit/Queue، Invoice، Payment، File، SmsMessage، License).
   - Queryهای ترکیبی چند-Entity در **Application Service** ترکیب می‌شوند (با تعداد محدود Repository) — نه Methodهای جادویی چند‌داده در Repository.
   - Rule: هر Repository ≤ ~15 Method؛ Method = یک Intent واضح (نام‌گذاری فعلی: `findActiveByMobile`, `claimSlot`, ...).
4. **مرز لایه‌ها:**
   ```
   REST Controller → Application Service (Business Rule + Transaction) → Repository (Query) → CpmsDb (Prepared)
   Domain: خالص (بدون Query)
   ```
   - Transaction Ownership: Application Service (Repository Transaction نمی‌بندد مگر برای Operationهای اتمیک داخلی مثل `claimSlot`).
   - Mass Assignment: Repository فقط با آرایه‌های صریح داخلی — نه مستقیم از Request.

## ساختار هدف (از F3)

```
src/Infrastructure/Repository/
  PatientRepository.php
  AppointmentRepository.php
  SlotRepository.php        (claim/hold موجود در F1 → اینجا)
  VisitQueueRepository.php
  InvoiceRepository.php
  PaymentRepository.php
  FileRepository.php
  SmsMessageRepository.php  (F2.5 — در فاز بعدی تطبیق)
  LicenseRepository.php     (F10)
```

## معیار Refactor محدود F1/F2 (فقط در صورت رخ‌دادن)

| محرک | اقدام |
|---|---|
| Duplication Query بین ≥2 Service | Extract به Repository + Impact Analysis |
| شکست Concurrency/Transaction | Move به Repository/Service با Lock مناسب + Impact Analysis |
| ناتوانی در Unit-Test (بدون WP) | Extract + Fake Repository + Impact Analysis |

## Consequences

- پراکندگی Query کنترل می‌شود (Baseline §25)؛ F1/F2 پایدار می‌ماند (بدون Rework ریسک‌دار قبل از F3).
- تست: Repositoryها با Fake/In-Memory در Unit قابل تست می‌شوند؛ Integration روی MySQL.
