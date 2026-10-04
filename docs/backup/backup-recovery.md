# برنامه Backup و Disaster Recovery — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | وابسته به: NFR-AV-1

> **وضعیت اجراییِ جاری — checkpoint مورخ 2026-10-04؛ کد مرجع است.** این سند در اصل طرحِ فاز ۷ بوده است. بخش‌های ۱ تا ۶ پایین، proposalهای تاریخی‌اند؛ نه runbook، SLA، پیکربندیِ جاری یا گزارشِ اجرای عملیات. خلاصهٔ وضعیت واقعی:
>
> - بکاپِ محلیِ `BackupService::createBackup()` نصب‌گسترده است: `db.sql` فقط جدول‌های منطبق با `$wpdb->prefix . 'cpms_%'` را dump می‌کند و `storage/` فایل‌های ریشه‌های بالینیِ فعالِ ثبت‌شده برای Clinicها را می‌گیرد. WordPress core/users/options/roles، uploads عمومی بیرون از ریشهٔ بالینی، فایل‌های plugin/source و deployment config جزو artifact نیستند؛ فایلِ uploads فقط اگر واقعاً زیرِ ریشهٔ بالینیِ فعال باشد و قاعدهٔ مسیر را بگذراند ممکن است وارد شود. جزئیات: [`s3-deployment-config.md`](s3-deployment-config.md).
> - artifact محلی plaintext است. `backup.enabled` پیش‌فرض خاموش است؛ اگر روشن شود، `backup.run` فقط بکاپ محلی را هنگام سررسیدِ `backup.interval_hours` (پیش‌فرض 24 ساعت، بازهٔ تنظیمی 1..168) می‌سازد. `backup.keep_count` پیش‌فرض 14 و فقط retention محلیِ شمارشی است؛ زمان واقعی به اجرای tick/worker وابسته است.
> - Slice 2C در هر فراخوانی یک مقصدِ S3 پیکربندی‌شده را mirror می‌کند؛ مخزن محلی جداگانه وجود دارد، اما orchestration چندمقصدی یا تضمینی برای جداییِ failure-domain دو مقصد نیست. mirror زمان‌بندی‌شده و remote retention وجود ندارد. فایل‌های remote با envelopeِ `CPMSBK01` و Sodium secretstream XChaCha20-Poly1305 رمز می‌شوند؛ این دربارهٔ artifact محلی صدق نمی‌کند.
> - ادعای checksum/restore خودکار، alert فوری، retry ثابتِ یک‌ساعته یا drill دوره‌ای نادرست است: شکست `backup.run` در مسیر queue log و throw می‌شود و از retry/backoff عمومیِ queue پیروی می‌کند، نه زمانِ یک‌ساعتهٔ تضمین‌شده؛ برای S3 نیز alert/retry خودکار وجود ندارد. remote verification و reconstruction/preflight فقط با فراخوانی دستی انجام می‌شوند؛ preflight به `restoreApply()` نمی‌رسد.
> - RPO/RTO عددیِ محقق‌شده یا تضمین‌شده وجود ندارد. Integration chain فاز ۱۵ از transport ساختگی/interceptشده استفاده می‌کند و دوامِ provider واقعی را ثابت نمی‌کند. بخشِ اجرای remote و حدودش در [`s3-deployment-config.md`](s3-deployment-config.md) مستند است.

## 1. اهداف تاریخیِ RPO/RTO (proposal فاز ۷؛ نه نتیجه یا تعهد)
| شاخص | V1 proposal (تاریخی) | V2 proposal (تاریخی) |
|---|---|---|
| **RPO** (هدفِ تاریخیِ حداکثر دادهٔ از دست‌رفته) | proposal: ≤ 24 ساعت | proposalِ V2: ≤ 1 ساعت (binlog/ساعتی) |
| **RTO** (زمان بازیابی سرویس) | ≤ 4 ساعت | ≤ 1 ساعت |
| Retention | Daily ×14، Weekly ×8، Monthly ×12 | + Annual |

> **تصمیم کارفرما (R-05):** اگر داده‌های بالینی حساسیت بالاتری دارند (مثلاً حجم بالای ویزیت در روز)، RPO ساعتی از روز اول (binlog یا بکاپ ساعتی) فعال می‌شود.

