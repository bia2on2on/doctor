# راه‌اندازی یکپارچه بر اساس نوع مرکز (Onboarding Facility Wizard)

Receipt: 2026-10-15T07:22:10Z. Base: 08c64134a8547b967ba889d59d5e0eca0981f5b5. No open PRs at start.

## رفتار
- تنها ورودی قابل مشاهده: ویزارد `cpms-wizard`. نخستین صفحه (پیش از bootstrap و پس از آن گام «نوع مرکز») می‌پرسد: «چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟» و هر ۱۲ نوع مصوب را فارسی نشان می‌دهد (`FacilityTypeCatalog`).
- نوع مرکز فقط انتخاب نمایشی/پیکربندی است؛ مجوز نیست و مرز مستأجر (Organization → Clinic → Location) را تعریف نمی‌کند.
- انتخاب پیش از bootstrap فقط در user-meta همان مدیر وردپرس (POST + nonce + `manage_options`) ذخیره می‌شود؛ هیچ Clinic/عضویت/داده‌ای ساخته نمی‌شود. پس از bootstrap همان انتخاب در گام ویزارد با `setup.facility_type` (Clinic-scoped Settings، با احراز Clinic-local CONFIG) ذخیره و از user-meta قابل ازسرگیری است. GET هیچ نوشتنی ندارد.
- جریان‌ها: مطب ساده (نام، مکان اصلی/بیشتر، پزشک، منشی اختیاری، برنامه، رزرو، بازبینی)؛ درمانگاه چندپزشکه (هویت، تعداد پزشکان، مکان‌ها، پذیرش/مالی، برنامه، رزرو، بازبینی)؛ ساختمان پزشکی (هویت، مطب‌های مستقل، مدیریت مستقل، مرز درمانگاه، انتساب کارکنان، مکان‌ها، محدوده جداگانه)؛ بیمارستان/شبکه (ساختار سازمان، درمانگاه‌های سرپایی، مدیران مجاز، مکان‌ها، فرایندهای سرپایی).
- گام «مکان‌ها» به مدیریت مکان موجود پیوند می‌دهد و منطقهٔ زمانی IANA صریح برای هر مکان را الزام می‌داند (بدون پیش‌فرض، بدون fallback ردیف اول).

## محدودیت‌های صریح (blocker)
- ساخت چند Clinic مستقل: هیچ مدل اختیار سازمانیِ مصوب/منجمدی در مخزن یافت نشد؛ بنابراین ویزارد با اختیار مدیر وردپرس چند Clinic نمی‌سازد و این جریان با پیام `data-cpms-multi-clinic-blocked` مسدود و شفاف اعلام می‌شود. Bootstrap موجود فقط تک‌Clinic، یک‌باره، با تأیید صریح، nonce، قفل ردیف، audit اتمیک و بدون هویت پزشک خودکار است.
- بیمارستان: بخش بستری و HIS پشتیبانی نمی‌شود (`data-cpms-inpatient-unsupported`)؛ فقط فرایندهای سرپایی موجود پوشش داده می‌شود. ماژول ساختگی ساخته نشده است.

## امنیت
Nonce/CSRF، `manage_options` برای انتخاب پیش از bootstrap، Clinic-local CONFIG برای ذخیره در ویزارد، whitelist سمت سرور برای نوع/ساختار، عدم اعتماد به clinic_id ارسالی، عدم ساخت هویت پزشک، عدم اختیار بالینی سراسری، و حفظ سرویس‌های موجود (`FirstPracticeSetupService`, `LocationService`, Schedule/Booking موجود) بدون سرویس موازی یا migration جدید.

## سازوکار تطبیقی واقعی (PR #230، ادامه)
- `FacilityTypeCatalog::flow_step_ids/flow_required_step_ids` گام‌های واقعاً نمایش‌داده‌شده و الزامی/اختیاری هر جریان را تعیین می‌کند؛ `CpmsSetupWizard::active_steps` ناوبری، پیشرفت و گام بعدی را از همان فهرست می‌سازد (بیمارستان «سلامت سیستم» دارد، مطب ساده ندارد؛ ساختمان پزشکی گام رزرو ندارد).
- کامل‌بودن گام‌ها با دادهٔ واقعی راستی‌آزمایی می‌شود: نام canonical کلینیک، مکان فعال دارای منطقهٔ زمانی، تعداد پزشک فعال ≥ تعداد موردنظر (`setup.intended_clinicians`؛ چندپزشکه دست‌کم ۲)، و وجود برنامهٔ کاری واقعی (`schedule_days`). اعلام عدد به‌تنهایی گام را کامل نمی‌کند.
- ذخیرهٔ ویزارد برای گام الزامیِ ناقص جلو نمی‌رود و خطای فارسی نشان می‌دهد؛ اتمام (finish) برای جریان انتخاب‌شده همهٔ گام‌های الزامیِ باقی‌مانده را نام می‌برد و برای ساختمان پزشکی fail-closed است. نصب‌های قدیمیِ بدون انتخاب نوع مرکز، رفتار قبلی (نام کلینیک + یک پزشک) را حفظ می‌کنند.

## Organization Manager provisioning (2026-10-18)

The Classic wizard links multi-Clinic users to Organization Manager provisioning for the narrow transaction that creates a Clinic and its primary Location. Access is checked against an active Organization and an explicit Organization Manager membership; the selected Organization ID is context, not authority. The Owner has separately approved a narrow first-manager bootstrap: a currently authenticated WordPress Administrator may use the Classic RTL bootstrap screen to select an existing active Organization and existing WordPress user, confirm the choice, and submit a valid nonce. Bootstrap creates only that Organization membership and its audit event; it does not attach users to Clinics or grant clinical/financial/PHI access, and Administrator status does not bypass normal Organization or Clinic DENY checks. There is no implicit first-row selection or install/upgrade/login bootstrap. The 12-item facility catalogue is unchanged. Adaptive configuration and completion for every newly provisioned facility type remain unimplemented; the multi-Clinic setup steps do not claim to configure unsupported inpatient workflows.
