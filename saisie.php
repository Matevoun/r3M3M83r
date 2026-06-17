<?php
/**
 * saisie.php - Interface de saisie locale pour instructions.md
 *
 * Ce fichier fournit une page HTML simple pour saisir un texte libre
 * et le faire analyser localement. Il ne modifie pas automatiquement
 * instructions.md : il propose des blocs rediges et une section cible.
 *
 * Boutons disponibles :
 *   - Analyser le contenu : nettoie le texte localement et propose des blocs.
 *   - Reformulation avancee avec IA : envoie le texte vers le service Node.js
 *     pour une reformulation par LLM, puis affiche le resultat.
 *
 * Ce fichier sert à raconter la vie de Mathieu. L'interface doit interpréter
 * un récit à la première personne, le transposer en troisième personne,
 * proposer où l'insérer dans instructions.md, et répondre aux questions
 * relatives au fichier de mémoire.
 *
 * Ce service Node.js est attendu comme application cPanel / O2Switch,
 * avec le script `reformulator/server.js` démarré et accessible depuis PHP.
 *
 * `server.js` est la source de verite pour la configuration LLM :
 *   - moteur actif (LLM_ENGINE)
 *   - ordre de fallback (LLM_FALLBACK_ORDER)
 *   - modele utilise (GROQ_MODEL, OPENAI_MODEL, ...)
 *   - URL de console accessible par l'interface
 *
 * `saisie.php` reste une interface legere et ne contient pas de logique
 * de reformulation LLM. Voir `reformulator/server.js` pour la logique
 * moteur / modèle et les routes `/llm-info` et `/reformuler`.
 *
 * Les logs sont écrits dans :
 *   - `reformulator/log/requests.log` (requêtes)
 *   - `reformulator/log/error.log` (erreurs PHP)
 *
 * RÈGLES D'OR POUR `saisie.php` :
 *   1. Toute modification doit être documentée clairement dans ce fichier.
 *   2. Ne pas déplacer la logique métier vers `reformulator/server.js` sans note.
 *   3. Le backend Node.js est géré par `reformulator/server.js`; ici, on reste interface.
 *   4. Toute nouvelle route ou dépendance externe doit être décrite dans les commentaires.
 *   5. En cas de panne du service, le code doit basculer proprement vers un fallback local.
 */

define('SOURCE_FILE', __DIR__ . '/instructions.md');

// Calcule l'URL de base du service reformulator.
// Priorite 1 : connexion directe via 127.0.0.1:PORT (lit le fichier .port ecrit par Node.js
//   au demarrage). Bypass complet d'Apache/Passenger, plus rapide et fiable.
// Priorite 2 : URL publique via HTTP_HOST (fallback si .port absent ou invalide).
// Priorite 3 : URL publique codee en dur (dernier recours).
// Toute modification de cette logique doit etre consignee dans les regles d'or.
function get_reformulator_base_url(): string {
    // Sur hébergement mutualisé o2switch/Passenger, la connexion directe
    // via 127.0.0.1:PORT n'est pas accessible depuis PHP (Passenger proxy).
    // On utilise toujours l'URL publique — identique au comportement de test_curl.php.
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/reformulator';
    }
    return 'https://charreyre.net/r3M3M83r/reformulator';
}

define('REFORMULATOR_BASE_URL', get_reformulator_base_url());

// URL du panneau de controle o2switch (cPanel).
define('CPANEL_URL', 'https://nombre.o2switch.net:2083');

// -----------------------------------------------------------------------------
// BLOC 1 : Recuperation des informations LLM depuis le backend Node.js
// -----------------------------------------------------------------------------

function parse_llm_info_from_server_file(): array {
    $filePath = __DIR__ . '/reformulator/server.js';
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    if ($content === false) {
        return [];
    }

    // Supprime les commentaires JavaScript pour éviter de parser des valeurs
    // présentes uniquement dans des lignes commentées.
    $content = preg_replace(['@//.*$@m', '@/\*.*?\*/@s'], '', $content);
    if ($content === null) {
        return [];
    }

    $info = [];

    if (!function_exists('normalize_engine_url')) {
        function normalize_engine_url(string $url): string {
            $url = trim($url);
            return preg_match('#^https?://#i', $url) ? $url : '';
        }
    }
    if (preg_match('/const\s+DEFAULT_LLM_ENGINE\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
        $info['defaultEngine'] = strtolower($matches[1]);
    }
    if (preg_match('/const\s+LLM_ENGINE\s*=\s*\(process\.env\[.*?\]\s*\|\|\s*([A-Za-z0-9_]+)\)\.toLowerCase\(\)/', $content, $matches)) {
        $info['engineName'] = strtoupper($matches[1]);
    }
    if (empty($info['engineName']) && !empty($info['defaultEngine'])) {
        $info['engineName'] = strtoupper($info['defaultEngine']);
    }

    $selectedEngine = strtolower($info['engineName'] ?? $info['defaultEngine'] ?? 'groq');

    if (preg_match('/\b' . preg_quote($selectedEngine, '/') . '\s*:\s*\{([^}]*)\}/i', $content, $matches)) {
        $engineBlock = $matches[1];
        if (preg_match('/defaultModel:\s*["\']([^"\']+)["\']/', $engineBlock, $vmatches)) {
            $info['selectedModel'] = trim($vmatches[1]);
        }
        if (preg_match('/engineUrl:\s*["\']([^"\']+)["\']/', $engineBlock, $umatches)) {
            $info['engineUrl'] = normalize_engine_url(trim($umatches[1]));
        }
    }

    if (empty($info['selectedModel'])) {
        if (preg_match('/\w+\s*:\s*\{[^}]*?defaultModel:\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $info['selectedModel'] = trim($matches[1]);
        }
    }
    if (empty($info['engineUrl']) && $selectedEngine === 'groq') {
        $info['engineUrl'] = 'https://console.groq.com/home';
    }
    if (empty($info['selectedModel'])) {
        $info['selectedModel'] = 'modele inconnu';
    }
    return $info;
}

// Appelle la route `/llm-info` exposée par reformulator/server.js pour récupérer
// la configuration LLM active. Si le service Node.js n'est pas joignable, on bascule
// sur un fallback local en lisant `reformulator/server.js` directement.
function get_llm_info(): array {
    $url = REFORMULATOR_BASE_URL . '/llm-info';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);        // augmenté
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // nouveau
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    close_curl_handle($ch);

    if ($error || !$result || $httpCode >= 400) {
        error_log("LLM-INFO unreachable - HTTP $httpCode - $error"); // pour debug
        $fallback = parse_llm_info_from_server_file();
        $fallback['reachable'] = false;
        $fallback['last_error'] = $error ?: "HTTP $httpCode";
        return $fallback;
    }

    $data = json_decode($result, true);
    if (!is_array($data) || empty($data['engineName'])) {
        $fallback = parse_llm_info_from_server_file();
        $fallback['reachable'] = false;
        return $fallback;
    }

    $data['reachable'] = true;
    return $data;
}

function get_requests_log_path(): string {
    return __DIR__ . '/reformulator/log/requests.log';
}

function get_error_log_path(): string {
    return __DIR__ . '/reformulator/log/error.log';
}

function ensure_reformulator_log_file(string $filePath): void {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!file_exists($filePath)) {
        @file_put_contents($filePath, '', LOCK_EX);
        @chmod($filePath, 0644);
    }
}

function append_requests_log(string $line): void {
    $filePath = get_requests_log_path();
    ensure_reformulator_log_file($filePath);
    @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);
    @chmod($filePath, 0644);
}

function log_reformulator_request(string $text): void {
    $date = date('d/m/Y H:i:s') . ' Europe/Paris';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
    $cleanText = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $text)));
    $length = mb_strlen($cleanText, 'UTF-8');
    $line = sprintf("[%s] REFORMULER IP=%s length=%d text=%s\n", $date, $ip, $length, $cleanText);
    append_requests_log($line);
}

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Europe/Paris');
} elseif (function_exists('ini_set')) {
    @ini_set('date.timezone', 'Europe/Paris');
}

if (function_exists('ini_set')) {
    // Redirige les erreurs PHP vers le fichier error.log (et non error_log !)
    @ini_set('error_log', __DIR__ . '/reformulator/log/error.log');
    @ini_set('log_errors', '1');
    ensure_reformulator_log_file(get_error_log_path());
}

