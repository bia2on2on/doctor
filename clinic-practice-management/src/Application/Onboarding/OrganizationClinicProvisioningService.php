<?php

declare(strict_types=1);

namespace ClinicCore\Application\Onboarding;

use ClinicCore\Infrastructure\Audit\AuditLogger;
use ClinicCore\Infrastructure\Db\CpmsDb;

/** Organization-scoped Clinic creation; this authority never grants Clinic/PHI access. */
final class OrganizationClinicProvisioningService {
    public const CAPABILITY = 'cpms_org_clinic_create';
    public const MEMBERSHIP_CAPABILITY = 'cpms_org_membership_manage';
    public const AUDIT_ACTION = 'ORGANIZATION_CLINIC_PROVISIONED';

    public function __construct(private readonly CpmsDb $db, private readonly AuditLogger $audit) {}

    /** @return list<array{id:int,name:string}> */
    public function organizations_for_manager(int $actor_id): array {
        if ($actor_id <= 0) return [];
        $rows=$this->db->fetchAll('SELECT o.id,o.name FROM '.$this->db->table('cpms_organizations').' o JOIN '.$this->db->table('cpms_organization_memberships').' m ON m.organization_id=o.id WHERE m.wp_user_id=%d AND m.status=%s AND m.role_key=%s AND o.status=%s ORDER BY o.name,o.id',[$actor_id,'active','organization_manager','active']);
        $out=[]; foreach($rows as $r) if($this->authorized($actor_id,(int)$r['id'],false)) $out[]=['id'=>(int)$r['id'],'name'=>(string)$r['name']];
        return $out;
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function clinics_for_manager(int $actor_id): array {
        if ($actor_id <= 0) { return []; }
        $members=$this->db->fetchAll('SELECT organization_id FROM '.$this->db->table('cpms_organization_memberships').' WHERE wp_user_id=%d AND status=%s AND role_key=%s ORDER BY organization_id',[$actor_id,'active','organization_manager']);
        $out=[];
        foreach ($members as $m) {
            $org=(int)$m['organization_id'];
            if (!$this->authorized($actor_id,$org,false)) continue;
            foreach ($this->db->fetchAll('SELECT id,name,slug FROM '.$this->db->table('cpms_clinics').' WHERE organization_id=%d ORDER BY name,id',[$org]) as $c) $out[]=['id'=>(int)$c['id'],'name'=>(string)$c['name'],'slug'=>(string)$c['slug']];
        }
        return $out;
    }

    /** @return array{clinic_id:int,location_id:int,audit_id:int} */
    public function provision(int $actor_id,int $organization_id,string $name,string $slug,string $timezone,?string $location_name=null,?string $address=null,?string $phone=null): array {
        if ($actor_id<=0 || $organization_id<=0) throw new \InvalidArgumentException('invalid authorization context');
        $name=trim($name); $slug=strtolower(trim($slug));
        if ($name==='' || mb_strlen($name)>190 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug) || strlen($slug)>190) throw new \InvalidArgumentException('invalid clinic attributes');
        if (!in_array($timezone,timezone_identifiers_list(),true)) throw new \InvalidArgumentException('invalid timezone');
        $location_name=trim((string)($location_name ?? $name));
        if ($location_name==='' || mb_strlen($location_name)>190) throw new \InvalidArgumentException('invalid location name');
        foreach (['address'=>$address,'phone'=>$phone] as $k=>$v) if ($v!==null && mb_strlen($v)>($k==='address'?255:32)) throw new \InvalidArgumentException('invalid '.$k);
        return $this->db->transactional(function() use($actor_id,$organization_id,$name,$slug,$timezone,$location_name,$address,$phone): array {
            $org=$this->db->fetchRowForUpdate('SELECT id,status FROM '.$this->db->table('cpms_organizations').' WHERE id=%d LIMIT 1',[$organization_id]);
            if ($org===null || (string)$org['status']!=='active' || !$this->authorized($actor_id,$organization_id,true)) throw new \RuntimeException('organization provisioning denied');
            if ($this->db->fetchValue('SELECT id FROM '.$this->db->table('cpms_clinics').' WHERE slug=%s LIMIT 1',[$slug])!==null) throw new \RuntimeException('clinic slug unavailable');
            $now=$this->db->nowUtcSql();
            if (!$this->db->insert('cpms_clinics',['organization_id'=>$organization_id,'name'=>$name,'slug'=>$slug,'timezone'=>$timezone,'address'=>$address,'phone'=>$phone,'created_at'=>$now,'updated_at'=>$now])) throw new \RuntimeException('clinic creation failed');
            $clinic_id=$this->db->wpdb_last_insert_id(); if ($clinic_id<=0) throw new \RuntimeException('clinic id unavailable');
            if (!$this->db->insert('cpms_locations',['clinic_id'=>$clinic_id,'name'=>$location_name,'slug'=>'main','address'=>$address,'phone'=>$phone,'timezone'=>$timezone,'is_primary'=>1,'is_active'=>1,'created_at'=>$now,'updated_at'=>$now])) throw new \RuntimeException('primary location creation failed');
            $location_id=$this->db->wpdb_last_insert_id(); if ($location_id<=0) throw new \RuntimeException('location id unavailable');
            $audit_id=$this->audit->log(self::AUDIT_ACTION,['wp_user_id'=>$actor_id,'role'=>'organization_manager'],'clinic',(int)$clinic_id,null,null,['organization_id'=>$organization_id,'slug'=>$slug,'timezone'=>$timezone],['location_id'=>$location_id],(int)$clinic_id);
            return ['clinic_id'=>(int)$clinic_id,'location_id'=>(int)$location_id,'audit_id'=>$audit_id];
        });
    }

