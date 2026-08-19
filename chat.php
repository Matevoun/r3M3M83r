<?php
/**
 * ============================================================================
 * r3M3M83r/chat.php — Interface de tchat strict sur instructions.md
 * ============================================================================
 *
 * ROLE :
 *  - Fournit une grande interface de tchat (comme data.php et saisie.php)
 *  - Chaque message interroge VRAIMENT instructions.md, jamais d'invention
 *  - Garde en memoire les echanges precedents (localStorage) jusqu'a Clear/Reset
 *  - Permet de changer de moteur IA a la volee (MISTRAL, GROQ, CEREBRAS, OPENROUTER...)
 *  - Bouton copie sur chaque reponse assistant
 *  - Roue de chargement pendant l'interrogation
 *  - Logs comme data.php (access.log + reformulator/log/requests.log)
 *  - 100% responsive (mobile + desktop)
 *  - S'appuie ENTIEREMENT sur le moteur reformulator existant
 *
 * DEPENDANCES (ne modifie pas ces fichiers, s'appuie dessus) :
 *  - reformulator/functions.php :
 *      * get_reformulator_base_url() -> URL du service Node
 *      * get_llm_info() -> moteur actif, ordre fallback, modele
 *      * build_memory_context_for_topic($text) -> pipeline complet :
 *          expand_query_intent_via_node() + selection sections + PREUVES DIRECTES
 *      * finalize_query_response_via_node($question, $evidence, $context)
 *          -> appelle Node /reformuler avec purpose=query (QUERY_PROMPT strict)
 *  - reformulator/prompts.js :
 *      * QUERY_PROMPT + FACTUALITY_RULES + STYLE_RULES
 *      * C'est lui qui interdit d'inventer, pas chat.php
 *  - chat_prompts.js (fichier JS modulable a part) :
 *      * Contient CHAT_ADDON pour gerer les pronoms via historique
 *      * Editable sans toucher au PHP
 *
 * FLUX D'EXECUTION (POST JSON) :
 *  1. Reception {message, history[], engine}
 *  2. Construction $historyText (8 derniers tours) pour comprehension pronoms
 *  3. Construction $retrievalQuery = dernier user + question actuelle
 *     -> utilise pour build_memory_context_for_topic() afin d'avoir les bonnes preuves
 *  4. $memoryContext = resultat de build_memory_context_for_topic()
 *     -> contient : Intention elargie + PREUVES DIRECTES + sections classees
 *  5. $questionForLLM = CHAT_ADDON (historique) + Question actuelle
 *  6. Appel finalize_query_response_via_node($questionForLLM, '', $memoryContext)
 *     -> Node utilise QUERY_PROMPT (strict) + contexte memoire
 *  7. Reponse JSON {reply, engine, model, debug}
 *  8. Log dans reformulator/log/requests.log + access.log (comme tracker.php)
 *
 * STOCKAGE HISTORIQUE :
 *  - Cote client : localStorage key = r3m3m83r_chat_strict_history (JSON array)
 *  - Structure : [{role:'user'|'assistant', content:string, engine, model, debug}]
 *  - Conserve jusqu'a Clear (bouton) ou Reset moteur
 *  - Garde 8 derniers tours pour le LLM, mais affiche tout
 *
 * RESPONSIVE :
 *  - Flexbox + max-width 1100px centre
 *  - Messages max-width 86% desktop, 94% mobile
 *  - Composer sticky bottom
 *  - Media queries <700px : topbar en colonne, boutons full width
 *
 * SECURITE :
 *  - Pas d'injection : message trim, history limite a 8 tours pour LLM
 *  - Pas de cache : header no-store
 *  - Validation moteur via whitelist implicite (get_llm_info fallbackOrder)
 */

// ==================== INCLUDES ====================
include_once __DIR__ . '/reformulator/functions.php'; // Source de verite moteur

// ==================== CONSTANTES ====================
define('CHAT_PROMPT_JS', __DIR__ . '/chat_prompts.js'); // Prompt modulable externe

// ==================== LOGS (comme data.php) ====================
/**
 * Log une requete chat dans 2 fichiers :
 * - reformulator/log/requests.log (format simple, comme saisie.php)
 * - access.log (format tracker.php lisible)
 */
