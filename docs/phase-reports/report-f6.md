# گزارش فاز F6 — مالی (Invoice/Payment/Adjustment/Void/Refund + Receipt + خلاصه + تعرفه‌ها + داشبورد مالی منشی + گارد Checkout + ADR-0026)

تاریخ: 2026-09-06 | وضعیت: **کامل — CI سبز** | محیط: PHP 8.1–8.4 / MySQL 8 / WordPress 6.7.2

> فازبندی مطابق `docs/roadmap/roadmap.md`: F6 = Services (G2)، Invoice/Payment/Adjustment/Void/Refund (D12–D15 + P3)، Receipt (D17)، داشبورد مالی منشی، Checkout Flow (D16/V14). در میانه فاز، **تصریح محصول کارفرما (نقش‌های پویا + Capability/Scope + Responsive — §29 قاعده فاز جاری)** رسید که با ADR-0026 ثبت و در همین فاز مستند شد، بدون گسترش Scope پیاده‌سازی.

## 1. خلاصه

- **چرخه مالی کامل شد (M2):** منشی → پزشک (F5) → مالی → خروج؛ صدور فاکتور با اقلام تعرفه/دستی، پرداخت ناقص/کامل Idempotent، اصلاح Credit/Debit، ابطال همان‌روز، بازپرداخت، رسید Deterministic جلالی + چاپ، خلاصه مالی روزانه/بازه‌ای.
- **قوانین سفت payment.md به کد برگردانده شد:** Invoice ≠ Payment؛ Paymentها Immutable (اصلاح فقط با Adjustment)؛ همه اعمال مالی + Transition ویزیت (V11/V12) در یک Transaction با Row Lock (M-7)؛ Idempotency در سطح خود جدول (M-1)؛ بیش‌پرداخت ممنوع (M-3)؛ رسید فقط از پرداخت‌های captured و بازتولید = همان محتوا (M-5).
- **گارد V14 در Checkout:** خروج با فاکتور باز → `CLINIC_NOT_SETTLED` 409 (با `open_invoices`/`balance`)؛ مسیر معافیت (waive با دلیل) عمداً باز ماند.
- **ADR-0026** (تصریح کارفرما): بررسی معماری §27 انجام شد — **بدون Blocker**؛ یافته‌ها و نقشه مهاجرت Debt نقش-محور مستند؛ F6 عمداً به Role Management/Scope/گزارشگری تفصیلی گسترش نیافت (قاعده فاز جاری D-15/§26).

## 2. ماتریس Acceptance Criteria

| معیار (roadmap/قرارداد) | وضعیت | شاهد |
|---|---|---|
| TP-02 (Idempotency پرداخت) | ✅ | `testPaymentIdempotencyReplayReturnsSamePayment` (سرویس: همان payment_id + تک‌ردیف) و `testRestPaymentFirst201Replay200` (REST: 201 → 200 + `CLINIC_IDEMPOTENCY_REPLAY`) و `testRestPaymentWithoutIdempotencyKeyIsRejected` (بدون هدر → 400) |
| TP-18 (دقت محاسبات) | ✅ | همه مبالغ ریالِ صحیح (integer) در Service + `InvoiceCalc` (تست Unit موجود)؛ ورودی کسری → 422 (`testIssueInvoiceRejectsInvalidInputs`)؛ کلید تسویه مؤثر = total−credit+debit (`testAdjustmentCreditAndDebit`) |
| TP-01 بخش مالی (ماتریس مجوز) | ✅ | `testPermissionMatrixOnFinanceOperations` (بیمار ۴۰۳ روی همه؛ پزشک ۴۰۳ روی payment_create/invoice_adjust، مجاز روی issue/void/refund/summary) + `testDoctorIssueInvoiceAllowedAndAdminIsTechnicalOnly` (admin فنی = فقط cpms_config — P-3) + `testServicesCrudAdminOnly` |
| D12 صدور + V11 | ✅ | `testIssueInvoiceMovesVisitAndAudits` (totals + INV-ymd-NNN + ویزیت → awaiting_payment + audit INVOICE_CREATE) |
| D13 پرداخت + I2/I3/V12 | ✅ | `testPartialThenFullPaymentSettlesVisit` (PAY-ymd-NNNN؛ فاکتور paid؛ ویزیت paid) |
| D14 ابطال (P2) | ✅ | `testVoidSameDayRestoresInvoiceButNotVisit` + `testVoidNextDayIsRejected` (`CLINIC_VOID_WINDOW_EXPIRED`) + `testVoidRequiresReason` |
| P3 بازپرداخت | ✅ | `testRefundPartialThenFull` (جزئی → captured می‌ماند؛ کامل → refunded + بازگردانی فاکتور؛ سقف → 422) |
| D15 اصلاح (M-6) | ✅ | `testAdjustmentCreditAndDebit` + `testAdjustmentCreditSettlesVisitAndValidates` (credit>بدهی → 422؛ دلیل الزامی؛ روی paid → 409) |
| D16/V14 Checkout | ✅ | `testVoidSameDayRestoresInvoiceButNotVisit` (NOT_SETTLED → تسویه مجدد → خروج) + `testCheckoutWithoutInvoiceStillWorks` (رگرسیون waive و paid بدون فاکتور) |
| D17 رسید (M-5) | ✅ | `testReceiptIsDeterministicAndJalali` (بازتولید = همان محتوا؛ جلالی 14xx/xx/xx؛ فقط captured) |
| D18 خلاصه | ✅ | `testSummaryRevenueAndOpenBalances` (total/by_method/count/open balances + تاریخ نامعتبر 422) |
| G2 تعرفه‌ها | ✅ | `testServicesCrudAdminOnly` (CRUD فقط admin؛ کد یکتا → 409؛ soft-delete + فیلتر active) |

