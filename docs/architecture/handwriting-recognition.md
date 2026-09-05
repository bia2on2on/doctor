# معماری تشخیص دست‌خط (Handwriting Recognition) — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | ADR-0010 | فعال‌سازی: V1.5

## 1. قانون سفت (Master Prompt §19)
> متن تشخیص‌داده‌شده **تا تأیید پزشک** هیچ اعتبار بالینی/نسخه‌ای ندارد و هرگز جایگزین دست‌نویس اصلی نمی‌شود.

## 2. Workflow
```
Doctor Save صفحه (HW_PAGE_SAVE)
   │  (اختیاری: دکمه «تشخیص» یا خودکار — Setting)
   ▼
OCR Job Queue (cpms_ocr_jobs: queued)
   │  Job Worker:
   ▼
[HandwritingProvider::recognize(pagePayload)]
   ├─ pagePayload = تصویر PNG (بدون PII: بدون نام/تاریخ/سربرگ)
   └─ Response: {text, confidence?, lines?}
   ▼
Job: extracted_text + status=success + review_status=pending
   ▼
UI پزشک: متن + (دست‌نویس اصلی کنار آن)
   ├─ پزشک ویرایش → PUT /ocr/jobs/{id}/review {action:confirm, confirmed_text}
   └─ پزشک رد   → review_status=rejected (extracted_text باقی می‌ماند، نامعتبر)
   ▼
confirmed_text → ذخیره + Searchable (LIKE/FTS — FR-19.1)
   ▼
(اختیاری) پزشک: «اسناد از متن تأییدشده» → ایجاد نسخه/یادداشت با source=ocr_confirmed + ocr_job_id
```

## 3. Interface Provider (قابل تعویض)
```php
interface HandwritingProvider {
  public function name(): string;
  public function recognize(HandwritingPage $page, ProviderContext $ctx): RecognitionResult;
  // RecognitionResult: { text, confidence?, raw?, provider, model, latency_ms }
}
// پیاده‌سازی: HttpProvider (JSON API هر Provider) — تنظیمات: endpoint, key, model, timeout
```
- **Persian-first:** قبل از انتخاب Provider، Acceptance Test روی ≥200 نمونه واقعی فارسی (شامل نام دارو/عدد/دوز)؛ گزارش دقت (CER/Word-Error) به کارفرما (R-04).
- Provider خارجی: Contract DPA + حذف PII از تصویر + Consent (T-13).

## 4. Job و Retries
- Queue: `cpms_jobs` (type=`ocr.recognize`) — خارج از Request کاربر (NFR-PERF-5).
- Retry: 3 بار با Backoff؛ شکست نهایی → `status=failed` + Operational Log + Alert (FR-10.5: Doctor بازگشت به «دست‌نویس اصلی» دارد — هیچ داتایی از دست نمی‌رود).
- Idempotency: یک Job موفق فعال = یک نتیجه؛ Job تکراری روی همان page → cancel قبلی.

## 5. دقت و اطمینان
| مورد | کنترل |
|---|---|
| نام دارو/دوز/عدد | UI: بخش‌های عددی/دوژ با Highlight + هشدار «تأیید دستی» |
| Confidence پایین (<0.6 اگر Provider بدهد) | UI: Warn قوی + پیشنهاد Re-scan/دستی |
| متن بدون تأیید | در هیچ Report/نسخه/جستجوی بالینی استفاده نمی‌شود (فقط Search روی confirmed — FR-19.1) |

## 6. امنیت داده
- تصویر موقت Provider: بعد از Response حذف (Server) — نگهداری فقط در `preview` نظامی.
- Log: بدون متن کامل در Operational (حداکثر length + hash) — T-14.