function chat_log_request(string $engine, int $msgLen) {
    $logFile = __DIR__ . '/reformulator/log/requests.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'inconnue'))[0]);
    $line = '[' . date('Y-m-d H:i:s') . '] CHAT engine=' . $engine . ' ip=' . $ip . ' len=' . $msgLen . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    $accessLog = __DIR__ . '/access.log';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $accessLine = "------------------------------\n[" . date('d/m/Y H:i:s') . " UTC" . date('P') . "] Chat IA (" . $engine . ") IP : " . $ip . "\nMode : chat.php\nURL : " . (($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')) . "\nUser-Agent : " . $ua . "\n\n";
    @file_put_contents($accessLog, $accessLine, FILE_APPEND | LOCK_EX);
}

// ==================== ENDPOINT AJAX (POST JSON) ====================
/**
 * Si POST JSON avec {message}, on traite comme API et on sort en JSON
 * Sinon on affiche le HTML du tchat
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['message'])) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');

        $message = trim((string)($data['message'] ?? ''));
        $history = $data['history'] ?? []; // Historique complet envoye par JS
        $engineReq = strtolower(trim((string)($data['engine'] ?? '')));

        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Message vide']);
            exit;
        }

        // --- 1) Charge addon tchat depuis fichier JS externe (modulable) ---
        // Contient la consigne pour gerer les pronoms via {{CHAT_HISTORY}}
        $chatAddon = '';
        if (is_file(CHAT_PROMPT_JS)) {
            $js = file_get_contents(CHAT_PROMPT_JS);
            if (preg_match('/CHAT_ADDON\s*=\s*`(.+?)`/s', $js, $m)) {
                $chatAddon = trim($m[1]);
            }
        }

        // --- 2) Formate l'historique pour le LLM (8 derniers tours max) ---
        // But : permettre au LLM de comprendre "et lui ?" "et sa soeur ?"
        // On garde role + content, pas les meta engine/debug
        $historyText = "";
        $lastUserForRetrieval = "";
        if (is_array($history)) {
            $slice = array_slice($history, -8); // Limite pour ne pas exploser le contexte
            foreach ($slice as $h) {
                $role = ($h['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'Utilisateur';
                $cnt = trim($h['content'] ?? '');
                if ($cnt !== '') $historyText .= $role . ": " . $cnt . "\n";
                // Garde dernier message user pour ameliorer la recherche memoire
                if (($h['role'] ?? '') === 'user' && $cnt !== '') $lastUserForRetrieval = $cnt;
            }
        }

        // --- 3) Construit la requete de recherche memoire (retrieval) ---
        // Si l'utilisateur dit "et son age ?" apres "Qui est Tonin ?", on veut chercher "Tonin age"
        // Donc on combine dernier user + question actuelle pour build_memory_context_for_topic
        $retrievalQuery = $message;
        if ($lastUserForRetrieval !== '' && mb_strlen($message, 'UTF-8') < 40) {
            // Question courte type pronom -> on enrichit avec contexte precedent
            $retrievalQuery = $lastUserForRetrieval . " " . $message;
        }

        // --- 4) Construit la question finale envoyee au LLM (avec historique) ---
        $questionForLLM = $message;
        if ($historyText !== '' && $chatAddon !== '') {
            $addonFilled = str_replace('{{CHAT_HISTORY}}', $historyText, $chatAddon);
            $questionForLLM = $addonFilled . "\n\nQuestion actuelle : " . $message;
        } elseif ($historyText !== '') {
            $questionForLLM = "Historique tchat (pour pronoms) :\n" . $historyText . "\n\nQuestion actuelle : " . $message;
        }

        // --- 5) PIPELINE MEMOIRE IDENTIQUE A saisie.php bouton Interroger ---
        // build_memory_context_for_topic() fait :
        // - expand_query_intent_via_node() (comprend intention)
        // - extract_instructions_sections() + outline
        // - select des sections pertinentes + ranked evidence lines
        // - retourne context = PREUVES DIRECTES + sections classees
        $memoryContext = '';
        $debugInfo = '';
        if (function_exists('build_memory_context_for_topic')) {
            $built = build_memory_context_for_topic($retrievalQuery);
            $memoryContext = $built['context'] ?? '';
            $debugInfo = $built['debug'] ?? '';
        }

        // Fallback si jamais le build echoue (fichier trop petit ou fonction manquante)
        if ($memoryContext === '' && function_exists('load_instructions_excerpt')) {
            $memoryContext = load_instructions_excerpt();
            $debugInfo = 'fallback excerpt - build_memory_context a echoue';
        }

        // Log comme data.php
        chat_log_request($engineReq ?: 'auto', mb_strlen($message));

        // --- 6) APPEL LLM STRICT via reformulator ---
        // finalize_query_response_via_node utilise :
        // - purpose=query
        // - QUERY_PROMPT de reformulator/prompts.js (avec FACTUALITY_RULES : N'INVENTE RIEN)
        // - instructionsContext = $memoryContext (preuves + sections)
        $finalReply = '';
        $usedEngine = $engineReq;
        $usedModel = '';

        if (function_exists('finalize_query_response_via_node')) {
            try {
                // Astuce pour forcer le moteur : on set $_POST['selected_engine'] temporairement
                // C'est le mecanisme utilise dans saisie.php pour le select moteur
                $oldPost = $_POST;
                if ($engineReq !== '') {
                    $_POST['selected_engine'] = $engineReq;
                }
                // Le 1er param = question + historique pronoms, 2eme = evidence vide, 3eme = contexte memoire strict
                $finalReply = finalize_query_response_via_node($questionForLLM, '', $memoryContext);
                $_POST = $oldPost;

                if (function_exists('get_llm_info')) {
                    $info = get_llm_info();
                    $usedEngine = strtolower($info['engineName'] ?? $engineReq);
                    $usedModel = $info['selectedModel'] ?? '';
                }
            } catch (Throwable $e) {
                $finalReply = '';
                $debugInfo .= ' | exception finalize: '.$e->getMessage();
            }
        }

        // Fallback direct si finalize echoue (Node down ?)
        if ($finalReply === '') {
            $baseUrl = function_exists('get_reformulator_base_url') ? get_reformulator_base_url() : 'https://charreyre.net/r3M3M83r/reformulator';
            $url = rtrim($baseUrl, '/') . '/reformuler';
            $payload = [
                'text' => $questionForLLM,
                'instructionsContext' => $memoryContext,
                'purpose' => 'query',
                'engine' => $engineReq
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 90
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp && $code < 300) {
                $j = json_decode($resp, true);
                if (!empty($j['cleaned'])) {
                    $finalReply = $j['cleaned'];
                    $usedEngine = $j['engine'] ?? $engineReq;
                    $usedModel = $j['model'] ?? '';
                }
            } else {
                $debugInfo .= ' | fallback http code '.$code;
            }
        }

        if ($finalReply === '') {
            http_response_code(500);
            echo json_encode(['error' => "Le moteur n'a pas répondu. Vérifie que Node tourne (reformulator/server.js). Debug: $debugInfo"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Reponse OK
        echo json_encode([
            'reply' => $finalReply,
            'engine' => $usedEngine,
            'model' => $usedModel,
            'debug' => $debugInfo,
            'via' => 'query-strict-memoire-conversation'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==================== RESET MOTEUR (clear cache Node) ====================
if (isset($_GET['action']) && $_GET['action'] === 'reset_llm') {
    $baseUrl = function_exists('get_reformulator_base_url') ? get_reformulator_base_url() : '';
    if ($baseUrl) { @file_get_contents(rtrim($baseUrl,'/').'/llm-info?reset=1'); }
    header('Location: chat.php?reset=ok');
    exit;
}

// ==================== INFOS MOTEUR POUR SELECT ====================
$llmInfo = function_exists('get_llm_info') ? get_llm_info() : ['engineName'=>'MISTRAL','defaultEngine'=>'mistral','fallbackOrder'=>['mistral','groq','cerebras','openrouter']];
$enginesList = $llmInfo['fallbackOrder'] ?? ['mistral','groq','cerebras','openrouter'];
$currentEngine = strtolower($llmInfo['engineName'] ?? 'mistral');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Tchat IA — r3M3M83r (mémoire conversation)</title>
<link rel="icon" type="image/png" sizes="96x96" href="favicon/favicon-96x96.png">
<style>
/* ===== RESET + BASE ===== */
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family:Inter,Arial,Helvetica,sans-serif;
  background:#f6f7f9;
  color:#111827;
  display:flex;
  flex-direction:column;
  height:100vh; /* full height pour mobile */
  height:100dvh; /* dynamic viewport pour mobile moderne */
}

/* ===== HEADER (titre + descri + topbar) ===== */
header{
  padding:1rem 1.2rem;
  background:#fff;
  border-bottom:1px solid #e5e7eb;
  position:sticky;
  top:0;
  z-index:10;
}
header h1{margin:0;font-size:1.2rem;line-height:1.3}
header p{margin:.4rem 0 0;color:#6b7280;font-size:.88rem;line-height:1.45}
.topbar{
  display:flex;
  gap:.6rem;
  flex-wrap:wrap;
  align-items:center;
  margin-top:.75rem;
}
select,button{
  border-radius:8px;
  border:1px solid #d1d5db;
  padding:.6rem .85rem;
  font-size:.9rem;
  transition:all .15s ease;
}
select{background:#fff;min-width:170px;cursor:pointer}
button{background:#16275b;color:#fff;border:none;cursor:pointer;font-weight:500}
button:hover{background:#0f1f4a;transform:translateY(-1px)}
button:active{transform:translateY(0)}
button.secondary{background:#e5e7eb;color:#111}
button.secondary:hover{background:#d1d5db}
.status{font-size:.8rem;color:#6b7280;min-height:1.2em}

/* ===== CHAT WRAPPER ===== */
#chat-wrap{
  flex:1;
  display:flex;
  flex-direction:column;
  max-width:1100px;
  width:100%;
  margin:0 auto;
  overflow:hidden;
  position:relative;
  background:#f9fafb;
}

/* ===== MESSAGES LIST ===== */
#messages{
  flex:1;
  overflow-y:auto;
  padding:1.2rem;
  display:flex;
  flex-direction:column;
  gap:1rem;
  -webkit-overflow-scrolling:touch; /* smooth scroll iOS */
}
.msg{
  max-width:86%;
  padding:.95rem 1.05rem;
  border-radius:16px;
  line-height:1.6;
  white-space:pre-wrap;
  word-break:break-word;
  position:relative;
  font-size:.96rem;
  box-shadow:0 1px 3px rgba(0,0,0,.06);
  animation:fadeIn .2s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.msg.user{
  align-self:flex-end;
  background:#16275b;
  color:#fff;
  border-bottom-right-radius:5px;
}
.msg.assistant{
  align-self:flex-start;
  background:#fff;
  border:1px solid #e5e7eb;
  border-bottom-left-radius:5px;
  color:#1f2937;
}
.msg .meta{
  font-size:.7rem;
  color:#9ca3af;
  margin-top:.5rem;
  display:flex;
  gap:.6rem;
  align-items:center;
  flex-wrap:wrap;
  border-top:1px solid #f3f4f6;
  padding-top:.4rem;
}
.copy-btn{
  border:none;
  background:#f3f4f6;
  color:#374151;
  padding:.3rem .6rem;
  border-radius:6px;
  font-size:.75rem;
  cursor:pointer;
}
.copy-btn:hover{background:#e5e7eb}

/* ===== COMPOSER (zone saisie) ===== */
#composer{
  padding:.75rem;
  background:#fff;
  border-top:1px solid #e5e7eb;
  display:flex;
  gap:.6rem;
  align-items:flex-end;
  position:sticky;
  bottom:0;
}
#composer textarea{
  flex:1;
  min-height:54px;
  max-height:160px;
  resize:vertical;
  border:1px solid #d1d5db;
  border-radius:12px;
  padding:.75rem .9rem;
  font:1rem/1.5 Arial,Helvetica,sans-serif;
  outline:none;
  transition:border-color .15s;
}
#composer textarea:focus{border-color:#16275b;box-shadow:0 0 0 3px rgba(22,39,91,.08)}
#send{
  min-width:104px;
  padding:.75rem 1rem;
  border-radius:12px;
  font-weight:600;
}

/* ===== LOADING OVERLAY (roue) ===== */
.loading-overlay{
  position:absolute;
  inset:0;
  background:rgba(255,255,255,.82);
  backdrop-filter:blur(4px);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:20;
  flex-direction:column;
  gap:.9rem;
}
.loading-overlay.open{display:flex}
.spinner{
  width:48px;
  height:48px;
  border:5px solid rgba(22,39,91,.12);
  border-top-color:#16275b;
  border-radius:50%;
  animation:spin 1s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-text{font-size:.93rem;color:#16275b;text-align:center;line-height:1.5;font-weight:500}

/* ===== RESPONSIVE 100% ===== */
@media(max-width:700px){
  header{padding:.9rem .9rem}
  header h1{font-size:1.05rem}
  header p{font-size:.82rem}
  .topbar{flex-direction:column;align-items:stretch}
  .topbar select, .topbar button{width:100%}
  #messages{padding:.8rem;gap:.8rem}
  .msg{max-width:94%;font-size:.92rem;padding:.8rem .9rem;border-radius:14px}
  #composer{padding:.6rem;gap:.5rem}
  #composer textarea{font-size:16px} /* evite zoom iOS */
  #send{min-width:84px;padding:.65rem .8rem}
}
@media(max-width:400px){
  .msg{max-width:96%}
}
</style>
</head>
<body>
<header>
<h1>Tchat IA — r3M3M83r <span style="font-weight:400;color:#6b7280">/ Mémoire conversation + instructions.md</span></h1>
<p>
Chaque message interroge <strong>instructions.md</strong> via le même pipeline que <strong>Interroger le fichier</strong> (QUERY_EXPAND → sélection sections → PREUVES DIRECTES).
Le modèle <strong>ne peut pas inventer</strong>. L'historique est conservé en mémoire (localStorage) pour comprendre les pronoms ("et lui ?", "sa soeur ?") et avoir une vraie conversation jusqu'à <em>Clean</em> ou <em>Reset</em>.
</p>
<div class="topbar">
<label for="engineSelect" style="font-size:.85rem;color:#374151">Moteur :</label>
<select id="engineSelect" aria-label="Choix moteur IA">
<option value="">AUTO (<?= htmlspecialchars(strtoupper($currentEngine)) ?>)</option>
<?php foreach($enginesList as $eng): $eng=trim(strtolower($eng)); if($eng==='') continue; ?>
<option value="<?= htmlspecialchars($eng) ?>" <?= $eng===$currentEngine?'selected':'' ?>><?= htmlspecialchars(strtoupper($eng)) ?></option>
<?php endforeach; ?>
</select>
<button class="secondary" id="resetEngineBtn" title="Vide cache Node + recharge">↻ Reset moteur</button>
<button class="secondary" id="clearChatBtn" title="Vide historique localStorage">🗑 Clean tchat</button>
<span id="status" class="status" aria-live="polite"></span>
</div>
</header>

<div id="chat-wrap">
<div id="messages" aria-live="polite" aria-label="Historique tchat"></div>
<div id="composer">
<textarea id="input" placeholder="Pose ta question sur la mémoire... (Entrée = envoyer, Shift+Entrée = nouvelle ligne)" aria-label="Message"></textarea>
<button id="send" aria-label="Envoyer message">Envoyer</button>
</div>
<div id="loading" class="loading-overlay" aria-hidden="true">
<div class="spinner" aria-hidden="true"></div>
<div class="loading-text">Interrogation de instructions.md...<br>Expansion intention + preuves + appel LLM</div>
</div>
</div>

<script src="chat_prompts.js"></script>
<script>
/* ========================================================================
 * JS TCHAT — 100% commente, memoire conversation, responsive, reformulator
 * ========================================================================
 * BLOC 1 : Constantes & stockage
 * BLOC 2 : Render historique
 * BLOC 3 : Envoi message (avec memoire)
 * BLOC 4 : Evenements UI
 */

// ===== BLOC 1 : Constantes & stockage localStorage =====
// Cle localStorage pour persister la conversation meme apres reload
const STORAGE_KEY = 'r3m3m83r_chat_strict_history_v2';
const ENGINE_KEY = 'r3m3m83r_chat_engine';
const messagesEl = document.getElementById('messages');
const inputEl = document.getElementById('input');
const sendBtn = document.getElementById('send');
const engineSelect = document.getElementById('engineSelect');
const statusEl = document.getElementById('status');
const loadingEl = document.getElementById('loading');

// Charge historique depuis localStorage (tableau d'objets {role, content, engine, model, debug})
// On ne migre que si la nouvelle cle n'existe pas DU TOUT (null), pas si elle vaut [] apres un Clean
let history = [];
const storedV2 = localStorage.getItem(STORAGE_KEY);
if(storedV2 !== null){
  try{ history = JSON.parse(storedV2)||[]; }catch(e){ history = []; }
} else {
  // Migration une seule fois depuis anciennes cles, puis on supprime les anciennes pour eviter resurrection apres Clean
  const oldKeys = ['r3m3m83r_chat_strict_history','r3m3m83r_chat_history'];
  for(const k of oldKeys){
    const old = localStorage.getItem(k);
    if(old){
      try{
        const parsed = JSON.parse(old);
        if(Array.isArray(parsed) && parsed.length>0){ history = parsed; break; }
      }catch(e){}
    }
  }
  // Si on a migre, on sauvegarde direct en v2 et on nettoie les anciennes
  if(history.length>0){
    localStorage.setItem(STORAGE_KEY, JSON.stringify(history));
    oldKeys.forEach(k=>localStorage.removeItem(k));
  }
}
let engine = localStorage.getItem(ENGINE_KEY) || '';
if(engine) engineSelect.value = engine;

function saveHistory(){ localStorage.setItem(STORAGE_KEY, JSON.stringify(history)); }
function saveEngine(){ localStorage.setItem(ENGINE_KEY, engineSelect.value); }

// ===== BLOC 2 : Render historique + auto-scroll =====
/**
 * Affiche tous les messages de history dans #messages
 * - Ajoute bouton Copier sur chaque reponse assistant
 * - Affiche meta : moteur + modele + debug (preuves)
 * - Scroll auto en bas IMPERATIF (desktop + mobile)
 *   On utilise 3 mecanismes complementaires pour etre sur :
 *   1) scrollTop = scrollHeight
 *   2) lastMsg.scrollIntoView({block:'end'})
 *   3) requestAnimationFrame double pour attendre le layout
 */
function scrollToBottom(instant=true){
  // Instant = true pour user message (pas d'anim), false pour assistant (smooth possible)
  requestAnimationFrame(()=>{
    requestAnimationFrame(()=>{
      const behavior = instant ? 'auto' : 'smooth';
      // Methode 1 : scrollTop
      try{ messagesEl.scrollTo({top: messagesEl.scrollHeight, behavior}); }catch(e){ messagesEl.scrollTop = messagesEl.scrollHeight; }
      // Methode 2 : scrollIntoView du dernier message (plus fiable sur iOS)
      const last = messagesEl.lastElementChild;
      if(last){
        try{ last.scrollIntoView({behavior, block:'end'}); }catch(e){}
      }
    });
  });
}

function render(){
  messagesEl.innerHTML='';
  history.forEach(m=>{
    const div = document.createElement('div');
    div.className='msg '+(m.role==='assistant'?'assistant':'user');
    div.textContent = m.content;
    const meta = document.createElement('div');
    meta.className='meta';
    if(m.role==='assistant'){
      const eng = m.engine ? '· '+m.engine.toUpperCase() : '';
      const dbg = m.debug ? '· '+m.debug : '';
      const span = document.createElement('span');
      span.textContent = (m.model||'') + ' ' + eng + ' ' + dbg;
      meta.appendChild(span);
      const cp = document.createElement('button');
      cp.className='copy-btn'; cp.textContent='📋 Copier';
      cp.onclick=()=>{navigator.clipboard.writeText(m.content).then(()=>{cp.textContent='Copié !'; setTimeout(()=>cp.textContent='📋 Copier',1500)});};
      meta.appendChild(cp);
    }
    div.appendChild(meta);
    messagesEl.appendChild(div);
  });
  scrollToBottom(true);
}
render();
// Re-scroll au resize (clavier mobile qui s'ouvre/ferme)
window.addEventListener('resize', ()=>scrollToBottom(true));
if(window.visualViewport){
  window.visualViewport.addEventListener('resize', ()=>scrollToBottom(true));
}

// ===== BLOC 3 : Envoi message avec memoire conversation =====
/**
 * Envoie le message actuel + tout l'historique au PHP
 * - Le PHP va :
 *   1. Reconstituer retrievalQuery = dernier user + question (pour avoir bonnes preuves meme avec pronom)
 *   2. Appeler build_memory_context_for_topic() (pipeline reformulator)
 *   3. Appeler finalize_query_response_via_node() avec question + historique pronoms + contexte memoire
 * - L'historique est conserve cote client jusqu'a Clean
 */
async function sendMessage(){
  const text = inputEl.value.trim();
  if(!text) return;
  engine = engineSelect.value; saveEngine();

  // Push user dans historique local avant envoi
  history.push({role:'user', content:text});
  render(); saveHistory();
  scrollToBottom(true); // focus immediat sur ta question
  inputEl.value='';
  statusEl.textContent='Interrogation...';
  loadingEl.classList.add('open');
  sendBtn.disabled=true;
  inputEl.disabled=true;

  try{
    const res = await fetch('chat.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        message:text,
        history: history.slice(0,-1),
        engine: engine
      })
    });
    const data = await res.json();
    if(data.error) throw new Error(data.error);
    history.push({role:'assistant', content:data.reply, engine:data.engine, model:data.model, debug:data.debug});
    saveHistory(); render();
    scrollToBottom(false); // smooth vers la reponse IA
    statusEl.textContent = 'Moteur: '+(data.engine||engine||'auto').toUpperCase()+' · mémoire conversation OK';
  }catch(e){
    history.push({role:'assistant', content:'Erreur: '+e.message+' (Node tourne ?)', engine:'error'});
    render(); scrollToBottom(false); statusEl.textContent='Erreur';
  }finally{
    loadingEl.classList.remove('open');
    sendBtn.disabled=false;
    inputEl.disabled=false;
    inputEl.focus();
    // Dernier scroll de securite apres fermeture overlay (mobile)
    setTimeout(()=>scrollToBottom(true),150);
    setTimeout(()=>statusEl.textContent='',5000);
  }
}

// ===== BLOC 4 : Evenements UI =====
sendBtn.onclick = sendMessage;
inputEl.addEventListener('keydown', e=>{
  if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendMessage(); }
});
document.getElementById('clearChatBtn').onclick=()=>{
  if(confirm('Vider tout l\'historique du tchat ? (localStorage)')){
    history=[];
    // Vide TOUTES les cles historique connues pour eviter resurrection au Reset
    localStorage.setItem(STORAGE_KEY, JSON.stringify([]));
    localStorage.removeItem('r3m3m83r_chat_strict_history');
    localStorage.removeItem('r3m3m83r_chat_history');
    saveHistory(); render();
    statusEl.textContent='Tchat vide';
    setTimeout(()=>statusEl.textContent='',2000);
  }
};
document.getElementById('resetEngineBtn').onclick=()=>{
  if(confirm('Reset moteur LLM (vide cache Node) ? L\'historique du tchat sera conserve.')){
    statusEl.textContent='Reset moteur en cours...';
    fetch('chat.php?action=reset_llm')
      .then(()=>{ statusEl.textContent='Moteur réinitialisé (historique conservé)'; setTimeout(()=>statusEl.textContent='',3000); })
      .catch(()=>{ statusEl.textContent='Reset envoyé'; setTimeout(()=>statusEl.textContent='',3000); });
    // Ne recharge PAS la page, sinon on risque de relancer une migration ou perdre le focus
  }
};
</script>
</body>
</html>