// -----------------------------------------------------------------------------
// BLOC 2 : Appel du service Node.js pour reformulation LLM ou recherche de fallback
// -----------------------------------------------------------------------------
// Cette fonction envoie le texte saisi au backend Node.js pour reformulation LLM.
// Le backend est responsable de comprendre un récit à la première personne
// et de le transposer en mémoire à la troisième personne lorsque c'est nécessaire.
function reformuler_via_node(string $text, string $instructionsContext = ''): string {
    global $selected_engine;
    // Envoie le texte saisi au service Node.js local pour reformulation LLM.
    // La route distante peut etre ajustee si le serveur est deplace.
    $payload = ['text' => $text];
    if ($instructionsContext !== '') {
        $payload['instructionsContext'] = $instructionsContext;
    }
    if (!empty($selected_engine)) {
        $payload['engine'] = $selected_engine;
    }
    $ch = curl_init(REFORMULATOR_BASE_URL . '/reformuler');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    close_curl_handle($ch);
    if ($error || !$result) {
        return '';
    }
    $data = json_decode($result, true);
    return $data['cleaned'] ?? '';
}

function propose_emplacement_via_node(string $text, string $instructionsContext = ''): string {
    global $selected_engine;
    $payload = ['text' => $text, 'purpose' => 'location'];
    if ($instructionsContext !== '') {
        $payload['instructionsContext'] = $instructionsContext;
    }
    if (!empty($selected_engine)) {
        $payload['engine'] = $selected_engine;
    }
    $ch = curl_init(REFORMULATOR_BASE_URL . '/reformuler');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    close_curl_handle($ch);
    if ($error || !$result) {
        return '';
    }
    $data = json_decode($result, true);
    return $data['cleaned'] ?? '';
}

function call_reformulator_service(array $payload): string {
    global $last_reformulator_error, $selected_engine;
    $last_reformulator_error = '';
    // Injecte le moteur sélectionné par l'utilisateur si non déjà présent.
    if (!empty($selected_engine) && !isset($payload['engine'])) {
        $payload['engine'] = $selected_engine;
    }
    $ch = curl_init(REFORMULATOR_BASE_URL . '/reformuler');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $result = curl_exec($ch);
    $curlError  = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    close_curl_handle($ch);

    if ($curlError || !$result) {
        $last_reformulator_error = sprintf('Service inaccessible (HTTP %s). cURL erreur: %s', $statusCode ?: 'inconnu', $curlError ?: 'réponse vide');
        return '';
    }

    $data = json_decode($result, true);
    if (!is_array($data)) {
        $last_reformulator_error = sprintf('Réponse JSON invalide (HTTP %s) : %s', $statusCode, substr($result, 0, 500));
        return '';
    }

    if (!empty($data['cleaned'])) {
        return $data['cleaned'];
    }

    if (!empty($data['error'])) {
        $errorText = is_string($data['error']) ? $data['error'] : json_encode($data['error'], JSON_UNESCAPED_UNICODE);
        $last_reformulator_error = sprintf('Erreur LLM (HTTP %s) : %s', $statusCode, $errorText);
    } else {
        $last_reformulator_error = sprintf('Réponse LLM vide ou invalide (HTTP %s)', $statusCode);
    }
    return '';
}

function is_negative_query_answer(string $response): bool {
    $text = mb_strtolower(trim($response), 'UTF-8');
    if ($text === '') {
        return true;
    }
    $patterns = [
        '/aucune mention trouvee/',
        '/aucun(?:e)? resultat/',
        "/je n(?:'|’)?ai pas trouve(?:e|é)/",
        '/pas de mention/',
        '/rien trouve(?:e)?/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

function extract_query_keywords_via_node(string $text, string $instructionsContext = ''): string {
    $payload = ['text' => $text, 'purpose' => 'query-keywords'];
    if ($instructionsContext !== '') {
        $payload['instructionsContext'] = $instructionsContext;
    }
    return call_reformulator_service($payload);
}

function query_instructions_via_node(string $text, string $instructionsContext = ''): string {
    $payload = ['text' => $text, 'purpose' => 'query'];
    if ($instructionsContext !== '') {
        $payload['instructionsContext'] = $instructionsContext;
    }
    return call_reformulator_service($payload);
}

function finalize_query_response_via_node(string $question, string $localEvidence, string $instructionsContext = ''): string {
    $payload = ['text' => $question, 'purpose' => 'query'];
    if ($instructionsContext !== '') {
        $payload['instructionsContext'] = trim($instructionsContext);
    }
    if ($localEvidence !== '') {
        $payload['instructionsContext'] = trim(($payload['instructionsContext'] ?? "") . "\n\n" . $localEvidence);
    }
    return call_reformulator_service($payload);
}

function close_curl_handle($ch): void {
    if (function_exists('curl_close')) {
        call_user_func('curl_close', $ch);
    }
}

function is_question_text(string $text): bool {
    if (preg_match('/\?/', $text)) {
        return true;
    }
    $text = mb_strtolower($text, 'UTF-8');
    $questionWords = [
        'qui', 'quoi', 'où', 'ou', 'comment', 'pourquoi', 'quand', 'est-ce que',
        'ai-je', 'as-tu', 'avez-vous', 'peux-tu', 'dois-je', 'quel', 'quelle',
        'combien', 'faut-il', 'suis-je', 'y a-t-il'
    ];
    foreach ($questionWords as $word) {
        if (mb_strpos($text, $word) !== false) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// BLOC 3 : Traitement local du texte, classification et suggestions sans LLM
// -----------------------------------------------------------------------------
function load_section_titles(): array {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) return [];
    $content = file_get_contents(SOURCE_FILE);
    if ($content === false) return [];
    $lines  = explode("\n", $content);
    $titles = [];
    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
            $titles[] = trim($matches[1]);
        }
    }
    return $titles;
}

function normalize_string(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    $text = preg_replace('/\s+([\.,;:\?!])/u', '$1', $text);
    $text = preg_replace('/([\(\[\{"])\s+/u', '$1', $text);
    $text = preg_replace('/\s+([\)\]\}"])|\s+\p{Pd}/u', '$1', $text);
    $text = str_replace(['``', "''"], ['"', '"'], $text);
    return trim($text);
}

function remove_accents(string $text): string {
    $trans = [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ð'=>'D','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Þ'=>'TH','ß'=>'ss',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'d','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'th','ÿ'=>'y',
    ];
    return strtr($text, $trans);
}

function normalize_for_matching(string $text): string {
    $text = remove_accents($text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function extract_keywords(string $text): array {
    $text = normalize_for_matching($text);
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['et','la','le','les','des','du','de','un','une','pour','avec','dans','sur','au','aux','par','plus','se','qui','que','est','pas','ou','son','sa','ses','a','au','il','elle','ils','elles','ne','pas','ce','cette','ces','etre','avoir','sous','entre','sans','donc','mais','comme','car','afin','lors','tres','deja','parle','parlé','jai','tu','tui','te','toi','toi-même','moi'];
    $keywords = [];
    foreach ($words as $word) {
        if (mb_strlen($word, 'UTF-8') < 4) continue;
        if (in_array($word, $stopwords, true)) continue;
        $keywords[$word] = ($keywords[$word] ?? 0) + 1;
    }
    arsort($keywords);
    return array_keys($keywords);
}

function rewrite_paragraph(string $text): string {
    $text = normalize_string($text);
    if ($text === '') return '';
    $corrections = [
        'siasie'=>'saisie','siase'=>'saisie','arrneger'=>'arranger','aranger'=>'arranger',
        'tillimité'=>'illimité','tilimité'=>'illimité','comprennene'=>'comprenne',
        'uen'=>'une','u ne'=>'une','a faire'=>'à faire','c de'=>"c'est de",'c que'=>"c'est que",
        'donf'=>'à fond','a donf'=>'à fond',
    ];
    foreach ($corrections as $wrong => $correct) {
        $text = str_ireplace($wrong, $correct, $text);
    }
    $text = preg_replace('/\s+([\.\?!,;:])/u', '$1', $text);
    $text = preg_replace('/([\.\?!])\s*/u', '$1 ', $text);
    $text = trim($text);
    if (!preg_match('/[\.\?!]$/u', $text)) $text .= '.';
    $first = mb_substr($text, 0, 1, 'UTF-8');
    $rest  = mb_substr($text, 1, null, 'UTF-8');
    $text  = mb_strtoupper($first, 'UTF-8') . $rest;
    $text  = preg_replace('/\bje cherche a\b/ui', 'je cherche à', $text);
    return $text;
}

function find_dates(string $text): array {
    $patterns = [
        '/\b(\d{1,2})\s*[\/\-]\s*(\d{1,2})\s*[\/\-]\s*(\d{2,4})\b/',
        '/\b(\d{1,2})\s+(janvier|fevrier|février|mars|avril|mai|juin|juillet|aout|août|septembre|octobre|novembre|decembre|décembre)\s+(\d{4})\b/iu',
        '/\b(\d{4})\b/',
    ];
    $dates = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $dates[] = trim($match[0]);
        }
    }
    return array_values(array_unique($dates));
}

function find_names(string $text): array {
    $candidates = [];
    if (preg_match_all('/\b([A-Z][a-z]{2,})(?:\s+[A-Z][a-z]{2,})*/u', $text, $matches)) {
        foreach ($matches[0] as $name) {
            if (mb_strlen($name, 'UTF-8') >= 4) $candidates[$name] = true;
        }
    }
    return array_keys($candidates);
}

function build_section_values(array $titles): array {
    $values = [];
    foreach ($titles as $title) {
        $norm = normalize_for_matching($title);
        $values[$title] = array_unique(array_merge(extract_keywords($title), preg_split('/\s+/', $norm, -1, PREG_SPLIT_NO_EMPTY)));
    }
    return $values;
}

function classify_paragraph(string $paragraph, array $section_values): string {
    $keywords = extract_keywords($paragraph);
    $scores   = [];
    foreach ($section_values as $title => $terms) {
        $score = 0;
        foreach ($keywords as $word) {
            if (in_array($word, $terms, true)) $score += 2;
            if (strpos($title, ucfirst($word)) !== false) $score += 1;
        }
        $scores[$title] = $score;
    }
    arsort($scores);
    $best = key($scores);
    if ($scores[$best] < 2) {
        if (preg_match('/\b(chronologie|date|anniversaire|né|née|décès|mort|naissance|événement|evenement|194|195|196|197|198|199|200|201|202)\b/i', $paragraph))
            return 'Chronologie : ligne de vie et jalons clefs';
        if (preg_match('/\b(Domaine|Saint-Antonin|TOUN|WDA|indivision|refuge|Natura 2000|ASPAS|LPO)\b/u', $paragraph))
            return 'Chronologie : ligne de vie et jalons clefs';
        return 'A trier / Proposition libre';
    }
    return $best;
}

function build_results(string $input): array {
    $titles        = load_section_titles();
    $section_values = build_section_values($titles);
    $paragraphs    = preg_split('/\R{2,}/u', trim($input));
    $results       = [];
    foreach ($paragraphs as $index => $paragraph) {
        $clean = normalize_string($paragraph);
        if ($clean === '') continue;
        $dates   = find_dates($clean);
        $names   = find_names($clean);
        $section = classify_paragraph($clean, $section_values);
        $suggestions = [];
        if (!empty($dates)) $suggestions[] = 'Dates detectees : ' . implode(', ', $dates);
        if (!empty($names)) $suggestions[] = 'Noms detectes : ' . implode(', ', $names);
        $suggestions[] = ($section === 'A trier / Proposition libre')
            ? 'Section non identifiee avec certitude. Proposez un classement manuel.'
            : "Section proposee: {$section}";
        $suggestions[] = 'Reformulation interne appliquee. Aucun LLM externe ou local n est utilise.';
        $results[] = [
            'content'     => $clean,
            'rewrite'     => rewrite_paragraph($clean),
            'section'     => $section,
            'dates'       => $dates,
            'names'       => $names,
            'suggestions' => $suggestions,
            'id'          => 'block-' . ($index + 1),
        ];
    }
    return $results;
}

function html_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function extract_instructions_outline(): array {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) return [];
    $content = file_get_contents(SOURCE_FILE);
    if ($content === false) return [];
    if (!preg_match_all('/^##\s+(.+)$/m', $content, $matches)) {
        return [];
    }
    return array_map('trim', $matches[1]);
}

