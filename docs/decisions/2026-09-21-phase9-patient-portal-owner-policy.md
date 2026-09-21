# سیاست‌های صادرشدهٔ مالک — Phase 9 Patient Portal و کانال‌های اعلان (OWNER-ISSUED PRODUCT POLICY)

تاریخ: 2026-09-21 · دامنه: Phase 9 (Patient Portal) + کانال‌های اعلان · وضعیت: صادرشده توسط مالک (Owner-issued) · ثبت: فقط مستندات (PR closure مستنداتِ Phase 9 Slice 2 / PR #96)

این سند سیاست‌های جدیدِ صریحِ مالک را به‌طور ماندگار ثبت می‌کند. این‌ها **سیاستِ
صادرشدهٔ مالک (OWNER-ISSUED PRODUCT POLICY)** هستند — **نه الزاماتِ اصلیِ SRS**.
متون تاریخیِ SRS عمداً بازنویسی نمی‌شوند تا وانمود شود این تصمیم‌ها از ابتدا
الزامِ SRS بوده‌اند؛ همان تاریخچه حفظ می‌شود و این سند سیاستِ فعلیِ عملیاتی را
روایت می‌کند (همان الگوی `2026-09-20-phase8-slice2-owner-decisions.md`).

این ثبت به‌تنهایی **شروعِ پیاده‌سازی، تأیید UX، یا ادعای تکمیل نیست** — پوستهٔ
Patient Portal با این closure شروع نشده است.

---

## A — هویتِ Portal (Portal identity)

1. **Only the Admin portal should visually reside inside WordPress wp-admin.**
2. **Patient Portal must use an independent professional CPMS frontend shell.**
3. Patient-facing UI must **not** expose:
   - WordPress admin sidebar,
   - admin toolbar/chrome,
   - WordPress update notices,
   - WordPress admin footer,
   - WordPress version strings.
4. Future non-admin portals should follow the independent-product principle unless
   a later owner policy changes it.

## B — کیفیت بصری (Visual quality)

1. Portal UI must be **professional, simple, Persian/RTL, responsive and
   commercially presentable**.
2. **Final visual appearance of user-facing portals requires owner visual approval.**
3. **Functional acceptance of PR #96 is NOT final visual-design approval.**
4. Do **not** interpret this policy as requiring endless redesign cycles:
   **one bounded visual review, then fix only concrete owner feedback.**

## C — ترتیبِ اجراییِ بعدیِ Phase 9 (Next Phase 9 ordering)

1. **BEFORE** adding major Patient Portal sections such as **Profile, Visits,
   Prescriptions or Files**, establish the **independent professional Patient
   Portal shell/design foundation**.
2. The shell should be **simple, fast and maintainable**.
3. WordPress may remain the underlying platform, but patient UI must **not
   visually resemble wp-admin**.
4. Prefer short/simple implementation and reuse before abstraction.
5. Do **not** introduce React/Vue/build tooling without objective need.

## D — کانال‌های اعلان (Notification channels)

**CURRENT VERSION:**

- Internal Patient Portal notifications.
- SMS.

**FUTURE VERSION:**

1. Optional external channels may include **Telegram** and **Iranian messaging
   platforms**.
2. External channels require **explicit patient consent/preferences**.
3. Notification **event/domain logic must remain separable** from delivery
   transport/provider.
4. Do **not** prematurely add provider classes/tables/settings/schema until that
   future requirement is implemented.

## E — جهت مهندسی (Engineering direction)

1. Prefer the **shortest clear professional implementation** before adding
   abstractions.
2. Preserve future extensibility **without speculative complexity**.
3. Maintain compatibility with the repository-supported **WordPress/PHP matrix**.
4. User-facing portals must remain **responsive and performance-aware**.
5. Admin customization should be strong where a **real product setting** is
   required, but do not add speculative settings merely for configurability.

---

## مرزِ این ثبت (Scope of this record)

- **فقط مستندات:** هیچ کد محصول، تست، workflow، asset، schema، migration،
  configuration یا رفتار runtime با این ثبت تغییر نکرد.
- **شروع کار نیست:** پوستهٔ مستقلِ Patient Portal و هیچ کانالِ اعلانِ جدیدی با
  این سند آغاز/مجاز نمی‌شود؛ اجرای هر مورد در آینده با scope/PR جداگانه و طبق
  ترتیبِ بخش C انجام می‌شود.
- **وضعیت فاز بدون تغییر از نظرِ تکمیل:** Phase 9 = IN PROGRESS (کامل نیست)؛
  Slice 1 = CLOSED؛ Slice 2 = CLOSED / TECHNICALLY COMPLETE (BOUNDED) via PR #96
  (merge `c18c18f4a33e3690d5002f6b21d56ad60334cdde`؛ accepted head
  `0f17aa2e562dd45b2bc0f1bfdd19b1895a093940`؛ آخرین migration همچنان `0022`).
