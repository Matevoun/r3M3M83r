<?php
/**
 * test_curl.php — outil de vérification du service LLM
 * Affiche la séquence réellement tentée (attempts) renvoyée par Node.js.
 *
 * Modification du 19/06/2026 : Prompt de test pré-renseigné riche et délirant
 * comme demandé par Mathieu.
 */

$endpointBase  = 'https://charreyre.net/r3M3M83r/reformulator';
$endpoint      = $endpointBase . '/reformuler';

// === PROMPT TEST PRÉ-RENSIGNÉ (optimisé) ===
$defaultTestText = 'Tu es en mode TEST PUR. Réponds de manière directe et créative. Raconte un fait totalement absurde et drôle avec Mathieu CHARREYRE. Commence directement par l\'histoire, sans répéter ma phrase.';

$testText = trim($_REQUEST['text'] ?? $defaultTestText);
if (empty($testText)) {
    $testText = $defaultTestText;
}

$testEngine = trim($_GET['engine'] ?? '');
if ($testEngine !== '' && in_array($testEngine, ['groq','cerebras','mistral','openrouter'], true)) {
    $testEngineLabel = strtoupper($testEngine) . ' (manuel)';
} else {
    $testEngine = '';
    $testEngineLabel = null;
}

function get_requests_log_path(): string { return __DIR__ . '/reformulator/log/requests.log'; }
function get_error_log_path(): string    { return __DIR__ . '/reformulator/log/error.log'; }
function ensure_reformulator_log_file(string $p): void {
    $d = dirname($p);
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!file_exists($p)) { @file_put_contents($p, '', LOCK_EX); @chmod($p, 0644); }
}
function append_requests_log(string $line): void {
    $p = get_requests_log_path(); ensure_reformulator_log_file($p);
    @file_put_contents($p, $line, FILE_APPEND | LOCK_EX); @chmod($p, 0644);
}
function log_test_curl_request(string $text): void {
    $date = date('d/m/Y H:i:s') . ' Europe/Paris';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    append_requests_log(sprintf("[%s] TEST_CURL IP=%s length=%d\n", $date, $ip, mb_strlen($text, 'UTF-8')));
}

if (function_exists('ini_set')) {
    @ini_set('error_log', get_error_log_path());
    @ini_set('log_errors', '1');
}
ensure_reformulator_log_file(get_error_log_path());
log_test_curl_request($testText);

$isCli     = php_sapi_name() === 'cli';
$isAjax    = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$wantPlain = $isCli || $isAjax || isset($_GET['plain']);

// ── Info LLM ─────────────────────────────────────────────────────────────────
function parse_llm_info_from_server_file(): array {
    $f = __DIR__ . '/reformulator/server.js';
    if (!is_file($f)) return [];
    $c = file_get_contents($f);
    if ($c === false) return [];
    $c = preg_replace(['@//.*$@m', '@/\*.*?\*/@s'], '', $c);
    if ($c === null) return [];
    $info = [];
    if (preg_match('/const\s+DEFAULT_LLM_ENGINE\s*=\s*["\']([^"\']+)["\']/', $c, $m)) $info['engineName'] = strtoupper($m[1]);
    if (preg_match('/defaultModel:\s*["\']([^"\']+)["\']/', $c, $m)) $info['selectedModel'] = trim($m[1]);
    if (empty($info['selectedModel'])) $info['selectedModel'] = 'inconnu';
    return $info;
}
function get_llm_info(): array {
    $ch = curl_init('https://charreyre.net/r3M3M83r/reformulator/llm-info');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $r = curl_exec($ch); curl_close($ch);
    if ($r !== false) {
        $d = json_decode($r, true);
        if (is_array($d) && !empty($d['engineName'])) return [
            'engineName'       => strtoupper($d['engineName'] ?? 'INCONNU'),
            'selectedModel'    => $d['selectedModel'] ?? 'inconnu',
            'engineUrl'        => $d['engineUrl'] ?? '',
            'fallbackOrder'    => $d['fallbackOrder'] ?? [],
            'availableEngines' => $d['availableEngines'] ?? [],
        ];
    }
    return parse_llm_info_from_server_file();
}

$llmInfo   = get_llm_info();
$llmEngine = $llmInfo['engineName'] ?? 'INCONNU';

// ── Appel reformulateur ──────────────────────────────────────────────────────
$payload = ['text' => $testText];
if ($testEngine !== '') $payload['engine'] = $testEngine;

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$result   = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$timing   = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

$decoded         = json_decode($result, true);
$cleaned         = is_array($decoded) && isset($decoded['cleaned'])  ? $decoded['cleaned']  : null;
$usedEngine      = is_array($decoded) && isset($decoded['engine'])   ? $decoded['engine']   : null;
$usedModel       = is_array($decoded) && isset($decoded['model'])    ? $decoded['model']    : null;
$attempts        = is_array($decoded) && isset($decoded['attempts']) ? $decoded['attempts'] : [];
$llmErrorDetails = null;
if (is_array($decoded) && !empty($decoded['details'])) $llmErrorDetails = $decoded['details'];
elseif (is_array($decoded) && !empty($decoded['error']))
    $llmErrorDetails = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);

$curlInfo        = curl_version();
$packageJsonPath = __DIR__ . '/reformulator/package.json';

