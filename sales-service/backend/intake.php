<?php
declare(strict_types=1);
require_once __DIR__.'/lead-records.php';

function leadValues(array $data): array {
    $values=[];foreach(['name'=>150,'organisation'=>150,'email'=>254,'phone'=>50,'source'=>150,'offering'=>150,'owner'=>150,'collector'=>150,'notes'=>10000] as $key=>$max)$values[$key]=textField($data,$key,$max,$key==='name');
    $values['email']=strtolower($values['email']);
    if($values['email']&&!filter_var($values['email'],FILTER_VALIDATE_EMAIL))fail('Enter a valid email');
    $values['phone']=preg_replace('/[\s().-]/','',$values['phone']);
    if($values['phone']&&!preg_match('/^\+?[0-9]{7,15}$/',$values['phone']))fail('Enter a phone number with country code');
    return $values;
}
function matchingLeads(array $values): array {
    if($values['email']===''&&$values['phone']==='')return [];
    return query("SELECT id,name,organisation FROM leads WHERE workspace_id=? AND ((email<>'' AND email=?) OR (phone<>'' AND phone=?))",[workspace(),$values['email'],$values['phone']])->fetchAll();
}
function createLead(array $values,string $request): array {
    $hash=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));db()->beginTransaction();
    try{query('SELECT id FROM workspaces WHERE id=? FOR UPDATE',[workspace()]);
        $prior=query('SELECT * FROM requests WHERE workspace_id=? AND request_key=?',[workspace(),$request])->fetch();
        if($prior){if($prior['payload_hash']!==$hash)fail('Request key already used for different data',409);$out=['id'=>$prior['lead_id'],'replayed'=>true];}
        elseif($matches=matchingLeads($values))$out=['error'=>'A matching contact already exists. Review it before adding another.','matches'=>role()==='collector'?array_fill(0,count($matches),['existing'=>true]):$matches];
        else{$id=uid();$submitterId=$_SESSION['user_id'];query('INSERT INTO leads (id,workspace_id,name,organisation,email,phone,source,offering,owner,collector,notes,collector_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',array_merge([$id,workspace()],array_values($values),[$submitterId]));materialiseLeadRecords(array_merge(['id'=>$id,'workspace_id'=>workspace(),'stage'=>'New'],$values));audit($id,'created',['source'=>$values['source']]);query('INSERT INTO requests VALUES (?,?,?,?)',[workspace(),$request,$hash,$id]);$out=['id'=>$id];}
        db()->commit();return $out;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function csvRows(string $csv): array {
    if(strlen($csv)>900000)fail('CSV file exceeds 900 KB');
    $stream=fopen('php://temp','w+');fwrite($stream,$csv);rewind($stream);
    $header=fgetcsv($stream,0,',','"','\\');if(!$header)fail('CSV has no header');
    $header=array_map(fn($v)=>strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',$v))),$header);
    if(count($header)!==count(array_unique($header)))fail('CSV has duplicate columns');
    if(!in_array('name',$header,true))fail('CSV needs a name column');
    $allowed=['name','organisation','email','phone','source','offering','owner','collector','notes'];$rows=[];$seen=[];
    while(($raw=fgetcsv($stream,0,',','"','\\'))!==false){
        if($raw===[null]||count($raw)===1&&trim((string)$raw[0])==='')continue;
        if(count($rows)>=1000)fail('CSV limit is 1000 rows');$line=count($rows)+2;
        if(count($raw)!==count($header)){$rows[]=['line'=>$line,'status'=>'invalid','reason'=>'Column count differs from header'];continue;}
        $mapped=[];foreach($header as $i=>$key)if(in_array($key,$allowed,true))$mapped[$key]=$raw[$i];
        try{$values=leadValues($mapped);$status='ready';$reason='';$keys=[];
            if($values['email']!=='')$keys[]='email:'.$values['email'];
            if($values['phone']!=='')$keys[]='phone:'.$values['phone'];
            if(array_filter($keys,fn($key)=>isset($seen[$key]))){$status='duplicate_in_file';$reason='Same email or phone already appears in this file';}
            elseif(matchingLeads($values)){$status='duplicate_existing';$reason='Email or phone matches an existing lead';}
            foreach($keys as $key)$seen[$key]=true;
            $rows[]=['line'=>$line,'status'=>$status,'reason'=>$reason,'values'=>$values];
        }catch(RuntimeException $e){$rows[]=['line'=>$line,'status'=>'invalid','reason'=>$e->getMessage()];}
    }fclose($stream);return $rows;
}
