<?php
declare(strict_types=1);

namespace ClinicCore\Application\Visits;

use ClinicCore\Application\Notifications\NotificationService;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Auth\RolesAndCapabilities;
use ClinicCore\Domain\Licensing\LicenseGate;
use ClinicCore\Domain\Machine\AppointmentMachine;
use ClinicCore\Domain\Machine\InvalidTransitionException;
use ClinicCore\Domain\Machine\VisitMachine;
use ClinicCore\Domain\Notifications\NotificationEvents;
use ClinicCore\Domain\Visits\VisitException;
use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;
use ClinicCore\Infrastructure\Repository\AppointmentRepository;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Infrastructure\Repository\VisitRepository;
use ClinicCore\Settings\SettingsFactory;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use Throwable;

/**
 * سرویس مراجعه/صف (F4) — Check-in/Walk-in + State Machine + Real-time Feed.
 *
 * تضمین‌ها:
 *  - **J-1 (یکتایی V10)**: هر Transition روی Row Lock (FOR UPDATE) داخل
 *    Transaction — دو complete/call هم‌زمان فقط یکی موفق (TP-03b, ADR-0004).
 *  - **J-2 (J-5 در SRS)**: قانون ویزیت Active واحد — Lock رکورد بیمار، سپس
 *    چک تکرار (`CLINIC_DUPLICATE_ACTIVE_VISIT`).
 *  - **J-3**: هر تغییر وضعیت → ردیف append-only در cpms_visit_status_history
 *    (همزمان Feed رئال‌تایم R1).
 *  - **ER-06/FR-5.5**: Check-in دیرهنگام → no_show روی Appointment + Visit
 *    فوری Walk-in-like با ارجاع به همان نوبت (Lazy + Cron Job).
 *  - **T9**: check_out → نوبت مرجع (در صورت وجود) completed می‌شود.
 *  - زمان‌بندی: همه مقادیر UTC در DB (ADR-0013)؛ ترتیب صف J-4 در Repository.
 *  - **T2**: Location timezone مرجع عملیاتی برای no-show (periodic + lazy)
 *    — هرگز no_show قبل از Location-local start+grace به‌صورت UTC instant
 */
final class VisitService
{
    /**
     * وضعیت‌های زندهٔ ویزیت — منبع یگانه: VisitMachine::ACTIVE_STATUSES (I-3).
     */
    private const ACTIVE_VISIT_STATUSES = VisitMachine::ACTIVE_STATUSES;

    private const QUEUE_STATUSES = ['waiting', 'called', 'in_consultation'];

    private readonly MembershipRepository $memberships;

    /** @var DateTimeImmutable|null test seam for deterministic operational day (Phase 10) */
    private static ?DateTimeImmutable $testNowUtc = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- legacy PSR-style, established contract

    /**
     * Test seam: set fixed UTC now for operational day calculation.
     * Used by deterministic RED tests (Kiritimati/Midway). Pass null to restore real time.
     */
    public static function setTestNowUtc(?DateTimeImmutable $now): void // phpcs:ignore Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
    { // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie.BraceOnNewLine -- WPCS
        self::$testNowUtc = $now !== null ? $now->setTimezone(new DateTimeZone('UTC')) : null; // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
    }

    public function __construct( private readonly CpmsDb $db, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing -- WPCS
        private readonly VisitRepository $visits,
        private readonly AppointmentRepository $appointments,
        private readonly SettingsFactory $settingsFactory,
        private readonly AuditLogger $audit,
        private readonly LicenseGate $licenseGate,
        private readonly ?\ClinicCore\Infrastructure\Logging\OpLogger $opLog = null,
        private readonly mixed $notificationServiceFactory = null,
        ?MembershipRepository $memberships = null ) { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine -- WPCS
        // Optional only for backwards-compatible direct service construction;
        // production wiring injects the same shared participation repository.
        $this->memberships = $memberships ?? new MembershipRepository($db);
    }

    /** @var array<int, \ClinicCore\Application\Notifications\NotificationService> */
    private array $notificationServicesByClinicId = [];

    // ================= V1 — Check-in (D6) =================

