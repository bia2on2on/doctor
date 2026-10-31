<?php

declare(strict_types=1);

namespace ClinicCore\Admin;

/**
 * UI helpers اسکوپ‌شدهٔ صفحات CPMS (Chunk F) — بدون logic business.
 *
 * فقط تولید markup با کلاس‌های طراحی سیستم (`cpms-empty`, `cpms-role-preset`, …) است؛
 * هیچ داده‌ای را نمی‌خواند و هیچ کوئری/جانبی ندارد.
 */
final class CpmsUi
{
    /**
     * Empty state حرفه‌ای با next action واضح (برای «بدون پزشک/برنامه/بکاپ/کاربر/گزارش»).
     *
     * @param string $icon        یکی از نام‌های «کوتاه» (🗂، 📅، 🗄، 👥، 📊، ✉️، 🩺).
     * @param string $title       عنوان کوتاه.
     * @param string $desc        توضیح فارسی + پیشنهاد گام بعدی (بدون HTML خام).
     * @param string $actionLabel برچسب دکمهٔ گام بعدی (خالی = بدون دکمه).
     * @param string $actionUrl   آدرس گام بعدی (خروجی esc_url می‌شود).
     */
    public static function emptyState(string $icon, string $title, string $desc, string $actionLabel = '', string $actionUrl = ''): string
    {
        $action = '';
        if ($actionLabel !== '' && $actionUrl !== '') {
            $action = '<div class="cpms-empty-action"><a class="button button-primary" href="' . esc_url($actionUrl) . '">' . esc_html($actionLabel) . '</a></div>';
        }

        return '<div class="cpms-empty">'
            . '<div class="cpms-empty-icon" aria-hidden="true">' . esc_html($icon) . '</div>'
            . '<div class="cpms-empty-title">' . esc_html($title) . '</div>'
            . '<div class="cpms-empty-desc">' . esc_html($desc) . '</div>'
            . $action
            . '</div>';
    }

    /**
     * Format a stored UTC datetime as a Persian/Jalali date + time string.
     *
     * Presentation only — the stored UTC instant is never rewritten and no
     * Gregorian comparison is involved. When no explicit timezone is supplied
     * the WordPress site timezone is used (system/backup/job logs are
     * site-wide, not clinic-scoped). Falls back to the raw string when the
     * input is not a parseable UTC datetime.
     */
    public static function jalaliDateTime(string $utc_datetime, string $timezone = ''): string
    {
        $raw = trim($utc_datetime);
        if ($raw === '') {
            return '';
        }
        try {
            if ($timezone !== '') {
                $tz = new \DateTimeZone($timezone);
            } elseif (function_exists('wp_timezone') && wp_timezone() instanceof \DateTimeZone) {
                $tz = wp_timezone();
            } else {
                $tz = new \DateTimeZone('UTC');
            }
            $utc = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
            if (false === $utc) {
                return $raw;
            }
            $local = $utc->setTimezone($tz);
            return \ClinicCore\Domain\Time\Jalali::formatYmd($local->format('Y-m-d')) . ' ' . $local->format('H:i');
        } catch (\Throwable $e) {
            return $raw;
        }
    }
}
