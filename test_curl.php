<?php
/**
 * test_curl.php
 *
 * Outil de verification pour le service de reformulation LLM.
 * Il envoie un texte de test vers l'endpoint configure et affiche:
 *   - la phrase de test envoye
 *   - la reponse brute du serveur
 *   - la phrase nettoyage/reformulee retournee par l'IA
 *   - les versions de PHP, cURL, Node.js, NPM et des dependances du package local.
 *
 * Note : sur o2switch mutualise, Node.js est gere via cPanel.
 * URL cPanel actuelle pour Node.js :
 *   https://nombre.o2switch.net:2083/cpsess4982455582/frontend/o2switch/lveversion/nodejs-selector.html.tt#/applications/public_html%2FCHARREYRE%2Fr3M3M83r%2Freformulator
 */

// Determine l'endpoint de test : connexion directe 127.0.0.1:PORT si .port existe,
// sinon fallback sur l'URL publique. Meme logique que saisie.php.
$portFile = __DIR__ . '/reformulator/.port';
$endpointBase = '';
$connectionMode = '';
if (is_file($portFile) && is_readable($portFile)) {
    $portRaw = trim((string)@file_get_contents($portFile));
    if ($portRaw !== '' && ctype_digit($portRaw) && (int)$portRaw > 0 && (int)$portRaw < 65536) {
        $endpointBase = 'http://127.0.0.1:' . $portRaw;
        $connectionMode = 'direct localhost:' . $portRaw . ' (via .port)';
    }
}
if ($endpointBase === '') {
    $endpointBase = 'https://charreyre.net/r3M3M83r/reformulator';
    $connectionMode = 'URL publique (fallback)';
}
$endpoint = $endpointBase . '/reformuler';
$testText = 'toto fait du ski';
if (function_exists('ini_set')) {
    @ini_set('error_log', __DIR__ . '/reformulator/log/error.log');
    @ini_set('log_errors', '1');
    ensure_reformulator_log_file(get_error_log_path());
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

function log_test_curl_request(string $text): void {
    $date = date('d/m/Y H:i:s') . ' Europe/Paris';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $length = mb_strlen($text, 'UTF-8');
    $line = sprintf("[%s] TEST_CURL IP=%s length=%d\n", $date, $ip, $length);
    append_requests_log($line);
}

log_test_curl_request($testText);

$isCli = php_sapi_name() === 'cli';
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$wantPlain = $isCli || $isAjax || isset($_GET['plain']);

function parse_llm_info_from_server_file(): array {
    $serverFile = __DIR__ . '/reformulator/server.js';
    if (!is_file($serverFile)) {
        return [];
    }

    $content = file_get_contents($serverFile);
    if ($content === false) {
        return [];
    }

    // Supprime les commentaires JavaScript pour éviter de parser des valeurs
    // présentes uniquement dans la documentation ou les blocs commentés.
    $content = preg_replace(['@//.*$@m', '@/\*.*?\*/@s'], '', $content);
    if ($content === null) {
        return [];
    }

    $info = [];
    if (preg_match('/const\s+LLM_ENGINE\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
        $info['engineName'] = strtoupper($matches[1]);
    }
    if (preg_match('/const\s+DEFAULT_LLM_ENGINE\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
        $info['defaultEngine'] = strtoupper($matches[1]);
    }
    if (preg_match('/defaultModel:\s*["\']([^"\']+)["\']/', $content, $matches)) {
        $info['selectedModel'] = trim($matches[1]);
    }
    if (empty($info['engineName']) && !empty($info['defaultEngine'])) {
        $info['engineName'] = $info['defaultEngine'];
    }
    if (empty($info['selectedModel'])) {
        $info['selectedModel'] = 'inconnu';
    }
    return $info;
}

function get_llm_info(): array {
    $url = 'https://charreyre.net/r3M3M83r/reformulator/llm-info';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result !== false) {
        $decoded = json_decode($result, true);
        if (is_array($decoded) && !empty($decoded['engineName'])) {
            return [
                'engineName' => strtoupper($decoded['engineName'] ?? 'INCONNU'),
                'selectedModel' => $decoded['selectedModel'] ?? 'inconnu',
                'engineUrl' => $decoded['engineUrl'] ?? '',
            ];
        }
    }

    return parse_llm_info_from_server_file();
}

$llmInfo = get_llm_info();
$llmEngine = $llmInfo['engineName'] ?? 'INCONNU';
$llmModel = $llmInfo['selectedModel'] ?? 'inconnu';

if (!$wantPlain) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Test Reformulation LLM</title>';
    echo '<style>body{font-family:Menlo,Consolas,monospace;background:#f4f4f4;color:#111;padding:1rem}pre{background:#fff;border:1px solid #ccc;padding:1rem;white-space:pre-wrap;word-break:break-word;line-height:1.4;margin:0}</style></head><body><pre>';
} else {
    header('Content-Type: text/plain; charset=UTF-8');
}

echo "--- TEST DE REFORMULATION LLM ---\n";
echo str_repeat('=', 50) . "\n";
echo "Endpoint de test : $endpoint\n";
echo "Mode de connexion : $connectionMode\n";
echo "Phrase de test   : $testText\n";
echo "Moteur LLM utilise : $llmEngine ($llmModel)\n\n";

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $testText]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$result = curl_exec($ch);
$error  = curl_error($ch);
$code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$info   = curl_getinfo($ch);
if (function_exists('curl_close')) {
    call_user_func('curl_close', $ch);
}

