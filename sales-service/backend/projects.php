<?php
declare(strict_types=1);

function defaultProject(string $workspaceId): string {
    $project=query('SELECT id FROM projects WHERE workspace_id=? AND name=?',[$workspaceId,'General'])->fetchColumn();
    if($project)return $project;
    $id=uid();
    query('INSERT IGNORE INTO projects (id,workspace_id,name) VALUES (?,?,?)',[$id,$workspaceId,'General']);
    return (string)query('SELECT id FROM projects WHERE workspace_id=? AND name=?',[$workspaceId,'General'])->fetchColumn();
}

function resolveProject(?string $id,string $workspaceId): string {
    if($id===null||$id==='')return defaultProject($workspaceId);
    if(!preg_match('/^[a-f0-9]{32}$/',$id))fail('Invalid project');
    $found=query('SELECT id FROM projects WHERE workspace_id=? AND id=?',[$workspaceId,$id])->fetchColumn();
    if(!$found)fail('Project not found',404);
    return $id;
}

function requestedProject(): ?string {
    $id=$_GET['project']??null;
    if($id===null||$id==='')return null;
    if(!is_string($id))fail('Invalid project');
    return resolveProject($id,workspace());
}
