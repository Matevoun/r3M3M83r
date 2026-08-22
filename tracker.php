<?php
/**
 * tracker.php — Traceur des consultations "lecture memoire" (instructions.md + data.php)
 *
 * ---------------------------------------------------------------------------
 * ROLE
 *   Journalise les acces en LECTURE au profil personnel dans access.log
 *   (meme dossier que ce script). Format multi-lignes lisible, consomme par
 *   le panneau Admin CHARREYRE (action=access_log).
 *
 *   Ce script ne gere PAS les questions LLM de Rebecca / Reformulator :
 *   ces interfaces ecrivent AUSSI dans access.log, mais en direct
 *   (rebecca/index.php -> chat_log_request ; moteurs/functions.php ->
 *   log_reformulator_request), sans passer par tracker.php.
 *
 * ---------------------------------------------------------------------------
 * DEUX MODES D'APPEL (uniquement)
 *   1. Via .htaccess (URL instructions.md) :
 *        RewriteRule ^instructions\.md$ tracker.php
 *      -> log access.log + readfile(instructions.md) + exit
 *
 *   2. Via require_once depuis data.php (parcours /sections) :
 *        define('TRACKER_SOURCE', 'data.php');
 *        define('TRACKER_SECTION', ...);
 *        require_once tracker.php;
 *      -> log access.log + return (data.php envoie la reponse HTML/texte)
 *
 * ---------------------------------------------------------------------------
 * PROTECTION ACCES DIRECT
 *   URL /tracker.php tapee dans le navigateur sans TRACKER_SOURCE -> HTTP 403.
 *
 * ---------------------------------------------------------------------------
 * DETECTION VISITEUR
 *   User-Agent classe : IAs (ChatGPT, Claude, Gemini, Copilot, Perplexity,
 *   LLM generiques), scripts/bots, crawlers SEO, bots reseaux sociaux,
 *   navigateur humain, UA absent / indetermine.
 *
 * ---------------------------------------------------------------------------
 * SECURITE
 *   - IP : X-Forwarded-For puis X-Real-IP puis REMOTE_ADDR (o2switch proxy)
 *   - urldecode sur URI et Referer
 *   - Mail par visite : DESACTIVE (Note dans le log). Resume eventuel via
 *     send_daily_summary.php si present.
 *
 * ---------------------------------------------------------------------------
 * FICHIERS
 *   - access.log          (racine r3M3M83r/) : journal partage
 *       * ecrit par tracker.php (modes 1 et 2 ci-dessus)
 *       * ecrit aussi par rebecca/ et reformulator/saisie.php (questions IA)
 *       * lu par Admin/index.php (?action=access_log)
 *       * protege par FilesMatch dans .htaccess
 *   - instructions.md     : servi en mode 1 uniquement
 *   - tracker_error_log   : erreurs PHP de ce script (ini_set error_log)
 *   - moteurs/log/requests.log : journal TECHNIQUE LLM (Node + PHP), distinct
 *     (etapes finales query/rewrite... ; pas les consultations de fichier)
 *
 * ---------------------------------------------------------------------------
 * CREATED : mars 2026
 * MODIFIE : 22/08/2026 — doc alignee architecture moteurs/ + rebecca + Admin
 * ---------------------------------------------------------------------------
 */

if (function_exists('ini_set')) {
    @ini_set('error_log', __DIR__ . '/tracker_error_log');
    @ini_set('log_errors', '1');
}


// Bloquer l'acces direct a tracker.php (URL tapee dans le navigateur)
// Exception : include depuis data.php (constante TRACKER_SOURCE definie)
if (!defined('TRACKER_SOURCE') && strpos($_SERVER['REQUEST_URI'] ?? '', 'tracker.php') !== false) {
    http_response_code(403);
    exit;
}

// Contexte d'appel :
//   $_tracker_via_data = true  -> data.php (pas de readfile en fin)
//   $_tracker_via_data = false -> .htaccess / instructions.md (readfile)
//   $_tracker_section          -> section demandee (null si fichier complet)
$_tracker_via_data = defined('TRACKER_SOURCE') && constant('TRACKER_SOURCE') === 'data.php';
$_tracker_section  = defined('TRACKER_SECTION') ? constant('TRACKER_SECTION') : null;

// --- Collecte requete ------------------------------------------------------------
// IP : X-Forwarded-For en priorite (o2switch derriere reverse proxy)
$date    = date('d/m/Y H:i:s') . ' UTC' . date('P');
$ip      = trim(explode(',', (string)(
               $_SERVER['HTTP_X_FORWARDED_FOR'] ??
               $_SERVER['HTTP_X_REAL_IP']       ??
               $_SERVER['REMOTE_ADDR']           ??
               'inconnue'
           ))[0]);
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '—';
$referer = urldecode($_SERVER['HTTP_REFERER']    ?? '—');
$method  = $_SERVER['REQUEST_METHOD']  ?? 'GET';
$_path   = urldecode($_SERVER['REQUEST_URI']     ?? '/r3M3M83r/instructions.md');
$_scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https' : 'http';
$_host   = $_SERVER['HTTP_HOST'] ?? 'mathieu.charreyre.net';
$url     = $_scheme . '://' . $_host . $_path;

