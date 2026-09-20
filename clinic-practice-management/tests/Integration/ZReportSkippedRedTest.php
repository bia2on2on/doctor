<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use WP_UnitTestCase;
final class ZReportSkippedRedTest extends WP_UnitTestCase {
    public function testReportSkipped() : void {
        $log = @file_get_contents('/tmp/phpunit-int.log');
        $junit = @file_get_contents('/tmp/phpunit-int-junit.xml');
        $skippedCases = [];
        if ($junit !== false) {
            if (preg_match_all('/<testcase[^>]*>.*?<skipped[^>]*>.*?<\/skipped>.*?<\/testcase>/s', $junit, $m)) {
                $skippedCases = $m[0];
            } elseif (preg_match_all('/<testcase[^>]*skipped[^>]*>/', $junit, $m2)) {
                $skippedCases = $m2[0];
            }
            // also try testcase with skipped attribute
            if (preg_match_all('/<testcase\b[^>]*\bskipped\b[^>]*>/i', $junit, $m3)) {
                $skippedCases = array_merge($skippedCases, $m3[0]);
            }
        }
        $logSnippet = $log !== false ? substr($log, -3000) : 'no log';
        $junitSnippet = $junit !== false ? substr($junit, 0, 5000) : 'no junit';
        $msg = "SKIPPED_REPORT: junit_skipped_count=" . count($skippedCases) . "\n";
        foreach ($skippedCases as $i => $c) {
            $msg .= "SKIPPED_CASE_" . $i . ": " . substr($c, 0, 800) . "\n";
        }
        $msg .= "LOG_TAIL:\n" . $logSnippet . "\n";
        $msg .= "JUNIT_HEAD:\n" . $junitSnippet . "\n";
        // Always fail so it appears in Post failures comment
        self::fail($msg);
    }
}