## 3. تصمیم‌های طراحی (Design Decisions)

1. **واحد پول = ریالِ صحیح (integer):** توافق با `InvoiceCalc` و مثال‌های قرارداد (`amount: 500000`)؛ DECIMAL(12,2) در DB با مقدار صحیح ذخیره می‌شود؛ ورودی کسری → `CLINIC_VALIDATION_FAILED`. «واحد صغیر» طبق docblock خود `InvoiceCalc` در IRR همان ریال است.
2. **Idempotency پرداخت روی خود جدول (M-1):** `UNIQUE(invoice_id, idempotency_key)` — بدون `cpms_idempotency_keys` عمومی؛ Replay = 200 + همان payment_id (نه خطا)؛ پنجره رقابت درج با fallback به `findByIdempotencyKey` پوشش داده شد.
3. **عددگیری سریال INV/PAY:** قفل ردیف کلینیک (`SELECT ... FOR UPDATE` روی `cpms_clinics`) → سریال‌سازی همه MAX+1های موازی per-clinic.
4. **تفسیر M-6** («Refund/Void روی فاکتور paid ممنوع»): با استناد به کامنت خود `InvoiceMachine` (که M-6 را برای ابطالِ *فاکتور* I4 — فقط open بدون پرداخت — استناد می‌کند) و Side-Effectهای P2/P3 (بازگردانی فاکتور): ممنوعیت به **عملیات سطح فاکتور** (اصلاح D15 و ابطال فاکتور) تعلق دارد؛ ابطال/بازپرداختِ *پرداخت* مجاز است و فقط روی فاکتور `voided` مسدود است. در payment.md ثبت شد.
5. **InvoiceMachine self-loop partial→partial:** I2 بار اول `partial` می‌سازد؛ پرداخت ناقص بعدی وضعیت را عوض نمی‌کند (تجمیع `paid_amount` — M-3). در payment.md ثبت شد.
6. **کلید تسویه مؤثر:** balance = total − paid − credit + debit (اصلاحات در همه مسیرهای بازمحاسبه اعمال می‌شوند؛ ستون `total` فاکتور دست‌نخورده می‌ماند).
7. **خواندن تعرفه‌ها:** `cpms_invoice_read` **یا** `cpms_config` — admin فنی (فقط cpms_config طبق P-3) باید لیست تعرفه‌هایی که می‌سازد را ببیند؛ منشی/پزشک برای فاکتورسازی سریع (FR-14.9).
8. **رسید = JSON ساخت‌یافته + نمای چاپ UI (window.print):** PDF سمت سرور به Backlog رفت (الزام «Dependency غیرضروری اضافه نشود» — engineering-baseline؛ PDF Generation در فهرست background ops).

## 4. معماری و فایل‌های کلیدی

