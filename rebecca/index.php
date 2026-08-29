<?php
    /**
     * ============================================================================
     * r3M3M83r/rebecca/index.php — Interface tchat Rebecca (ex chat.php)
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
     *  6. Appel finalize_query_response_via_node($questionForLLM, '', $memoryContext, 'query-chat')
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
    include_once __DIR__ . '/../moteurs/functions.php'; // Pipeline memoire + Node
    include_once __DIR__ . '/../moteurs/llm.php';      // Selection moteur partagee

    // ==================== CONSTANTES ====================
    // CORRECTIF 19/08/2026 : CHAT_PROMPT_JS pointait vers un nom de fichier
    // fixe et exact ('chat_prompts.js', minuscules + underscore). Le fichier
    // reellement present sur le serveur s'appelle 'Chat-Prompts.js'
    // (majuscules + tiret) -- deux noms DIFFERENTS sur un systeme de
    // fichiers Linux sensible a la casse (o2switch). Consequence concrete :
    // is_file() renvoyait toujours false, $chatAddon restait vide, et la
    // persona "Rebecca / Rebbye" definie dans le fichier n'etait JAMAIS
    // chargee -- d'ou les reponses generiques ("Reformulator", "je n'ai pas
    // de nom") malgre un fichier de prompt pourtant correctement rempli.
    //
    // find_chat_prompt_js() essaie plusieurs variantes de nom usuelles
    // (le nom "officiel" documente ci-dessus + les variantes de casse/tiret
    // que Mathieu a deja utilisees), puis en dernier recours scanne le
    // dossier a la recherche de tout fichier .js contenant "chat" et
    // "prompt" dans son nom (insensible a la casse). Ainsi, peu importe la
    // capitalisation exacte du fichier reellement present sur le serveur,
    // il sera trouve sans avoir a renommer quoi que ce soit.
    function find_chat_prompt_js(): string {
        // Cherche d'abord dans rebecca/, puis a la racine r3M3M83r (compat).
        $candidates = [
            'Chat-Prompts.js',
            'chat_prompts.js',
            'Chat_Prompts.js',
            'chat-prompts.js',
            'ChatPrompts.js',
            'chatprompts.js',
        ];
        $dirs = [__DIR__, dirname(__DIR__)];
        foreach ($dirs as $dir) {
            foreach ($candidates as $name) {
                $path = $dir . '/' . $name;
                if (is_file($path)) {
                    return $path;
                }
            }
        }
        // Dernier recours : balayage rebecca/ puis racine projet.
        foreach ([__DIR__, dirname(__DIR__)] as $dir) {
            $files = @scandir($dir);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if (preg_match('/^chat.*prompt.*\.js$/i', $file)) {
                    return $dir . '/' . $file;
                }
            }
        }
        return '';
    }
    define('CHAT_PROMPT_JS', find_chat_prompt_js()); // Prompt modulable externe (persona Rebecca/Rebbye)

    // ==================== LOGS (comme data.php) ====================
    /**
     * Log une requete chat dans 2 fichiers :
     * - reformulator/log/requests.log (format simple, comme saisie.php)
     * - access.log (format tracker.php lisible)
     */
    /**
     * CORRECTIF 19/08/2026 : journalise aussi le texte exact de la question
     * (comme saisie.php pour REFORMULER), et les erreurs eventuelles.
     */
    function chat_log_request(string $engine, int $msgLen, string $message = '', string $extra = '') {
        $logFile = dirname(__DIR__) . '/moteurs/log/requests.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'inconnue'))[0]);
        $clean = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $message)));
        if (mb_strlen($clean, 'UTF-8') > 500) {
            $clean = mb_substr($clean, 0, 500, 'UTF-8') . '...';
        }
        $line = '[' . date('Y-m-d H:i:s') . '] CHAT engine=' . $engine
            . ' ip=' . $ip
            . ' len=' . $msgLen
            . ($extra !== '' ? ' ' . $extra : '')
            . ' text=' . $clean
            . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        $accessLog = dirname(__DIR__) . '/access.log';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
        $accessLine = "------------------------------\n[" . date('d/m/Y H:i:s') . " UTC" . date('P') . "] Chat IA (" . $engine . ") IP : " . $ip
            . "\nMode : rebecca/index.php"
            . "\nURL : " . (($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''))
            . "\nUser-Agent : " . $ua
            . ($clean !== '' ? "\nQuestion : " . $clean : '')
            . "\n\n";
        @file_put_contents($accessLog, $accessLine, FILE_APPEND | LOCK_EX);
    }

    function chat_log_error(string $detail): void {
        $logFile = dirname(__DIR__) . '/moteurs/log/error.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $line = '[' . date('Y-m-d H:i:s') . '] CHAT_ERROR ' . trim($detail) . PHP_EOL;
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        @error_log('CHAT_ERROR ' . trim($detail));
    }

    // ==================== ROUTAGE LLM (pas de listes de mots-clefs) ====================
    /**
     * CORRECTIF 19/08/2026 : le LLM decide s'il faut chercher dans instructions.md.
     * Plus de listes de synonymes / greetings a maintenir dans le PHP.
     * purpose=chat-route (prompts.js) -> reponse MEMORY ou CHAT.
     * En cas d'echec reseau / reponse ambiguë : MEMORY (mieux chercher une fois de trop).
     */
    function chat_route_needs_memory(string $message, string $historySnippet = ''): array {
        $message = trim($message);
        if ($message === '') {
            return ['needs_memory' => true, 'raw' => '', 'debug' => 'message vide -> MEMORY'];
        }
        global $selected_engine;
        $text = "Message utilisateur :\n" . $message;
        if ($historySnippet !== '') {
            $text .= "\n\nHistorique recent (pour pronoms) :\n" . $historySnippet;
        }
        $payload = [
            'text' => $text,
            'purpose' => 'chat-route',
        ];
        if (!empty($selected_engine)) {
            $payload['engine'] = $selected_engine;
        }
        $raw = '';
        if (function_exists('call_reformulator_service')) {
            $raw = (string) call_reformulator_service($payload);
        }
        $norm = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $raw)), 'UTF-8');
        // Accepte "CHAT", "MEMORY", ou premiere ligne / mot dominant
        if (preg_match('/\bCHAT\b/', $norm) && !preg_match('/\bMEMORY\b/', $norm)) {
            return ['needs_memory' => false, 'raw' => $raw, 'debug' => 'route=CHAT'];
        }
        if (preg_match('/\bMEMORY\b/', $norm)) {
            return ['needs_memory' => true, 'raw' => $raw, 'debug' => 'route=MEMORY'];
        }
        // Defaut prudent
        return ['needs_memory' => true, 'raw' => $raw, 'debug' => 'route=MEMORY (defaut, reponse route ambiguë: ' . mb_substr($norm, 0, 40, 'UTF-8') . ')'];
    }

    /**
     * Reponse conversationnelle courte sans scan instructions.md (purpose=chat-talk).
     */
    function chat_talk_via_node(string $questionForLLM): string {
        global $selected_engine;
        $payload = [
            'text' => $questionForLLM,
            'purpose' => 'chat-talk',
        ];
        if (!empty($selected_engine)) {
            $payload['engine'] = $selected_engine;
        }
        if (function_exists('call_reformulator_service')) {
            return trim((string) call_reformulator_service($payload));
        }
        return '';
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
            $chatAddonSourceInfo = 'aucun fichier de prompt trouve (voir find_chat_prompt_js())';
            if (CHAT_PROMPT_JS !== '' && is_file(CHAT_PROMPT_JS)) {
                $js = file_get_contents(CHAT_PROMPT_JS);
                if (preg_match('/CHAT_ADDON\s*=\s*`(.+?)`/s', $js, $m)) {
                    $chatAddon = trim($m[1]);
                    $chatAddonSourceInfo = basename(CHAT_PROMPT_JS) . ' (CHAT_ADDON charge, ' . mb_strlen($chatAddon, 'UTF-8') . ' caracteres)';
                } else {
                    $chatAddonSourceInfo = basename(CHAT_PROMPT_JS) . ' trouve mais regex CHAT_ADDON=`...` n\'a rien matche (verifier la syntaxe du fichier)';
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
            //
            // CORRECTIF : on ne lance ce pipeline (donc les appels LLM
            // d'expansion/selection + la recherche dans instructions.md) QUE
            // si le message n'est pas un simple echange de politesse/hors
            // sujet -- voir chat_is_small_talk() plus haut. Ca evite d'aller
            // chercher dans le fichier pour "Salut, comment vas-tu ?".
            // Routage LLM (MEMORY vs CHAT) — pas de liste de mots en dur
            // Selection moteur via moteurs/llm.php (partage avec saisie.php)
            llm_apply_selected_engine($engineReq);
            $route = chat_route_needs_memory($message, $historyText);
            $needsMemory = !empty($route['needs_memory']);
            $memoryContext = '';
            $debugInfo = $route['debug'] ?? '';
            $isChatOnly = !$needsMemory;

            if ($isChatOnly) {
                $debugInfo .= ' | recherche instructions.md ignoree';
            } elseif (function_exists('build_memory_context_for_topic')) {
                $built = build_memory_context_for_topic($retrievalQuery);
                $memoryContext = $built['context'] ?? '';
                $debugInfo .= ' | ' . ($built['debug'] ?? 'memoire');
            }

            // Fallback si le build echoue (timeout, erreur). Ne PAS ecraser le debug route.
            if ($memoryContext === '' && !$isChatOnly && function_exists('build_memory_context_for_topic')) {
                // 2e tentative legere : meme appel (souvent cold-start Node)
                $built2 = build_memory_context_for_topic($retrievalQuery);
                $memoryContext = $built2['context'] ?? '';
                $debugInfo .= ' | retry build: ' . ($built2['debug'] ?? 'vide');
            }
            if ($memoryContext === '' && !$isChatOnly && function_exists('load_instructions_excerpt')) {
                $memoryContext = load_instructions_excerpt();
                $debugInfo .= ' | fallback excerpt (contexte faible — reponse a prendre avec prudence)';
            }

            // Log question exacte (requests.log + access.log)
            chat_log_request(
                $engineReq !== '' ? $engineReq : 'auto',
                mb_strlen($message, 'UTF-8'),
                $message,
                $isChatOnly ? 'mode=chat' : 'mode=memoire'
            );

            // --- 6) APPEL LLM STRICT via reformulator ---
            // finalize_query_response_via_node / call_reformulator_service lisent
            // la variable GLOBALE $selected_engine (pas seulement $_POST).
            // CORRECTIF 19/08/2026 : sans global, le select "Groq" etait ignore
            // et le serveur restait sur le defaut Mistral.
            $finalReply = '';
            $usedEngine = $engineReq !== '' ? $engineReq : 'auto';
            $usedModel = '';

            if (function_exists('finalize_query_response_via_node') || function_exists('call_reformulator_service')) {
                try {
                    llm_apply_selected_engine($engineReq);

                    if ($isChatOnly) {
                        // Reponse rapide sans pipeline memoire
                        $finalReply = chat_talk_via_node($questionForLLM);
                    } else {
                        $finalReply = finalize_query_response_via_node($questionForLLM, '', $memoryContext, 'query-chat');
                    }

                    if ($engineReq !== '') {
                        $usedEngine = $engineReq;
                    } elseif (function_exists('get_llm_info')) {
                        $info = get_llm_info();
                        $usedEngine = strtolower((string) ($info['engineName'] ?? 'auto'));
                        $usedModel = (string) ($info['selectedModel'] ?? '');
                    }
                } catch (Throwable $e) {
                    $finalReply = '';
                    $debugInfo .= ' | exception finalize: ' . $e->getMessage();
                    chat_log_error($e->getMessage());
                }
            }

            // Fallback direct si finalize echoue (Node down ?)
            if ($finalReply === '') {
                $baseUrl = function_exists('get_reformulator_base_url') ? get_reformulator_base_url() : 'https://charreyre.net/r3M3M83r/reformulator';
                $url = rtrim($baseUrl, '/') . '/reformuler';
                $payload = [
                    'text' => $questionForLLM,
                    'instructionsContext' => $memoryContext,
                    'purpose' => 'query-chat',
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
                chat_log_error('reponse vide | ' . $debugInfo);
                http_response_code(500);
                echo json_encode(['error' => "Le moteur n'a pas répondu. Vérifie que Node tourne (moteurs/server.js). Debug: $debugInfo"], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Reponse OK
            echo json_encode([
                'reply' => $finalReply,
                'engine' => $usedEngine,
                'model' => $usedModel,
                'debug' => $debugInfo . "\nPrompt tchat (persona) : " . $chatAddonSourceInfo,
                'via' => 'query-strict-memoire-conversation'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ==================== RESET MOTEUR (clear cache Node) ====================
    if (isset($_GET['action']) && $_GET['action'] === 'reset_llm') {
        $baseUrl = function_exists('get_reformulator_base_url') ? get_reformulator_base_url() : '';
        if ($baseUrl) { @file_get_contents(rtrim($baseUrl,'/').'/llm-info?reset=1'); }
        header('Location: ./?reset=ok');
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
        <title>Rebecca — Tchat memoire r3M3M83r</title>
        <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
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
            select{background:#fff;cursor:pointer;min-width:0}
            button{background:#16275b;color:#fff;border:none;cursor:pointer;font-weight:500}
            button:hover{background:#0f1f4a;transform:translateY(-1px)}
            button:active{transform:translateY(0)}
            button.secondary{background:#e5e7eb;color:#111}
            button.secondary:hover{background:#d1d5db}
            .status{font-size:.8rem;color:#6b7280;min-height:1.2em}

            /* ===== GROUPE MOTEUR (select + RAZ + Vider) =====
             * Reste TOUJOURS sur une seule ligne (flex-direction:row jamais
             * change), y compris en responsive.
             * CORRECTIF 19/08/2026 : le "width:100%" et les flex-grow (2/1)
             * etaient AUSSI actifs en desktop par erreur, ce qui etirait le
             * groupe sur toute la largeur de la topbar et forcait le label
             * "Moteur :" a passer au-dessus -- perdant l'aspect compact
             * d'origine. Ces regles de largeur/etirement ne doivent
             * s'appliquer qu'en mobile (voir @media(max-width:700px)
             * plus bas) ; en desktop, le groupe garde sa taille naturelle
             * (select ~170px, boutons a la taille de leur texte), exactement
             * comme avant l'ajout de .engine-row. */
            .engine-label{font-size:.85rem;color:#374151;white-space:nowrap}
            .engine-row{
                display:flex;
                flex-direction:row;
                gap:.5rem;
                min-width:0;
            }
            .engine-row select{min-width:170px}
            .engine-row button{white-space:nowrap}

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
            .bubble-body{line-height:1.45;word-wrap:break-word}
        .bubble-body strong{font-weight:600}
        .msg.assistant{
                align-self:flex-start;
                background:#fff;
                border:1px solid #e5e7eb;
                border-bottom-left-radius:5px;
                color:#1f2937;
            }
            .msg .meta{
                font-style: italic;
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
            /* ===== BARRE D'OUTILS (bouton Copier) =====
             * Placee juste SOUS la reponse, AVANT le bloc meta/debug -- pour
             * ne pas laisser croire que "Copier" recopie aussi le debug.
             * Alignee a droite (demande explicite). N'existe que pour les
             * messages assistant (jamais pour les messages utilisateur). */
            .msg-toolbar{
                display:flex;
                justify-content:flex-end;
                margin-top:.6rem;
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

            /* ===== PANNEAU DEBUG (repris de saisie.php) =====
             * Collapse par defaut (element <details> natif, sans attribut
             * "open"), meme esthetique que reformulator/saisie.php. */
            .debug-panel{margin:.5rem 0 0;border:1px solid #e5e7eb;border-radius:8px;background:#f4f6fb;color:#4b5563}
            .debug-panel summary{cursor:pointer;list-style:none;padding:.4rem .7rem;font-size:.74rem;font-style:italic;color:#6b7280;user-select:none}
            .debug-panel summary::-webkit-details-marker{display:none}
            .debug-panel summary::before{content:"▸ ";font-style:normal;color:#9ca3af}
            .debug-panel[open] summary::before{content:"▾ "}
            .debug-panel summary:hover{color:#374151;background:#eef2ff;border-radius:8px}
            .debug-panel pre{margin:0;padding:.55rem .75rem .7rem;font-size:.72rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;color:#4b5563;border-top:1px solid #e5e7eb;background:#fafbff;border-radius:0 0 8px 8px}

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
                /* Ici seulement (mobile) : le groupe s'etire sur 100% de la
                 * largeur, select plus large que les boutons (ratio 2:1). */
                .engine-row{width:100%}
                .engine-row select{flex:2 1 0;min-width:0;font-size:.82rem;padding:.55rem .4rem}
                .engine-row button{flex:1 1 0;min-width:0;font-size:.82rem;padding:.55rem .4rem}
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
            <h1>Tchat IA — Projet r3M3M83r</h1>
            <p>
                Interface <strong>Rebecca</strong> uniquement. Le moteur memoire est celui de <strong>r3M3M83r</strong>
                (meme pipeline que Reformulator / saisie.php) — cette page ne fait que l'UI tchat.
            </p>
            <div class="topbar">
                <label for="engineSelect" class="engine-label">Moteur :</label>
                <div class="engine-row">
                    <select id="engineSelect" aria-label="Choix moteur IA">
                        <?php
                            $enginePref = ''; // le JS restaure localStorage apres
                            echo llm_render_engine_options($enginePref, $llmInfo);
                        ?>
                    </select>
                    <button class="secondary" id="resetEngineBtn" title="Vide cache Node + recharge">RAZ</button>
                    <button class="secondary" id="clearChatBtn" title="Vide historique localStorage">Vider</button>
                </div>
                <span id="status" class="status" aria-live="polite"></span>
            </div>
        </header>

        <div id="chat-wrap">
            <div id="messages" aria-live="polite" aria-label="Historique tchat"></div>
            <div id="composer">
                <textarea id="input" placeholder="Questionner la mémoire de Mathieu ..." aria-label="Message"></textarea>
                <button id="send" aria-label="Envoyer message">Envoyer</button>
            </div>
            <div id="loading" class="loading-overlay" aria-hidden="true">
                <div class="spinner" aria-hidden="true"></div>
                <div class="loading-text">Interrogation de instructions.md ...<br>Expansion intention + preuves + appel LLM</div>
            </div>
        </div>

        <!-- CHAT_ADDON charge cote serveur (find_chat_prompt_js) ; pas besoin cote client -->
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

            /**
             * CORRECTIF 19/08/2026 : scrollToBottom() amene le BAS du panneau
             * de messages a l'ecran -- correct pour voir son propre message
             * qu'on vient de taper, mais mauvais pour une reponse IA longue
             * (frequent avec le debug/preuves) : on ne voyait alors QUE la
             * fin de la reponse, obligeant a remonter manuellement pour la
             * lire depuis le debut. Particulierement genant en responsive ou
             * l'ecran est petit.
             *
             * scrollToMessageStart() amene au contraire le DEBUT (le haut) du
             * DERNIER message a l'ecran -- l'utilisateur voit la reponse
             * depuis son premier mot, et peut ensuite scroller vers le bas a
             * son rythme pour lire la suite / le bouton Copier / le debug.
             * Utilise scrollIntoView(block:'start'), fiable sur desktop et
             * mobile (y compris iOS Safari). Meme double
             * requestAnimationFrame que scrollToBottom() pour attendre que le
             * layout (render() venant d'ajouter le noeud) soit stabilise
             * avant de calculer la position de scroll.
             */
            function scrollToMessageStart(){
            requestAnimationFrame(()=>{
                requestAnimationFrame(()=>{
                const last = messagesEl.lastElementChild;
                if(!last) return;
                try{
                    last.scrollIntoView({behavior:'smooth', block:'start'});
                }catch(e){
                    // Fallback navigateurs anciens : calcul manuel de la position
                    messagesEl.scrollTop = Math.max(0, last.offsetTop - 12);
                }
                });
            });
            }


        /** Escape HTML puis **gras**, *italique*, sauts de ligne ; retire ## titres. */
        function formatChatHtml(raw) {
            if (!raw) return '';
            let s = String(raw);
            s = s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            s = s.replace(/^#{1,6}\s*/gm, '');
            s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            s = s.replace(/(?<!\*)\*([^*]+)\*(?!\*)/g, '<em>$1</em>');
            s = s.replace(/\n/g, '<br>');
            return s;
        }

        function render(){
            messagesEl.innerHTML='';
            history.forEach(m=>{
                const div = document.createElement('div');
                div.className='msg '+(m.role==='assistant'?'assistant':'user');
                // Texte de la bulle dans son propre element : necessaire pour
                // pouvoir ajouter toolbar/meta/debug APRES sans les ecraser
                // (textContent sur div remplacerait tout enfant existant).
                const bubbleText = document.createElement('div');
                bubbleText.className = 'bubble-body';
                // Affichage tchat : prose + gras leger, sans titres markdown bruts
                bubbleText.innerHTML = formatChatHtml(m.content);
                div.appendChild(bubbleText);

                // CORRECTIF : les messages utilisateur n'ont plus AUCUN bloc
                // .meta (meme vide) -- c'est ce div vide qui affichait une
                // ligne grise fantome sous les bulles de question, puisque
                // .meta porte un border-top meme sans contenu.
                if(m.role==='assistant'){
                // --- Barre d'outils : bouton Copier seul, juste sous la
                // reponse, avant meta/debug, aligne a droite. Ne copie QUE
                // le texte de la reponse (pas le debug) -- comportement
                // volontairement conserve, la position/alignement suffit a
                // lever l'ambiguite visuelle.
                const toolbar = document.createElement('div');
                toolbar.className='msg-toolbar';
                const cp = document.createElement('button');
                cp.className='copy-btn'; cp.textContent='📋 Copier';
                cp.onclick=()=>{navigator.clipboard.writeText(m.content).then(()=>{cp.textContent='Copié !'; setTimeout(()=>cp.textContent='📋 Copier',1500)});};
                toolbar.appendChild(cp);
                div.appendChild(toolbar);

                // --- Ligne meta : moteur + modele uniquement (plus le debug,
                // deplace dans son propre panneau collapsible ci-dessous).
                const meta = document.createElement('div');
                meta.className='meta';
                const eng = m.engine ? '· '+m.engine.toUpperCase() : '';
                const span = document.createElement('span');
                span.textContent = (m.model||'') + ' ' + eng;
                meta.appendChild(span);
                div.appendChild(meta);

                // --- Debug : collapse par defaut, meme esthetique que
                // reformulator/saisie.php (element <details> natif).
                if(m.debug){
                    const details = document.createElement('details');
                    details.className='debug-panel';
                    const summary = document.createElement('summary');
                    summary.textContent='Debug (preuves / contexte memoire)';
                    const pre = document.createElement('pre');
                    pre.textContent = m.debug;
                    details.appendChild(summary);
                    details.appendChild(pre);
                    div.appendChild(details);
                }
                }
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
            statusEl.textContent='Interrogation ...';
            loadingEl.classList.add('open');
            sendBtn.disabled=true;
            inputEl.disabled=true;

            try{
                const res = await fetch('', {
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
                // Debut de la reponse visible (pas seulement le bas / debug)
                if (typeof scrollToMessageStart === 'function') scrollToMessageStart();
                else scrollToBottom(false);
                statusEl.textContent = 'Moteur: '+(data.engine||engine||'auto').toUpperCase()+(data.debug&&/route=CHAT|mode=chat/i.test(String(data.debug))?' · conversation':' · memoire');
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
                statusEl.textContent='Reset moteur en cours ...';
                fetch('?action=reset_llm')
                .then(()=>{ statusEl.textContent='Moteur réinitialisé (historique conservé)'; setTimeout(()=>statusEl.textContent='',3000); })
                .catch(()=>{ statusEl.textContent='Reset envoyé'; setTimeout(()=>statusEl.textContent='',3000); });
                // Ne recharge PAS la page, sinon on risque de relancer une migration ou perdre le focus
            }
            };
        </script>

        <footer style="max-width:1100px;width:100%;margin:0 auto;padding:.65rem 1.2rem 1rem;text-align:right;font-size:.82rem;color:#6b7280;border-top:1px solid #e5e7eb;background:#f9fafb;">
            <p style="margin:0 0 .4rem;">Les moteurs IA peuvent faire des erreurs : Verifier les faits importants dans <a href="https://mathieu.charreyre.net/r3M3M83r/sections" target="_blank" rel="noopener noreferrer" title="Interroger directement le fichier d'instructions"><strong>instructions.md</strong></a> avant de les reprendre.</p>
            <p style="margin:0;">
                <a href="<?php echo htmlspecialchars(defined('CPANEL_URL') ? CPANEL_URL : 'https://nombre.o2switch.net:2083/', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir le cPanel o2switch Node.js">Ouvrir cPanel o2switch</a>
                &bull;
                <a href="../moteurs/log_proxy.php?name=requests_log" target="_blank" rel="noopener noreferrer" title="Voir les requetes effectuees">Voir les requetes</a>
                &bull;
                <a href="../moteurs/log_proxy.php?name=error_log" target="_blank" rel="noopener noreferrer" title="Voir les retours d erreurs">Voir les erreurs</a>
            </p>
        </footer>

        <?php
            if (function_exists('r3m3m83r_node_health_ui')) {
                echo r3m3m83r_node_health_ui('../moteurs/node_health.php');
            }
        ?>

    </body>
</html>