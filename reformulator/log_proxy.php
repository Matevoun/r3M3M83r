<?php
/**
 * log_proxy.php
 *
 * Affiche les fichiers de log de reformulator avec les entrees les plus
 * recentes en premier (ordre inverse chronologique).
 * Rendu HTML dans un navigateur, texte brut en CLI ou avec ?plain=1.
 *
 * Actions supportées :
 *   ?name=error_log|requests_log        → affiche le journal
 *   ?name=...&plain=1                   → texte brut
 *   ?name=...&action=clear (POST)       → vide le fichier
 */

$allowed = ['error_log', 'requests_log'];
$mapping = [
    'error_log'    => 'log/error.log',
    'requests_log' => 'log/requests.log',
];
$labels = [
    'error_log'    => 'Journal des erreurs',
    'requests_log' => 'Journal des requetes',
];

$name = $_GET['name'] ?? '';
if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Fichier introuvable';
    exit;
}

$file = __DIR__ . '/' . $mapping[$name];
$dir  = dirname($file);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
if (!file_exists($file)) {
    @touch($file);
    @chmod($file, 0666);
}
if (!is_readable($file)) {
    @chmod($file, 0666);
}

// Action : vider le fichier (POST uniquement)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    if (is_writable($file)) {
        file_put_contents($file, '');
        header('Location: ?name=' . urlencode($name));
        exit;
    } else {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Impossible de vider le fichier (permissions)';
        exit;
    }
}

if (!is_readable($file)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Acces refuse';
    exit;
}

$raw = @file_get_contents($file);
if ($raw === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Impossible de lire le fichier';
    exit;
}

// Decoupe en lignes, supprime les vides, inverse pour avoir le plus recent en haut.
$lines = array_filter(explode("\n", $raw), function ($l) { return trim($l) !== ''; });
$lines = array_reverse(array_values($lines));

$wantPlain = isset($_GET['plain']) || php_sapi_name() === 'cli';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($wantPlain) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo implode("\n", $lines) . "\n";
    exit;
}

$title   = htmlspecialchars($labels[$name] ?? $name, ENT_QUOTES, 'UTF-8');
$count   = count($lines);
$mtime   = @filemtime($file);
$mtimeStr = $mtime ? date('d/m/Y H:i:s', $mtime) : 'inconnue';
$nameEnc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $title; ?> — reformulator</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Menlo,Consolas,'Courier New',monospace;background:#0f1117;color:#c9d1d9;font-size:.88rem;line-height:1.5}
.bar{background:#16275b;color:#fff;padding:.7rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;position:sticky;top:0;z-index:10}
.bar h1{font-size:1rem;margin:0;font-family:Arial,sans-serif}
.bar .meta{font-size:.82rem;color:#a0aec0}
.bar-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
a.btn,button.btn{background:#374e8c;color:#fff;border:none;padding:.35rem .8rem;border-radius:5px;text-decoration:none;font-size:.82rem;cursor:pointer;font-family:Arial,sans-serif}
a.btn:hover,button.btn:hover{background:#2e4475}
button.btn-danger{background:#7f1d1d}
button.btn-danger:hover{background:#991b1b}
.empty{color:#6e7681;font-style:italic;padding:2rem 1.2rem}
.log-wrap{padding:.8rem 1.2rem}
.entry{border-bottom:1px solid #21262d;padding:.45rem 0;white-space:pre-wrap;word-break:break-all}
.entry:first-child{border-top:1px solid #21262d}
.ts{color:#58a6ff;margin-right:.4rem}
.err{color:#f85149}
.req{color:#3fb950}
</style>
</head>
<body>
<div class="bar">
  <div>
    <h1><?php echo $title; ?></h1>
    <div class="meta">Derniere modification : <?php echo $mtimeStr; ?> &mdash; <?php echo $count; ?> ligne<?php echo $count !== 1 ? 's' : ''; ?> &mdash; Plus recent en haut</div>
  </div>
  <div class="bar-actions">
    <button class="btn" onclick="copyAll()">Copier tout</button>
    <a class="btn" href="?name=<?php echo $nameEnc; ?>&amp;plain=1" target="_blank">Texte brut</a>
    <form method="post" action="?name=<?php echo $nameEnc; ?>" style="display:inline" onsubmit="return confirm('Vider ce journal ? Cette action est irréversible.')">
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn btn-danger">Vider le journal</button>
    </form>

    <!-- Boutons améliorés -->
    <button class="btn" onclick="window.close()" style="background:#e53e3e;color:white;">✕ Fermer cet onglet</button>
    <a class="btn" href="../saisie.php" target="_self" style="background:#2f855a;">← Retour à la saisie</a>
  </div>
</div>
<?php if (empty($lines)): ?>
<p class="empty">Aucune entree dans ce journal.</p>
<?php else: ?>
<div class="log-wrap" id="log-content">
<?php foreach ($lines as $line): ?>
<?php
    $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/^(\[[^\]]+\])/', '<span class="ts">$1</span>', $escaped);
    $isErr = (stripos($line, 'error') !== false || stripos($line, 'exception') !== false || stripos($line, 'fatal') !== false);
    $cls = $isErr ? ' err' : (stripos($line, 'REFORMULER') !== false || stripos($line, 'TEST_CURL') !== false ? ' req' : '');
?>
<div class="entry<?php echo $cls; ?>"><?php echo $escaped; ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<script>
function copyAll() {
    var lines = [];
    document.querySelectorAll('.entry').forEach(function(el) { lines.push(el.textContent); });
    navigator.clipboard.writeText(lines.join('\n')).then(function() {
        var b = document.querySelector('button.btn');
        var orig = b.textContent;
        b.textContent = 'Copie !';
        setTimeout(function() { b.textContent = orig; }, 1800);
    });
}

function closeTab() {
    window.close();
    // Fallback si le navigateur bloque la fermeture
    setTimeout(() => {
        window.location.href = '../saisie.php';
    }, 800);
}

// Optionnel : rendre le bouton Fermer encore plus visible
document.addEventListener('DOMContentLoaded', function() {
    const closeBtn = document.querySelector('button[onclick*="window.close"]');
    if (closeBtn) {
        closeBtn.style.fontWeight = 'bold';
    }
});
</script>
</body>
</html>