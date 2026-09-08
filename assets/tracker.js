/** B-Metrics: one asynchronous, cookieless pageview per page load. */
(() => {
    'use strict';
    const script = document.currentScript;
    if (!script) return;
    const endpoint = new URL('track.php', script.src.replace(/assets\/tracker\.js(?:\?.*)?$/, '')).href;
    // Installing the same snippet twice must not double-count this page load.
    window.__bmetricsSent = window.__bmetricsSent || Object.create(null);
    if (window.__bmetricsSent[endpoint]) return;
    window.__bmetricsSent[endpoint] = true;
    const send = () => {
        try {
            const bytes = new Uint8Array(16);
            let eventId;
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
                eventId = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
            } else {
                eventId = Date.now().toString(36) + Math.random().toString(36).slice(2).padEnd(16, '0');
            }
            const payload = JSON.stringify({
                url: location.href, referrer: document.referrer || '',
                language: navigator.language || '',
                screen_width: Math.max(0, Math.round(screen.width || 0)),
                screen_height: Math.max(0, Math.round(screen.height || 0)), event_id: eventId
            });
            // Credential-free fetch is preferred; text/plain avoids a CORS preflight.
            // Beacon is only a fallback when fetch is unavailable, not an automatic retry.
            if (typeof window.fetch === 'function') {
                window.fetch(endpoint, {method: 'POST', mode: 'cors', credentials: 'omit',
                    headers: {'Content-Type': 'text/plain;charset=UTF-8'},
                    body: payload, keepalive: true, cache: 'no-store'}).catch(() => {});
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([payload], {type: 'text/plain;charset=UTF-8'}));
            }
        } catch (error) {
            // Analytics must never break the monitored page or expose its data in logs.
        }
    };
    // Defer prerendered documents until activation, avoiding phantom pageviews.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', send, {once: true});
    } else {
        send();
    }
})();