// ── Rendu ────────────────────────────────────────────────────────────────────
if (!$wantPlain) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Test LLM</title>';
    echo '<style>body{font-family:Menlo,Consolas,monospace;background:#f4f4f4;color:#111;padding:1rem}pre{background:#fff;border:1px solid #ccc;padding:1rem;white-space:pre-wrap;word-break:break-word;line-height:1.4;margin:0}</style></head><body><pre>';
} else {
    header('Content-Type: text/plain; charset=UTF-8');
}

$sep = str_repeat('=', 56);
$sub = str_repeat('-', 56);

echo "--- TEST DE REFORMULATION LLM ---\n$sep\n";
echo "Endpoint        : $endpoint\n";
echo "Moteur DEMANDÉ  : " . ($testEngineLabel ?? "AUTO — $llmEngine") . "\n";
echo "Fallback order  : " . implode(' → ', $llmInfo['fallbackOrder'] ?? []) . "\n";
echo "Moteurs dispo   : " . implode(', ', $llmInfo['availableEngines'] ?? []) . "\n";
echo "$sep\n\n";

echo "=== PROMPT ENVOYÉ ===\n";
echo $testText . "\n\n";
echo "$sep\n\n";

echo "HTTP code       : $httpCode\n";
echo "Temps réponse   : " . round($timing, 3) . " s\n";
echo "cURL erreur     : " . ($curlErr !== '' ? $curlErr : 'aucune') . "\n\n";

// ── Séquence réellement tentée ───────────────────────────────────────────────
if (!empty($attempts)) {
    echo "$sub\nSÉQUENCE TENTÉE\n$sub\n";
    foreach ($attempts as $i => $a) {
        $n      = $i + 1;
        $eng    = strtoupper($a['engine'] ?? '?');
        $mod    = $a['model'] ?? '?';
        $status = $a['status'] ?? '?';
        $err    = $a['error'] ?? '';
        $icon   = ($status === 'success') ? '✓' : (($status === 'skipped') ? '○' : '✗');
        echo "  $icon $n. $eng ($mod) → $status";
        if ($err !== '' && $err !== null) echo " — $err";
        echo "\n";
    }
    echo "\n";
}

// ── Résultat ─────────────────────────────────────────────────────────────────
echo "$sub\nRÉSULTAT\n$sub\n";

if ($cleaned !== null && $cleaned !== '') {
    $finalEngine = strtoupper($usedEngine ?? '?');
    $finalModel  = $usedModel ?? '?';
    echo "✓ Reformulation OK via $finalEngine ($finalModel) :\n\n$cleaned\n";
} elseif ($httpCode === 0 || $curlErr !== '') {
    echo "✗ Impossible de joindre le service Node.js.\n";
    echo "  → cPanel › Node.js Apps › reformulator › Restart\n";
    echo "  → URL attendue : $endpointBase\n";
} elseif ($httpCode === 404) {
    echo "✗ HTTP 404 — route introuvable.\n";
    echo "  → Vérifier Application URL = /r3M3M83r/reformulator et Startup file = server.js\n";
    if ($result !== false && trim($result) !== '')
        echo "\n  Réponse brute :\n  " . substr(trim($result), 0, 600) . "\n";
} elseif ($httpCode === 429) {
    echo "✗ HTTP 429 — rate limit (voir séquence ci-dessus pour le moteur concerné).\n";
    if ($llmErrorDetails) echo "  Détail API : $llmErrorDetails\n";
    echo "  → Attendre et réessayer, ou choisir un autre moteur\n";
} elseif ($httpCode >= 500) {
    echo "✗ HTTP $httpCode — erreur Node.js (voir séquence ci-dessus).\n";
    if ($llmErrorDetails) echo "\n  Détail : $llmErrorDetails\n";
    if (is_array($decoded))
        echo "\n  JSON complet :\n" . json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    elseif ($result !== false && trim($result) !== '')
        echo "\n  Réponse brute :\n  " . substr(trim($result), 0, 800) . "\n";
    echo "\n  Causes fréquentes : Modèle inexistant, clef API invalide, quota dépassé\n";
} else {
    echo "✗ Réponse inattendue (HTTP $httpCode).\n";
    if ($result !== false && trim($result) !== '')
        echo "\n  Réponse brute :\n  " . substr(trim($result), 0, 600) . "\n";
}

echo "\n$sep\nENVIRONNEMENT\n$sub\n";
echo "PHP     : " . phpversion() . "\n";
echo "cURL    : " . ($curlInfo['version'] ?? 'inconnu') . "\n";

if (is_file($packageJsonPath)) {
    $pkg = json_decode(file_get_contents($packageJsonPath), true);
    if (is_array($pkg)) {
        echo "$sep\nPACKAGE REFORMULATOR\n$sub\n";
        echo "name    : " . ($pkg['name'] ?? '?') . "\n";
        echo "version : " . ($pkg['version'] ?? '?') . "\n";
        if (!empty($pkg['dependencies']))
            foreach ($pkg['dependencies'] as $n => $v) echo "  $n: $v\n";
    }
}
echo "$sep\nFIN DU TEST\n$sep\n";
if (!$wantPlain) echo '</pre></body></html>';