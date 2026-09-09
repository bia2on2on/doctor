<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

/**
 * Real-Table Migration Lifecycle — Phase 2 (زیرساخت تست، نه production).
 *
 * مشکل اثبات‌شده (runs 34291741398 / 34305395185 — بخش FK EVIDENCE کامنت PR):
 *
 *  WP_UnitTestCase_Base::start_transaction() دو فیلتر روی 'query' ثبت می‌کند
 *  (abstract-testcase.php در WP 6.7.2 — از سورس همان تگ verify شد):
 *
 *      add_filter( 'query', array( $this, '_create_temporary_tables' ) );  // priority 10
 *      add_filter( 'query', array( $this, '_drop_temporary_tables' ) );    // priority 10
 *
 *  این فیلترها هر CREATE/DROP TABLE را به TEMPORARY تبدیل می‌کنند و InnoDB
 *  روی جدول موتی FOREIGN KEY نمی‌پذیرد (errno 150). به‌علاوه (evidence همان
 *  runها) MySQL در CREATE TEMPORARY TABLE IF NOT EXISTS حتی وقتی جدولِ واقعیِ
 *  هم‌نام وجود دارد، ساختِ سایهٔ موقت را «تلاش» می‌کند — پس هر Migration
 *  lifecycle واقعی (rollback → re-migrate) که جدول دارای FK می‌سازد، درون
 *  تست‌های WP شکست می‌خورد، در حالی که همان Migrationها در نصب واقعی سبزند
 *  (Real-WP Acceptance ×2 روی 5379860/d443e3b).
 *
 * راه‌حل (کمترین blast radius — گزینهٔ A مصوب مالک): فقط حین عملیاتِ
 * migration lifecycle، همان دو callback را با همان identity دقیق remove
 * می‌کنیم و در finally دقیقاً به حالت قبل برمی‌گردانیم (متدها public در
 * 6.7.2؛ remove_filter/add_filter با آرایهٔ [object, method] همان نمونه،
 * مطمئن). خارج از این پنجره، رفتار temporary-table وردپرس برای بقیهٔ
 * Integration tests دست‌نخورده می‌ماند (اثبات: TempTableIsolationTest).
 *
 * این trait فقط زیرساخت تست است؛ production code و Migrationها تغییر نمی‌کنند.
 */
trait RealTableMigrations
{
    /**
     * null = تعلیقی فعال نیست؛ true/false = وضعیت ثبتِ فیلترها «پیش از» تعلیق.
     */
    private ?bool $wpTempTableRewritesWereActive = null;

    /**
     * اجرای $fn با فیلترهای temporary-table معلق — بازگشت دقیق به حالت قبل در
     * finally (حتی اگر $fn exception باندازد).
     *
     * @param callable():mixed $fn
     *
     * @return mixed نتیجهٔ $fn
     */
    private function withRealTables(callable $fn)
    {
        $this->suspendWpTempTableRewrites();

        try {
            return $fn();
        } finally {
            $this->restoreWpTempTableRewrites();
        }
    }

    /**
     * تعلیق rewriteهای temporary-table وردپرس (فقط همین نمونهٔ تست).
     */
    private function suspendWpTempTableRewrites(): void
    {
        if ($this->wpTempTableRewritesWereActive !== null) {
            return; // از قبل معلق است
        }

        $create = [$this, '_create_temporary_tables'];
        $drop = [$this, '_drop_temporary_tables'];

        // فقط همان چیزی که start_transaction() ثبت کرده برداشته می‌شود؛
        // اگر اصلاً ثبت نشده بود (transactional tests خاموش)، چیزی اضافه نمی‌شود.
        $this->wpTempTableRewritesWereActive = has_filter('query', $create) !== false
            && has_filter('query', $drop) !== false;

        remove_filter('query', $create);
        remove_filter('query', $drop);
    }

    /**
     * بازگردانی دقیق به حالت پیش از تعلیق.
     */
    private function restoreWpTempTableRewrites(): void
    {
        if ($this->wpTempTableRewritesWereActive === null) {
            return; // تعلیقی نبوده است
        }

        if ($this->wpTempTableRewritesWereActive) {
            // همان شکل ثبتِ خود WP (priority پیش‌فرض 10). tear_down خودِ WP هم
            // این دو فیلتر را remove می‌کند؛ remove روی حالت غایب no-op است.
            add_filter('query', [$this, '_create_temporary_tables']);
            add_filter('query', [$this, '_drop_temporary_tables']);
        }

        $this->wpTempTableRewritesWereActive = null;
    }
}
