/**
 * ============================================================================
 * r3M3M83r/moteurs/loading_steps.js
 * ============================================================================
 * Séquence informative unifiée pour Rebecca et le Reformulator (saisie.php)
 */

const R3_LOADING_STEPS = [
    'Interrogation de instructions.md ...<br>Expansion intention + preuves + appel LLM',
    'Le fichier mémoriel est en cours de parcours ...',
    'Le service IA est contacté',
    'Patienter encore un peu',
    'On y croit ...',
    'Les moteurs doivent être lents aujourd\'hui !',
    'Les moteurs semblent bien occupés ...',
    'Dernières vérifications en cours ...'
];

function r3InitProgressiveLoader(overlayElementId, textElementClassName, intervalMs = 4000) {
    const overlay = document.getElementById(overlayElementId);
    const textEl = document.querySelector('.' + textElementClassName);
    let timer = null;
    let stepIndex = 0;

    return {
        start: function(initialStepIndex = 0) {
            stepIndex = initialStepIndex;
            if (textEl && R3_LOADING_STEPS[stepIndex]) {
                textEl.innerHTML = R3_LOADING_STEPS[stepIndex];
            }
            if (overlay) {
                overlay.classList.add('open');
            }
            if (timer) clearInterval(timer);
            timer = setInterval(() => {
                stepIndex = Math.min(stepIndex + 1, R3_LOADING_STEPS.length - 1);
                if (textEl) {
                    textEl.innerHTML = R3_LOADING_STEPS[stepIndex];
                }
            }, intervalMs);
        },
        setStep: function(indexOrMessage) {
            if (typeof indexOrMessage === 'number' && R3_LOADING_STEPS[indexOrMessage]) {
                stepIndex = indexOrMessage;
                if (textEl) textEl.innerHTML = R3_LOADING_STEPS[stepIndex];
            } else if (typeof indexOrMessage === 'string') {
                if (textEl) textEl.innerHTML = indexOrMessage;
            }
        },
        stop: function() {
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (overlay) {
                overlay.classList.remove('open');
            }
        }
    };
}