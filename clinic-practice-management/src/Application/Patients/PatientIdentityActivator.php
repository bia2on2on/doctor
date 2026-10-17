<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Patients;

use ClinicCore\Domain\Patients\PatientIdentityException;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;

/**
 * فعال‌سازی هویتِ سازمانیِ بیمار برای «رکوردِ بالینیِ تازه‌ساخته‌شده»
 * (Organization Patient Identity activation — بنیانِ سیاستِ پس از انقضای
 * لایسنس؛ خودِ محدودیتهای تجاری در این لایه پیاده نمی‌شود).
 *
 * چرا یک کلاس جدا: `PatientIdentityService` مجموعهٔ primitiveهای هویت است
 * (ساخت/lookup/link صریح). «فعال‌سازی» یک سیاستِ مسیرِ ساخت است و باید در
 * همان مرزِ تراکنشیِ ساختِ بیمار اجرا شود. جداکردن آن، primitiveها را
 * دست‌نخورده نگه می‌دارد و سیاست را در یک نقطهٔ قابل‌تست متمرکز می‌کند.
 *
 * قواعدِ الزامیِ ایمنی (هیچ‌کدام قابل دور زدن از ورودیِ درخواست نیست):
 *
 *  1. Organization **فقط سمت سرور** از `clinic_id`ِ معتبرِ همان عملیات حل
 *     می‌شود (`cpms_clinics.organization_id`). هیچ `organization_id` یا
 *     `identity_id`ی از بدنهٔ درخواست پذیرفته نمی‌شود. Clinicِ بدونِ
 *     Organization ⇒ fail-closed (هیچ هویتی ساخته نمی‌شود).
 *
 *  2. **هیچ استنتاجِ هم‌هویتی از صفاتِ جمعیت‌شناختی**: موبایل، کد ملی، نام،
 *     تاریخ تولد و هر صفت دیگری هرگز باعثِ استفادهٔ مجددِ یک هویت نمی‌شوند.
 *     پیش‌فرضِ همیشگی = ساختِ هویتِ **تازه** برای هر رکوردِ بالینیِ جدید.
 *
 *  3. تنها راهِ استفادهٔ مجددِ هویت، یک **تأییدِ صریحِ قابل‌اتکا** است:
 *     وجودِ لینکِ صریحِ `cpms_patient_identity_links` بینِ همان WP User و
 *     یک هویت در **همان Organization**. آن جدول فقط با primitiveِ صریحِ
 *     `PatientIdentityService::link_user()` پر می‌شود و هرگز به‌صورت خودکار
 *     از روی موبایل ساخته نمی‌شود. اگر کاربر در آن Organization «دقیقاً یک»
 *     هویتِ لینک‌شده نداشته باشد (صفر یا بیش از یک = مبهم) ⇒ هویتِ تازه.
 *
 *  4. ایزولاسیونِ Clinic/Organization: رکوردِ بالینی باید به همان Clinicِ
 *     معتبر تعلق داشته باشد، وگرنه fail-closed. هیچ PHIِ بین‌کلینیکی از این
 *     مسیر خوانده یا نوشته نمی‌شود؛ فعال‌سازی فقط `patients.identity_id` را
 *     می‌نویسد.
 *
 *  5. هم‌زمانی: نوشتنِ لینک، یک UPDATEِ **شرطی** روی `identity_id IS NULL`
 *     است. اگر برنده نشویم، وضعیتِ پایدار دوباره خوانده می‌شود و فقط وقتی
 *     همان هویت است پذیرفته می‌شود؛ در غیر این صورت خطا ⇒ rollbackِ کلِ
 *     ساختِ بیمار. هیچ لینکِ موجودی هرگز بازنویسی نمی‌شود.
 *
 *  6. بیمارانِ legacy با `identity_id = NULL` دست‌نخورده می‌مانند: این کلاس
 *     فقط در مسیرِ ساختِ رکوردِ **جدید** صدا زده می‌شود و هیچ backfill یا
 *     ادغامِ خودکاری انجام نمی‌دهد.
 */
final class PatientIdentityActivator {

    public function __construct(
        private readonly PatientIdentityRepository $identities,
        private readonly PatientIdentityService $service
    ) {
    }

