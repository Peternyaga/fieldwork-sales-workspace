<?php
declare(strict_types=1);
function applySchema(): void {
    foreach(explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql)if(trim($sql))db()->exec($sql);
    $database=db()->query('SELECT DATABASE()')->fetchColumn();
    foreach([
        ['users','role',"VARCHAR(20) NOT NULL DEFAULT 'owner'"],
        ['users','active','TINYINT(1) NOT NULL DEFAULT 1'],
        ['users','setup_pending','TINYINT(1) NOT NULL DEFAULT 0'],
        ['users','auth_version','INT NOT NULL DEFAULT 1'],
        ['leads','owner_user_id','CHAR(32) NULL'],
        ['leads','collector_user_id','CHAR(32) NULL']
    ] as [$table,$column,$type]){
        $found=query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,$table,$column])->fetchColumn();
        if(!$found)db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $type");
    }
    foreach([
        ['users','tenant_user','workspace_id,id'],
        ['leads','assigned','workspace_id,owner_user_id'],
        ['leads','submitted','workspace_id,collector_user_id']
    ] as [$table,$index,$columns]){
        $found=query('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,$table,$index])->fetchColumn();
        if(!$found)db()->exec("CREATE INDEX `$index` ON `$table` ($columns)");
    }
}
