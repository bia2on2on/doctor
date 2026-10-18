<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

/**
 * Facility-type presentation catalogue for unified onboarding.
 *
 * A facility type is ONLY a presentation/configuration choice: it never
 * grants authorization, never defines a tenant boundary, and never creates
 * Clinics. Multi-Clinic facility onboarding remains an adaptive-flow limitation;
 * creation is available only through the Organization-scoped provisioning
 * service and never through this presentation catalogue.
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
     * Adaptive Persian presentation profile for one facility type.
     *
     * Presentation/configuration ONLY: these labels never grant
     * authorization, never define a tenant boundary and never change
     * validation semantics (flow-level validation stays authoritative).
     * An empty/unknown id returns the generic legacy profile so old
     * installations keep the previous "نام کلینیک + پزشک" wording.
     * The 'guidance' entry is a short honest operational hint shown in the
     * flow summary; it never states a clinical/regulatory requirement.
     *
     * @return array{unit:string,clinician:string,clinicians:string,name_label:string,guidance:string}
     */
    public static function type_profile( string $id ): array {
        $defaults = [
            'unit'       => 'کلینیک',
            'clinician'  => 'پزشک',
            'clinicians' => 'پزشکان',
            'name_label' => 'نام کلینیک',
            'guidance'   => '',
        ];
        $profiles = [
            'doctor_practice'               => ['unit' => 'مطب', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مطب', 'guidance' => 'جریان مطب: نام مطب، مکان اصلی، پزشک و منشی اختیاری؛ برنامهٔ کاری و رزرو را ساده نگه دارید و در صورت نیاز مکان دوم اضافه کنید.'],
            'general_clinic'                => ['unit' => 'درمانگاه', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام درمانگاه', 'guidance' => 'درمانگاه عمومی: دست‌کم دو پزشک ثبت کنید و برنامهٔ کاری و ظرفیت رزرو هر پزشک را مشخص کنید؛ پذیرش و مالی اختیاری و قابل‌افزودن در هر زمان است.'],
            'specialized_clinic'            => ['unit' => 'درمانگاه', 'clinician' => 'پزشک متخصص', 'clinicians' => 'پزشکان متخصص', 'name_label' => 'نام درمانگاه', 'guidance' => 'درمانگاه تخصصی/پلی‌کلینیک: پزشکان متخصص را ثبت و برنامهٔ کاری هر تخصص را جداگانه تعیین کنید؛ مرز داده‌ها همان یک کلینیک است.'],
            'dental_clinic'                 => ['unit' => 'کلینیک دندانپزشکی', 'clinician' => 'دندانپزشک', 'clinicians' => 'دندانپزشکان', 'name_label' => 'نام کلینیک دندانپزشکی', 'guidance' => 'کلینیک دندانپزشکی: دندانپزشکان را ثبت کنید و برنامهٔ کاری و مدت نوبت را متناسب با نوع خدمات در گام رزرو تنظیم کنید.'],
            'diagnostic_center'             => ['unit' => 'مرکز تشخیصی', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز تشخیصی/پاراکلینیکی: فقط فرایندهای سرپایی پشتیبانی می‌شود؛ ظرفیت و زمان‌بندی پذیرش را در تنظیم رزرو و برنامهٔ کاری تعیین کنید.'],
            'rehabilitation_center'         => ['unit' => 'مرکز توانبخشی', 'clinician' => 'پزشک/درمانگر', 'clinicians' => 'پزشکان/درمانگران', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز توانبخشی: جلسات سرپایی را با برنامهٔ کاری پزشکان/درمانگران و زمان نوبت بلندتر در تنظیم رزرو پوشش دهید.'],
            'mental_health_center'          => ['unit' => 'مرکز سلامت روان', 'clinician' => 'پزشک/درمانگر', 'clinicians' => 'پزشکان/درمانگران', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز سلامت روان: پرونده‌ها در همان مرز کلینیک و با همان قواعد محرمانگی نگه‌داری می‌شود؛ ویزیت‌ها فقط سرپایی هستند.'],
            'specialized_outpatient_center' => ['unit' => 'مرکز درمان سرپایی', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز درمان تخصصی سرپایی: فقط فرایندهای سرپایی پشتیبانی می‌شود؛ برنامه و رزرو را متناسب با همان خدمات ثبت کنید.'],
            'limited_surgery_center'        => ['unit' => 'مرکز جراحی محدود', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز جراحی محدود/سرپایی: اتاق عمل را به‌عنوان مکان ثبت و برنامهٔ سرپایی را تعیین کنید؛ بستری شبانه و HIS پشتیبانی نمی‌شود.'],
            'hospital'                      => ['unit' => 'بیمارستان', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام بیمارستان (فقط محدودهٔ سرپایی)', 'guidance' => 'بیمارستان: فقط درمانگاه‌های سرپایی و فرایندهای سرپایی پشتیبانی می‌شود؛ بخش بستری و سامانهٔ جامع بیمارستانی (HIS) در این محصول وجود ندارد و در گام سلامت باید صریح تأیید شود.'],
            'comprehensive_health_center'   => ['unit' => 'مرکز جامع سلامت', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مرکز', 'guidance' => 'مرکز جامع سلامت: خدمات بهداشتی و درمانی سرپایی را با مکان‌ها، برنامهٔ کاری و رزرو پوشش دهید.'],
            'other'                         => ['unit' => 'مرکز', 'clinician' => 'پزشک', 'clinicians' => 'پزشکان', 'name_label' => 'نام مرکز', 'guidance' => 'سایر مراکز: همان گام‌های درمانگاه چندپزشکه با تنظیمات عمومی نمایش داده می‌شود.'],
        ];

        $profile = $profiles[ $id ] ?? [];

        return [
            'unit'       => (string) ( $profile['unit'] ?? $defaults['unit'] ),
            'clinician'  => (string) ( $profile['clinician'] ?? $defaults['clinician'] ),
            'clinicians' => (string) ( $profile['clinicians'] ?? $defaults['clinicians'] ),
            'name_label' => (string) ( $profile['name_label'] ?? $defaults['name_label'] ),
            'guidance'   => (string) ( $profile['guidance'] ?? $defaults['guidance'] ),
        ];
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

    /**
     * Facility-type/structure compatibility policy — the single source of
     * truth for the picker UI (disabled options), both save endpoints and
     * flow resolution:
     *  - '' (auto: matches the type) is always compatible;
     *  - an unknown structure is never compatible;
     *  - 'hospital_network' is compatible ONLY with the hospital type;
     *  - the hospital type is compatible ONLY with 'hospital_network' or '' —
     *    so a selected hospital can never be downgraded to a flow that skips
     *    the outpatient-only disclosure, the health step or the explicit
     *    acknowledgment ('single'/'multi_doctor'/'medical_building' are refused).
     * Multi-Clinic medical_building provisioning stays fail-closed elsewhere.
     */
    public static function structure_is_compatible( string $facility_id, string $structure ): bool {
        if ( $structure === '' ) {
            return true;
        }
        if ( ! isset( self::structures()[ $structure ] ) ) {
            return false;
        }
        $is_hospital = ( $facility_id === 'hospital' );
        if ( $structure === 'hospital_network' ) {
            return $is_hospital;
        }

        return ! $is_hospital;
    }

    /**
     * Persian refusal message for an incompatible type/structure pair.
     */
    public static function structure_incompatibility_message( string $facility_id, string $structure ): string {
        if ( $facility_id === 'hospital' ) {
            return 'ساختار انتخاب‌شده با بیمارستان سازگار نیست: بیمارستان فقط با ساختار «بیمارستان یا شبکهٔ درمانی» (یا پیش‌فرض همان نوع مرکز) راه‌اندازی می‌شود و جریان‌های مطب/درمانگاه/ساختمان پزشکی محدودیت «فقط سرپایی» را دور نمی‌زنند.';
        }

        return 'ساختار «بیمارستان یا شبکهٔ درمانی» فقط برای نوع مرکز بیمارستان قابل انتخاب است؛ برای این نوع مرکز، ساختار نامعتبر یا نامتناسب است.';
    }

    public static function resolve_flow( string $facility_id, string $structure = '' ): string {
        // Defense in depth: an incompatible override (e.g. hand-crafted
        // user-meta hospital+single) is ignored, never resolved — a selected
        // hospital always keeps the hospital_network flow and its outpatient
        // safety steps. Save endpoints additionally refuse the combination.
        if ( $structure !== '' && ! self::structure_is_compatible( $facility_id, $structure ) ) {
            $structure = '';
        }
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
            default => ['نوع مرکز', 'هویت درمانگاه', 'تعداد پزشکان', 'مکان‌ها', 'کارکنان پذیرش و مالی (اختیاری)', 'برنامهٔ کاری', 'تنظیم رزرو نوبت', 'بازبینی آمادگی'],
        };
    }

    public static function flow_supports_provisioning( string $flow ): bool {
        // Independent Clinic creation exists in OrganizationClinicProvisioningService,
        // but this wizard flow still cannot provision/configure a facility end-to-end.
        // Keep this gate closed instead of marking an unprovisioned wizard complete.
        return $flow !== 'medical_building';
    }

    public static function flow_supports_inpatient( string $flow ): bool {
        // Inpatient/HIS workflows are unsupported for every known flow.
        return $flow !== '' && false;
    }

    public static function supports_multi_clinic_provisioning(): bool {
        return false;
    }

    /**
     * Wizard step ids actually shown for a flow (real step selection).
     *
     * @return list<string>
     */
    public static function flow_step_ids( string $flow ): array {
        return match ( $flow ) {
            'simple_practice' => ['facility', 'clinic', 'locations', 'doctors', 'users', 'schedules', 'booking', 'review', 'finish'],
            'medical_building' => ['facility', 'clinic', 'locations', 'review', 'finish'],
            'hospital_network' => ['facility', 'clinic', 'locations', 'doctors', 'users', 'schedules', 'booking', 'health', 'review', 'finish'],
            default => ['facility', 'clinic', 'locations', 'doctors', 'users', 'schedules', 'booking', 'review', 'finish'],
        };
    }

    /**
     * Required wizard step ids for a flow; everything else shown is optional.
     *
     * @return list<string>
     */
    public static function flow_required_step_ids( string $flow ): array {
        return match ( $flow ) {
            'simple_practice' => ['facility', 'clinic', 'locations', 'doctors', 'schedules', 'booking', 'review'],
            'medical_building' => ['facility', 'clinic', 'locations', 'review'],
            'hospital_network' => ['facility', 'clinic', 'locations', 'doctors', 'schedules', 'booking', 'health', 'review'],
            // 'users' (reception/finance staff) is deliberately OPTIONAL for every flow:
            // the step links to the staff screen and nothing verifies a real staff row here,
            // so requiring it would be an unenforceable declaration. Optional, not faked.
            default => ['facility', 'clinic', 'locations', 'doctors', 'schedules', 'booking', 'review'],
        };
    }
}
