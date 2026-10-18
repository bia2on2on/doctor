<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use ClinicCore\Admin\CpmsSetupWizard;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use ClinicCore\Application\Scope\ScopeContext;
use ClinicCore\Application\Scope\TrustedClinicEstablisher;
use ClinicCore\Bootstrap\App;
use ClinicCore\Infrastructure\Repository\MembershipRepository;
use ClinicCore\Settings\Settings;
use WP_UnitTestCase;
/**
 * Adaptive facility-type onboarding slice (12 supported types) — corrective batch.
 *
 * Contracts pinned here:
 *  - all 12 approved facility types keep adaptive Persian profiles + honest guidance;
 *  - adaptive labels/inputs/validation render RTL inside the real wizard without GET writes;
 *  - hospital stays outpatient-only: explicit acknowledgment is REQUIRED to complete its
 *    health step, and the disclosure renders consistently;
 *  - reception/finance (users) step is explicitly optional — never an unenforceable "required";
 *  - medical building stays fail-closed with an explicit Organization Manager provisioning
 *    handoff and no tenant-scope guessing;
 *  - cross-admin resumability: a second authorized admin with explicit membership and a fresh
 *    ScopeContext resumes the persisted Clinic-scoped type/structure in the REAL rendered HTML
 *    (checked radio, flow summary, disclosures) with zero GET writes and zero user-meta writes;
 *  - authorization: pre-bootstrap selection requires manage_options + nonce; post-bootstrap
 *    wizard writes are denied for an administrator without an active authorized membership,
 *    and zero-membership scope establishment never infers a Clinic;
 *  - rollback/preservation: invalid type/structure input never writes and preserves previously
 *    stored non-empty configuration;
 *  - non-destructive evolution: same-flow and cross-flow changes (including after completion —
 *    the sanctioned acceptance journey) only touch config/user-meta, never tenant rows
 *    (Clinic/Location/Membership/Clinician) and never silently delete the completion marker;
 *  - missing_required_steps is accurate after cross-flow evolution (exactly the genuinely
 *    unmet steps of the new flow, and empty after the real acknowledgment is saved).
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
 /** Actor switch with explicit membership + fresh ScopeContext (no implicit sole-Clinic shortcut). */
 private function switchActorWithMembership(int $uid, int $clinicId): void {
  cpms_test_seed_membership($uid, $clinicId, 'cpms_manager');
  wp_set_current_user($uid);
  ScopeContext::clear();
  App::resetScope();
  App::replaceExplicitScope(App::scope());
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
 public function testAllTwelveTypesKeepAdaptiveProfilesWithGuidanceAndNoInpatientOrMultiClinicClaims(): void {
  self::assertCount(12, FacilityTypeCatalog::all(), 'the 12 approved facility types must be preserved');
  foreach (FacilityTypeCatalog::all() as $t) {
   $profile = FacilityTypeCatalog::type_profile((string) $t['id']);
   foreach (['unit','clinician','clinicians','name_label','guidance'] as $key) {
    self::assertNotSame('', trim((string) $profile[$key]), 'profile '.$key.' for '.$t['id']);
   }
   self::assertFalse(FacilityTypeCatalog::flow_supports_inpatient(FacilityTypeCatalog::resolve_flow((string) $t['id'])), 'no flow may imply inpatient/HIS support: '.$t['id']);
  }
  self::assertSame('دندانپزشک', FacilityTypeCatalog::type_profile('dental_clinic')['clinician']);
  self::assertSame('مطب', FacilityTypeCatalog::type_profile('doctor_practice')['unit']);
  self::assertSame('بیمارستان', FacilityTypeCatalog::type_profile('hospital')['unit']);
  self::assertStringContainsString('سرپایی', FacilityTypeCatalog::type_profile('hospital')['name_label'], 'hospital identity label must stay outpatient-scoped');
  self::assertStringContainsString('HIS', FacilityTypeCatalog::type_profile('hospital')['guidance'], 'hospital guidance must name the inpatient/HIS limitation explicitly');
  $generic = FacilityTypeCatalog::type_profile('');
  self::assertSame('نام کلینیک', $generic['name_label'], 'legacy installs without a selection keep the previous wording');
  self::assertSame('', $generic['guidance'], 'legacy installs get no invented guidance');
  self::assertSame($generic, FacilityTypeCatalog::type_profile('forged_type'));
  self::assertFalse(FacilityTypeCatalog::supports_multi_clinic_provisioning());
 }
 public function testReceptionFinanceUsersStepIsExplicitlyOptionalAndNeverUnenforceableRequired(): void {
  foreach (['simple_practice','multi_doctor_clinic','hospital_network','medical_building'] as $flow) {
   self::assertNotContains('users', FacilityTypeCatalog::flow_required_step_ids($flow), 'users step must be explicitly optional for '.$flow);
  }
  self::assertContains('کارکنان پذیرش و مالی (اختیاری)', FacilityTypeCatalog::flow_steps('multi_doctor_clinic'));
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','general_clinic'); update_user_meta($uid,'cpms_onboarding_structure','multi_doctor');
  $steps = CpmsSetupWizard::active_steps();
  $users = null;
  foreach ($steps as $s) { if ($s['id']==='users') { $users = $s; } }
  self::assertIsArray($users, 'users step stays visible in the multi-doctor journey');
  self::assertFalse((bool) $users['required'], 'visible but explicitly optional');
  $html = $this->renderStep('users');
  self::assertStringContainsString('data-cpms-users-step-optional="1"', $html);
  self::assertStringContainsString('این گام اختیاری است', $html);
 }
 public function testAdaptivePersianLabelsInputsAndGuidanceRenderRtlForDentalAndDoctorPractice(): void {
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','dental_clinic'); update_user_meta($uid,'cpms_onboarding_structure','multi_doctor');
  $html = $this->renderStep('doctors');
  self::assertStringContainsString('dir="rtl"',$html);
  self::assertStringContainsString('تعداد دندانپزشکان موردنظر',$html);
  self::assertStringContainsString('دندانپزشکان فعال',$html);
  self::assertStringContainsString('min="2"',$html,'multi-doctor flow input min must match the server-side ≥2 validation');
  self::assertStringContainsString('دست‌کم ۲',$html);
  self::assertStringContainsString('data-cpms-facility-guidance="dental_clinic"',$html,'per-type guidance renders in the flow summary');
  update_user_meta($uid,'cpms_onboarding_facility_type','doctor_practice'); update_user_meta($uid,'cpms_onboarding_structure','single');
  $html = $this->renderStep('clinic');
  self::assertStringContainsString('نام مطب',$html);
  self::assertStringContainsString('مشخصات مطب',$html);
  self::assertStringContainsString('data-cpms-facility-guidance="doctor_practice"',$html);
  $html = $this->renderStep('doctors');
  self::assertStringContainsString('min="1"',$html,'the dedicated doctor-practice flow keeps a single-provider minimum');
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''),'GET render must not persist the facility choice');
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''),'GET render must not persist the structure choice');
 }
 public function testMultiDoctorIntendedCountValidationRejectsSingleProviderWithoutTouchingData(): void {
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','dental_clinic'); update_user_meta($uid,'cpms_onboarding_structure','multi_doctor');
  $before = $this->tenantSnapshot();
  $err = CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid);
  self::assertNotSame('',$err,'multi-provider flow must reject an intended count below two');
  self::assertSame(0, CpmsSetupWizard::intended_clinicians(App::settings()), 'a rejected save must not write the count');
  $err = CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'2'],$uid);
  self::assertSame('',$err);
  self::assertSame(2, CpmsSetupWizard::intended_clinicians(App::settings()));
  self::assertSame($before,$this->tenantSnapshot(),'doctors-step saves never touch tenant rows');
 }
 public function testHospitalFlowRendersOutpatientOnlyDisclosureAndRequiresExplicitAcknowledgment(): void {
  $uid = $this->authorize();
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','hospital_network');
  $html = $this->renderStep('facility');
  self::assertStringContainsString('data-cpms-inpatient-unsupported',$html);
  self::assertStringContainsString('سرپایی',$html);
  $html = $this->renderStep('health');
  self::assertStringContainsString('data-cpms-hospital-outpatient-scope="1"',$html);
  self::assertStringContainsString('name="outpatient_only_ack"',$html,'hospital health step must carry the explicit acknowledgment field');
  self::assertStringContainsString('data-cpms-inpatient-unsupported',$html);
  self::assertFalse(CpmsSetupWizard::step_is_complete('health', App::settings()));
  $err = CpmsSetupWizard::save_health(App::settings(), [], $uid);
  self::assertNotSame('',$err,'hospital health step must not complete without the acknowledgment');
  self::assertStringContainsString('سرپایی',$err);
  self::assertFalse(CpmsSetupWizard::step_is_complete('health', App::settings()));
  $err = CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid);
  self::assertSame('',$err);
  self::assertTrue((bool) App::settings()->get('setup.outpatient_only_ack', false));
  self::assertTrue(CpmsSetupWizard::step_is_complete('health', App::settings()));
  $html = $this->renderStep('health');
  self::assertStringContainsString("checked='checked'",$html,'saved acknowledgment resumes checked (RTL, no re-asking)');
 }
 public function testLegacyHealthStepStaysInformationalWithoutFacilitySelection(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid), 'non-hospital flows keep the informational health step');
  self::assertSame('', (string)(App::settings()->get('setup.outpatient_only_ack','')??''), 'no acknowledgment key is invented for non-hospital flows');
  CpmsSetupWizard::mark_step_completed(App::settings(), 'health', $uid);
  self::assertTrue(CpmsSetupWizard::step_is_complete('health', App::settings()));
 }
 public function testSecondAuthorizedAdminResumesPersistedSelectionInRealRenderedHtml(): void {
  $uid = $this->authorize();
  $clinicId = (int) App::scope()->clinicId;
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  $uid2 = self::factory()->user->create(['role'=>'administrator']);
  self::assertSame('', (string) get_user_meta($uid2,'cpms_onboarding_facility_type',true));
  $this->switchActorWithMembership($uid2, $clinicId);
  self::assertSame('hospital', CpmsSetupWizard::selected_facility_type(), 'persisted Clinic-scoped type must resume without user-meta');
  self::assertSame(['facility','clinic','locations','doctors','users','schedules','booking','health','review','finish'], array_column(CpmsSetupWizard::active_steps(),'id'));
  $html = $this->renderStep('facility');
  self::assertSame(1, preg_match('/value="hospital"[^>]*checked/', $html), 'the persisted type is the checked radio for the second admin');
  self::assertStringContainsString('data-cpms-onboarding-flow="hospital_network"',$html,'flow summary resumes for the second admin');
  self::assertStringContainsString('data-cpms-inpatient-unsupported',$html,'hospital disclosure resumes for the second admin');
  self::assertStringContainsString('data-cpms-facility-guidance="hospital"',$html);
  self::assertSame('', (string) get_user_meta($uid2,'cpms_onboarding_facility_type',true),'GET render must not write user-meta for the second admin');
  self::assertSame('', (string) get_user_meta($uid2,'cpms_onboarding_structure',true));
  self::assertSame('hospital',(string)App::settings()->get('setup.facility_type'),'GET render must not rewrite stored config');
  // Medical-building disclosure resumes the same way for a second admin.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'other','structure'=>'medical_building'],$uid2));
  $uid3 = self::factory()->user->create(['role'=>'administrator']);
  $this->switchActorWithMembership($uid3, $clinicId);
  $html = $this->renderStep('review');
  self::assertStringContainsString('data-cpms-multi-clinic-blocked',$html,'medical-building disclosure resumes for a third admin');
  self::assertStringContainsString('data-cpms-building-handoff="1"',$html,'review step carries the explicit Organization Manager provisioning handoff');
  self::assertStringContainsString('page=cpms-organizations',$html);
  self::assertStringContainsString('هیچ محدودهٔ مستأجری از اینجا حدس زده نمی‌شود',$html);
 }
 public function testStoredStructureIsResumableForAnotherAuthorizedAdminWithoutWrites(): void {
  $uid = $this->authorize();
  $clinicId = (int) App::scope()->clinicId;
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'hospital_network'],$uid));
  self::assertSame('hospital_network',(string)App::settings()->get('setup.facility_structure'));
  self::assertContains('health', array_column(CpmsSetupWizard::active_steps(),'id'), 'the selecting admin sees the stored adaptive flow');
  $other = self::factory()->user->create(['role'=>'administrator']);
  $this->switchActorWithMembership($other, $clinicId);
  $steps = array_column(CpmsSetupWizard::active_steps(),'id');
  self::assertContains('health',$steps,'stored structure must drive the same adaptive flow for every authorized manager');
  self::assertContains('clinic',$steps);
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_facility_type',true),'reading the flow must never write user-meta for the other admin');
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_structure',true));
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'));
 }
 public function testPostBootstrapWizardWriteDeniedForAdminWithoutActiveMembershipAndNoClinicInference(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>''],$uid));
  $outsider = self::factory()->user->create(['role'=>'administrator']);
  wp_set_current_user($outsider);
  // Zero-membership scope establishment must never infer a Clinic (no first-row fallback).
  $threw = false;
  try { (new TrustedClinicEstablisher(App::db(), new MembershipRepository(App::db())))->establish($outsider, null); } catch (\Throwable) { $threw = true; }
  self::assertTrue($threw, 'a zero-membership administrator must not get an inferred Clinic scope');
  $prev = $_POST;
  $_POST = ['action'=>'cpms_wizard_save','_wpnonce'=>wp_create_nonce('cpms_wizard_save'),'step'=>'facility','facility_type'=>'hospital','structure'=>'hospital_network'];
  try {
   CpmsSetupWizard::save();
   self::fail('an administrator without an active authorized membership must not write through the wizard');
  } catch (\WPDieException $e) {
   self::assertStringContainsString('عضویت فعال',$e->getMessage());
  }
  $_POST = $prev;
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'),'denied write must leave stored config untouched');
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''));
  self::assertSame('',(string) get_user_meta($outsider,'cpms_onboarding_facility_type',true));
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
 public function testCrossFlowEvolutionAfterCompletionIsAllowedAndNonDestructive(): void {
  // Mirrors the established browser-acceptance journey (admin.multi.facility_resumes):
  // a finished doctor-practice setup may evolve into a multi-doctor clinic flow.
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid));
  App::settings()->set(CpmsSetupWizard::COMPLETE_KEY,true,$uid);
  $before = $this->tenantSnapshot();
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'multi_doctor'],$uid);
  self::assertSame('',$err,'cross-flow evolution stays allowed after completion (acceptance journey admin.multi.facility_resumes)');
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('multi_doctor',(string)App::settings()->get('setup.facility_structure'));
  self::assertSame('multi_doctor',(string) get_user_meta($uid,'cpms_onboarding_structure',true));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false),'evolution must never silently delete the completion marker');
  self::assertSame($before,$this->tenantSnapshot(),'evolution must never touch Clinic/Location/Membership/Clinician rows');
  self::assertSame(['facility','clinic','locations','doctors','users','schedules','booking','review','finish'], array_column(CpmsSetupWizard::active_steps(),'id'), 'the adaptive flow switches to the newly selected multi-doctor journey');
 }
 public function testMissingRequiredStepsAccurateAfterCrossFlowEvolutionWithRealData(): void {
  $uid = $this->authorize(); $clinicId = (int) App::scope()->clinicId; global $wpdb;
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid));
  self::assertSame('', CpmsSetupWizard::saveClinic(App::settings(),['clinic_name'=>'مطب کامل'],$uid));
  $locs = App::locationRepository()->listForClinic($clinicId);
  if ($locs===[]) { App::locationRepository()->create($clinicId,'مکان اصلی','main-'.wp_generate_password(6,false,false),'Asia/Tehran', gmdate('Y-m-d H:i:s')); $locs = App::locationRepository()->listForClinic($clinicId); }
  $locationId = (int) $locs[0]['id'];
  $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_clinicians WHERE clinic_id='.$clinicId); $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک کامل']);
  $clinicians = App::clinicianRepository()->listAll($clinicId,false); $clinicianId = (int) $clinicians[0]['id'];
  $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$wpdb->prefix.'cpms_schedule (clinic_id,clinician_id,location_id,day_of_week,start_time,end_time,is_active,created_at,updated_at) VALUES (%d,%d,%d,1,%s,%s,1,NOW(),NOW())',$clinicId,$clinicianId,$locationId,'09:00:00','12:00:00'));
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid));
  self::assertSame('', CpmsSetupWizard::saveBooking(App::settings(),['duration'=>'20','capacity'=>'1','lead'=>'2','future'=>'60','cancel'=>'24'],$uid));
  CpmsSetupWizard::mark_step_completed(App::settings(),'booking',$uid);
  self::assertSame([], CpmsSetupWizard::missing_required_steps(App::settings()), 'the completed doctor-practice flow reports nothing missing');
  $ref = new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  self::assertSame('', $ref->invoke(null, App::settings(), $uid));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  // Cross-flow evolution to hospital: completion marker and tenant rows survive untouched,
  // and the missing-step report is exactly the genuinely unmet step of the new flow.
  $before = $this->tenantSnapshot();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame($before,$this->tenantSnapshot());
  self::assertSame(['سلامت سیستم'], CpmsSetupWizard::missing_required_steps(App::settings()), 'only the outpatient-acknowledged health step is genuinely missing after evolution');
  self::assertFalse(CpmsSetupWizard::step_is_complete('review', App::settings()), 'review must not claim readiness while a required step is unmet');
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid));
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid));
  self::assertSame([], CpmsSetupWizard::missing_required_steps(App::settings()));
  self::assertTrue(CpmsSetupWizard::step_is_complete('review', App::settings()));
  self::assertSame($before,$this->tenantSnapshot(),'the whole evolution path never touched tenant rows');
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
 public function testInvalidChangePreservesPreviouslyStoredNonEmptyConfiguration(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'multi_doctor'],$uid));
  $before = $this->tenantSnapshot();
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'forged','structure'=>'single'],$uid);
  self::assertNotSame('',$err);
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'dental_clinic','structure'=>'forged_structure'],$uid);
  self::assertNotSame('',$err);
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'),'a rejected change must preserve the stored non-empty type');
  self::assertSame('multi_doctor',(string)App::settings()->get('setup.facility_structure'),'a rejected change must preserve the stored non-empty structure');
  self::assertSame('general_clinic',(string) get_user_meta($uid,'cpms_onboarding_facility_type',true));
  self::assertSame('multi_doctor',(string) get_user_meta($uid,'cpms_onboarding_structure',true));
  self::assertSame('multi_doctor_clinic', FacilityTypeCatalog::resolve_flow(CpmsSetupWizard::selected_facility_type(),'multi_doctor'), 'flow resolution stays on the preserved selection');
  self::assertSame($before,$this->tenantSnapshot());
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
 public function testMedicalBuildingFinishStaysFailClosedAndDisclosesProvisioningHandoff(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'other','structure'=>'medical_building'],$uid));
  $before = $this->tenantSnapshot();
  $html = $this->renderStep('facility');
  self::assertStringContainsString('data-cpms-multi-clinic-blocked',$html);
  self::assertStringContainsString('page=cpms-organizations',$html,'multi-Clinic provisioning stays behind the Organization Manager screen');
  $html = $this->renderStep('review');
  self::assertStringContainsString('data-cpms-building-handoff="1"',$html,'review states the explicit provisioning handoff: no tenant scope is guessed here');
  self::assertStringContainsString('درمانگاه مستقلِ جدید نمی‌سازد',$html);
  $ref = new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  $err = $ref->invoke(null, App::settings(), $uid);
  self::assertNotSame('',$err,'medical building completion must stay fail-closed in the wizard');
  self::assertFalse((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame($before,$this->tenantSnapshot(),'the refused finish must not create or alter any Clinic row');
  self::assertFalse(FacilityTypeCatalog::flow_supports_provisioning('medical_building'));
 }
}
