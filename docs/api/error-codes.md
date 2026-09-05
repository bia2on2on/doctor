# Error Codes Registry — CPMS

نسخه 1.0 | 2026-09-05 | مرجع: **ADR-0019** (تأیید نهایی کارفرما) | هر Endpoint جدید **باید** Error Codeهایش را در همین فهرست ثبت کند.

## قواعد

- Format: `CLINIC_{DOMAIN}_{DETAIL}` — Uppercase Snake_Case.
- کدها **Stable** و machine-readable هستند؛ تغییر Code = **Breaking Change** (فقط در ریلیزهای Major با اعلان).
- **`code`** = ثابت ماشین‌خوان (API/Log/Monitoring) — **`message`** = متن فارسی کاربر (بدون جزئیات فنی).
- HTTP Statuss استاندارد: `400` validation · `401` unauthenticated · `403` forbidden/nonce · `404` not-found (به‌عنوان 403 for IDOR) · `409` conflict · `422` business-rule · `429` rate/lockout · `502` provider-downstream · `500` internal.
- **Envelope (روتین F3):** در پاسخ REST، خودِ `code` top-level همان ثابت `CLINIC_*` است (`{"code":"CLINIC_*","message":"...","data":{"status":<http>,...}}`) — از نسخه F3 به بعد هیچ کد snake-case مشتق‌شده (`cpms_clinic_*`) در سطح top-level وجود ندارد. خطاهای اعتبارسنجی پارامتر توسط خود WP REST قبل از رسیدن به Handler ممکن است با کدهای بومی `rest_missing_callback_param`/`rest_invalid_param` (و در Cookie-Auth با Nonce اشتباه: `rest_cookie_invalid_nonce`) برگردند — اینها خارج از Registry ما هستند (قرارداد §0).

## Auth / OTP (بیمار)

| Code | HTTP | Meaning |
|---|---|---|
| `CLINIC_MOBILE_INVALID` | 400 | شماره موبایل نامعتبر/فرمت اشتباه |
| `CLINIC_OTP_COOLDOWN` | 429 | فاصله ارسال‌ها کمتر از Cooldown است |
| `CLINIC_OTP_DAILY_LIMIT` | 429 | سقف روزانه ارسال OTP تمام شده |
| `CLINIC_OTP_INVALID` | 400 | کد ارسالی نادرست است |
| `CLINIC_OTP_EXPIRED` | 400 | کد منقضی شده است |
| `CLINIC_OTP_LOCKED` | 429 | قفل موقت به‌دلیل تلاش‌های نادرست/سوءاستفاده |

## REST (عمومی)

| Code | HTTP | Meaning |
|---|---|---|
| `CLINIC_INVALID_NONCE` | 403 | Nonce نامعتبر (CSRF) |
| `CLINIC_UNAUTHORIZED` | 401 | Session/Authentication معتبر نیست |
| `CLINIC_PERMISSION_DENIED` | 403 | Capability کافی وجود ندارد |
| `CLINIC_RATE_LIMITED` | 429 | Rate Limit (تلاش‌های تکراری موقتاً محدود) |
| `CLINIC_VALIDATION_FAILED` | 400 | اعتبارسنجی ورودی ناموفق (جزئیات در `data.errors`) |
| `CLINIC_NOT_FOUND` | 404/403 | مورد موجود نیست (برای Entityهای Patient به‌عنوان 403) |
| `CLINIC_INVALID_TRANSITION` | 409 | Transition نامعتبر در State Machine (شامل تکرار/Double Complete) |
| `CLINIC_POLICY_VIOLATION` | 409 | نقض Policy کسب‌وکار (لغو/جابه‌جا خارج از بازه مجاز) |
| `CLINIC_OVERPAYMENT` | 422 | مبلغ پرداخت بیش از باقیمانده فاکتور |
| `CLINIC_IDEMPOTENCY_REPLAY` | 200 | تکرار درخواست با `Idempotency-Key` شناخته‌شده — **پاسخ Origin** بازمی‌گردد (خطا نیست) |
| `CLINIC_FILE_INVALID` | 400 | فایل آپلود‌شده نامعتبر (MIME/اندازه/نوع) |
| `CLINIC_CONFLICT` | 409 | Conflict نگارشی (مثلاً Handwriting: `client_revision` ≠ `version` سرور) |
| `CLINIC_INTERNAL_ERROR` | 500 | خطای پیش‌بینی‌نشده سرور (جزئیات فنی فقط در Log — نه در Response) |

## Slots / Booking (F1/F2/F3)

