import React from 'react';

export function ResearchBrief({activities}) {
  const record = activities?.find(activity => activity.kind === 'contact_research');
  if (!record) return null;
  let research;
  try { research = JSON.parse(record.body); } catch { return null; }
  const source = /^https:\/\//.test(research.source || '') ? research.source : null;
  const personSource = /^https:\/\//.test(research.person_source || '') ? research.person_source : null;
  return <section className="research-brief" aria-label="Public-source contact research">
    <span className="eyebrow">HISTORICAL RESEARCH · UNVERIFIED</span>
    <strong>{research.person ? `${research.person} · ${research.role}` : research.target_role}</strong>
    <p>{research.person ? 'Publicly named contact; confirm their current role and whether they approve website work.' : 'No current decision-maker was reliably identified. Ask the person answering who approves website or marketing work.'}</p>
    <small>This older research may be superseded by later notes. Confirm identity and contact details. {source && <a href={source} target="_blank" rel="noopener noreferrer">Number source ↗</a>}{personSource && personSource !== source && <> · <a href={personSource} target="_blank" rel="noopener noreferrer">Person source ↗</a></>}</small>
  </section>;
}