| فایل | نقش |
|---|---|
| `src/Application/Finance/FinanceService.php` + `FinanceException.php` | هسته D12–D18/P3/G2؛ فقط Capability-check (ADR-0026 D-1) |
| `src/Infrastructure/Repository/{Service,Invoice,Payment}Repository.php` | Data-Access (ADR-0021)؛ شماره‌گذاری سریال؛ revenueSummary |
| `src/Rest/FinanceController.php` | ۱۱ route با Nonce+Cap+Envelope (الگوی ClinicalController) |
| `src/Admin/SecretaryFinancePage.php` | داشبورد مالی منشی (زیرمنوی صف) — بدون PHI در HTML اولیه |
| `src/Application/Visits/VisitService.php` | `applyTransition` public+`$forceRole`؛ گارد NOT_SETTLED در checkout |
| `src/Domain/Machine/InvoiceMachine.php` | pay_partial/pay_full (+self-loop) با نقش system |
| `src/Bootstrap/App.php` | `financeService()` + ثبت Controller/Page |
| `tests/Integration/FinanceFlowTest.php` | ۱۷ تست Integration |
| `docs/adr/ADR-0026-*.md` | نقش‌های پویا + بررسی معماری + نقشه مهاجرت |

## 5. Audit (FR-21.1 / M-4)

اکشن‌های مرجع audit-strategy §2 عیناً استفاده شدند: `INVOICE_CREATE`، `PAYMENT_CAPTURE`، `PAYMENT_VOID`، `PAYMENT_REFUND`، `PAYMENT_ADJUST` (نه IMAGE صیغه‌های CREATED/CAPTURED) + `SETTING_UPDATE` با `resource_type=service` برای تعرفه‌ها. before/after مبلغی با کلیدهای نقطه‌دار (`invoice.paid_amount`, `invoice.balance`) و بدون PHI اضافی. Transitionهای V11/V12 به‌طور خودکار توسط `VisitService::applyTransition` با نقش `system` ثبت می‌شوند (`VISIT_INVOICE_READY`/`VISIT_SETTLED`).

## 6. باگ‌های واقعی که در چرخه CI کشف و رفع شدند (بدون skip/weaken تست)

1. **`listServices` فقط `cpms_invoice_read` می‌خواست** → admin فنی (فقط `cpms_config`) می‌توانست تعرفه بسازد ولی لیست نکند (CI run 33995895811) → خواندن OR شد.
2. **`InvoiceMachine` self-loop نداشت:** پرداخت ناقص دوم روی فاکتور `partial` → `InvalidTransitionException` (CI run 33995895811) → self-loop مطابق M-3 + ثبت در payment.md.
3. **رقابت درج Idempotency:** درجِ هم‌زمان با همان کلید (UNIQUE) → fallback به replay اضافه شد (Defense-in-depth؛ در تست‌های ترتیبی قابل مشاهده نیست ولی مسیر کد پوشش داده شده).
4. انتظارات تست خودم اصلاح شد: Totals (gross 800k → total 740k)، waive → `checked_out` مستقیم (V13 — نه paid)، تاریخ نامعتبر باید round-trip نشکند (`1405-06-15` سالِ معتبر است!)، و audit JSON با فاصله بعد از «:» encode می‌شود → تست‌ها JSON را decode می‌کنند.

## 7. تصمیم‌ها، انحراف‌ها و اقلام باز