    /** Explicit organization-scoped manager grant/revocation; last active manager is protected. */
    public function set_manager(int $actor_id,int $organization_id,int $target_user_id,bool $active): int {
        if($actor_id<=0||$organization_id<=0||$target_user_id<=0||get_userdata($target_user_id)===false) throw new \InvalidArgumentException('invalid membership subject');
        return $this->db->transactional(function() use($actor_id,$organization_id,$target_user_id,$active): int {
            $org=$this->db->fetchRowForUpdate('SELECT id,status FROM '.$this->db->table('cpms_organizations').' WHERE id=%d LIMIT 1',[$organization_id]);
            if($org===null||$org['status']!=='active'||!$this->authorized($actor_id,$organization_id,true,self::MEMBERSHIP_CAPABILITY)) throw new \RuntimeException('organization membership management denied');
            $table=$this->db->table('cpms_organization_memberships');
            $target=$this->db->fetchRowForUpdate('SELECT id,status,role_key FROM '.$table.' WHERE organization_id=%d AND wp_user_id=%d LIMIT 1',[$organization_id,$target_user_id]);
            if(!$active && $target!==null && $target['status']==='active' && $target['role_key']==='organization_manager') {
                $n=(int)$this->db->fetchValue('SELECT COUNT(*) FROM '.$table.' WHERE organization_id=%d AND status=%s AND role_key=%s',[$organization_id,'active','organization_manager']);
                if($n<=1) throw new \RuntimeException('cannot remove last active organization manager');
            }
            $now=$this->db->nowUtcSql();
            if($active) {
                if($target===null) {
                    if(!$this->db->insert('cpms_organization_memberships',['organization_id'=>$organization_id,'wp_user_id'=>$target_user_id,'role_key'=>'organization_manager','status'=>'active','created_by_wp_user_id'=>$actor_id,'created_at'=>$now,'updated_at'=>$now])) throw new \RuntimeException('organization membership grant failed');
                    $mid=$this->db->wpdb_last_insert_id(); if($mid<=0) throw new \RuntimeException('organization membership id unavailable');
                } else {
                    $mid=(int)$target['id'];
                    if($this->db->update('cpms_organization_memberships',['role_key'=>'organization_manager','status'=>'active','updated_at'=>$now],['id'=>$mid])===false) throw new \RuntimeException('organization membership grant failed');
                }
            } else { if($target===null) throw new \RuntimeException('organization membership not found'); $mid=(int)$target['id']; if($this->db->update('cpms_organization_memberships',['status'=>'suspended','updated_at'=>$now],['id'=>$mid])===false) throw new \RuntimeException('organization membership revoke failed'); }
            $this->audit->log($active?'ORGANIZATION_MANAGER_GRANTED':'ORGANIZATION_MANAGER_REVOKED',['wp_user_id'=>$actor_id,'role'=>'organization_manager'],'organization',$organization_id,null,null,null,['target_wp_user_id'=>$target_user_id,'active'=>$active],null,true);
            return (int)$mid;
        });
    }

    /** Explicit installation bootstrap intentionally remains closed: no trusted proof of Organization ownership exists. */
    public function bootstrap_owner(int $actor_id,int $organization_id): never {
        throw new \RuntimeException('organization ownership bootstrap unavailable: verified ownership evidence required');
    }

    private function authorized(int $actor_id,int $organization_id,bool $lock,string $permission=self::CAPABILITY): bool {
        $read=$lock?'fetchRowForUpdate':'fetchRow';
        $m=$this->db->$read('SELECT id,role_key,status FROM '.$this->db->table('cpms_organization_memberships').' WHERE organization_id=%d AND wp_user_id=%d LIMIT 1',[$organization_id,$actor_id]);
        if ($m===null || $m['status']!=='active' || $m['role_key']!=='organization_manager') return false;
        $read_all=$lock?'fetchAllForUpdate':'fetchAll';
        $rows=$this->db->$read_all('SELECT capability,effect FROM '.$this->db->table('cpms_organization_membership_capabilities').' WHERE membership_id=%d',[(int)$m['id']]);
        foreach($rows as $r) if($r['capability']===$permission && $r['effect']==='deny') return false;
        foreach($rows as $r) if($r['capability']===$permission && $r['effect']==='grant') return true;
        // Role preset is Organization-scoped only. Clinic AuthorizationService remains untouched.
        return true;
    }
}
