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
}