## 2. دامنهٔ پیشنهادیِ تاریخی (نه inventory بکاپِ جاری)
| قلمِ برنامه‌ریزی‌شدهٔ تاریخی (نه محتوای فعلی) | مکانِ پیشنهادی | روشِ پیشنهادی |
|---|---|---|
| MySQL (تمام `cpms_*` + حداقل wp_users/options/roles) | DB | `mysqldump --single-transaction --routines` (شب، 03:00) |
| Medical Files + Handwriting Storage + Previewها | ریشهٔ خصوصیِ فایل‌های پزشکی (proposal) | `rsync`/`rclone` آینه‌سازی |
| wp-content/uploads (Preview/Thumbs) | — | همراه با بالا |
| Config لازم (php.ini مربوطه، .env بدون Secret یا با Key جدا) | — | فایل Config + نسخه |
| Cron/Job State | `cpms_jobs` | داخل DB (بالا) |

## 3. چرخهٔ پیشنهادیِ تاریخی (نه زمان‌بندی/سیاستِ جاری)
- **زمان:** هر 6 ساعت (Job `backup.trigger` — برای **RPO ≤ 6h** الزامی)؛ مدت تخمینی هر بار < 15 دقیقه.
- **فشرده‌سازی + رمزنگاری:** `tar.gz` → `age`/`openssl enc -aes-256-gcm` (Key **خارج سرور** — T-21).
- **مقصد:** حداقل 2 مقصد: (1) دیسک دوم/Network، (2) Object Storage (S3/B2/سرویس داخلی) — مکان‌های فیزیکی متفاوت.
- **تأیید:** هر Backup: checksum (SHA-256) + `restore verify` (بازکردن dump در DB تست — Sample) → Report در Operational Log.
- **Alert:** شکست Backup → Internal Notification فوری به مدیر فنی + Retry بعد 1 ساعت.

## 4. فرایند بازیابیِ پیشنهادیِ تاریخی (نه runbook اجرایی)
```
1) اعلام ریسک + توقف نوشت (معمولی: خارج از ساعات اوج)
2) انتخاب آخرین Backup سالم (checksum + verify OK)
3) بازیابی DB در سرور/استانسی تست → smoke test (سایت بالا + یک نوبت تست)
4) جایگزینی در Production + بازیابی فایل‌ها
5) صحت‌سنجی: تعداد رکوردهای کلیدی (patients/appointments/visits/payments) + Hash Chain Audit
6) ثبت در Operational + Audit (RESTORE_EVENT) + اطلاع‌رسانی به کارفرما
```

## 5. تست‌های بازیابیِ پیشنهادیِ تاریخی (نه تستِ خودکار یا زمان‌بندی‌شده)
| تست | فرکانس | موفقیت = |
|---|---|---|
| Restore کامل (DB+File) در محیط مجزا | **هر فصل + بعد از هر Migration حساس** | سرویس تست قابل استفاده؛ صحت‌سنجی داده |
| Restore نقطه‌ای (فقط یک جدول) | هر 6 ماه | — |
| RTO تایمینگ | هر بار تست | ≤ RTO هدف |
> نتیجه هر تست در `/docs/backup/restore-reports/` (فاز 13).

## 6. سناریوهای پیشنهادیِ تاریخی (RPO/RTO محقق/تضمین‌شده نیست)
| سناریو | اقدام |
|---|---|
| خرابی دیسک/سرور | Restore آخرین Backup + RPO ≤ 6h |
| حذف/تغییر اشتباه داده | Restore نقطه‌ای از Backup قبلی + (V2: PITR) |
| آلودگی Ransomware | Backup رمزنگاری‌شده جدا + Key خارج → Restore از مقصد دوم |
| اشتباه Migration | Backup قبل از Migration (اجباری) + Rollback Migration (Section 47) |

---

## 7. مرز امنیتی مقصد بکاپ (OD-9 — الزام‌آور از Phase 1)

