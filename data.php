<?php
/**
 * data.php — Serveur de sections pour instructions.md (profil personnel Mathieu CHARREYRE)
 *
 * RÔLE
 *   Permet aux IAs (Claude, ChatGPT, Gemini…) de charger une section précise du fichier
 *   instructions.md lorsqu'elles ne peuvent pas le charger en entier (trop volumineux).
 *   Sert aussi d'index cliquable pour les navigateurs humains.
 *
 * PARAMÈTRE ?s=
 *   Omis / "all"  → renvoie le fichier instructions.md complet (même contenu que l'URL directe)
 *   "list"        → liste toutes les sections avec leur URL et leur taille
 *                   (HTML cliquable si le client envoie Accept: text/html, texte sinon)
 *   "1" … "13"   → section numérotée (ex. ?s=3 = section 3)
 *   mot-clef      → recherche partielle case-insensitive sur le titre de section (min. 4 chars)
 *                   ex. ?s=philosophie, ?s=santé, ?s=chronologie
 *   "faq"         → section FAQ (règles d'or pour IAs)
 *   "table"       → table des matières
 *
 * ALIASES .htaccess (URLs propres sans .php ni paramètres) :
 *   /sections      → ?s=list
 *   /sN            → ?s=N   (ex. /s3, /s11)
 *   /faq           → ?s=faq
 *   /table         → ?s=table
 *   /profil        → ?s=all
 *   /section/X     → ?s=X   (fallback dynamique)
 *
 * TRACKING
 *   Chaque appel déclenche tracker.php via require_once (log fichier + mail).
 *   Les constantes TRACKER_SOURCE et TRACKER_SECTION sont définies avant l'include
 *   pour que tracker.php sache qu'il est appelé depuis data.php (et non directement).
 *
 * SÉCURITÉ
 *   - Validation du paramètre ?s= par regex (whitelist de caractères)
 *   - Refus des paramètres < 4 chars sauf digits et mots réservés
 *   - X-Robots-Tag: noindex, nofollow
 *   - Cache-Control: no-store (pas de cache proxy/CDN)
 *
 * CONTENU
 *   Le fichier instructions.md pèse ~222 Ko (Content-Length: 222975).
 *   Les sections sont délimitées par les titres de niveau ## (deux dièses).
 *   La section la plus lourde est s3 (~56 Ko).
 *
 * FLUX D'EXÉCUTION
 *   Requête HTTP
 *     └─ data.php
 *          ├─ 1. Validation ?s= (regex whitelist, longueur min.)
 *          ├─ 2. require_once tracker.php  (log + mail ; définit $_scheme / $_host)
 *          ├─ 3. file_get_contents(instructions.md)
 *          ├─ 4. Dispatch selon $section :
 *          │      ├─ "all"      → echo fichier brut                  (text/plain)
 *          │      ├─ "list"     ┬─ Accept:text/html → HTML navigateur (tableau + recherche plein texte + filtre JS)
 *          │      │             └─ autre            → texte aligné    (pour IAs / curl)
 *          │      └─ autre      ┬─ Accept:text/html → HTML rendu       (md_to_html + JS highlight/scroll si ?q=)
 *          │                    └─ autre            → Markdown brut   (text/plain)
 *          └─ 5. Sortie + exit
 *
 * HELPERS INTERNES (fonctions PHP)
 *   html_shell($title)               → DOCTYPE + <head> + CSS + début <body>
 *   html_footer($show_back)          → fermeture </body></html> + lien "← Retour" optionnel
 *   md_to_html($md)                  → convertit Markdown instructions.md en HTML minimal
 *   strip_accents($s)                → normalisation UTF-8 sans accents (pour recherches)
 *   accent_insensitive_pattern($q)   → regex char-class pour surlignage insensible aux accents
 *
 * CRÉÉ : mars 2026   MODIFIÉ : 12 avril 2026
 */

// ─── Configuration ──────────────────────────────────────────────────────────
// Chemin absolu vers instructions.md (dans le même dossier que ce script)
define('SOURCE_FILE', __DIR__ . '/instructions.md');

// ─── En-têtes par défaut (text/plain pour les IAs) ─────────────────────────
// Note : le mode ?s=list peut remplacer Content-Type par text/html si le client
// envoie Accept: text/html (navigateur). Les autres modes restent en text/plain.
header('Content-Type: text/plain; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');  // pas d'indexation Google/Bing
header('Cache-Control: no-store');           // pas de cache intermédiaire

$section = trim($_GET['s'] ?? 'all');

// Validation : whitelist de caractères (lettres, chiffres, tirets, accents, espace)
// Évite toute injection de chemin ou de commande.
if (!preg_match('/^[a-zA-Z0-9_\-àâäéèêëîïôùûüçÀÂÄÉÈÊËÎÏÔÙÛÜÇ ]+$/', $section) && $section !== 'all' && $section !== 'list') {
    http_response_code(400);
    exit("Paramètre invalide.");
}

// Refus des mots trop courts (évite les faux positifs sur des mots d'une lettre)
// Exceptions : mots réservés (all, list, faq, table) et numéros purs (1, 3, 13…)
if (!in_array($section, ['all', 'list', 'faq', 'table'], true) && mb_strlen($section) < 4 && !ctype_digit($section)) {
    http_response_code(400);
    exit("Paramètre trop court (minimum 4 caractères, ou numéro de section). Utilisez ?s=list pour voir les sections disponibles.");
}

