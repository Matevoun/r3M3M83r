/**
 * ============================================================================
 * r3M3M83r/moteurs/health_check.js
 * ============================================================================
 * Test réel et automatique au chargement de la page.
 */

async function r3CheckProvidersHealth(statusBarId = 'llm-status-bar', forceProbe = false) {
    const statusBar = document.getElementById(statusBarId);
    if (!statusBar) return;

    if (!window.r3IsCoolingDown) {
        statusBar.innerHTML = '<span style="color: #666;">Test réel des moteurs au chargement ...</span>';
    }

    try {
        const url = (typeof forceProbe !== 'undefined' && forceProbe) ? '../moteurs/llm-info?probe=1' : '../moteurs/llm-info';
        const res = await fetch(url, { cache: 'no-store' });
        if (!res.ok) throw new Error("Erreur HTTP " + res.status);
        const data = await res.json();

        function renderStatus() {
            const apiKeys = data.apiKeysStatus || {};
            const runtimes = data.enginesRuntimeStatus || {};
            const engines = Object.keys(apiKeys);
            let currentlyCooling = false;

            if (engines.length === 0) {
                statusBar.innerHTML = '<span style="color: #e74c3c;">Aucun moteur configuré</span>';
                return;
            }

            // Génération de la structure HTML UNE SEULE FOIS pour ne pas casser le hover de la souris
            if (statusBar.children.length !== engines.length) {
                let html = '';
                engines.forEach(eng => {
                    html += `<span id="llm-badge-${eng}" style="display: inline-flex; align-items: center; gap: 4px; margin-right: 12px; cursor: help;">
                                <span class="llm-dot" style="height: 8px; width: 8px; border-radius: 50%; display: inline-block;"></span>
                                <span class="llm-name" style="color: #333;">${eng.charAt(0).toUpperCase() + eng.slice(1)}</span>
                             </span>`;
                });
                statusBar.innerHTML = html;
            }

            // Mise à jour douce des propriétés (couleur, tooltip, texte)
            engines.forEach(eng => {
                const badge = document.getElementById(`llm-badge-${eng}`);
                if (!badge) return;

                const dot = badge.querySelector('.llm-dot');
                const nameLabel = badge.querySelector('.llm-name');

                const hasKey = apiKeys[eng] === 'present';
                const rt = runtimes[eng] || { status: 'unknown', code: null, cooldownUntil: 0 };
                const isActiveEngine = data.engineName && eng.toLowerCase() === data.engineName.toLowerCase();

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

                    if (rt.cooldownUntil && rt.cooldownUntil > Date.now()) {
                        const secondsLeft = Math.ceil((rt.cooldownUntil - Date.now()) / 1000);
                        keyStatus += ` - Cooldown : ${secondsLeft}s`;
                        currentlyCooling = true;
                    }
                }

                if (isActiveEngine) {
                    keyStatus += ` (Actif : ${data.selectedModel || 'Modèle par défaut'})`;
                }

                // Application des mises à jour sur l'élément existant
                badge.title = `${eng.toUpperCase()} : ${keyStatus}`;
                dot.style.backgroundColor = color;
                nameLabel.style.fontWeight = isActiveEngine ? 'bold' : '500';
            });

            if (window.r3CooldownTimer) clearTimeout(window.r3CooldownTimer);

            if (currentlyCooling) {
                window.r3IsCoolingDown = true;
                window.r3CooldownTimer = setTimeout(renderStatus, 1000);
            } else {
                if (window.r3IsCoolingDown) {
                    window.r3IsCoolingDown = false;
                    r3CheckProvidersHealth(statusBarId, true);
                }
            }
        }

        renderStatus();

    } catch (e) {
        statusBar.innerHTML = '<span style="color: #e74c3c;">Service moteurs injoignable (Node down)</span>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    r3CheckProvidersHealth();

    const razBtn = document.querySelector('#resetEngineBtn, #raz-button-id-or-class, button[onclick*="raz"], #raz, #raz-btn');
    if (razBtn) {
        razBtn.addEventListener('click', () => {
            setTimeout(function () { r3CheckProvidersHealth('llm-status-bar', true); }, 400);
        });
    }
});