# ADR-0002 — مدل نقش/قابلیت و تفکیک Admin فنی از دسترسی پزشکی

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
Master Prompt §1 و §37: Administrator وردپرس نباید به‌صورت خودکار PHI ببیند؛ Authorization صریح و مستقل.

## Decision
- افزونه 3 نقش می‌سازد: `patient`، `clinic_secretary`، `clinic_doctor` (با مجموعه Capability `cpms_*`).
- نقش `administrator` وردپرس: فقط `cpms_config` + فنی. دسترسی پزشکی (`cpms_medical_read`، `cpms_audit_read`، ...) **به کاربر** (نه نقش) به‌صورت دستی اعطا می‌شود.
- یک `AccessPolicy` (Single Source of Truth) برای Capability + Data-Access + Field/Row Filter.
- نقش `clinic_manager` (مدیر مطب) به‌عنوان گسترش آماده (بدون تغییر مدل).

## Consequences
+ Least Privilege واقعی؛ Audit تغییر مجوز.
− Admin فنی برای «دیدن یک پرونده» باید Capability بگیرد (ارزش امنیتی).

## Alternatives
- نقش `clinic_admin` با دسترسی کامل (مردود: نقض §1).
- فقط نقش‌های وردپرس بدون Capability (مردود: دانه‌بندی درز).
