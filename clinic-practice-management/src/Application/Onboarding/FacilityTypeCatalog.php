<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

/**
 * Facility-type presentation catalogue for unified onboarding.
 *
 * A facility type is ONLY a presentation/configuration choice: it never
 * grants authorization, never defines a tenant boundary, and never creates
 * Clinics. Multi-Clinic provisioning has no approved Organization-level
 * authority model, so that flow is explicitly blocked (not silently faked).
 */
final class FacilityTypeCatalog {

    /**
     * @return list<array{id:string,label:string,flow:string}>
     */
    public static function all(): array {
        return [
            ['id' => 'doctor_practice', 'label' => 'مطب پزشک — انفرادی یا مشترک', 'flow' => 'simple_practice'],
            ['id' => 'general_clinic', 'label' => 'درمانگاه عمومی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'specialized_clinic', 'label' => 'درمانگاه تخصصی یا فوق‌تخصصی / پلی‌کلینیک', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'dental_clinic', 'label' => 'کلینیک یا درمانگاه دندانپزشکی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'diagnostic_center', 'label' => 'مرکز تشخیصی و پاراکلینیکی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'rehabilitation_center', 'label' => 'مرکز توانبخشی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'mental_health_center', 'label' => 'مرکز سلامت روان', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'specialized_outpatient_center', 'label' => 'مرکز درمان تخصصی سرپایی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'limited_surgery_center', 'label' => 'مرکز جراحی محدود یا سرپایی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'hospital', 'label' => 'بیمارستان', 'flow' => 'hospital_network'],
            ['id' => 'comprehensive_health_center', 'label' => 'مرکز جامع سلامت و خدمات بهداشتی', 'flow' => 'multi_doctor_clinic'],
            ['id' => 'other', 'label' => 'سایر مراکز درمانی', 'flow' => 'multi_doctor_clinic'],
        ];
    }

    /**
     * @return array{id:string,label:string,flow:string}|null
     */
    public static function find( string $id ): ?array {
        foreach ( self::all() as $type ) {
            if ( $type['id'] === $id ) {
                return $type;
            }
        }

        return null;
    }

    public static function is_valid( string $id ): bool {
        return self::find( $id ) !== null;
    }

    /**
     * Structure override values selectable alongside a facility type.
     *
     * @return array<string,string>
     */
    public static function structures(): array {
        return [
            'single'           => 'مطب یا مرکز تک‌واحدی',
            'multi_doctor'     => 'درمانگاه چندپزشکه',
            'medical_building' => 'ساختمان یا مجتمع پزشکی با چند مطب مستقل',
            'hospital_network' => 'بیمارستان یا شبکهٔ درمانی',
        ];
    }

    public static function resolve_flow( string $facility_id, string $structure = '' ): string {
        if ( $structure === 'medical_building' ) {
            return 'medical_building';
        }
        if ( $structure === 'hospital_network' ) {
            return 'hospital_network';
        }
        if ( $structure === 'multi_doctor' ) {
            return 'multi_doctor_clinic';
        }
        if ( $structure === 'single' ) {
            return 'simple_practice';
        }
        $type = self::find( $facility_id );

        return $type['flow'] ?? 'multi_doctor_clinic';
    }

    /**
     * Persian step titles for a flow.
     *
     * @return list<string>
     */
    public static function flow_steps( string $flow ): array {
        return match ( $flow ) {
            'simple_practice' => ['نوع مرکز', 'نام مطب', 'مکان اصلی و مکان‌های بیشتر در صورت نیاز', 'تنظیم پزشک', 'منشی (اختیاری)', 'برنامهٔ کاری', 'تنظیم رزرو نوبت', 'بازبینی آمادگی'],
            'medical_building' => ['نوع مرکز', 'هویت ساختمان یا سازمان', 'تعداد مطب‌های مستقل', 'مدیریت مستقل هر مطب', 'مرز مستقل هر درمانگاه', 'انتساب صریح کارکنان', 'مکان‌ها', 'محدودهٔ بالینی و مالی جداگانه'],
            'hospital_network' => ['نوع مرکز', 'ساختار سازمان', 'درمانگاه‌های سرپایی', 'مدیران مجاز', 'مکان‌ها', 'فرایندهای سرپایی پشتیبانی‌شده', 'بازبینی آمادگی'],
            default => ['نوع مرکز', 'هویت درمانگاه', 'تعداد پزشکان', 'مکان‌ها', 'کارکنان پذیرش و مالی', 'برنامهٔ کاری', 'تنظیم رزرو نوبت', 'بازبینی آمادگی'],
        };
    }

    public static function flow_supports_provisioning( string $flow ): bool {
        // No approved Organization-level authority model exists for creating
        // multiple independent Clinics; never fake it with WP admin authority.
        return $flow !== 'medical_building';
    }

    public static function flow_supports_inpatient( string $flow ): bool {
        // Inpatient/HIS workflows are unsupported for every known flow.
        return $flow !== '' && false;
    }

    public static function supports_multi_clinic_provisioning(): bool {
        return false;
    }
}
