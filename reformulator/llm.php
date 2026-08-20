<?php
/**
 * r3M3M83r/reformulator/llm.php — Selection moteur LLM partagee
 * (Rebecca + saisie.php + tout le moteur r3M3M83r).
 * Emplacement : avec functions.php / server.js / prompts.js (pas a la racine).
 *
 * ROLE : unique point pour choisir / appliquer le moteur (mistral, groq, ...).
 * Inclus par chat.php et saisie.php (via functions.php ou directement).
 * Ne contient PAS les prompts ni le pipeline memoire (restent dans reformulator/).
 *
 * DEPEND : reformulator/functions.php deja charge pour get_llm_info() si besoin.
 */

if (!defined('R3M3M83R_LLM_PHP')) {
    define('R3M3M83R_LLM_PHP', true);
}

/**
 * Applique le moteur choisi (POST, JSON, ou query) sur la globale $selected_engine.
 * Chaîne vide = mode AUTO (ordre fallback Node).
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

/** Slugs connus (alignés sur server.js LLM_ENGINES). */
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
 * Liste ordonnée pour un <select> : priorite availableEngines de Node, sinon connus.
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
    // engineName peut etre "MISTRAL" ou le slug
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
 * Pied de page logs commun (Rebecca, Reformulator, data.php).
 * Chemins relatifs depuis la racine r3M3M83r/.
 */
function r3m3m83r_footer_logs_html(string $prefix = ''): string {
    $cpanel = defined('CPANEL_URL') ? CPANEL_URL : 'https://nombre.o2switch.net:2083/';
    $p = $prefix; // ex. '' ou './'
    return '<p style="margin:.35rem 0 0;font-size:.82rem;color:#666;">'
        . '<a href="' . htmlspecialchars($cpanel, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Ouvrir cPanel o2switch</a>'
        . ' &bull; '
        . '<a href="' . htmlspecialchars($p . 'reformulator/log_proxy.php?name=error_log', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Voir les erreurs</a>'
        . ' &bull; '
        . '<a href="' . htmlspecialchars($p . 'reformulator/log_proxy.php?name=requests_log', ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">Voir les requetes</a>'
        . '</p>';
}