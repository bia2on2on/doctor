# برنامه تست — CPMS

نسخه 1.0 | 2026-09-05 | فاز 8 | زیرساخت: PHPUnit + WP Test Suite (`WP_UnitTestCase`) + WP-CLI (Fixture) + CI (GitHub Actions)

## 1. سطوح تست

| سطح | ابزار | هدف |
|---|---|---|
| Unit | PHPUnit | Business Rules خالص (State Machines، Policy، OTP Logic، Invoice Calculation) |
| Integration | WP Test Suite + MySQL واقعی | API + DB + Migration + Repository |
| API/Authorization | REST Client در Integration | Permission Matrix + IDOR |
| Concurrency | PHP Co-Process / parallel requests | Race Conditionها |
| E2E (Smoke) | (V1.5: Playwright) | سناریوی پذیرش Section 51 |
| Security | SAST (PHPStan/Psalm) + Checklist | T-01..T-24 |
| Load | k6 (اختیاری V1.5) | NFR-PERF-1 |

## 2. تست‌های الزامی (Acceptance Tests)

| ID | تست | سطح | Assert کلیدی |
|---|---|---|---|
| **TP-01** | **سناریوی پذیرش** (Section 51): رزرو→OTP→پروفایل→تأیید→Check-in→Call→ویزیت→دست‌خط→نسخه→توصیه→Follow-up→Complete→Invoice→Payment→Receipt→Checkout→Dashboard بیمار | E2E/Integration | همه گام‌ها 200؛ وضعیت‌ها مطابق State Machines؛ Audit کامل |
| **TP-02** | **Idempotency پرداخت:** POST payment با `Idempotency-Key` تکراری | Integration | دفعه اول 201؛ تکرار = 200 + همان `payment_id`؛ فقط یک رکورد |
| **TP-03** | **Concurrency رزرو:** N=10 Request هم‌زمان برای یک Slot (ظرفیت 1) | Concurrency | دقیقاً **یک** موفق؛ بقیه `CLINIC_SLOT_TAKEN`/`409`؛ `booked_count=1`؛ بدون Row اضافی |
| TP-03b | Concurrency Queue: دو `complete` هم‌زمان | Concurrency | یک موفق؛ دیگری `CLINIC_INVALID_TRANSITION` |
| **TP-04** | CSRF: موتانت بدون Nonce | API | 403 |
| **TP-05** | **OTP Rate Limit:** 6 کد اشتباه → Lockout؛ 4 کد/روز → رفض | Integration | `CLINIC_OTP_LOCKED`؛ شمارش در DB |
| **TP-06** | **File Access:** (a) URL مستقیم فایل → 403/404؛ (b) Patient روی فایل بیمار دیگر → 404 + Audit؛ (c) فایل با MIME جعلی (php داخل jpg) → `CLINIC_FILE_INVALID` | Security | هیچ نشتی |
| **TP-07** | **IDOR جامع:** Patient A روی همه Endpointهای `/{id}` با IDهای B (patient, appointment, visit, note, prescription, file, invoice) | API | 404/403 + Audit attempt |
| **TP-08** | **Private Note:** Secretary و Patient → `GET /visits/{id}` و `GET /notes` → هیچ `doctor_private` (سطح Query + سطح API) | Security | 0 رکورد private در Response |
| **TP-09** | **Admin بدون Capability صریح** → PHI Endpoints | API | 403 |
| **TP-10** | **Permission Matrix Parametrized:** هر (Role × Resource × Action) از Matrix | API | مطابق Matrix (تولید خودکار از CSV Matrix) |
| TP-11 | **Audit Integrity:** Hash Chain Verification؛ تلاش UPDATE/DELETE مستقیم DB (در تست) → Block/Log | Security | زنجیر سالم |
| **TP-12** | **Handwriting Offline:** Cut Network وسط Auto-save → Local Persistence → Restore → Sync → Merge/Conflict | E2E | هیچ Stroke از دست نمی‌رود؛ وضعیت UI صحیح |
| TP-13 | Job Idempotency (holds.expire، backup.trigger، ...) | Unit/Integration | دو بار اجرا = یک اثر |
| TP-14 | State Machine Exhaustive: هر (state × event) نامعتبر | Unit | `CLINIC_INVALID_TRANSITION` |
| TP-15 | Migration: Upgrade از V(n) به V(n+1) روی داده‌های Seed + Rollback Test | Integration | داده‌ها سالم |
| TP-16 | **Backup Restore:** Restore در محیط مجزا + Smoke | DR | مطابق docs/backup |
| TP-17 | Validation: کد ملی checksum، موبایل فرمت، تاریخ تولد، Mass Assignment | Unit/API | `CLINIC_VALIDATION_FAILED` |
| TP-18 | Invoice Calculation: تخفیف/مالیات/Partial/Overpayment/Adjustment | Unit | `balance` دقیق |
| TP-19 | No-Show Flow: Grace Passage → auto no_show؛ Check-in بعد از Grace → Visit فوری | Integration | مطابق ER-06/07 |
| TP-20 | Reschedule: انتقال Hold + آزادسازی Slot قدیم + لینک دوطرفه | Integration | مطابق T7 |

## 3. پوشش و کیفیت
- هسته Business Rules (State Machines, Policy, Financial): **Branch Coverage ≥ 80%** (Gate CI).
- هر PR: Lint (PHP-CS-Fixer) + Static Analysis (PHPStan L.6) + Test — CI سبز = Merge.
- **ممنوعیت‌های Section 56 به‌عنوان Gate:** هیچ Endpoint بدون تست Permission، هیچ Feature بدون TP مربوطه.

## 4. Fixture و داده تست
- Seed: 1 clinic، 2 clinician، 30 patient، 100 appointment/visit (تولید Deterministic).
- PHI تست: داده مصنوعی (اسم «آزمودنی ۱» و...) — **هیچ داده واقعی در CI**.
