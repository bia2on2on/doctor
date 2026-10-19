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
 *    unmet steps of the new flow, and empty after the real acknowledgment is saved);
 *  - type/structure compatibility policy: a hospital can never be paired with a structure
 *    that would skip the outpatient-only disclosure/health step/acknowledgment (server
 *    validation, picker UI and resolve_flow defense in depth all agree);
 *  - fresh-acknowledgment semantics: save_health validates the affirmative check on the
 *    CURRENT submission — a stored true flag is never a substitute, it is reset on any
 *    type/structure change and on progress restart, and rejected submissions never
 *    destroy stored history (clinical/financial/tenant data untouched throughout);
 *  - truthful completion state: the historical setup.completed flag is preserved in
 *    storage, but banner, finish-step indicator and finish card display completion only
 *    when the CURRENT flow supports provisioning and every CURRENT required step is
 *    verifiably complete (doctor_practice → general_clinic → hospital → medical_building);
 *  - defense in depth: the public save_facility/save_doctors/save_health methods enforce
 *    the same Clinic-local CONFIG gate as every other wizard write.
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
  self::assertSame('مرکز درمانی', FacilityTypeCatalog::type_profile('hospital')['unit'], 'hospital unit must be the outpatient-neutral wording, not "بیمارستان" or "کلینیک"');
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
  // Hospital outpatient network: same multi-provider minimum, aligned with the displayed input min.
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','hospital_network');
  $err = CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid);
  self::assertNotSame('',$err,'hospital flow must reject an intended count below the displayed minimum of 2');
  self::assertStringContainsString('دست‌کم ۲',$err);
  self::assertSame(2, CpmsSetupWizard::intended_clinicians(App::settings()), 'the rejected hospital save leaves the stored count untouched');
  $html = $this->renderStep('doctors');
  self::assertStringContainsString('min="2"',$html,'hospital doctors input min must match the server-side ≥2 validation');
  self::assertStringContainsString('دست‌کم ۲',$html);
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'2'],$uid),'positive control: intended 2 is valid for the hospital flow');
  // Positive control: the single-provider practice flow accepts 1 (displayed min = 1).
  update_user_meta($uid,'cpms_onboarding_facility_type','doctor_practice'); update_user_meta($uid,'cpms_onboarding_structure','single');
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid),'positive control: intended 1 is valid for the doctor-practice flow');
  self::assertSame(1, CpmsSetupWizard::intended_clinicians(App::settings()));
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
  // medical_building is only compatible with non-hospital types under the
  // compatibility policy; 'other' + medical_building is the sanctioned combo.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'other','structure'=>'medical_building'],$uid));
  self::assertSame('medical_building',(string)App::settings()->get('setup.facility_structure'));
  self::assertSame(['facility','clinic','locations','review','finish'], array_column(CpmsSetupWizard::active_steps(),'id'), 'the selecting admin sees the stored adaptive flow');
  $other = self::factory()->user->create(['role'=>'administrator']);
  $this->switchActorWithMembership($other, $clinicId);
  self::assertSame(['facility','clinic','locations','review','finish'], array_column(CpmsSetupWizard::active_steps(),'id'), 'stored structure must drive the same adaptive flow for every authorized manager');
  $html = $this->renderStep('review');
  self::assertStringContainsString('data-cpms-multi-clinic-blocked',$html,'the stored medical-building structure drives the fail-closed disclosure for the other admin');
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_facility_type',true),'reading the flow must never write user-meta for the other admin');
  self::assertSame('',(string) get_user_meta($other,'cpms_onboarding_structure',true));
  self::assertSame('other',(string)App::settings()->get('setup.facility_type'));
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
  // and the missing-step report is exactly the genuinely unmet steps of the new flow.
  // The hospital outpatient network is multi-provider, so the single clinician from the
  // doctor-practice phase no longer satisfies the doctors step (displayed min = 2).
  $before = $this->tenantSnapshot();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame($before,$this->tenantSnapshot());
  self::assertSame(['پزشکان','سلامت سیستم'], CpmsSetupWizard::missing_required_steps(App::settings()), 'doctors must be re-verified against the hospital multi-provider minimum and the health step needs a fresh acknowledgment');
  self::assertFalse(CpmsSetupWizard::step_is_complete('review', App::settings()), 'review must not claim readiness while a required step is unmet');
  // Positive control: satisfy the doctors step for real (second clinician + intended 2).
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک دوم']);
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'2'],$uid));
  $afterDoctors = $this->tenantSnapshot();
  self::assertSame(['سلامت سیستم'], CpmsSetupWizard::missing_required_steps(App::settings()), 'only the outpatient-acknowledged health step remains genuinely missing');
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid));
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid));
  self::assertSame([], CpmsSetupWizard::missing_required_steps(App::settings()));
  self::assertTrue(CpmsSetupWizard::step_is_complete('review', App::settings()));
  self::assertSame($afterDoctors,$this->tenantSnapshot(),'after the intentional clinician addition the evolution path never touched tenant rows again');
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
 public function testHospitalStructureOverrideCanNeverBypassOutpatientSafety(): void {
  $uid = $this->authorize();
  $before = $this->tenantSnapshot();
  // Server-side refusals for every downgrade attempt: no config, no meta, no tenant writes.
  foreach ([['hospital','single'],['hospital','multi_doctor'],['hospital','medical_building'],['general_clinic','hospital_network']] as $combo) {
   $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>$combo[0],'structure'=>$combo[1]],$uid);
   self::assertNotSame('',$err,'incompatible combination must be refused: '.$combo[0].'+'.$combo[1]);
   self::assertStringContainsString('سازگار نیست',$err,'the refusal explains the compatibility policy in Persian');
  }
  self::assertSame('',(string)(App::settings()->get('setup.facility_type','')??''),'refused saves must not write config');
  self::assertSame('',(string)(App::settings()->get('setup.facility_structure','')??''));
  self::assertSame('',(string) get_user_meta($uid,'cpms_onboarding_facility_type',true));
  self::assertSame('',(string) get_user_meta($uid,'cpms_onboarding_structure',true));
  self::assertSame($before,$this->tenantSnapshot(),'refusals never touch tenant rows');
  // Positive controls: the compatible hospital selections persist.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  self::assertSame('hospital',(string)App::settings()->get('setup.facility_type'));
  self::assertSame('hospital_network',(string)App::settings()->get('setup.facility_structure'));
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>''],$uid),'the type-default structure stays valid for a hospital');
  // Defense in depth: hand-crafted meta can never downgrade the hospital flow —
  // resolve_flow ignores the incompatible override, so disclosure + health + ack stay.
  update_user_meta($uid,'cpms_onboarding_facility_type','hospital'); update_user_meta($uid,'cpms_onboarding_structure','single');
  $html = $this->renderStep('facility');
  self::assertStringContainsString('data-cpms-onboarding-flow="hospital_network"',$html,'an incompatible stored structure never resolves away from the hospital flow');
  self::assertStringContainsString('data-cpms-inpatient-unsupported',$html);
  // Picker UI: incompatible structures are disabled for the selected hospital; the compatible one is not.
  self::assertSame(1, preg_match('/value="single"[^>]*disabled/', $html), 'single structure must render disabled for a hospital');
  self::assertSame(1, preg_match('/value="multi_doctor"[^>]*disabled/', $html));
  self::assertSame(1, preg_match('/value="medical_building"[^>]*disabled/', $html));
  self::assertSame(0, preg_match('/value="hospital_network"[^>]*disabled/', $html), 'the compatible structure must stay selectable');
  self::assertStringContainsString('data-cpms-structure-policy',$html,'the picker carries the machine-readable compatibility policy');
  self::assertStringContainsString('ساختار باید با نوع مرکز سازگار باشد',$html);
  $html = $this->renderStep('health');
  self::assertStringContainsString('name="outpatient_only_ack"',$html,'the outpatient-only acknowledgment can never be skipped by a structure override');
  self::assertStringContainsString('data-cpms-hospital-outpatient-scope="1"',$html);
  self::assertFalse(CpmsSetupWizard::step_is_complete('health', App::settings()));
  // The pre-bootstrap endpoint enforces the same policy (manage_options + nonce only).
  $prev = $_POST;
  $_POST = ['action'=>'cpms_wizard_facility','_wpnonce'=>wp_create_nonce('cpms_wizard_facility'),'facility_type'=>'hospital','structure'=>'single'];
  try {
   CpmsSetupWizard::save_facility_selection();
   self::fail('the pre-bootstrap endpoint must refuse a hospital downgrade too');
  } catch (\WPDieException $e) {
   self::assertStringContainsString('سازگار نیست',$e->getMessage());
  }
  $_POST = $prev;
  self::assertSame('single',(string) get_user_meta($uid,'cpms_onboarding_structure',true),'the refused pre-bootstrap save must not rewrite meta');
  self::assertSame($before,$this->tenantSnapshot());
 }
 public function testSaveHealthRequiresFreshAffirmativeAckAndStaleAckIsReset(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  // Unchecked submission: refused, nothing persisted.
  $err = CpmsSetupWizard::save_health(App::settings(), [], $uid);
  self::assertNotSame('',$err);
  self::assertStringContainsString('تأیید',$err,'the refusal demands a fresh explicit acknowledgment');
  self::assertSame(false,(bool)App::settings()->get('setup.outpatient_only_ack',false));
  self::assertFalse(CpmsSetupWizard::step_is_complete('health', App::settings()));
  // Affirmative fresh POST: accepted (positive control).
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid));
  self::assertTrue((bool)App::settings()->get('setup.outpatient_only_ack',false));
  self::assertTrue(CpmsSetupWizard::step_is_complete('health', App::settings()));
  // A stored true is never a substitute for the fresh affirmative POST.
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'0'], $uid));
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid));
  // A rejected resubmission never destroys the stored historical value (non-destructive).
  self::assertTrue((bool)App::settings()->get('setup.outpatient_only_ack',false),'rejected submissions must not wipe the stored acknowledgment');
  // Changing the type/structure resets the stale acknowledgment — config-scope only.
  $before = $this->tenantSnapshot();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'multi_doctor'],$uid));
  self::assertSame(false,(bool)App::settings()->get('setup.outpatient_only_ack',false),'a stale acknowledgment must never survive a type/structure change');
  self::assertSame($before,$this->tenantSnapshot(),'the ack reset is config-scope only — tenant rows untouched');
  // Returning to the hospital requires a fresh acknowledgment again.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>''],$uid));
  self::assertFalse(CpmsSetupWizard::step_is_complete('health', App::settings()));
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid));
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid));
  self::assertTrue(CpmsSetupWizard::step_is_complete('health', App::settings()));
  // Progress restart clears flow-bound state but never tenant/clinical history.
  App::settings()->set(CpmsSetupWizard::COMPLETE_KEY, true, $uid);
  $mid = $this->tenantSnapshot();
  CpmsSetupWizard::reset_progress(App::settings(), $uid);
  self::assertSame(false,(bool)App::settings()->get('setup.outpatient_only_ack',false),'restart must clear the acknowledgment so it can never be reused stale');
  self::assertFalse((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false));
  self::assertSame('welcome',(string)(App::settings()->get('setup.current_step','')??''));
  self::assertSame('hospital',(string)App::settings()->get('setup.facility_type'),'restart never erases the persisted facility selection');
  self::assertSame($mid,$this->tenantSnapshot(),'restart is progress-only: clinical/financial/tenant rows preserved');
 }
 public function testTruthfulCompletionStateAcrossFlowEvolutionIsDisplayedOnlyWhenCurrentlyTrue(): void {
  $uid = $this->authorize(); $clinicId = (int) App::scope()->clinicId; global $wpdb;
  // Build a genuinely completed doctor-practice setup with real data.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'doctor_practice','structure'=>'single'],$uid));
  self::assertSame('', CpmsSetupWizard::saveClinic(App::settings(),['clinic_name'=>'مطب کامل'],$uid));
  $locs = App::locationRepository()->listForClinic($clinicId);
  if ($locs===[]) { App::locationRepository()->create($clinicId,'مکان اصلی','main-'.wp_generate_password(6,false,false),'Asia/Tehran', gmdate('Y-m-d H:i:s')); $locs = App::locationRepository()->listForClinic($clinicId); }
  $wpdb->query('SET FOREIGN_KEY_CHECKS=0'); $wpdb->query('DELETE FROM '.$wpdb->prefix.'cpms_clinicians WHERE clinic_id='.$clinicId); $wpdb->query('SET FOREIGN_KEY_CHECKS=1');
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک کامل']);
  $clinicians = App::clinicianRepository()->listAll($clinicId,false);
  $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.$wpdb->prefix.'cpms_schedule (clinic_id,clinician_id,location_id,day_of_week,start_time,end_time,is_active,created_at,updated_at) VALUES (%d,%d,%d,1,%s,%s,1,NOW(),NOW())',$clinicId,(int)$clinicians[0]['id'],(int)$locs[0]['id'],'09:00:00','12:00:00'));
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'1'],$uid));
  self::assertSame('', CpmsSetupWizard::saveBooking(App::settings(),['duration'=>'20','capacity'=>'1','lead'=>'2','future'=>'60','cancel'=>'24'],$uid));
  CpmsSetupWizard::mark_step_completed(App::settings(),'booking',$uid);
  $ref = new \ReflectionMethod(CpmsSetupWizard::class,'saveFinish'); $ref->setAccessible(true);
  self::assertSame('', $ref->invoke(null, App::settings(), $uid));
  $banner = '✅ راه‌اندازی تکمیل شده است';
  // Positive control: a genuinely complete flow displays completion everywhere.
  self::assertTrue(CpmsSetupWizard::step_is_complete('finish', App::settings()));
  self::assertStringContainsString($banner, $this->renderStep('review'),'the header banner reflects a truthful completion');
  self::assertStringContainsString('data-cpms-finish-ready="1"', $this->renderStep('finish'));
  // 1) Evolve to a multi-doctor clinic: history preserved, current flow honestly incomplete.
  $before = $this->tenantSnapshot();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>'multi_doctor'],$uid));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false),'the historical completion flag is preserved in storage');
  self::assertSame(['پزشکان'], CpmsSetupWizard::missing_required_steps(App::settings()));
  self::assertFalse(CpmsSetupWizard::step_is_complete('finish', App::settings()),'completion must not be displayed while the current flow is unmet');
  self::assertStringNotContainsString($banner, $this->renderStep('review'),'the banner must not claim a completion the current flow does not satisfy');
  $html = $this->renderStep('finish');
  self::assertStringContainsString('data-cpms-finish-ready="0"',$html);
  self::assertStringContainsString('data-cpms-stale-completion="1"',$html,'the finish card discloses the preserved historical flag honestly');
  self::assertStringContainsString('پزشکان',$html);
  self::assertSame($before,$this->tenantSnapshot());
  // Restore real readiness: a second clinician + intended 2 → completion is truthful again.
  App::clinicianRepository()->create($clinicId,['full_name'=>'پزشک دوم']);
  self::assertSame('', CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'2'],$uid));
  self::assertSame([], CpmsSetupWizard::missing_required_steps(App::settings()));
  self::assertTrue(CpmsSetupWizard::step_is_complete('finish', App::settings()));
  self::assertStringContainsString($banner, $this->renderStep('review'),'positive control: the banner returns as soon as readiness is genuinely true again');
  // 2) Evolve to the hospital: the stale ack was reset → health genuinely missing; doctors already ≥2.
  $mid = $this->tenantSnapshot();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$uid));
  self::assertSame(false,(bool)App::settings()->get('setup.outpatient_only_ack',false),'evolution resets the stale acknowledgment');
  self::assertSame(['سلامت سیستم'], CpmsSetupWizard::missing_required_steps(App::settings()));
  self::assertFalse(CpmsSetupWizard::step_is_complete('finish', App::settings()));
  self::assertStringNotContainsString($banner, $this->renderStep('review'));
  self::assertNotSame('', CpmsSetupWizard::save_health(App::settings(), [], $uid),'a fresh explicit acknowledgment is required on the current submission');
  self::assertSame('', CpmsSetupWizard::save_health(App::settings(), ['outpatient_only_ack'=>'1'], $uid));
  self::assertTrue(CpmsSetupWizard::step_is_complete('finish', App::settings()));
  self::assertStringContainsString($banner, $this->renderStep('review'));
  self::assertSame($mid,$this->tenantSnapshot());
  // 3) Evolve to a medical building: never complete, never erased, fail-closed finish.
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'other','structure'=>'medical_building'],$uid));
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false),'the historical flag survives every evolution');
  self::assertFalse(CpmsSetupWizard::step_is_complete('finish', App::settings()),'the building flow never displays completion in this wizard');
  self::assertStringNotContainsString($banner, $this->renderStep('review'));
  $html = $this->renderStep('finish');
  self::assertStringContainsString('data-cpms-finish-ready="0"',$html);
  self::assertStringContainsString('data-cpms-stale-completion="1"',$html);
  self::assertNotSame('', $ref->invoke(null, App::settings(), $uid),'building completion stays fail-closed');
  self::assertTrue((bool)App::settings()->get(CpmsSetupWizard::COMPLETE_KEY,false),'the refused finish must not erase the historical flag');
  self::assertSame($mid,$this->tenantSnapshot(),'the whole evolution path never touched tenant rows after the intentional clinician additions');
 }
 public function testPublicSaveMethodsDenyActorWithoutClinicLocalConfig(): void {
  $uid = $this->authorize();
  self::assertSame('', CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'general_clinic','structure'=>''],$uid));
  $before = $this->tenantSnapshot();
  $outsider = self::factory()->user->create(['role'=>'administrator']);
  wp_set_current_user($outsider);
  // Defense in depth: the same Clinic-local CONFIG gate every other wizard write
  // enforces, checked BEFORE any flow inspection or payload processing.
  $err = CpmsSetupWizard::save_facility(App::settings(),['facility_type'=>'hospital','structure'=>'hospital_network'],$outsider);
  self::assertStringContainsString('عضویت فعال',$err,'save_facility must enforce the Clinic-local CONFIG gate');
  self::assertSame('general_clinic',(string)App::settings()->get('setup.facility_type'),'the denied save must not write config');
  $err = CpmsSetupWizard::save_doctors(App::settings(),['intended_clinicians'=>'5'],$outsider);
  self::assertStringContainsString('عضویت فعال',$err);
  self::assertSame(0, CpmsSetupWizard::intended_clinicians(App::settings()),'the denied save_doctors must not write the count');
  $err = CpmsSetupWizard::save_health(App::settings(),['outpatient_only_ack'=>'1'],$outsider);
  self::assertStringContainsString('عضویت فعال',$err,'the auth gate fires even on a non-hospital flow (gate precedes flow logic)');
  self::assertSame(false,(bool)App::settings()->get('setup.outpatient_only_ack',false),'the denied save_health must not write the acknowledgment');
  self::assertSame('',(string) get_user_meta($outsider,'cpms_onboarding_facility_type',true));
  self::assertSame('',(string) get_user_meta($outsider,'cpms_onboarding_structure',true));
  self::assertSame($before,$this->tenantSnapshot(),'denied saves never touch tenant rows');
 }
}