function load_instructions_excerpt(): string {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) return '';
    $content = file_get_contents(SOURCE_FILE);
    if ($content === false) return '';
    $excerpt = trim(preg_replace('/\s+/', ' ', substr($content, 0, 2000)));
    return $excerpt;
}

function load_instructions_content(): string {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) return '';
    $content = file_get_contents(SOURCE_FILE);
    if ($content === false) return '';
    return trim($content);
}

function count_instructions_lines(): int {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) {
        return 0;
    }
    $lines = file(SOURCE_FILE, FILE_IGNORE_NEW_LINES);
    return is_array($lines) ? count($lines) : 0;
}

function build_instructions_context_for_text(string $text): string {
    $outline = extract_instructions_outline();
    $excerpt = load_instructions_excerpt();
    $sections = extract_instructions_sections();
    $relevantSections = find_relevant_instruction_sections($text, $sections, 3);
    if (!empty($relevantSections)) {
        return build_instructions_context_with_sections($outline, $excerpt, $relevantSections);
    }
    [$bestSectionTitle, $bestSectionContent] = find_best_instruction_section($text, $sections);
    if ($bestSectionTitle !== '' && $bestSectionContent !== '') {
        return build_instructions_context($outline, $excerpt, $bestSectionTitle, $bestSectionContent);
    }
    return build_instructions_context($outline, $excerpt);
}

function build_instructions_context_with_sections(array $outline, string $excerpt, array $selectedSections): string {
    $context = 'Le fichier d\'instructions contient les sections suivantes : ' . implode(' ; ', $outline) . '.';
    if ($excerpt !== '') {
        $context .= ' Extrait du document : ' . $excerpt;
    }
    foreach ($selectedSections as $sectionTitle => $sectionContent) {
        $sectionContent = trim(preg_replace('/\s+/', ' ', $sectionContent));
        if (mb_strlen($sectionContent, 'UTF-8') > 800) {
            $sectionContent = mb_substr($sectionContent, 0, 800, 'UTF-8') . '...';
        }
        $context .= ' Section pertinente : ' . $sectionTitle . '. Contenu de la section : ' . $sectionContent;
    }
    return $context;
}

function build_instructions_context(array $outline, string $excerpt, string $sectionTitle = '', string $sectionContent = ''): string {
    $context = 'Le fichier d\'instructions contient les sections suivantes : ' . implode(' ; ', $outline) . '.';
    if ($excerpt !== '') {
        $context .= ' Extrait du document : ' . $excerpt;
    }
    if ($sectionTitle !== '' && $sectionContent !== '') {
        $sectionContent = trim(preg_replace('/\s+/', ' ', $sectionContent));
        if (mb_strlen($sectionContent, 'UTF-8') > 1200) {
            $sectionContent = mb_substr($sectionContent, 0, 1200, 'UTF-8') . '...';
        }
        $context .= ' Section probable : ' . $sectionTitle . '. Contenu de la section : ' . $sectionContent;
    }
    return $context;
}

function extract_instructions_sections(): array {
    if (!is_file(SOURCE_FILE) || !is_readable(SOURCE_FILE)) return [];
    $content = file_get_contents(SOURCE_FILE);
    if ($content === false) return [];

    $lines = preg_split('/\R/u', $content);
    $sections = [];
    $currentTitle = '';
    $currentContent = [];
    foreach ($lines as $line) {
        if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
            if ($currentTitle !== '') {
                $sections[$currentTitle] = trim(implode("\n", $currentContent));
            }
            $currentTitle = trim($matches[1]);
            $currentContent = [];
            continue;
        }
        if ($currentTitle !== '') {
            $currentContent[] = $line;
        }
    }
    if ($currentTitle !== '') {
        $sections[$currentTitle] = trim(implode("\n", $currentContent));
    }
    return $sections;
}

function find_best_instruction_section(string $text, array $sections): array {
    if (empty($sections)) return ['', ''];

    $textWords = extract_keywords($text);
    if (empty($textWords)) return ['', ''];

    $scores = [];
    foreach ($sections as $title => $content) {
        $score = 0;
        $normalizedTitle = normalize_for_matching($title);
        foreach ($textWords as $word) {
            if (strpos($normalizedTitle, $word) !== false) {
                $score += 3;
            }
            if (stripos($content, $word) !== false) {
                $score += 1;
            }
        }
        $scores[$title] = $score;
    }

    arsort($scores);
    $bestTitle = key($scores);
    if ($scores[$bestTitle] < 2) {
        return ['', ''];
    }
    return [$bestTitle, $sections[$bestTitle]];
}

function filter_search_terms(array $candidates): array {
    $terms = [];
    foreach ($candidates as $candidate) {
        $candidate = normalize_for_matching($candidate);
        $candidate = preg_replace('/\s+/', ' ', $candidate);
        if ($candidate === '' || mb_strlen($candidate, 'UTF-8') < 2) {
            continue;
        }
        $keywords = extract_keywords($candidate);
        foreach ($keywords as $keyword) {
            if (mb_strlen($keyword, 'UTF-8') >= 2) {
                $terms[] = $keyword;
            }
        }
    }
    return array_values(array_unique($terms));
}

