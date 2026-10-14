# CPMS — سیستم مدیریت مطب

**Clinic Practice Management System (CPMS)** یک افزونهٔ WordPress برای مدیریت فرایندهای مطب است. کد افزونه در `clinic-practice-management/` قرار دارد و مستندات فنی در `docs/` نگهداری می‌شوند.

> **برای ایجنت‌ها و مشارکت‌کنندگان:** پیش از شروع، [`AGENTS.md`](AGENTS.md) را بخوانید و طبق آن به راهنمای عملیاتی [`docs/agent-guide.md`](docs/agent-guide.md) مراجعه کنید. وضعیت فازها و ترتیب کار را از [نقشهٔ راه رسمی](docs/roadmap/roadmap.md) بررسی کنید؛ از چک‌پوینت‌ها و گزارش‌های تاریخی به‌عنوان وضعیت فعلی استفاده نکنید.

## فناوری‌ها و پیش‌نیازها

- WordPress 6.4 یا بالاتر
- PHP 8.1 یا بالاتر (ماتریس CI: نسخه‌های 8.1 تا 8.4)
- MySQL 8
- Composer 2 برای نصب وابستگی‌ها و اجرای ابزارهای توسعه

## ساختار مخزن

| مسیر | کاربرد |
|---|---|
| `clinic-practice-management/` | افزونهٔ WordPress |
| `clinic-practice-management/src/` | کد اصلی افزونه با namespace `ClinicCore\` |
| `clinic-practice-management/tests/Unit/` | تست‌های واحد |
| `clinic-practice-management/tests/Integration/` | تست‌های یکپارچه‌سازی WordPress/MySQL |
| `clinic-practice-management/bin/cpms` | ابزار خط فرمان افزونه |
| `docs/` | معماری، تصمیم‌ها، API، امنیت، تست و راهنماهای پروژه |
| `docs/adr/` | تصمیم‌های معماری ثبت‌شده (ADR) |
| `.github/workflows/` | گردش‌کارهای CI و پذیرش |

برای نقشهٔ مستندات، به [`docs/README.md`](docs/README.md) و برای اطلاعات فنی نصب/CLI به [`clinic-practice-management/README.md`](clinic-practice-management/README.md) مراجعه کنید.

> **وضعیتِ جاری (راستی‌آزماییِ مستندات ۲۰۲۶-۱۰-۱۳):** مرجعِ واحدِ «وضعیت فعلی»، چک‌پوینتِ
> [`docs/project-current-state.md` — Custom Role current state](docs/project-current-state.md#custom-role-current-state-2026-10-13) است
> (`main = 8f8ca4bfc8e4cbc828f9689833392a1482a35d1a`؛ آخرین migration `2026_10_13_0027`).
> کار **نقش‌ها و مجوزهای سفارشی** در چهار برشِ مصوب تکمیل و ادغام شده است (PRهای #213، #214، #215، #216/#217/#219، #220، #221)؛
> مدلِ مجاز و مرزهای امنیتی در [`docs/decisions/2026-10-10-custom-role-permission-management-model.md`](docs/decisions/2026-10-10-custom-role-permission-management-model.md)
> و رویهٔ کاربری در [`docs/user-guide.md` §7](docs/user-guide.md) آمده است.
> **Phase 19 بسته و Phase 20 آغازنشده باقی می‌ماند، پوستهٔ کنونی همان Classic است، و هیچ‌یک از این‌ها ادعای آمادگیِ تجاری/انتشار نیست.**

## شروع توسعه

```bash
cd clinic-practice-management
composer install
```

اجرای تست‌ها:

```bash
composer test          # اجرای مجموعه‌تست‌های تعریف‌شده در phpunit.xml
composer test:unit     # فقط تست‌های Unit
```

تست‌های یکپارچه‌سازی به محیط WordPress و MySQL نیاز دارند؛ جزئیات را در [برنامهٔ تست](docs/testing/testing-plan.md) و گردش‌کار [CI](.github/workflows/ci.yml) ببینید.

## اصول مشارکت

- پیش از تغییر، محدودهٔ تسک، وضعیت مخزن و اسناد مرجع مرتبط را بررسی کنید.
- قراردادهای امنیت، مجوزها، محدوده‌بندی Clinic/Location و معماری ثبت‌شده در ADRها را رعایت کنید.
- تغییرات را محدود نگه دارید و تست‌ها و مستندات مرتبط را همراه آن به‌روز کنید.
- برای وضعیت و محدودیت‌های شناخته‌شده، [ثبت انحراف مستندات](docs/drift-register.md) را نیز بررسی کنید.

جزئیات قواعد اجرا، الگوهای کدنویسی و روند تحویل در [`docs/agent-guide.md`](docs/agent-guide.md) است.
