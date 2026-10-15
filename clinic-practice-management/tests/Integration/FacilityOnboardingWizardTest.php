<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;
/** Unified adaptive Persian onboarding: facility choice is presentation only. */
final class FacilityOnboardingWizardTest extends WP_UnitTestCase {
 protected function setUp(): void { parent::setUp(); App::migrations()->migrate(); Settings::flushCache(); App::resetScope(); ScopeContext::clear(); }
 protected function tearDown(): void { Settings::flushCache(); ScopeContext::clear(); App::resetScope(); wp_set_current_user(0); parent::tearDown(); }
 private function authorize(): int {
  $id = self::factory()->user->create(['role'=>'administrator']); wp_set_current_user($id);
  $clinicId = 1; try { $clinicId = App::scope()->clinicId; } catch (\Throwable) {}
  if ($clinicId>0) cpms_test_seed_membership($id,$clinicId,'cpms_manager');
  try { App::replaceExplicitScope(App::scope()); } catch (\Throwable) {}
  return $id;
 }
 public function testFacilityIsFirstWizardStepAndPersists(): void {
  $uid=$this->authorize();
  self::assertSame('facility', CpmsSetupWizard::steps()[0]['id']);
  $err=CpmsSetupWizard::saveFacility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid);
  self::assertSame('',$err);
  self::assertSame('doctor_practice',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('doctor_practice', CpmsSetupWizard::selected_facility_type(), 'selection must be resumable');
 }
 public function testInvalidFacilityRejectedWithoutWrite(): void {
  $uid=$this->authorize();
  $err=CpmsSetupWizard::saveFacility(App::settings(),['facility_type'=>'forged'],$uid);
  self::assertNotSame('',$err);
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''));
 }
 public function testFacilityStepRendersAllPersianTypesRtlAndAdaptiveNotices(): void {
  $uid=$this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','medical_building');
  $prev=$_GET; $_GET['step']='facility'; ob_start();
  try { CpmsSetupWizard::render(); $html=(string)ob_get_contents(); } finally { ob_end_clean(); $_GET=$prev; }
  self::assertStringContainsString('چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟',$html);
  self::assertStringContainsString('dir="rtl"',$html);
  foreach (FacilityTypeCatalog::all() as $t) self::assertStringContainsString($t['label'],$html);
  self::assertStringContainsString('data-cpms-multi-clinic-blocked',$html);
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''),'GET render must not persist facility choice');
 }
 public function testHospitalFlowDisclosesInpatientUnsupported(): void {
  self::assertFalse(FacilityTypeCatalog::flowSupportsInpatient('hospital_network'));
  self::assertContains('درمانگاه‌های سرپایی', FacilityTypeCatalog::flowSteps('hospital_network'));
 }
}
