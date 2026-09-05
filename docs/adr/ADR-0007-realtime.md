# ADR-0007 — Real-time: Controlled Polling در V1 + Transport قابل تعویض

وضعیت: Accepted | تاریخ: 2026-09-05

## Context
§31: MVP می‌تواند Polling باشد ولی لایه باید قابل تعویض به WebSocket/SSE باشد.

## Decision
- V1: `GET /rt/queue?since={event_id}` (Etag/304) — منشی 3s، پزشک 5s؛ فقط وقتی Tab видим (Page Visibility API).
- کلاینت: یک Interface `RealtimeTransport` (`subscribe(topic, cb)`) — PollingAdapter فعلی.
- Server: Endpoint سبک (فقط تغییرات از `event_id`) + Index مناسب.
- V2: WebSocket/SSE Adapter — بدون تغییر UI.

## Consequences
+ بدون زیرساخت اضافه (Reverse-Proxy WS)؛ بار کم (304).
− تاخیر ≤3–5s (برای «فراخوان بیمار» قابل قبول است؛ اگر کارفرما <1s خواست: SSE سریع فعال می‌شود).
