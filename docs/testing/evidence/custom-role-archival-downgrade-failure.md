### Slice 4B schema/downgrade evidence (run 38356935641, head bd4c1b68b18f9647f39e48b8fc78fec71397054c)
```
Installing...
Running as single site... To run multisite, use -c tests/phpunit/multisite.xml
Not running ajax tests. To execute these, use --group ajax.
Not running ms-files tests. To execute these, use --group ms-files.
Not running external-http tests. To execute these, use --group external-http.
PHPUnit 9.6.38 by Sebastian Bergmann and contributors.

.ARCHIVAL_DOWNGRADE_FAILURE_EVIDENCE {"causes":["migration 0027: unsafe rollback refused; definitions and archival state must be preserved: migration 0027: unexpected rollback guard; no constraint may be silently reused or removed","migration 0027: unexpected rollback guard; no constraint may be silently reused or removed"],"status_column":{"Field":"status","Type":"enum('active','inactive','archived')","Collation":"utf8mb4_unicode_ci","Null":"NO","Key":"","Default":"active","Extra":"","Privileges":"select,insert,update,references","Comment":""},"constraints":[{"CONSTRAINT_NAME":"PRIMARY","ENFORCED":"YES","CHECK_CLAUSE":null},{"CONSTRAINT_NAME":"u_custom_role","ENFORCED":"YES","CHECK_CLAUSE":null},{"CONSTRAINT_NAME":"fk_customrole_clinic","ENFORCED":"YES","CHECK_CLAUSE":null},{"CONSTRAINT_NAME":"cpms_archive_rollback_fda565f82a24","ENFORCED":"YES","CHECK_CLAUSE":"(`status` in (_utf8mb4\\'active\\',_utf8mb4\\'inactive\\'))"}]}
EWordPress database error Check constraint 'cpms_archive_rollback_fda565f82a24' is violated. for query UPDATE wptests_cpms_custom_role_defs SET status = 'archived' WHERE id = 31 made by include('/home/runner/work/doctor/doctor/clinic-practice-management/vendor/phpunit/phpunit/phpunit'), PHPUnit\TextUI\Command::main, PHPUnit\TextUI\Command->run, PHPUnit\TextUI\TestRunner->run, PHPUnit\Framework\TestSuite->run, PHPUnit\Framework\TestCase->run, PHPUnit\Framework\TestResult->run, PHPUnit\Framework\TestCase->runBare, PHPUnit\Framework\TestCase->runTest, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->testArchivedRowsRefuseRollbackAndRetainVersionSchemaAndCapabilities, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->assertUnsafeRollback, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->withRealTables, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->ClinicCore\Tests\Integration\{closure}, ClinicCore\Infrastructure\Db\CpmsDb->execute
F<div id="error"><p class="wpdberror"><strong>WordPress database error:</strong> [Check constraint &#039;cpms_archive_rollback_fda565f82a24&#039; is violated.]<br /><code>UPDATE wptests_cpms_custom_role_defs SET status = &#039;archived&#039; WHERE id = 31</code></p></div>WordPress database error Check constraint 'cpms_archive_rollback_fda565f82a24' is violated. for query UPDATE wptests_cpms_custom_role_defs SET status = 'archived' WHERE id = 32 made by include('/home/runner/work/doctor/doctor/clinic-practice-management/vendor/phpunit/phpunit/phpunit'), PHPUnit\TextUI\Command::main, PHPUnit\TextUI\Command->run, PHPUnit\TextUI\TestRunner->run, PHPUnit\Framework\TestSuite->run, PHPUnit\Framework\TestCase->run, PHPUnit\Framework\TestResult->run, PHPUnit\Framework\TestCase->runBare, PHPUnit\Framework\TestCase->runTest, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->testNonStrictSqlCannotSilentlyConvertArchivedDefinitionsDuringRollback, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->assertUnsafeRollback, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->withRealTables, ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest->ClinicCore\Tests\Integration\{closure}, ClinicCore\Infrastructure\Db\CpmsDb->execute
F                                                                4 / 4 (100%)<div id="error"><p class="wpdberror"><strong>WordPress database error:</strong> [Check constraint &#039;cpms_archive_rollback_fda565f82a24&#039; is violated.]<br /><code>UPDATE wptests_cpms_custom_role_defs SET status = &#039;archived&#039; WHERE id = 32</code></p></div>

Time: 00:00.141, Memory: 42.50 MB

There was 1 error:

1) ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest::testUpgradeFrom0026PreservesRowsColumnAttributesAndEveryIndex
RuntimeException: migration 0027: unsafe rollback refused; definitions and archival state must be preserved: migration 0027: unexpected rollback guard; no constraint may be silently reused or removed

/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/2026_10_13_0027_custom_role_archival.php:119
/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/MigrationRunner.php:140
/home/runner/work/doctor/doctor/clinic-practice-management/src/Infrastructure/Db/CpmsDb.php:239
/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/MigrationRunner.php:139
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:57
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/RealTableMigrations.php:55
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:48

Caused by
RuntimeException: migration 0027: unexpected rollback guard; no constraint may be silently reused or removed

/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/2026_10_13_0027_custom_role_archival.php:58
/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/2026_10_13_0027_custom_role_archival.php:108
/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/MigrationRunner.php:140
/home/runner/work/doctor/doctor/clinic-practice-management/src/Infrastructure/Db/CpmsDb.php:239
/home/runner/work/doctor/doctor/clinic-practice-management/src/Migrations/MigrationRunner.php:139
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:57
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/RealTableMigrations.php:55
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:48

--

There were 2 failures:

1) ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest::testArchivedRowsRefuseRollbackAndRetainVersionSchemaAndCapabilities
Failed asserting that 0 is identical to 1.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:108
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/RealTableMigrations.php:55
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:103
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:93

2) ClinicCore\Tests\Integration\CustomRoleArchivalMigrationTest::testNonStrictSqlCannotSilentlyConvertArchivedDefinitionsDuringRollback
Failed asserting that 0 is identical to 1.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:108
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/RealTableMigrations.php:55
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:103
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalMigrationTest.php:98

ERRORS!
Tests: 4, Assertions: 10, Errors: 1, Failures: 2.
```

