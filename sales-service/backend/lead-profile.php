<?php
declare(strict_types=1);
require_once __DIR__.'/lead-records.php';

function profileFields(): array {
    return ['name'=>150,'organisation'=>150,'phone'=>50,'email'=>254,'website'=>500,'facebook'=>500,'instagram'=>500,'linkedin'=>500,'contact_role'=>150,'location'=>250,'source'=>150];
}
function profileValue(string $field,mixed $value): string {
    if(!isset(profileFields()[$field])||!is_string($value))fail('Unsupported profile field or value',422);
    $value=trim($value);
    if(mb_strlen($value)>profileFields()[$field]||preg_match('/[\x00-\x1F\x7F]/',$value)||($field==='name'&&$value===''))fail('Check '.$field,422);
    if($value!==''&&$field==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL))fail('Invalid email',422);
    if($value!==''&&$field==='phone'&&!preg_match('/^\+?[0-9 ()-]{5,80}$/',$value))fail('Invalid phone',422);
    if($value!==''&&in_array($field,['website','facebook','instagram','linkedin'],true)){
        if(!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($value,PHP_URL_SCHEME)??''),['http','https'],true)||parse_url($value,PHP_URL_USER)!==null||parse_url($value,PHP_URL_PASS)!==null)fail('Use an http(s) URL without credentials',422);
    }
    return $value;
}
function editLeadProfile(string $id,array $args): array {
    requireRole(['owner','manager','salesperson']);
    foreach(array_keys($args) as $key)if(!in_array($key,['version','changes','provenance','source_activity_id','evidence_type','source_url'],true))fail('Unexpected argument',422);
    $version=$args['version']??null;$changes=$args['changes']??null;
    if(!is_int($version)||$version<1||!is_array($changes)||!count($changes))fail('Supply current version and profile changes',422);
    $provenance=textField($args,'provenance',2000,true);
    $evidenceType=textField($args,'evidence_type',30)?:'user_supplied';
    if(!in_array($evidenceType,['user_supplied','public_source'],true))fail('Invalid evidence type',422);
    $sourceUrl=textField($args,'source_url',1000);
    if($evidenceType==='public_source'&&$sourceUrl==='')fail('Public-source edits require a source URL',422);
    if($sourceUrl!==''&&(!filter_var($sourceUrl,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($sourceUrl,PHP_URL_SCHEME)??''),['http','https'],true)||parse_url($sourceUrl,PHP_URL_USER)!==null||parse_url($sourceUrl,PHP_URL_PASS)!==null))fail('Use an http(s) source URL without credentials',422);
    $values=[];foreach($changes as $field=>$value)$values[$field]=profileValue((string)$field,$value);
    $source=$args['source_activity_id']??null;
    if($source!==null&&(!is_string($source)||!preg_match('/^[a-f0-9]{32}$/',$source)))fail('Invalid source activity',422);
    db()->beginTransaction();
    try {
        $lead=query('SELECT * FROM leads WHERE workspace_id=? AND id=? FOR UPDATE',[workspace(),$id])->fetch();
        if(!$lead||!canAccessLead($lead))fail('Lead not found',404);
        if((int)$lead['version']!==$version)fail('This lead changed. Reload before editing.',409);
        if($source!==null&&!query('SELECT id FROM activities WHERE workspace_id=? AND lead_id=? AND id=?',[workspace(),$id,$source])->fetch())fail('Source activity not found',404);
        $delta=[];foreach($values as $field=>$value)if(($lead[$field]??'')!==$value)$delta[$field]=['from'=>$lead[$field]??'','to'=>$value];
        if(!$delta){db()->commit();return ['ok'=>true,'version'=>$version,'changed'=>false];}
        materialiseLeadRecords($lead);
        $sets=[];$params=[];foreach($delta as $field=>$change){$sets[]="`$field`=?";$params[]=$change['to'];}
        query('UPDATE leads SET '.implode(',',$sets).',version=version+1 WHERE workspace_id=? AND id=?',array_merge($params,[workspace(),$id]));
        query('UPDATE contacts SET name=?,email=?,phone=? WHERE workspace_id=? AND source_lead_id=?',[$values['name']??$lead['name'],$values['email']??$lead['email'],$values['phone']??$lead['phone'],workspace(),$id]);
        if(isset($delta['organisation'])){
            // Give this intake contact its own organisation; never rename a shared organisation.
            $contact=query('SELECT id,organisation_id FROM contacts WHERE workspace_id=? AND source_lead_id=?',[workspace(),$id])->fetch();
            if($contact){
                $organisationId=null;
                if($values['organisation']!==''){
                    $organisationId=uid();query('INSERT INTO organisations (id,workspace_id,name) VALUES (?,?,?)',[$organisationId,workspace(),$values['organisation']]);
                }
                query('UPDATE contacts SET organisation_id=? WHERE workspace_id=? AND id=?',[$organisationId,workspace(),$contact['id']]);
            }
        }
        audit($id,'profile_updated',['changes'=>$delta,'provenance'=>$provenance,'source_activity_id'=>$source,'evidence_type'=>$evidenceType,'source_url'=>$sourceUrl?:null,'verification'=>$evidenceType==='public_source'?'Public source; current accuracy not independently confirmed':'User-supplied; not independently verified','actor_user_id'=>$_SESSION['user_id']]);
        db()->commit();return ['ok'=>true,'version'=>$version+1,'changed'=>true];
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
