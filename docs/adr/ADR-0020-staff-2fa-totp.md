# ADR-0020 — 2FA برای حساب‌های Privileged (TOTP RFC 6238، Policy بر پایه Access)

تاریخ: 2026-09-05 | وضعیت: **تأیید نهایی کارفرما** | منبع: Engineering Baseline §8

## تصمیم

1. **روش: TOTP (RFC 6238)** — اپلیکیشن Authenticator (SHA-1/SHA-256، 6 رقم، 30 ثانیه، ±1 window).
   - **SMS-OTP روش Recovery پیش‌فرض 2FA نیست** (وابستگی به پنل SMS + نشتی به Third-party).
   - ماژول SMS و OTP بیمار (F2.5) **کاملاً مستقل** از Staff 2FA باقی می‌ماند (دو زیرساخت جدا با مرز واضح).
2. **Policy بر پایه Access، نه Role:** 2FA برای هر کاربری **الزامی** است که Medical/Sensitive Access داشته باشد:
   - Doctor و Secretary فعلی (دسترسی PHI) ✅
   - هر Role/User آینده که دسترسی صریح به **PHI، اطلاعات پزشکی، مالی حساس، Export، Audit یا تنظیمات حساس** بگیرد ✅ (Gate روی Grant دسترسی اعمال می‌شود، نه روی Role)
   - Patient: خارج (جریان OTP خودش — §25 ADR-0025)
3. **Recovery Codes:** 10 کد یک‌بارمصرف، هنگام Enable تولید؛ هر کد فقط یک‌بار (consumed)؛ باقیمانده در UI نمایش داده می‌شود.
4. **امنیت Secretها:**
   - TOTP Secret: **Vault (AES-256-GCM، ADR-0025)** — plaintext هرگز در DB/Log/REST.
   - Recovery Codes: فقط **Hash** (SHA-256) ذخیره می‌شود — خام Log/ذخیره نمی‌شود.
   - نمایش Secret: فقط یک‌بار در زمان Enable (QR + متن) — بعداً قابل بازیابی نیست (Reset لازم است).
5. **Reset/Disable:** فقط با Permission مناسب (خود کاربر هنگام Log-in + Admin فنی با `cpms_config`) و **Audit** (`TFA_ENABLED`, `TFA_RESET`, `TFA_DISABLED`).
6. **جریان Login:**
   ```
   Password/Session معتبر → Access-Policy Check (دارای Medical/Sensitive Access؟)
     → بله: 2FA Challenge (TOTP یا Recovery Code) → Session کامل
     → خیر: Session کامل (بدون 2FA)
   ```
   - تا 2FA Complete نشده: فقط `verify/submit` 2FA + `logout` مجازند (نه REST پزشکی).
   - شکست تکراری TOTP → Rate Limit + Lockout موقت (Policy: 5 تلاش / 15 دقیقه — از Settings، مثل OTP).

## Schema (Migration در فاز پیاده‌سازی)

```sql
cpms_user_2fa (
  wp_user_id PK,
  totp_secret_sealed JSON,     -- Vault (ADR-0025)
  algorithm ENUM('SHA1','SHA256') DEFAULT 'SHA256',
  digits TINYINT DEFAULT 6,
  period TINYINT DEFAULT 30,
  recovery_codes_sealed JSON,  -- Vault (فقط Hash داخل — دو لایه)
  recovery_used_count TINYINT,
  enabled_at DATETIME(3),
  last_verified_at DATETIME(3),
  locked_until DATETIME(3) NULL
)
```

## Capabilityها

- هیچ Capability جدید برای **دسترسی** (2FA شرط ورود است، نه مجوز داده).
- `cpms_2fa_admin` (فنی): Reset/Disable توسط Admin — با Audit. (به Matrix v1.3 در فاز پیاده‌سازی)

## فاز پیاده‌سازی

**F5** (اولین فاز استفاده پزشک) — قبل از اولین دسترسی واقعی پزشک به PHI. Architecture `TwoFactorGate` از همان F5 در Login/REST middleware قرار می‌گیرد (بدون نیاز به Refactor بعدی).

## Consequences

- امن‌تر از SMS-2FA؛ بدون وابستگی به پنل پیامک.
- Recovery Codes یک‌بارمصرف = مسیر بازیابی بدون SMS.
- مستقلی کامل: خرابی SMS Provider **اثری** روی ورود Staff ندارد (و برعکس).
