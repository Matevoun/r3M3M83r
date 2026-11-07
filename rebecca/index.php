<?php
  declare(strict_types=1);

  ini_set('display_errors', '0');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => $https,
      'httponly' => true,
      'samesite' => 'Lax',
  ]);
  session_start();
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
  require_once dirname(__DIR__) . '/moteurs/rebecca.php';

  function rebecca_access_path(): string
  {
      return dirname(__DIR__) . '/moteurs/acces.php';
  }

  function rebecca_access_hash(): ?string
  {
      $path = rebecca_access_path();
      if (!is_file($path)) {
          return null;
      }
      $loaded = include $path;
      if (!is_array($loaded) || !is_string($loaded['hash'] ?? null) || $loaded['hash'] === '') {
          return null;
      }
      return $loaded['hash'];
  }

  function rebecca_admin(): bool
  {
      return !empty($_SESSION['rebecca_admin']);
  }

  function rebecca_write_php(string $path, array $data): bool
  {
      $tmp = $path . '.tmp';
      $body = "<?php\nreturn " . var_export($data, true) . ";\n";
      if (file_put_contents($tmp, $body, LOCK_EX) === false) {
          return false;
      }
      return rename($tmp, $path);
  }

  function rebecca_json(array $payload, int $code = 200): void
  {
      http_response_code($code);
      header('Content-Type: application/json; charset=utf-8');
      header('X-Robots-Tag: noindex');
      echo json_encode($payload, JSON_UNESCAPED_UNICODE);
      exit;
  }

  function rebecca_history(mixed $raw): array
  {
      if (!is_array($raw)) {
          return [];
      }
      $history = [];
      foreach (array_slice($raw, -6) as $turn) {
          if (!is_array($turn) || !isset($turn['role'], $turn['content']) || !is_string($turn['content'])) {
              continue;
          }
          $history[] = [
              'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
              'content' => $turn['content'],
          ];
      }
      return $history;
  }

  function rebecca_public_state(): array
  {
      $memory = rebecca_memory();
      $state = [
          'ok' => $memory['ok'],
          'lines' => $memory['lines'],
          'admin' => rebecca_admin(),
          'hasPassword' => rebecca_access_hash() !== null,
          'hasKeys' => rebecca_server_keys() !== [],
      ];
      if (rebecca_admin()) {
          $state['keys'] = rebecca_server_keys();
      }
      return $state;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $body = json_decode((string) file_get_contents('php://input'), true);
      if (!is_array($body)) {
          rebecca_json(['ok' => false, 'reply' => 'Requete illisible.', 'logs' => ['// corps non json'], 'blocks' => [], 'engine' => null, 'model' => null], 400);
      }
      $action = is_string($body['action'] ?? null) ? $body['action'] : '';
      if ($action === 'initpass') {
          if (rebecca_access_hash() !== null) {
              rebecca_json(['ok' => false, 'error' => 'Un mot de passe existe deja.'], 403);
          }
          $pass = trim((string) ($body['password'] ?? ''));
          if (mb_strlen($pass) < 8) {
              rebecca_json(['ok' => false, 'error' => 'Huit caracteres minimum.'], 400);
          }
          $written = rebecca_write_php(rebecca_access_path(), ['hash' => password_hash($pass, PASSWORD_DEFAULT)]);
          if (!$written) {
              rebecca_json(['ok' => false, 'error' => 'Impossible d ecrire dans moteurs/. Regle le dossier en ecriture.'], 500);
          }
          session_regenerate_id(true);
          $_SESSION['rebecca_admin'] = true;
          $_SESSION['fails'] = 0;
          rebecca_json(['ok' => true, 'admin' => true, 'keys' => rebecca_server_keys()]);
      }
      if ($action === 'unlock') {
          $hash = rebecca_access_hash();
          if ($hash === null) {
              rebecca_json(['ok' => false, 'error' => 'Aucun mot de passe encore.'], 400);
          }
          $fails = (int) ($_SESSION['fails'] ?? 0);
          if ($fails >= 8) {
              rebecca_json(['ok' => false, 'error' => 'Trop d essais. Reviens dans un moment.'], 429);
          }
          if (!password_verify((string) ($body['password'] ?? ''), $hash)) {
              $_SESSION['fails'] = $fails + 1;
              rebecca_json(['ok' => false, 'error' => 'Mot de passe refuse.'], 403);
          }
          session_regenerate_id(true);
          $_SESSION['fails'] = 0;
          $_SESSION['rebecca_admin'] = true;
          rebecca_json(['ok' => true, 'admin' => true, 'keys' => rebecca_server_keys()]);
      }
      if ($action === 'lock') {
          unset($_SESSION['rebecca_admin']);
          rebecca_json(['ok' => true, 'admin' => false]);
      }
      if ($action === 'savekeys') {
          if (!rebecca_admin()) {
              rebecca_json(['ok' => false, 'error' => 'Ferme.'], 403);
          }
          $current = rebecca_server_keys();
          $allowed = [];
          foreach (rebecca_engines() as $engine) {
              $allowed[$engine['apiKeyEnv']] = true;
          }
          $posted = is_array($body['keys'] ?? null) ? $body['keys'] : [];
          foreach ($allowed as $name => $unused) {
              if (!array_key_exists($name, $posted) || !is_string($posted[$name])) {
                  continue;
              }
              $value = trim($posted[$name]);
              if (!empty($body['wrapped']) && $value !== '') {
                  $decoded = base64_decode($value, true);
                  if (!is_string($decoded)) {
                      rebecca_json(['ok' => false, 'error' => 'Clef illisible.'], 400);
                  }
                  $value = trim($decoded);
              }
              if ($value === '') {
                  unset($current[$name]);
              } else {
                  $current[$name] = $value;
              }
          }
          $written = rebecca_write_php(dirname(__DIR__) . '/moteurs/clefs.php', $current);
          if (!$written) {
              rebecca_json(['ok' => false, 'error' => 'Impossible d ecrire moteurs/clefs.php.'], 500);
          }
          rebecca_json(['ok' => true, 'keys' => rebecca_server_keys()]);
      }
      $message = trim((string) ($body['message'] ?? ''));
      if ($message === '') {
          rebecca_json(['ok' => false, 'reply' => 'Message vide.', 'logs' => [], 'blocks' => [], 'engine' => null, 'model' => null], 400);
      }
      $preferred = 'auto';
      if (is_string($body['preferred'] ?? null) && ( $body['preferred'] === 'auto' || isset(rebecca_engines()[$body['preferred']]) )) {
          $preferred = $body['preferred'];
      }
      $deep = !empty($body['deep']);
      session_write_close();
      set_time_limit(70);
      $result = rebecca_run($message, rebecca_history($body['history'] ?? []), rebecca_server_keys(), $preferred, $deep);
      rebecca_json($result);
  }

  if (isset($_GET['etat'])) {
      rebecca_json(rebecca_public_state());
  }

  $engines = rebecca_engines();
  $prompts = [
      'Qui est Alain GROS',
      'Qui est Ambre',
      'Qui est Charles de LEUSSE',
      'Qui est Elisabeth PAULY',
      'Qui est Eva SALOMON',
      'Qui est Jean SALOMON',
      'Qui est Julien GAUTIER',
      'Qui est Virginie FLORAND',
      'Tonin, Indy, Ankou ...',
      'Les soeurs SICARD',
      'La villa Jacquemont',
      'Le Domaine Saint-Antonin',
      'La WDA, en quelques faits',
      'Le GAN, c\'est quoi',
      'Qu\'est-ce que Club-Internet',
      'Qu\'est-ce que PhotoMat',
      'Fioles d\'Ambre',
      'PixelGod',
      'Les vacances à Gigaro',
  ];
  shuffle($prompts);
  $prompts = array_slice($prompts, 0, 3);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>Rebecca - Mémoire interactive de Mathieu CHARREYRE</title>
  <meta name="description" content="Rebecca répond sur Mathieu CHARREYRE à partir de son fichier. Un nom, une date, un lieu. Elle ne complète pas ce qui n'y est pas.">
  <link rel="canonical" href="https://mathieu.charreyre.net/r3M3M83r/Rebecca/">
  <meta name="robots" content="index,follow">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="fr_FR">
  <meta property="og:title" content="Rebecca - Mémoire interactive de Mathieu CHARREYRE">
  <meta property="og:description" content="Un nom, une date, un lieu. Rebecca cherche dans le fichier et ne brode pas.">
  <meta property="og:url" content="https://mathieu.charreyre.net/r3M3M83r/Rebecca/">
  <meta property="og:image" content="https://mathieu.charreyre.net/r3M3M83r/Rebecca/Rebecca.webp">
  <meta name="twitter:card" content="summary">
  <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
  <link rel="stylesheet" href="fonts.css">
  <style>
    :root { --bg:#16130f; --paper:#f4efe6; --ink:#1c1712; --gold:#c6a15a; --muted:#b7ab9e; }
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body { background: var(--bg); color: var(--paper); font-family: Outfit, "Avenir Next", sans-serif; }
    button { font: inherit; color: inherit; cursor: pointer; }
    .app { display: flex; flex-direction: column; height: 100dvh; height: 100svh; }
    header { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid rgba(244,239,230,.15); }
    .avatar { border: 0; padding: 0; background: transparent; border-radius: 999px; line-height: 0; flex: 0 0 auto; }
    .avatar img { width: 44px; height: 44px; border-radius: 999px; object-fit: cover; box-shadow: 0 0 0 1px rgba(198,161,90,.5); }
    header .who { min-width: 0; flex: 1; }
    header h1 { margin: 0; font-family: Fraunces, Palatino, serif; font-size: 1.35rem; font-weight: 500; }
    header p { margin: 4px 0 0; color: var(--muted); font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .nav { display: flex; gap: 8px; margin-left: auto; flex: 0 0 auto; }
    .nav-btn { min-height: 44px; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(244,239,230,.35); background: transparent; }
    .nav-keys { border-color: rgba(198,161,90,.85); color: var(--gold); }
    .nav-btn[aria-expanded="true"] { background: rgba(244,239,230,.12); }
    .body { position: relative; display: flex; min-height: 0; flex: 1; }
    .thread-wrap { display: flex; min-width: 0; min-height: 0; flex: 1; flex-direction: column; }
    .thread { min-height: 0; flex: 1; overflow-y: auto; padding: 24px 16px; }
    .col { width: min(42rem, 100%); margin: 0 auto; display: flex; flex-direction: column; gap: 24px; }
    .lead { font-family: Fraunces, Palatino, serif; font-size: 1.8rem; line-height: 1.2; margin: 0; max-width: 40ch; }
    .sub { color: var(--muted); line-height: 1.5; max-width: 40ch; }
    .suggest { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; margin-top: 8px; }
    .suggest button {
      position: relative;
      min-height: 44px;
      border: 1px solid rgba(244,239,230,.22);
      border-radius: 999px;
      padding: 10px 42px 10px 18px;
      text-align: left;
      color: var(--paper);
      background: rgba(244,239,230,.03);
      transition: border-color .22s ease, background .22s ease, transform .22s ease, box-shadow .22s ease;
    }
    .suggest button::after {
      content: "→";
      position: absolute;
      right: 16px;
      top: 50%;
      color: var(--gold);
      opacity: 0;
      transform: translateY(-50%) translateX(-8px);
      transition: opacity .22s ease, transform .22s ease;
    }
    .suggest button:hover,
    .suggest button:focus-visible {
      border-color: rgba(198,161,90,.95);
      background: rgba(198,161,90,.14);
      box-shadow: 0 8px 24px rgba(0,0,0,.28);
      transform: translateX(8px);
      outline: none;
    }
    .suggest button:hover::after,
    .suggest button:focus-visible::after {
      opacity: 1;
      transform: translateY(-50%) translateX(0);
    }
    .suggest button:active {
      transform: translateX(3px) scale(.985);
      background: rgba(198,161,90,.24);
    }
    .user { border: 0; background: transparent; text-align: left; }
    .user { margin-left: auto; max-width: 85%; border-left: 2px solid var(--gold); padding-left: 16px; line-height: 1.5; }
    .bubble { max-width: 92%; }
    .who-line, .engine { font-size: .8rem; }
    .who-line { font-family: Fraunces, Palatino, serif; color: var(--gold); margin: 0 0 8px; }
    .paper { position: relative; background: var(--paper); color: var(--ink); border-radius: 16px 16px 16px 4px; padding: 12px 16px; white-space: pre-wrap; font-family: Fraunces, Palatino, serif; font-size: 1.05rem; line-height: 1.45; }
    .paper strong { font-weight: 600; }
    .paper a { color: inherit; text-underline-offset: 2px; }
    .paper-tools { display: flex; justify-content: flex-end; margin: -6px -6px 6px; }
    .copy { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 0; border-radius: 999px; background: transparent; color: var(--ink); opacity: .45; }
    .copy:hover, .copy:focus-visible { opacity: 1; background: rgba(28,23,18,.06); outline: none; }
    .copy.done { opacity: 1; color: #6d5a32; }
    .engine { color: var(--muted); margin: 8px 0 0; }
    .bar { height: 4px; overflow: hidden; border-radius: 999px; background: rgba(244,239,230,.15); }
    .bar span { display: block; height: 100%; width: 40%; background: var(--gold); animation: sweep 1.1s ease-in-out infinite; }
    @keyframes sweep { from { transform: translateX(-70%); } to { transform: translateX(220%); } }
    .composer { border-top: 1px solid rgba(244,239,230,.15); padding: 12px 16px; padding-bottom: calc(12px + env(safe-area-inset-bottom)); }
    .composer form { display: flex; gap: 8px; align-items: flex-end; width: min(42rem, 100%); margin: 0 auto; }
    textarea { flex: 1; min-height: 44px; max-height: 9rem; resize: none; border-radius: 16px; border: 1px solid rgba(244,239,230,.2); background: transparent; color: var(--paper); padding: 12px 16px; font: inherit; font-size: 16px; transition: border-color .22s ease, background .22s ease, transform .22s ease, box-shadow .22s ease; }
    textarea:hover,
    textarea:focus { outline: none; border-color: rgba(198,161,90,.95); background: rgba(198,161,90,.14); box-shadow: 0 8px 24px rgba(0,0,0,.28); transform: translateY(-2px); }
    .composer button[type="submit"] { width: 44px; height: 44px; border: 0; border-radius: 999px; background: var(--gold); color: var(--ink); font-weight: 500; }
    .composer button[type="submit"]:disabled { opacity: .4; }
    aside { display: none; }
    aside.is-open { display: flex; position: absolute; inset: 0; z-index: 2; flex-direction: column; background: var(--bg); }
    .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid rgba(244,239,230,.15); }
    .panel-head p { margin: 0; font-family: Fraunces, Palatino, serif; font-size: 1.2rem; }
    .panel-head button { min-height: 44px; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(244,239,230,.35); background: transparent; }
    .panel { min-height: 0; flex: 1; overflow-y: auto; padding: 16px; }
    pre { white-space: pre-wrap; color: var(--muted); font-size: .82rem; line-height: 1.45; }
    details { margin-top: 12px; }
    summary { cursor: pointer; }
    .block { margin-top: 8px; color: var(--paper); font-size: .88rem; white-space: pre-wrap; }
    label { display: block; margin: 0 0 12px; font-size: .9rem; }
    input, select { width: 100%; margin-top: 4px; min-height: 44px; border-radius: 12px; border: 1px solid rgba(244,239,230,.2); background: #16130f; color: var(--paper); padding: 8px 12px; font: inherit; font-size: 16px; }
    .gold-btn, .ghost-btn { min-height: 44px; border-radius: 999px; padding: 0 16px; margin: 4px 8px 12px 0; }
    .gold-btn { background: var(--gold); color: var(--ink); border: 0; }
    .ghost-btn { background: transparent; color: var(--paper); border: 1px solid rgba(244,239,230,.35); }
    .check { display: flex; align-items: center; gap: 8px; }
    .check input { width: auto; min-height: 0; margin: 0; }
    .pass-row { display: flex; gap: 8px; align-items: center; }
    .pass-row input { flex: 1; min-width: 0; }
    .peek { flex: 0 0 auto; min-height: 44px; margin-top: 4px; padding: 0 14px; border-radius: 12px; border: 1px solid rgba(198,161,90,.75); background: transparent; color: var(--gold); }
    .peek[aria-pressed="true"] { background: rgba(198,161,90,.16); }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); }
    @media (min-width: 960px) {
      aside.is-open { position: static; width: min(24rem, 38vw); border-left: 1px solid rgba(244,239,230,.15); }
    }
    @media (max-width: 640px) {
      header { align-items: center; flex-wrap: wrap; padding: 10px 12px; }
      header h1 { font-size: 1.2rem; }
      .nav { width: 100%; }
      .nav-btn { flex: 1; }
      .lead { font-size: 1.45rem; }
      .thread { padding: 16px 12px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .bar span { animation: none; width: 100%; opacity: .7; }
      .suggest button, .suggest button::after { transition: none; }
      .suggest button:hover, .suggest button:focus-visible, .suggest button:active,
      textarea:hover, textarea:focus { transform: none; }
    }
  </style>
</head>

<body>
  <div class="app">
    <header>
      <button type="button" class="avatar" id="home" aria-label="Retour a l'accueil">
        <img src="Rebecca.webp" alt="Avatar de Rebecca" title="Avatar de Rebecca">
      </button>
      <div class="who">
        <h1>Rebecca</h1>
        <p id="status">Lecture du fichier ...</p>
      </div>
      <div class="nav">
        <button type="button" title="Journal de traitement r3M3M83r" class="nav-btn" id="open-journal" aria-expanded="false" aria-controls="panel">Journal</button>
        <button type="button" title="Gestion des clefs API des moteurs LLMs" class="nav-btn nav-keys" id="open-moteurs" aria-expanded="false" aria-controls="panel">Clefs</button>
      </div>
    </header>
    <div class="body">
      <section class="thread-wrap">
        <div class="thread" id="thread">
          <div class="col" id="col">
            <div id="empty">
              <p class="lead">Je connais bien Mathieu ...</p>
              <p class="sub">Un nom, une date, un lieu ... Je cherche l'entree, anecdotes comprises, puis un moteur reformule. S'il n'y a rien, je le dis.</p>
              <div class="suggest">
                <p>Par exemple :</p>
                <?php foreach ($prompts as $prompt):
                  $label = htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8');
                ?>
                <button type="button" title="<?= $label ?>" aria-label="<?= $label ?>" data-q="<?= $label ?>"><?= $label ?></button>
                <?php endforeach; ?>
                <p>Ou :</p>
              </div>
            </div>
          </div>
        </div>
        <div class="composer">
          <form id="form">
            <label class="sr" for="draft">Message</label>
            <textarea id="draft" rows="2" placeholder="Un nom, une date, un lieu, ..."></textarea>
            <button type="submit" title="Envoyer la requête" id="send" disabled>OK</button>
          </form>
        </div>
      </section>
      <aside id="panel" hidden>
        <div class="panel-head">
          <p id="panel-title" title="Journal de traitement r3M3M83r">Journal</p>
          <button type="button" title="Fermer le journal" id="close-panel">Fermer</button>
        </div>
        <div class="panel" id="panel-body"></div>
      </aside>
    </div>
  </div>

  <script>
    const ENGINES = <?php echo json_encode(array_map(static function (string $id, array $engine): array {
        return ['id' => $id, 'name' => $engine['name'], 'env' => $engine['apiKeyEnv'], 'rescue' => !empty($engine['rescue'])];
    }, array_keys($engines), $engines), JSON_UNESCAPED_UNICODE); ?>;
    const CHAT = "rebecca.chat";
    const col = document.getElementById("col");
    const thread = document.getElementById("thread");
    const draft = document.getElementById("draft");
    const sendBtn = document.getElementById("send");
    const panel = document.getElementById("panel");
    const panelBody = document.getElementById("panel-body");
    const panelTitle = document.getElementById("panel-title");
    const journalBtn = document.getElementById("open-journal");
    const keysBtn = document.getElementById("open-moteurs");
    let messages = [];
    let busy = false;
    let mode = null;
    let gate = { admin: false, hasPassword: false, keys: {} };

    function plainReply(text) {
      return text.replace(/\*\*([^*\n]+?)\*\*/g, "$1");
    }

    function formatReply(text) {
      const escaped = text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
      const withBold = escaped.replace(/\*\*([^*\n]+?)\*\*/g, "<strong>$1</strong>");
      return withBold.replace(/https?:\/\/[^\s<]+/g, (raw) => {
        const trimmed = raw.replace(/[),.;:!?]+$/g, "");
        const tail = raw.slice(trimmed.length);
        const url = trimmed
          .replace(/[\u2010\u2011\u2012\u2013\u2014\u2015\u2212\uFE58\uFE63\uFF0D]/g, "-")
          .replace(/\u00AD/g, "");
        return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + "</a>" + tail;
      });
    }

    function paint() {
      const empty = document.getElementById("empty");
      if (empty) empty.hidden = messages.length > 0;
      col.querySelectorAll("[data-msg]").forEach((node) => node.remove());
      for (const message of messages) {
        const node = document.createElement(message.role === "user" ? "p" : "article");
        node.dataset.msg = "1";
        if (message.role === "user") {
          node.className = "user";
          node.textContent = message.content;
        } else {
          node.className = "bubble";
          const who = document.createElement("p");
          who.className = "who-line";
          who.textContent = "Rebecca";
          const paper = document.createElement("div");
          paper.className = "paper";
          const tools = document.createElement("div");
          tools.className = "paper-tools";
          const copy = document.createElement("button");
          copy.type = "button";
          copy.className = "copy";
          copy.setAttribute("aria-label", "Copier la reponse");
          copy.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="8" y="8" width="12" height="12" rx="2"></rect><path d="M4 16V6a2 2 0 0 1 2-2h10"></path></svg>';
          copy.addEventListener("click", async () => {
            try {
              await navigator.clipboard.writeText(plainReply(message.content));
              copy.classList.add("done");
              copy.setAttribute("aria-label", "Copie");
              setTimeout(() => {
                copy.classList.remove("done");
                copy.setAttribute("aria-label", "Copier la reponse");
              }, 1200);
            } catch {
              copy.setAttribute("aria-label", "Copie impossible");
            }
          });
          const body = document.createElement("div");
          body.innerHTML = formatReply(message.content);
          tools.append(copy);
          paper.append(tools, body);
          node.append(who, paper);
          if (message.suggest) {
            const again = document.createElement("div");
            again.className = "suggest";
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = "Chercher encore : " + message.suggest;
            button.addEventListener("click", () => send(message.suggest, true));
            again.append(button);
            node.append(again);
          }
          if (message.engine) {
            const meta = document.createElement("p");
            meta.className = "engine";
            meta.textContent = message.engine + (message.model ? " · " + message.model : "");
            node.append(meta);
          }
        }
        col.append(node);
      }
      if (mode !== "moteurs") thread.scrollTop = thread.scrollHeight;
      if (mode === "journal") renderJournal();
    }

    function renderJournal() {
      panelTitle.textContent = "Journal";
      panelBody.replaceChildren();
      const last = [...messages].reverse().find((item) => item.role === "assistant");
      const pre = document.createElement("pre");
      pre.textContent = last && last.logs ? last.logs.join("\n") : "Le journal s'affiche apres une reponse.";
      panelBody.append(pre);
      for (const block of (last && last.blocks) || []) {
        const details = document.createElement("details");
        const summary = document.createElement("summary");
        summary.textContent = "l." + block.startLine + "-" + block.endLine + (block.heading ? " · " + block.heading : "");
        const body = document.createElement("div");
        body.className = "block";
        body.textContent = block.text;
        details.append(summary, body);
        panelBody.append(details);
      }
    }

    async function postAction(payload) {
      const response = await fetch("index.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const raw = await response.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch {
        throw new Error("o2switch a bloque la requete (page de securite). Recharge, puis reessaie.");
      }
      if (!response.ok || data.ok === false) throw new Error(data.error || "Refuse");
      return data;
    }

    function renderKeys() {
      panelTitle.textContent = "Clefs";
      panelBody.replaceChildren();
      if (!gate.admin) {
        const note = document.createElement("p");
        note.className = "sub";
        note.textContent = gate.hasPassword
          ? "Les clefs ne s'affichent qu'avec le mot de passe."
          : "Premier passage. Choisis le mot de passe des clefs, huit caracteres minimum, avant de partager le lien.";
        const label = document.createElement("label");
        label.textContent = "Mot de passe";
        const row = document.createElement("div");
        row.className = "pass-row";
        const input = document.createElement("input");
        input.type = "password";
        input.autocomplete = gate.hasPassword ? "current-password" : "new-password";
        const peek = document.createElement("button");
        peek.type = "button";
        peek.className = "peek";
        peek.textContent = "Voir";
        peek.setAttribute("aria-pressed", "false");
        peek.addEventListener("click", () => {
          const shown = input.type === "text";
          input.type = shown ? "password" : "text";
          peek.textContent = shown ? "Voir" : "Cacher";
          peek.setAttribute("aria-pressed", String(!shown));
          input.focus();
        });
        row.append(input, peek);
        const button = document.createElement("button");
        button.type = "button";
        button.className = "gold-btn";
        button.textContent = gate.hasPassword ? "Entrer" : "Enregistrer";
        const err = document.createElement("p");
        err.className = "engine";
        button.addEventListener("click", async () => {
          err.textContent = "";
          button.disabled = true;
          try {
            const data = await postAction({
              action: gate.hasPassword ? "unlock" : "initpass",
              password: input.value,
            });
            gate.admin = true;
            gate.hasPassword = true;
            gate.keys = data.keys || {};
            renderKeys();
          } catch (error) {
            err.textContent = error.message;
            button.disabled = false;
          }
        });
        input.addEventListener("keydown", (event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            button.click();
          }
        });
        label.append(row);
        panelBody.append(note, label, button, err);
        input.focus();
        return;
      }
      const note = document.createElement("p");
      note.className = "sub";
      note.textContent = "Ces clefs servent a tout le monde. Un champ vide efface la clef. Elles ne restent pas dans le navigateur.";
      const show = document.createElement("label");
      show.className = "check";
      const check = document.createElement("input");
      check.type = "checkbox";
      show.append(check, document.createTextNode("Afficher les clefs"));
      panelBody.append(note, show);
      const fields = {};
      for (const engine of ENGINES) {
        const label = document.createElement("label");
        label.textContent = engine.name;
        const input = document.createElement("input");
        input.type = "password";
        input.autocomplete = "off";
        input.dataset.key = engine.env;
        input.value = gate.keys[engine.env] || "";
        input.placeholder = engine.env;
        fields[engine.env] = input;
        label.append(input);
        panelBody.append(label);
      }
      check.addEventListener("change", () => {
        for (const input of Object.values(fields)) input.type = check.checked ? "text" : "password";
      });
      const save = document.createElement("button");
      save.type = "button";
      save.className = "gold-btn";
      save.textContent = "Enregistrer";
      const lock = document.createElement("button");
      lock.type = "button";
      lock.className = "ghost-btn";
      lock.textContent = "Fermer l'acces";
      const err = document.createElement("p");
      err.className = "engine";
      save.addEventListener("click", async () => {
        err.textContent = "";
        const keys = {};
        for (const [name, input] of Object.entries(fields)) {
          const value = input.value.trim();
          keys[name] = value === "" ? "" : btoa(unescape(encodeURIComponent(value)));
        }
        try {
          const data = await postAction({ action: "savekeys", keys, wrapped: true });
          gate.keys = data.keys || {};
          err.textContent = "Clefs enregistrees.";
        } catch (error) {
          err.textContent = error.message;
        }
      });
      lock.addEventListener("click", async () => {
        try { await postAction({ action: "lock" }); } catch { /* session deja close */ }
        gate.admin = false;
        gate.keys = {};
        renderKeys();
      });
      panelBody.append(save, lock, err);
    }

    function renderPanel() {
      if (mode === "journal") renderJournal();
      else if (mode === "moteurs") renderKeys();
    }

    function openPanel(next) {
      mode = mode === next ? null : next;
      const open = mode !== null;
      panel.classList.toggle("is-open", open);
      panel.hidden = !open;
      journalBtn.setAttribute("aria-expanded", String(mode === "journal"));
      keysBtn.setAttribute("aria-expanded", String(mode === "moteurs"));
      if (open) renderPanel();
    }

    function goHome() {
      messages = [];
      localStorage.removeItem(CHAT);
      mode = null;
      panel.classList.remove("is-open");
      panel.hidden = true;
      journalBtn.setAttribute("aria-expanded", "false");
      keysBtn.setAttribute("aria-expanded", "false");
      paint();
      thread.scrollTop = 0;
    }

    async function send(text, deep = false) {
      const message = text.trim();
      if (!message || busy) return;
      const history = messages.slice(-6).map((item) => ({ role: item.role, content: item.content }));
      messages.push({ role: "user", content: message });
      draft.value = "";
      sendBtn.disabled = true;
      busy = true;
      paint();
      const wait = document.createElement("div");
      wait.id = "wait";
      wait.innerHTML = '<p class="who-line">Rebecca</p><div class="bar"><span></span></div><p class="engine">Lecture du fichier, puis bascule des moteurs.</p>';
      col.append(wait);
      thread.scrollTop = thread.scrollHeight;
      const controller = new AbortController();
      const kill = setTimeout(() => controller.abort(), 50000);
      try {
        const response = await fetch("index.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ message, history, deep }),
          signal: controller.signal,
        });
        const raw = await response.text();
        let result;
        try {
          result = JSON.parse(raw);
        } catch {
          messages.push({ role: "assistant", content: "Le serveur a renvoye une page, pas une reponse. Rien n'a ete invente.", logs: ["// reponse non json"], blocks: [] });
          return;
        }
        messages.push({
          role: "assistant",
          content: result.reply || "Pas de reponse.",
          logs: result.logs || [],
          blocks: result.blocks || [],
          engine: result.engine,
          model: result.model,
          suggest: result.suggest || "",
        });
      } catch (error) {
        const timedOut = error && error.name === "AbortError";
        messages.push({
          role: "assistant",
          content: timedOut
            ? "Les moteurs n'ont pas repondu a temps. Rien n'a ete invente. Tu peux renvoyer la question."
            : "L'echange n'est pas arrive au moteur. Rien n'a ete invente.",
          logs: [timedOut ? "// abandon apres 50 s" : "// echec reseau avant reponse"],
          blocks: [],
        });
      } finally {
        clearTimeout(kill);
        document.getElementById("wait")?.remove();
        busy = false;
        localStorage.setItem(CHAT, JSON.stringify(messages.slice(-30)));
        paint();
      }
    }

    document.getElementById("form").addEventListener("submit", (event) => {
      event.preventDefault();
      send(draft.value);
    });
    draft.addEventListener("input", () => { sendBtn.disabled = busy || !draft.value.trim(); });
    draft.addEventListener("keydown", (event) => {
      if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        send(draft.value);
      }
    });
    document.querySelectorAll("[data-q]").forEach((button) => {
      button.addEventListener("click", () => send(button.dataset.q));
    });
    document.getElementById("open-journal").addEventListener("click", () => openPanel("journal"));
    document.getElementById("open-moteurs").addEventListener("click", () => openPanel("moteurs"));
    document.getElementById("close-panel").addEventListener("click", () => openPanel(mode));
    document.getElementById("home").addEventListener("click", goHome);

    try {
      const stored = JSON.parse(localStorage.getItem(CHAT) || "[]");
      if (Array.isArray(stored)) messages = stored.slice(-30);
    } catch { messages = []; }
    paint();
    const statusEl = document.getElementById("status");
    const slow = setTimeout(() => {
      statusEl.textContent = "La lecture met trop de temps. Si rien ne vient, recharge la page.";
    }, 8000);
    const controller = new AbortController();
    const kill = setTimeout(() => controller.abort(), 12000);
    fetch("index.php?etat=1", { signal: controller.signal }).then((response) => response.json()).then((info) => {
      clearTimeout(slow);
      gate.admin = !!info.admin;
      gate.hasPassword = !!info.hasPassword;
      gate.keys = info.keys || {};
      statusEl.textContent = info.ok
        ? info.lines.toLocaleString("fr-FR") + " lignes lues en local"
        : "Fichier memoire introuvable a cote de ce dossier";
      if (mode === "moteurs") renderKeys();
    }).catch(() => {
      clearTimeout(slow);
      statusEl.textContent = "Lecture interrompue. Le fichier n'a pas repondu a temps, ou la page a un souci. Recharge.";
    }).finally(() => clearTimeout(kill));
  </script>
</body>
</html>