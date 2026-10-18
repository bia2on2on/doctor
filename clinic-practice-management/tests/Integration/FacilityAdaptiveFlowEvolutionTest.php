<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Bootstrap\App;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;
/**
 * Adaptive facility-type onboarding slice (12 supported types).
 *
 * Contracts pinned here:
 *  - all 12 approved facility types keep adaptive Persian presentation profiles;
 *  - adaptive labels render RTL inside the real wizard without persisting on GET;
 *  - hospital stays outpatient-only; medical building stays fail-closed and never
 *    guesses tenant scope (provisioning lives behind Organization Manager authority);
 *  - the stored structure is resumable for every authorized manager of the Clinic;
 *  - safe configuration evolution: same-flow facility changes are allowed, cross-flow
 *    changes after completion are refused BEFORE any write, and no facility save ever
 *    touches tenant rows (Clinic/Location/Membership/Clinician);
 *  - authorization: the pre-bootstrap selection requires manage_options + valid nonce;
 *  - rollback: invalid facility/structure input leaves zero partial state.
 */
final class FacilityAdaptiveFlowEvolutionTest extends WP_UnitTestCase {
 protected function setUp(): void { parent::setUp(); App::migrations()->migrate(); Settings::flushCache(); App::resetScope(); ScopeContext::clear(); }
 protected function tearDown(): void { Settings::flushCache(); ScopeContext::clear(); App::resetScope(); wp_set_current_user(0); parent::tearDown(); }
 private function authorize(): int {
  $id = self::factory()->user->create(['role'=>'administrator']); wp_set_current_user($id);
  $clinicId = 1; try { $clinicId = App::scope()->clinicId; } catch (\Throwable) {}
  if ($clinicId>0) cpms_test_seed_membership($id,$clinicId,'cpms_manager');
  try { App::replaceExplicitScope(App::scope()); } catch (\Throwable) {}
  return $id;
 }
 private function tenantSnapshot(): array {
  global $wpdb; $p = $wpdb->prefix;
  return [
   'clinics'     => (int) $wpdb->get_var('SELECT COUNT(*) FROM '.$p.'cpms_clinics'),
   'locations'   => (int) $wpdb->get_var('SELECT COUNT(*) FROM '.$p.'cpms_locations'),
   'memberships' => (int) $wpdb->get_var('SELECT COUNT(*) FROM '.$p.'cpms_clinic_memberships'),
   'clinicians'  => (int) $wpdb->get_var('SELECT COUNT(*) FROM '.$p.'cpms_clinicians'),
  ];
 }
 private function renderStep(string $step): string {
  $prev = $_GET; $_GET['step'] = $step; ob_start();
  try { CpmsSetupWizard::render(); return (string) ob_get_contents(); } finally { ob_end_clean(); $_GET = $prev; }
 }
 public function testAllTwelveTypesKeepAdaptiveProfilesWithoutInpatientOrMultiClinicClaims(): void {
  self::assertCount(12, FacilityTypeCatalog::all(), 'the 12 approved facility types must be preserved');
  foreach (FacilityTypeCatalog::all() as $t) {
   $profile = FacilityTypeCatalog::type_profile((string) $t['id']);
   foreach (['unit','clinician','clinicians','name_label'] as $key) {
    self::assertNotSame('', trim((string) $profile[$key]), 'profile '.$key.' for '.$t['id']);
   }
   self::assertFalse(FacilityTypeCatalog::flow_supports_inpatient(FacilityTypeCatalog::resolve_flow((string) $t['id'])), 'no flow may imply inpatient/HIS support: '.$t['id']);
  }
  self::assertSame('دندانپزشک', FacilityTypeCatalog::type_profile('dental_clinic')['clinician']);
  self::assertSame('مطب', FacilityTypeCatalog::type_profile('doctor_practice')['unit']);
  self::assertSame('بیمارستان', FacilityTypeCatalog::type_profile('hospital')['unit']);
  self::assertStringContainsString('سرپایی', FacilityTypeCatalog::type_profile('hospital')['name_label'], 'hospital identity label must stay outpatient-scoped');
  $generic = FacilityTypeCatalog::type_profile('');
  self::assertSame('نام کلینیک', $generic['name_label'], 'legacy installs without a selection keep the previous wording');
  self::assertSame($generic, FacilityTypeCatalog::type_profile('forged_type'));
  self::assertFalse(FacilityTypeCatalog::supports_multi_clinic_provisioning());
 }
 public function testAdaptivePersianLabelsRenderRtlForDentalAndDoctorPractice(): void {
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','dental_clinic'); update_user_meta($uid,'cpms_onboarding_structure','multi_doctor');
  $html = $this->renderStep('doctors');
  self::assertStringContainsString('dir="rtl"',$html);
  self::assertStringContainsString('تعداد دندانپزشکان موردنظر',$html);
  self::assertStringContainsString('دندانپزشکان فعال',$html);
  update_user_meta($uid,'cpms_onboarding_facility_type','doctor_practice'); update_user_meta($uid,'cpms_onboarding_structure','single');
  $html = $this->renderStep('clinic');
  self::assertStringContainsString('نام مطب',$html);
  self::assertStringContainsString('مشخصات مطب',$html);
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''),'GET render must not persist the facility choice');
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''),'GET render must not persist the structure choice');
 }
 public function testHospitalFlowRenderStaysOutpatientOnly(): void {
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','hospital_network');
  $html = $this->renderStep('facility');
  self::assertStringContainsString('data-cpms-inpatient-unsupported',$html);
  self::assertStringContainsString('سرپایی',$html);
  self::assertStringNotContainsString('بخش بستری پشتیبانی می‌شود',$html);
 }
 public function testStoredStructureIsResumableForAnotherAuthorizedAdminWithoutWrites(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'hospital_network'],$uid));
  self::assertSame('hospital_network',(string)App::settings()->get('setup.facility_structure'));
  self::assertContains('health', array_column(CpmsSetupWizard::active_steps(),'id'), 'the selecting admin sees the stored adaptive flow');
  $other = self::factory()->user->create(['role'=>'administrator']);
  wp_set_current_user($other);
  $steps = array_column(CpmsSetupWizard::active_steps(),'id');
  self::assertContains('health',$steps,'stored structure must drive the same adaptive flow for every authorized manager');
  self::assertContains('clinic',$steps);
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_facility_type',true),'reading the flow must never write user-meta for the other admin');
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_structure',true));
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'));
 }
 public function testSameFlowFacilityEvolutionAfterCompletionKeepsCompletionAndTenantRows(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>''],$uid));
  App::settings()->set(CpmsSetupWizard::COMPLETE_KEY,true,$uid);
  $before = $this->tenantSnapshot();
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'dental_clinic','structure'=>'multi_doctor'],$uid);
  self::assertSame('',$err,'same-flow evolution (multi_doctor_clinic) must stay allowed after completion');
  self::assertSame('dental_clinic',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('multi_doctor',(string)App::settings()->get('setup.facility_structure'));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame($before,$this->tenantSnapshot(),'facility evolution must never touch tenant rows');
 }
 public function testCrossFlowEvolutionAfterCompletionIsRefusedWithoutAnyWrite(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid));
  App::settings()->set(CpmsSetupWizard::COMPLETE_KEY,true,$uid);
  $before = $this->tenantSnapshot();
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid);
  self::assertNotSame('',$err,'cross-flow change after completion must be refused');
  self::assertStringContainsString('بازنشانی',$err,'refusal must point to the explicit non-destructive restart path');
  self::assertSame('doctor_practice',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('single',(string)App::settings()->get('setup.facility_structure'));
  self::assertSame('single',(string) get_user_meta($uid,'cpms_onboarding_structure',true));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false),'completion marker must survive a refused transition');
  self::assertSame($before,$this->tenantSnapshot());
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'medical_building'],$uid);
  self::assertNotSame('',$err,'structure override into a different flow is refused after completion too');
  self::assertSame('single',(string)App::settings()->get('setup.facility_structure'));
  self::assertSame($before,$this->tenantSnapshot());
 }
 public function testInvalidFacilityOrStructureIsRejectedWithNoPartialWrite(): void {
  $uid = $this->authorize();
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'forged','structure'=>'single'],$uid);
  self::assertNotSame('',$err);
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''));
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''));
  self::assertSame('',(string) get_user_meta($uid,'cpms_onboarding_facility_type',true));
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'forged_structure'],$uid);
  self::assertNotSame('',$err);
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''),'invalid structure must roll back the whole save — no partial facility write');
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''));
  self::assertSame('',(string) get_user_meta($uid,'cpms_onboarding_facility_type',true));
  self::assertSame('',(string) get_user_meta($uid,'cpms_onboarding_structure',true));
 }
 public function testPreBootstrapSelectionRequiresManageOptionsAndValidNonce(): void {
  $sub = self::factory()->user->create(['role'=>'subscriber']);
  wp_set_current_user($sub);
  $prev = $_POST;
  $_POST = ['action'=>'cpms_wizard_facility','_wpnonce'=>wp_create_nonce('cpms_wizard_facility'),'facility_type'=>'hospital'];
  try {
   CpmsSetupWizard::save_facility_selection();
   self::fail('a subscriber must never persist a facility selection');
  } catch (\WPDieException $e) {
   self::assertStringContainsString('اعتبارسنجی ناموفق',$e->getMessage());
  }
  self::assertSame('',(string) get_user_meta($sub,'cpms_onboarding_facility_type',true));
  $admin = self::factory()->user->create(['role'=>'administrator']);
  wp_set_current_user($admin);
  $_POST = ['action'=>'cpms_wizard_facility','facility_type'=>'hospital'];
  try {
   CpmsSetupWizard::save_facility_selection();
   self::fail('an administrator without a valid nonce must never persist a facility selection');
  } catch (\WPDieException $e) {
   self::assertStringContainsString('اعتبارسنجی ناموفق',$e->getMessage());
  }
  self::assertSame('',(string) get_user_meta($admin,'cpms_onboarding_facility_type',true));
  $_POST = $prev;
 }
 public function testMedicalBuildingFinishStaysFailClosedAndDisclosesProvisioningPath(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'other','structure'=>'medical_building'],$uid));
  $before = $this->tenantSnapshot();
  $html = $this->renderStep('facility');
  self::assertStringContainsString('data-cpms-multi-clinic-blocked',$html);
  self::assertStringContainsString('page=cpms-organizations',$html,'multi-Clinic provisioning stays behind the Organization Manager screen');
  $ref = new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  $err = $ref->invoke(null, App::settings(), $uid);
  self::assertNotSame('',$err,'medical building completion must stay fail-closed in the wizard');
  self::assertFalse((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame($before,$this->tenantSnapshot(),'the refused finish must not create or alter any Clinic row');
  self::assertFalse(FacilityTypeCatalog::flow_supports_provisioning('medical_building'));
 }
}
