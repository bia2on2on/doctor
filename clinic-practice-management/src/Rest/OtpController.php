<?php

declare(strict_types=1);

namespace ClinicCore\Rest;

use ClinicCore\Application\Auth\OtpException;
use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Scope\ScopeRequiredException;
use ClinicCore\Bootstrap\App;
use ClinicCore\Domain\Booking\BookingException;
use ClinicCore\Infrastructure\Security\ClientIp;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Endpointهای Public احراز هویت — API Contract A2/A3.
 *
 * امنیت: Public (بدون Nonce) — به همین دلیل Rate Limit سخت‌گیرانه
 * (otp-day, otp-hour, otp-ip) + محدودیت‌های OtpPolicy.
 */
final class OtpController extends RestBase
{
    public function __construct(private readonly OtpService $otp)
    {
    }

    public function register_routes(): void
    {
        register_rest_route(self::NS, '/otp/request', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->requestCode($request),
                'permission_callback' => fn () => $this->permPublic(),
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'purpose' => ['required' => false, 'type' => 'string', 'enum' => OtpService::PURPOSES, 'default' => OtpService::PURPOSE_LOGIN],
                    // Phase 8 Slice 2 — انتخابِ preserved (SELECTORS ONLY):
                    // هیچ‌کدام از این‌ها authority مستقیم Clinic نیستند؛ سرور
                    // فقط از دادهٔ persisted (clinician/slot) Clinic را مشتق
                    // می‌کند. بدون انتخاب، رفتار تثبیت‌شده حفظ می‌شود.
                    'clinician_id' => ['required' => false, 'type' => 'integer'],
                    'slot_id' => ['required' => false, 'type' => 'integer'],
                    'slot_date' => ['required' => false, 'type' => 'string'],
                    'slot_time' => ['required' => false, 'type' => 'string'],
                ],
            ],
        ]);

        register_rest_route(self::NS, '/otp/verify', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => fn (WP_REST_Request $request) => $this->verifyCode($request),
                'permission_callback' => fn () => $this->permPublic(),
                'args' => [
                    'mobile' => ['required' => true, 'type' => 'string'],
                    'code' => ['required' => true, 'type' => 'string'],
                    'purpose' => ['required' => false, 'type' => 'string', 'enum' => OtpService::PURPOSES, 'default' => OtpService::PURPOSE_LOGIN],
                ],
            ],
        ]);
    }

    private function requestCode(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            // Phase 8 Slice 2 — Clinicِ Challenge فقط از دادهٔ persisted مشتق
            // می‌شود (A2 با انتخابِ preserved). Tamper/ناسازگاری: 404/422
            // تثبیت‌شده، صفر Challenge، صفر SMS.
            $clinicId = $this->resolveSelectionClinic($request);

            $result = $this->otp->request(
                (string) $request->get_param('mobile'),
                (string) $request->get_param('purpose'),
                null,
                $this->clientIp($request),
                $clinicId
            );

            return $this->success($result, 200);
        } catch (OtpException $e) {
            return $this->error($e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData());
        } catch (BookingException $e) {
            return $this->error($e->errorCode, $e->httpStatus, $e->getMessage(), $e->data);
        } catch (ScopeRequiredException $e) {
            // بدون انتخاب، روی نصب چند-Clinic: fail-closed صریح با پاکت
            // محصول — هرگز استثنای فراری، هرگز 500.
            return $this->error(
                'CLINIC_SCOPE_REQUIRED',
                400,
                'برای دریافت کد، ابتدا پزشک و نوبت موردنظر را انتخاب کنید'
            );
        }
    }

    private function verifyCode(WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $result = $this->otp->verify(
                (string) $request->get_param('mobile'),
                (string) $request->get_param('code'),
                (string) $request->get_param('purpose'),
                $this->clientIp($request)
            );

            return $this->success($result, 200);
        } catch (OtpException $e) {
            return $this->error($e->apiCode(), $e->httpStatus(), $e->getMessage(), $e->getData());
        } catch (ScopeRequiredException $e) {
            // Challenge تاریخیِ بدون Clinic روی نصب چند-Clinic: fail-closed
            // صریح با پاکت محصول — هرگز استثنای فراری، هرگز 500.
            return $this->error(
                'CLINIC_SCOPE_REQUIRED',
                400,
                'امکان تعیین مرکزِ این کد وجود ندارد — درخواست کد با انتخاب نوبت را تکرار کنید'
            );
        }
    }

    /**
     * Phase 8 Slice 2 — مشتق‌سازی Clinicِ چالش از «فقط» دادهٔ persisted.
     *
     * وقتی مراجعِ انتخاب (clinician_id, slot_id, slot_date, slot_time) ارائه
     * شده باشند:
     *  ۱. clinicianِ فعالِ persisted؛  ۲. Clinic از clinicianِ persisted؛
     *  ۳. slot داخل همان Clinic؛  ۴. اثباتِ رابطهٔ slot/clinician/date/time؛
     *  ۵. بازگشت Clinicِ ناشی از رابطهٔ persisted نوبت‌دهی.
     *
     * Tamper/ناسازگاری → همان واژگان fail-closed مسیرهای booking:
     * CLINIC_NOT_FOUND/404 (clinician ناموجود/غیرفعال یا slot بیرون از
     * Clinic)، CLINIC_VALIDATION_FAILED/422 (ناهمخوانی tuple یا انتخابِ
     * ناقص). هیچ Challenge و هیچ SMS در این مسیرها ساخته نمی‌شود.
     *
     * بدون انتخاب → null (رفتار تثبیت‌شده: resolver تک-Clinic/Scope محیطی).
     *
     * @throws BookingException
     */
    private function resolveSelectionClinic(WP_REST_Request $request): ?int
    {
        $clinicianId = $request->get_param('clinician_id');
        $slotId = $request->get_param('slot_id');
        $slotDate = $request->get_param('slot_date');
        $slotTime = $request->get_param('slot_time');

        $hasSelection = self::hasValue($clinicianId) || self::hasValue($slotId)
            || self::hasValue($slotDate) || self::hasValue($slotTime);
        if (!$hasSelection) {
            return null;
        }

        // انتخابِ ناقص = ورودی نامعتبر (نه حدس، نه fallback).
        if (!self::hasValue($clinicianId) || !self::hasValue($slotId)
            || !self::hasValue($slotDate) || !self::hasValue($slotTime)) {
            throw BookingException::of(
                'CLINIC_VALIDATION_FAILED',
                'برای ادامهٔ رزرو، انتخاب نوبت ناقص است — دوباره پزشک و نوبت را انتخاب کنید',
                422
            );
        }

        $clinicianId = (int) $clinicianId;
        $slotId = (int) $slotId;
        $slotDate = (string) $slotDate;
        $slotTime = (string) $slotTime;
        if ($clinicianId <= 0 || $slotId <= 0 || $slotDate === '' || $slotTime === '') {
            throw BookingException::of(
                'CLINIC_VALIDATION_FAILED',
                'برای ادامهٔ رزرو، انتخاب نوبت ناقص است — دوباره پزشک و نوبت را انتخاب کنید',
                422
            );
        }

        // ۱+۲) clinicianِ فعالِ persisted — Clinic فقط از ردیفِ persisted.
        $clinician = App::db()->fetchRow(
            'SELECT clinic_id, is_active FROM ' . App::db()->table('cpms_clinicians') . ' WHERE id = %d LIMIT 1',
            [$clinicianId]
        );
        if ($clinician === null || (int) $clinician['is_active'] !== 1) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'پزشک انتخابی یافت نشد', 404);
        }
        $clinicId = (int) $clinician['clinic_id'];

        // ۳) slot باید داخل همان Clinic persisted باشد.
        $slot = App::db()->fetchRow(
            'SELECT clinician_id, slot_date, slot_time FROM ' . App::db()->table('cpms_schedule_slots') .
            ' WHERE id = %d AND clinic_id = %d LIMIT 1',
            [$slotId, $clinicId]
        );
        if ($slot === null) {
            throw BookingException::of('CLINIC_NOT_FOUND', 'اسلات انتخابی یافت نشد', 404);
        }

        // ۴) اثباتِ رابطهٔ slot/clinician/date/time (نرمال‌سازی H:i ↔ H:i:s —
        // همان الگوی مسیر B1).
        $timeMatches = substr((string) $slot['slot_time'], 0, 8) === substr($slotTime, 0, 8);
        if ((int) $slot['clinician_id'] !== $clinicianId
            || (string) $slot['slot_date'] !== $slotDate
            || !$timeMatches) {
            $normRequested = substr($slotDate . ' ' . $slotTime, 0, 19);
            $normSlot = (string) $slot['slot_date'] . ' ' . substr((string) $slot['slot_time'], 0, 8);
            if ((int) $slot['clinician_id'] !== $clinicianId
                || substr($normRequested, 0, 16) !== substr($normSlot, 0, 16)) {
                throw BookingException::of(
                    'CLINIC_VALIDATION_FAILED',
                    'انتخاب نوبت با رکورد زمان‌بندی همخوانی ندارد — دوباره نوبت را انتخاب کنید',
                    422
                );
            }
        }

        // ۵) Clinicِ ناشی از رابطهٔ persisted نوبت‌دهی.
        return $clinicId;
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * IP کلاینت برای Rate Limit.
     *
     * Phase 1A: پیش از این مستقیماً `$_SERVER['REMOTE_ADDR']` خوانده
     * می‌شد. رفتار پیش‌فرض تغییری نکرده (هدرهای Forwarded همچنان
     * بی‌اعتبارند)، اما حالا صریح و پیکربندی‌پذیر است: پشت Proxy معتمدِ
     * اعلام‌شده، IP واقعی کلاینت استخراج می‌شود، وگرنه هدر نادیده گرفته
     * می‌شود. جزئیات در ClientIp.
     */
    private function clientIp(WP_REST_Request $request): ?string
    {
        return ClientIp::resolve();
    }
}
