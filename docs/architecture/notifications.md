# معماری لایه اعلان (Notifications) — CPMS

نسخه 1.0 | 2026-09-05 | فاز 7

## 1. اصول
- **N-1** لایه مستقل: Domain رویداد → `NotificationService` → Queue → Channel Adapter. کد بیزینس فقط رویداد publish می‌کند.
- **N-2** **Blocking ممنوع:** در Request اصلی فقط INSERT `cpms_notifications(status=queued)`؛ ارسال توسط Job (NFR-PERF-5 / FR-20.4).
- **N-3** وضعیت: `queued → sent → delivered(H) / failed(+retry) / cancelled`.
- **N-4** Retry: Backoff (1m/5m/15m)؛ بعد از `attempts ≥ 3` → `failed` + Operational Log + Alert.
- **N-5** Dedup: `dedupe_key` (مثلاً `apt:{id}:confirm`) — تکرار رویداد = یک اعلان.
- **N-6** Template با داده Jalali (مثلاً «نوبت شما پنج‌شنبه ۹ مهر ساعت ۱۰:۰۰» — تاریخ از UTC→timezone کلینیک→Jalali در Lایه Template).

## 2. Channelها
| Channel | V1 | Note |
|---|---|---|
| Internal (در-app) | ✅ | `cpms_notifications` + `GET /rt/notifications` (badge) |
| SMS | ✅ (Provider از Setting) | Interface `SmsGateway::send(mobile, templateId, params)` → Providerهای ایرانی (تصمیم کارفرما — R-01) |
| Email | ✅ (اختیاری) | PHPMailer/SMTP از Setting |
| Push | ❌ (V2) | Adapter آماده در معماری |

## 3. رویدادها (Event Catalog)
| Event | Channel | Timing |
|---|---|---|
| `OTP.issued` | SMS | فوری (Job < 5s) |
| `APPT.confirmed` | SMS+Internal | فوری |
| `APPT.reminder` | SMS+Internal | Job: شب قبل (21:00) + صبح (08:00) — Setting |
| `APPT.changed` (لغو/جابه‌جا) | SMS+Internal | فوری |
| `APPT.cancelled` | Internal (مطب) | فوری |
| `QUEUE.called` | Internal (منشی) + Real-time | فوری (حتی قبل از Job) |
| `QUEUE.ready_payment` | Internal (منشی) | فوری |
| `FOLLOWUP.reminder` | SMS+Internal | Job روی `suggested_date - 1d` |
| `PAYMENT.receipt` | (اختیاری) Email/SMS | فوری |

## 4. Real-time (مکمل)
- `QUEUE.called` هم به‌صورت Real-time (R1: `GET /rt/queue` Polling 3s) به منشی می‌رسد — اعلان SMS/Email ندارد (Internal فقط).
- Transport: V1 = Controlled Polling (ADR-0007)؛ Interface `RealtimeTransport` → تعویض WebSocket/SSE بدون تغییر UI.

## 5. مدیریت اعلان‌ها
- انصراف خودکار: لغو نوبت → Cancel اعلان‌های queued مرتبط (`dedupe`/`apt:{id}`).
- Quiet hours: SMS فقط بین 08:00–21:00 (Setting) — OTP مستثنا.
- اعلان‌های Internal: Archived بعد از 90 روز (read/unread).
