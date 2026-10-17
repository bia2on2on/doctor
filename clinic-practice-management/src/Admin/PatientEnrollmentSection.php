<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

use ClinicCore\Application\Patients\PatientEnrollmentOptions;

/**
 * Classic Patient Portal — «ثبت پرونده در مرکز دیگر».
 *
 * Pure presentation over the server-derived {@see PatientEnrollmentOptions}
 * view. It never reads a request Clinic id, never queries the database and never
 * receives patient/encounter/identity data. The select contains only names the
 * server already authorized; the enrollment endpoint re-authorizes every POST.
 *
 * Enrollment is not booking: no appointment control or booking action exists in
 * this section. The one-month new-patient reception-only policy is represented
 * by *not* offering online creation to unverified subjects.
 */
final class PatientEnrollmentSection {
    /**
     * @param array{state:string, clinics:list<array{id:int, name:string}>} $options
     */
    public static function render( array $options ): string {
        $state   = (string) ( $options['state'] ?? PatientEnrollmentOptions::STATE_UNAVAILABLE );
        $clinics = self::sanitize_clinics( $options['clinics'] ?? [] );

        if ( $state === PatientEnrollmentOptions::STATE_ELIGIBLE && $clinics === [] ) {
            $state = PatientEnrollmentOptions::STATE_UNAVAILABLE;
        }

        $html  = '<section class="cpms-pp-section cpms-pp-enrollment" id="enrollment"'
            . ' data-role="enrollment-section" data-state="' . esc_attr( $state ) . '"'
            . ' dir="rtl" aria-labelledby="cpms-pp-enrollment-heading">';
        $html .= '<h1 id="cpms-pp-enrollment-heading">ثبت پرونده در مرکز دیگر</h1>';

        switch ( $state ) {
            case PatientEnrollmentOptions::STATE_ELIGIBLE:
                $html .= self::eligible_markup( $clinics );
                break;
            case PatientEnrollmentOptions::STATE_UNVERIFIED:
                $html .= self::notice_markup(
                    'ثبت آنلاین برای بیماران تأییدنشده فعال نیست',
                    self::unverified_body()
                );
                break;
            case PatientEnrollmentOptions::STATE_NO_DESTINATION:
                $html .= self::notice_markup(
                    'مرکز دیگری برای ثبت در دسترس نیست',
                    self::no_destination_body()
                );
                break;
            default:
                $html .= self::notice_markup(
                    'ثبت آنلاین در حال حاضر در دسترس نیست',
                    self::unavailable_body()
                );
                break;
        }

        return $html . '</section>';
    }

    private static function unverified_body(): string {
        return 'هویت سازمانی شما برای ثبت آنلاین پرونده در مرکز دیگر '
            . 'تأیید نشده است. این وضعیت برای بیماران جدید یا تأییدنشده '
            . 'طبیعی است و به‌معنای نبود دسترسی '
            . 'به پرونده‌های خودتان نیست. '
            . 'برای تشکیل پروندهٔ جدید به پذیرش مرکز مراجعه کنید یا با '
            . 'مطب تماس بگیرید.';
    }

    private static function no_destination_body(): string {
        return 'برای همهٔ مراکز مجاز این حساب که امکان ثبت '
            . 'دارند، پروندهٔ '
            . 'محلی شما وجود دارد یا مرکز دیگری ثبت نشده است. '
            . 'برای نوبت یا '
            . 'تغییر پرونده با پذیرش مطب تماس بگیرید.';
    }

    private static function unavailable_body(): string {
        return 'امکان ثبت آنلاین پرونده در مرکز دیگر برای این '
            . 'حساب در حال '
            . 'حاضر وجود ندارد. برای بررسی وضعیت و تشکیل یا '
            . 'ادامهٔ پرونده با '
            . 'پذیرش مطب تماس بگیرید. هیچ پرونده یا سابقهٔ '
            . 'مرکز دیگری در این '
            . 'صفحه نمایش داده نمی‌شود.';
    }

