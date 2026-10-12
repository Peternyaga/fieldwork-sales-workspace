CREATE TABLE IF NOT EXISTS workspaces (id CHAR(32) PRIMARY KEY, name VARCHAR(150) NOT NULL, timezone VARCHAR(80) NOT NULL DEFAULT 'Africa/Nairobi') ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS users (id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, name VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'owner', active TINYINT(1) NOT NULL DEFAULT 1, setup_pending TINYINT(1) NOT NULL DEFAULT 0, auth_version INT NOT NULL DEFAULT 1, UNIQUE KEY tenant_user(workspace_id,id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS account_tokens (id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, purpose VARCHAR(16) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_by CHAR(32) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY account_pending(workspace_id,user_id,purpose,used_at), FOREIGN KEY(workspace_id,user_id) REFERENCES users(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS plugin_tokens (id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, user_id CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, auth_version INT NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY plugin_user(workspace_id,user_id), FOREIGN KEY(workspace_id,user_id) REFERENCES users(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS projects (id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, name VARCHAR(150) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY tenant_project(workspace_id,id), UNIQUE KEY project_name(workspace_id,name), FOREIGN KEY(workspace_id) REFERENCES workspaces(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS leads (
 id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, project_id CHAR(32) NULL, name VARCHAR(150) NOT NULL, organisation VARCHAR(150) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '', phone VARCHAR(50) NOT NULL DEFAULT '', source VARCHAR(150) NOT NULL DEFAULT '',
 offering VARCHAR(150) NOT NULL DEFAULT '', owner VARCHAR(150) NOT NULL DEFAULT '', collector VARCHAR(150) NOT NULL DEFAULT '', owner_user_id CHAR(32) NULL, collector_user_id CHAR(32) NULL,
 stage VARCHAR(30) NOT NULL DEFAULT 'New', notes TEXT NOT NULL, version INT NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_lead(workspace_id,id), KEY lookup_email(workspace_id,email), KEY lookup_phone(workspace_id,phone), KEY assigned(workspace_id,owner_user_id), KEY submitted(workspace_id,collector_user_id), KEY lead_project(workspace_id,project_id), FOREIGN KEY(workspace_id) REFERENCES workspaces(id), CONSTRAINT leads_project_fk FOREIGN KEY(workspace_id,project_id) REFERENCES projects(workspace_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS organisations (
 id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, name VARCHAR(150) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_organisation(workspace_id,id), KEY organisation_name(workspace_id,name),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS contacts (
 id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, organisation_id CHAR(32) NULL,
 name VARCHAR(150) NOT NULL, email VARCHAR(254) NOT NULL DEFAULT '', phone VARCHAR(50) NOT NULL DEFAULT '',
 source_lead_id CHAR(32) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_contact(workspace_id,id), UNIQUE KEY contact_source(workspace_id,source_lead_id),
 KEY contact_email(workspace_id,email), KEY contact_phone(workspace_id,phone),
 FOREIGN KEY(workspace_id) REFERENCES workspaces(id),
 FOREIGN KEY(workspace_id,organisation_id) REFERENCES organisations(workspace_id,id),
 FOREIGN KEY(workspace_id,source_lead_id) REFERENCES leads(workspace_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS opportunities (
 id CHAR(32) PRIMARY KEY, workspace_id CHAR(32) NOT NULL, contact_id CHAR(32) NOT NULL,
 offering VARCHAR(150) NOT NULL, stage VARCHAR(30) NOT NULL DEFAULT 'New', version INT NOT NULL DEFAULT 1, request_key VARCHAR(80) NULL,
 source_lead_id CHAR(32) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY tenant_opportunity(workspace_id,id), UNIQUE KEY opportunity_source(workspace_id,source_lead_id),
 UNIQUE KEY opportunity_request(workspace_id,request_key),
 KEY contact_opportunities(workspace_id,contact_id),
 FOREIGN KEY(workspace_id,contact_id) REFERENCES contacts(workspace_id,id),
 FOREIGN KEY(workspace_id,source_lead_id) REFERENCES leads(workspace_id,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS activities (id CHAR(32) PRIMARY KEY,workspace_id CHAR(32) NOT NULL,lead_id CHAR(32) NOT NULL,kind VARCHAR(40) NOT NULL,body TEXT NOT NULL,actor VARCHAR(150) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY history(workspace_id,lead_id,created_at),FOREIGN KEY(workspace_id,lead_id) REFERENCES leads(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS tasks (id CHAR(32) PRIMARY KEY,workspace_id CHAR(32) NOT NULL,lead_id CHAR(32) NOT NULL,title VARCHAR(250) NOT NULL,owner VARCHAR(150) NOT NULL,due_at DATETIME NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY due_tasks(workspace_id,status,due_at),FOREIGN KEY(workspace_id,lead_id) REFERENCES leads(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS agent_work_items (id CHAR(32) PRIMARY KEY,workspace_id CHAR(32) NOT NULL,lead_id CHAR(32) NOT NULL,source_activity_id CHAR(32) NOT NULL,parent_work_id CHAR(32) NULL,kind VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,completed_at DATETIME NULL,result TEXT NULL,UNIQUE KEY source_once(workspace_id,source_activity_id),UNIQUE KEY reversal_once(workspace_id,parent_work_id),KEY pending_work(workspace_id,status,created_at),FOREIGN KEY(workspace_id,lead_id) REFERENCES leads(workspace_id,id),FOREIGN KEY(source_activity_id) REFERENCES activities(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS agent_poll_pairings (id CHAR(32) PRIMARY KEY,verifier_hash CHAR(64) NOT NULL,device_name VARCHAR(120) NOT NULL,workspace_id CHAR(32) NULL,user_id CHAR(32) NULL,approved_at DATETIME NULL,used_at DATETIME NULL,expires_at DATETIME NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY pairing_expiry(expires_at)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS agent_poll_tokens (id CHAR(32) PRIMARY KEY,workspace_id CHAR(32) NOT NULL,user_id CHAR(32) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,auth_version INT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,last_used_at DATETIME NULL,revoked_at DATETIME NULL,KEY poll_user(workspace_id,user_id),FOREIGN KEY(workspace_id,user_id) REFERENCES users(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS oauth_clients (id CHAR(32) PRIMARY KEY,name VARCHAR(150) NOT NULL,redirect_uris TEXT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS oauth_rate_limits (bucket CHAR(64) PRIMARY KEY,window_start DATETIME NOT NULL,attempts INT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS oauth_codes (code_hash CHAR(64) PRIMARY KEY,client_id CHAR(32) NOT NULL,user_id CHAR(32) NOT NULL,workspace_id CHAR(32) NOT NULL,redirect_uri VARCHAR(1024) NOT NULL,resource VARCHAR(512) NOT NULL,scope VARCHAR(250) NOT NULL,code_challenge CHAR(43) NOT NULL,expires_at DATETIME NOT NULL,used_at DATETIME NULL,FOREIGN KEY(client_id) REFERENCES oauth_clients(id),FOREIGN KEY(workspace_id,user_id) REFERENCES users(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS oauth_grants (id CHAR(32) PRIMARY KEY,client_id CHAR(32) NOT NULL,user_id CHAR(32) NOT NULL,workspace_id CHAR(32) NOT NULL,resource VARCHAR(512) NOT NULL,scope VARCHAR(250) NOT NULL,access_hash CHAR(64) NOT NULL UNIQUE,refresh_hash CHAR(64) NOT NULL UNIQUE,access_expires_at DATETIME NOT NULL,refresh_expires_at DATETIME NOT NULL,auth_version INT NOT NULL,revoked_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY oauth_user(workspace_id,user_id),FOREIGN KEY(client_id) REFERENCES oauth_clients(id),FOREIGN KEY(workspace_id,user_id) REFERENCES users(workspace_id,id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS requests (workspace_id CHAR(32) NOT NULL,request_key VARCHAR(80) NOT NULL,payload_hash CHAR(64) NOT NULL,lead_id CHAR(32) NOT NULL,PRIMARY KEY(workspace_id,request_key)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS assignment_events (id CHAR(32) PRIMARY KEY,workspace_id CHAR(32) NOT NULL,lead_id CHAR(32) NOT NULL,from_user_id CHAR(32) NULL,to_user_id CHAR(32) NOT NULL,assigned_by CHAR(32) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY assignment_history(workspace_id,lead_id,created_at),FOREIGN KEY(workspace_id,lead_id) REFERENCES leads(workspace_id,id)) ENGINE=InnoDB;
