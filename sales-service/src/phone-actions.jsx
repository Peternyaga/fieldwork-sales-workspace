import React, {useState} from 'react';

function dialNumber(value) {
  return value.replace(/[^+\d]/g, '');
}

async function copyNumber(value) {
  if (navigator.clipboard?.writeText) {
    try { await navigator.clipboard.writeText(value); return; } catch { /* HTTP LAN origins may block Clipboard API. */ }
  }
  const input = document.createElement('textarea');
  input.value = value;
  input.style.position = 'fixed';
  input.style.opacity = '0';
  document.body.append(input);
  input.select();
  const copied = document.execCommand('copy');
  input.remove();
  if (!copied) throw new Error('Copy was blocked by this browser');
}

export function PhoneActions({phone, compact = false}) {
  const [message, setMessage] = useState('');
  if (!phone) return <span className="phone-missing">Phone not found</span>;
  return <span className={`phone-actions${compact ? ' compact' : ''}`}>
    <a href={`tel:${dialNumber(phone)}`} aria-label={`Call ${phone}`}>{phone}</a>
    <button type="button" className="phone-copy" aria-label={`Copy ${phone}`} onClick={async () => {
      try { await copyNumber(phone); setMessage('Copied'); }
      catch { setMessage('Select number to copy'); }
    }}>Copy</button>
    {message && <small role="status">{message}</small>}
  </span>;
}
