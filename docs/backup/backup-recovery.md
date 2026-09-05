# برنامه Backup و Disaster Recovery — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | وابسته به: NFR-AV-1

## 1. اهداف (RPO/RTO)
| شاخص | V1 | V2 (پیشنهاد) |
|---|---|---|
| **RPO** (حداکثر داده از دست رفته) | ≤ 24 ساعت | ≤ 1 ساعت (binlog/ساعتی) |
| **RTO** (زمان بازیابی سرویس) | ≤ 4 ساعت | ≤ 1 ساعت |
| Retention | Daily ×14، Weekly ×8، Monthly ×12 | + Annual |

> **تصمیم کارفرما (R-05):** اگر داده‌های بالینی حساسیت بالاتری دارند (مثلاً حجم بالای ویزیت در روز)، RPO ساعتی از روز اول (binlog یا بکاپ ساعتی) فعال می‌شود.

## 2. محدوده Backup
| داده | مکان | روش |
|---|---|---|
| MySQL (تمام `cpms_*` + حداقل wp_users/options/roles) | DB | `mysqldump --single-transaction --routines` (شب، 03:00) |
| Medical Files + Handwriting Storage + Previewها | `/var/www/clinic-storage` | `rsync`/`rclone` آینه‌سازی |
| wp-content/uploads (Preview/Thumbs) | — | همراه با بالا |
| Config لازم (php.ini مربوطه، .env بدون Secret یا با Key جدا) | — | فایل Config + نسخه |
| Cron/Job State | `cpms_jobs` | داخل DB (بالا) |

## 3. چرخه و ایمنی
- **زمان:** هر 6 ساعت (Job `backup.trigger` — برای **RPO ≤ 6h** الزامی)؛ مدت تخمینی هر بار < 15 دقیقه.
- **فشرده‌سازی + رمزنگاری:** `tar.gz` → `age`/`openssl enc -aes-256-gcm` (Key **خارج سرور** — T-21).
- **مقصد:** حداقل 2 مقصد: (1) دیسک دوم/Network، (2) Object Storage (S3/B2/سرویس داخلی) — مکان‌های فیزیکی متفاوت.
- **تأیید:** هر Backup: checksum (SHA-256) + `restore verify` (بازکردن dump در DB تست — Sample) → Report در Operational Log.
- **Alert:** شکست Backup → Internal Notification فوری به مدیر فنی + Retry بعد 1 ساعت.

## 4. فرایند بازیابی (Restore) — Documented
```
1) اعلام ریسک + توقف نوشت (معمولی: خارج از ساعات اوج)
2) انتخاب آخرین Backup سالم (checksum + verify OK)
3) بازیابی DB در سرور/استانسی تست → smoke test (سایت بالا + یک نوبت تست)
4) جایگزینی در Production + بازیابی فایل‌ها
5) صحت‌سنجی: تعداد رکوردهای کلیدی (patients/appointments/visits/payments) + Hash Chain Audit
6) ثبت در Operational + Audit (RESTORE_EVENT) + اطلاع‌رسانی به کارفرما
```

## 5. تست بازیابی (اجباری)
| تست | فرکانس | موفقیت = |
|---|---|---|
| Restore کامل (DB+File) در محیط مجزا | **هر فصل + بعد از هر Migration حساس** | سرویس تست قابل استفاده؛ صحت‌سنجی داده |
| Restore نقطه‌ای (فقط یک جدول) | هر 6 ماه | — |
| RTO تایمینگ | هر بار تست | ≤ RTO هدف |
> نتیجه هر تست در `/docs/backup/restore-reports/` (فاز 13).

## 6. سناریوهای DR
| سناریو | اقدام |
|---|---|
| خرابی دیسک/سرور | Restore آخرین Backup + RPO ≤ 6h |
| حذف/تغییر اشتباه داده | Restore نقطه‌ای از Backup قبلی + (V2: PITR) |
| آلودگی Ransomware | Backup رمزنگاری‌شده جدا + Key خارج → Restore از مقصد دوم |
| اشتباه Migration | Backup قبل از Migration (اجباری) + Rollback Migration (Section 47) |
