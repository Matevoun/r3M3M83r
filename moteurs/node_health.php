<?php
/**
 * r3M3M83r/moteurs/node_health.php
 * ---------------------------------------------------------------------------
 * Ping + tentative de reveil du service Node (Passenger / o2switch).
 * Appele en AJAX au chargement de Rebecca et Reformulator (saisie.php).
 *
 * GET  -> JSON { ok, stage, message, diagnosis, attempts, engine? }
 * stages : ok | waking | down
 *
 * CREATED : 29/08/2026 — Mathieu CHARREYRE
 * ---------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

/**
 * Une tentative de contact /llm-info.
 * Fichier autonome (pas d'include functions.php) pour rester leger au ping.
 */
function node_health_ping_once(string $url): array {
    if (!function_exists('curl_init')) {
        $raw = @file_get_contents($url);
        if ($raw === false) {
            return ['ok' => false, 'http' => 0, 'error' => 'file_get_contents failed', 'body' => ''];
        }
        $data = json_decode($raw, true);
        $ok = is_array($data) && !empty($data['engineName']);
        return ['ok' => $ok, 'http' => 200, 'error' => '', 'body' => $raw, 'data' => $data];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'r3M3M83r-NodeHealth/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    $ok = ($err === '' && $code >= 200 && $code < 400 && is_array($data) && !empty($data['engineName']));
    return ['ok' => $ok, 'http' => $code, 'error' => $err, 'body' => (string) $body, 'data' => $data];
}

// URL publique du service Node (meme logique simplifiee que get_reformulator_base_url)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'mathieu.charreyre.net';
$base = $scheme . '://' . $host . '/r3M3M83r/moteurs';

$url = $base . '/llm-info';
$maxAttempts = 4;
$delaySec = 2;
$attempts = [];
$last = null;

for ($i = 1; $i <= $maxAttempts; $i++) {
    $last = node_health_ping_once($url);
    $attempts[] = [
        'n'    => $i,
        'http' => $last['http'],
        'ok'   => $last['ok'],
        'error'=> $last['error'] !== '' ? $last['error'] : null,
    ];
    if ($last['ok']) {
        echo json_encode([
            'ok'         => true,
            'stage'      => 'ok',
            'message'    => 'Service Node actif.',
            'diagnosis'  => 'ok',
            'attempts'   => $attempts,
            'engine'     => $last['data']['engineName'] ?? null,
            'model'      => $last['data']['selectedModel'] ?? null,
            'woke'       => $i > 1,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Echec : on retente (reveil Passenger)
    if ($i < $maxAttempts) {
        sleep($delaySec);
    }
}

$diag = 'down';
if ($last && (int) $last['http'] === 404) {
    $diag = 'route';
} elseif ($last && (int) $last['http'] === 406) {
    $diag = 'blocked';
} elseif ($last && (int) $last['http'] >= 500) {
    $diag = 'app_error';
}

echo json_encode([
    'ok'        => false,
    'stage'     => 'down',
    'message'   => 'Le service Node.js (moteurs) ne repond pas apres plusieurs tentatives de reveil.',
    'diagnosis' => $diag,
    'detail'    => $last
        ? trim(($last['error'] ?: '') . ' HTTP ' . $last['http'])
        : 'aucune reponse',
    'attempts'  => $attempts,
    'hint'      => 'Demander a l\'administrateur de redemarrer l\'application Node.js dans le cPanel o2switch (Node.js Selector → Restart).',
], JSON_UNESCAPED_UNICODE);
