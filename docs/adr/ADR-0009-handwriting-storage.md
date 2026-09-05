# ADR-0009 — ذخیره دست‌خط: Stroke Data فشرده در سطح صفحه (نه Row به‌ازای هر نقطه)

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§18: داده خام Stroke (x,y,pressure,ts,tool) ذخیره شود؛ «ذخیره هر نقطه به‌عنوان Row جدا بدون بررسی حجم» ممنوع؛ Performance بهینه.

## Decision
- `cpms_handwriting_pages.stroke_data` = `gzip(JSON)` آرایه Strokeها؛ یک صفحه = یک Row.
- Save = یک UPDATE (+ یک INSERT در `page_versions` برای History).
- Undo/Redo در کلاینت (Local) — وضعیت Server همیشه «آخرین Snapshot».
- Preview: PNG کلاینتی (اختیاری آپلود) + PDF (V1.5).
- Version: append-only + سیاست نگهداری (آخرین 10 + 30 روز) (FR-22.5).

## Consequences
+ I/O ثابت (مستقل از تعداد نقطه)؛ Read/Write ساده؛ امکان بازسازی/ادیت آینده (Stroke-level در JSON).
− ویرایش Stroke تکی Server-side در V1 نیست (کلاینت کار را انجام می‌دهد)؛ Conflict=page-level (ADR-0014).

## Alternatives
- Row به‌ازای هر Stroke (I/O بیشتر؛ نیاز به Reassembly — رد شد).
- فقط تصویر (رد شد: §18 صریح — بدون داده خام).
