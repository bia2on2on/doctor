<?php

declare(strict_types=1);

namespace ClinicCore\Application\Messaging;

use ClinicCore\Application\Authorization\AuthorizationException;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Messaging\MessageText;
use ClinicCore\Domain\Time\Jalali;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\ClinicianRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\MessageRepository;
use ClinicCore\Infrastructure\Repository\PatientRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use DomainException;

/**
 * Two-way internal Doctor/Receptionist messaging inside ONE trusted Clinic.
 *
 * Authority is re-checked on EVERY read and write: explicit trusted Clinic
 * scope, active membership, effective Clinic-scoped capabilities, participant
 * membership, Organization equality and (when present) Location eligibility.
 * No clinical note, prescription, medical file or other PHI field is ever
 * joined into a message projection. Messages are append-only.
 */
final class StaffMessagingService {

    public const RETENTION_POLICY = 'append_only';

    private const DOCTOR_CAP       = RolesAndCapabilities::CONSULT_COMPLETE;
    private const RECEPTIONIST_CAP = RolesAndCapabilities::QUEUE_CHECKIN;
    private const BASE_CAP         = RolesAndCapabilities::VISIT_READ;

    public function __construct(
        private readonly CpmsDb $db,
        private readonly MessageRepository $messages,
        private readonly MembershipRepository $memberships,
        private readonly VisitRepository $visits,
        private readonly PatientRepository $patients,
        private readonly ClinicianRepository $clinicians,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Context + eligible counterparts. Visibility only; every write re-checks.
     *
     * @return array<string, mixed>
     */
    public function context( int $actor_user_id ): array {
        $ctx = $this->context_for( $actor_user_id );

        return [
            'clinic_id'         => $ctx['clinic_id'],
            'organization_id'   => $ctx['organization_id'],
            'actor_wp_user_id'  => $actor_user_id,
            'actor_class'       => $ctx['staff_class'],
            'counterparts'      => $this->counterparts( $ctx, $actor_user_id ),
            'unread_total'      => $this->messages->unread_total( $ctx['clinic_id'], $ctx['organization_id'], $actor_user_id ),
            'retention_policy'  => self::RETENTION_POLICY,
            'max_message_chars' => MessageText::MAX_LENGTH,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function threads( int $actor_user_id, int $limit = 50 ): array {
        $ctx      = $this->context_for( $actor_user_id );
        $location = $this->allowed_locations( $ctx['clinic_id'], $actor_user_id );
        $rows     = $this->messages->threads_for_user(
            $ctx['clinic_id'],
            $ctx['organization_id'],
            $actor_user_id,
            $location,
            $limit
        );

        $threads = [];
        foreach ( $rows as $row ) {
            $counterpart_id = (int) $row['counterpart_wp_user_id'];
            // A suspended/removed counterpart must not resurrect a thread.
            if ( $this->memberships->find_active( $ctx['clinic_id'], $counterpart_id ) === null ) {
                continue;
            }
            if ( ! $this->counterpart_location_allowed( $ctx['clinic_id'], $counterpart_id, $row['location_id'] ) ) {
                continue;
            }
            $last      = $this->messages->last_message( (int) $row['id'] );
            $threads[] = [
                'id'                   => (int) $row['id'],
                'counterpart'          => $this->present_user( $counterpart_id, (string) $row['counterpart_staff_class'] ),
                'patient_id'           => $row['patient_id'] !== null ? (int) $row['patient_id'] : null,
                'visit_id'             => $row['visit_id'] !== null ? (int) $row['visit_id'] : null,
                'location_id'          => $row['location_id'] !== null ? (int) $row['location_id'] : null,
                'unread_count'         => $this->messages->thread_unread( (int) $row['id'], $actor_user_id ),
                'last_message_excerpt' => $last !== null ? MessageText::preview( (string) $last['body'] ) : '',
                'last_message_at'      => $last !== null ? (string) $last['created_at'] : null,
                'last_message_jalali'  => $last !== null ? $this->present_time( (string) $last['created_at'] ) : null,
            ];
        }

        return [
            'threads'      => $threads,
            'unread_total' => $this->messages->unread_total( $ctx['clinic_id'], $ctx['organization_id'], $actor_user_id ),
            'polling'      => ['strategy' => 'authenticated_incremental', 'interval_ms' => 8000],
        ];
    }

    /**
     * Optional patient/visit-linked conversation: BOTH ids are required and
     * the persisted Visit->patient->Clinic chain is verified server-side.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create_thread( int $actor_user_id, array $input ): array {
        $ctx          = $this->context_for( $actor_user_id );
        $recipient_id = (int) ( $input['recipient_wp_user_id'] ?? 0 );
        if ( $recipient_id <= 0 || $recipient_id === $actor_user_id ) {
            throw MessagingException::of( 'CLINIC_VALIDATION_FAILED', 'گیرندهٔ پیام نامعتبر است', 422 );
        }
        if ( ! $this->recipient_eligible( $ctx['clinic_id'], $recipient_id, $ctx['staff_class'] ) ) {
            throw MessagingException::of(
                'CLINIC_PERMISSION_DENIED',
                'گفتگوی داخلی فقط بین پزشک و پذیرش مجاز است',
                403,
                ['reason' => 'role_pairing']
            );
        }

        $patient_id  = $input['patient_id'] ?? null;
        $visit_id    = $input['visit_id'] ?? null;
        $has_patient = $patient_id !== null && $patient_id !== '' && (int) $patient_id > 0;
        $has_visit   = $visit_id !== null && $visit_id !== '' && (int) $visit_id > 0;
        if ( $has_patient !== $has_visit ) {
            throw MessagingException::of( 'CLINIC_VALIDATION_FAILED', 'شناسهٔ بیمار و ویزیت باید با هم ارسال شوند', 422 );
        }

        $patient_fk  = null;
        $visit_fk    = null;
        $location_fk = null;
        if ( $has_patient ) {
            $patient_fk = (int) $patient_id;
            $visit_fk   = (int) $visit_id;
            $visit      = $this->visits->find( $visit_fk );
            if ( $visit === null || (int) $visit['clinic_id'] !== $ctx['clinic_id'] ) {
                throw MessagingException::of( 'CLINIC_NOT_FOUND', 'ویزیت یافت نشد', 404 );
            }
            $patient = $this->patients->find( $patient_fk );
            if (
                $patient === null
                || (int) $patient['clinic_id'] !== $ctx['clinic_id']
                || (int) $visit['patient_id'] !== $patient_fk
            ) {
                throw MessagingException::of( 'CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404 );
            }
            if ( $ctx['staff_class'] === 'doctor' && ! $this->doctor_owns_visit( $actor_user_id, $ctx['clinic_id'], $visit ) ) {
                throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'این ویزیت به پزشک جاری تعلق ندارد', 403 );
            }
            $location_fk = isset( $visit['location_id'] ) ? (int) $visit['location_id'] : null;
            if ( $location_fk !== null ) {
                $this->assert_location_allowed( $ctx['clinic_id'], $actor_user_id, $location_fk );
                $this->assert_counterpart_location_allowed( $ctx['clinic_id'], $recipient_id, $location_fk );
            }
        }

        $thread_id = $this->db->transactional(
            function () use ( $ctx, $actor_user_id, $recipient_id, $patient_fk, $visit_fk, $location_fk ): int {
				$id = $this->messages->create_thread(
                    [
						'clinic_id'             => $ctx['clinic_id'],
						'organization_id'       => $ctx['organization_id'],
						'location_id'           => $location_fk,
						'patient_id'            => $patient_fk,
						'visit_id'              => $visit_fk,
						'created_by_wp_user_id' => $actor_user_id,
                    ]
				);
				$this->messages->add_participant( $id, $actor_user_id, $ctx['staff_class'] );
				$this->messages->add_participant( $id, $recipient_id, $this->recipient_class( $ctx['clinic_id'], $recipient_id ) );
				// No message body, patient name or clinical text in audit payloads.
				$this->audit->log(
                    'MESSAGE_THREAD_CREATE',
                    $this->actor( $actor_user_id ),
                    'message_thread',
                    $id,
                    $patient_fk,
                    null,
                    [
						'thread_id'    => $id,
						'recipient_id' => $recipient_id,
						'visit_id'     => $visit_fk,
						'location_id'  => $location_fk,
                    ],
                    ['clinic_id' => $ctx['clinic_id'], 'organization_id' => $ctx['organization_id']]
				);

				return $id;
			}
        );

        return $this->thread_view( $ctx, $actor_user_id, $thread_id );
    }

    /**
     * Incremental authenticated polling: only messages newer than since_id.
     *
     * @return array<string, mixed>
     */
    public function messages( int $actor_user_id, int $thread_id, int $since_id, int $limit ): array {
        $ctx = $this->context_for( $actor_user_id );
        $this->require_thread( $ctx, $actor_user_id, $thread_id );
        $rows     = $this->messages->messages_since( $thread_id, $since_id, $limit );
        $messages = [];
        $last_id  = $since_id;
        foreach ( $rows as $row ) {
            $id         = (int) $row['id'];
            $last_id    = max( $last_id, $id );
            $messages[] = [
                'id'                => $id,
                'thread_id'         => (int) $row['thread_id'],
                'sender_wp_user_id' => (int) $row['sender_wp_user_id'],
                'body'              => (string) $row['body'],
                'created_at'        => (string) $row['created_at'],
                'jalali_time'       => $this->present_time( (string) $row['created_at'] ),
                'mine'              => (int) $row['sender_wp_user_id'] === $actor_user_id,
            ];
        }

        return [
            'messages'     => $messages,
            'last_id'      => $last_id,
            'unread_count' => $this->messages->thread_unread( $thread_id, $actor_user_id ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send( int $actor_user_id, int $thread_id, mixed $raw_body ): array {
        $ctx    = $this->context_for( $actor_user_id );
        $thread = $this->require_thread( $ctx, $actor_user_id, $thread_id );
        try {
            $body = MessageText::sanitize( $raw_body );
        } catch ( DomainException $exception ) {
            $too_long = $exception->getMessage() === MessageText::TOO_LONG;
            throw MessagingException::of(
                'CLINIC_VALIDATION_FAILED',
                $too_long ? 'پیام بیش از حد طولانی است' : 'متن پیام الزامی است',
                422,
                ['max_chars' => MessageText::MAX_LENGTH]
            );
        }

        $message_id = $this->db->transactional(
            function () use ( $ctx, $actor_user_id, $thread_id, $thread, $body ): int {
				$id = $this->messages->insert_message( $ctx['clinic_id'], $thread_id, $actor_user_id, $body );
				$this->audit->log(
                    'MESSAGE_SEND',
                    $this->actor( $actor_user_id ),
                    'message',
                    $id,
                    $thread['patient_id'] !== null ? (int) $thread['patient_id'] : null,
                    null,
                    [
						'thread_id'   => $thread_id,
						'body_length' => mb_strlen( $body, 'UTF-8' ),
						'visit_id'    => $thread['visit_id'] !== null ? (int) $thread['visit_id'] : null,
                    ]
				);

				return $id;
			}
        );

        $row = $this->messages->find_message( $message_id );

        return [
            'id'                => $message_id,
            'thread_id'         => $thread_id,
            'sender_wp_user_id' => $actor_user_id,
            'body'              => $body,
            'created_at'        => (string) ( $row['created_at'] ?? $this->db->nowUtcSql() ),
            'jalali_time'       => $this->present_time( (string) ( $row['created_at'] ?? $this->db->nowUtcSql() ) ),
            'mine'              => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mark_read( int $actor_user_id, int $thread_id ): array {
        $ctx = $this->context_for( $actor_user_id );
        $this->require_thread( $ctx, $actor_user_id, $thread_id );
        $last = $this->messages->last_message( $thread_id );
        if ( $last !== null ) {
            $this->messages->mark_read( $thread_id, $actor_user_id, (int) $last['id'] );
        }

        return [
            'thread_id'            => $thread_id,
            'last_read_message_id' => $last !== null ? (int) $last['id'] : 0,
            'unread_count'         => $this->messages->thread_unread( $thread_id, $actor_user_id ),
            'unread_total'         => $this->messages->unread_total( $ctx['clinic_id'], $ctx['organization_id'], $actor_user_id ),
        ];
    }

    // ================= Helpers =================

    /**
     * @return array{clinic_id: int, organization_id: int, staff_class: string}
     */
    private function context_for( int $actor_user_id ): array {
        $scope = ScopeContext::tryGet();
        if ( $scope === null || $scope->clinicId <= 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope API.
            throw MessagingException::of( 'CLINIC_SCOPE_REQUIRED', 'زمینهٔ معتبر مطب لازم است', 400 );
        }
        if ( $actor_user_id <= 0 ) {
            throw MessagingException::of( 'CLINIC_UNAUTHORIZED', 'وارد نشده‌اید', 401 );
        }
        $clinic_id = (int) $scope->clinicId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope API.
        if ( $this->memberships->find_active( $clinic_id, $actor_user_id ) === null ) {
            throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'عضویت فعالی در این مطب ندارید', 403 );
        }

        $auth = App::authorization_service();
        try {
            $auth->authorize( $actor_user_id, $clinic_id, self::BASE_CAP );
        } catch ( AuthorizationException $exception ) {
            throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'دسترسی لازم را ندارید', 403, ['reason' => $exception->getErrorCode()] );
        }
        $doctor_class       = $auth->can( $actor_user_id, $clinic_id, self::DOCTOR_CAP );
        $receptionist_class = $auth->can( $actor_user_id, $clinic_id, self::RECEPTIONIST_CAP );
        if ( ! $doctor_class && ! $receptionist_class ) {
            throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'پیام‌رسانی داخلی برای این نقش مجاز نیست', 403 );
        }

        $organization_id = $scope->organizationId !== null ? (int) $scope->organizationId : (int) $this->db->fetchValue( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- established ClinicScope API.
            'SELECT organization_id FROM ' . $this->db->table( 'cpms_clinics' ) . ' WHERE id = %d',
            [$clinic_id]
        );
        if ( $organization_id <= 0 ) {
            throw MessagingException::of( 'CLINIC_SCOPE_REQUIRED', 'سازمان مطب قابل شناسایی نیست', 400 );
        }

        return [
            'clinic_id'       => $clinic_id,
            'organization_id' => $organization_id,
            'staff_class'     => $doctor_class ? 'doctor' : 'receptionist',
        ];
    }

    /**
     * @param array{clinic_id: int, organization_id: int, staff_class: string} $ctx
     * @return list<array<string, mixed>>
     */
    private function counterparts( array $ctx, int $actor_user_id ): array {
        $out      = [];
        $own_locs = $this->allowed_locations( $ctx['clinic_id'], $actor_user_id );
        foreach ( $this->messages->active_member_ids( $ctx['clinic_id'], $actor_user_id ) as $user_id ) {
            $class = $this->recipient_class( $ctx['clinic_id'], $user_id );
            if ( $class === '' || $class === $ctx['staff_class'] ) {
                // Iran V1 product contract: a thread is doctor <-> receptionist.
                continue;
            }
            if ( $own_locs !== null ) {
                $their_locs = $this->allowed_locations( $ctx['clinic_id'], $user_id );
                if ( $their_locs !== null && array_intersect( $own_locs, $their_locs ) === [] ) {
                    continue;
                }
            }
            $out[] = $this->present_user( $user_id, $class );
        }

        return $out;
    }

    private function recipient_eligible( int $clinic_id, int $recipient_id, string $actor_class ): bool {
        if ( $this->memberships->find_active( $clinic_id, $recipient_id ) === null ) {
            return false;
        }

        $recipient_class = $this->recipient_class( $clinic_id, $recipient_id );

        return $recipient_class !== '' && $recipient_class !== $actor_class;
    }

    private function recipient_class( int $clinic_id, int $user_id ): string {
        $auth = App::authorization_service();
        if ( ! $auth->can( $user_id, $clinic_id, self::BASE_CAP ) ) {
            return '';
        }
        if ( $auth->can( $user_id, $clinic_id, self::DOCTOR_CAP ) ) {
            return 'doctor';
        }
        if ( $auth->can( $user_id, $clinic_id, self::RECEPTIONIST_CAP ) ) {
            return 'receptionist';
        }

        return '';
    }

    /**
     * null = membership allows every Location of this Clinic.
     *
     * @return list<int>|null
     */
    private function allowed_locations( int $clinic_id, int $user_id ): ?array {
        $membership = $this->memberships->find_active( $clinic_id, $user_id );
        if ( $membership === null || (string) ( $membership['scope_mode'] ?? 'clinic' ) !== 'location' ) {
            return null;
        }

        return array_values( array_map( 'intval', $this->memberships->location_ids_for( (int) $membership['id'] ) ) );
    }

    private function assert_location_allowed( int $clinic_id, int $user_id, int $location_id ): void {
        $allowed = $this->allowed_locations( $clinic_id, $user_id );
        if ( $allowed !== null && ! in_array( $location_id, $allowed, true ) ) {
            throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'دسترسی به این موقعیت عملیاتی ندارید', 403 );
        }
    }

    private function assert_counterpart_location_allowed( int $clinic_id, int $user_id, int $location_id ): void {
        $allowed = $this->allowed_locations( $clinic_id, $user_id );
        if ( $allowed !== null && ! in_array( $location_id, $allowed, true ) ) {
            throw MessagingException::of( 'CLINIC_VALIDATION_FAILED', 'گیرنده به این موقعیت عملیاتی دسترسی ندارد', 422 );
        }
    }

    private function counterpart_location_allowed( int $clinic_id, int $user_id, mixed $thread_location ): bool {
        if ( $thread_location === null ) {
            return true;
        }
        $allowed = $this->allowed_locations( $clinic_id, $user_id );

        return $allowed === null || in_array( (int) $thread_location, $allowed, true );
    }

    /**
     * @param array<string, mixed> $visit
     */
    private function doctor_owns_visit( int $actor_user_id, int $clinic_id, array $visit ): bool {
        $clinician = $this->clinicians->find_by_wp_user( $actor_user_id );
        if ( $clinician === null || (int) $clinician['id'] !== (int) $visit['clinician_id'] ) {
            return false;
        }

        return $this->memberships->clinician_participates_in( (int) $clinician['id'], $clinic_id );
    }

    /**
     * @param array{clinic_id: int, organization_id: int, staff_class: string} $ctx
     * @return array<string, mixed>
     */
    private function require_thread( array $ctx, int $actor_user_id, int $thread_id ): array {
        $thread = $this->messages->find_thread( $thread_id );
        if (
            $thread === null
            || (int) $thread['clinic_id'] !== $ctx['clinic_id']
            || (int) $thread['organization_id'] !== $ctx['organization_id']
            || $this->messages->participant( $thread_id, $actor_user_id ) === null
        ) {
            throw MessagingException::of( 'CLINIC_NOT_FOUND', 'گفتگو یافت نشد', 404 );
        }
        $participants = $this->thread_participants( $thread_id, $actor_user_id );
        if ( $participants === [] ) {
            throw MessagingException::of( 'CLINIC_NOT_FOUND', 'گفتگو یافت نشد', 404 );
        }
        $counterpart_id = $participants[0];
        if ( $this->memberships->find_active( $ctx['clinic_id'], $counterpart_id ) === null ) {
            throw MessagingException::of( 'CLINIC_PERMISSION_DENIED', 'گیرندهٔ گفتگو غیرفعال است', 403 );
        }
        if ( $thread['location_id'] !== null ) {
            $location_id = (int) $thread['location_id'];
            $this->assert_location_allowed( $ctx['clinic_id'], $actor_user_id, $location_id );
            $this->assert_location_allowed( $ctx['clinic_id'], $counterpart_id, $location_id );
        }

        return $thread;
    }

    /** @return list<int> */
    private function thread_participants( int $thread_id, int $actor_user_id ): array {
        $rows = $this->db->fetchAll(
            'SELECT wp_user_id FROM ' . $this->db->table( 'cpms_message_participants' )
            . ' WHERE thread_id = %d AND wp_user_id != %d',
            [$thread_id, $actor_user_id]
        );

        return array_values( array_map( 'intval', array_column( is_array( $rows ) ? $rows : [], 'wp_user_id' ) ) );
    }

    /**
     * @param array{clinic_id: int, organization_id: int, staff_class: string} $ctx
     * @return array<string, mixed>
     */
    private function thread_view( array $ctx, int $actor_user_id, int $thread_id ): array {
        $thread      = $this->require_thread( $ctx, $actor_user_id, $thread_id );
        $counterpart = $this->thread_participants( $thread_id, $actor_user_id );
        $user_id     = $counterpart[0] ?? 0;

        return [
            'id'           => $thread_id,
            'counterpart'  => $this->present_user( $user_id, $this->recipient_class( $ctx['clinic_id'], $user_id ) ),
            'patient_id'   => $thread['patient_id'] !== null ? (int) $thread['patient_id'] : null,
            'visit_id'     => $thread['visit_id'] !== null ? (int) $thread['visit_id'] : null,
            'location_id'  => $thread['location_id'] !== null ? (int) $thread['location_id'] : null,
            'unread_count' => $this->messages->thread_unread( $thread_id, $actor_user_id ),
        ];
    }

    /**
     * @return array{wp_user_id: int, display_name: string, staff_class: string, label: string}
     */
    private function present_user( int $user_id, string $staff_class ): array {
        $name = 'کاربر شماره ' . $user_id;
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( $user !== false && $user !== null ) {
                $name = (string) ( $user->display_name !== '' ? $user->display_name : $user->user_login );
            }
        }

        return [
            'wp_user_id'   => $user_id,
            'display_name' => $name,
            'staff_class'  => $staff_class,
            'label'        => $staff_class === 'doctor' ? 'پزشک' : 'پذیرش',
        ];
    }

    private function present_time( string $utc_sql ): string {
        $date = substr( $utc_sql, 0, 10 );
        $time = substr( $utc_sql, 11, 5 );

        return $date !== '' ? Jalali::formatYmd( $date ) . ' — ' . $time . ' UTC' : '';
    }

    /**
     * @return array{wp_user_id: int, role: string}
     */
    private function actor( int $user_id ): array {
        $role = 'unknown';
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( $user !== false && $user !== null ) {
                $role = (string) ( $user->roles[0] ?? 'unknown' );
            }
        }

        return ['wp_user_id' => $user_id, 'role' => $role];
    }
}