> **قاعده:** ریشهٔ بکاپِ **فعال** (Setting `backup.storage_path` یا پیش‌فرض) باید **بیرون از DocumentRoot** باشد. بکاپ حاوی همان PHI و یک dump کامل پایگاه داده است — `.htaccess` روی nginx خوانده نمی‌شود و مرز مجوز نیست.

| وضعیت | رفتار سیستم |
|---|---|
| مسیر فعال بیرون از webroot | عادی — بکاپ/verify/restore همه کار می‌کنند |
| مسیر فعال داخل webroot (Setting ناامن) | **Fail-Closed**: بکاپ جدید/حذف با خطای صریح `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT` رد می‌شود؛ مسیر **عوض نمی‌شود** (هیچ fallback بی‌صدایی نیست)؛ Health = FAIL |
| بکاپ‌های قدیمی داخل webroot | فقط **مبدأ legacy فقط‌خواندنی**: verify/preflight/restore آن‌ها کار می‌کند (پس از یافته‌نشدن در مخزن فعال، ریشهٔ خصوصی و ریشهٔ legacy جست‌وجو می‌شود) |
| Safety Backup پیش از Restore | **فقط** در مقصد امن خصوصی نوشته می‌شود: مخزن فعالِ قابل‌نوشتن، یا در پیکربندی ناامن، ریشهٔ خصوصی پیش‌فرض (`…/cpms-private/cpms-backups`). مبدأ legacy هرگز مقصد نیست ⇒ restore قفل نمی‌شود |
| نبود هیچ مقصد امنی | restore مخرب آغاز نمی‌شود (بدون Safety Backup، DROP/ایمپورت ممنوع) |

### Runbook: نصبی که `backup.storage_path` آن داخل webroot است

1. **تشخیص:** صفحهٔ «CPMS (سیستم)» → Health: `storage.backups = FAIL`؛ یا خطای `CLINIC_BACKUP_STORAGE_INSIDE_WEBROOT` هنگام بکاپ.
2. **مهاجرت خودکار:** در هر request ادمن/REST، محتوای آن ریشه (و ریشهٔ legacy قدیمی `wp-content/cpms-backups`) idempotent به ریشهٔ خصوصی منتقل می‌شود: کپی → تأیید sha256 → rename → تأیید → فقط آن‌گاه حذف مبدأ. تعارض محتوا بازنویسی نمی‌شود و در Operational Log (`CPMS_PRIVATE_STORAGE_MIGRATION`، area=`cpms-backups-unsafe-config`) گزارش می‌شود.
3. **اصلاح Setting (دست اپراتور):** بعد از migrate تمیز، `backup.storage_path` را خالی کنید (پیش‌فرض = ریشهٔ خصوصی) یا مسیر مطلقِ بیرون از webroot بدهید. سیستم این Setting را عمداً خودش تغییر نمی‌دهد.
4. **بازیابی اضطراری قبل از اصلاح Setting:** مجاز است — بکاپ‌های legacy با typed Backup ID قابل preflight/restore اند (Admin: فرم Restore؛ CLI: `bin/cpms backup restore <id> --yes`). Safety Backup به ریشهٔ خصوصی می‌رود و رویداد `RESTORE_APPLIED` در Audit، `source`، `legacy_unverified` و `safety_destination` را ثبت می‌کند.
5. ** nginx (فقط Defense in Depth):** تا پایان مهاجرت، بکاپ‌های داخل webroot را deny کنید: `location ^~ /wp-content/cpms-backups/ { deny all; return 404; }`

> **یادداشت تاریخیِ OD-9 — عبارتِ «Phase 15 ساخته نمی‌شود» منسوخ است.** از آن زمان Phase 15 آینه‌سازیِ دستیِ رمزنگاری‌شدهٔ یک بکاپِ محلی به یک مقصد S3 و بازسازیِ remote تا verifier و restore preflight را پیاده کرده است؛ جزئیات و حدود: [`s3-deployment-config.md`](s3-deployment-config.md). S3 scheduling، remote retention/delete، monitoring/alerting و destructive remote restore همچنان پیاده نشده‌اند؛ این یادداشت نه RPO/RTO می‌دهد و نه آمادگیِ کاملِ disaster recovery را ادعا می‌کند.