    /**
     * @param list<array{id:int, name:string}> $clinics
     */
    private static function eligible_markup( array $clinics ): string {
        $intro = 'برای سازمان‌هایی که هویت شما در آن‌ها تأیید شده است، '
            . 'می‌توانید در مرکزی که هنوز پروندهٔ محلی ندارید '
            . 'پروندهٔ تازه '
            . 'بسازید. فقط مراکز مجاز همین حساب نمایش داده می‌شود و هیچ '
            . 'مرکزی به‌صورت خودکار انتخاب نمی‌شود.';

        $html  = '<p class="description">' . esc_html( $intro ) . '</p>';
        $html .= '<form class="cpms-pp-enrollment__form"'
            . ' data-role="patient-enrollment-form" method="post" novalidate'
            . ' aria-describedby="cpms-pp-enrollment-policy">';

        $html .= '<fieldset class="cpms-pp-enrollment__fieldset">';
        $html .= '<legend>اطلاعات پروندهٔ محلی</legend>';

        $html .= '<div class="cpms-pp-enrollment__field">';
        $html .= '<label for="cpms-pp-enrollment-clinic">مرکز مقصد</label>';
        $html .= '<select id="cpms-pp-enrollment-clinic" data-role="enrollment-clinic"'
            . ' required aria-describedby="cpms-pp-enrollment-clinic-hint"'
            . ' aria-required="true">';
        $html .= '<option value="">انتخاب مرکز…</option>';
        foreach ( $clinics as $clinic ) {
            $html .= '<option value="' . esc_attr( (string) $clinic['id'] ) . '">'
                . esc_html( $clinic['name'] ) . '</option>';
        }
        $html .= '</select>';
        $html .= '<p id="cpms-pp-enrollment-clinic-hint" class="description">'
            . 'این فهرست از وضعیت تأییدشدهٔ حساب شما ساخته شده '
            . 'است؛ مرکز را '
            . 'خودتان انتخاب کنید.</p>';
        $html .= '</div>';

        $html .= '<div class="cpms-pp-enrollment__field">';
        $html .= '<label for="cpms-pp-enrollment-first-name">نام</label>';
        $html .= '<input id="cpms-pp-enrollment-first-name" name="first_name"'
            . ' data-role="enrollment-first-name" type="text"'
            . ' autocomplete="given-name" maxlength="120" required'
            . ' aria-required="true">';
        $html .= '</div>';

        $html .= '<div class="cpms-pp-enrollment__field">';
        $html .= '<label for="cpms-pp-enrollment-last-name">نام خانوادگی</label>';
        $html .= '<input id="cpms-pp-enrollment-last-name" name="last_name"'
            . ' data-role="enrollment-last-name" type="text"'
            . ' autocomplete="family-name" maxlength="120" required'
            . ' aria-required="true">';
        $html .= '</div>';

        $html .= '<div class="cpms-pp-enrollment__field">';
        $html .= '<label for="cpms-pp-enrollment-mobile">شمارهٔ موبایل</label>';
        $html .= '<input id="cpms-pp-enrollment-mobile" name="mobile"'
            . ' data-role="enrollment-mobile" type="tel" inputmode="tel"'
            . ' autocomplete="tel" maxlength="16" dir="ltr" required'
            . ' aria-required="true"'
            . ' aria-describedby="cpms-pp-enrollment-mobile-hint">';
        $html .= '<p id="cpms-pp-enrollment-mobile-hint" class="description">'
            . 'شمارهٔ تماس این پروندهٔ محلی؛ برای تأیید هویت '
            . 'استفاده نمی‌شود.</p>';
        $html .= '</div>';

        $html .= '</fieldset>';

        $html .= '<div class="cpms-pp-enrollment__notice'
            . ' cpms-pp-enrollment__notice--info" id="cpms-pp-enrollment-policy">';
        $html .= '<p><strong>این اقدام نوبت ایجاد نمی‌کند.</strong> '
            . 'ثبت پرونده فقط یک پروندهٔ محلی تازه در مرکز '
            . 'انتخاب‌شده می‌سازد. '
            . 'سابقهٔ درمانی، ویزیت‌ها و اطلاعات بالینی مراکز '
            . 'دیگر کپی یا '
            . 'نمایش داده نمی‌شود.</p>';
        $html .= '<p>بررسی نهایی مجاز بودن ثبت، هنگام ارسال درخواست '
            . 'و در سمت '
            . 'سرور انجام می‌شود.</p>';
        $html .= '</div>';

        $html .= '<p class="cpms-pp-enrollment__error" role="alert" tabindex="-1"'
            . ' data-role="enrollment-error" hidden></p>';
        $html .= '<p class="cpms-pp-enrollment__status" role="status"'
            . ' aria-live="polite" data-role="enrollment-status" hidden></p>';

        $html .= '<div class="cpms-pp-enrollment__actions">';
        $html .= '<button type="submit" class="cpms-pp-btn cpms-pp-btn--primary"'
            . ' data-role="enrollment-submit">ثبت پرونده در این مرکز</button>';
        $html .= '</div>';
        $html .= '<noscript><p class="description">برای ثبت آنلاین،'
            . ' جاوااسکریپت مرورگر باید فعال باشد. در غیر این '
            . 'صورت با پذیرش'
            . ' مطب تماس بگیرید.</p></noscript>';
        $html .= '</form>';

        $html .= '<div class="cpms-pp-enrollment__success" role="status"'
            . ' tabindex="-1" data-role="enrollment-success" hidden>';
        $html .= '<h2>پروندهٔ محلی شما ثبت شد</h2>';
        $html .= '<p>ثبت پرونده انجام شد. هیچ نوبتی ایجاد نشده است. برای '
            . 'دیدن پرونده، صفحه را دوباره بارگذاری کنید یا به '
            . 'بخش «پروفایل»'
            . ' بروید.</p>';
        $html .= '<p class="description">شمارهٔ پرونده (MRN):'
            . ' <b dir="ltr" data-role="enrollment-mrn"></b></p>';
        $html .= '<p class="description">اگر این پرونده را نمی‌شناسید، فوراً'
            . ' با پذیرش مطب تماس بگیرید.</p>';
        $html .= '</div>';

        return $html;
    }

    private static function notice_markup( string $title, string $body ): string {
        return '<div class="cpms-pp-enrollment__notice'
            . ' cpms-pp-enrollment__notice--muted"'
            . ' data-role="enrollment-unavailable" role="status">'
            . '<h2>' . esc_html( $title ) . '</h2>'
            . '<p>' . esc_html( $body ) . '</p>'
            . '</div>';
    }

    /**
     * Defensive normalization: the renderer accepts only positive ids and
     * non-empty names, de-duplicates ids and keeps server order.
     *
     * @param mixed $clinics
     * @return list<array{id:int, name:string}>
     */
    private static function sanitize_clinics( $clinics ): array {
        if ( ! is_array( $clinics ) ) {
            return [];
        }

        $out = [];
        foreach ( $clinics as $clinic ) {
            if ( ! is_array( $clinic ) ) {
                continue;
            }
            $id   = (int) ( $clinic['id'] ?? 0 );
            $name = trim( (string) ( $clinic['name'] ?? '' ) );
            if ( $id <= 0 || $name === '' || isset( $out[ $id ] ) ) {
                continue;
            }
            $out[ $id ] = [ 'id' => $id, 'name' => $name ];
        }

        return array_values( $out );
    }
}
