# ADR-0001 — معماری لایه‌ای افزونه (نه Monolith Spaghetti)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
Master Prompt §46: پروژه نباید تک‌فایل/اسپاگتی باشد؛ Business Logic در Template نه، DB Access پراکنده نه.

## Decision
ساختار افزونه `clinic-practice-management/`:
```
src/
  Domain/            ← Entity, ValueObject, State Machine, Policy (بدون WP dependency)
  Application/       ← Service (Use Case Orchestration)
  Infrastructure/
    Repository/      ← MySQL (WordPress $wpdb با Prepared)
    Gateway/         ← SmsGateway, HandwritingProvider, StorageDriver
    Queue/           ← JobQueue (DB-based V1)
  Api/               ← REST Controllers + Validators (نویسندگان فقط)
  Authorization/     ← AccessPolicy, Capability Map
  Admin/             ← Settings/Config صفحات (فنی)
  Frontend/          ← JS/CSS داشبوردها (نه PHP template)
  Jobs/              ← Handlers
  Migrations/        ← Schema versions
tests/
```
- Domain لایه بدون `global $wpdb` و بدون `function_exists('wp_...')` → Unit Test خالص.
- Namespace: `ClinicCore\`؛ پیش‌وند جدول `cpms_`؛ پیش‌اندازهای PHP `cpms_`.

## Consequences
+ تست‌پذیری، مرزهای واضح، تعویض‌پذیری Adapterها.
− حجم اولیه کد بیشتر نسبت به «تک‌فایل سریع» — پذیرفته شده (MVP موقت نمی‌خواهیم).

## Alternatives
- Single-file plugin (مردود: نگهداری ناپذیر).
- Microservice جدا از WP (مردود در V1: هزینه عملیاتی؛ مرز REST ما به‌گونه‌ای است که جداسازی بعدی ممکن است).
