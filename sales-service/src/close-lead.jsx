import React, {useState} from 'react';

export function CloseLead({lead, busy, onClose}) {
  const [expanded, setExpanded] = useState(false);
  const [reason, setReason] = useState('');
  if (lead.stage === 'Lost') return <p className="closed-lead-note">This lead is closed. Its remaining follow-ups have been cancelled. Change its stage if you decide to reopen it.</p>;
  return <section className="close-lead">
    {!expanded ? <button type="button" className="secondary" onClick={() => setExpanded(true)}>Close lead</button> : <form onSubmit={event => {event.preventDefault(); onClose(reason);}}>
      <h3>Close this lead?</h3>
      <p>Record why it is closed. All pending follow-ups for this lead will be cancelled; completed work stays in its history.</p>
      <label className="field"><span>Reason</span><textarea value={reason} onChange={event => setReason(event.target.value)} maxLength="1000" rows="2" placeholder="They already have a website" required/></label>
      <div className="form-actions"><button type="button" className="secondary" onClick={() => setExpanded(false)}>Keep open</button><button disabled={busy || !reason.trim()}>Close lead and cancel follow-ups</button></div>
    </form>}
  </section>;
}
