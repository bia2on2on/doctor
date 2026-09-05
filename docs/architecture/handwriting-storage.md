# معماری ذخیره دست‌خط — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7 | ADR-0009 / ADR-0014

## 1. مدل داده (چرا این‌گونه؟)
| گزینه | نتیجه |
|---|---|
| هر نقطه یک Row | ❌ یک صفحه = ده‌ها هزار Row → I/O و Space فاجعه (ممنوع در Master Prompt §18) |
| هر Stroke یک Row با آرایه نقطه‌ها | ⚠️ قابل قبول ولی N Row/صفحه |
| **هر صفحه یک Row با JSON فشرده Strokeها** ✅ | یک Save = یک UPDATE؛ Read صفحه = یک Query؛ Undo/Redo در کلاینت |

**تصمیم (ADR-0009):** `cpms_handwriting_pages.stroke_data` = `gzip(JSON)` (Base64 در انتقال):
```jsonc
{
  "v": 1, "w": 1600, "h": 1200, "bg": "lined",
  "strokes": [
    { "id": "s1", "tool": "pen", "color": "#111", "size": 2.4,
      "points": [[x, y, pressure, ts_ms], ...] }
  ]
}
```
- `pressure`: 0..1 (null اگر دستگاه ندهد)؛ `ts_ms`: relative to stroke start.
- Preview: PNG توسط کلاینت (Canvas render) — آپلود اختیاری؛ PDF (V1.5، ساخت Server/Client).
- **Version:** هر Save موفق → ردیف در `cpms_handwriting_page_versions` (append-only، snapshot فشرده)؛ سیاست نگهداری (آخرین 10 + 30 روز) (FR-22.5).

## 2. چرخه Save (Online)
```
Tablet Canvas ──(autosave 5s/تغییر)──▶ PUT /handwriting/pages/{id}
  Body: {page_index, stroke_data(gzip+b64), w, h, client_revision}
  Header: Idempotency-Key
Server:
  1) Auth (Doctor) + Data-Access (visit)
  2) Row Lock صفحه
  3) Conflict Check: client_revision vs version
     ├─ متناظر → UPDATE + version+1 + INSERT version-row + 200 {version}
     └─ متفاوت → 409 CLINIC_CONFLICT {server_version, server_data_ref}
  4) Audit HW_PAGE_SAVE (رویداد، نه محتوا)
```

## 3. چرخه آفلاین (T-15 / ADR-0014)
```
[Canvas] ──▶ [IndexedDB draft (local)] ◀── Autosave
    │
    ├─ Online:  Sync Queue → PUT → Success → Local Delete (پیش‌فرض)
    │                                    → Failure → retry (backoff: 5s, 30s, 2m, 10m, ...)
    └─ Offline: Local حفظ می‌شود؛ UI وضعیت:
         [💾 در حال ذخیره] [✅ ذخیره شد] [📡 آفلاین] [⚠️ Sync ناموفق]
```
- **Conflict (2 تب/دو دستگاه):** سرور Version را برمی‌گرداند → UI: «نسخه به‌روزتر وجود دارد» + گزینه‌ها: (الف) ادامه روی نسخه سرور (ب) جایگزینی با نسخه من (با Reason) — تصمیم پزشک؛ هر دو Audit.
- **Local Security (T-16):** حذف Local بعد از Sync موفق (Setting `hw.local_retain=off|last|always`)؛ Encryption Local (AES-GCM با Key مشتق‌شده از Session) → V1.5.
- **حجم:** IndexedDB Limit (مثلاً 50MB/Document) — هشدار + Encourage Sync.

## 4. دسترسی و نمایش
- Read فقط: Doctor مالک Visit (+ Doctorهای مجاز V2)؛ Secretary/Patient ❌.
- Rendering Server-side (برای PDF/Preview): Canvas headless (V1.5) — برای V1: Preview کلاینتی کافی است.
- Annotation روی تصویر: Strokeها روی `image_ref` (medical_attachment) — همان ساختار.

## 5. Performance
- یک صفحه 2000 Stroke ≈ 200–800KB فشرده — مناسب LONGTEXT.
- Index: دسترسی اصلی از طریق `document_id/visit_id` — بدون Query روی محتوا.
- N+1: لود صفحه = 1 Query (document) + N Query pages (batch) یا 1 Query با IN (N صفحات).