$logFile = __DIR__ . '/access.log';
$to = 'webmaster@wda-fr.org';

// --- Detection source ------------------------------------------------------------
$_ua_lower = strtolower($ua);
if (preg_match('/gpt|chatgpt|openai/i', $ua)) {
    $_source = 'IA — ChatGPT / OpenAI';
} elseif (preg_match('/claude|anthropic/i', $ua)) {
    $_source = 'IA — Claude / Anthropic';
} elseif (preg_match('/gemini|google-extended|bard/i', $ua)) {
    $_source = 'IA — Gemini / Google';
} elseif (preg_match('/copilot|github-copilot/i', $ua)) {
    $_source = 'IA — GitHub Copilot';
} elseif (preg_match('/perplexity/i', $ua)) {
    $_source = 'IA — Perplexity';
} elseif (preg_match('/llm|llama|mistral|cohere|ai2|huggingface/i', $ua)) {
    $_source = 'IA — LLM (générique)';
} elseif (preg_match('/python-requests|curl|wget|httpx|aiohttp|axios|node-fetch|go-http|java|okhttp/i', $ua)) {
    $_source = 'Script / Bot technique';
} elseif (preg_match('/googlebot|bingbot|yandex|duckduckbot|semrush|ahrefs|mj12bot|dotbot|rogerbot|crawler|spider/i', $ua)) {
    $_source = 'Crawler / Indexeur SEO';
} elseif (preg_match('/facebookexternalhit|twitterbot|linkedinbot|slackbot|whatsapp|telegram/i', $ua)) {
    $_source = 'Bot réseaux sociaux';
} elseif (preg_match('/chrome|firefox|safari|opera|edge|msie|trident/i', $ua)) {
    $_source = 'Navigateur humain';
} elseif ($ua === '—' || $ua === '') {
    $_source = 'Inconnu (pas de User-Agent)';
} else {
    $_source = 'Indéterminé';
}

$_visitorType = ($_source === 'Navigateur humain') ? 'Humain' : 'Bot / Script / IA';

$_visitorLabel = ($_source === 'Navigateur humain')
    ? 'Humain (navigateur)'
    : 'Bot / Script / IA — ' . $_source;

$_row = function(string $label, string $value): string {
    return $label . ' : ' . $value . "\n";
};

$logLine  = "------------------------------\n";
$logLine .= '[' . $date . '] ' . $_visitorLabel . ' (IP : ' . $ip . ")\n";
$logLine .= $_row('Mode',        $_tracker_via_data ? 'data.php' : 'instructions.md');
$logLine .= $_row('URL',         $url);
$logLine .= $_row('Referer',     $referer);
$logLine .= $_row('User-Agent',  $ua);
$logLine .= $_row('Type',        $_visitorType);
$logLine .= $_row('Source',      $_source);
$logLine .= "Note : Mail de notification désactivée.\n";
$logLine .= "\n";
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

$_shouldMail = false;

if ($_tracker_via_data) {
    $_label  = $_tracker_section ?? 'complet';
    $subject = '[CHARREYRE] Requete LLM "' . $_label . '" — ' . date('d/m/Y H:i');
    $body    = "Consultation du profil personnel via LLM\r\n"
             . "===============================\r\n\r\n"
             . $_row('Source',       $_source . ' (IP : ' . $ip . ')')
             . $_row('Section',      $_label . ' (URL : ' . $url . ')')
             . $_row('Date/heure',   $date)
             . $_row('User-Agent',   $ua)
             . $_row('Referer',      $referer)
             . $_row('Methode HTTP', $method)
             . "\r\n---\r\n"
             . "https://mathieu.charreyre.net/r3M3M83r/data.php";
} else {
    $subject = '[CHARREYRE] Lecture profil complet — ' . date('d/m/Y H:i');
    $body    = "Consultation du profil personnel (fichier complet)\r\n"
             . "=========================================\r\n\r\n"
             . $_row('Source',       $_source . ' (IP : ' . $ip . ')')
             . $_row('Date/heure',   $date)
             . $_row('User-Agent',   $ua)
             . $_row('Referer',      $referer)
             . $_row('Methode HTTP', $method)
             . $_row('URL',          $url)
             . "\r\n---\r\n"
             . "https://mathieu.charreyre.net/r3M3M83r/instructions.md";
}

// Mail par visite desactive. Journal uniquement ; resume eventuel : send_daily_summary.php

// Mode data.php : data.php gere la sortie
if ($_tracker_via_data) {
    return;
}

// Mode instructions.md : servir le fichier
$file = __DIR__ . '/instructions.md';
if (!is_readable($file)) {
    http_response_code(500);
    exit('Fichier non disponible.');
}

header('Content-Type: text/plain; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Length: ' . filesize($file));
readfile($file);
exit;