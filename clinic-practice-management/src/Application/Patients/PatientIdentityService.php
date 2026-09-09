<?php

declare( strict_types=1 );

namespace ClinicCore\Application\Patients;

use ClinicCore\Domain\Patients\PatientIdentityException;
use ClinicCore\Domain\Validators\MobileValidator;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\PatientIdentityRepository;

/**
 * سرویس هویت بیمار — Phase 2 foundation (C5 / AD-14 / P-B).
 *
 * مدل مصوب:
 *   Organization → Patient Identity (هویت سازمانی؛ internal_ref تغییرناپذیر)
 *   Clinic → Clinical Patient Record (cpms_patients — کاملاً Clinic-scoped)
 *
 * قواعد الزام‌آور:
 *  - موبایل «صفت» است، نه کلید هویت: همان موبایل ⇒ هیچ ادغام/تطبیق
 *    خودکار و مخربی انجام نمی‌شود؛ فقط فهرست کاندید (deterministic).
 *  - همهٔ lookupها Organization-scoped؛ هیچ fallback/فرضی وجود ندارد
 *    (نه clinic_id=1، نه organization فرضی).
 *  - هویتِ Organization دیگر «موجود» نیست (NOT_FOUND یکسان — anti-enum).
 *  - هیچ دسترسی بالینی بین‌کلینیکی ضمنی: خواندن رکورد بالینی فقط با
 *    Clinic صریحِ همان Organization (fail-closed ⇒ خالی).
 *  - linking کاربر صریح است (هرگز خودکار با موبایل) و Organization-bound.
 *  - این کلاس فقط primitive است؛ REST/policy/merge-workbook فاز ۳/۹ نیست.
 */
final class PatientIdentityService {

    public function __construct(
        private readonly CpmsDb $db,
        private readonly PatientIdentityRepository $identities
    ) {
    }

    // ================= Identity primitives =================