// ─── Tracking ────────────────────────────────────────────────────────────────
// On définit deux constantes AVANT d'inclure tracker.php :
//   TRACKER_SOURCE  → indique à tracker.php qu'il est appelé depuis data.php
//                     (évite le 403 de la protection contre l'accès direct)
//   TRACKER_SECTION → nom de la section demandée, pour le sujet du mail
// tracker.php loggue dans access.log + envoie un mail à webmaster@wda-fr.org,
// puis fait return (pas de readfile ici, c'est data.php qui gère la sortie).
define('TRACKER_SOURCE',  'data.php');
define('TRACKER_SECTION', $section !== 'all' ? $section : null);
require_once __DIR__ . '/tracker.php';

// ─── Lecture du fichier ──────────────────────────────────────────────────────

if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) {
    http_response_code(500);
    exit("Fichier source indisponible.");
}

$content = file_get_contents(SOURCE_FILE);
if ($content === false) {
    http_response_code(500);
    exit("Erreur de lecture.");
}

// ─── Sortie complète ─────────────────────────────────────────────────────────

if ($section === 'all') {
    echo $content;
    exit;
}

// ─── Découpage en sections (délimitées par les titres ## ) ──────────────────
// Chaque section commence à un titre "## …" et s'arrête au titre ## suivant.
// Le titre lui-même est inclus dans le corps de la section.
// Résultat : tableau associatif ['## Titre complet' => 'contenu complet incluant le titre']

$lines      = explode("\n", $content);
$sections   = [];
$current_title = null;
$current_body  = [];
$intro_lines   = [];
$intro         = '';

foreach ($lines as $line) {
    if (preg_match('/^## /', $line)) {
        // Nouveau titre ## : on sauvegarde la section précédente si elle existe
        if ($current_title !== null) {
            $sections[$current_title] = implode("\n", $current_body);
        } elseif (!empty($intro_lines)) {
            $intro = implode("\n", $intro_lines);
        }
        $current_title = $line;
        $current_body  = [$line];
    } else {
        if ($current_title !== null) {
            $current_body[] = $line;
        } else {
            $intro_lines[] = $line;
        }
    }
}
// Flush de la dernière section (pas de ## suivant pour déclencher la sauvegarde)
if ($current_title !== null) {
    $sections[$current_title] = implode("\n", $current_body);
} elseif (!empty($intro_lines)) {
    $intro = implode("\n", $intro_lines);
}

// ─── Helpers HTML partagés (listing + rendu section) ───────────────────────
// Utilisés uniquement quand Accept: text/html (navigateur humain).

/**
 * Retourne le <!DOCTYPE> + <head> + début de <body> avec le CSS commun.
 * Responsive : une colonne sur mobile, table sur desktop.
 */
