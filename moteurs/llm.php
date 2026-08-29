<?php
/**
 * r3M3M83r/moteurs/llm.php — Selection moteur LLM partagee
 * (Rebecca + saisie.php + tout le moteur r3M3M83r).
 * Emplacement : avec functions.php / server.js / prompts.js (pas a la racine).
 *
 * ROLE : unique point pour choisir / appliquer le moteur (mistral, groq, ...).
 * Inclus par chat.php et saisie.php (via functions.php ou directement).
 * Ne contient PAS les prompts ni le pipeline memoire.
 *
 * DEPEND : functions.php deja charge pour get_llm_info() si besoin.
 *
 * MODIFIE 29/08/2026 : r3m3m83r_node_health_ui() + footer logs vers moteurs/
 */

if (!defined('R3M3M83R_LLM_PHP')) {
    define('R3M3M83R_LLM_PHP', true);
}

/**
 * Applique le moteur choisi (POST, JSON, ou query) sur la globale $selected_engine.
 * Chaine vide = mode AUTO (ordre fallback Node).
 */
function llm_apply_selected_engine(?string $engine): string {
    global $selected_engine;
    $engine = strtolower(trim((string) $engine));
    $allowed = llm_known_engine_slugs();
    if ($engine !== '' && !in_array($engine, $allowed, true)) {
        $engine = '';
    }
    $selected_engine = $engine;
    if ($engine !== '') {
        $_POST['selected_engine'] = $engine;
    } else {
        unset($_POST['selected_engine']);
    }
    return $engine;
}

/** Slugs connus (alignes sur server.js LLM_ENGINES). */
function llm_known_engine_slugs(): array {
    return ['mistral', 'groq', 'cerebras', 'openrouter'];
}

/** Labels affichage UI. */
function llm_engine_labels(): array {
    return [
        'mistral'    => 'Mistral',
        'groq'       => 'Groq',
        'cerebras'   => 'Cerebras',
        'openrouter' => 'OpenRouter',
    ];
}

/**
 * Liste ordonnee pour un <select> : priorite availableEngines de Node, sinon connus.
 */
function llm_engines_for_select(?array $llmInfo = null): array {
    if ($llmInfo === null && function_exists('get_llm_info')) {
        $llmInfo = get_llm_info();
    }
    $llmInfo = is_array($llmInfo) ? $llmInfo : [];
    $available = $llmInfo['availableEngines'] ?? [];
    if (!is_array($available) || empty($available)) {
        $available = $llmInfo['fallbackOrder'] ?? llm_known_engine_slugs();
    }
    $out = [];
    foreach ($available as $eng) {
        $eng = strtolower(trim((string) $eng));
        if ($eng !== '' && !in_array($eng, $out, true)) {
            $out[] = $eng;
        }
    }
    return $out ?: llm_known_engine_slugs();
}

/** Nom moteur courant (defaut serveur) en minuscules. */
function llm_current_engine_slug(?array $llmInfo = null): string {
    if ($llmInfo === null && function_exists('get_llm_info')) {
        $llmInfo = get_llm_info();
    }
    $llmInfo = is_array($llmInfo) ? $llmInfo : [];
    $name = strtolower((string) ($llmInfo['engineName'] ?? $llmInfo['defaultEngine'] ?? 'mistral'));
    $map = ['mistral' => 'mistral', 'groq' => 'groq', 'cerebras' => 'cerebras', 'openrouter' => 'openrouter'];
    foreach ($map as $slug => $_) {
        if (strpos($name, $slug) !== false) {
            return $slug;
        }
    }
    return 'mistral';
}

/**
 * HTML option list pour select moteur (sans le <select> lui-meme).
 * $selected = slug choisi manuellement ('' = Auto).
 */
