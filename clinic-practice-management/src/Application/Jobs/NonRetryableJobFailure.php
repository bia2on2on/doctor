<?php

declare(strict_types=1);

namespace ClinicCore\Application\Jobs;

/**
 * قراردادِ صریحِ «شکستِ غیرقابلِ تلاشِ مجدد» برای Jobها (M-4).
 *
 * هر `Throwable` که این نشانگر را implement کند **قطعی** تلقی می‌شود: تکرارِ همان
 * Job با همان ورودی/پیکربندی، همان شکست را بازتولید می‌کند (payload نامعتبر،
 * پیکربندیِ نامعتبر، ...). بنابراین `JobsDispatcher` آن را در **همان اولین
 * تلاش** نهایی (`failed`) می‌کند و Requeue/Backoffِ عمومی نمی‌دهد.
 *
 * قواعد:
 *  - تصمیم‌گیری فقط بر پایهٔ **نوعِ** Throwable است، هرگز بر پایهٔ متنِ پیام.
 *  - این نشانگر **نباید** به‌صورت جمعی به خانواده‌های عمومیِ خطا (مثل
 *    `ScopeRequiredException` یا همهٔ خطاهای پیکربندی) زده شود؛ هر مورد
 *    تصمیمِ محصولیِ جداگانه می‌خواهد.
 *  - `JobQueue` هیچ سیاستِ Clinic/tenant/مجوز نمی‌شناسد؛ فقط گذارِ نهاییِ فنی را
 *    فراهم می‌کند (`JobQueue::failTerminal()`).
 */
interface NonRetryableJobFailure
{
}
