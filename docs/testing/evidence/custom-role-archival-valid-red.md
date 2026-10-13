### Slice 4B focused archival contract (run 38352981951, attempt 1; head ee163c3a4a591da934009f0f60efc8b7f2853797)
```
Installing...
Running as single site... To run multisite, use -c tests/phpunit/multisite.xml
Not running ajax tests. To execute these, use --group ajax.
Not running ms-files tests. To execute these, use --group ms-files.
Not running external-http tests. To execute these, use --group external-http.
PHPUnit 9.6.38 by Sebastian Bergmann and contributors.

FFFFFFFFFFFFFFFFFFFFFF.FF                                         25 / 25 (100%)

Time: 00:01.583, Memory: 44.50 MB

There were 24 failures:

1) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testActiveRoleArchivalPersistsDefinitionCapabilitiesAndReservedKey
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:91

2) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testInactiveRoleCanBeArchivedWithoutReactivation
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:108

3) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testUnauthorizedActorCannotArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Authorization\AuthorizationException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:116

4) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testWordPressAdministratorWithoutClinicMembershipCannotArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Scope\ScopeRequiredException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:125

5) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testForeignClinicDefinitionIsNotAddressable
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Roles\CustomRoleManagementException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:131

6) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testSuspendedActorCannotArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Scope\ScopeRequiredException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:139

7) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testActiveMembershipReferenceRefusesArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Roles\CustomRoleManagementException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:467
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:144

8) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testSuspendedMembershipReferenceRefusesArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Roles\CustomRoleManagementException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:467
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:149

9) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testForeignClinicReferenceDoesNotBlockLocalArchive
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:157

10) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchivedKeyCannotBeRecreated
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:164

11) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchivedRoleSuppliesNoAuthorityAndOverridePrecedenceStaysUnchanged
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:172

12) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchivedRoleIsNotAnAssignmentOptionAndAssignmentIsDenied
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:189

13) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchivedDefinitionRejectsAllManagementMutationPaths
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:198

14) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testRepositoryPrimitivesCannotEditOrReactivateArchivedDefinitions
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:220

15) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testMembershipRoleMutationPrimitivesRefuseArchivedAssignment
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:242

16) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testUnknownStoredStatusIsNeverNormalizedToActiveOrAssignable
unknown persisted status must not be normalized to ACTIVE
Failed asserting that two strings are not identical.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:268

17) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testStaleAndZeroVersionsRefuseArchive
Failed asserting that PHPUnit\Framework\ExpectationFailedException Object (...) is an instance of class "ClinicCore\Application\Roles\CustomRoleManagementException".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:535
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:284

18) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testTerminalRepeatWithCurrentVersionIsNoOpWithoutDuplicateAudit
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:292

19) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testAuditFailureRollsBackArchiveStatusVersionAndCapabilities
Failed asserting that 'slice 4b must implement the authorized archive operation\n
failed asserting that false is true.' contains "audit".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:312

20) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchivalNeverMutatesGlobalRolesOrClinicalRecords
Slice 4B must implement the authorized archive operation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:515
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:447
/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:327

21) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testSafeSuspendedReassignmentPreservesSuspensionOverridesRestoreMetadataAndIdentity
a bounded suspended-member reassignment path is required
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:352

22) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testSuspendedReassignmentCannotBeUsedForActiveTargetsOrUnauthorizedActors
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:376

23) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testArchiveUiHasExplicitActionConsequenceConfirmationAndDedicatedNonce
Failed asserting that '<div class="wrap" dir="rtl"><h1>نقش‌های سفارشی</h1><div class="notice notice-info inline"><p>نقش سفارشی مخصوص همین Clinic است؛ کلید فنی نقش پس از ساخت هرگز تغییر نمی‌کند و فقط نام نمایشی قابل ویرایش است. شما فقط می‌توانید مجوزهایی را بدهید که خودتان در همین Clinic دارید؛ مجوزهای حساس قابل واگذاری نیستند.</p></div><form method="get" action="http://example.org/wp-admin/admin.php" style="margin:12px 0"><input type="hidden" name="page" value="cpms-custom-roles"><label for="cpms-custom-role-clinic">Clinic</label> <select name="cpms_clinic_id" id="cpms-custom-role-clinic"><option value="50"  selected='selected'>Lifecycle test crlA-2ff67dbb (#50)</option></select> <button type="submit" class="button">نمایش</button></form><h2 class="title">نقش‌های سفارشی این Clinic</h2><table class="widefat striped"><thead><tr><th scope="col">نام نمایشی</th><th scope="col">کلید فنی (تغییرناپذیر)</th><th scope="col">وضعیت</th><th scope="col">نسخه</th><th scope="col">مجوزها</th><th scope="col">وضعیت نقش</th></tr></thead><tbody><tr><td>Lifecycle archive_ui</td><td><code dir="ltr">archive_ui</code></td><td>فعال</td><td>1</td><td>cpms_search</td><td><p class="description">با غیرفعال‌سازی، این تعریف و تخصیص‌های موجود حفظ می‌شوند اما نقش دیگر هیچ اختیار مشتق‌شده‌ای نمی‌دهد؛ GRANT/DENY عضویت تغییر نمی‌کند.</p><form method="post" action="http://example.org/wp-admin/admin-post.php"><input type="hidden" name="action" value="cpms_custom_role_deactivate"><input type="hidden" name="cpms_clinic_id" value="50"><input type="hidden" name="role_key" value="archive_ui"><input type="hidden" name="version" value="1"><input type="hidden" id="_wpnonce" name="_wpnonce" value="bffc793f80" /><input type="hidden" name="_wp_http_referer" value="" /><button type="submit" class="button" onclick="return confirm(&quot;\u0627\u06cc\u0646 \u0646\u0642\u0634 \u063a\u06cc\u0631\u0641\u0639\u0627\u0644 \u0645\u06cc\u200c\u0634\u0648\u062f\u061b \u062a\u0639\u0631\u06cc\u0641\u060c \u062a\u062e\u0635\u06cc\u0635\u200c\u0647\u0627 \u0648 GRANT\/DENY \u0639\u0636\u0648\u06cc\u062a \u062d\u0630\u0641 \u0646\u0645\u06cc\u200c\u0634\u0648\u0646\u062f. \u0627\u062f\u0627\u0645\u0647 \u0645\u06cc\u200c\u062f\u0647\u06cc\u062f\u061f&quot;);">غیرفعال‌سازی</button></form></td></tr></tbody></table><h2 class="title">تعریف نقش سفارشی جدید</h2><form method="post" action="http://example.org/wp-admin/admin-post.php"><input type="hidden" name="action" value="cpms_custom_role_create"><input type="hidden" name="cpms_clinic_id" value="50"><input type="hidden" id="_wpnonce" name="_wpnonce" value="8d18a8e54f" /><input type="hidden" name="_wp_http_referer" value="" /><table class="form-table" role="presentation"><tr><th scope="row"><label for="cpms-custom-role-key">کلید فنی نقش</label></th><td><input name="role_key" id="cpms-custom-role-key" type="text" dir="ltr" required pattern="[a-z][a-z0-9_]{1,63}" value="" class="regular-text"><p class="description">حروف کوچک لاتین، رقم و زیرخط؛ با حرف شروع می‌شود (۲ تا ۶۴ کاراکتر). این کلید پس از ساخت تغییر نمی‌کند و با نقش‌های داخلی CPMS تداخل ندارد.</p></td></tr><tr><th scope="row"><label for="cpms-custom-role-name">نام نمایشی</label></th><td><input name="display_name" id="cpms-custom-role-name" type="text" required maxlength="120" value="" class="regular-text"><p class="description">هر زبانی مجاز است (فارسی، لاتین، عربی، CJK و …). حداکثر ۱۲۰ کاراکتر.</p></td></tr></table><fieldset><legend>مجوزهای قابل واگذاری (بر پایه اختیار مؤثر شما در این Clinic)</legend><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 16px"><label><input type="checkbox" name="capabilities[]" value="cpms_patient_read" > <code dir="ltr">cpms_patient_read</code></label><label><input type="checkbox" name="capabilities[]" value="cpms_report_read" > <code dir="ltr">cpms_report_read</code></label><label><input type="checkbox" name="capabilities[]" value="cpms_search" > <code dir="ltr">cpms_search</code></label></div></fieldset><p><button type="submit" class="button button-primary">ساخت نقش سفارشی</button></p></form><h2 class="title">Lifecycle archive_ui <code dir="ltr">archive_ui</code></h2><form method="post" action="http://example.org/wp-admin/admin-post.php" style="margin-bottom:10px"><input type="hidden" name="action" value="cpms_custom_role_rename"><input type="hidden" name="cpms_clinic_id" value="50"><input type="hidden" name="role_key" value="archive_ui"><input type="hidden" id="_wpnonce" name="_wpnonce" value="4bf9dfd679" /><input type="hidden" name="_wp_http_referer" value="" /><label for="cpms-rename-archive_ui">ویرایش نام نمایشی</label> <input name="display_name" id="cpms-rename-archive_ui" type="text" required maxlength="120" value="Lifecycle archive_ui" class="regular-text"> <button type="submit" class="button">ذخیره نام</button></form><form method="post" action="http://example.org/wp-admin/admin-post.php"><input type="hidden" name="action" value="cpms_custom_role_caps"><input type="hidden" name="cpms_clinic_id" value="50"><input type="hidden" name="role_key" value="archive_ui"><input type="hidden" id="_wpnonce" name="_wpnonce" value="d1f426a764" /><input type="hidden" name="_wp_http_referer" value="" /><fieldset><legend>مجوزهای قابل واگذاری (بر پایه اختیار مؤثر شما در این Clinic)</legend><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 16px"><label><input type="checkbox" name="capabilities[]" value="cpms_patient_read" > <code dir="ltr">cpms_patient_read</code></label><label><input type="checkbox" name="capabilities[]" value="cpms_report_read" > <code dir="ltr">cpms_report_read</code></label><label><input type="checkbox" name="capabilities[]" value="cpms_search"  checked='checked'> <code dir="ltr">cpms_search</code></label></div></fieldset><p><button type="submit" class="button button-secondary">ذخیره مجوزهای مجاز</button></p></form></div>' contains "cpms_custom_role_archive".

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:404

24) ClinicCore\Tests\Integration\CustomRoleArchivalTest::testInvalidArchiveNonceStopsBeforeMutation
Failed asserting that false is true.

/home/runner/work/doctor/doctor/clinic-practice-management/tests/Integration/CustomRoleArchivalTest.php:422

FAILURES!
Tests: 25, Assertions: 199, Failures: 24.
```