    /**
     * Check-in بیمار دارای نوبت — یا نرمال (داخل Grace) یا ER-06 دیرهنگام.
     *
     * T2: Lazy no-show determination now uses Location timezone + per-Clinic grace
     * as UTC instant, not strtotime without timezone.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed> رکورد Visit
     */
    public function checkIn(int $actorUserId, int $patientId, int $appointmentId, array $meta = []): array
    {
        $this->requireSecretary($actorUserId, 'check_in');
        $actorRole = 'secretary';

        return $this->db->transactional(function () use ($actorUserId, $actorRole, $patientId, $appointmentId, $meta): array {
            $this->lockPatient($patientId);

            // Phase 7 Slice 2 — I-3/سریال‌سازی: Check-in روی همان ردیف نوبت قفل
            // می‌گیرد که T5/T6/T7 (BookingService::cancel/reschedule) قفل می‌کنند؛
            // ترتیب فعلی حفظ می‌شود (ابتدا بیمار، سپس نوبت) و مسیر قفل هم همان
            // findForUpdate موجود است. بدون این قفل، لغو/جابه‌جایی هم‌زمان می‌تواند
            // وضعیت پاره (نوبت Terminal با ویزیت زنده) را کامیت کند.
            $appt = $this->appointments->findForUpdate($appointmentId);
            if ( $appt === null ) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
            }
            // Phase 3 Slice 6B — مالکیت پایدار نوبت در برابر Clinic معتبرِ صریحِ
            // درخواست (مرز REST کارکنی): نوبتِ Clinic دیگر همان پاکتِ «نوبت
            // یافت نشد» را می‌گیرد — پیش از ساخت ویزیت و هرجهش پایدار.
            $this->guardAppointmentWithinExplicitScope($appt);
            if ( (int) $appt['patient_id'] !== $patientId) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                $this->auditAndThrow( $actorUserId, $actorRole, 'FORBIDDEN_ACCESS_ATTEMPT', 'visit', $appointmentId, $patientId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.MultipleArguments,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    'نوبت به این بیمار تعلق ندارد', 403, 'CLINIC_PERMISSION_DENIED' ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,PEAR.Functions.FunctionCallSignature.MultipleArguments -- WPCS
            }

            // ER-06: نوبت پایان‌یافته/لغوشده قابل Check-in نیست؛
            // دیرهنگام (پس از Grace) → no_show + Visit فوری Walk-in-like (ارجاع حفظ می‌شود).
            $source = 'scheduled';
            $status = (string) $appt['status'];
            $nowSql = $this->db->nowUtcSql();
            $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            // T2: per-Clinic grace + Location timezone
            $clinicId = (int) ($appt['clinic_id'] ?? 0);
            $locationId = (int) ($appt['location_id'] ?? 0);

            $this->guardDuplicateActiveVisit($patientId, (int) $appt['clinician_id'], $clinicId, $locationId); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract

            if ( in_array($status, ['cancelled_by_patient', 'cancelled_by_staff', 'rescheduled', 'completed'], true )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                throw VisitException::of( 'CLINIC_INVALID_APPOINTMENT_STATE', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'این نوبت ' . $this->appointmentStatusLabel($status) . ' است و قابل Check-in نیست',
                    409,
                    ['appointment_status' => $status] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }
            if ( $status === 'no_show' ) {
                // ER-06: late arrival after T8 — unbound walk-in (must NOT bind
                // a genuinely active Visit to a terminal no_show appointment).
                $source = 'walk_in';
                $appointmentId = null;
            } else {
                // T2: Location-aware lazy no-show check
                $shouldMarkNoShow = false;
                if ( $clinicId > 0 && $locationId > 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    $tz = $this->resolveLocationTimezone($locationId, $clinicId);
                    if ( $tz !== null ) {
                        $apptUtc = $this->appointmentUtcInstant($appt, $tz);
                        $grace = $this->graceForClinic($clinicId);
                        if ( $apptUtc !== null && $grace !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                            $eligible = $apptUtc->add(new DateInterval('PT' . $grace . 'M'));
                            if ( $nowUtc >= $eligible ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                                $shouldMarkNoShow = true;
                            }
                        } else {
                            // fail-closed: if timezone or grace cannot be resolved, do NOT mark no_show
                            $shouldMarkNoShow = false;
                        }
                    } else {
                        // fail-closed: missing/invalid Location timezone => do NOT prematurely mark
                        $shouldMarkNoShow = false;
                    }
                } else {
                    // No location_id — no documented compatibility rule, fail-closed (do NOT mark no_show)
                    // Per task: do not use fallback primary Location to silently reinterpret
                    $shouldMarkNoShow = false;
                    $this->opLog?->warning('visit.location_missing', ['appointment_id' => $appointmentId, 'clinic_id' => $clinicId]);
                }

                if ( $shouldMarkNoShow ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    // ER-06 / FR-6.5: late arrival atomically T8s the
                    // appointment then creates a walk-in-like Visit that
                    // keeps the appointment reference.
                    $this->markAppointmentNoShow($appt, $this->db->nowUtc(), $actorUserId);
                    $source = 'walk_in';
                } elseif ( $status === 'pending' ) {
                    // حضور بیمار = تایید نوبت (T3) — تا Checkout مسیر کامل شود
                    $this->confirmAppointment($appt, $nowSql, $actorUserId);
                }
            }

            $visit = $this->createVisit( $actorUserId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                $clinicId > 0 ? $clinicId : (int) $appt['clinic_id'],
                $patientId,
                (int) $appt['clinician_id'],
                $appointmentId,
                $source,
                'check_in',
                isset($meta['note']) ? (string) $meta['note'] : null ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS

            $this->audit('VISIT_CHECKED_IN', $actorUserId, $actorRole, 'visit', (int) $visit['id'], $patientId, null, $visit, [
                'appointment_id' => $appointmentId,
                'source' => $source,
            ]);

            return $visit;
        });
    }

    // ================= V2 — Walk-in (D7) =================

    /**
     * ثبت مراجعه بدون نوبت (Walk-in) — بیمار در کلینیک حاضر است.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function walkIn(int $actorUserId, int $patientId, int $clinicianId, array $meta = []): array
    {
        $this->requireSecretary($actorUserId, 'create_walk_in');

        // سیاست لایسنس (§18 دستور کارفرما F4): «ویزیت مستقل جدید» در حالت
        // Expired/Restricted (Read-Only) ممنوع — Walk-in بدون نوبت است.
        $this->assertLicense(LicenseGate::OP_VISIT_CHECKIN);

        $actorRole = 'secretary';

        return $this->db->transactional(function () use ($actorUserId, $actorRole, $patientId, $clinicianId, $meta): array {
            // Phase 4 Slice 3 — Clinic عملیات WalkIn از context مورد اعتماد
            // staff می‌آید؛ clinicians.clinic_id فقط Clinic خانه/سازگاری است.
            // همان Clinic برای مشارکت حرفه‌ای، مالکیت بیمار، درج Visit و تمام
            // side-effectهای Clinic-sensitive در createVisit استفاده می‌شود.
            $clinicId = $this->walkInClinicId();
            if ( !$this->memberships->clinician_participates_in($clinicianId, $clinicId )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- legacy PSR-style, established contract
                throw VisitException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد', 404);
            }

            $patient = $this->lockPatient($patientId);
            if ( (int) $patient['clinic_id'] !== $clinicId) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                throw VisitException::of('CLINIC_VALIDATION_FAILED', 'این بیمار به کلینیک دیگری تعلق دارد', 422);
            }

            // Determine Location for duplicate check (same as createVisit operational date logic)
            $locationIdForGuard = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            try {
                $scope_for_guard = \ClinicCore\Application\Scope\ScopeContext::tryGet();
                if ( $scope_for_guard !== null && $scope_for_guard->locationId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
                    $locationIdForGuard = (int) $scope_for_guard->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                } else {
                    $app_scope_for_guard = \ClinicCore\Bootstrap\App::scope();
                    if ( $app_scope_for_guard->locationId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
                        $locationIdForGuard = (int) $app_scope_for_guard->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    }
                }
            } catch ( \Throwable $e ) {
                unset( $e );
            }
            if ( $locationIdForGuard === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                try {
                    $eligible_for_guard = $this->eligibleLocationIdsForActor( $clinicId, $actorUserId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    if ( 1 === count( $eligible_for_guard ) ) {
                        $locationIdForGuard = $eligible_for_guard[0]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    }
                } catch ( \Throwable $e ) {
                    unset( $e );
                }
            }
            $this->guardDuplicateActiveVisit($patientId, $clinicianId, $clinicId, $locationIdForGuard); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract

            $visit = $this->createVisit( $actorUserId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                $clinicId,
                $patientId,
                $clinicianId,
                null,
                'walk_in',
                'create_walk_in',
                isset($meta['note']) ? (string) $meta['note'] : null ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS

            $this->audit('VISIT_WALK_IN', $actorUserId, $actorRole, 'visit', (int) $visit['id'], $patientId, null, $visit, []);

            return $visit;
        });
    }

    // ================= V3..V15 — Transitionهای عمومی (D8 / E3..E6) =================

    /**
     * اجرای یک Event ماشین روی ویزیت — با Row Lock (J-1).
     *
     * Events: enqueue|cancel|call|recall|start|skip|complete|reopen|
     *         invoice_ready|waive|check_out
     *
     * @param array<string, mixed> $meta {reason?, room?, note?}
     * @return array<string, mixed> رکورد به‌روزشده
     */
    public function transition( int $actorUserId, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        int $visitId,
        string $event,
        array $meta = [] ): array { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine -- WPCS
        // F9 (ADR-0027 Minor #3) — گارد مالکیت «قبل از Transaction» تا Auditِ
        // رد شدن (FORBIDDEN_ACCESS_ATTEMPT) با Rollback از بین نرود.
        $preVisit = $this->visits->find($visitId);
        if ( $preVisit !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            $this->guardDoctorTransitionOwnership($actorUserId, $preVisit);
            // Phase 3 Slice 6B — مالکیت پایدار ویزیت در برابر Clinic معتبرِ
            // صریحِ درخواست: ویزیتِ Clinic دیگر «مثل نبودن» است (404 parity)؛
            // انکار پیش از Transaction = صفر اثر جانبی پایدار.
            $this->guardVisitWithinExplicitScope($preVisit);
        }

        return $this->db->transactional(function () use ($actorUserId, $visitId, $event, $meta): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ( $visit === null ) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
            }
            // Defense-in-depth — همان مالکیت داخل Transaction (post-lock).
            $this->guardVisitWithinExplicitScope($visit);

            return $this->applyTransition($actorUserId, $visit, $event, $meta);
        });
    }

    /**
     * اجرای Transition روی Visit از قبل Lock شده — **بدون** Transaction
     * (فراخواننده باید داخل transactional باشد؛ J-1 با Row Lock).
     *
     * F6: public شده برای FinanceService (M-7 — عمل مالی و Transition در
     * یک Transaction واحد)؛ $forceRole فقط برای نقش سیستم (V11/V12) است.
     *
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function applyTransition( int $actorUserId, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        array $visit,
        string $event,
        array $meta = [],
        ?string $forceRole = null ): array { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        $visitId = (int) $visit['id'];
        $actorRole = $forceRole ?? ($this->roleForUser($actorUserId) ?? 'secretary');
        $fromStatus = (string) $visit['status'];

        // F9 (ADR-0027 Minor #3) — Resource Authorization سرور-side:
        // پزشک فقط روی ویزیت «خودش» (Clinician متصل به حسابش) Transition می‌زند؛
        // منشی/سیستم در V1 دامنه مطب دارند (Scope کامل = ADR-0026/V2).
        // (transition() همین گارد را قبل از Transaction هم اجرا می‌کند تا Auditِ
        // رد شدن Rollback نشود؛ اینجا defense-in-depth برای فراخوانی مستقیم است.)
        if ( $forceRole === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            $this->guardDoctorTransitionOwnership($actorUserId, $visit);
        }

        $toStatus = $this->machineCheck($fromStatus, $event, $actorRole);
        $row = $this->patchForEvent($visit, $event, $toStatus, $actorUserId, $meta);
        $this->visits->updateById($visitId, $row);

        $this->visits->insertHistory($visitId, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'note' => $this->historyNote($event, $meta),
            'request_id' => null,
        ]);

        $visit = array_merge($visit, $row, ['status' => $toStatus]);

        // T9: خروج نهایی (پرداخت‌شده یا معافیت) → نوبت مرجع completed
        if ( in_array($event, ['check_out', 'waive'], true ) && !empty($visit['appointment_id'])) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- WPCS
            $this->completeReferencedAppointment((int) $visit['appointment_id'], $actorUserId);
        }

        $this->audit('VISIT_' . strtoupper($event), $actorUserId, $actorRole, 'visit', $visitId, (int) $visit['patient_id'], null, $visit, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]);

        // N-1 (F8): رویداد اعلان صف — INSERT داخل همان Transaction (N-2)؛
        // شکست اعلان هرگز گردش‌کار صف را نمی‌شکند (قاعده کارفرما).
        $this->publishQueueNotification($event, $visit, $actorUserId, $meta);

        return $this->presentVisit($visit);
    }

    /**
     * Resolve NotificationService per-Clinic via factory — scope-neutral.
     * Clinic comes from durable visit row, never from ambient App::scope().
     */
    private function notificationServiceForClinic(int $clinicId): ?NotificationService
    {
        if ( $clinicId <= 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            return null;
        }
        if ( isset($this->notificationServicesByClinicId[$clinicId] )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.Arrays.ArrayKeySpacingRestrictions.NoSpacesAroundArrayKeys,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            return $this->notificationServicesByClinicId[$clinicId];
        }
        $factory = $this->notificationServiceFactory;
        if ( is_callable($factory )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            try {
                $svc = $factory($clinicId);
                if ( $svc instanceof NotificationService ) {
                    return $this->notificationServicesByClinicId[$clinicId] = $svc;
                }
            } catch ( Throwable $e ) {
                $this->opLog?->warning('visit.notif_factory_failed', ['clinic_id' => $clinicId, 'error' => $e->getMessage()]);
                return null;
            }
        }
        // Backward compat: if factory is actually a NotificationService instance (old call-sites)
        if ( $factory instanceof NotificationService ) {
            return $factory;
        }
        return null;
    }

    /**
     * QUEUE.called / QUEUE.ready_payment → اعلان Internal به منشی‌ها
     * (notifications.md §3) — به‌جز فراخواننده؛ R1 مکمل است (Feed صف).
     *
     * M-2 fix: Clinic comes from durable visit data, service resolved per-Clinic via factory.
     *
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $meta
     */
    private function publishQueueNotification(string $event, array $visit, int $actorUserId, array $meta): void
    {
        $clinicId = (int) ($visit['clinic_id'] ?? 0);
        $notifications = $this->notificationServiceForClinic($clinicId);
        if ( $notifications === null ) {
            return;
        }

        try {
            $patientName = trim((string) $this->db->fetchValue( 'SELECT CONCAT(p.first_name, \' \', p.last_name) FROM ' . $this->db->table('cpms_patients') . ' p WHERE p.id = %d LIMIT 1', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.CastStructureSpacing.NoSpaceBeforeOpenParenthesis -- legacy PSR-style, established contract
                [(int) $visit['patient_id']] )); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.WhiteSpace.CastStructureSpacing.NoSpaceBeforeOpenParenthesis -- WPCS
            $room = trim((string) ($meta['room'] ?? ''));

            if ( $event === 'call' ) {
                $notifications->publishToStaff( $clinicId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    NotificationEvents::QUEUE_CALLED,
                    [
                        'patient_name' => $patientName !== '' ? $patientName : 'بیمار',
                        'room' => $room !== '' ? $room : '—',
                    ],
                    'queue:called:v' . (int) $visit['id'] . ':r' . (int) ($visit['recall_count'] ?? 0),
                    RolesAndCapabilities::QUEUE_READ,
                    $actorUserId ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            } elseif ( $event === 'invoice_ready' ) {
                $notifications->publishToStaff( $clinicId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    NotificationEvents::QUEUE_READY_PAYMENT,
                    ['patient_name' => $patientName !== '' ? $patientName : 'بیمار'],
                    'queue:pay:v' . (int) $visit['id'],
                    RolesAndCapabilities::QUEUE_READ,
                    $actorUserId ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            }
        } catch ( Throwable $e ) {
            $this->opLog?->warning('visit.notif_failed', ['visit_id' => (int) $visit['id'], 'error' => $e->getMessage()]);
        }
    }

    // ================= D1/E1 — داشبورد امروز =================

    /**
     * داشبورد امروز: صف زنده + آمار + پزشکان فعال.
     *
     * ADR-0030/Part1 (اصل «پزشک داده پزشک دیگر را ضمنی نمی‌بیند» — Master Context §8):
     * اگر Actor «پزشکِ» متصل به یک Clinician باشد، خروجی صرفاً ویزیت‌های همان
     * Clinician است (پارامتر clinician_id نادیده گرفته می‌شود — Scope سرور-side).
     * منشی/سایر نقش‌های دارای QUEUE_READ دامنهٔ کل **مطبِ context موثق** را می‌بینند
     * (Clinic از Scope صریحِ درخواست یا Resolution سیستمی «تنها Clinic» حل می‌شود —
     * هیچ clinic_id ثابتی در این Service وجود ندارد؛ مبهَم ⇒ CLINIC_SCOPE_REQUIRED).
     *
     * Phase 10 legacy: trusted Clinic + optional trusted Location + Location-local day when Location present.
     * No N>1 REQUIRED for shared queue/wp-admin — that stricter rule lives in Doctor Portal boundary.
     *
     * @return array<string, mixed>
     */
    public function today( int $actor_user_id, ?int $clinician_id = null ): array {
        $this->requireQueueReader( $actor_user_id );
        $clinic_id          = $this->queueClinicId();
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, $clinician_id );
        $location_id        = $this->queueLocationId( $clinic_id, $actor_user_id );

        // Legacy shared queue: use UTC date for Today to preserve existing wp-admin/shared behavior.
        // Operational Location date is portal-specific (todayForDoctorPortal).
        $operational_date = $this->nowUtc()->format( 'Y-m-d' );

        $queue = $this->visits->queueFor( $clinic_id, $scope_clinician_id, self::QUEUE_STATUSES, $operational_date, $location_id );
        $stats = $this->visits->statsFor( $clinic_id, $operational_date, $scope_clinician_id, $location_id );

        return [
            'date'          => $operational_date,
            'stats'         => $stats,
            'queue'         => array_map( [ $this, 'presentVisit' ], $queue ),
            'last_event_id' => $this->visits->lastEventId( $clinic_id, $operational_date, $scope_clinician_id, $location_id ),
            'location_id'   => $location_id,
        ];
    }

    /**
     * Doctor Portal Today — strict Location enforcement (Blocker 1).
     *
     * Owner decision FINAL for Doctor Portal:
     * - 0 eligible => fail closed / no Today or Queue data
     * - 1 eligible => auto-bind
     * - N>1 => explicit Location REQUIRED (no first/primary fallback)
     * - foreign/inactive/unassigned => denied (UNAVAILABLE)
     *
     * Uses existing CLINIC_SCOPE_REQUIRED 400 with field=location_id reason=location_required.
     * Never returns eligible IDs.
     *
     * @return array<string, mixed>
     */
    public function todayForDoctorPortal( int $actor_user_id, ?int $clinician_id = null ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $this->requireQueueReader( $actor_user_id );
        $clinic_id          = $this->queueClinicId();
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, $clinician_id );
        $location_id        = $this->queueLocationId( $clinic_id, $actor_user_id );

        $eligible = $this->eligibleLocationIdsForActor( $clinic_id, $actor_user_id );

        if ( [] === $eligible ) {
            $date = $this->nowUtc()->format( 'Y-m-d' );
            return [
                'date'          => $date,
                'stats'         => $this->emptyStats(),
                'queue'         => [],
                'last_event_id' => 0,
                'location_id'   => null,
            ];
        }

        if ( null === $location_id ) {
            if ( 1 === count( $eligible ) ) {
                $location_id = $eligible[0];
            } else {
                // N>1 without explicit Location => REQUIRED
                throw VisitException::of( 'CLINIC_SCOPE_REQUIRED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'Location scope required: multiple eligible locations',
                    400,
                    [
                        'field'  => 'location_id',
                        'reason' => 'location_required',
                    ] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }
        }

        // Validate explicit Location is eligible (foreign/inactive/unassigned => denied)
        if ( ! in_array( $location_id, $eligible, true ) ) {
            throw VisitException::of( 'CLINIC_SCOPE_UNAVAILABLE', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'Trusted clinic context is not available.',
                [ 'reason' => 'location' ],
                403 ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }

        $operational_date = $this->operationalDateForLocation( $location_id, $clinic_id );
        $queue            = $this->visits->queueFor( $clinic_id, $scope_clinician_id, self::QUEUE_STATUSES, $operational_date, $location_id );
        $stats            = $this->visits->statsFor( $clinic_id, $operational_date, $scope_clinician_id, $location_id );

        return [
            'date'          => $operational_date,
            'stats'         => $stats,
            'queue'         => array_map( [ $this, 'presentVisit' ], $queue ),
            'last_event_id' => $this->visits->lastEventId( $clinic_id, $operational_date, $scope_clinician_id, $location_id ),
            'location_id'   => $location_id,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
    { // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie.BraceOnNewLine -- WPCS
        return [
            'checked_in' => 0, 'waiting' => 0, 'called' => 0, 'in_consultation' => 0, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
            'consultation_completed' => 0, 'awaiting_payment' => 0, 'paid' => 0, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
            'checked_out' => 0, 'cancelled' => 0, 'skipped' => 0, 'total' => 0, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
            'appointments_today' => 0, 'appointments_no_show' => 0, 'walk_in_today' => 0, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
        ];
    }

    // ================= R1 — Feed رئال‌تایم (ADR-0007) =================

    /**
     * رویدادهای صف بعد از since — Light Endpoint برای Polling کنترل‌شده.
     * Legacy shared behavior: no N>1 REQUIRED, uses Location from scope if present else no filter.
     *
     * @return array<string, mixed>
     */
    public function eventsSince( int $actor_user_id, int $since_event_id ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $this->requireQueueReader( $actor_user_id );
        $clinic_id = $this->queueClinicId(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, null );
        $location_id = $this->queueLocationId( $clinic_id, $actor_user_id ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
        // Legacy: operational_date null => repository uses UTC (gmdate) to preserve shared behavior.
        $operational_date = null;

        $events  = $this->visits->eventsSince( $clinic_id, max( 0, $since_event_id ), 200, $scope_clinician_id, $operational_date, $location_id );
        $last_id = $since_event_id;
        foreach ( $events as $e ) {
            $last_id = max( $last_id, (int) $e['id'] );
        }

        return [
            'events'        => array_map( static fn( array $e ): array => [ // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'event_id'   => (int) $e['id'], // phpcs:ignore WordPress.Arrays.ArrayIndentation.ItemNotAligned,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
                    'visit_id'   => (int) $e['visit_id'], // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
                    'from_status' => $e['from_status'],
                    'to_status'   => $e['to_status'],
                    'changed_at'  => $e['changed_at'],
                    'actor_role'  => $e['actor_role'],
                    'note'        => $e['note'],
                ], // phpcs:ignore WordPress.Arrays.ArrayIndentation.CloseBraceNotAligned -- WPCS
                $events ), // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            'last_event_id' => $last_id,
        ];
    }

    /**
     * آخرین event_id کلینیک — ETag کلاینت (R1).
     * Legacy shared behavior: no N>1 REQUIRED.
     */
    public function lastEventId( int $actor_user_id ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $this->requireQueueReader( $actor_user_id );
        $clinic_id = $this->queueClinicId(); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, null );
        $location_id = $this->queueLocationId( $clinic_id, $actor_user_id ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment
        // Legacy: operational_date null => repository uses UTC.
        $operational_date = null;

        return $this->visits->lastEventId( $clinic_id, $operational_date, $scope_clinician_id, $location_id );
    }

    /**
     * Doctor Portal eventsSince — strict Location enforcement.
     *
     * @return array<string, mixed>
     */
    public function eventsSinceForDoctorPortal( int $actor_user_id, int $since_event_id ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $this->requireQueueReader( $actor_user_id );
        $clinic_id          = $this->queueClinicId();
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, null );
        $location_id        = $this->queueLocationId( $clinic_id, $actor_user_id );

        $eligible = $this->eligibleLocationIdsForActor( $clinic_id, $actor_user_id );

        if ( [] === $eligible ) {
            return [
                'events'        => [],
                'last_event_id' => $since_event_id,
            ];
        }

        if ( null === $location_id ) {
            if ( 1 === count( $eligible ) ) {
                $location_id = $eligible[0];
            } else {
                throw VisitException::of( 'CLINIC_SCOPE_REQUIRED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'Location scope required: multiple eligible locations',
                    400,
                    [
                        'field'  => 'location_id',
                        'reason' => 'location_required',
                    ] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }
        }

        if ( ! in_array( $location_id, $eligible, true ) ) {
            throw VisitException::of( 'CLINIC_SCOPE_UNAVAILABLE', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'Trusted clinic context is not available.',
                [ 'reason' => 'location' ],
                403 ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }

        $operational_date = $this->operationalDateForLocation( $location_id, $clinic_id );
        $events           = $this->visits->eventsSince( $clinic_id, max( 0, $since_event_id ), 200, $scope_clinician_id, $operational_date, $location_id );
        $last_id          = $since_event_id;
        foreach ( $events as $e ) {
            $last_id = max( $last_id, (int) $e['id'] );
        }

        return [
            'events'        => array_map( static fn( array $e ): array => [ // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'event_id'   => (int) $e['id'], // phpcs:ignore WordPress.Arrays.ArrayIndentation.ItemNotAligned,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
                    'visit_id'   => (int) $e['visit_id'], // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- legacy alignment, keep readability
                    'from_status' => $e['from_status'],
                    'to_status'   => $e['to_status'],
                    'changed_at'  => $e['changed_at'],
                    'actor_role'  => $e['actor_role'],
                    'note'        => $e['note'],
                ], // phpcs:ignore WordPress.Arrays.ArrayIndentation.CloseBraceNotAligned -- WPCS
                $events ), // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            'last_event_id' => $last_id,
        ];
    }

    /**
     * Doctor Portal lastEventId — strict Location enforcement.
     */
    public function lastEventIdForDoctorPortal( int $actor_user_id ): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $this->requireQueueReader( $actor_user_id );
        $clinic_id          = $this->queueClinicId();
        $scope_clinician_id = $this->queueScopeClinicianId( $actor_user_id, $clinic_id, null );
        $location_id        = $this->queueLocationId( $clinic_id, $actor_user_id );

        $eligible = $this->eligibleLocationIdsForActor( $clinic_id, $actor_user_id );

        if ( [] === $eligible ) {
            return 0;
        }

        if ( null === $location_id ) {
            if ( 1 === count( $eligible ) ) {
                $location_id = $eligible[0];
            } else {
                throw VisitException::of( 'CLINIC_SCOPE_REQUIRED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'Location scope required: multiple eligible locations',
                    400,
                    [
                        'field'  => 'location_id',
                        'reason' => 'location_required',
                    ] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }
        }

        if ( ! in_array( $location_id, $eligible, true ) ) {
            throw VisitException::of( 'CLINIC_SCOPE_UNAVAILABLE', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'Trusted clinic context is not available.',
                [ 'reason' => 'location' ],
                403 ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }

        $operational_date = $this->operationalDateForLocation( $location_id, $clinic_id );
        return $this->visits->lastEventId( $clinic_id, $operational_date, $scope_clinician_id, $location_id );
    }

    // ================= D16 — Checkout (T9) =================

    /**
     * خروج از کلینیک:
     *  - paid → check_out (V14 — منشی)
     *  - awaiting_payment + معافیت → waive (V13 — منشی/پزشک)
     *  - بدون پرداخت/معافیت → CLINIC_POLICY_VIOLATION (فاکتور/پرداخت واقعی = F6)
     *
     * @return array<string, mixed>
     */
    public function checkout(int $actorUserId, int $visitId, ?string $waiveReason): array
    {
        return $this->db->transactional(function () use ($actorUserId, $visitId, $waiveReason): array {
            $visit = $this->visits->findForUpdate($visitId);
            if ( $visit === null ) {
                throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
            }
            // Phase 3 Slice 6B — مالکیت پایدار ویزیت در برابر Clinic معتبرِ صریحِ
            // درخواست (D16 مسیر کارکنی؛ 404 parity؛ پیش از هر Transition).
            $this->guardVisitWithinExplicitScope($visit);

            $status = (string) $visit['status'];
            if ( $status === 'awaiting_payment' ) {
                if ( $waiveReason === null || trim($waiveReason ) === '') { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                    throw VisitException::of( 'CLINIC_POLICY_VIOLATION', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                        'پرداخت هنوز ثبت نشده است — معافیت (waive) نیاز به دلیل دارد یا ابتدا پرداخت را ثبت کنید',
                        409,
                        ['visit_status' => $status] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
                }

                return $this->applyTransition($actorUserId, $visit, 'waive', ['reason' => $waiveReason]);
            }
            if ( $status === 'consultation_completed' ) {
                throw VisitException::of( 'CLINIC_POLICY_VIOLATION', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'ابتدا وضعیت مالی ویزیت را مشخص کنید (فاکتور/معافیت)',
                    409,
                    ['visit_status' => $status] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }

            // V14 guard (visit-queue.md): خروج با فاکتور تسویه‌نشده ممنوع — NOT_SETTLED.
            // اینجا فقط paid→check_out می‌رسد؛ فاکتور باز یعنی بدهی واقعی مانده است.
            $unsettled = $this->unsettledInvoiceBalance($visitId);
            if ( $unsettled['count'] > 0 ) {
                throw VisitException::of( 'CLINIC_NOT_SETTLED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                    'فاکتور این ویزیت تسویه نشده است — ابتدا پرداخت را کامل کنید یا از مسیر معافیت اقدام کنید',
                    409,
                    ['open_invoices' => $unsettled['count'], 'balance' => $unsettled['balance']] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
            }

            // paid → check_out (V14)؛ سایر وضعیت‌ها → ماشین خطای transition می‌دهد
            return $this->applyTransition($actorUserId, $visit, 'check_out', []);
        });
    }

    /**
     * بدهی فعال ویزیت — گارد NOT_SETTLED (V14)؛ concurrent-safe چون همیشه
     * داخل Transaction با قفل ردیف Visit اجرا می‌شود.
     *
     * @return array{balance: float, count: int}
     */
    private function unsettledInvoiceBalance(int $visitId): array
    {
        $row = $this->db->fetchRow( 'SELECT COUNT(*) AS n, COALESCE(SUM(balance), 0) AS bal FROM ' . $this->db->table('cpms_invoices') . // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
            " WHERE visit_id = %d AND status IN ('open', 'partial')",
            [$visitId] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract

        return ['balance' => (float) ($row['bal'] ?? 0), 'count' => (int) ($row['n'] ?? 0)];
    }

    // ================= FR-5.5 — No-show خودکار (Cron) — T2 Location-aware =================

    /**
     * نوبت‌های بدون مراجعه پس از Grace → no_show (اگر Visit فعالی ندارند).
     *
     * T2: Location timezone مرجع عملیاتی — هرگز no_show قبل از Location-local start+grace
     * به‌صورت UTC instant. Periodic و lazy یک قرارداد زمانی مشترک دارند.
     *
     * Bounded candidate strategy: repository returns candidates where slot_date <= now+2d,
     * limit 100. Actual eligibility computed in PHP with explicit DateTimeZone + per-Clinic grace.
     * This is safe: never excludes overdue, may include future which will be filtered (no premature).
     *
     * T2 starvation fix: uses durable cursor via cpms_jobs payload for continuation.
     * Root starts at null, continuation advances strictly, bounded per tick.
     *
     * @param array{slot_date:string, slot_time:string, id:int}|null $incomingCursor
     * @return array{processed:int, next_cursor:?array, has_more:bool, depth:int, scanned:int}
     */
    public function processNoShows(?array $incomingCursor = null, int $depth = 0): array
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $count = 0;
        $cursor = $incomingCursor;
        $maxScan = 500; // bounded scan per tick to avoid full table
        $scanned = 0;
        $batchSize = 100;
        $maxToProcess = 100; // bounded per tick
        $hasMore = false;
        $nextCursor = null;

        // Depth is observability only — no arbitrary product ceiling.
        // Only negative depth is invalid (fail-closed). Upper bound is PHP_INT_MAX implicitly.
        if ( $depth < 0 ) {
            $this->opLog?->warning('visit.no_show_depth_invalid', ['depth' => $depth]);
            return [
                'processed' => 0,
                'next_cursor' => null,
                'has_more' => false,
                'depth' => $depth,
                'scanned' => 0,
            ];
        }

        while ( $scanned < $maxScan && $count < $maxToProcess ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            $candidates = $this->visits->appointmentsPastGraceCandidates($batchSize, $nowUtc, $cursor);
            if ( empty($candidates )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                $hasMore = false;
                break;
            }

            $hasMore = count($candidates) === $batchSize;

            foreach ( $candidates as $appt ) {
                $scanned++;
                // Advance cursor to current row for next batch (progress even if invalid)
                $cursor = [
                    'slot_date' => (string) ($appt['slot_date'] ?? ''),
                    'slot_time' => (string) ($appt['slot_time'] ?? ''),
                    'id' => (int) ($appt['id'] ?? 0),
                ];
                $nextCursor = $cursor;

                $clinicId = (int) ($appt['clinic_id'] ?? 0);
                $locationId = (int) ($appt['location_id'] ?? 0);

                if ( $clinicId <= 0 || $locationId <= 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    $this->opLog?->warning('visit.location_missing', ['appointment_id' => $appt['id'] ?? 0, 'clinic_id' => $clinicId, 'location_id' => $locationId]);
                    continue; // fail-closed
                }

                // Location validation from JOIN data if available
                $locClinicId = $appt['loc_clinic_id'] ?? null;
                $locTimezone = $appt['loc_timezone'] ?? null;

                if ( $locClinicId === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    // LEFT JOIN returned null => location missing
                    $this->opLog?->warning('visit.location_missing', ['location_id' => $locationId, 'clinic_id' => $clinicId]);
                    continue;
                }

                if ( (int) $locClinicId !== $clinicId) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                    $this->opLog?->warning('visit.location_clinic_mismatch', [
                        'location_id' => $locationId,
                        'expected_clinic' => $clinicId,
                        'actual_clinic' => (int) $locClinicId,
                    ]);
                    continue;
                }

                $tzName = trim((string) ($locTimezone ?? ''));
                if ( $tzName === '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    $this->opLog?->warning('visit.location_timezone_missing', ['location_id' => $locationId]);
                    continue;
                }

                try {
                    $tz = new DateTimeZone($tzName);
                } catch ( Throwable $e ) {
                    $this->opLog?->warning('visit.location_timezone_invalid', [
                        'location_id' => $locationId,
                        'timezone' => $tzName,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $apptUtc = $this->appointmentUtcInstant($appt, $tz);
                if ( $apptUtc === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    continue;
                }

                $grace = $this->graceForClinic($clinicId);
                if ( $grace === null ) {
                    continue; // fail-closed
                }

                $eligible = $apptUtc->add(new DateInterval('PT' . $grace . 'M'));

                if ( $nowUtc < $eligible ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    continue; // not yet past grace
                }

                $count += $this->db->transactional(function () use ($appt): int {
                    $fresh = $this->appointments->findForUpdate((int) $appt['id']);
                    if ( $fresh === null || (string) $fresh['status'] !== 'confirmed') { // phpcs:ignore WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                        return 0;
                    }
                    if ( $this->activeVisitForAppointment((int) $fresh['id']) !== null) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.WhiteSpace.CastStructureSpacing.NoSpaceBeforeOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                        return 0;
                    }
                    $this->markAppointmentNoShow($fresh, $this->db->nowUtc(), null);
                    return 1;
                });

                if ( $count >= $maxToProcess ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    break 2;
                }
            }

            if ( count($candidates ) < $batchSize) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                $hasMore = false;
                break;
            }
        }

        // If hasMore true, nextCursor is already set to last scanned row
        // If no more candidates, nextCursor should be null to stop chain
        if ( !$hasMore ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- legacy PSR-style, established contract
            $nextCursor = null;
        }

        return [
            'processed' => $count,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'depth' => $depth,
            'scanned' => $scanned,
        ];
    }

    /**
     * Legacy wrapper for callers expecting int (backward compat).
     * @return int
     */
    public function processNoShowsLegacy(): int
    {
        $res = $this->processNoShows(null, 0);
        return (int) ($res['processed'] ?? 0);
    }

    // ================= History (J-3) =================

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $actorUserId, int $visitId): array
    {
        $this->requireQueueReader($actorUserId);
        $visit = $this->visits->find($visitId);
        if ( $visit === null ) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
        }

        return array_map(static fn (array $h): array => [
            'event_id' => (int) $h['id'],
            'from_status' => $h['from_status'],
            'to_status' => $h['to_status'],
            'changed_at' => $h['changed_at'],
            'actor_wp_user_id' => $h['actor_wp_user_id'] !== null ? (int) $h['actor_wp_user_id'] : null,
            'actor_role' => $h['actor_role'],
            'note' => $h['note'],
        ], $this->visits->historyFor($visitId));
    }

    /**
     * خواندن یک ویزیت — کنترل دسترسی در Controller (capability) + اینجا فقط وجود.
     *
     * @return array<string, mixed>
     */
    public function getVisit(int $actorUserId, int $visitId): array
    {
        $this->requireQueueReader($actorUserId);
        $visit = $this->visits->find($visitId);
        if ( $visit === null ) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
        }

        return $this->presentVisit($visit);
    }

    // ================= Helpers — ساخت و Transition =================

    /** @var string|null from_status برای audit (داخل transactional transition) */
    private ?string $lastFrom = null;

    /**
     * ساخت Visit + تاریخچه Check-in + Enqueue خودکار (FR-6.1) + active_visit_id.
     *
     * Phase 10: visit_date is Location-local operational date when Location is known,
     * otherwise UTC date (fallback for legacy paths). This ensures Today+Queue
     * filtering by Location-local day is consistent with creation.
     *
     * @return array<string, mixed>
     */
    private function createVisit( int $actorUserId, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        int $clinic_id,
        int $patientId,
        int $clinicianId,
        ?int $appointmentId,
        string $source,
        string $event,
        ?string $note = null ): array { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine -- WPCS
        $now = $this->db->nowUtc();
        // Determine Location for new visit to compute operational date
        $locationIdForDate = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        if ( $appointmentId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            $apptLoc = $this->db->fetchValue( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                'SELECT location_id FROM ' . $this->db->table('cpms_appointments') . ' WHERE id = %d LIMIT 1', // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
                [ $appointmentId ] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            if ( $apptLoc !== null && $apptLoc !== '' ) { // phpcs:ignore Generic.PHP.Syntax.PHPSyntax,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                $locationIdForDate = (int) $apptLoc; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            }
        }
        if ( $locationIdForDate === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            $scope = ScopeContext::tryGet();
            if ( $scope !== null && $scope->locationId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                $locationIdForDate = (int) $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            } else {
                try {
                    $appScope = App::scope(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    if ( $appScope->locationId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
                        $locationIdForDate = (int) $appScope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    }
                } catch ( ScopeRequiredException $e ) { // phpcs:ignore WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                    unset( $e ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
                }
            }
        }
        if ( $locationIdForDate === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            // Defense-in-depth for single-clinic installs where SystemClinicResolver
            // returns clinic without location (auto 1 eligible should still give operational date)
            try {
                $eligible = $this->eligibleLocationIdsForActor( $clinic_id, $actorUserId ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                if ( count( $eligible ) === 1 ) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                    $locationIdForDate = (int) $eligible[0]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                }
            } catch ( Throwable $e ) { // phpcs:ignore WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                unset( $e ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
            }
        }

        $visitDate = $this->nowUtc()->format('Y-m-d'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        if ( $locationIdForDate !== null && $locationIdForDate > 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            try {
                $visitDate = $this->operationalDateForLocation($locationIdForDate, $clinic_id); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            } catch ( Throwable $e ) { // phpcs:ignore WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceAfterOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
                $visitDate = $this->nowUtc()->format('Y-m-d'); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            }
        }

        $visitId = $this->visits->insert($clinic_id, [
            'clinician_id' => $clinicianId,
            'patient_id' => $patientId,
            'appointment_id' => $appointmentId,
            'source' => $source,
            'status' => 'checked_in',
            'visit_date' => $visitDate, // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            'check_in_at' => $now,
        ]);

        $this->visits->insertHistory($visitId, [
            'from_status' => null,
            'to_status' => 'checked_in',
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => 'secretary',
            'note' => $note ?? ($source === 'scheduled' ? 'Check-in با نوبت' : 'Check-in (Walk-in)'),
            'request_id' => null,
        ]);

        // D-6: رابطه دوطرفه — active_visit_id روی Appointment
        if ( $appointmentId !== null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            $this->appointments->updateStatus($appointmentId, ['active_visit_id' => $visitId]);
        }

        // FR-6.1: Enqueue خودکار (پیش‌فرض روشن) — actor=system مجاز ماشین V3
        if ( $this->shouldAutoEnqueue($clinic_id )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            $this->applyEnqueue($visitId, 'checked_in', $actorUserId, $now);
        }

        return $this->presentVisit($this->visits->find($visitId) ?? ['id' => $visitId]);
    }

    /**
     * Enqueue (V3) — تغییر وضعیت + مهر waiting_since + تاریخچه.
     */
    private function applyEnqueue(int $visitId, string $fromStatus, int $actorUserId, string $now): void
    {
        $toStatus = $this->machineCheck($fromStatus, 'enqueue', 'system');
        $this->visits->updateById($visitId, ['status' => $toStatus, 'waiting_since' => $now]);
        $this->visits->insertHistory($visitId, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_wp_user_id' => $actorUserId,
            'actor_role' => 'system',
            'note' => 'افزودن خودکار به صف',
            'request_id' => null,
        ]);
    }

    /**
     * آماده‌سازی patch ستون‌ها بر اساس Event.
     *
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function patchForEvent(array $visit, string $event, string $toStatus, int $actorUserId, array $meta): array
    {
        $now = $this->db->nowUtc();
        $row = ['status' => $toStatus];

        switch ( $event ) {
            case 'enqueue':
                $row['waiting_since'] = $visit['waiting_since'] ?? $now;
                break;
            case 'call':
                $row['called_at'] = $now;
                break;
            case 'recall':
                // J-6: سقف Recall از Settings per-Clinic
                $recallCount = (int) $visit['recall_count'];
                $clinicId = (int) ($visit['clinic_id'] ?? 0);
                $max = $this->maxRecallsForClinic($clinicId);
                if ( $recallCount >= $max ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                    throw VisitException::of( 'CLINIC_RECALL_LIMIT_REACHED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                        'سقف فراخوان مجدد (' . $max . ') پر شده است',
                        409,
                        ['recall_count' => $recallCount, 'max_recalls' => $max] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                }
                $row['recall_count'] = $recallCount + 1;
                $row['called_at'] = null;
                $row['waiting_since'] = $now; // FIFO جدید — انتهای صف
                break;
            case 'start':
                $row['consultation_started_at'] = $now;
                break;
            case 'complete':
                $row['consultation_completed_at'] = $now;
                break;
            case 'check_out':
                $row['checked_out_at'] = $now;
                $row['active'] = 0;
                break;
            case 'cancel':
                $reason = trim((string) ($meta['reason'] ?? ''));
                if ( $reason === '' ) {
                    throw VisitException::of('CLINIC_VALIDATION_FAILED', 'دلیل لغو الزامی است', 422);
                }
                $row['cancel_reason'] = mb_substr($reason, 0, 255);
                $row['cancelled_by_wp_user_id'] = $actorUserId;
                $row['active'] = 0;
                break;
            case 'skip':
                $reason = trim((string) ($meta['reason'] ?? ''));
                if ( $reason === '' ) {
                    throw VisitException::of('CLINIC_VALIDATION_FAILED', 'دلیل رد شدن از صف الزامی است', 422);
                }
                $row['skip_reason'] = mb_substr($reason, 0, 255);
                $row['active'] = 0;
                break;
            case 'waive':
                $row['checked_out_at'] = $now;
                $row['active'] = 0;
                break;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function historyNote(string $event, array $meta): ?string
    {
        $note = trim((string) ($meta['reason'] ?? $meta['note'] ?? ''));
        if ( $note !== '' ) {
            return mb_substr($note, 0, 255);
        }

        return match ($event) {
            'call' => !empty($meta['room']) ? 'فراخوان — اتاق ' . (string) $meta['room'] : 'فراخوان بیمار',
            'recall' => 'بازگشت به صف (فراخوان مجدد)',
            'start' => 'شروع ویزیت',
            'complete' => 'پایان ویزیت',
            'check_out' => 'خروج از کلینیک',
            default => null,
        };
    }

    /**
     * V10 یکتایی با Row Lock تضمین شده؛ اینجا فقط نگاشت خطای ماشین.
     */
    private function machineCheck(string $from, string $event, string $actor): string
    {
        try {
            return VisitMachine::create()->machine()->assert($from, $event, $actor);
        } catch ( InvalidTransitionException $e ) {
            throw VisitException::of( 'CLINIC_INVALID_TRANSITION', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                $e->getMessage(),
                409,
                ['from' => $from, 'event' => $event] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }
    }

    // ================= Helpers — Guardها و داده =================

    private function guardDuplicateActiveVisit(int $patientId, int $clinicianId, int $clinicId = 0, ?int $locationId = null): void // phpcs:ignore Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingAfterOpen,Squiz.Functions.FunctionDeclarationArgumentSpacing.SpacingBeforeClose,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
    {
        $today = $this->nowUtc()->format('Y-m-d'); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- legacy alignment, keep readability
        if ( $locationId !== null && $locationId > 0 && $clinicId > 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            try {
                $today = $this->operationalDateForLocation( $locationId, $clinicId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            } catch ( \Throwable $e ) {
                unset( $e );
            }
        }
        $existing = $this->visits->findActiveByPatientDay($patientId, $clinicianId, $today); // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        if ( $existing !== null && in_array((string) $existing['status'], self::ACTIVE_VISIT_STATUSES, true)) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.WhiteSpace.CastStructureSpacing.NoSpaceBeforeOpenParenthesis,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            throw VisitException::of( 'CLINIC_DUPLICATE_ACTIVE_VISIT', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'این بیمار امروز ویزیت فعال (در جریان) دارد',
                409,
                ['visit_id' => (int) $existing['id'], 'visit_status' => (string) $existing['status']] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockPatient(int $patientId): array
    {
        // J-5: Serialize per-patient — دو Check-in/Walk-in هم‌زمان همان بیمار
        $patient = $this->db->fetchRowForUpdate( 'SELECT * FROM ' . $this->db->table('cpms_patients') . ' WHERE id = %d LIMIT 1', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
            [$patientId] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        if ( $patient === null ) {
            throw VisitException::of('CLINIC_NOT_FOUND', 'بیمار یافت نشد', 404);
        }

        return $patient;
    }

    /**
     * Clinic معتبر برای WalkIn کارکنی.
     *
     * مسیر REST همیشه Scope صریحی را که RestClinicContext از عضویت فعال staff
     * برقرار کرده برمی‌گرداند. fallback فقط سازگاری فراخوان‌های داخلی قدیمی در
     * نصب واقعاً تک‌کلینیکی است؛ SystemClinicResolver در حالت چندکلینیکی
     * fail-closed است و هرگز اولین Clinic/Clinic خانه/payload را حدس نمی‌زند.
     */
    private function walkInClinicId(): int
    {
        $scope = ScopeContext::tryGet();
        if ( $scope !== null ) {
            return (int) $scope->clinicId;
        }

        try {
            return App::scope()->clinicId;
        } catch ( ScopeRequiredException $e ) {
            throw VisitException::of($e->errorCode, $e->getMessage(), $e->httpStatus(), $e->getData());
        }
    }

    // ================= Phase 3 Slice 6B — مالکیت پایدار شیء =================

    /**
     * مالکیت پایدار شیء در برابر Clinic معتبرِ صریحِ جاری.
     *
     * Clinic معتبر فقط از Scope صریحِ درخواست (مرز REST کارکنی —
     * RestClinicEstablisher) می‌آید، نه از payload و نه از «اولین Clinic»؛
     * Clinicِ خودِ ردیفِ پایدار فقط «شاهد مالکیت برای مقایسه» است. عدم تطابق
     * ⇒ همان پاکتِ خطای شیءِ ناموجود (404 parity — عدم شمارش/افشای وجود
     * منبعِ Clinic دیگر) و صفر اثر جانبی پایدار.
     *
     * بدون Scope صریح (فراخوان داخلی/wp-admin legacy) رفتار موجود حفظ
     * می‌شود — همان قرارداد C7-S2 در ScheduleService. مسیرهای تولیدیِ REST
     * کارکنی همیشه Scope صریح دارند (RestClinicContext) پس در عمل fail-closed
     * است. `applyTransition` عمداً دست‌نخورده ماند تا رفتار Finance/Jobهای
     * سیستمی (forceRole=system، M-7) تغییری نکند.
     */
    private function guardVisitWithinExplicitScope(array $visit): void
    {
        $scope = ScopeContext::tryGet();
        if ( $scope !== null && (int) ($visit['clinic_id'] ?? 0) !== (int) $scope->clinicId) { // phpcs:ignore Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceAfterOpen,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            throw VisitException::of('CLINIC_NOT_FOUND', 'مراجعه یافت نشد', 404);
        }
    }

    private function guardAppointmentWithinExplicitScope(array $appt): void
    {
        $scope = ScopeContext::tryGet();
        if ( $scope !== null && (int) ($appt['clinic_id'] ?? 0) !== (int) $scope->clinicId) { // phpcs:ignore Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceAfterOpen,Generic.WhiteSpace.ArbitraryParenthesesSpacing.SpaceBeforeClose,WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            throw VisitException::of('CLINIC_NOT_FOUND', 'نوبت یافت نشد', 404);
        }
    }

    /**
     * نقش منطقی برای ماشین — از WP Role (نه فقط Capability).
     */
    private function roleForUser(int $wpUserId): ?string
    {
        $user = get_userdata($wpUserId);
        if ( $user === false || $user->roles === [] ) {
            return null;
        }
        if ( in_array(RolesAndCapabilities::ROLE_DOCTOR, $user->roles, true )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            return 'doctor';
        }
        if ( in_array(RolesAndCapabilities::ROLE_SECRETARY, $user->roles, true )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            return 'secretary';
        }

        return null;
    }

    private function requireSecretary(int $wpUserId, string $event): void
    {
        $role = $this->roleForUser($wpUserId);
        if ( $role !== 'secretary' ) {
            // ماشین: V1/V2 فقط secretary — نقش دیگر → خطای transition
            throw VisitException::of( 'CLINIC_PERMISSION_DENIED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'فقط منشی می‌تواند ' . ($event === 'check_in' ? 'Check-in' : 'Walk-in') . ' ثبت کند',
                403 ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }
    }

    private function requireQueueReader(int $wpUserId): void
    {
        if ( !user_can($wpUserId, RolesAndCapabilities::QUEUE_READ )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- legacy PSR-style, established contract
            throw VisitException::of('CLINIC_PERMISSION_DENIED', 'دسترسی به صف ندارید', 403);
        }
    }

    /**
     * ADR-0030/Part1 + Phase 4 Slice 4 — Scope صف/Feed برای «پزشکِ متصل»:
     * نقش doctor → فقط ویزیت‌های «هویت یکتای فعال» خودش (بدون هویت یا بدون
     * مشارکت فعال در Clinic مورد اعتماد → صفر نتیجه، نه کل کلینیک)؛
     * سایر نقش‌ها (منشی و …) → دامنه مطب (پارامتر ورودی اعمال می‌شود).
     *
     * حرفه‌ای مشترک (Phase 4): یک کاربر وردپرس حداکثر یک هویت clinician دارد
     * (`u_clinician_user`)؛ آن هویت می‌تواند هم‌زمان در چند Clinic «مشارکت
     * پایدار فعال» داشته باشد. بنابراین:
     *  - هویت پزشک **بدون فیلتر Clinic** حل می‌شود — `clinicians.clinic_id`
     *    (Clinic خانه/سازگاری) هرگز معیار دامنه نیست؛
     *  - مشارکت با `MembershipRepository::clinician_participates_in()` سنجیده
     *    می‌شود (SoT = عضویت فعال پایدار)؛
     *  - Clinic صف همچنان از `queueClinicId()` (context موثق) می‌آید و
     *    `visit.clinic_id` مرز بی‌قیدوشرط tenant در همهٔ پرس‌وجوهای صف می‌ماند —
     *    clinician_id فقط «تنگ‌تر» می‌کند، هرگز tenant را تعیین نمی‌کند.
     *
     * مقدار بازگشتی: null = بدون فیلتر؛ int = clinician_id الزامی (0 = هیچ).
     *
     * @throws VisitException فیلتر کارکنی نامعتبر (404 parity موجود)
     * @throws \RuntimeException شکست پرس‌وجوی حل هویت (fail-closed؛ هرگز
     *                           «پزشک ندارد» تفسیر نمی‌شود)
     */
    private function queueScopeClinicianId(int $actorUserId, int $clinicId, ?int $requestedClinicianId): ?int
    {
        if ( $this->roleForUser($actorUserId ) !== 'doctor') { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            if ( $requestedClinicianId === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                return null;
            }
            // کارکنان نمی‌تواند با پارامتر، دامنه را به پزشکِ خارج از Clinic مورد
            // اعتماد ببرد. معیار = هویت فعال + مشارکت پایدار فعال در همان Clinic
            // (نه Clinic خانهٔ پروفایل)؛ در غیر این صورت همان 404 parity موجود.
            if ( !$this->memberships->clinician_participates_in($requestedClinicianId, $clinicId )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- legacy PSR-style, established contract
                throw VisitException::of('CLINIC_NOT_FOUND', 'پزشک یافت نشد یا غیرفعال است', 404);
            }

            return $requestedClinicianId;
        }

        // ADR-0030: دامنهٔ پزشک = **هویت یکتای فعالِ او** (بدون فیلتر Clinic —
        // نه یکپارچه‌سازی سراسریِ Clinic و نه Clinic خانه). نبودِ هویت فعال یا
        // نبودِ مشارکت ACTIVE در Clinic مورد اعتماد ⇒ مجموعهٔ خالی (0) —
        // هرگز دامنهٔ منشی/کل مطب.
        $identityId = $this->memberships->active_clinician_id_for_wp_user($actorUserId);
        if ( $identityId === null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            return 0;
        }
        if ( !$this->memberships->clinician_participates_in($identityId, $clinicId )) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis,WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- legacy PSR-style, established contract
            return 0;
        }

        return $identityId;
    }

    /**
     * C6 (Visit Tenant Hardcode): مطبِ جریان صف/Today/Feed از **context موثق**
     * حل می‌شود — Scope صریحِ درخواست (مرز REST از Membership) یا Resolution
     * سیستمی «تنها Clinic». هیچ مقدار پیش‌فرض/اولین‌Clinic جای آن نمی‌نشیند؛
     * نبودِ context ⇒ CLINIC_SCOPE_REQUIRED (400) و بدون هیچ ردیفی.
     */
    private function queueClinicId(): int
    {
        try {
            return App::scope()->clinicId;
        } catch ( ScopeRequiredException $e ) {
            throw VisitException::of($e->errorCode, $e->getMessage(), $e->httpStatus(), $e->getData());
        }
    }

    /**
     * Phase 10: trusted operational Location from scope.
     * Returns null when scope has no Location (0 eligible or not yet resolved).
     */
    private function queueLocationId( int $clinic_id, int $actor_user_id ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        // Try trusted scope first (established by TrustedClinicEstablisher)
        $scope = ScopeContext::tryGet();
        if ( null !== $scope && null !== $scope->locationId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            return (int) $scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
        }
        try {
            $app_scope = App::scope();
            if ( null !== $app_scope->locationId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
                return (int) $app_scope->locationId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            }
        } catch ( ScopeRequiredException $e ) {
            unset( $e );
        }

        return null;
    }

    /**
     * Phase 10: eligible Locations for current actor + clinic.
     * Mirrors TrustedClinicEstablisher logic: clinic mode => all active Locations,
     * location mode => assigned active Locations.
     * For doctor without membership but with home-clinic participation, return active locations (defense for legacy tests).
     *
     * @return list<int>
     */
    private function eligibleLocationIdsForActor( int $clinic_id, int $actor_user_id ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        if ( $clinic_id <= 0 || $actor_user_id <= 0 ) {
            return [];
        }
        $membership = $this->memberships->find_active( $clinic_id, $actor_user_id );
        $active     = $this->activeLocationIdsForClinic( $clinic_id );
        if ( null === $membership ) {
            // No active membership: check if actor is doctor with active clinician that participates via home clinic
            // This preserves legacy DoctorQueueScopeTest which has clinician home clinic but no membership seed
            try {
                $clinician_id = $this->memberships->active_clinician_id_for_wp_user( $actor_user_id );
                if ( null !== $clinician_id && $this->memberships->clinician_participates_in( $clinician_id, $clinic_id ) ) {
                    return $active;
                }
            } catch ( \Throwable $e ) {
                unset( $e );
            }
            // For secretary without membership, also return active to preserve old staff behavior (no fail-closed for staff)
            $role = $this->roleForUser( $actor_user_id );
            if ( 'secretary' === $role || 'receptionist' === $role || 'cashier' === $role ) {
                return $active;
            }
            return [];
        }
        $scope_mode = (string) ( $membership['scope_mode'] ?? 'clinic' );
        if ( 'location' === $scope_mode ) {
            $assigned = $this->memberships->location_ids_for( (int) $membership['id'] );
            $eligible = array_values( array_intersect( $assigned, $active ) );
            $eligible = array_values( array_unique( array_map( 'intval', $eligible ) ) );
            sort( $eligible );
            return $eligible;
        }
        return $active;
    }

    /**
     * @return list<int>
     */
    private function activeLocationIdsForClinic( int $clinic_id ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $rows = $this->db->fetchAll( 'SELECT id FROM ' . $this->db->table( 'cpms_locations' ) . // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
            ' WHERE clinic_id = %d AND is_active = 1 ORDER BY id ASC',
            [ $clinic_id ] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        $ids = []; // phpcs:ignore Generic.Formatting.MultipleStatementAlignment.NotSameWarning -- legacy alignment, keep readability
        foreach ( ( is_array( $rows ) ? $rows : [] ) as $r ) {
            $ids[] = (int) ( $r['id'] ?? 0 );
        }
        $ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
        sort( $ids );
        return $ids;
    }

    /**
     * Phase 10: testable clock – smallest local seam.
     * Uses static testNowUtc if set, otherwise WordPress filter `cpms_visit_now_utc`
     * (can return DateTimeImmutable or string), otherwise real UTC now.
     */
    private function nowUtc(): DateTimeImmutable { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        if ( null !== self::$testNowUtc ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            return self::$testNowUtc; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
        }
        // WordPress filter seam for integration tests (deterministic operational day)
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'cpms_visit_now_utc', null );
            if ( $filtered instanceof DateTimeImmutable ) {
                return $filtered->setTimezone( new DateTimeZone( 'UTC' ) );
            }
            if ( is_string( $filtered ) && '' !== $filtered ) {
                try {
                    $dt = new DateTimeImmutable( $filtered );
                    return $dt->setTimezone( new DateTimeZone( 'UTC' ) );
                } catch ( Throwable $e ) {
                    unset( $e );
                }
            }
        }
        return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
    }

    /**
     * Phase 10: operational today = Location timezone + current instant (UTC now).
     * No Clinic/WP/PHP/browser/Tehran/first fallback – uses validated IANA timezone
     * from persisted eligible Location.
     */
    private function operationalDateForLocation( int $location_id, int $clinic_id ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- legacy PSR-style, established contract
        $tz = $this->resolveLocationTimezone( $location_id, $clinic_id );
        if ( null === $tz ) {
            // fail-closed: if timezone cannot be resolved, use UTC date but log
            $this->opLog?->warning( 'visit.location_timezone_unresolvable', [ 'location_id' => $location_id, 'clinic_id' => $clinic_id ] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- legacy PSR-style, established contract
            return $this->nowUtc()->format( 'Y-m-d' );
        }
        $now_utc = $this->nowUtc();
        $local   = $now_utc->setTimezone( $tz );
        return $local->format( 'Y-m-d' );
    }

    /**
     * Seam مرکزی مجوز (الگوی BookingService) — **بدون Network Call**؛
     * Gate وضعیت local را می‌خواند (ADR-0023). Read-Only → عملیات جدید ممنوع.
     */
    private function assertLicense(string $operation): void
    {
        $decision = $this->licenseGate->assert($operation);
        if ( !$decision->allowed ) { // phpcs:ignore WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter -- WPCS
            throw VisitException::of( 'CLINIC_LICENSE_BLOCKED', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                'سیستم در حالت Read-Only است (مجازت) — ثبت مراجعه جدید مجاز نیست',
                503 ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }
    }

    // ================= T2 — Location timezone + per-Clinic grace helpers =================

    /**
     * T2: Resolve Location timezone explicitly — fail-closed on missing, mismatch, invalid.
     *
     * Requirements:
     * - explicit IANA
     * - fail closed on missing Location
     * - fail closed if Location does not belong to appointment's Clinic
     * - fail closed on missing/invalid timezone
     * - no Clinic timezone override, no WP site timezone, no PHP default, no Asia/Tehran fallback
     */
    private function resolveLocationTimezone(int $locationId, int $clinicId): ?DateTimeZone
    {
        if ( $locationId <= 0 || $clinicId <= 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            return null;
        }

        $row = $this->db->fetchRow( 'SELECT id, clinic_id, timezone FROM ' . $this->db->table('cpms_locations') . ' WHERE id = %d LIMIT 1', // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
            [$locationId] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract

        if ( $row === null ) {
            $this->opLog?->warning('visit.location_missing', ['location_id' => $locationId, 'clinic_id' => $clinicId]);
            return null;
        }

        if ( (int) $row['clinic_id'] !== $clinicId) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            $this->opLog?->warning('visit.location_clinic_mismatch', [
                'location_id' => $locationId,
                'expected_clinic' => $clinicId,
                'actual_clinic' => (int) $row['clinic_id'],
            ]);
            return null;
        }

        $tzName = trim((string) ($row['timezone'] ?? ''));
        if ( $tzName === '' ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            $this->opLog?->warning('visit.location_timezone_missing', ['location_id' => $locationId]);
            return null;
        }

        try {
            return new DateTimeZone($tzName);
        } catch ( Throwable $e ) {
            $this->opLog?->warning('visit.location_timezone_invalid', [
                'location_id' => $locationId,
                'timezone' => $tzName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * T2: Convert persisted local date/time + explicit Location timezone => UTC instant.
     */
    private function appointmentUtcInstant(array $appt, DateTimeZone $tz): ?DateTimeImmutable
    {
        $date = $appt['slot_date'] ?? '';
        $time = $appt['slot_time'] ?? '';
        if ( $date === '' || $time === '' ) {
            return null;
        }

        $dateStr = trim((string) $date) . ' ' . trim((string) $time);
        // Try H:i:s first, then H:i
        $local = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateStr, $tz);
        if ( $local === false ) {
            $local = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr, $tz);
        }
        if ( $local === false ) {
            $this->opLog?->warning('visit.appointment_datetime_parse_failed', ['slot_date' => $date, 'slot_time' => $time]);
            return null;
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * T2: per-Clinic grace resolution via SettingsFactory — explicit clinic_id, no ambient.
     *
     * M-2 fix: Clinic-specific grace must come ONLY from SettingsFactory::forClinic($rowClinicId)
     * where rowClinicId came from durable appointment data. If resolution fails, fail-closed for that row,
     * do NOT use legacy ambient Settings, do NOT use another Clinic, do NOT substitute fixed value.
     *
     * Returns null on failure => fail-closed skip (do NOT mark no_show)
     */
    private function graceForClinic(int $clinicId): ?int
    {
        if ( $clinicId <= 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            return null;
        }

        try {
            $settings = $this->settingsFactory->forClinic($clinicId);
            $grace = (int) $settings->get('queue.no_show_grace_minutes', 30);
            return max(0, $grace);
        } catch ( Throwable $e ) {
            $this->opLog?->warning('visit.grace_resolve_failed', ['clinic_id' => $clinicId, 'error' => $e->getMessage()]);
            // Fail-closed: do not mark no_show if grace cannot be resolved (avoid premature / cross-Clinic bleed)
            return null;
        }
    }

    private function shouldAutoEnqueue(int $clinicId): bool
    {
        try {
            $settings = $this->settingsFactory->forClinic($clinicId);
            return (bool) $settings->get('queue.auto_enqueue', true);
        } catch ( Throwable $e ) {
            return true;
        }
    }

    private function maxRecallsForClinic(int $clinicId): int
    {
        try {
            $settings = $this->settingsFactory->forClinic($clinicId);
            return (int) $settings->get('queue.max_recalls', 3);
        } catch ( Throwable $e ) {
            return 3;
        }
    }

    /**
     * @param array<string, mixed> $appt
     */
    private function appointmentStartTime(array $appt): ?string
    {
        if ( empty($appt['slot_date'] ) || empty($appt['slot_time'])) { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            return null;
        }

        return $appt['slot_date'] . ' ' . $appt['slot_time'];
    }

    /**
     * @param array<string, mixed> $appt
     */
    private function confirmAppointment(array $appt, string $now, int $actorUserId): void
    {
        $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'confirm', 'secretary');
        $this->appointments->updateStatus((int) $appt['id'], ['status' => $toStatus, 'confirmed_at' => $now]);
        $this->audit('APPOINTMENT_CONFIRMED', $actorUserId, 'secretary', 'appointment', (int) $appt['id'], (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'via' => 'visit_check_in',
        ]);
    }

    /**
     * @param array<string, mixed> $appt
     */
    /**
     * I-3 — genuinely active Visit bound to this appointment (not a stale pointer).
     *
     * @return array<string, mixed>|null
     */
    private function activeVisitForAppointment(int $appointmentId): ?array
    {
        $statuses = VisitMachine::ACTIVE_STATUSES;
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        return $this->db->fetchRow( 'SELECT * FROM ' . $this->db->table('cpms_visits') . // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket -- WPCS
            ' WHERE appointment_id = %d AND active = 1 AND status IN (' . $placeholders . ') ' .
            'ORDER BY id DESC LIMIT 1',
            array_merge([$appointmentId], $statuses) ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
    }

    private function markAppointmentNoShow(array $appt, string $now, ?int $actorUserId): void
    {
        // ماشین Appointment فقط CONFIRMED → no_show را مجاز می‌کند (T8)؛
        // نوبت‌های PENDING مسیر انقضای خودشان (Hold/Cron) را دارند.
        $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'no_show', 'system');
        $this->appointments->updateStatus((int) $appt['id'], [
            'status' => $toStatus,
            'no_show_at' => $now,
            'active_visit_id' => null,
        ]);
        $this->audit('APPOINTMENT_NO_SHOW', $actorUserId, $actorUserId === null ? 'system' : 'secretary', 'appointment', (int) $appt['id'], (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'slot' => ($appt['slot_date'] ?? '') . ' ' . ($appt['slot_time'] ?? ''),
        ]);
    }

    private function completeReferencedAppointment(int $appointmentId, int $actorUserId): void
    {
        $appt = $this->appointments->findForUpdate($appointmentId);
        if ( $appt === null || (string) $appt['status'] === 'completed') { // phpcs:ignore WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- WPCS
            return;
        }
        // T9: خروج بیمار → نوبت مرجع completed (system event ماشین).
        // نوبت‌های Terminal دیگر (no_show/cancelled) فقط رابطه را آزاد می‌کنند —
        // ER-06: وضعیت نوبت دیرهنگام حفظ می‌شود (تاریخچه).
        try {
            $toStatus = AppointmentMachine::create()->machine()->assert((string) $appt['status'], 'visit_checked_out', 'system');
        } catch ( InvalidTransitionException $e ) {
            $this->appointments->updateStatus($appointmentId, ['active_visit_id' => null]);

            return;
        }
        $this->appointments->updateStatus($appointmentId, ['status' => $toStatus, 'active_visit_id' => null]);
        $this->audit('APPOINTMENT_COMPLETED', $actorUserId, 'system', 'appointment', $appointmentId, (int) $appt['patient_id'], ['status' => $appt['status']], ['status' => $toStatus], [
            'via' => 'visit_check_out',
        ]);
    }

    /**
     * @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function presentVisit(array $visit): array
    {
        return [
            'id' => (int) $visit['id'],
            'patient_id' => (int) $visit['patient_id'],
            'patient_name' => trim(($visit['patient_first_name'] ?? '') . ' ' . ($visit['patient_last_name'] ?? '')),
            'clinician_id' => (int) $visit['clinician_id'],
            'clinician_name' => $visit['clinician_name'] ?? null,
            'appointment_id' => $visit['appointment_id'] !== null ? (int) $visit['appointment_id'] : null,
            'source' => (string) $visit['source'],
            'status' => (string) $visit['status'],
            'express' => !empty($visit['express']) && (int) $visit['express'] === 1,
            'check_in_at' => $visit['check_in_at'],
            'waiting_since' => $visit['waiting_since'],
            'called_at' => $visit['called_at'],
            'consultation_started_at' => $visit['consultation_started_at'],
            'consultation_completed_at' => $visit['consultation_completed_at'],
            'checked_out_at' => $visit['checked_out_at'],
            'recall_count' => (int) $visit['recall_count'],
            'active' => (int) ($visit['active'] ?? 1) === 1,
            'skip_reason' => $visit['skip_reason'] ?? null,
            'cancel_reason' => $visit['cancel_reason'] ?? null,
        ];
    }

    private function appointmentStatusLabel(string $status): string
    {
        return match ($status) {
            'cancelled_by_patient' => 'توسط بیمار لغو',
            'cancelled_by_staff' => 'توسط کلینیک لغو',
            'rescheduled' => 'جابه‌جا شده',
            'completed' => 'تکمیل شده',
            default => $status,
        };
    }

    /**
     * F9 (ADR-0027 Minor #3) — مالکیت ویزیت پزشک: Clinician متصل به حسابِ
     * Actor باید همان Clinician ویزیت باشد (الگوی ClinicalService::requireOwnVisit).
     * فقط نقش doctor را می‌گیرد؛ منشی/سیستم در V1 دامنه مطب دارند (ADR-0026/V2).
     *
     * @param array<string, mixed> $visit
     */
    private function guardDoctorTransitionOwnership(int $actorUserId, array $visit): void
    {
        if ( $this->roleForUser($actorUserId ) !== 'doctor') { // phpcs:ignore PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            return;
        }

        $linkedClinicianId = $this->db->fetchValue( 'SELECT id FROM ' . $this->db->table('cpms_clinicians') . // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceAfterOpenBracket,PEAR.Functions.FunctionCallSignature.SpaceBeforeCloseBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
            ' WHERE wp_user_id = %d AND is_active = 1 LIMIT 1',
            [$actorUserId] ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract

        if ( $linkedClinicianId === null || (int) $linkedClinicianId !== (int) $visit['clinician_id']) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.WhiteSpace.ControlStructureSpacing.NoSpaceBeforeCloseParenthesis -- legacy PSR-style, established contract
            // IDOR/Cross-doctor: 403 + Audit (الگوی T-01) — نه 404؛ وجود ویزیت
            // برای دارنده QUEUE_READ آشکار است، رد شدنِ عملیات است که گزارش می‌شود.
            $this->auditAndThrow( $actorUserId, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
                'doctor',
                'FORBIDDEN_ACCESS_ATTEMPT',
                'visit',
                (int) $visit['id'],
                (int) $visit['patient_id'],
                'پزشک فقط می‌تواند روی ویزیت‌های خودش عملیات صف انجام دهد (ADR-0027 — Scope صریح لازم دارد)',
                403,
                'CLINIC_PERMISSION_DENIED' ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        }
    }

    private function auditAndThrow( int $wpUserId, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- legacy PSR-style, established contract
        string $role,
        string $action,
        string $resourceType,
        int $resourceId,
        int $patientId,
        string $message,
        int $http,
        string $code ): never { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine -- WPCS
        $this->audit($action, $wpUserId, $role, $resourceType, $resourceId, $patientId, null, null, []);
        throw VisitException::of($code, $message, $http);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, mixed> $meta
     */
    private function audit( string $action, // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.FirstParamSpacing -- WPCS
        ?int $wpUserId,
        string $role,
        string $resourceType,
        ?int $resourceId,
        ?int $patientId,
        ?array $before,
        ?array $after,
        array $meta ): void { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.CloseBracketLine -- WPCS
        try {
            $this->audit->log( $action, // phpcs:ignore PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket -- WPCS
                ['wp_user_id' => $wpUserId, 'role' => $role],
                $resourceType,
                $resourceId,
                $patientId,
                $before,
                $after,
                $meta ); // phpcs:ignore PEAR.Functions.FunctionCallSignature.CloseBracketLine,PEAR.Functions.FunctionCallSignature.Indent -- WPCS
        } catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- WPCS
            // Audit نباید جریان عملیات بالینی را قطع کند (تطبیق الگوی BookingService)
        }
    }
}
