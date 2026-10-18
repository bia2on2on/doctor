<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Unit\Onboarding;
use ClinicCore\Application\Onboarding\FacilityTypeCatalog;
use PHPUnit\Framework\TestCase;
final class FacilityTypeCatalogTest extends TestCase {
 public function testAllTwelvePersianFacilityTypesPresent(): void {
  $all = FacilityTypeCatalog::all();
  self::assertCount(12, $all);
  $labels = array_column($all, 'label');
  foreach (['مطب پزشک — انفرادی یا مشترک','درمانگاه عمومی','درمانگاه تخصصی یا فوق‌تخصصی / پلی‌کلینیک','کلینیک یا درمانگاه دندانپزشکی','مرکز تشخیصی و پاراکلینیکی','مرکز توانبخشی','مرکز سلامت روان','مرکز درمان تخصصی سرپایی','مرکز جراحی محدود یا سرپایی','بیمارستان','مرکز جامع سلامت و خدمات بهداشتی','سایر مراکز درمانی'] as $l) self::assertContains($l, $labels);
 }
 public function testEachFacilityTypeMapsToKnownFlow(): void {
  foreach (FacilityTypeCatalog::all() as $t) {
   self::assertContains($t['flow'], ['simple_practice','multi_doctor_clinic','hospital_network'], $t['id']);
   self::assertNotSame([], FacilityTypeCatalog::flow_steps($t['flow']));
  }
  self::assertSame('simple_practice', FacilityTypeCatalog::find('doctor_practice')['flow'] ?? null);
  self::assertSame('hospital_network', FacilityTypeCatalog::find('hospital')['flow'] ?? null);
  self::assertNull(FacilityTypeCatalog::find('unknown'));
  self::assertFalse(FacilityTypeCatalog::is_valid('unknown'));
 }
 public function testHospitalDisclosesInpatientUnsupportedAndBuildingBlocked(): void {
  self::assertFalse(FacilityTypeCatalog::flow_supports_inpatient('hospital_network'));
  self::assertFalse(FacilityTypeCatalog::supports_multi_clinic_provisioning());
  $b = FacilityTypeCatalog::flow_steps('medical_building');
  self::assertNotSame([], $b);
  self::assertFalse(FacilityTypeCatalog::flow_supports_provisioning('medical_building'));
  self::assertTrue(FacilityTypeCatalog::flow_supports_provisioning('simple_practice'));
 }
 public function testFlowStepSelectionIsRealAndFlowSpecific(): void {
  $simple = FacilityTypeCatalog::flow_step_ids('simple_practice');
  $hospital = FacilityTypeCatalog::flow_step_ids('hospital_network');
  $building = FacilityTypeCatalog::flow_step_ids('medical_building');
  self::assertContains('locations', $simple);
  self::assertContains('schedules', $simple);
  self::assertContains('health', $hospital);
  self::assertNotContains('health', $simple);
  self::assertNotContains('booking', $building);
  $req = FacilityTypeCatalog::flow_required_step_ids('multi_doctor_clinic');
  self::assertContains('doctors', $req);
  self::assertContains('locations', $req);
  self::assertNotContains('users', FacilityTypeCatalog::flow_required_step_ids('simple_practice'), 'secretary must stay optional for a simple practice');
 }
 public function testAdaptiveTypeProfilesCoverEveryCatalogIdWithPersianLabels(): void {
  foreach (FacilityTypeCatalog::all() as $t) {
   $p = FacilityTypeCatalog::type_profile((string) $t['id']);
   foreach (['unit','clinician','clinicians','name_label','guidance'] as $k) {
    self::assertNotSame('', trim((string) $p[$k]), 'profile '.$k.' for '.$t['id']);
   }
   self::assertNotContains('users', FacilityTypeCatalog::flow_required_step_ids(FacilityTypeCatalog::resolve_flow((string) $t['id'])), 'reception/finance users step must be explicitly optional for '.$t['id']);
  }
  self::assertStringContainsString('HIS', FacilityTypeCatalog::type_profile('hospital')['guidance'], 'hospital guidance must name the inpatient/HIS limitation');
  self::assertContains('کارکنان پذیرش و مالی (اختیاری)', FacilityTypeCatalog::flow_steps('multi_doctor_clinic'));
  self::assertSame('دندانپزشک', FacilityTypeCatalog::type_profile('dental_clinic')['clinician']);
  self::assertSame('مطب', FacilityTypeCatalog::type_profile('doctor_practice')['unit']);
  self::assertSame('بیمارستان', FacilityTypeCatalog::type_profile('hospital')['unit']);
  self::assertStringContainsString('سرپایی', FacilityTypeCatalog::type_profile('hospital')['name_label'], 'hospital identity label must stay outpatient-scoped');
  self::assertSame(FacilityTypeCatalog::type_profile(''), FacilityTypeCatalog::type_profile('unknown'), 'unknown ids fall back to the generic legacy profile');
  self::assertSame('نام کلینیک', FacilityTypeCatalog::type_profile('')['name_label']);
  self::assertSame('', FacilityTypeCatalog::type_profile('')['guidance'], 'legacy installs get no invented guidance');
 }
 public function testStructureCompatibilityPolicyProtectsHospitalOutpatientScope(): void {
  // '' (type default) is always compatible; unknown structures never are.
  foreach (array_keys(FacilityTypeCatalog::structures()) as $k) {
   self::assertTrue(FacilityTypeCatalog::structure_is_compatible('hospital', ''), 'hospital + type-default structure stays valid');
   self::assertFalse(FacilityTypeCatalog::structure_is_compatible('hospital', 'forged'), 'unknown structures are refused: '.$k);
  }
  self::assertTrue(FacilityTypeCatalog::structure_is_compatible('hospital', 'hospital_network'));
  foreach (['single','multi_doctor','medical_building'] as $bad) {
   self::assertFalse(FacilityTypeCatalog::structure_is_compatible('hospital', $bad), 'a hospital must never accept '.$bad.' — that would skip the outpatient-only health step');
   self::assertStringContainsString('سازگار نیست', FacilityTypeCatalog::structure_incompatibility_message('hospital', $bad));
  }
  self::assertFalse(FacilityTypeCatalog::structure_is_compatible('general_clinic', 'hospital_network'), 'the hospital-network structure is reserved for the hospital type');
  self::assertNotSame('', FacilityTypeCatalog::structure_incompatibility_message('general_clinic', 'hospital_network'));
  self::assertTrue(FacilityTypeCatalog::structure_is_compatible('general_clinic', 'multi_doctor'));
  self::assertTrue(FacilityTypeCatalog::structure_is_compatible('other', 'medical_building'));
  // Defense in depth: resolve_flow silently ignores incompatible overrides —
  // a selected hospital ALWAYS keeps hospital_network (disclosure + ack step).
  foreach (['single','multi_doctor','medical_building','forged'] as $bad) {
   self::assertSame('hospital_network', FacilityTypeCatalog::resolve_flow('hospital', $bad), 'hospital flow can never be downgraded by structure '.$bad);
  }
  self::assertSame('multi_doctor_clinic', FacilityTypeCatalog::resolve_flow('general_clinic', 'hospital_network'));
  self::assertSame('simple_practice', FacilityTypeCatalog::resolve_flow('doctor_practice', 'single'));
  self::assertSame('medical_building', FacilityTypeCatalog::resolve_flow('other', 'medical_building'));
  self::assertSame('hospital_network', FacilityTypeCatalog::resolve_flow('hospital', ''));
 }
 public function testWizardSourceContracts(): void {
  $s = (string) file_get_contents(dirname(__DIR__,3).'/src/Admin/CpmsSetupWizard.php');
  self::assertStringContainsString('چه نوع مرکز یا مجموعه درمانی می‌خواهید راه‌اندازی کنید؟', $s);
  self::assertStringContainsString("['id' => 'facility'", $s);
  self::assertStringContainsString('cpms_wizard_facility', $s);
  self::assertStringContainsString('wp_verify_nonce', $s);
  self::assertStringContainsString('data-cpms-onboarding-flow', $s);
  self::assertStringContainsString('data-cpms-multi-clinic-blocked', $s);
  self::assertStringContainsString('function active_steps', $s);
  self::assertStringContainsString('intended_clinicians', $s);
  self::assertStringContainsString('missing_required_steps', $s);
  self::assertStringContainsString('data-cpms-inpatient-unsupported', $s);
  // No user-facing technical English labels in facility step markers
  self::assertStringContainsString('dir="rtl"', $s);
 }
}
