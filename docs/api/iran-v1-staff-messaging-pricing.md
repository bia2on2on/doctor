# Iran V1 — staff messaging + visit pricing REST contract

Namespace: `clinic/v1`. All routes are **private**: `X-WP-Nonce` (wp_rest),
a logged-in WordPress user, an explicit trusted Clinic
(`X-CPMS-Clinic-Id`, from `RestClinicContext`/membership — never authority in
the payload), an active membership and the effective Clinic-scoped capability.
Every response uses the standard `{ "data": ... }` success envelope and the
`CLINIC_*` error envelope (ADR-0019). No WordPress comments, no WebSocket.

Amounts are **integer Rial** (`IRR`). Every UI string says «ریال»; there is no
rial↔toman conversion. `quantity` is at most two decimals (`cpms_invoice_items.quantity`).

## A. Messaging — `/staff/portal/messages`

| Method | Route | Capability | Rate limit | Result |
| --- | --- | --- | --- | --- |
| GET | `/context` | `cpms_visit_read` + doctor/receptionist class | 120/min | `clinic_id`, `organization_id`, `actor_class`, `counterparts[]`, `unread_total`, `retention_policy=append_only`, `max_message_chars=2000` |
| GET | `/threads?limit=50` | same | 120/min | `threads[]` (`id`, `counterpart`, `patient_id`, `visit_id`, `location_id`, `unread_count`, `last_message_excerpt`, `last_message_at`, `last_message_jalali`), `unread_total`, `polling` |
| POST | `/threads` | same | 120/min | `{recipient_wp_user_id, patient_id?, visit_id?}` → thread (201). `patient_id` and `visit_id` must be **both** present or both absent; server verifies `visit.clinic_id`, `visit.patient_id === patient_id`, `patient.clinic_id`, doctor ownership of the Visit and Location eligibility of both participants. |
| GET | `/threads/{id}/messages?since_id=0&limit=100` | same | 120/min | `messages[]` (`id`, `sender_wp_user_id`, `body`, `created_at`, `jalali_time`, `mine`), `last_id`, `unread_count`. Incremental authenticated polling; bounded to 100 rows. |
| POST | `/threads/{id}/messages` | same | **20 sends/min per actor** | `{body}` → message (201). Plain text only: HTML tags/control characters stripped, 1–2000 characters. |
| POST | `/threads/{id}/read` | same | 120/min | Monotonic `last_read_message_id`, `unread_count`, `unread_total`. |

**Isolation:** a thread is readable only by its two participants, and only when
the persisted `clinic_id` **and** `organization_id` equal the trusted scope. A
thread with a `location_id` additionally requires Location authorization for
the actor and the counterpart. Unknown/foreign ids share the non-enumerating
404 `CLINIC_NOT_FOUND` parity. No clinical note, prescription, medical file or
other PHI field is projected. Audit events (`MESSAGE_THREAD_CREATE`,
`MESSAGE_SEND`) store ids/lengths only — never message bodies.

**Retention:** append-only. There is no update/delete route for messages;
history is kept for the Clinic lifetime under the existing backup/DR policy.

## B. Doctor pricing — `/staff/portal/pricing`

| Method | Route | Capability | Result |
| --- | --- | --- | --- |
| GET | `/context?location_id=0` | `cpms_invoice_read` | doctor identity (server-derived clinician), `default_fee`, `currency=IRR`, `currency_label=ریال`, `can_edit`, caps |
| POST | `/default-fee` | `cpms_invoice_read` + `cpms_invoice_create` | `{amount, location_id?}` → previous/new default fee; `location_id=0` is clinic-wide |
| GET | `/visits` | `cpms_invoice_read` | today's Visits of this doctor only (server-derived clinician + eligible Locations) |
| GET | `/visits/{id}/billing` | `cpms_invoice_read` | itemized billing projection (below) |
| POST | `/visits/{id}/consultation-fee` | write caps | `{amount}` → per-Visit override (draft only) |
| POST | `/visits/{id}/charges` | write caps | `{description, amount, quantity?, note?}` → billing (201) |
| PUT | `/visits/{id}/charges/{charge_id}` | write caps | correct a draft charge |
| DELETE | `/visits/{id}/charges/{charge_id}` | write caps | soft-remove a draft charge (row + audit history survive) |

Writes are limited to 30/min per actor. A doctor can only price their own
Visits: the clinician identity is derived from `cpms_clinicians.wp_user_id`
and must equal `visit.clinician_id`; the Visit must be in the trusted Clinic
and an eligible Location. Draft-only: an active invoice, a captured payment,
a paid/checked-out Visit, or a charge already stamped into an invoice returns
409 `CLINIC_INVOICE_NOT_MODIFIABLE`.

## C. Receptionist finance board — `/staff/portal/finance`

| Method | Route | Capability | Result |
| --- | --- | --- | --- |
| GET | `/visits/{id}/billing` | `cpms_finance_read` + `cpms_invoice_read` | same itemized projection, CURRENT trusted Location only |
| POST | `/visits/{id}/invoice/auto` | `cpms_invoice_create` | issues ONE invoice from the doctor's effective fee + draft charges **through the existing `FinanceService::issueInvoice()`** (201) |

The billing projection always distinguishes:

- `default_fee` — doctor/clinic/location default,
- `override_fee` — this Visit only,
- `effective_fee` — override when set, otherwise default,
- `charges[]` — additional treatment/service items with `unit_amount`, `quantity`, `line_total`, `note`,
- `charges_total`, `expected_total`,
- `invoice` — persisted invoice `items[]`, `total`, `paid_amount`, `balance`, `status`, `payments[]`,
- `paid_amount`, `remaining_balance`, `payment_status`, `payment_status_label` (Persian),
- `currency=IRR`, `currency_label=ریال`, `editable`, `can_edit_pricing`.

Payment capture/void/refund stay on the **existing** routes and capabilities:
`POST /staff/portal/finance/invoices/{id}/payments` requires
`cpms_payment_create` and a UUID `Idempotency-Key`; a replay returns 200
`CLINIC_IDEMPOTENCY_REPLAY` and never creates a second payment. Doctors do
not receive capture/void authority from this slice.

## D. Portal modules (Staff Portal, no new top-level wp-admin menu)

- `?cpms-module=messages` → «پیام‌های داخلی مطب» — Persian RTL, responsive,
  unread badges, timestamps, authenticated incremental polling every 8s.
- `?cpms-module=pricing` → «تعرفه و خدمات ویزیت» — Persian RTL, responsive,
  billing refresh every 15s.
- The delivered Finance board gains an itemized billing panel with an
  «صدور فاکتور با تعرفه پزشک» action and 15s refresh of the open projection.

Both modules are appended to `StaffPortalShell::registered_modules()`; the
existing doctor/reception/finance/reports deep links and the single
«مدیریت مطب» wp-admin menu are unchanged.
