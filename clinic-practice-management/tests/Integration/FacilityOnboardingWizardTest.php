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
  $err=CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid);
  self::assertSame('',$err);
  self::assertSame('doctor_practice',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('doctor_practice', CpmsSetupWizard::selected_facility_type(), 'selection must be resumable');
 }
 public function testInvalidFacilityRejectedWithoutWrite(): void {
  $uid=$this->authorize();
  $err=CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'forged'],$uid);
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
 public function testActiveStepsActuallyDifferByFacility(): void {
  $uid=$this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','doctor_practice'); update_user_meta($uid,'cpms_onboarding_structure','single');
  $simple=array_column(CpmsSetupWizard::active_steps(),'id');
  self::assertContains('locations',$simple); self::assertNotContains('health',$simple);
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','hospital_network');
  $hospital=array_column(CpmsSetupWizard::active_steps(),'id');
  self::assertContains('health',$hospital);
  self::assertNotSame($simple,$hospital);
 }
 public function testMultiDoctorRequiresIntendedClinicianCountVerifiedAgainstActual(): void {
  $uid=$this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','general_clinic'); update_user_meta($uid,'cpms_onboarding_structure','multi_doctor');
  $err=CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid);
  self::assertNotSame('',$err,'multi-doctor flow must reject an intended count below two');
  $err=CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'2'],$uid);
  self::assertSame('',$err);
  global $wpdb; $clinicId=App::scope()->clinicId;
  $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_clinicians WHERE clinic_id='.(int)$clinicId); $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک یک']);
  self::assertFalse(CpmsSetupWizard::step_is_complete('doctors', App::settings()), 'one actual clinician must not satisfy an intended count of two');
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک دو']);
  self::assertTrue(CpmsSetupWizard::step_is_complete('doctors', App::settings()));
 }
 public function testLocationsStepVerifiesActualLocationWithTimezone(): void {
  $this->authorize(); $clinicId=App::scope()->clinicId;
  global $wpdb; $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_locations WHERE clinic_id='.(int)$clinicId); $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
  self::assertFalse(CpmsSetupWizard::step_is_complete('locations', App::settings()));
  App::locationRepository()->create($clinicId,'مکان اصلی','main-'.wp_generate_password(6,false,false),'Asia/Tehran', gmdate('Y-m-d H:i:s'));
  self::assertTrue(CpmsSetupWizard::step_is_complete('locations', App::settings()));
 }
 public function testFinishBlockedForMedicalBuildingAndMissingRequiredReported(): void {
  $uid=$this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','other'); update_user_meta($uid,'cpms_onboarding_structure','medical_building');
  global $wpdb; $wpdb->query($wpdb->prepare('UPDATE '.$wpdb->prefix.'cpms_clinics SET name = "" WHERE id = %d', App::scope()->clinicId));
  $missing=CpmsSetupWizard::missing_required_steps(App::settings());
  self::assertNotSame([], $missing);
  $ref=new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  $err=$ref->invoke(null, App::settings(), $uid);
  self::assertNotSame('', $err);
  self::assertFalse((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
 }
 public function testSimplePracticeCanCompleteWithRealSavedConfiguration(): void {
  $uid=$this->authorize(); $clinicId=App::scope()->clinicId; global $wpdb;
  update_user_meta($uid,'cpms_onboarding_facility_type','doctor_practice'); update_user_meta($uid,'cpms_onboarding_structure','single');
  CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid);
  CpmsSetupWizard::saveClinic(App::settings(),['clinic_name'=>'مطب کامل'],$uid);
  $locs=App::locationRepository()->listForClinic($clinicId);
  if ($locs===[]) { App::locationRepository()->create($clinicId,'مکان اصلی','main-'.wp_generate_password(6,false,false),'Asia/Tehran', gmdate('Y-m-d H:i:s')); $locs=App::locationRepository()->listForClinic($clinicId); }
  $locationId=(int)$locs[0]['id'];
  $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_clinicians WHERE clinic_id='.(int)$clinicId); $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک کامل']);
  $clinicians=App::clinicianRepository()->listAll($clinicId,false); $clinicianId=(int)$clinicians[0]['id'];
  $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$wpdb->prefix.'cpms_schedule (clinic_id,clinician_id,location_id,day_of_week,start_time,end_time,is_active,created_at,updated_at) VALUES (%d,%d,%d,1,%s,%s,1,NOW(),NOW())',$clinicId,$clinicianId,$locationId,'09:00:00','12:00:00'));
  CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid);
  CpmsSetupWizard::saveBooking(App::settings(),['duration'=>'20','capacity'=>'1','lead'=>'2','future'=>'60','cancel'=>'24'],$uid);
  CpmsSetupWizard::mark_step_completed(App::settings(),'booking',$uid);
  CpmsSetupWizard::mark_step_completed(App::settings(),'schedules',$uid);
  CpmsSetupWizard::mark_step_completed(App::settings(),'review',$uid);
  $ref=new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  $err=$ref->invoke(null, App::settings(), $uid);
  self::assertSame('', $err);
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
 }
 public function testHospitalFlowDisclosesInpatientUnsupported(): void {
  self::assertFalse(FacilityTypeCatalog::flow_supports_inpatient('hospital_network'));
  self::assertContains('درمانگاه‌های سرپایی', FacilityTypeCatalog::flow_steps('hospital_network'));
 }
}