| Code | HTTP | Meaning |
|---|---|---|
| `CLINIC_SLOT_TAKEN` | 409 | اسلات در لحظه Claim گرفته شده است (Concurrency conflict) |
| `CLINIC_HOLD_EXPIRED` | 422 | Hold منقضی شده است |
| `CLINIC_DUPLICATE_IN_FLIGHT` | 409 | Idempotency: عملیات هم‌نام در حال انجام است |
| `CLINIC_DUPLICATE_APPOINTMENT` | 409 | نوبت تکراری (Retry/Double Submit با Idempotency-Key شناخته‌شده → پاسخ Origin، 200) |
| `CLINIC_DUPLICATE_ACTIVE_VISIT` | 409 | بیمار ویزیت Active دارد (قانون واحد Active Visit) |
| `CLINIC_DURATION_INVALID` | 400 | مدت نوبت نامعتبر (نه در جدول مجازها) |

## Finance (F1)

| Code | HTTP | Meaning |
|---|---|---|
| `CLINIC_ADJUSTMENT_EXCEEDS` | 422 | تخفیف/حساب بیشتر از مبلغ مجاز |
| `CLINIC_ADJUSTMENT_INVALID` | 400 | مقدار Adjust نامعتبر |
| `CLINIC_DISCOUNT_EXCEEDS` | 422 | تخفیف بیشتر از مبلغ آیتم |
| `CLINIC_ITEM_DISCOUNT_INVALID` | 400 | تخفیف آیتم نامعتبر |
| `CLINIC_INVOICE_ITEM_INVALID` | 400 | آیتم فاکتور نامعتبر |
| `CLINIC_PAYMENT_AMOUNT_INVALID` | 400 | مبلغ پرداخت نامعتبر |

## SMS (F2.5)

| Code | HTTP | Meaning | Retry-able? |
|---|---|---|---|
| `CLINIC_SMS_NOT_CONFIGURED` | 503 | Provider تنظیم نشده است | — |
| `CLINIC_SMS_PROVIDER_UNKNOWN` | 500 | Provider نامعتبر | — |
| `CLINIC_SMS_AUTH_METHOD_INVALID` | 500 | روش Auth Provider نامعتبر | — |
| `CLINIC_SMS_EVENT_UNKNOWN` | 400 | Event پیامک شناخته‌نشده | — |
| `CLINIC_SMS_MAPPING_INVALID` | 400 | نگاشت Provider (Endpoint/Response) نامعتبر | — |
| `CLINIC_SMS_MESSAGE_INVALID` | 400 | محتوای پیام نامعتبر | — |
| `CLINIC_SMS_TEMPLATE_INVALID` | 400 | قالب پیامک نامعتبر | — |
| `CLINIC_SMS_TEMPLATE_NOT_SUPPORTED` | 400 | قالب برای Provider فعلی پشتیبانی نمی‌شود | — |
| `CLINIC_SMS_MISSING_VARIABLE` | 400 | متغیر قالب موجود نیست | — |
| `CLINIC_SMS_UNKNOWN_VARIABLE` | 400 | متغیر ناشناخته در قالب | — |
| `CLINIC_SMS_MAX_ATTEMPTS` | 502 | سقف تلاش‌های Provider تمام شده | — |
| `CLINIC_SMS_PROVIDER_UNREACHABLE` | 502 | Provider دسترس‌نیست (Network/Timeout) | ✅ |
| `CLINIC_SMS_RATE_LIMITED` | 502 | Rate Limit سمت Provider | ✅ |
| `CLINIC_SMS_PROVIDER_ERROR` | 502 | خطای 5xx سمت Provider | ✅ |
| `CLINIC_SMS_NO_CREDIT` | 502 | اعتبار Provider تمام است | — |
| `CLINIC_SMS_INVALID_MOBILE` | 502 | Provider شماره را نامعتبر شناخته | — |
| `CLINIC_SMS_ENDPOINT_INVALID` | 500 | Endpoint سفارشی نامعتبر (SSRF/تأیید) | — |
| `CLINIC_SMS_ENDPOINT_UNRESOLVABLE` | 500 | Endpoint resolves نمی‌شود | — |
| `CLINIC_SSRF_BLOCKED` | 500 | هدف توسط SSRL Guard مسدود شده | — |
| `CLINIC_SMS_OTP_NO_RETRY` | — | (Internal) OTP در Queue Retry نمی‌شود (Fast-path) | — |

> `SMS_FAILED` یک **روداد Log** (Operational) است، نه Code خطای API.

## Licensing (Seam — F10 تکمیل می‌کند)

| Code | HTTP | Meaning |
|---|---|---|
| `CLINIC_LICENSE_BLOCKED` | 503 | لایسنس اجازه عملیات جدید را نمی‌دهد (Read-Only: Read/Export آزاد) — در F3 تعریف شد (Seam)، در F10 با جزئیات State تکمیل می‌شود |

## Rule ثبت کد جدید

1. قبل از Merge هر Feature: کدهای جدید این فایل ثبت شوند (PR بدون ثبت Code = ناقص).
2. هر Code دقیقاً یک HTTP Status دارد (معمولاً).
3. `Retry-able` فقط برای خطاهای Downstream transient.
4. کد در API `message` فارسی دارد؛ جزئیات فنی فقط در Log (`code` + `details`).
