import React, {useEffect, useState} from 'react';
import {ProjectSwitch} from './projects.jsx';
import {PluginConnect} from './plugin.jsx';

export function Sidebar({session, projects, projectId, view, pendingCount, api, onSelectProject, onCreateProject, onConnections, onAgentReports, onNavigate, onTeam, onSignOut}) {
  const [menuOpen, setMenuOpen] = useState(false);
  useEffect(() => {
    if (!menuOpen) return;
    const closeOnEscape = event => { if (event.key === 'Escape') setMenuOpen(false); };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [menuOpen]);

  const sections = session.role === 'collector' ? ['Today', 'Leads', 'Intake'] : ['Today', 'Leads', 'Intake', 'Pipeline', 'Schedule'];
  const icons = session.role === 'collector' ? ['◷', '◎', '⇥'] : ['◷', '◎', '⇥', '▥', '▦'];
  const navigate = section => { onNavigate(section); setMenuOpen(false); };

  return <aside className="app-sidebar">
    <div className="sidebar-head">
      <div className="brand">◈ &nbsp; Fieldwork</div>
      <button className="mobile-menu-button" type="button" aria-controls="sidebar-navigation" aria-expanded={menuOpen} onClick={() => setMenuOpen(open => !open)}>
        <span aria-hidden="true">{menuOpen ? '×' : '☰'}</span>{menuOpen ? 'Close' : 'Menu'}
      </button>
    </div>
    <div id="sidebar-navigation" className={`sidebar-navigation${menuOpen ? ' is-open' : ''}`}>
      <div className="workspace">{session.workspace}<small>{location.hostname==='localhost'||location.hostname==='127.0.0.1'?'LOCAL WORKSPACE':'FIELDWORK WORKSPACE'}</small></div>
      <ProjectSwitch projects={projects} value={projectId} canCreate={['owner', 'manager'].includes(session.role)} onSelect={id => {onSelectProject(id); setMenuOpen(false);}} onCreate={async name => {await onCreateProject(name); setMenuOpen(false);}}/>
      <nav aria-label="Workspace sections">{sections.map((section, index) => <button type="button" className={view === section ? 'nav active' : 'nav'} key={section} aria-current={view === section ? 'page' : undefined} onClick={() => navigate(section)}><span aria-hidden="true">{icons[index]}</span>{section}{section === 'Today' && <em>{pendingCount}</em>}</button>)}</nav>
      {session.role === 'owner' && <button type="button" className="nav team-nav" onClick={() => {onTeam(); setMenuOpen(false);}}><span aria-hidden="true">♙</span>Team &amp; access</button>}
      {location.hostname==='localhost'||location.hostname==='127.0.0.1'?<PluginConnect api={api}/>:<button type="button" className="nav" onClick={()=>{onConnections();setMenuOpen(false)}}>Connected apps</button>}
      {session.role !== 'collector' && <button type="button" className="nav" onClick={()=>{onAgentReports();setMenuOpen(false)}}>Agent activity</button>}
      <div className="sidebar-bottom"><p><span className="status-dot"/> Fieldwork service</p><small>Communications and desktop alarms<br/>will be connected in the next phase.</small><button type="button" className="nav" onClick={() => {setMenuOpen(false); onSignOut();}}>Sign out</button></div>
    </div>
  </aside>;
}