function build_query_terms_from_llm(string $llmOutput, string $fallback = ''): array {
    $llmOutput = trim(preg_replace('/[\r\n]+/', ',', $llmOutput));
    $rawTerms = array_filter(array_map('trim', preg_split('/[,;|]+/', $llmOutput, -1, PREG_SPLIT_NO_EMPTY)));
    $terms = filter_search_terms($rawTerms);
    if (empty($terms) && $fallback !== '') {
        $terms = extract_keywords($fallback);
    }
    return array_values(array_unique($terms));
}

function score_term_document_frequency(string $term, array $sections): int {
    $count = 0;
    foreach ($sections as $content) {
        if (match_term_in_text($term, normalize_for_matching($content))) {
            $count++;
        }
    }
    return $count;
}

function filter_search_query_terms(array $terms): array {
    $stopwords = [
        'connais','connait','connaître','sais','sait','savoir','veux','veux-tu','pouvez','peux','peut',
        'voulez','voulons','avez','as','a','est','es','être','faire','fait','t','tu','vous','monsieur',
        'madame','svp','stp','please','parle','parlé','parler','parlez','dire','dit','ecrire','écrire',
        'ai-je','avez-vous','as-tu','suis-je','y','lui','leur','me','moi','mon','ma','mes','ton','ta','tes',
        'une','un','le','la','les','du','des','de','dans','sur','chez','pour','avec','sans','entre','par'
    ];
    $filtered = [];
    foreach ($terms as $term) {
        $term = trim($term);
        if ($term === '' || in_array($term, $stopwords, true)) {
            continue;
        }
        $filtered[] = $term;
    }
    return array_values(array_unique($filtered));
}

function build_search_term_label(array $searchTerms, string $query, array $sectionMatches): string {
    $queryTerms = filter_search_query_terms(extract_keywords($query));
    if (!empty($queryTerms)) {
        return implode(', ', $queryTerms);
    }

    $matchedTerms = [];
    foreach ($sectionMatches as $match) {
        $sentences = $match['matches'] ?? [];
        foreach ($searchTerms as $term) {
            foreach ($sentences as $sentence) {
                if (match_term_in_text($term, normalize_for_matching($sentence))) {
                    $matchedTerms[] = $term;
                    break;
                }
            }
        }
    }
    $matchedTerms = array_values(array_unique($matchedTerms));
    if (!empty($matchedTerms)) {
        return implode(', ', $matchedTerms);
    }

    return implode(', ', $searchTerms);
}

