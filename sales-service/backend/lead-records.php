<?php
declare(strict_types=1);

// Keep the intake record while introducing reusable people and deal records.
// Source IDs make the migration repeatable and preserve existing lead links.
function materialiseLeadRecords(array $lead): void {
    $workspace=$lead['workspace_id'];
    $leadId=$lead['id'];
    $existing=query('SELECT id FROM contacts WHERE workspace_id=? AND source_lead_id=?',[$workspace,$leadId])->fetch();
    if($existing)return;

    $organisationId=null;
    if($lead['organisation']!==''){
        $organisationId=uid();
        query('INSERT INTO organisations (id,workspace_id,name) VALUES (?,?,?)',[$organisationId,$workspace,$lead['organisation']]);
    }
    query('INSERT INTO contacts (id,workspace_id,organisation_id,name,email,phone,source_lead_id) VALUES (?,?,?,?,?,?,?)',
        [$leadId,$workspace,$organisationId,$lead['name'],$lead['email'],$lead['phone'],$leadId]);
    if($lead['offering']!==''){
        query('INSERT INTO opportunities (id,workspace_id,contact_id,offering,stage,source_lead_id) VALUES (?,?,?,?,?,?)',
            [$leadId,$workspace,$leadId,$lead['offering'],$lead['stage'],$leadId]);
    }
}
