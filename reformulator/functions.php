<?php
    /**
     * functions.php - Interface de saisie locale pour instructions.md
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
     *
     * CORRECTIF 04/07/2026 (Mathieu CHARREYRE) :
     *   - extract_via_node() et extract_text_from_file() renvoient desormais un
     *     tableau structure ['success' => bool, 'text' => string, 'error' => string]
     *     au lieu d'une chaine ou l'erreur etait detectee par recherche du mot
     *     "Erreur" dans le texte (faux positifs si un vrai document contient ce
     *     mot, et le detail de l'erreur reelle etait perdu). Toute extraction
     *     ratee est desormais aussi tracee via error_log() (donc dans
     *     reformulator/log/error.log).
     *
     * CORRECTIF 20/07/2026 (v3) : la recherche du bouton "Interroger" souffrait
     * de deux limites cumulees :
     *   1. Le contexte envoye au LLM restait quasi-systematiquement l'extrait
     *      GENERIQUE des ~2000 premiers caracteres du fichier (charge une
     *      seule fois au demarrage de la page et renvoye tel quel dans le
     *      champ cache a chaque soumission), car la condition
     *      "if ($instructions_context === '')" n'etait quasiment jamais vraie
     *      -- ce contexte generique n'a aucune raison de contenir la section
     *      reellement pertinente pour une question donnee.
     *   2. Meme quand une section pertinente etait identifiee, son contenu
     *      etait tronque a 800-1200 caracteres, et les "preuves" locales
     *      n'incluaient qu'UNE SEULE phrase par section (180 caracteres max)
     *      -- d'ou des reponses qui semblent "tronquees a l'endroit
     *      interessant", quel que soit le moteur LLM utilise (le probleme
     *      est en amont du LLM, pas dans le LLM lui-meme).
     * Voir le handler query_instructions plus bas pour le detail du correctif.
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
    define('CPANEL_URL', 'https://nombre.o2switch.net:2083/cpsess2639929987/frontend/o2switch/lveversion/nodejs-selector.html.tt#/');

    // -----------------------------------------------------------------------------
    // BLOC 1 : Recuperation des informations LLM depuis le backend Node.js
    // -----------------------------------------------------------------------------

    // Normalise une URL de moteur LLM extraite de server.js (ajoute le schéma
    // si absent, retire le slash final) pour un affichage/lien propre.
    function normalize_engine_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return rtrim($url, '/');
    }

    function parse_llm_info_from_server_file(): array {
        $filePath = __DIR__ . '/reformulator/server.js';
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Supprime les commentaires pour parsing propre
        $content = preg_replace(['@//.*$@m', '@/\*.*?\*/@s'], '', $content);

        $info = [];

        // Lecture dynamique du DEFAULT_LLM_ENGINE
        if (preg_match('/const\s+DEFAULT_LLM_ENGINE\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
            $info['defaultEngine'] = strtolower(trim($matches[1]));
        }

        // Lecture du LLM_ENGINE actif
        if (preg_match('/const\s+LLM_ENGINE\s*=\s*\(process\.env\[.*?\]\s*\|\|\s*([A-Za-z0-9_]+)\)\.toLowerCase\(\)/', $content, $matches)) {
            $info['engineName'] = strtoupper(trim($matches[1]));
        }

        if (empty($info['engineName']) && !empty($info['defaultEngine'])) {
            $info['engineName'] = strtoupper($info['defaultEngine']);
        }

        $selectedEngine = strtolower($info['engineName'] ?? $info['defaultEngine'] ?? 'mistral');

        // Extraction du modèle et URL pour le moteur actif
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
            $info['selectedModel'] = 'modele inconnu';
        }

        return $info;
    }

    // Appelle la route `/llm-info` exposée par reformulator/server.js pour récupérer
    // la configuration LLM active. Si le service Node.js n'est pas joignable, on bascule
    // sur un fallback local en lisant `reformulator/server.js` directement.
    // CORRECTIF 05/07/2026 (v4) : classifie precisement l'echec de /llm-info au
    // lieu de toujours afficher "Service Node.js inaccessible". Distingue :
    //   - 'down'      : panne reelle (timeout, connexion refusee) -> Node.js ne tourne pas
    //   - 'blocked'    : HTTP 406 -> tres probablement mod_security o2switch, PAS Node.js
    //   - 'route'      : HTTP 404 -> Node.js tourne mais la route /llm-info n'existe pas
    //   - 'app_error'  : HTTP 500+ -> Node.js tourne mais plante sur cette requete
    //                    (cause frequente : dependance npm manquante, ex: word-extractor
    //                    ajoutee a package.json sans avoir relance "npm install" sur o2switch)
    //   - 'malformed'  : HTTP 200 mais JSON invalide/incomplet
    //   - 'unknown'    : cas non categorise, on affiche quand meme le detail brut
    function classify_llm_info_failure(string $curlError, int $httpCode, string $rawResult): array {
        $curlErrorLower = mb_strtolower($curlError, 'UTF-8');

        $connectionKeywords = [
            "couldn't connect", 'connection refused', 'connection timed out',
            'resolve host', 'resolving timed out', 'operation timed out',
            'ssl connect error', 'could not resolve', 'empty reply from server',
            'failed to connect', 'network is unreachable', 'no route to host',
        ];

        $looksLikeConnectionFailure = ($httpCode === 0);
        if (!$looksLikeConnectionFailure && $curlError !== '') {
            foreach ($connectionKeywords as $keyword) {
                if (strpos($curlErrorLower, $keyword) !== false) {
                    $looksLikeConnectionFailure = true;
                    break;
                }
            }
        }

        if ($looksLikeConnectionFailure) {
            return [
                'diagnosis' => 'down',
                'detail'    => $curlError !== '' ? $curlError : 'Aucune réponse du serveur (connexion impossible).',
            ];
        }

        // La connexion TCP a reussi (on a un code HTTP), le probleme est donc
        // ailleurs : blocage WAF, route incorrecte, erreur applicative Node...
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr($rawResult, 0, 300))));

        if ($httpCode === 406) {
            return [
                'diagnosis' => 'blocked',
                'detail'    => 'HTTP 406 — requête bloquée avant Node.js (probable mod_security o2switch, pas un problème du service lui-même). ' . $snippet,
            ];
        }
        if ($httpCode === 404) {
            return [
                'diagnosis' => 'route',
                'detail'    => 'HTTP 404 — la route /llm-info est introuvable. Vérifier que server.js expose bien cette route et que REFORMULATOR_BASE_URL pointe au bon endroit. ' . $snippet,
            ];
        }
        if ($httpCode >= 500) {
            return [
                'diagnosis' => 'app_error',
                'detail'    => "HTTP $httpCode — Node.js a répondu mais a rencontré une erreur interne (ex : dépendance npm manquante après un déploiement — penser à relancer \"npm install\" côté o2switch). " . $snippet,
            ];
        }
        if ($httpCode > 0) {
            return [
                'diagnosis' => 'unknown',
                'detail'    => "HTTP $httpCode. " . $snippet,
            ];
        }

        return [
            'diagnosis' => 'down',
            'detail'    => 'Réponse vide, aucun code HTTP reçu.',
        ];
    }

    // CORRECTIF 18/07/2026 : execute une seule tentative de contact avec /llm-info.
    // Extrait de get_llm_info() pour permettre les tentatives repetees ci-dessous.
    function fetch_llm_info_once(string $url): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        close_curl_handle($ch);
        return ['result' => $result, 'error' => $error, 'httpCode' => $httpCode];
    }

    function get_llm_info(): array {
        $url = REFORMULATOR_BASE_URL . '/llm-info';

        // CORRECTIF 18/07/2026 : apres quelques jours d'inactivite, o2switch/Passenger
        // met l'app Node en veille. La premiere requete qui la reveille peut echouer
        // (404/502/503/504/timeout) le temps que le process redemarre (cold start) et
        // que le mapping Passenger se re-branche. On retente automatiquement avant
        // d'afficher une erreur, pour eviter un aller-retour manuel dans cPanel a
        // chaque reprise d'utilisation apres une periode d'inactivite. On ne retente
        // PAS sur un blocage WAF franc (406) ou une vraie erreur applicative (500 avec
        // corps de reponse), qui ne se resoudront pas tout seuls en quelques secondes.
        $maxAttempts = 3;
        $retryDelaySeconds = 2;
        $attempt = 0;
        $lastAttempt = ['result' => false, 'error' => '', 'httpCode' => 0];

        while ($attempt < $maxAttempts) {
            $attempt++;
            $lastAttempt = fetch_llm_info_once($url);
            $transientFailure = ($lastAttempt['error'] !== '' || !$lastAttempt['result'] || $lastAttempt['httpCode'] >= 400);
            if (!$transientFailure) {
                break;
            }
            $classification = classify_llm_info_failure($lastAttempt['error'], $lastAttempt['httpCode'], (string) $lastAttempt['result']);
            $worthRetrying = in_array($classification['diagnosis'], ['down', 'route'], true);
            if (!$worthRetrying || $attempt >= $maxAttempts) {
                break;
            }
            sleep($retryDelaySeconds);
        }

        $result   = $lastAttempt['result'];
        $error    = $lastAttempt['error'];
        $httpCode = $lastAttempt['httpCode'];

        if ($error || !$result || $httpCode >= 400) {
            error_log("LLM-INFO unreachable apres $attempt tentative(s) - HTTP $httpCode - $error");
            $classification = classify_llm_info_failure($error, $httpCode, (string) $result);
            $fallback = parse_llm_info_from_server_file();
            $fallback['reachable'] = false;
            $fallback['last_error'] = $error ?: "HTTP $httpCode";
            $fallback['diagnosis'] = $classification['diagnosis'];
            $fallback['diagnosis_detail'] = $classification['detail'];
            return $fallback;
        }

        $data = json_decode($result, true);
        if (!is_array($data) || empty($data['engineName'])) {
            $fallback = parse_llm_info_from_server_file();
            $fallback['reachable'] = false;
            $fallback['diagnosis'] = 'malformed';
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr((string) $result, 0, 300))));
            $fallback['diagnosis_detail'] = 'Réponse HTTP 200 mais JSON invalide ou incomplet — ' . $snippet;
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

    // CORRECTIF 20/07/2026 (v3) : distinct de extract_query_keywords_via_node()
    // ci-dessus, qui ne fait qu'extraire les mots deja presents dans la
    // question. Celle-ci demande au LLM de deviner des termes ABSENTS de la
    // question mais lies semantiquement (voir QUERY_EXPAND_PROMPT cote
    // server.js) -- necessaire pour qu'une question sur "mes cousins" trouve
    // aussi "oncle", "tante", "branche paternelle", etc. dans le fichier.
    function expand_query_terms_via_node(string $text, string $instructionsContext = ''): string {
        $payload = ['text' => $text, 'purpose' => 'query-expand'];
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

        // Force l'envoi du contexte complet si possible
        if (empty($payload['instructionsContext'])) {
            $payload['instructionsContext'] = load_instructions_excerpt(); // fallback
        }

        return call_reformulator_service($payload);
    }

    function close_curl_handle(mixed $ch): void {
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

    // CORRECTIF 20/07/2026 (v3) : limites de troncature relevees de 800/1200 a
    // 2500 caracteres par section. Ces fonctions ne sont appelees qu'avec un
    // petit nombre de sections deja jugees pertinentes (2-3 max), donc le
    // volume total transmis au LLM reste raisonnable meme avec des sections
    // plus longues -- l'objectif est justement d'eviter de couper pile a
    // l'endroit interessant.
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
            if (mb_strlen($sectionContent, 'UTF-8') > 2500) {
                $sectionContent = mb_substr($sectionContent, 0, 2500, 'UTF-8') . '...';
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
            if (mb_strlen($sectionContent, 'UTF-8') > 2500) {
                $sectionContent = mb_substr($sectionContent, 0, 2500, 'UTF-8') . '...';
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
            if (match_term_in_text($term, $content)) {  // ← corrigé
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

    // -----------------------------------------------------------------------------
    // FONCTIONS DE RECHERCHE AMÉLIORÉES (Interroger)
    // -----------------------------------------------------------------------------

    function match_term_in_text(string $term, string $text): bool {
        if ($term === '') return false;
        $term_norm = normalize_for_matching($term);
        $text_norm = normalize_for_matching($text);

        if (mb_stripos($text_norm, $term_norm) !== false) {
            return true;
        }

        $pattern = '/\b' . preg_quote($term, '/') . '\b/ui';
        return preg_match($pattern, $text) === 1 ||
            preg_match('/' . preg_quote($term_norm, '/') . '/ui', $text_norm) === 1;
    }

    function match_term_in_text_improved(string $term, string $text): bool {
        return match_term_in_text($term, $text); // alias pour compatibilité
    }

    function search_with_counts(string $query, array $sections): array {
        $llmKeywords = extract_query_keywords_via_node($query);
        $searchTerms = choose_search_terms($query, $llmKeywords, $sections);

        // Force noms propres exacts
        preg_match_all('/\b[A-ZÀÂÄÉÈÊËÎÏÔÖÙÛÜ][a-zàâäéèêëîïôöùûü]+\b/u', $query, $names);
        foreach ($names[0] as $name) {
            $searchTerms[] = $name;  // on garde la casse pour les noms
        }
        $searchTerms = array_unique(array_filter($searchTerms));

        $results = [];
        $totalOccurrences = 0;

        foreach ($sections as $title => $content) {
            $score = 0;
            $matchesInSection = [];
            $countInSection = 0;
            $normContent = normalize_for_matching($content);

            foreach ($searchTerms as $term) {
                if (empty($term)) continue;

                $termNorm = normalize_for_matching($term);

                // Recherche TRÈS stricte : mot entier uniquement
                $countInSection = preg_match_all('/\b' . preg_quote($termNorm, '/') . '\b/ui', $normContent);

                $totalOccurrences += $countInSection;

                if ($countInSection > 0) {
                    $score += 20;  // poids très élevé
                    $sentences = extract_section_match_sentences($content, [$term], 5);
                    $matchesInSection = array_merge($matchesInSection, $sentences);
                }
            }

            if ($score >= 20) {  // seuil élevé
                $results[$title] = [
                    'score'       => $score,
                    'content'     => $content,
                    'matches'     => array_slice(array_unique($matchesInSection), 0, 5),
                    'excerpt'     => $matchesInSection[0] ?? '',
                    'occurrences' => $countInSection
                ];
            }
        }

        uasort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return [
            'sections'   => $results,
            'total_occ'  => $totalOccurrences,
            'terms'      => $searchTerms
        ];
    }

    // Recherche locale légère et plus robuste pour le bouton Interroger
    // CORRECTIF 18/07/2026 : la version precedente traitait toute la question
    // comme UNE SEULE chaine litterale a rechercher (ex: la phrase normalisee
    // entiere "qui est ma cousine et qui est notifie..."), ce qui ne correspond
    // quasiment jamais a du texte reel pour une question en langage naturel.
    // Consequence concrete observee : la recherche locale ne trouvait rien,
    // aucune preuve n'etait transmise au LLM, qui finissait par inventer une
    // citation plausible plutot que d'admettre l'absence de resultat.
    // On decoupe desormais la question en mots-cles significatifs (comme le fait
    // deja search_with_counts()/search_instructions_sections_by_terms) et on
    // cherche chaque terme independamment, en sous-chaine normalisee (accents et
    // casse ignores) -- ce qui permet par exemple de retrouver "cousine" a
    // l'interieur de "petite-cousine".
    //
    // CORRECTIF 20/07/2026 (v3) : accepte desormais un jeu de termes DEJA
    // CALCULE en parametre optionnel ($precomputedTerms), pour permettre au
    // handler query_instructions de fusionner mots-cles locaux + mots-cles LLM
    // + termes elargis semantiquement avant la recherche (voir plus bas). Ne
    // garde qu'une seule phrase "excerpt" par section pour compatibilite
    // ascendante, mais expose aussi 'excerpts' (plusieurs phrases) et
    // 'content' (contenu integral de la section) pour un contexte plus riche.
    function search_with_counts_light(string $query, array $sections, ?array $precomputedTerms = null): array {
        if ($precomputedTerms !== null) {
            $terms = $precomputedTerms;
        } else {
            $terms = extract_keywords($query);

            // Ajoute les mots commencant par une majuscule meme courts (noms propres,
            // ex: "Edith"), qu'extract_keywords() seul ne privilegie pas specifiquement.
            $rawQueryWords = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($rawQueryWords as $word) {
                $cleanWord = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
                if ($cleanWord !== '' && preg_match('/^\p{Lu}/u', $cleanWord) && mb_strlen($cleanWord, 'UTF-8') >= 3) {
                    $terms[] = $cleanWord;
                }
            }
        }
        $terms = array_values(array_unique(array_filter($terms, function ($t) { return trim($t) !== ''; })));

        if (empty($terms)) {
            return ['sections' => [], 'total_occ' => 0];
        }

        $results = [];
        $totalOccurrences = 0;

        foreach ($sections as $title => $content) {
            $normContent = normalize_for_matching($content);
            $sectionOccurrences = 0;
            $matchedTerms = [];

            foreach ($terms as $term) {
                $termNorm = normalize_for_matching($term);
                if ($termNorm === '') continue;
                $count = substr_count($normContent, $termNorm);
                if ($count > 0) {
                    $sectionOccurrences += $count;
                    $matchedTerms[] = $term;
                }
            }

            if ($sectionOccurrences > 0) {
                $totalOccurrences += $sectionOccurrences;
                // CORRECTIF 20/07/2026 (v3) : jusqu'a 6 phrases matchees (au
                // lieu d'1 seule), plus le contenu integral de la section pour
                // permettre au handler d'inclure des sections entieres dans
                // le contexte final envoye au LLM.
                $sentences = extract_section_match_sentences($content, $matchedTerms, 6);

                $results[$title] = [
                    'occurrences' => $sectionOccurrences,
                    'excerpt'     => $sentences[0] ?? '',
                    'excerpts'    => $sentences,
                    'content'     => $content,
                ];
            }
        }

        // On garde les meilleures sections
        uasort($results, fn($a, $b) => $b['occurrences'] <=> $a['occurrences']);
        $results = array_slice($results, 0, 8, true);   // un peu plus large

        return [
            'sections'   => $results,
            'total_occ'  => $totalOccurrences
        ];
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

    /**
     * Extrait le texte d'un fichier uploade (.txt, .md, .pdf, .docx, .doc).
     *
     * CORRECTIF 04/07/2026 : renvoie desormais un tableau structure
     * ['success' => bool, 'text' => string, 'error' => string] au lieu d'une
     * simple chaine. Avant ce correctif, une erreur d'extraction etait signalee
     * en injectant le mot "Erreur" dans le texte lui-meme, ce qui produisait de
     * faux positifs (un document contenant legitimement ce mot) et masquait le
     * detail reel de l'echec. Voir aussi extract_via_node() ci-dessous.
     */
    function extract_text_from_file(string $tmpPath, string $originalName): array {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $format = strtoupper($ext);

        if ($ext === 'txt' || $ext === 'md') {
            $rawText = trim(@file_get_contents($tmpPath) ?: '');
            if ($rawText === '') {
                $error = "Fichier texte vide ou illisible : $originalName";
                error_log("EXTRACT_TEXT_FROM_FILE echec - $error");
                return ['success' => false, 'text' => '', 'error' => $error];
            }
            $prefix = "Mathieu vient de soumettre ce document en $format :\n\n";
            return ['success' => true, 'text' => $prefix . $rawText, 'error' => ''];
        }

        if (in_array($ext, ['pdf', 'docx', 'doc', 'rtf'], true)) {
            $nodeResult = extract_via_node($tmpPath, $originalName);
            if (!$nodeResult['success']) {
                // Le detail complet est deja trace dans extract_via_node().
                return $nodeResult;
            }
            if (trim($nodeResult['text']) === '') {
                $error = "Le service Node.js n'a retourné aucun texte pour $originalName (document scanné, protégé, ou vide ?).";
                error_log("EXTRACT_TEXT_FROM_FILE echec - $error");
                return ['success' => false, 'text' => '', 'error' => $error];
            }
            $prefix = "Mathieu vient de soumettre ce document en $format :\n\n";
            return ['success' => true, 'text' => $prefix . trim($nodeResult['text']), 'error' => ''];
        }

        $error = "Type de fichier non supporté : $originalName";
        error_log("EXTRACT_TEXT_FROM_FILE echec - $error");
        return ['success' => false, 'text' => '', 'error' => $error];
    }

    /**
     * Envoie un fichier PDF/DOCX au service Node.js (route /reformuler, purpose=extract)
     * pour extraction du texte.
     *
     * CORRECTIF 04/07/2026 : renvoie desormais un tableau structure au lieu d'une
     * chaine contenant "[Erreur Node.js ...]" en cas d'echec. L'ancien format
     * etait indetectable de maniere fiable par l'appelant (voir extract_text_from_file
     * et le handler extract_only), et le detail de l'erreur curl / HTTP etait perdu
     * sans etre journalise nulle part — d'ou l'absence totale de logs malgre des
     * echecs systematiques sur les PDF/DOCX.
     */
    function extract_via_node(string $tmpPath, string $originalName): array {
        // CORRECTIF 05/07/2026 (v2) : l'ajout des en-tetes Accept/User-Agent n'a
        // pas suffi -- le 406 persiste. Le blocage vient donc du multipart
        // lui-meme (mod_security o2switch bloque probablement les requetes
        // multipart/form-data contenant un fichier binaire vers cette route,
        // avant meme d'atteindre Passenger/Node.js). On contourne entierement le
        // multipart : le fichier est lu, encode en base64 et envoye en JSON,
        // exactement comme les routes reformuler_via_node()/call_reformulator_service()
        // qui, elles, passent sans probleme. Cote server.js, la route /reformuler
        // doit lire req.body.fileData (base64) quand aucun fichier multipart
        // n'est present. Voir CORRECTIF 05/07/2026 (v2) dans server.js.
        $mime = mime_content_type($tmpPath) ?: 'application/octet-stream';

        $fileContent = @file_get_contents($tmpPath);
        if ($fileContent === false) {
            $details = "Impossible de lire le fichier temporaire local ($tmpPath) avant encodage base64.";
            error_log("EXTRACT_VIA_NODE echec pour '$originalName' - $details");
            return ['success' => false, 'text' => '', 'error' => $details];
        }

        $payload = [
            'purpose'  => 'extract',
            'fileName' => $originalName,
            'fileMime' => $mime,
            'fileData' => base64_encode($fileContent),
        ];
        unset($fileContent); // libere la memoire avant l'appel curl

        $ch = curl_init(REFORMULATOR_BASE_URL . '/reformuler');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // un peu plus large : base64 + JSON est ~35% plus volumineux que le fichier brut
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Reformulator-PHP-Client/1.0');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $result = curl_exec($ch);
        $error  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        close_curl_handle($ch);

        if ($error || $httpCode >= 400 || !$result) {
            $details = $error !== '' ? $error : ('HTTP ' . $httpCode . ' - ' . substr((string) $result, 0, 300));
            error_log("EXTRACT_VIA_NODE echec pour '$originalName' - $details");
            return ['success' => false, 'text' => '', 'error' => $details];
        }

        $data = json_decode($result, true);
        if (!is_array($data) || !array_key_exists('cleaned', $data)) {
            $details = 'Réponse JSON invalide (HTTP ' . $httpCode . ') : ' . substr((string) $result, 0, 300);
            error_log("EXTRACT_VIA_NODE reponse invalide pour '$originalName' - $details");
            return ['success' => false, 'text' => '', 'error' => $details];
        }

        return ['success' => true, 'text' => (string) ($data['cleaned'] ?? ''), 'error' => ''];
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

        // === EXTRACTION SEULE DU FICHIER (nouveau bouton) ===
        // CORRECTIF 04/07/2026 : utilise desormais le tableau structure renvoye par
        // extract_text_from_file() au lieu de chercher la sous-chaine "Erreur"
        // dans le texte extrait (source de faux positifs et de details perdus).
        // Chaque echec est aussi trace via error_log().
        if (isset($_GET['extract_only']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            if (!empty($_FILES['uploaded_file']) && $_FILES['uploaded_file']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['uploaded_file']['tmp_name'];
                $fileName = $_FILES['uploaded_file']['name'];
                $extraction = extract_text_from_file($tmpPath, $fileName);

                if ($extraction['success'] && trim($extraction['text']) !== '') {
                    echo json_encode(['success' => true, 'text' => $extraction['text']]);
                } else {
                    $errorDetail = $extraction['error'] !== '' ? $extraction['error'] : 'Impossible d\'extraire le texte du fichier';
                    error_log("EXTRACT_ONLY echec pour '$fileName' - $errorDetail");
                    echo json_encode(['success' => false, 'error' => $errorDetail]);
                }
            } else {
                $uploadErrorCode = $_FILES['uploaded_file']['error'] ?? 'aucun fichier reçu';
                error_log("EXTRACT_ONLY aucun fichier recu ou erreur upload PHP - code=" . $uploadErrorCode);
                echo json_encode(['success' => false, 'error' => 'Aucun fichier reçu']);
            }
            exit;
        }

        $instructions_context = trim($_POST['instructions_context'] ?? '');
        // Moteur IA sélectionné par l'utilisateur (vide = auto/fallback)
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

        // ====================== INTERROGER LE FICHIER ======================
        // CORRECTIF 20/07/2026 (v3) : voir l'entete du fichier pour le detail
        // des deux limites corrigees (contexte generique jamais reconstruit +
        // extraits tronques a une seule phrase). Ce bloc reconstruit desormais
        // TOUJOURS un contexte riche pour ce bouton specifiquement, fusionne
        // trois sources de termes de recherche (locaux + LLM-extraits +
        // LLM-elargis semantiquement), et transmet le contenu INTEGRAL
        // (tronque a 2500 caracteres, pas 180) des sections les plus
        // pertinentes au LLM final.
        if (isset($_POST['query_instructions']) && $input_text !== '') {
            $reformule_original = $input_text;

            // On ignore volontairement le contexte generique deja present dans
            // le champ cache (charge une fois au demarrage de la page) : il
            // n'a aucune raison de contenir la section pertinente pour CETTE
            // question precise.
            $instructions_context = build_instructions_context_for_text($input_text);
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();

            $query_result = '';
            $query_debug = "Moteur : " . ($selected_engine ?: strtoupper($llmInfo['engineName'] ?? 'AUTO')) . "\n\n";

            $sections = extract_instructions_sections();

            // Trois sources de termes fusionnees :
            //   1. localTerms   : mots-cles significatifs extraits de la question elle-meme
            //   2. keywordTerms : mots-cles extraits PAR LE LLM depuis la question
            //   3. expandedTerms: termes ABSENTS de la question mais lies semantiquement
            //                     (synonymes, degres de parente, branches familiales...)
            // Si le service LLM est indisponible, on continue avec les seuls
            // termes locaux (degradation propre, pas de blocage de la fonctionnalite).
            $localTerms = extract_keywords($input_text);
            $rawQueryWords = preg_split('/\s+/u', $input_text, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($rawQueryWords as $word) {
                $cleanWord = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
                if ($cleanWord !== '' && preg_match('/^\p{Lu}/u', $cleanWord) && mb_strlen($cleanWord, 'UTF-8') >= 3) {
                    $localTerms[] = $cleanWord;
                }
            }

            $llmKeywordsRaw = extract_query_keywords_via_node($input_text, $instructions_context);
            $keywordTerms = $llmKeywordsRaw !== '' ? build_query_terms_from_llm($llmKeywordsRaw) : [];

            $llmExpandedRaw = expand_query_terms_via_node($input_text, $instructions_context);
            $expandedTerms = $llmExpandedRaw !== '' ? build_query_terms_from_llm($llmExpandedRaw) : [];

            $mergedTerms = array_values(array_unique(array_merge($localTerms, $keywordTerms, $expandedTerms)));

            $localSearch = search_with_counts_light($input_text, $sections, $mergedTerms);

            // Le contenu INTEGRAL (tronque a 2500 caracteres) des 3 sections
            // les plus pertinentes est transmis, pas une seule phrase isolee
            // -- pour que le LLM puisse repondre meme quand l'info utile est
            // formulee autrement que via les termes de recherche exacts.
            $localEvidence = '';
            if (!empty($localSearch['sections'])) {
                $localEvidence = "Sections pertinentes trouvées (contenu intégral, tronqué si besoin) :\n";
                $sectionIndex = 0;
                foreach ($localSearch['sections'] as $title => $data) {
                    $sectionIndex++;
                    $localEvidence .= "\n--- Section : " . $title . " (" . $data['occurrences'] . " occurrences) ---\n";
                    if ($sectionIndex <= 3 && !empty($data['content'])) {
                        $sectionContent = trim(preg_replace('/\s+/', ' ', $data['content']));
                        if (mb_strlen($sectionContent, 'UTF-8') > 2500) {
                            $sectionContent = mb_substr($sectionContent, 0, 2500, 'UTF-8') . '...';
                        }
                        $localEvidence .= $sectionContent . "\n";
                    } elseif (!empty($data['excerpts'])) {
                        foreach ($data['excerpts'] as $sentence) {
                            $localEvidence .= "  Extrait : " . mb_substr($sentence, 0, 200) . "\n";
                        }
                    }
                }
                $localEvidence .= "\nTotal occurrences détectées : " . $localSearch['total_occ'];
            }

            // Appel à l'IA avec un prompt plus précis et concis
            $finalResponse = finalize_query_response_via_node(
                $input_text,
                $localEvidence,
                $instructions_context
            );

            if ($finalResponse !== '' && !is_negative_query_answer($finalResponse)) {
                $query_result = $finalResponse;
                $reformule_msg = 'Réponse générée par l\'IA (contexte riche + recherche élargie)';
            } else {
                $query_result = "Je n'ai pas trouvé d'information pertinente dans tes mémoires pour cette question.";
                $reformule_msg = 'Aucune information trouvée.';
            }

            $query_debug .= "Termes locaux : " . implode(', ', $localTerms) . "\n";
            $query_debug .= "Termes LLM (extraits) : " . implode(', ', $keywordTerms) . "\n";
            $query_debug .= "Termes LLM (élargis) : " . implode(', ', $expandedTerms) . "\n";
            $query_debug .= "Occurrences détectées : " . ($localSearch['total_occ'] ?? 0) . "\n";
            $query_debug .= "Sections filtrées : " . count($localSearch['sections'] ?? []) . "\n";
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

        if (is_file(SOURCE_FILE)) {
            $source_date = date('d/m/Y H:i', filemtime(SOURCE_FILE));
        }
    }

    $llmInfo = get_llm_info();
    // TEST : Provoque une erreur PHP pour vérifier l’écriture dans error.log
    if (isset($_GET['test_error_log'])) {
        trigger_error('Erreur de test volontaire pour vérifier error.log', E_USER_WARNING);
    }