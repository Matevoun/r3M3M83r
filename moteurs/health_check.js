/**
 * ============================================================================
 * r3M3M83r/moteurs/health_check.js
 * ============================================================================
 * Test réel et automatique au chargement de la page.
 */

async function r3CheckProvidersHealth(statusBarId = 'llm-status-bar', forceProbe = false) {
    const statusBar = document.getElementById(statusBarId);
    if (!statusBar) return;

    statusBar.innerHTML = '<span style="color: #666;">Test réel des moteurs au chargement ...</span>';

    try {
        const url = (typeof forceProbe !== 'undefined' && forceProbe) ? '../moteurs/llm-info?probe=1' : '../moteurs/llm-info';
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error("Erreur HTTP " + res.status);
        const data = await res.json();

        let html = '';
        const apiKeys = data.apiKeysStatus || {};
        const runtimes = data.enginesRuntimeStatus || {};
        const engines = Object.keys(apiKeys);

        if (engines.length === 0) {
            statusBar.innerHTML = '<span style="color: #e74c3c;">Aucun moteur configuré</span>';
            return;
        }

        engines.forEach(eng => {
            const hasKey = apiKeys[eng] === 'present';
            const rt = runtimes[eng] || { status: 'unknown', code: null };

            let color = '#e74c3c';
            let keyStatus = 'Injoignable / Down';

            if (!hasKey) {
                color = '#e74c3c';
                keyStatus = 'Clef absente dans .env';
            } else if (rt.status === 'success') {
                color = '#2ecc71';
                keyStatus = 'Opérationnel';
            } else if (rt.status === 'error') {
                color = '#e74c3c';
                keyStatus = `En erreur (HTTP ${rt.code})`;
            }

            html += `<span style="display: inline-flex; align-items: center; gap: 4px; margin-right: 12px;" title="${eng.toUpperCase()} : ${keyStatus}">
                        <span style="height: 8px; width: 8px; background-color: ${color}; border-radius: 50%; display: inline-block;"></span>
                        <span style="color: #333; font-weight: 500;">${eng.charAt(0).toUpperCase() + eng.slice(1)}</span>
                     </span>`;
        });

        statusBar.innerHTML = html;

    } catch (e) {
        statusBar.innerHTML = '<span style="color: #e74c3c;">Service moteurs injoignable (Node down)</span>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    r3CheckProvidersHealth();

    const razBtn = document.querySelector('#raz-button-id-or-class, button[onclick*="raz"], #raz, #raz-btn');
    if (razBtn) {
        razBtn.addEventListener('click', () => {
            setTimeout(function () { r3CheckProvidersHealth('llm-status-bar', true); }, 400);
        });
    }
});