1. **[Deviation — مستند]** بازگشت از paid: ابطال/بازپرداختِ پرداختِ بعد از تسویه، فاکتور را بازگردانی می‌کند اما وضعیت ویزیت را **برنمی‌گرداند** (V12 یک‌طرفه است). گارد NOT_SETTLED مانع خروج با بدهی باز می‌شود؛ نتیجه: ویزیت paid می‌ماند تا تسویه مجدد یا معافیت. اگر کارفرما بازگشت خودکار (paid→awaiting_payment) بخواهد، رویداد جدید در VisitMachine لازم است — **Open Item برای تصمیم**.
2. **[Deviation — مستند]** PDF سمت سرور رسید انجام نشد (بدون Dependency جدید طبق baseline) → رسید JSON + چاپ UI؛ PDF در Backlog (F8/پس از تصمیم کتابخانه).
3. **[Scope]** ابطال خود فاکتور (I4 — `cpms_invoice_void` در ماتریس) REST endpoint ندارد (در قرارداد D-table نیست) → پیاده نشد؛ `activeForVisit` فاکتور voided را «فعال» حساب نمی‌کند (صدور مجدد ممکن). Open Item برای تصمیم کارفرما اگر endpoint لازم شود.
4. **[Scope — طبق تصریح §26]** Role Management کامل + Scope + گزارشگری مالی تفصیلی (per-doctor/per-service/Aggregate-تفکیک-از-Detail) در F6 پیاده نشد → V2 طبق ADR-0026/roadmap؛ F8 گزارش‌ها با تفکیک Aggregate/Detail آماده Scope می‌شود.
5. **[Debt — نقشه مهاجرت ADR-0026]** موارد نقش-محور باقی‌مانده: `ClinicalService::requireRole` (۹ عمل)، `VisitService::roleForUser` (بازیگر ماشین)، انشعاب‌های visibility در `MedicalFileService` — با `RoleActorResolver` + Cap جایگزین در فاز Role Management مهاجرت می‌شوند؛ کد جدید F6 کاملاً Capability-محور است.
6. پرداخت آنلاین/بیمه (FR-14.11) طبق roadmap V2 — معماری Webhook+Reconciliation آماده؛ Overpayment به حساب بیمار (گزینه C در M-3) پیاده نشد.

## 8. Documentation Sync (همزمان)

- **api-contract:** ردیف‌های P3 (refund)، D12b (`GET /invoices/{id}`)، D12c (`GET /visits/{id}/invoice`)؛ به‌روزرسانی D12/D13/D14/D15/D16/D17/D18 (وضعیت‌ها، کدها، ریال صحیح، 201/200)؛ G2 با تفصیل CRUD + خواندن OR-cap
- **error-codes.md:** `CLINIC_NOT_SETTLED` 409، `CLINIC_VOID_WINDOW_EXPIRED` 409، `CLINIC_INVOICE_NOT_MODIFIABLE` 409 (بخش Finance → F1/F6)
- **state-machines/payment.md:** پیشوندهای `CLINIC_*` در جدول خطاها + `CLINIC_NOT_SETTLED` + یادداشت تفسیر M-6 + self-loop I2
- **audit-strategy.md:** یادداشت F6 (تعرفه‌ها با `SETTING_UPDATE`/resource=service)
- **ADR-0026 (جدید):** نقش‌های پویا + Capability/Scope + بررسی معماری + نقشه مهاجرت
- **SRS:** FR-1.13/1.14 (نقش پویا/Escalation) + NFR-SEC-13/14 + NFR-UI-5/6 (Responsive همه داشبوردها + UI مبتنی بر Capability)
- **auth-authorization.md §2.5** + **permission-matrix v1.4** (P-9..P-12 + §6 نقش‌های پویا/Scope/گزارشگری) + **roadmap** (V2/F8/F6✅) + **agent-guide** (قاعده D-1، وضعیت F6، نکات F7) + **CHANGELOG**

## 9. تست و CI

- **CI نهایی (سبز):** آخرین HEAD بررسی‌شده **73b8001** — run **33996797749** — ۵/۵ چک (Unit PHP 8.1/8.2/8.3/8.4 + Integration WP 6.7.2/MySQL 8). کد کامل F6 (7121d56) با run **33996401245** سبز شد؛ کامیت‌های پس از آن فقط مستندات‌اند. Integration = ۲۰۳ تست، ۰ skip (FinanceFlowTest = ۱۷).
- چرخه CI در فاز: 33995895811 (۷ خطا/شکست → رفع)، 33996278341 (۳ شکست انتظار تست → رفع)، 33996401245 (سبز).
- رگرسیون کامل قبلی‌ها: RestQueueTest (مسیرهای Checkout بدون فاکتور)، IdempotencyTest، VisitConcurrencyTest، PermissionMatrixTest (TP-10 doc↔کد) همه سبز ماندند.

## 10. جمع‌بندی

F6 طبق Scope تعریف‌شده کامل شد: چرخه مالی End-to-End با قوانین سفت payment.md، گارد خروج V14، داشبورد مالی منشی، و ثبت کامل تصریح محصول در معماری/مستندات (ADR-0026) بدون Blocker و بدون گسترش Scope. مایلستون **M2** (چرخه کامل مطب — Pilot داخلی) رسیده. مطابق پروتکل، **اینجا متوقف می‌شوم** — شروع F7 (دست‌خط/Canvas + Offline Sync) نیازمند تأیید کارفرما است.
