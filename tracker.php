<?php
/**
 * tracker.php — Traceur de consultations pour instructions.md et data.php
 *
 * RÔLE
 *   Loggue chaque accès au profil personnel (instructions.md ou data.php) :
 *     - Dans access.log (dans ce même dossier) : une ligne par hit
 *     - Par mail à webmaster@wda-fr.org : détail complet de la requête
 *
 * DEUX MODES D'APPEL
 *   1. Via .htaccess (instructions.md direct) :
 *      → RewriteRule ^instructions\.md$ tracker.php
 *      → tracker.php log + mail + readfile(instructions.md) + exit
 *
 *   2. Via require_once depuis data.php :
 *      → data.php définit TRACKER_SOURCE='data.php' et TRACKER_SECTION avant l'include
 *      → tracker.php log + mail + return (data.php gère lui-même la sortie)
 *
 * PROTECTION ACCÈS DIRECT
 *   Si quelqu'un appelle /r3M3M83r/tracker.php directement dans le navigateur,
 *   le script répond 403. La condition vérifie que l'URI contient "tracker.php"
 *   ET que TRACKER_SOURCE n'est pas défini (= pas un appel légitime depuis data.php).
 *
 * DÉTECTION DU TYPE DE VISITEUR
 *   Le User-Agent est analysé pour distinguer : IAs connues (ChatGPT, Claude, Gemini,
 *   Copilot, Perplexity, LLMs génériques), scripts/bots techniques, crawlers SEO,
 *   bots réseaux sociaux, navigateurs humains, User-Agent absent.
 *
 * SÉCURITÉ
 *   - IP extraite de X-Forwarded-For (o2switch est derrière un reverse proxy)
 *   - urldecode sur URI et Referer (lisibilité du log)
 *   - mail() envoyé avec From: mathieu@charreyre.net (domaine hébergé = SPF/DKIM valides)
 *
 * FICHIERS
 *   - access.log : log brut, une ligne par hit, utilisé uniquement par tracker.php (protégé par FilesMatch dans .htaccess)
 *   - instructions.md : fichier servi en mode 1 (readfile transparent)
 *
 * CRÉÉ : mars 2026   MODIFIÉ : mars 2026
 */

if (function_exists('ini_set')) {
    @ini_set('error_log', __DIR__ . '/tracker_error_log');
    @ini_set('log_errors', '1');
}


// Bloquer l'accès direct à tracker.php (URL tapée directement dans le navigateur)
// Exception : si appelé en include depuis data.php (constante TRACKER_SOURCE définie)
if (!defined('TRACKER_SOURCE') && strpos($_SERVER['REQUEST_URI'] ?? '', 'tracker.php') !== false) {
    http_response_code(403);
    exit;
}

// Détermine le contexte d'appel :
//   $_tracker_via_data = true  → appelé depuis data.php (ne pas faire readfile en fin de script)
//   $_tracker_via_data = false → appelé depuis .htaccess (mode transparent, readfile à la fin)
//   $_tracker_section          → section demandée (null si fichier complet)
$_tracker_via_data = defined('TRACKER_SOURCE') && constant('TRACKER_SOURCE') === 'data.php';
$_tracker_section  = defined('TRACKER_SECTION') ? constant('TRACKER_SECTION') : null;

// --- Collecte des informations de la requête ------------------------------------
// L'IP est extraite de X-Forwarded-For en priorité car o2switch est derrière un
// reverse proxy : REMOTE_ADDR contiendrait l'IP du proxy, pas du visiteur réel.
// On prend le premier élément s'il y a plusieurs IPs chaînées (ex. via VPN).
$date    = date('d/m/Y H:i:s') . ' UTC' . date('P');
$ip      = trim(explode(',', (string)(
               $_SERVER['HTTP_X_FORWARDED_FOR'] ??
               $_SERVER['HTTP_X_REAL_IP']       ??
               $_SERVER['REMOTE_ADDR']           ??
               'inconnue'
           ))[0]);
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '—';
$referer = urldecode($_SERVER['HTTP_REFERER']    ?? '—');  // décodé pour lisibilité
$method  = $_SERVER['REQUEST_METHOD']  ?? 'GET';
// Reconstruction de l'URL complète (REQUEST_URI = chemin + query string, déjà urlencodé)
$_path   = urldecode($_SERVER['REQUEST_URI']     ?? '/r3M3M83r/instructions.md');
$_scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https' : 'http';
$_host   = $_SERVER['HTTP_HOST'] ?? 'mathieu.charreyre.net';
$url     = $_scheme . '://' . $_host . $_path;

// --- Log fichier ---------------------------------------------------------------
// Écrit dans access.log (même dossier, protégé par FilesMatch dans .htaccess).
// LOCK_EX évite les corruptions en cas de hits simultanés.
// Le résultat de l'envoi mail est ajouté juste après (MAIL_OK / MAIL_FAIL).
$logFile = __DIR__ . '/access.log';

// --- Corps du mail ---
$to = 'webmaster@wda-fr.org';

// --- Détection de la source de la requête ---
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

// --- Discrimination Bot / Humain ------------------------------------------------
// On distingue les visites provenant d'un vrai navigateur humain des autres.
$_visitorType = ($_source === 'Navigateur humain') ? 'Humain' : 'Bot / Script / IA';

$_visitorLabel = ($_source === 'Navigateur humain')
    ? 'Humain (navigateur)'
    : 'Bot / Script / IA — ' . $_source;

// Ligne formatée pour un journal humain, avec des blocs lisibles.
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

// Ne pas envoyer de notification pour les consultations clairement identifiées
// comme provenant d'un navigateur humain. Cela réduit les faux positifs.
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

// Le mail par visite est désactivé. Les consultations sont journalisées uniquement
// et un résumé quotidien est envoyé séparément par send_daily_summary.php.

// --- Servir instructions.md de façon transparente (mode .htaccess uniquement) ---
// En mode data.php (TRACKER_SOURCE défini), on s'arrête ici : data.php gère la sortie.
// En mode .htaccess, on sert le fichier complet avec les bons headers pour les IAs :
//   - Content-Length exact : permet à l'IA de détecter une troncature
//   - CORS ouvert          : nécessaire pour les fetch cross-origin des agents IA
//   - X-Robots-Tag         : empêche l'indexation par les moteurs de recherche
if ($_tracker_via_data) {
    return;
}

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
header('Content-Length: ' . filesize($file));  // taille exacte = détection troncature
readfile($file);
exit;