    /**
     * فعال‌سازی هویت برای رکوردِ بالینیِ تازه‌ساخته‌شده.
     *
     * باید **درونِ همان تراکنشِ** ساختِ بیمار فراخوانی شود: هر خطایی اینجا
     * باید ساختِ بیمار را هم برگرداند (fail-closed).
     *
     * @param int         $clinic_id            Clinicِ معتبرِ همان عملیات (سمت سرور).
     * @param int         $patient_id           رکوردِ بالینیِ تازه‌ساخته‌شده.
     * @param string|null $normalized_mobile    صفتِ lookup (نه کلیدِ هویت) — از قبل normalize شده.
     * @param int|null    $verified_wp_user_id  فقط برای مسیرهایی که هویتِ کاربر سمت سرور
     *                                          تأیید شده است (مثلاً OTP + مالکِ Hold). نبودِ آن
     *                                          یعنی «هیچ تأییدِ صریحی نیست» ⇒ هویتِ تازه.
     *
     * @return int شناسهٔ هویتِ سازمانیِ متصل‌شده.
     *
     * @throws PatientIdentityException هر شکستی ⇒ fail-closed.
     */
    public function activate_for_new_patient(
        int $clinic_id,
        int $patient_id,
        ?string $normalized_mobile = null,
        ?int $verified_wp_user_id = null
    ): int {
        $organization_id = $this->require_organization( $clinic_id );
        $patient         = $this->require_patient_in_clinic( $patient_id, $clinic_id );

        // رکوردِ از قبل متصل: idempotent فقط اگر هویت در همان Organization
        // باشد؛ هر چیز دیگری fail-closed (هرگز بازنویسی نمی‌شود).
        $already = $patient['identity_id'] !== null ? (int) $patient['identity_id'] : null;
        if ( $already !== null ) {
            return $this->assert_existing_link_in_organization( $patient_id, $already, $organization_id );
        }

        $identity_id = $this->resolve_verified_identity( $organization_id, $verified_wp_user_id )
            ?? $this->service->create_identity( $organization_id, $normalized_mobile );

        $linked = $this->identities->set_patient_identity_if_unlinked( $patient_id, $identity_id );
        if ( $linked < 1 ) {
            // بازندهٔ مسابقه: فقط اگر وضعیتِ پایدار دقیقاً همان هویت باشد قابل قبول است.
            $this->assert_persisted_identity( $patient_id, $identity_id );
        }

        // تأییدِ پس از نوشتن — هیچ «موفقیتِ اعلام‌شدهٔ بدونِ داده» پذیرفته نیست.
        $this->assert_persisted_identity( $patient_id, $identity_id );

        return $identity_id;
    }

    /**
     * Organizationِ مالکِ Clinic — سمت سرور و اجباری. Clinicِ ناشناخته یا
     * بدونِ Organization ⇒ fail-closed (هیچ هویتی ساخته نمی‌شود).
     */
    private function require_organization( int $clinic_id ): int {
        $organization_id = $this->identities->clinic_organization( $clinic_id );
        if ( $organization_id === null || $organization_id <= 0 ) {
            throw PatientIdentityException::of(
                'CLINIC_PATIENT_IDENTITY_ORG_UNRESOLVED',
                __( 'سازمانِ این کلینیک قابل تشخیص نیست؛ ثبت بیمار انجام نشد.', 'cpms' ),
                500,
                [ 'clinic_id' => $clinic_id ]
            );
        }

        return $organization_id;
    }

    /**
     * رکوردِ بالینی باید وجود داشته باشد و به همان Clinicِ معتبر تعلق داشته
     * باشد (مرزِ tenant — هیچ رکوردی از Clinic دیگر لمس نمی‌شود).
     *
     * @return array<string, mixed>
     */
    private function require_patient_in_clinic( int $patient_id, int $clinic_id ): array {
        $patient = $this->identities->patient( $patient_id );
        if ( $patient === null ) {
            throw PatientIdentityException::not_found( __( 'بیمار', 'cpms' ), $patient_id );
        }
        if ( (int) $patient['clinic_id'] !== $clinic_id ) {
            throw PatientIdentityException::patient_not_in_clinic( $patient_id, $clinic_id );
        }

        return $patient;
    }

    /**
     * استفادهٔ مجددِ هویت **فقط** با تأییدِ صریحِ قابل‌اتکا: لینکِ صریحِ
     * موجودِ کاربر↔هویت در همان Organization و دقیقاً یک مورد.
     *
     * عمداً هیچ جستجویی بر اساس موبایل/کد ملی/نام انجام نمی‌شود.
     */
    private function resolve_verified_identity( int $organization_id, ?int $verified_wp_user_id ): ?int {
        if ( $verified_wp_user_id === null || $verified_wp_user_id <= 0 ) {
            return null;
        }

        $links = $this->identities->identities_for_user( $organization_id, $verified_wp_user_id );
        if ( count( $links ) !== 1 ) {
            // صفر = تأییدی وجود ندارد؛ بیش از یک = مبهم. هر دو ⇒ هویتِ تازه.
            return null;
        }

        $identity_id = (int) $links[0]['id'];

        // دفاعِ عمقی: هویتِ لینک‌شده باید واقعاً در همان Organization باشد.
        $identity = $this->identities->find( $identity_id );
        if ( $identity === null || (int) $identity['organization_id'] !== $organization_id ) {
            return null;
        }

        return $identity_id;
    }

    /**
     * لینکِ از پیش موجود: فقط وقتی قابل قبول است که هویت در همان
     * Organization باشد (در غیر این صورت نشانهٔ نشتِ مرزِ tenant است).
     */
    private function assert_existing_link_in_organization(
        int $patient_id,
        int $identity_id,
        int $organization_id
    ): int {
        $identity = $this->identities->find( $identity_id );
        if ( $identity === null || (int) $identity['organization_id'] !== $organization_id ) {
            throw PatientIdentityException::record_already_linked( $patient_id, $identity_id );
        }

        return $identity_id;
    }

    /**
     * خواندنِ دوبارهٔ وضعیتِ پایدار — تنها سیگنالِ معتبرِ موفقیت.
     */
    private function assert_persisted_identity( int $patient_id, int $identity_id ): void {
        $row       = $this->identities->patient( $patient_id );
        $persisted = ( $row === null || $row['identity_id'] === null ) ? null : (int) $row['identity_id'];

        if ( $persisted !== $identity_id ) {
            throw PatientIdentityException::of(
                'CLINIC_PATIENT_IDENTITY_ACTIVATION_FAILED',
                __( 'فعال‌سازی هویت بیمار انجام نشد؛ ثبت بیمار برگردانده شد.', 'cpms' ),
                500,
                [ 'patient_id' => $patient_id ]
            );
        }
    }
}