function html_shell(string $title): string {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$t}</title>
<link rel="icon" type="image/png" sizes="96x96" href="favicon/favicon-96x96.png">
<link rel="icon" href="favicon/favicon.svg" type="image/svg+xml" sizes="any">
<link rel="icon" href="favicon/favicon.ico">
<link rel="apple-touch-icon" href="favicon/apple-touch-icon.png">
<link rel="manifest" href="favicon/site.webmanifest">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:monospace;background:#111;color:#ccc;padding:1.5rem;line-height:1.6;font-size:.9rem}
    a{color:#7cf;text-decoration:none}
    a:hover{text-decoration:underline}
    h1{color:#fff;font-size:1.05rem;margin-bottom:1.2rem;border-bottom:1px solid #333;padding-bottom:.5rem}
    h2{color:#aef;font-size:1rem;margin:1.6rem 0 .4rem}
    h3{color:#cdf;font-size:.95rem;margin:1.2rem 0 .3rem}
    p{margin:.5rem 0}
    ul,ol{margin:.4rem 0 .4rem 1.4rem}
    li{margin:.15rem 0}
    strong{color:#fff}
    em{color:#bbb}
    code{background:#1e1e1e;color:#f8c;padding:.1rem .3rem;border-radius:3px;font-size:.85rem}
    pre{background:#1a1a1a;color:#eee;padding:1rem;border-radius:4px;overflow-x:auto;margin:.8rem 0;font-size:.82rem}
    hr{border:none;border-top:1px solid #333;margin:1rem 0}
    blockquote{border-left:3px solid #555;padding-left:.8rem;color:#aaa;margin:.6rem 0}
    /* Table (listing /sections) */
    table{border-collapse:collapse;width:100%}
    tr:nth-child(even){background:#1a1a1a}
    td{padding:.35rem .7rem;vertical-align:top}
    .td-titre{color:#ddd}
    .td-ko{text-align:right;white-space:nowrap;color:#888}
    /* Responsive : sur petit écran, empiler les cellules */
    @media(max-width:680px){
    table,tbody,tr,td{display:block;width:100%}
    tr{border-bottom:1px solid #222;padding:.4rem 0}
    td{padding:.1rem .4rem}
    .td-ko{text-align:left;color:#666;font-size:.8rem}
    }
    /* Lien retour */
    .back{display:inline-block;margin-bottom:1rem;font-size:.82rem;color:#888}
    .back:hover{color:#7cf}
    /* Recherche — humains seulement (/sections) */
    .search-wrap{margin-bottom:1.4rem}
    .search-wrap form{display:flex;gap:.5rem;align-items:center;flex-wrap:nowrap}
    .search-wrap input[type=search]{flex:1 1 0;min-width:0;background:#1a1a1a;border:1px solid #444;color:#eee;padding:.45rem .7rem;border-radius:4px;font:inherit;outline:none}
    .search-wrap input[type=search]:focus{border-color:#7cf}
    .search-wrap .btn-group{display:flex;gap:.5rem;flex-shrink:0}
    .search-wrap button{background:#1e3a4a;border:1px solid #7cf;color:#7cf;padding:.45rem 1rem;border-radius:4px;cursor:pointer;font:inherit;white-space:nowrap}
    .search-wrap button:hover{background:#2a4a5a}
    a.btn-clear{background:#2a1a1a;border:1px solid #c77;color:#c77;padding:.45rem 1rem;border-radius:4px;font:inherit;white-space:nowrap;text-decoration:none;display:inline-block}
    a.btn-clear:hover{background:#3a2525;color:#e99}
    .search-hint{font-size:.78rem;color:#555;margin-top:.35rem}
    .search-info{font-size:.85rem;color:#888;margin:.8rem 0}
    .search-info strong{color:#aef}
    .result{margin:.8rem 0;padding:.7rem;background:#1a1a1a;border-left:3px solid #7cf;border-radius:0 4px 4px 0}
    .result h3{font-size:.9rem;margin-bottom:.4rem}
    .excerpt-wrap{position:relative;padding-right:5.5rem}
    .excerpt{font-size:.82rem;color:#888;white-space:pre-wrap;word-break:break-word;margin:0;line-height:1.6}
    .btn-copy{position:absolute;top:0;right:0;background:#222;border:1px solid #555;color:#999;font-size:.72rem;padding:.2rem .55rem;border-radius:3px;cursor:pointer;line-height:1.4;white-space:nowrap}
    .btn-copy:hover{border-color:#7cf;color:#7cf}
    mark{background:#2b2b00;color:#ffe566;font-weight:bold;padding:0 3px;border-radius:2px}
    .ellipsis{color:#7cf;font-weight:bold;opacity:0.8}
    #filter-info{font-size:.82rem;color:#666;margin-bottom:.3rem}
    /* Date de derniere modification du fichier source */
    .file-meta{display:flex;flex-wrap:wrap;align-items:baseline;column-gap:.6rem;row-gap:.1rem;font-size:.78rem;margin:.15rem 0 1.1rem}
    .file-meta .lbl{color:#444}
    .file-meta time{font-style:normal;white-space:nowrap}
    .file-meta .f-age{font-size:.72rem;white-space:nowrap}
    .f-fresh{color:#3a9}.f-recent{color:#a83}.f-stale{color:#666}
    .file-meta{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .lbl-lg{display:inline}.lbl-sm{display:none}
    .mtime-sm{display:none}
    @media(max-width:520px){.mtime-lg{display:none}.mtime-sm{display:inline}.lbl-lg{display:none}.lbl-sm{display:inline}}
</style>
</head>
<body>
HTML;
}

/**
 * Convertit le Markdown du fichier instructions.md en HTML minimal.
 * Gère : ## / ### titres, **gras**, *italique*, `code`, ``` blocs,
 * listes - et 1., blockquotes >, <hr> ---, liens [text](url).
 */
function md_to_html(string $md): string {
    $lines  = explode("\n", $md);
    $html   = '';
    $in_pre = false;
    $in_ul  = false;
    $in_ol  = false;

    $close_lists = function() use (&$html, &$in_ul, &$in_ol) {
        if ($in_ul) { $html .= "</ul>\n"; $in_ul = false; }
        if ($in_ol) { $html .= "</ol>\n"; $in_ol = false; }
    };

    $inline = function(string $s): string {
        // Liens [text](url)
        $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $s);
        // Gras **text** ou __text__
        $s = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $s);
        // Italique *text* ou _text_ (après gras)
        $s = preg_replace('/\*([^*]+?)\*|_([^_]+?)_/', '<em>$1$2</em>', $s);
        // Code inline `text`
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        return $s;
    };

    foreach ($lines as $line) {
        // Bloc de code ```
        if (preg_match('/^```/', $line)) {
            $close_lists();
            if (!$in_pre) { $html .= "<pre>"; $in_pre = true; }
            else          { $html .= "</pre>\n"; $in_pre = false; }
            continue;
        }
        if ($in_pre) { $html .= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n"; continue; }

        $raw = rtrim($line);
        $esc = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

        // HR
        if (preg_match('/^---+$/', $raw)) { $close_lists(); $html .= "<hr>\n"; continue; }

        // Titres ## et ###
        if (preg_match('/^### (.+)$/', $raw, $m)) {
            $close_lists(); $html .= '<h3>' . $inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . "</h3>\n"; continue;
        }
        if (preg_match('/^## (.+)$/', $raw, $m)) {
            $close_lists(); $html .= '<h2>' . $inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . "</h2>\n"; continue;
        }

        // Blockquote >
        if (preg_match('/^> ?(.*)$/', $raw, $m)) {
            $close_lists(); $html .= '<blockquote>' . $inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . "</blockquote>\n"; continue;
        }

        // Liste non ordonnée - / *
        if (preg_match('/^[\-\*] (.+)$/', $raw, $m)) {
            if ($in_ol) { $html .= "</ol>\n"; $in_ol = false; }
            if (!$in_ul) { $html .= "<ul>\n"; $in_ul = true; }
            $html .= '<li>' . $inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . "</li>\n"; continue;
        }

        // Liste ordonnée 1.
        if (preg_match('/^\d+\. (.+)$/', $raw, $m)) {
            if ($in_ul) { $html .= "</ul>\n"; $in_ul = false; }
            if (!$in_ol) { $html .= "<ol>\n"; $in_ol = true; }
            $html .= '<li>' . $inline(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . "</li>\n"; continue;
        }

        // Ligne vide
        if ($raw === '') { $close_lists(); $html .= "\n"; continue; }

        // Paragraphe
        $close_lists();
        $html .= '<p>' . $inline($esc) . "</p>\n";
    }
    $close_lists();
    if ($in_pre) $html .= "</pre>\n";
    return $html;
}

/** Retourne la fermeture </body></html>, avec le lien retour si $show_back est vrai. */
function html_footer(bool $show_back = true): string {
    $back = $show_back ? '<p><a class="back" href="sections">← Retour à la liste des sections</a></p>' : '';
    $github_raw = 'https://raw.githubusercontent.com/Matevoun/r3M3M83r/refs/heads/main/instructions.md';
    $local_md   = 'https://mathieu.charreyre.net/r3M3M83r/instructions.md';
    // Logs reformulator (memes liens que saisie.php / chat.php)
    $logs = '<p style="margin:.6rem 0 .2rem;font-size:.8rem;color:#666;">'
          . '<a href="reformulator/log_proxy.php?name=error_log" style="color:#7cf;" target="_blank" rel="noopener noreferrer">Voir les erreurs</a>'
          . ' &bull; '
          . '<a href="reformulator/log_proxy.php?name=requests_log" style="color:#7cf;" target="_blank" rel="noopener noreferrer">Voir les requetes</a>'
          . ' &bull; '
          . '<a href="saisie.php" style="color:#7cf;">Reformulator</a>'
          . ' &bull; '
          . '<a href="chat.php" style="color:#7cf;">Rebecca (tchat)</a>'
          . '</p>';
    $foot_text = '<div style="margin-top:1.5rem;background:#111;color:#bbb;font-size:.86rem;line-height:1.5;">'
               . '<p style="margin:.2rem 0;">Fichier brut accessible en <a href="' . $local_md . '" style="color:#7cf;" target="_blank">local</a> (<span style="color:#999;">' . $local_md . '</span>) et sur <a href="' . $github_raw . '" style="color:#7cf;" target="_blank">GitHub</a> (<span style="color:#999;">' . $github_raw . '</span>)</p><br>'
               . '<p style="margin:.2rem 0;">Si vous etes la, c est que Mathieu vous a normalement autorise a consulter cette page... ou que vous etes un agent particulierement persuasif.</p>'
               . $logs
               . '</div>';
    return $back . $foot_text . '</body></html>';
}

/**
 * Supprime les diacritiques d'une chaîne UTF-8 et la met en minuscules.
 * Permet une comparaison insensible aux accents : "Meribel" trouve "Méribel".
 */
function strip_accents(string $s): string {
    $s    = mb_strtolower($s, 'UTF-8');
    $from = ['à','á','â','ã','ä','å','æ','ç','è','é','ê','ë','ì','í','î','ï','ñ','ò','ó','ô','õ','ö','œ','ù','ú','û','ü','ý','ÿ','À','Á','Â','Ã','Ä','Å','Æ','Ç','È','É','Ê','Ë','Ì','Í','Î','Ï','Ñ','Ò','Ó','Ô','Õ','Ö','Œ','Ù','Ú','Û','Ü','Ý','Ÿ'];
    $to   = ['a','a','a','a','a','a','ae','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','oe','u','u','u','u','y','y','a','a','a','a','a','a','ae','c','e','e','e','e','i','i','i','i','n','o','o','o','o','o','oe','u','u','u','u','y','y'];
    return str_replace($from, $to, $s);
}

/**
 * Construit un pattern regex qui accepte les variantes accentuées d'un terme.
 * Utilisé pour surligner les hits dans les extraits de recherche.
 * Ex. : "meribel" génère un pattern qui matche aussi "Méribel".
 */
function accent_insensitive_pattern(string $q): string {
    $map = [
        'a' => '[aàáâãäå]', 'e' => '[eèéêë]', 'i' => '[iìíîï]',
        'o' => '[oòóôõö]',  'u' => '[uùúûü]', 'c' => '[cç]',
        'n' => '[nñ]',      'y' => '[yýÿ]',
    ];
    $chars   = preg_split('//u', strip_accents($q), -1, PREG_SPLIT_NO_EMPTY);
    $pattern = '';
    foreach ($chars as $ch) {
        $pattern .= $map[$ch] ?? preg_quote($ch, '/');
    }
    return $pattern;
}

// ─── Listing des sections disponibles ───────────────────────────────────────

if ($section === 'list') {
    // Passe 1 : calcul du slug de chaque section (= identifiant court pour l'URL)
    //   - Section numérotée "## 3. Titre" → slug = "3" → URL /s3
    //   - Section nommée "## FAQ …"       → slug = premier mot en minuscules → URL /faq ou /table
    //     (les aliases /faq et /table sont déclarés dans .htaccess)
    $max_titre = max(array_map('mb_strlen', array_keys($sections)));
    $slugs     = [];
    foreach ($sections as $titre => $body) {
        if (preg_match('/^## (\d+)\./', $titre, $m)) {
            $slugs[$titre] = $m[1];  // numéro pur ex. "3"
        } else {
            $words = preg_split('/\s+/', preg_replace('/^##\s+/', '', $titre));
            $slugs[$titre] = mb_strtolower($words[0] ?? 'section');  // premier mot ex. "faq"
        }
    }

    // Détection du type de client :
    //   navigateur → Accept contient "text/html" → rendu HTML avec liens cliquables
    //   IA / curl  → Accept = "*/*" ou absent    → rendu texte aligné (machine-readable)
    $accept     = $_SERVER['HTTP_ACCEPT'] ?? '';
    $is_browser = str_contains($accept, 'text/html');
    // $_scheme et $_host sont définis dans tracker.php (require_once plus haut) :
    //   $_scheme = 'https' ou 'http' selon HTTPS/port
    //   $_host   = $_SERVER['HTTP_HOST'] ?? 'mathieu.charreyre.net'
    $base       = $_scheme . '://' . $_host . '/r3M3M83r/';
    // Sections non numérotées ayant un alias propre dans .htaccess (sans data.php?s=)
    $alias_propres = ['faq', 'table'];

    // Calcul des données communes : construit le tableau $rows utilisé par les deux
    // rendus (HTML et texte). Chaque entrée contient :
    //   'url'    → URL directe (avec alias propre si disponible, ex. /s3 ou /faq)
    //   'titre'  → titre Markdown complet + suffixe court entre parenthèses (ex. " (s3)")
    //   'taille' → taille du corps en octets (affiché en Ko dans le tableau)
    $rows = [];
    foreach ($sections as $titre => $body) {
        $slug = $slugs[$titre];
        if (ctype_digit($slug)) {
            // Section numérotée : URL propre /sN (ex. /s3, /s11)
            $url_section  = $base . 's' . $slug;
            $alias_suffix = ' (s' . $slug . ')';
        } elseif (in_array($slug, $alias_propres, true)) {
            // Section nommée avec alias déclaré dans .htaccess (ex. /faq, /table)
            $url_section  = $base . $slug;
            $alias_suffix = ' (' . $slug . ')';
        } else {
            // Section sans alias : URL avec paramètre data.php?s=slug
            $url_section  = $base . 'data.php?s=' . $slug;
            $alias_suffix = '';
        }
        $rows[] = [
            'url'    => $url_section,
            'titre'  => $titre . $alias_suffix,
            'taille' => strlen($body),
        ];
    }

    if ($is_browser) {
        // ── Rendu HTML pour navigateur (humains seulement) ──────────────────
        header('Content-Type: text/html; charset=UTF-8');
        echo html_shell('Sections — instructions.md');
        $line_count = substr_count($content, "\n") + 1;

        // Fraicheur du fichier source
        $mtime     = filemtime(SOURCE_FILE);
        $mois_fr   = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        $mtime_h   = date('j', $mtime) . ' ' . $mois_fr[(int)date('n', $mtime)] . ' ' . date('Y', $mtime) . ' à ' . date('H\hi', $mtime);
        $mtime_sm  = date('d/m/Y', $mtime) . ' à ' . date('H\hi', $mtime);
        $mtime_iso = date('c', $mtime);
        $age_days  = (int)floor((time() - $mtime) / 86400);
        $age_lbl   = $age_days === 0 ? "aujourd'hui" : ($age_days === 1 ? 'hier' : 'il y a ' . $age_days . ' jour' . ($age_days > 1 ? 's' : ''));
        $age_cls   = $age_days <= 7 ? 'f-fresh' : ($age_days <= 60 ? 'f-recent' : 'f-stale');

        $q     = trim($_GET['q'] ?? '');
        $q_esc = htmlspecialchars($q, ENT_QUOTES, 'UTF-8');
        $file_size_ko = round(filesize(SOURCE_FILE) / 1024);

        // ── En-tête ──────────────────────────────────────────────────────────
        echo '<h1>Sections disponibles dans <a href="https://mathieu.charreyre.net/r3M3M83r/instructions.md" target="_blank" rel="noopener noreferrer"><em>instructions.md</em></a> (' . $line_count . ' lignes - ' . $file_size_ko . ' Ko)</h1>';
        echo '<p class="file-meta">'
           . '<span class="lbl lbl-lg">Derni&egrave;re modification&nbsp;:</span>'
           . '<span class="lbl lbl-sm">MaJ&nbsp;:</span> '
           . '<time class="mtime-lg ' . $age_cls . '" datetime="' . htmlspecialchars($mtime_iso, ENT_QUOTES, 'UTF-8') . '"'
           . ' title="' . htmlspecialchars($mtime_h, ENT_QUOTES, 'UTF-8') . '">'
           . htmlspecialchars($mtime_h, ENT_QUOTES, 'UTF-8') . '</time>'
           . '<time class="mtime-sm ' . $age_cls . '" datetime="' . htmlspecialchars($mtime_iso, ENT_QUOTES, 'UTF-8') . '"'
           . ' title="' . htmlspecialchars($mtime_h, ENT_QUOTES, 'UTF-8') . '">'
           . htmlspecialchars($mtime_sm, ENT_QUOTES, 'UTF-8') . '</time>'
           . ' <span class="f-age ' . $age_cls . '">&middot;&nbsp;' . htmlspecialchars($age_lbl, ENT_QUOTES, 'UTF-8') . '</span>'
           . '</p>';

        // ── Formulaire de recherche ──────────────────────────────────────────
        echo '<div class="search-wrap">';
        echo '<form action="sections" method="get">';
        echo '<input type="search" name="q" id="q" placeholder="Rechercher dans le contenu ..." value="' . $q_esc . '" autocomplete="off" spellcheck="false">';
        echo '<div class="btn-group">';
        echo '<button type="submit">Chercher</button>';
        if ($q !== '') {
            echo '<a class="btn-clear" href="sections">✕ Effacer</a>';
        }
        echo '</div>';
        echo '</form>';
        echo '<p class="search-hint">Entrée → recherche plein texte &nbsp;&middot;&nbsp; filtrage instantané sur les titres ci-dessous</p>';
        echo '</div>';

        // JS : animation typewriter sur le placeholder (pause au focus, reprise au blur)
        $tw_terms = json_encode([
            'Fanette','Association WDA','Club-Internet',
            'Domaine Saint-Antonin','Ambre',
            'Subway','Jouques','WoW Alliance','Charles de Leusse',
            'Pitié-Salpêtrière','No Man\'s Sky','Netiquette','David WINTER','TOUN',
        ], JSON_UNESCAPED_UNICODE);
        echo '<script>(function(){';
        echo 'var i=document.getElementById(\'q\');if(!i||i.value!==\'\')return;';
        echo 'var t=' . $tw_terms . ',';
        echo 'base=\'Rechercher dans le contenu \u2026\',';
        echo 'idx=Math.floor(Math.random()*t.length),pos=0,del=false,tid=null;';
        echo 'function step(){var w=t[idx];if(!del){';
        echo 'pos++;i.placeholder=w.slice(0,pos);';
        echo 'if(pos>=w.length){del=true;tid=setTimeout(step,1800);}else{tid=setTimeout(step,80);}}';
        echo 'else{pos--;i.placeholder=w.slice(0,pos);';
        echo 'if(pos<=0){del=false;idx=(idx+1)%t.length;tid=setTimeout(step,400);}else{tid=setTimeout(step,40);}}}';
        echo 'i.addEventListener(\'focus\',function(){clearTimeout(tid);i.placeholder=\'\';});';
        echo 'i.addEventListener(\'blur\',function(){if(i.value===\'\'){i.placeholder=base;idx=(idx+1)%t.length;pos=0;del=false;tid=setTimeout(step,1500);}});';
        echo 'tid=setTimeout(step,2000);}());</script>';

        if ($q !== '' && mb_strlen($q) >= 2) {
            // ── Recherche plein texte dans le corps des sections ─────────────
            // Algorithme d'extraction d'extrait :
            //   1. Pour chaque section, on normalise sans accents et on cherche $q.
            //   2. Dès la première ligne contenant le hit, on essaie de réduire
            //      à la phrase exacte (split sur . ! ? + espace) pour un extrait court.
            //   3. Si pas de phrase isolable, on prend la ligne entière.
            //   4. Ajout d'ellipses intelligentes (…) avant/après si tronqué.
            //   5. L'extrait brut est htmlspecialchars'd puis les occurrences de $q
            //      sont entourées d'un <mark> via accent_insensitive_pattern().
            //   6. L'URL du résultat embarque ?q= pour que la page cible puisse
            //      déclencher le TreeWalker JS (highlight + scroll).
            $results = [];
            $q_stripped = strip_accents($q);
            foreach ($sections as $titre => $body) {
                if (mb_stripos(strip_accents($body), $q_stripped) !== false) {
                    // Aplatit les espaces/tabulations multiples pour des extraits propres
                    $body_flat   = preg_replace('/[ \t]+/', ' ', $body);
                    $lines       = explode("\n", $body_flat);
                    $excerpt_str = '';
                    $is_truncated_start = false;
                    $is_truncated_end   = false;
                    foreach ($lines as $line_idx => $bline) {
                        $bline = trim($bline);
                        if ($bline === '' || mb_stripos(strip_accents($bline), $q_stripped) === false) continue;
                        // Vérifier si tronqué au début (pas la première ligne non-vide du body)
                        $is_truncated_start = $line_idx > 0;
                        // Découper en phrases sur . ! ? suivi d'un espace
                        $sents = preg_split('/(?<=[.!?])\s+/', $bline, -1, PREG_SPLIT_NO_EMPTY);
                        if (count($sents) > 1) {
                            foreach ($sents as $sent_idx => $sent) {
                                if (mb_stripos(strip_accents($sent), $q_stripped) !== false) {
                                    $excerpt_str = trim($sent); // phrase minimale
                                    // Si pas la première phrase de la ligne, tronqué au début
                                    if ($sent_idx > 0) $is_truncated_start = true;
                                    // Si pas la dernière phrase de la ligne, tronqué à la fin
                                    if ($sent_idx < count($sents) - 1) $is_truncated_end = true;
                                    break;
                                }
                            }
                        }
                        if ($excerpt_str === '') { $excerpt_str = $bline; } // ligne complète
                        // Vérifier si tronqué à la fin (pas la dernière ligne du body)
                        if ($line_idx < count($lines) - 1) $is_truncated_end = true;
                        break;
                    }
                    $slug = $slugs[$titre];
                    if (ctype_digit($slug)) {
                        $url_r = $base . 's' . $slug;
                    } elseif (in_array($slug, $alias_propres, true)) {
                        $url_r = $base . $slug;
                    } else {
                        $url_r = $base . 'data.php?s=' . $slug;
                    }
                    $match_count = preg_match_all('/' . accent_insensitive_pattern($q) . '/iu', $body, $m) ?: 0;
                    $results[] = [
                        'titre'          => $titre,
                        'excerpt'        => $excerpt_str,
                        'url'            => $url_r,
                        'q'              => $q,
                        'count'          => $match_count,
                        'truncated_start' => $is_truncated_start,
                        'truncated_end'   => $is_truncated_end,
                    ];
                }
            }

            if ($intro !== '' && mb_stripos(strip_accents($intro), $q_stripped) !== false) {
                $intro_flat   = preg_replace('/[ \t]+/', ' ', $intro);
                $lines        = explode("\n", $intro_flat);
                $excerpt_str  = '';
                foreach ($lines as $bline) {
                    $bline = trim($bline);
                    if ($bline === '' || mb_stripos(strip_accents($bline), $q_stripped) === false) continue;
                    $sents = preg_split('/(?<=[.!?])\s+/', $bline, -1, PREG_SPLIT_NO_EMPTY);
                    if (count($sents) > 1) {
                        foreach ($sents as $sent) {
                            if (mb_stripos(strip_accents($sent), $q_stripped) !== false) {
                                $excerpt_str = trim($sent);
                                break;
                            }
                        }
                    }
                    if ($excerpt_str === '') { $excerpt_str = $bline; }
                    break;
                }
                $match_count = preg_match_all('/' . accent_insensitive_pattern($q) . '/iu', $intro, $m) ?: 0;
                $results[] = [
                    'titre'   => '## Avant-propos',
                    'excerpt' => $excerpt_str,
                    'url'     => $base . 'data.php?s=all',
                    'q'       => $q,
                    'count'   => $match_count,
                ];
            }

            if (empty($results)) {
                echo '<p class="search-info">Aucun résultat pour <strong>' . $q_esc . '</strong>.</p>';
            } else {
                $total_matches = array_sum(array_column($results, 'count'));
                $occ_label    = $total_matches === 1 ? 'occurrence' : 'occurrences';
                echo '<p class="search-info">' . count($results) . ' section(s) contenant <strong>' . $q_esc . '</strong> (' . $total_matches . ' ' . $occ_label . ')</p>';
                echo '<p class="search-hint" style="margin-top:-.5rem;margin-bottom:.8rem;color:#777;">💡 <strong>Cliquez sur une section</strong> pour afficher son contenu complet avec les mots-clefs surlignés et défilement automatique.</p>';
                foreach ($results as $r) {
                    $url_with_q = $r['url'] . '?q=' . rawurlencode($r['q']);
                    $url_rh  = htmlspecialchars($url_with_q,         ENT_QUOTES, 'UTF-8');
                    $titre_h = htmlspecialchars($r['titre'] . ' (' . $r['count'] . ' ' . ($r['count'] === 1 ? 'occurrence' : 'occurrences') . ')', ENT_QUOTES, 'UTF-8');
                    // Ajout des ellipses intelligentes si tronqué
                    $excerpt_raw = trim($r['excerpt']);
                    // Version pour copier (texte brut avec ellipses simples)
                    $copy_text = $excerpt_raw;
                    if ($r['truncated_start']) $copy_text = '[…] ' . $copy_text;
                    if ($r['truncated_end'])   $copy_text = $copy_text . ' […]';
                    // Version pour affichage (HTML échappé + balises d'ellipses stylées)
                    $exc_raw = htmlspecialchars($excerpt_raw, ENT_QUOTES, 'UTF-8');
                    if ($r['truncated_start']) $exc_raw = '<span class="ellipsis">[…]</span> ' . $exc_raw;
                    if ($r['truncated_end'])   $exc_raw = $exc_raw . ' <span class="ellipsis">[…]</span>';
                    $exc     = preg_replace('/(' . accent_insensitive_pattern($q) . ')/iu', '<mark>$1</mark>', $exc_raw);
                    $copy_h  = htmlspecialchars($copy_text, ENT_QUOTES, 'UTF-8');
                    echo "<div class=\"result\"><h3><a href=\"{$url_rh}\" title=\"Cliquer pour afficher la section complète avec surlignage\">➤ {$titre_h}</a></h3>"
                       . "<div class=\"excerpt-wrap\"><pre class=\"excerpt\">{$exc}</pre>"
                       . "<button class=\"btn-copy\" data-text=\"{$copy_h}\">⎘ Copier</button></div></div>\n";
                }
                // ── JS : copie de l'extrait dans le presse-papier ────────────
                // Chaque bouton "⎘ Copier" porte un attribut data-text avec l'extrait brut
                // (sans balises HTML). Utilise navigator.clipboard (API moderne, HTTPS requis).
                // Feedback visuel : "✓ Copié" pendant 1,5 s puis retour à "⎘ Copier".
                echo "<script>(function(){document.querySelectorAll('.btn-copy').forEach(function(b){b.addEventListener('click',function(){var t=this.dataset.text;navigator.clipboard.writeText(t).then(function(){b.textContent='✓ Copié';setTimeout(function(){b.textContent='⎘ Copier';},1500);});});});})();</script>";
            }

        } else {
            // ── Tableau normal + filtre JS instantané sur les titres ──────────
            echo '<p id="filter-info"></p>';
            echo '<table id="sections-table">';
            foreach ($rows as $r) {
                $ko    = round($r['taille'] / 1024, 1);
                $titre = htmlspecialchars($r['titre'], ENT_QUOTES, 'UTF-8');
                $url   = htmlspecialchars($r['url'],   ENT_QUOTES, 'UTF-8');
                echo "<tr data-titre=\"{$titre}\"><td><a href=\"{$url}\">{$url}</a></td><td class=\"td-titre\">{$titre}</td><td class=\"td-ko\">{$ko} Ko</td></tr>\n";
            }
            echo '</table>';
            // ── JS : filtre instantané sur les titres du tableau ──────────
            // Écoute l'événement 'input' sur #q (champ de recherche déjà présent
            // dans le formulaire). Normalise les accents côté JS (NFD + strip \u0300-\u036f)
            // pour matcher sans se soucier des diacritiques. Met à jour #filter-info
            // avec le nombre de lignes visibles. Aucun appel serveur : entièrement client.
            echo '<script>(function(){' .
                 'var i=document.getElementById(\'q\'),' .
                 't=document.getElementById(\'sections-table\'),' .
                 'n=document.getElementById(\'filter-info\');' .
                 'if(!i||!t)return;' .
                 'function da(s){return s.normalize("NFD").replace(/[\u0300-\u036f]/g,"");}' .
                 'i.addEventListener(\'input\',function(){' .
                 'var v=da(this.value.toLowerCase().trim()),' .
                 'rows=t.querySelectorAll(\'tr\'),c=0;' .
                 'rows.forEach(function(tr){' .
                 'var s=da((tr.getAttribute(\'data-titre\')||\'\')' . '.toLowerCase()),' .
                 'ok=v===\'\'||s.indexOf(v)!==-1;' .
                 'tr.style.display=ok?\'\':\'none\';' .
                 'if(ok)c++;' .
                 '});' .
                 'n.textContent=v===\'\'?\'\':c+\' section(s) affich\u00e9e(s)\';' .
                 '});' .
                 '}());</script>';
        }

        echo html_footer(false); // page /sections : pas de lien "retour" vers elle-même
    } else {
        // ── Rendu texte aligné pour IAs / curl ──────────────────────────────
        $line_count = substr_count($content, "\n") + 1;
        echo "Sections disponibles dans instructions.md ({$line_count} lignes) :\n";
        echo "(Fetcher l'URL indiquee pour charger une section)\n\n";
        foreach ($rows as $r) {
            $ko        = str_pad(round($r['taille'] / 1024, 1), 5, ' ', STR_PAD_LEFT);
            $url_pad   = str_repeat(' ', 55 - mb_strlen($r['url']));
            $titre_pad = str_repeat(' ', ($max_titre + 6) - mb_strlen($r['titre']));
            echo "  " . $r['url'] . $url_pad . "  →  " . $r['titre'] . $titre_pad . "  (" . $ko . " Ko)\n";
        }
    }
    exit;
}

// ─── Recherche de la section demandée ───────────────────────────────────────
// Deux stratégies, dans l'ordre :
//   1. Si ?s= est un entier pur (ex. "3") → match exact sur "## 3." en début de titre
//      Plus fiable qu'une recherche textuelle (évite de matcher "13" sur la section 3)
//   2. Sinon → tentative de correspondance exacte sur le slug de section,
//      puis recherche partielle case-insensitive si aucun slug ne correspond.

$found_sections = [];

if (ctype_digit($section)) {
    // Match exact numérique : "## 3." et non pas "## 13."
    foreach ($sections as $titre => $body) {
        if (preg_match('/^## ' . preg_quote($section, '/') . '\./', $titre)) {
            $found_sections[] = [
                'titre' => $titre,
                'body'  => $body,
            ];
            break;
        }
    }
} else {
    $section_stripped = strip_accents(mb_strtolower($section, 'UTF-8'));
    $slug_map = [];
    foreach ($sections as $titre => $body) {
        if (preg_match('/^## (\d+)\./', $titre, $m)) {
            $slug_map[$m[1]] = $titre;
        } else {
            $words = preg_split('/\s+/', preg_replace('/^##\s+/', '', $titre));
            $slug   = strip_accents(mb_strtolower($words[0] ?? '', 'UTF-8'));
            if ($slug !== '') {
                $slug_map[$slug] = $titre;
            }
        }
    }

    if (isset($slug_map[$section_stripped])) {
        $found_sections[] = [
            'titre' => $slug_map[$section_stripped],
            'body'  => $sections[$slug_map[$section_stripped]],
        ];
    } else {
        foreach ($sections as $titre => $body) {
            if (mb_stripos(strip_accents($titre), $section_stripped) !== false
                || mb_stripos(strip_accents($body), $section_stripped) !== false) {
                $found_sections[] = [
                    'titre' => $titre,
                    'body'  => $body,
                ];
            }
        }
        if (!empty($intro) && mb_stripos(strip_accents($intro), $section_stripped) !== false) {
            array_unshift($found_sections, [
                'titre' => '## Avant-propos',
                'body'  => $intro,
            ]);
        }
    }
}

if (!empty($found_sections)) {
    $accept     = $_SERVER['HTTP_ACCEPT'] ?? '';
    $is_browser = str_contains($accept, 'text/html');
    if ($is_browser) {
        $page_title = 'Résultats pour "' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '"';
        header('Content-Type: text/html; charset=UTF-8');
        echo html_shell($page_title . ' — instructions.md');
        echo '<a class="back" href="sections">← Retour à la liste des sections</a>';
        echo '<h1>' . $page_title . '</h1>';
        $hq = trim($_GET['q'] ?? '');
        foreach ($found_sections as $index => $found) {
            if ($index > 0) {
                echo '<hr>';
            }
            echo md_to_html($found['body']);
        }
        if ($hq !== '' && mb_strlen($hq) >= 2) {
            $hq_pattern = accent_insensitive_pattern($hq);
            $hq_js      = json_encode($hq_pattern, JSON_UNESCAPED_UNICODE);
            echo '<script>(function(){' .
                 'var q=' . $hq_js . ';' .
                 'var walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null,false);' .
                 'var nodes=[];var n;' .
                 'while((n=walker.nextNode()))nodes.push(n);' .
                 'var re=new RegExp(q,"gi");' .
                 'var first=null;' .
                 'nodes.forEach(function(tn){' .
                   'if(!tn.nodeValue.trim())return;' .
                   'var parts=tn.nodeValue.split(re);' .
                   'if(parts.length<2)return;' .
                   'var matches=tn.nodeValue.match(re);' .
                   'var frag=document.createDocumentFragment();' .
                   'parts.forEach(function(p,i){' .
                     'frag.appendChild(document.createTextNode(p));' .
                     'if(i<matches.length){' .
                       'var m=document.createElement("mark");' .
                       'm.textContent=matches[i];' .
                       'if(!first)first=m;' .
                       'frag.appendChild(m);' .
                     '}' .
                   '});' .
                   'tn.parentNode.replaceChild(frag,tn);' .
                 '});' .
                 'if(first)first.scrollIntoView({behavior:"smooth",block:"center"});' .
                 '}());</script>';
        }
        echo html_footer();
    } else {
        foreach ($found_sections as $index => $found) {
            if ($index > 0) {
                echo "\n\n---\n\n";
            }
            echo $found['body'];
        }
    }
} else {
    http_response_code(404);
    echo "Section '$section' introuvable.\n\nSections disponibles :\n\n";
    foreach (array_keys($sections) as $titre) {
        echo "  " . $titre . "\n";
    }
}