$decoded = json_decode($result, true);
$cleaned = null;
if (is_array($decoded) && array_key_exists('cleaned', $decoded)) {
    $cleaned = $decoded['cleaned'];
}

$curlInfo = curl_version();
$packageJsonPath = __DIR__ . '/reformulator/package.json';
$packageInfo = null;
if (is_file($packageJsonPath)) {
    $packageInfo = json_decode(file_get_contents($packageJsonPath), true);
}

echo str_repeat('=', 50) . "\n";
echo "HTTP code     : $code\n";
echo "cURL erreur   : " . ($error !== '' ? $error : 'aucune') . "\n";
echo "Temps de reponse : " . round($info['total_time'] ?? 0, 3) . " s\n";
echo str_repeat('=', 50) . "\n\n";

echo "--- REPONSE SERVEUR BRUTE ---\n";
if ($result === false || trim($result) === '') {
    echo "aucune reponse brute\n\n";
} else {
    $trimmed = trim($result);
    if ($decoded === null) {
        echo "Corps non JSON ou page d'erreur renvoyée (" . strlen($trimmed) . " octets).\n";
        $preview = substr($trimmed, 0, 400);
        echo $preview . (strlen($trimmed) > 400 ? "... (truncated)\n" : "\n");
        echo "\n";
    } else {
        echo $trimmed . "\n\n";
    }
}

echo "--- REPONSE SERVEUR NETTOYEE ---\n";
if ($code === 404) {
    echo "Erreur : URL introuvable (HTTP 404).\n\n";
    echo "Causes probables :\n";
    if (strpos($connectionMode, 'localhost') !== false) {
        echo "  - Node.js a ecrit le fichier .port mais n'ecoute plus sur ce port.\n";
        echo "  - Relancer Node.js dans cPanel => Node.js Apps => Restart.\n";
        echo "  - Apres redemarrage, le fichier .port est regenere.\n";
    } else {
        echo "  - Node.js n'est pas demarre ou Passenger ne proxifie pas les requetes.\n";
        echo "  - Solution : cPanel => Node.js Apps => votre app reformulator :\n";
        echo "      Application URL    = /r3M3M83r/reformulator\n";
        echo "      Application Root   = public_html/CHARREYRE/r3M3M83r/reformulator\n";
        echo "      Startup file       = server.js\n";
        echo "    Puis cliquer Save et Restart.\n";
        echo "  - Note : apres le premier redemarrage, le fichier .port sera cree\n";
        echo "    et les prochains appels passeront en connexion directe localhost.\n";
    }
    echo "\n";
} elseif ($code >= 500) {
    if (is_array($decoded) && !empty($decoded['error'])) {
        $details = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);
        echo "Erreur LLM : $details\n\n";
    } else {
        echo "Erreur serveur : HTTP $code. Réponse non JSON ou non attendue.\n\n";
    }
} elseif ($cleaned !== null) {
    echo trim($cleaned) . "\n\n";
} else {
    if ($result === false || trim($result) === '') {
        echo "Aucune réponse JSON valide retournée par le serveur.\n\n";
    } else {
        echo "Réponse reçue, mais aucun champ 'cleaned' trouvé. Vérifiez le backend Node.js.\n\n";
    }
}

echo str_repeat('=', 50) . "\n";
echo "ENVIRONNEMENT\n";
echo str_repeat('-', 50) . "\n";
echo "PHP version : " . phpversion() . "\n";
echo "cURL version : " . ($curlInfo['version'] ?? 'inconnu') . "\n";


if ($packageInfo !== null) {
    echo str_repeat('=', 50) . "\n";
    echo "PACKAGE LOCAL\n";
    echo str_repeat('-', 50) . "\n";
    echo "name    : " . ($packageInfo['name'] ?? 'inconnu') . "\n";
    echo "version : " . ($packageInfo['version'] ?? 'inconnu') . "\n";
    if (!empty($packageInfo['dependencies'])) {
        echo "dependances :\n";
        foreach ($packageInfo['dependencies'] as $name => $version) {
            $version = str_replace(["\\n", "\r"], ["\n", ''], trim((string) $version));
            foreach (explode("\n", $version) as $line) {
                echo "  - $name: " . trim($line) . "\n";
            }
        }
    }
}

echo str_repeat('=', 50) . "\n";
echo "FIN DU TEST\n";
echo str_repeat('=', 50) . "\n";

if (!$wantPlain) {
    echo '</pre></body></html>';
}
?>