function choose_search_terms(string $query, string $llmOutput, array $sections): array {
    $queryTerms = filter_search_query_terms(extract_keywords($query));

    // Amélioration : on garde les noms propres même courts
    $rawQueryWords = preg_split('/\s+/', normalize_for_matching($query), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($rawQueryWords as $word) {
        if (preg_match('/^[A-Z]/u', $word) && mb_strlen($word) >= 3) {
            $queryTerms[] = $word;
        }
    }

    $llmTerms = filter_search_query_terms(build_query_terms_from_llm($llmOutput, ''));
    $candidates = array_unique(array_merge($queryTerms, $llmTerms));
    $candidates = array_values(array_filter($candidates, function ($value) {
        return $value !== '';
    }));

    $sectionCount = count($sections);
    $scored = [];
    foreach ($candidates as $term) {
        $df = score_term_document_frequency($term, $sections);
        if ($df === 0) continue;

        $scored[$term] = [
            'is_query' => in_array($term, $queryTerms, true) ? 1 : 0,
            'idf' => log(1 + ($sectionCount / $df)),
            'df' => $df,
            'length' => mb_strlen($term, 'UTF-8'),
        ];
    }

    if (empty($scored)) {
        return $queryTerms;
    }

    uasort($scored, function ($a, $b) {
        if ($a['is_query'] !== $b['is_query']) return $b['is_query'] <=> $a['is_query'];
        if ($a['idf'] !== $b['idf']) return $b['idf'] <=> $a['idf'];
        return $b['length'] <=> $a['length'];
    });

    $selected = array_slice(array_keys($scored), 0, 6, true); // un peu plus large
    return array_values($selected);
}

function match_term_in_text(string $term, string $text): bool {
    if ($term === '') {
        return false;
    }
    $pattern = '/\b' . preg_quote($term, '/') . '\b/u';
    return preg_match($pattern, $text) === 1;
}

function extract_section_match_sentences(string $content, array $terms, int $maxSentences = 12): array {
    $content = preg_replace('/\s+/', ' ', $content);
    $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
    $matches = [];
    foreach ($sentences as $sentence) {
        $normalizedSentence = normalize_for_matching($sentence);
        foreach ($terms as $term) {
            if (match_term_in_text($term, $normalizedSentence)) {
                $trimmed = trim($sentence);
                if ($trimmed !== '' && !in_array($trimmed, $matches, true)) {
                    $matches[] = $trimmed;
                    break;
                }
            }
        }
        if (count($matches) >= $maxSentences) {
            break;
        }
    }
    return $matches;
}

function search_instructions_sections_by_terms(array $terms, array $sections, int $maxResults = 0): array {
    $terms = array_filter(array_map('trim', $terms), function ($value) {
        return $value !== '';
    });
    if (empty($terms) || empty($sections)) {
        return [];
    }

    $sectionCount = count($sections);
    $termDocumentFrequency = [];
    foreach ($terms as $term) {
        $termDocumentFrequency[$term] = 0;
        foreach ($sections as $content) {
            if (match_term_in_text($term, normalize_for_matching($content))) {
                $termDocumentFrequency[$term]++;
            }
        }
    }

    $results = [];
    foreach ($sections as $title => $content) {
        $score = 0;
        $normalizedTitle = normalize_for_matching($title);
        $normalizedContent = normalize_for_matching($content);
        foreach ($terms as $term) {
            $idf = log(1 + ($sectionCount / max(1, $termDocumentFrequency[$term])));
            if (match_term_in_text($term, $normalizedTitle)) {
                $score += 6 * $idf;
            }
            if (match_term_in_text($term, $normalizedContent)) {
                $score += 3 * $idf;
            }
        }
        if ($score > 0) {
            $matches = extract_section_match_sentences($content, $terms, 12);
            if (!empty($matches)) {
                $results[$title] = [
                    'score' => $score,
                    'content' => $content,
                    'matches' => $matches,
                    'excerpt' => $matches[0],
                ];
            }
        }
    }

    if (empty($results)) {
        return [];
    }

    uasort($results, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    if ($maxResults > 0) {
        return array_slice($results, 0, $maxResults, true);
    }
    return $results;
}

function search_instructions_sections(string $query, array $sections, int $maxResults = 4): array {
    $llmOutput = extract_query_keywords_via_node($query, '');
    $terms = choose_search_terms($query, $llmOutput, $sections);
    if (empty($terms)) {
        return [];
    }
    return search_instructions_sections_by_terms($terms, $sections, $maxResults);
}

function search_instructions_sections_by_full_query(string $query, array $sections, int $maxResults = 0): array {
    $query = normalize_for_matching($query);
    if ($query === '' || mb_strlen($query, 'UTF-8') < 4) {
        return [];
    }
    $queryTerms = extract_keywords($query);
    $results = [];
    foreach ($sections as $title => $content) {
        $normalizedContent = normalize_for_matching($content);
        if (strpos($normalizedContent, $query) !== false) {
            $matches = extract_section_match_sentences($content, $queryTerms, 12);
            if (empty($matches)) {
                $snippet = trim(preg_replace('/\s+/', ' ', substr($content, 0, 200)));
                if ($snippet !== '') {
                    $matches[] = $snippet . '...';
                }
            }
            if (!empty($matches)) {
                $results[$title] = [
                    'score' => 100,
                    'content' => $content,
                    'matches' => $matches,
                    'excerpt' => $matches[0],
                ];
            }
        }
    }
    if (empty($results)) {
        return [];
    }
    if ($maxResults > 0) {
        return array_slice($results, 0, $maxResults, true);
    }
    return $results;
}

function search_instructions_sections_by_terms_loose(array $terms, array $sections, int $maxResults = 0): array {
    $terms = array_filter(array_map('trim', $terms), function ($value) {
        return $value !== '';
    });
    if (empty($terms) || empty($sections)) {
        return [];
    }

    $results = [];
    foreach ($sections as $title => $content) {
        $score = 0;
        $normalizedTitle = normalize_for_matching($title);
        $normalizedContent = normalize_for_matching($content);
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (strpos($normalizedTitle, $term) !== false) {
                $score += 5;
            }
            if (strpos($normalizedContent, $term) !== false) {
                $score += 3;
            }
        }
        if ($score > 0) {
            $matches = extract_section_match_sentences($content, $terms, 12);
            if (empty($matches)) {
                $snippet = trim(preg_replace('/\s+/', ' ', substr($content, 0, 200)));
                if ($snippet !== '') {
                    $matches[] = $snippet . '...';
                }
            }
            if (!empty($matches)) {
                $results[$title] = [
                    'score' => $score,
                    'content' => $content,
                    'matches' => $matches,
                    'excerpt' => $matches[0],
                ];
            }
        }
    }

    if (empty($results)) {
        return [];
    }

    uasort($results, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    if ($maxResults > 0) {
        return array_slice($results, 0, $maxResults, true);
    }
    return $results;
}

function extract_section_excerpt(string $content, array $terms): string {
    $content = preg_replace('/\s+/', ' ', $content);
    $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($sentences as $sentence) {
        $normalizedSentence = normalize_for_matching($sentence);
        foreach ($terms as $term) {
            if (match_term_in_text($term, $normalizedSentence)) {
                return trim($sentence);
            }
        }
    }
    return '';
}

function format_local_query_response(array $terms, array $sectionMatches, string $query): string {
    $queryLabel = !empty($terms) ? build_search_term_label($terms, $query, $sectionMatches) : 'votre question';
    $parts = [];
    $parts[] = "Oui, le fichier d\'instructions mentionne '{$queryLabel}'.";
    $parts[] = 'Sections concernées :';
    foreach ($sectionMatches as $title => $match) {
        $matches = $match['matches'] ?? [];
        $count = count($matches);
        $excerpt = '';
        if ($count > 0) {
            $excerpt = trim($matches[0]);
            if (mb_strlen($excerpt, 'UTF-8') > 160) {
                $excerpt = mb_substr($excerpt, 0, 157, 'UTF-8') . '...';
            }
        }
        $parts[] = sprintf('- %s (%d occurrence%s)%s', $title, $count, $count > 1 ? 's' : '', $excerpt !== '' ? ' : ' . $excerpt : '');
    }
    if (empty($sectionMatches)) {
        return 'Aucune mention trouvée dans le fichier d\'instructions.';
    }
    return implode("\n", $parts);
}

function find_relevant_instruction_sections(string $text, array $sections, int $maxSections = 3): array {
    if (empty($sections)) return [];

    $textWords = extract_keywords($text);
    if (empty($textWords)) return [];

    $matches = [];
    foreach ($sections as $title => $content) {
        $score = 0;
        $normalizedTitle = normalize_for_matching($title);
        foreach ($textWords as $word) {
            if (strpos($normalizedTitle, $word) !== false) {
                $score += 4;
            }
            if (stripos($content, $word) !== false) {
                $score += 2;
            }
        }
        if ($score > 0) {
            $matches[$title] = $score;
        }
    }

    if (empty($matches)) {
        return [];
    }

    arsort($matches);
    $selected = array_slice($matches, 0, $maxSections, true);
    $result = [];
    foreach ($selected as $title => $score) {
        $result[$title] = $sections[$title];
    }
    return $result;
}

// ---------------------------------------------------------------
// TRAITEMENT DU FORMULAIRE
// ---------------------------------------------------------------
$input_text               = '';
$reformule_original        = '';
$reformule_interpretation  = '';
$proposed_location         = '';
$query_result              = '';
$query_debug               = '';
$last_reformulator_error   = '';
$selected_engine           = '';  // Moteur IA choisi par l'utilisateur (état global)
$instructions_outline      = [];
$instructions_excerpt      = '';
$instructions_context      = '';
$instructions_loaded       = false;
$instructions_line_count   = 0;
$blocks                   = [];
$feedback                 = '';
$source_date              = '';
$reformule_msg            = '';
$source_url               = '';

if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $source_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/' . rawurlencode(basename(SOURCE_FILE));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'load_instructions') {
    $instructions_outline = extract_instructions_outline();
    $instructions_excerpt = load_instructions_excerpt();
    $instructions_context = build_instructions_context($instructions_outline, $instructions_excerpt);
    $lineCount = count_instructions_lines();
    header('Content-Type: application/json');
    echo json_encode([
        'outline' => $instructions_outline,
        'excerpt' => $instructions_excerpt,
        'context' => $instructions_context,
        'lines' => $lineCount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_text = trim($_POST['story'] ?? '');
    $instructions_context = trim($_POST['instructions_context'] ?? '');
    // Moteur IA sélectionné par l'utilisateur (vide = auto/fallback).
    $selected_engine = trim($_POST['selected_engine'] ?? '');

    // Si les instructions sont chargees, enrichir le contexte avec le contenu de la section la plus probable.
    if ($instructions_context !== '') {
        $sections = extract_instructions_sections();
        [$bestSectionTitle, $bestSectionContent] = find_best_instruction_section($input_text, $sections);
        if ($bestSectionTitle !== '' && $bestSectionContent !== '') {
            $instructions_context = build_instructions_context(
                extract_instructions_outline(),
                load_instructions_excerpt(),
                $bestSectionTitle,
                $bestSectionContent
            );
        }
    }

    // Bouton "Reformulation avec IA"
    if (isset($_POST['reformuler']) && $input_text !== '') {
        $reformule_original = $input_text;
        if ($instructions_context === '') {
            $instructions_context = build_instructions_context_for_text($input_text);
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }
        log_reformulator_request($input_text);
        $cleaned = reformuler_via_node($input_text, $instructions_context);
        if ($cleaned !== '') {
            $reformule_interpretation = $cleaned;
            $reformule_msg          = 'Voici ce que vous écriviez, et mon interprétation.';
        } else {
            $reformule_msg = 'Erreur : le reformulator n\'a pas répondu. Vérifiez que Node.js tourne.';
        }
    }

    // Bouton "Proposer emplacement"
    if (isset($_POST['proposer_emplacement']) && $input_text !== '') {
        $reformule_original = $input_text;
        if ($instructions_context === '') {
            $instructions_context = build_instructions_context_for_text($input_text);
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }
        $proposed_location = propose_emplacement_via_node($input_text, $instructions_context);
        if ($proposed_location !== '') {
            $reformule_msg = 'Emplacement proposé en fonction de la structure du fichier d\'instructions.';
        } else {
            $reformule_msg = 'Erreur : le service n\'a pas pu proposer un emplacement.';
        }
    }

    // Bouton "Interroger le fichier d'instructions"
    if (isset($_POST['query_instructions']) && $input_text !== '') {
        $reformule_original = $input_text;
        $sections = extract_instructions_sections();

        if ($instructions_context === '') {
            $instructions_context = build_instructions_context_for_text($input_text);
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }

        $query_result = '';
        $query_debug = "Moteur : " . ($selected_engine ?: strtoupper($llmInfo['engineName'] ?? 'AUTO')) . "\n\n";

        // === 1. On donne la priorité maximale à l'IA ===
        $llmResponse = query_instructions_via_node($input_text, $instructions_context);
        $query_debug .= "Réponse LLM : " . ($llmResponse ?: '(vide)') . "\n";

        if ($llmResponse !== '' && !is_negative_query_answer($llmResponse)) {
            $query_result = $llmResponse;
            $reformule_msg = 'Réponse générée directement par l’IA (elle a compris la question).';
        } else {
            $query_debug .= "→ LLM n'a pas trouvé de réponse utile → recherche locale renforcée\n";

            // Recherche locale très permissive pour les noms
            $llmKeywords = extract_query_keywords_via_node($input_text, $instructions_context);
            $searchTerms = choose_search_terms($input_text, $llmKeywords, $sections);

            // Force tous les noms propres
            preg_match_all('/\b[A-ZÀÂÄÉÈÊËÎÏÔÖÙÛÜ][a-zàâäéèêëîïôöùûü]+\b/u', $input_text, $nameMatches);
            foreach ($nameMatches[0] as $name) {
                $searchTerms[] = normalize_for_matching($name);
            }
            $searchTerms = array_unique(array_filter($searchTerms));

            $query_debug .= "Termes recherchés : " . implode(', ', $searchTerms) . "\n";

            $sectionMatches = search_instructions_sections_by_terms($searchTerms, $sections, 0);

            if (empty($sectionMatches)) {
                $sectionMatches = search_instructions_sections_by_full_query($input_text, $sections, 0);
            }

            if (!empty($sectionMatches)) {
                $query_result = format_local_query_response($searchTerms, $sectionMatches, $input_text);
                $reformule_msg = 'Résultat trouvé par recherche locale.';
            } else {
                $query_result = "Aucune mention trouvée dans instructions.md pour cette question.";
                $reformule_msg = 'Aucun résultat trouvé.';
            }
        }

        $query_debug .= "\nSections trouvées : " . count($sectionMatches ?? []) . "\n";
    }

    // Bouton "Charger les instructions"
    if (isset($_POST['charger_instructions'])) {
        $instructions_outline = extract_instructions_outline();
        $instructions_excerpt = load_instructions_excerpt();
        $instructions_context = build_instructions_context($instructions_outline, $instructions_excerpt);
        $instructions_loaded = true;
        $instructions_line_count = count_instructions_lines();
        $reformule_msg = 'Le fichier d\'instructions a été chargé et analysé.';
    }

    // Bouton "Analyser"
    // Nettoie le texte localement puis lance l'analyse de classification / reformulation interne.
    if (isset($_POST['analyser']) && $input_text !== '') {
        $clean_input = normalize_string($input_text);
        $cleaned     = ($clean_input !== $input_text);
        if ($cleaned) {
            $input_text = $clean_input;
        }
        $blocks   = build_results($input_text);
        $feedback = count($blocks) . ' bloc' . (count($blocks) > 1 ? 's' : '') . ' propose' . (count($blocks) > 1 ? 's' : '') . ' pour verification.';
        if ($cleaned) {
            $feedback .= ' Texte nettoye avant analyse.';
        }
    }

    if (is_file(SOURCE_FILE)) {
        $source_date = date('d/m/Y H:i', filemtime(SOURCE_FILE));
    }
}

$llmInfo = get_llm_info();
// TEST : Provoque une erreur PHP pour vérifier l’écriture dans error.log
if (isset($_GET['test_error_log'])) {
    trigger_error('Erreur de test volontaire pour vérifier error.log', E_USER_WARNING);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Saisie memoire — CHARREYRE</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;color:#1d1d1d}
.page{width:100%;max-width:1100px;margin:0 auto;padding:1rem}
.header{display:flex;flex-wrap:wrap;justify-content:space-between;gap:1rem;margin-bottom:1rem}
.header h1{font-size:1.4rem;margin:0}
.header .meta{font-size:.95rem;color:#555;line-height:1.5}
.card{background:#fff;border:1px solid #dadada;border-radius:8px;padding:1rem;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:1rem}
textarea{width:100%;min-height:240px;border:1px solid #bbb;border-radius:6px;padding:.8rem;font:1rem/1.5rem Arial,Helvetica,sans-serif;resize:vertical}
.btn-row{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem}
button{border:none;background:#16275b;color:#fff;padding:.72rem .95rem;border-radius:6px;font-size:.95rem;cursor:pointer;min-width:104px}
.btn-row button{min-width:110px}
button:hover{background:#0f1f4a}
button.ia{background:#0a3d62}
button.ia:hover{background:#072d4a}
button.test{background:#2d6aa5}
button.test:hover{background:#244f7b}
button.secondary{background:#8795b3;color:#fff;padding:.65rem .85rem;min-width:96px}
button.secondary:hover{background:#6d7a9b}
.btn-row-secondary{margin-top:.5rem}
.msg-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:6px;padding:.7rem 1rem;margin-bottom:.8rem}
.loading-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.48);display:none;align-items:center;justify-content:center;z-index:2000;padding:1rem}
.loading-overlay.open{display:flex}
.loading-box{background:#ffffff;display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:1.3rem 1.4rem;border-radius:14px;box-shadow:0 18px 45px rgba(0,0,0,.22);max-width:320px;width:100%;text-align:center}
.loading-spinner{width:48px;height:48px;border:5px solid rgba(22,39,91,.15);border-top-color:#16275b;border-radius:50%;animation:spin 1s linear infinite}
.loading-text{font-size:.98rem;color:#16275b;line-height:1.4}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.72);display:none;align-items:center;justify-content:center;z-index:1000;padding:1rem}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:14px;max-width:min(900px,100%);width:auto;min-width:320px;max-height:calc(100vh - 2rem);box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;background:#0f1f4a;color:#fff}
.modal-header h2{font-size:1rem;margin:0}
.modal-close{border:none;background:transparent;color:#fff;font-size:1.1rem;cursor:pointer;padding:.25rem .5rem}
.modal-body{flex:1;background:#fff;display:flex;flex-direction:column;overflow:hidden}
#test-output{flex:1;overflow:auto;margin:0; padding:1rem; background:#f7f9ff; color:#111; white-space:pre-wrap; word-break:break-word;}
.modal-footer{padding:.8rem 1rem;background:#f5f5f5;text-align:right;font-size:.93rem;color:#333}
@media(max-width:900px){.modal{max-width:calc(100% - 2rem);max-height:calc(100vh - 2rem);border-radius:10px}.modal-header,.modal-footer{padding:.8rem}.btn-row{flex-direction:column}button{width:100%}}
.msg-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:6px;padding:.7rem 1rem;margin-bottom:.8rem}
.section-suggestion{font-size:.95rem;margin:.25rem 0;color:#333}
.blocks{width:100%;display:grid;grid-template-columns:1fr;gap:1rem}
.block{border:1px solid #d2d2d2;border-radius:8px;padding:1rem;background:#fcfcff}
.block h2{font-size:1rem;margin:0 0 .65rem}
.block p{margin:.35rem 0;color:#444}
.block pre{white-space:pre-wrap;background:#f2f2ff;padding:.85rem;border-radius:6px;border:1px solid #e0e0ff;overflow:auto}
.block .copy{display:inline-block;margin-top:.5rem;padding:.45rem .8rem;background:#374e8c;color:#fff;border-radius:5px;font-size:.9rem;text-decoration:none;border:none;cursor:pointer}
.block .copy:hover{background:#2e4475}
.ia-result{border:1px solid #d2d2d2;border-radius:8px;padding:1rem;background:#fcfcff;margin-bottom:1rem}
.ia-result h2{font-size:1.05rem;margin:0 0 .8rem}
.ia-result pre{white-space:pre-wrap;background:#f7f9ff;padding:.85rem;border-radius:6px;border:1px solid #e0e0ff;overflow:auto;margin:0}
.summary{display:grid;grid-template-columns:1fr;gap:.5rem}
.summary .item{padding:.8rem;background:#eef2ff;border-radius:6px;border:1px solid #d6dff6}
.notice{font-size:.94rem;color:#333}
.footer{text-align:right;font-size:.85rem;color:#666;margin-top:1.2rem}
.meta-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.55rem;margin-top:.45rem}
.meta-line{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:.25rem 0}
.meta-line + .meta-line{margin-top:.8rem}
.meta-line strong{margin-right:.5rem}
.mini-button{font-size:.92rem;padding:.45rem .75rem;border-radius:6px;border:none;background:#16275b;color:#fff;cursor:pointer;white-space:nowrap;min-width:140px;max-width:180px;width:auto !important;transition:transform .14s ease,background .14s ease}
.mini-button:hover{background:#1f3d83;transform:translateY(-1px)}
.engine-select-row{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.7rem;padding:.55rem .7rem;background:#f0f3fa;border:1px solid #d0d8ef;border-radius:7px}
.engine-select-row label{font-size:.93rem;color:#333;white-space:nowrap}
.engine-select-row select{font-size:.93rem;padding:.35rem .6rem;border:1px solid #b0bdd8;border-radius:5px;background:#fff;color:#1d1d1d;cursor:pointer}
.engine-badge{display:inline-block;font-size:.82rem;padding:.2rem .55rem;border-radius:4px;background:#d4e4f7;color:#0a3d62;font-weight:bold;white-space:nowrap}
@media(max-width:720px){.meta-actions{flex-direction:column;align-items:flex-start} .mini-button{width:auto !important}}
@media(min-width:720px){.blocks{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.header{flex-direction:column}button{width:100%}}
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div>
      <h1>Interface de saisie mémoriel de MaT.</h1>
      <p class="notice">Saisir un texte libre. L'outil propose des blocs rédigés et un classement par section. Aucune modification automatique n'est effectuée.</p>
    </div>
    <div class="meta">
      <p class="meta-line">
        <button type="button" id="reset-page" class="mini-button" title="Recharge la page saisie.php à zéro, sans contexte ni historique">Reset</button>
      </p>
      <p class="meta-line">
        Cible analysee : <strong><a href="<?php echo html_escape($source_url ?: basename(SOURCE_FILE)); ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir <?php echo html_escape(basename(SOURCE_FILE)); ?> dans un nouvel onglet"><?php echo html_escape(basename(SOURCE_FILE)); ?></a></strong>
        <button type="button" id="load-instructions" class="mini-button" title="Recharger le contexte du fichier d'instructions">Recharger contexte</button>
      </p>
      <?php if ($source_date !== ''): ?>
      <p>Date du fichier cible : <?php echo html_escape($source_date); ?></p>
      <?php endif; ?>
      <p>Analyse locale disponible — la reformulation avancée utilise un service LLM externe.</p>
      <div class="instructions-status-container">
        <?php if ($instructions_loaded && $instructions_line_count > 0): ?>
          <div class="msg-ok" style="margin:0 0 1rem 0;">Contexte d'instructions chargé — <?php echo html_escape((string)$instructions_line_count); ?> lignes lues.</div>
        <?php endif; ?>
      </div>
      <div class="meta-actions">
        <p style="margin:0">Moteur LLM demandé : <strong><?php
            $engineName = html_escape(strtoupper($llmInfo['engineName'] ?? 'INCONNU'));
            $engineUrl = $llmInfo['engineUrl'] ?? '';
            $modelName = $llmInfo['selectedModel'] ?? 'modele inconnu';
            $displayText = $engineName . ' (' . html_escape($modelName) . ')';

            if ($selected_engine !== '') {
                $displayText = html_escape(strtoupper($selected_engine)) . ' <em>(manuel)</em>';
            }

            if ($engineUrl !== '') {
                echo '<a href="' . html_escape($engineUrl) . '" target="_blank" rel="noopener noreferrer">' . $displayText . '</a>';
            } else {
                echo $displayText;
            }
        ?></strong></p>
        <button type="button" id="open-test-modal" class="mini-button" title="Tester le moteur LLM">Tester</button>
      </div>
    </div>
  </div>

  <?php if (!($llmInfo['reachable'] ?? true)): ?>
  <div class="msg-err" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:.3rem;">
    <span>&#9888;&#65039; <strong>Service Node.js inaccessible.</strong> La reformulation IA ne fonctionnera pas tant que le service n&rsquo;est pas d&eacute;marr&eacute;.</span>
    <a href="<?php echo html_escape(CPANEL_URL); ?>" target="_blank" rel="noopener noreferrer" style="background:#0a3d62;color:#fff;padding:.45rem .85rem;border-radius:6px;text-decoration:none;white-space:nowrap;font-size:.9rem;font-weight:bold;">Ouvrir cPanel o2switch &#8594;</a>
  </div>
  <div class="msg-err" style="font-size:.9rem;margin-top:-.4rem;margin-bottom:.8rem;">
    Dans cPanel &#8594; <strong>Node.js Apps</strong> &#8594; chercher l&rsquo;application <strong>reformulator</strong> &#8594; bouton <strong>Restart</strong>. Si l&rsquo;app n&rsquo;appara&icirc;t pas, elle a peut-&ecirc;tre &eacute;t&eacute; arr&ecirc;t&eacute;e ou d&eacute;sactiv&eacute;e.
  </div>
  <?php endif; ?>

  <?php if ($reformule_msg !== ''): ?>
  <div class="<?php echo (str_contains($reformule_msg, 'Erreur')) ? 'msg-err' : 'msg-ok'; ?>">
    <?php echo html_escape($reformule_msg); ?>
  </div>
  <?php endif; ?>

  <?php if ($reformule_original !== ''): ?>
  <div class="ia-result">
    <h2>Rendu :</h2>
    <p><strong>Ce que vous écriviez :</strong></p>
    <pre><?php echo html_escape($reformule_original); ?></pre>
    <?php if ($reformule_interpretation !== ''): ?>
      <div style="margin-top:1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <p style="margin:0"><strong>Mon interprétation :</strong></p>
        <button class="copy" type="button" data-target="interpretation-output">Copier l'interprétation</button>
      </div>
      <pre id="interpretation-output"><?php echo html_escape($reformule_interpretation); ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($proposed_location !== ''): ?>
  <div class="ia-result">
    <h2>Emplacement proposé :</h2>
    <pre><?php echo html_escape($proposed_location); ?></pre>
  </div>
  <?php endif; ?>

  <?php if ($query_result !== ''): ?>
  <div class="ia-result">
    <h2>Réponse du fichier d'instructions :</h2>
    <pre><?php echo html_escape($query_result); ?></pre>
  </div>
  <?php endif; ?>

  <?php if ($query_debug !== ''): ?>
  <div class="msg-err" style="white-space:pre-wrap; font-size:.93rem; margin-top:.8rem;">
    <?php echo html_escape($query_debug); ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <form method="post" action="">
      <label for="story"><strong>Texte libre</strong></label>
      <textarea id="story" name="story" placeholder="Raconte ta vie à la première personne ; l'IA le transposera en mémoire de Mathieu à la troisième personne." required><?php echo html_escape($input_text); ?></textarea>
      <p class="notice">Le service IA corrige, reformule et transpose à la troisième personne, puis propose où ranger le souvenir dans le fichier d'instructions. Le bouton “Interroger” interroge le fichier d'instructions pour répondre à une question.</p>
      <input type="hidden" name="instructions_context" value="<?php echo html_escape($instructions_context); ?>">
      <?php
        // Construction de la liste des moteurs disponibles pour le sélecteur.
        $availableEngines = $llmInfo['availableEngines'] ?? [];
        $currentEngineName = strtolower($llmInfo['engineName'] ?? 'groq');
        // Si la liste est vide (service non joignable), on propose les moteurs connus.
        if (empty($availableEngines)) {
            $availableEngines = ['groq', 'cerebras', 'mistral', 'openrouter'];
        }
        $engineLabels = ['groq' => 'Groq', 'cerebras' => 'Cerebras', 'mistral' => 'Mistral', 'openrouter' => 'OpenRouter'];
      ?>
      <div class="engine-select-row">
        <label for="selected_engine">Moteur IA :</label>
        <select id="selected_engine" name="selected_engine">
          <option value="" <?php echo ($selected_engine === '') ? 'selected' : ''; ?>>Auto — <?php echo html_escape(strtoupper($currentEngineName)); ?> (défaut)</option>
          <?php foreach ($availableEngines as $eng): ?>
          <option value="<?php echo html_escape($eng); ?>" <?php echo ($selected_engine === $eng) ? 'selected' : ''; ?>>
            <?php echo html_escape($engineLabels[$eng] ?? ucfirst($eng)); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($selected_engine !== ''): ?>
          <span class="engine-badge"><?php echo html_escape(strtoupper($selected_engine)); ?> sélectionné</span>
        <?php endif; ?>
      </div>
      <div class="btn-row">
        <button type="submit" name="analyser" class="secondary" title="Analyser le texte localement">Analyse simple</button>
        <button type="submit" name="query_instructions" class="test" title="Interroger le fichier d'instructions">Interroger</button>
        <button type="submit" name="proposer_emplacement" class="test" title="Proposer un emplacement dans le fichier d'instructions">Proposer emplacement</button>
        <button type="submit" name="reformuler" class="ia" title="Reformuler le texte en utilisant le moteur LLM">Reformulation avancee avec IA</button>
      </div>
    </form>
  </div>

  <?php if ($feedback !== ''): ?>
  <div class="card">
    <div class="summary">
      <div class="item"><strong>Resultat</strong><br><?php echo html_escape($feedback); ?></div>
      <div class="item"><strong>Instructions de securite</strong><br>Copier les blocs qui conviennent, puis inserer manuellement dans le fichier memoriel ou le fichier d'instructions.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal-overlay" id="test-modal">
    <div class="modal">
      <div class="modal-header">
        <h2>Test du moteur de reformulation</h2>
        <button class="modal-close" id="close-test-modal" type="button">Fermer</button>
      </div>
      <div class="modal-body">
        <pre id="test-output" style="margin:0; padding:1rem; background:#f7f9ff; color:#111; overflow:auto; min-height:240px; white-space:pre-wrap; word-break:break-word;">Chargement du benchmark en cours ...</pre>
      </div>
      <div class="modal-footer" id="modal-footer" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
        <span style="font-size:.9rem;">Si le chargement n&rsquo;aboutit pas : <a id="modal-fallback" href="test_curl.php" target="_blank" rel="noopener noreferrer" style="color:#0f1f4a;text-decoration:underline;">Ouvrir dans un nouvel onglet</a></span>
        <button id="copy-test-output" type="button" style="background:#374e8c;color:#fff;border:none;padding:.4rem .85rem;border-radius:5px;cursor:pointer;font-size:.9rem;flex-shrink:0;">Copier le rapport</button>
      </div>
    </div>
  </div>

  <?php if (!empty($blocks)): ?>
  <div class="blocks">
    <?php foreach ($blocks as $block): ?>
      <section class="block" id="<?php echo html_escape($block['id']); ?>">
        <h2>Bloc <?php echo html_escape(substr($block['id'], 6)); ?></h2>
        <p class="section-suggestion"><strong>Section proposee :</strong> <?php echo html_escape($block['section']); ?></p>
        <?php foreach ($block['suggestions'] as $suggestion): ?>
          <p class="notice"><?php echo html_escape($suggestion); ?></p>
        <?php endforeach; ?>
        <p><strong>Texte propose :</strong></p>
        <pre id="output-<?php echo html_escape($block['id']); ?>"><?php echo html_escape($block['rewrite']); ?></pre>
        <button class="copy" type="button" data-target="output-<?php echo html_escape($block['id']); ?>">Copier le bloc redige</button>
        <details>
          <summary>Voir le texte original</summary>
          <pre><?php echo html_escape($block['content']); ?></pre>
        </details>
      </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="footer">
    <p>Pour plus de securite, inserer toujours a la main dans le fichier cible.</p>
    <p style="font-size:.82rem; margin-top:.5rem; color:#444;">
      <a href="reformulator/log_proxy.php?name=error_log" target="_blank" rel="noopener noreferrer">Voir les erreurs</a>
      •
      <a href="reformulator/log_proxy.php?name=requests_log" target="_blank" rel="noopener noreferrer">Voir les requêtes</a>
    </p>
  </div>
</div>
<div class="loading-overlay" id="loading-overlay" aria-hidden="true">
  <div class="loading-box">
    <div class="loading-spinner" aria-hidden="true"></div>
    <div class="loading-text">Traitement en cours… Merci de patienter.</div>
  </div>
</div>
<script>
for (const button of document.querySelectorAll('.copy')) {
    button.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        if (!target) return;
        navigator.clipboard.writeText(target.textContent).then(() => {
            const originalText = button.textContent;
            button.textContent = 'Copie effectuee';
            setTimeout(() => { button.textContent = originalText; }, 1600);
        }).catch(() => {
            const originalText = button.textContent;
            button.textContent = 'Erreur de copie';
            setTimeout(() => { button.textContent = originalText; }, 1600);
        });
    });
}

const modal = document.getElementById('test-modal');
const output = document.getElementById('test-output');
const openModal = document.getElementById('open-test-modal');
const closeModal = document.getElementById('close-test-modal');
const modalFooter = document.getElementById('modal-footer');

function openTestModal() {
    if (output && modal) {
        output.textContent = 'Chargement du benchmark en cours ...';
        modal.classList.add('open');

        // Récupère le moteur actuellement sélectionné dans le formulaire
        const engineSelect = document.getElementById('selected_engine');
        let engineParam = '';
        if (engineSelect && engineSelect.value !== '') {
            engineParam = '?engine=' + encodeURIComponent(engineSelect.value);
        }

        fetch('./test_curl.php' + engineParam, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/plain'
            },
            cache: 'no-store'
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' ' + response.statusText);
                }
                return response.text();
            })
            .then((text) => {
                output.textContent = text;
            })
            .catch((error) => {
                output.textContent = 'Erreur de chargement : ' + error.message + '\n\n' +
                    'Essayer le lien ci-dessous si le chargement direct échoue.';
            });
    }
}

function closeTestModal() {
    if (modal) {
        modal.classList.remove('open');
    }
    if (output) {
        output.textContent = 'Chargement du benchmark en cours ...';
    }
}

const loadInstructionsButton = document.getElementById('load-instructions');
const resetPageButton = document.getElementById('reset-page');
const instructionsStatusContainers = document.querySelectorAll('.instructions-status-container');
const instructionsContextInput = document.querySelector('input[name="instructions_context"]');

const storedInstructions = sessionStorage.getItem('saisie_instructions_context');
const storedInstructionsLoaded = sessionStorage.getItem('saisie_instructions_loaded') === '1';
const storedInstructionsLineCount = parseInt(sessionStorage.getItem('saisie_instructions_line_count') || '0', 10);
if (storedInstructions) {
    instructionsContextInput.value = storedInstructions;
}
if (storedInstructionsLoaded) {
    showInstructionsLoadedStatus(storedInstructionsLineCount);
    if (loadInstructionsButton) {
        loadInstructionsButton.textContent = 'Recharger';
    }
} else {
    hideInstructionsLoadedStatus();
    if (loadInstructionsButton) {
        loadInstructionsButton.textContent = 'Charger instructions';
        // Chargement automatique du contexte au premier affichage de la page
        setTimeout(function() { if (loadInstructionsButton) loadInstructionsButton.click(); }, 0);
    }
}

if (loadInstructionsButton) {
    loadInstructionsButton.addEventListener('click', function () {
        // Reset any previous instructions state before reloading from zero.
        sessionStorage.removeItem('saisie_instructions_context');
        sessionStorage.removeItem('saisie_instructions_loaded');
        sessionStorage.removeItem('saisie_instructions_line_count');
        hideInstructionsLoadedStatus();
        instructionsContextInput.value = '';
        loadInstructionsButton.disabled = true;
        loadInstructionsButton.textContent = 'Rechargement...';
        fetch(window.location.pathname + '?action=load_instructions', {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        })
            .then((response) => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then((data) => {
                showInstructionsLoadedStatus(data.lines || 0);
                sessionStorage.setItem('saisie_instructions_context', data.context);
                sessionStorage.setItem('saisie_instructions_loaded', '1');
                sessionStorage.setItem('saisie_instructions_line_count', String(data.lines || 0));
                instructionsContextInput.value = data.context;
                loadInstructionsButton.textContent = 'Recharger';
            })
            .catch((error) => {
                console.error(error);
                loadInstructionsButton.textContent = 'Erreur de chargement';
            })
            .finally(() => {
                loadInstructionsButton.disabled = false;
            });
    });
}

if (resetPageButton) {
    resetPageButton.addEventListener('click', function () {
        sessionStorage.removeItem('saisie_instructions_context');
        sessionStorage.removeItem('saisie_instructions_loaded');
        sessionStorage.removeItem('saisie_instructions_line_count');
        window.location.href = window.location.pathname;
    });
}

const form = document.querySelector('form');
const loadingOverlay = document.getElementById('loading-overlay');
if (form) {
    form.addEventListener('submit', function () {
        const stored = sessionStorage.getItem('saisie_instructions_context');
        if (instructionsContextInput && stored) {
            instructionsContextInput.value = stored;
        }
        if (loadingOverlay) {
            loadingOverlay.classList.add('open');
        }
    });
}

function hideLoadingOverlay() {
    if (loadingOverlay) {
        loadingOverlay.classList.remove('open');
    }
}

function showInstructionsLoadedStatus(lineCount) {
    lineCount = lineCount || 0;
    if (!instructionsStatusContainers) return;
    instructionsStatusContainers.forEach(function(container) {
        if (container) {
            var lineInfo = lineCount > 0 ? ' \u2014 ' + lineCount + ' lignes lues' : '';
            container.innerHTML = '<div class="msg-ok" style="margin:0 0 1rem 0;">Contexte d\'instructions charg\u00e9' + lineInfo + '.</div>';
        }
    });
}

function hideInstructionsLoadedStatus() {
    if (!instructionsStatusContainers) return;
    instructionsStatusContainers.forEach((container) => {
        if (container) {
            container.innerHTML = '';
        }
    });
}

function renderInstructions() {
    showInstructionsLoadedStatus();
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

if (openModal) {
    openModal.addEventListener('click', openTestModal);
}
// Mise à jour dynamique du moteur affiché en haut
const engineSelect = document.getElementById('selected_engine');
const engineDisplay = document.querySelector('.meta-actions p strong');

function updateEngineDisplay() {
    if (!engineSelect || !engineDisplay) return;

    const selectedValue = engineSelect.value.trim();
    let displayText = '';

    if (selectedValue === '') {
        // Option "Auto"
        displayText = 'GROQ (défaut)';
    } else {
        // Moteur sélectionné manuellement
        displayText = selectedValue.toUpperCase() + ' (manuel)';
    }

    engineDisplay.textContent = displayText;

    // Petit effet visuel
    engineDisplay.style.transition = 'color 0.4s';
    engineDisplay.style.color = '#0a3d62';
    setTimeout(() => { engineDisplay.style.color = ''; }, 1200);
}

if (engineSelect && engineDisplay) {
    engineSelect.addEventListener('change', updateEngineDisplay);
    // Mise à jour initiale au chargement
    setTimeout(updateEngineDisplay, 100);
}
if (closeModal) {
    closeModal.addEventListener('click', closeTestModal);
}
if (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeTestModal();
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal && modal.classList.contains('open')) {
        closeTestModal();
    }
});

const copyTestOutputButton = document.getElementById('copy-test-output');
if (copyTestOutputButton) {
    copyTestOutputButton.addEventListener('click', function () {
        var text = output ? output.textContent : '';
        navigator.clipboard.writeText(text).then(function () {
            var orig = copyTestOutputButton.textContent;
            copyTestOutputButton.textContent = 'Copie effectuee !';
            setTimeout(function () { copyTestOutputButton.textContent = orig; }, 1800);
        }).catch(function () {
            var orig = copyTestOutputButton.textContent;
            copyTestOutputButton.textContent = 'Erreur de copie';
            setTimeout(function () { copyTestOutputButton.textContent = orig; }, 1800);
        });
    });
}
</script>
</body>
</html>