function llm_render_engine_options(string $selected = '', ?array $llmInfo = null): string {
    $labels = llm_engine_labels();
    $current = llm_current_engine_slug($llmInfo);
    $html = '<option value=""' . ($selected === '' ? ' selected' : '') . '>Auto — '
        . htmlspecialchars(strtoupper($current), ENT_QUOTES, 'UTF-8') . ' (defaut)</option>';
    foreach (llm_engines_for_select($llmInfo) as $eng) {
        $label = $labels[$eng] ?? ucfirst($eng);
        $sel = ($selected === $eng) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($eng, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

/**
 * Pied de page logs commun (Rebecca, Reformulator, consultation).
 * $prefix = chemin vers la racine r3M3M83r/ depuis la page (ex. '../' depuis rebecca/).
 */
function r3m3m83r_footer_logs_html(string $prefix = ''): string {
    $cpanel = defined('CPANEL_URL') ? CPANEL_URL : 'https://nombre.o2switch.net:2083/';
    $p = $prefix;
    return '<p style="margin:.35rem 0 0;font-size:.82rem;color:#666;">'
        . '<a href="' . htmlspecialchars($cpanel, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Ouvrir cPanel o2switch</a>'
        . ' &bull; '
        . '<a href="' . htmlspecialchars($p . 'moteurs/log_proxy.php?name=error_log', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Voir les erreurs</a>'
        . ' &bull; '
        . '<a href="' . htmlspecialchars($p . 'moteurs/log_proxy.php?name=requests_log', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Voir les requetes</a>'
        . '</p>';
}

/**
 * Bandeau + script : ping Node au chargement (reveil Passenger si besoin).
 *
 * Usage (juste avant </body>) :
 *   echo r3m3m83r_node_health_ui('../moteurs/node_health.php');
 *
 * $pingUrl = chemin relatif vers moteurs/node_health.php depuis la page.
 */
function r3m3m83r_node_health_ui(string $pingUrl): string {
    $urlJson = json_encode($pingUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return <<<HTML
<style>
#r3-node-health-banner{display:none;position:fixed;z-index:99999;left:50%;bottom:1.25rem;transform:translateX(-50%);
  max-width:min(520px,92vw);background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:10px;
  box-shadow:0 8px 28px rgba(0,0,0,.35);padding:.9rem 1.1rem;font:0.92rem/1.45 system-ui,sans-serif}
#r3-node-health-banner strong{color:#fbbf24}
#r3-node-health-banner .r3-nh-actions{margin-top:.55rem;display:flex;gap:.5rem;flex-wrap:wrap}
#r3-node-health-banner button{cursor:pointer;border:0;border-radius:6px;padding:.35rem .75rem;font-size:.85rem}
#r3-node-health-banner .r3-nh-retry{background:#38bdf8;color:#0f172a;font-weight:600}
#r3-node-health-banner .r3-nh-close{background:#475569;color:#fff}
#r3-node-health-banner.r3-nh-waking strong{color:#7dd3fc}
#r3-node-health-banner.r3-nh-ok{background:#14532d;border-color:#166534}
#r3-node-health-banner.r3-nh-ok strong{color:#bbf7d0}
</style>
<div id="r3-node-health-banner" role="status" aria-live="polite"></div>
<script>
(function () {
  var pingUrl = {$urlJson};
  var box = document.getElementById('r3-node-health-banner');
  if (!box) return;

  function show(html, cls) {
    box.className = cls || '';
    box.innerHTML = html;
    box.style.display = 'block';
  }
  function hide() {
    box.style.display = 'none';
    box.innerHTML = '';
    box.className = '';
  }

  function runPing() {
    show('<strong>Verification du moteur IA…</strong><br>Attendez, tentative de reveil de Node.js (Passenger) si besoin.', 'r3-nh-waking');
    fetch(pingUrl, { cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { okHttp: r.ok, j: j }; }); })
      .then(function (pack) {
        var j = pack.j || {};
        if (j.ok) {
          if (j.woke) {
            show('<strong>Moteur IA reveille.</strong><br>Service Node operationnel' +
              (j.engine ? ' (' + j.engine + ')' : '') + '.', 'r3-nh-ok');
            setTimeout(hide, 3200);
          } else {
            hide();
          }
          return;
        }
        var hint = j.hint || "Demander a l'administrateur de redemarrer Node.js dans le cPanel o2switch.";
        show(
          '<strong>Le service Node.js n\\'est pas actif.</strong><br>' +
          (j.message || 'Aucune reponse du moteur.') + '<br><span style="opacity:.9;font-size:.88rem">' + hint + '</span>' +
          '<div class="r3-nh-actions">' +
          '<button type="button" class="r3-nh-retry" id="r3-nh-retry">Reessayer le reveil</button>' +
          '<button type="button" class="r3-nh-close" id="r3-nh-close">Fermer</button>' +
          '</div>',
          ''
        );
        var bRetry = document.getElementById('r3-nh-retry');
        var bClose = document.getElementById('r3-nh-close');
        if (bRetry) bRetry.onclick = function () { runPing(); };
        if (bClose) bClose.onclick = hide;
      })
      .catch(function (err) {
        show(
          '<strong>Impossible de verifier le moteur IA.</strong><br>' +
          'Ping injoignable (' + (err && err.message ? err.message : 'reseau') + '). ' +
          'Si les reponses IA echouent, demander un redemarrage Node dans le cPanel.' +
          '<div class="r3-nh-actions">' +
          '<button type="button" class="r3-nh-retry" id="r3-nh-retry">Reessayer</button>' +
          '<button type="button" class="r3-nh-close" id="r3-nh-close">Fermer</button>' +
          '</div>',
          ''
        );
        var bRetry = document.getElementById('r3-nh-retry');
        var bClose = document.getElementById('r3-nh-close');
        if (bRetry) bRetry.onclick = function () { runPing(); };
        if (bClose) bClose.onclick = hide;
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runPing);
  } else {
    runPing();
  }
})();
</script>
HTML;
}