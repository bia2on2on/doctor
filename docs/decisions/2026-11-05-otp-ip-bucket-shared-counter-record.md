# Known-limitation record — OTP per-IP bucket is global while its threshold is clinic-scoped

> **Document type:** governance **record** of an already-present design property
> and a known latent risk. **It is not an Owner decision, decides nothing, and
> changes no behaviour, no pin, no probe, no matrix leg, no assertion and no
> product contract.**
> **Recorded:** 2026-11-05 (PR #324 review follow-up).

## 1. The recorded design property (unchanged evidence)

`src/Application/Auth/OtpService::request()` enforces the OTP issuance per-IP
hourly guard as:

- **Bucket (counter):** `RateLimiter::hit('otp-ip:' . $ip, …, 3600)` — the key
  is **global per IP**; one `cpms_rate_limits` row is shared by all Clinics and
  all request contexts (scope of `cpms_rate_limits` is the still-open Owner
  decision Q6).
- **Threshold:** `otp.ip_hourly_max` (default **10**, preserving the former
  literal `hit(..., 10, 3600)` behaviour) is read from the `Settings` of the
  Clinic that owns the request context — the explicit persisted `$clinicId`
  when valid, otherwise the resolver branch.

So today: **the counter is global, the limit is clinic-scoped.**

## 2. Known latent risk (documented, not endorsed)

Two Clinics whose patients share one upstream IP (carrier NAT, office proxy)
consume the **same** counter. Whichever context requests first spends hits that
count against the *other* Clinic's threshold too. Severity is bounded — this is
the OTP **issuance** anti-abuse guard with a one-hour window, not a correctness
or tenant-data boundary — but the interference is real and cross-tenant.

## 3. Writing surface today

**Nothing in the product writes `otp.ip_hourly_max`.** The only value source is
`Settings::DEFAULTS['otp.ip_hourly_max'] = 10` plus the generic `Settings::set`
test/seed path; no admin editor, no exposed writer. No writer is added by PR
#324, deliberately.

## 4. Open decision linkage

Scoping the `otp-ip:` bucket key per Clinic — and any matching change to the
shared `cpms_rate_limits` model — is **explicitly deferred to Owner decision
Q6**, which remains **OPEN**. This record must not be cited as Q6 being
decided. Code comments at the gate and the integration tests
(`tests/Integration/OtpIpBucketTest.php`) pin the *current* shared-counter
semantics so a future Q6 change is deliberate and diff-visible.

## 5. Related records

- `LoginRateLimiter` header documents the same Q6 dependency for login buckets.
- 2026-10-12 third-party baseline diagnostic exception — unrelated lane;
  referenced here only as the record-vs-decision format precedent.