    /**
     * ساخت هویت سازمانی — بدون هیچ اثر جانبی روی هویتهای موجود
     * (بدون merge/link خودکار). موبایل اختیاری است (فقط صفت lookup).
     *
     * @return int شناسهٔ هویت جدید
     */
    public function create_identity( int $organization_id, ?string $mobile = null ): int {
        if ( ! $this->identities->organization_exists( $organization_id ) ) {
            throw PatientIdentityException::not_found( __( 'سازمان', 'cpms' ), $organization_id );
        }

        $normalized = null;
        if ( $mobile !== null && $mobile !== '' ) {
            $normalized = $this->normalize_or_fail( $mobile );
        }

        $now = $this->db->nowUtcSql();
        $id  = $this->identities->insert(
            [
                'organization_id'   => $organization_id,
                'internal_ref'      => $this->generate_internal_ref(),
                'normalized_mobile' => $normalized,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]
        );

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find( int $identity_id ): ?array {
        return $this->identities->find( $identity_id );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find_by_internal_ref( string $internal_ref ): ?array {
        return $this->identities->find_by_internal_ref( $internal_ref );
    }

    /**
     * کاندیدهای هویت با این موبایل «در یک Organization» — قطعی (ORDER BY id).
     * هیچ نتیجه‌ای از Organizationهای دیگر برگردانده نمی‌شود.
     *
     * @return list<array<string, mixed>>
     */
    public function lookup_by_mobile( int $organization_id, string $mobile ): array {
        $normalized = $this->normalize_or_fail( $mobile );

        return $this->identities->find_by_mobile( $organization_id, $normalized );
    }

    /**
     * شناسهٔ کاندیدهای duplicate (همان lookup؛ خروجی id-only).
     *
     * @return list<int>
     */
    public function duplicate_candidates( int $organization_id, string $mobile ): array {
        return array_map(
            static fn( array $row ): int => (int) $row['id'],
            $this->lookup_by_mobile( $organization_id, $mobile )
        );
    }

    /**
     * تغییر صفت موبایل — هویت (id/internal_ref) تغییر نمی‌کند و هیچ
     * کاندید دیگری merge/حذف نمی‌شود.
     */
    public function set_mobile( int $identity_id, string $mobile ): void {
        $this->require_identity( $identity_id );
        $normalized = $this->normalize_or_fail( $mobile );

        $this->identities->update(
            $identity_id,
            [
                'normalized_mobile' => $normalized,
                'updated_at'        => $this->db->nowUtcSql(),
            ]
        );
    }

    // ================= Identity ↔ Clinical Record =================

    /**
     * اتصال رکورد بالینی (cpms_patients) به هویت — Clinic باید به همان
     * Organization تعلق داشته باشد و بیمار به همان Clinic.
     */
    public function link_clinical_record(
        int $organization_id,
        int $identity_id,
        int $clinic_id,
        int $patient_id
    ): void {
        $this->require_identity_in_org( $organization_id, $identity_id );

        $clinic_org = $this->identities->clinic_organization( $clinic_id );
        if ( $clinic_org !== $organization_id ) {
            throw PatientIdentityException::clinic_not_in_organization( $clinic_id, $organization_id );
        }

        $patient = $this->identities->patient( $patient_id );
        if ( $patient === null ) {
            throw PatientIdentityException::not_found( __( 'بیمار', 'cpms' ), $patient_id );
        }
        if ( (int) $patient['clinic_id'] !== $clinic_id ) {
            throw PatientIdentityException::patient_not_in_clinic( $patient_id, $clinic_id );
        }

        $existing = $patient['identity_id'] !== null ? (int) $patient['identity_id'] : null;
        if ( $existing !== null && $existing !== $identity_id ) {
            throw PatientIdentityException::record_already_linked( $patient_id, $existing );
        }

        if ( $existing === null ) {
            $this->identities->set_patient_identity( $patient_id, $identity_id );
        }
    }

    /**
     * رکوردهای بالینیِ هویت در «یک» Clinic — ایزوله: هیچ رکوردی از
     * Clinicهای دیگر (حتی همان Organization) برگردانده نمی‌شود.
     * Clinic خارج از Organization ⇒ خالی (fail-closed؛ افشا نمی‌شود).
     *
     * @return list<array<string, mixed>>
     */
    public function clinical_records_for_clinic(
        int $organization_id,
        int $identity_id,
        int $clinic_id
    ): array {
        $identity = $this->identities->find( $identity_id );
        if ( $identity === null || (int) $identity['organization_id'] !== $organization_id ) {
            return [];
        }

        if ( $this->identities->clinic_organization( $clinic_id ) !== $organization_id ) {
            return [];
        }

        return $this->identities->clinical_records_for_clinic( $clinic_id, $identity_id );
    }

    // ================= Identity ↔ WP User =================

    /**
     * لینک صریح WP User ↔ Identity (Organization-bound). هرگز خودکار با
     * موبایل انجام نمی‌شود؛ mobile_at_link فقط snapshot تاریخچه است.
     */
    public function link_user(
        int $organization_id,
        int $identity_id,
        int $wp_user_id,
        string $mobile_at_link
    ): void {
        $this->require_identity_in_org( $organization_id, $identity_id );

        if ( $wp_user_id <= 0 || get_userdata( $wp_user_id ) === false ) {
            throw PatientIdentityException::not_found( __( 'کاربر وردپرس', 'cpms' ), $wp_user_id );
        }
        if ( $this->identities->user_link_exists( $identity_id, $wp_user_id ) ) {
            throw PatientIdentityException::user_link_exists( $identity_id, $wp_user_id );
        }

        $snapshot = $this->normalize_or_fail( $mobile_at_link );

        $this->db->transactional(
            function () use ( $organization_id, $identity_id, $wp_user_id, $snapshot ): void {
                $this->identities->clear_primary_user_links( $organization_id, $wp_user_id );
                $this->identities->insert_user_link(
                    [
                        'organization_id' => $organization_id,
                        'identity_id'     => $identity_id,
                        'wp_user_id'      => $wp_user_id,
                        'mobile_at_link'  => $snapshot,
                        'is_primary'      => 1,
                        'linked_at'       => $this->db->nowUtcSql(),
                    ]
                );
            }
        );
    }

    /**
     * هویتهای متصل به کاربر «در یک Organization» — cross-org ⇒ خالی.
     *
     * @return list<array<string, mixed>>
     */
    public function identities_for_user( int $organization_id, int $wp_user_id ): array {
        return $this->identities->identities_for_user( $organization_id, $wp_user_id );
    }

    // ================= helpers =================

    /**
     * @return array<string, mixed>
     */
    private function require_identity( int $identity_id ): array {
        $identity = $this->identities->find( $identity_id );
        if ( $identity === null ) {
            throw PatientIdentityException::not_found( __( 'هویت بیمار', 'cpms' ), $identity_id );
        }

        return $identity;
    }

    /**
     * مثل require_identity ولی مرز Organization — هویتِ Organization دیگر
     * همان NOT_FOUND است (anti-enumeration: وجودش افشا نمی‌شود).
     *
     * @return array<string, mixed>
     */
    private function require_identity_in_org( int $organization_id, int $identity_id ): array {
        $identity = $this->identities->find( $identity_id );
        if ( $identity === null || (int) $identity['organization_id'] !== $organization_id ) {
            throw PatientIdentityException::not_found( __( 'هویت بیمار', 'cpms' ), $identity_id );
        }

        return $identity;
    }

    /**
     * نرمال‌سازی موبایل — نامعتبر ⇒ خطای صریح (validation جدا از lookup).
     */
    private function normalize_or_fail( string $mobile ): string {
        $normalized = MobileValidator::normalize( $mobile );
        if ( $normalized === null ) {
            throw PatientIdentityException::invalid_mobile();
        }

        return $normalized;
    }

    /**
     * PID-{ymd}-{12hex} — یکتا در سطح global (u_identity_ref)؛ retry روی
     * برخورد (الگوی MRN در PatientService).
     */
    private function generate_internal_ref(): string {
        for ( $i = 0; $i < 5; $i++ ) {
            $ref    = 'PID-' . gmdate( 'ymd' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 12 ) );
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM ' . $this->db->table( 'cpms_patient_identities' ) .
                ' WHERE internal_ref = %s',
                [ $ref ]
            );
            if ( (int) $exists === 0 ) {
                return $ref;
            }
        }
        throw PatientIdentityException::of(
            'CLINIC_PATIENT_IDENTITY_REF_COLLISION',
            __( 'خطا در ساخت شناسهٔ هویت؛ دوباره تلاش کنید.', 'cpms' ),
            500
        );
    }
}
