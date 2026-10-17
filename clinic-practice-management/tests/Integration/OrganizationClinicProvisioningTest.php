<?php
declare(strict_types=1);
namespace ClinicCore\Tests\Integration;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/** Real database integration coverage for the Organization provisioning boundary. */
final class OrganizationClinicProvisioningTest extends WP_UnitTestCase {
    private int $org=0; private int $actor=0; private string $org_status='active'; private array $members=[]; private array $clinics=[];
    protected function setUp(): void { parent::setUp(); App::migrations()->migrate(); $this->org=(int)(App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_organizations').' ORDER BY id LIMIT 1')??0); $this->org_status=(string)(App::db()->fetchValue('SELECT status FROM '.App::db()->table('cpms_organizations').' WHERE id=%d',[$this->org])??'active'); $this->actor=self::factory()->user->create(['role'=>'administrator']); }
    protected function tearDown(): void { $db=App::db(); foreach($this->clinics as $id) $db->query('DELETE FROM '.$db->table('cpms_clinics').' WHERE id=%d',[$id]); foreach($this->members as $id){$db->query('DELETE FROM '.$db->table('cpms_organization_membership_capabilities').' WHERE membership_id=%d',[$id]);$db->query('DELETE FROM '.$db->table('cpms_organization_memberships').' WHERE id=%d',[$id]);} if($this->org>0)$db->update('cpms_organizations',['status'=>$this->org_status],['id'=>$this->org]); parent::tearDown(); }
    private function manager(int $user, int $org): void { $db=App::db(); $now=$db->nowUtcSql(); $db->insert('cpms_organization_memberships',['organization_id'=>$org,'wp_user_id'=>$user,'role_key'=>'organization_manager','status'=>'active','created_by_wp_user_id'=>$user,'created_at'=>$now,'updated_at'=>$now]); $this->members[]=$db->wpdb_last_insert_id(); }
    public function testExplicitOrganizationMembershipProvisionsClinicAndPrimaryLocationWithValidAuditChain(): void {
        self::assertGreaterThan(0,$this->org); $this->manager($this->actor,$this->org);
        $r=App::organization_clinic_provisioning_service()->provision($this->actor,$this->org,'Integration Clinic','integration-clinic','Europe/London','Main'); $this->clinics[]=$r['clinic_id'];
        self::assertGreaterThan(0,$r['clinic_id']); self::assertSame($this->org,(int)App::db()->fetchValue('SELECT organization_id FROM '.App::db()->table('cpms_clinics').' WHERE id=%d',[$r['clinic_id']]));
        self::assertSame($r['clinic_id'],(int)App::db()->fetchValue('SELECT clinic_id FROM '.App::db()->table('cpms_locations').' WHERE id=%d',[$r['location_id']]));
        self::assertTrue(App::audit()->verifyChain(1000)['ok']);
    }
    public function testAdministratorWithoutExplicitMembershipAndForgedOrganizationAreDenied(): void {
        try { App::organization_clinic_provisioning_service()->provision($this->actor,$this->org,'Forged Clinic','forged-clinic','Europe/London'); self::fail('unexpected provisioning'); } catch(\RuntimeException $e) { self::assertStringContainsString('denied',$e->getMessage()); }
        try { App::organization_clinic_provisioning_service()->provision($this->actor,PHP_INT_MAX,'Forged Clinic','forged-clinic','Europe/London'); self::fail('unexpected provisioning'); } catch(\RuntimeException $e) { self::assertStringContainsString('denied',$e->getMessage()); }
    }
    public function testOrganizationDenyOverridesExplicitManagerMembershipAndSuspendedOrganizationFailsClosed(): void {
        $this->manager($this->actor,$this->org); $m=(int)App::db()->fetchValue('SELECT id FROM '.App::db()->table('cpms_organization_memberships').' WHERE organization_id=%d AND wp_user_id=%d',[$this->org,$this->actor]);
        App::db()->insert('cpms_organization_membership_capabilities',['membership_id'=>$m,'capability'=>'cpms_org_clinic_create','effect'=>'deny','created_by_wp_user_id'=>$this->actor,'created_at'=>App::db()->nowUtcSql()]);
        try { App::organization_clinic_provisioning_service()->provision($this->actor,$this->org,'Denied Clinic','denied-clinic','Europe/London'); self::fail('DENY ignored'); } catch(\RuntimeException $e) { self::assertStringContainsString('denied',$e->getMessage()); }
        App::db()->update('cpms_organizations',['status'=>'suspended'],['id'=>$this->org]);
        try { App::organization_clinic_provisioning_service()->provision($this->actor,$this->org,'Suspended Clinic','suspended-clinic','Europe/London'); self::fail('suspended org accepted'); } catch(\RuntimeException $e) { self::assertStringContainsString('denied',$e->getMessage()); }
    }
    public function testLastOrganizationManagerCannotBeRevokedAndBootstrapRemainsClosed(): void {
        $this->manager($this->actor,$this->org);
        try { App::organization_clinic_provisioning_service()->set_manager($this->actor,$this->org,$this->actor,false); self::fail('last manager removed'); } catch(\RuntimeException $e) { self::assertStringContainsString('last active',$e->getMessage()); }
        try { App::organization_clinic_provisioning_service()->bootstrap_owner($this->actor,$this->org); self::fail('bootstrap unexpectedly opened'); } catch(\RuntimeException $e) { self::assertStringContainsString('verified ownership evidence',$e->getMessage()); }
    }
}
