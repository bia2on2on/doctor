# استراتژی نگهداری فایل پزشکی — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7

## 1. اصول
- **F-1** هیچ فایل پزشکی **URL عمومی** ندارد. خروجی فقط از `GET /files/{id}/stream` با Permission Check (T-06).
- **F-2** نام فایل روی دیسک تصادفی (sha256 + random) — نام اصلی فقط در DB (`original_filename`).
- **F-3** اعتبارسنجی: `finfo` (MIME واقعی) + Whitelist Extension + حداکثر حجم (20MB پیش‌فرض، Setting). عدم انطباق → `CLINIC_FILE_INVALID` (بدون ذخیره).
- **F-4** هر Read/Download فایل `doctor_private` یا `lab_result` → Audit `FILE_READ` (T-04/T-14).
- **F-5** حذف = Soft Delete (`deleted_at`)؛ پاک‌سازی فیزیکی فقط با Job طبق Retention + Approval.

## 2. چیدمان فیزیکی
```
/var/www/clinic-storage/            ← خارج از DocumentRoot
└── {clinic_id}/
    └── {stored_filename[:2]}/
        └── {stored_filename}.{ext}   ← مثال: a3/f9/a3f9...c2.pdf
wp-content/uploads/clinic/          ← فقط Previewهای سبک (اختیاری) با .htaccess deny
```
- اگر زیرساخت اجازه ندهد: `wp-content/clinic-files/` با `deny from all` + `.htaccess` + `index.php` خالی + نام تصادفی (لایه دوم: Stream مجوزیافته).
- **رمزنگاری:** سطح V1 = محافظت ساختاری (خارج webroot + نام تصادفی + ACL) + رمزنگاری دیسک سرور. **رمزنگاری هر-فایل (AES-256-GCM):** توصیه‌شده — تصمیم کارفرما (Setting `files.encrypt_at_rest`؛ اگر روشن: Key از Env).

## 3. جریان Upload
```
Client ──multipart──▶ API /files
  1) Auth + Capability + Data-Access (patient)
  2) RateLimit (10/hr)
  3) finfo MIME ∈ Whitelist؟ Extension متناظر؟ Size ≤ Max؟
  4) (V1.5) ClamAV Scan
  5) ذخیره با نام تصادفی + INSERT metadata
  6) Audit FILE_UPLOAD
```

## 4. جریان Download/Stream
```
GET /files/{id}/stream
  1) Auth + Capability + Data-Access (patient ownership / doctor / secretary→patient_visible)
  2) اگر حساس: Audit FILE_READ
  3) X-Sendfile/X-Accel-Redirect (اگر موجود) یا PHP ReadFile (با Read Time Limit)
  4) Header: Content-Type واقعی، Content-Disposition: attachment; filename="<اسلیمی>"
```

## 5. Preview
- تصویر: thumbnail کوچک (بدون PHI در URL — از همان Stream با size) برای لیست‌ها.
- PDF: نمایش در کلاینت (Blob از Stream)؛ **نه** URL مستقیم.

## 6. مهاجرت/گسترش
- `StorageDriver` interface: `LocalDriver` (V1) → `S3/MinIO Driver` (V2) — بدون تغییر Domain.
- Backup: فایل‌ها بخشی از برنامه بکاپ (docs/backup).
