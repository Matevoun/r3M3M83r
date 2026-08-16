<?php
    /**
     * reformulator/functions.php - Interface de saisie locale pour instructions.md
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
     * REGLES D'OR (immuables - a relire avant toute modification) :
     *   1. Toute modification doit etre documentee clairement dans ce fichier.
     *   2. Ne pas deplacer la logique metier vers reformulator/server.js sans note.
     *   3. Le backend Node.js est gere par reformulator/server.js ; ici on reste interface.
     *   4. Toute nouvelle route ou dependance externe doit etre decrite dans les commentaires.
     *   5. En cas de panne du service, le code doit basculer proprement vers un fallback local.
     *   6. Orthographe archaique OBLIGATOIRE dans le code et les commentaires :
     *      - ecrire CLEF (jamais "cle" ni "clés"), NENUPHAR (jamais "nenufar"),
     *        soeurs avec O et E separes (jamais la ligature oe).
     *      - Pas de tiret cadratin, pas d'emoji en dur, pas de glyphe special.
     *   7. Objectif du bouton Interroger : comprendre l'INTENTION de la question
     *      (combien, ai-je eu, parle-moi de...) et repondre a partir du fichier
     *      instructions.md, pas se contenter de compter les occurrences d'un mot.
     *      La recherche locale (search_with_counts_light + expansion de synonymes)
     *      alimente le LLM ; le LLM doit synthetiser, lister, decompter.
     *   8. La meme exigence de comprehension s'applique a tous les boutons
     *      (Interroger, Proposer emplacement, Reformulation, Extraction).
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

    // CORRECTIF 23/07/2026 (v4) : functions.php vit desormais dans /reformulator/
    // (deplace lors du refactor saisie.php/functions.php). Tous les chemins
    // bases sur __DIR__ qui supposaient etre a la racine du projet doivent
    // etre corriges en consequence -- SOURCE_FILE en particulier, qui
    // pointait vers .../reformulator/instructions.md (inexistant) au lieu de
    // .../instructions.md (racine, un niveau au-dessus). Consequence concrete
    // observee : toutes les fonctions de lecture du fichier renvoyaient du
    // vide, le contexte transmis au LLM etait vide, et le LLM repondait -- a
    // raison, vu ce qu'il recevait -- "Le contexte fourni ne mentionne pas ce
    // sujet" pour absolument toutes les questions.
    if (!defined('SOURCE_FILE')) {
        define('SOURCE_FILE', dirname(__DIR__) . '/instructions.md');
    }

    // Couche de recherche generique dans instructions.md (CORRECTIF 16/08/2026).
    // Tout ce qui concerne la lecture du fichier, son decoupage en blocs, la
    // ponderation des termes et la construction du contexte vit desormais la.
    // Lire la documentation en tete du fichier avant d'y toucher.
    require_once __DIR__ . '/retrieval.php';

    // Calcule l'URL de base du service reformulator.
    // Priorite 1 : connexion directe via 127.0.0.1:PORT (lit le fichier .port ecrit par Node.js
    //   au demarrage). Bypass complet d'Apache/Passenger, plus rapide et fiable.
    // Priorite 2 : URL publique via HTTP_HOST (fallback si .port absent ou invalide).
    // Priorite 3 : URL publique codee en dur (dernier recours).
    // Toute modification de cette logique doit etre consignee dans les regles d'or.
    function get_reformulator_base_url(): string {
        // Priorite 0 (ajout 16/08/2026) : variable d'environnement REFORMULATOR_URL.
        // Elle ne sert pas en production ; elle permet de faire tourner le site
        // en local (php -S) contre un "node server.js" ecoutant sur un autre
        // port, par exemple :
        //   REFORMULATOR_URL=http://127.0.0.1:3000 php -S 127.0.0.1:8080
        $override = getenv('REFORMULATOR_URL');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }

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
        // CORRECTIF 23/07/2026 (v4) : server.js est desormais un fichier
        // FRERE de functions.php (tous deux dans /reformulator/), plus un
        // sous-dossier imbrique -- l'ancien chemin pointait vers
        // .../reformulator/reformulator/server.js (inexistant).
        $filePath = __DIR__ . '/server.js';
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

    // CORRECTIF 23/07/2026 (v4) : idem SOURCE_FILE et server.js -- log/ est un
    // sous-dossier direct du dossier ou vit desormais functions.php, pas d'un
    // sous-dossier "reformulator" imbrique.
    function get_requests_log_path(): string {
        return __DIR__ . '/log/requests.log';
    }

    function get_error_log_path(): string {
        return __DIR__ . '/log/error.log';
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
        @ini_set('error_log', __DIR__ . '/log/error.log');
        @ini_set('log_errors', '1');
        ensure_reformulator_log_file(get_error_log_path());
    }

    // -----------------------------------------------------------------------------
    // BLOC 2 : Appel du service Node.js pour reformulation LLM ou recherche de fallback
    // -----------------------------------------------------------------------------
    // Reformulation avancee (bouton "Reformulation avancee avec IA").
    // Envoie le texte au backend Node.js (purpose implicite = rewrite / SAISIE_PROMPT).
    // Le prompt cote server.js doit :
    //   - comprendre la nature de l'anecdote (pas un simple correcteur ortho)
    //   - transposer je/moi -> Mathieu (3e personne)
    //   - synthetiser intelligemment (ex. 15 lignes -> 4-7) sans inventer
    //   - rendre un texte pret a coller dans instructions.md
    // Voir SAISIE_PROMPT dans reformulator/server.js (CORRECTIF 08/08/2026).
    function reformuler_via_node(string $text, string $instructionsContext = ''): string {
        global $selected_engine;
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
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
        // CORRECTIF 16/08/2026 : 30 secondes ne suffisaient pas des que le
        // contexte depassait quelques dizaines de milliers de caracteres. PHP
        // coupait la ligne pendant que le moteur redigeait, et l'interface
        // annoncait une panne de service alors que la reponse arrivait.
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
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

    /**
     * CORRECTIF 05/08/2026 : elargit l'intention de la question (ancetres,
     * amis d'enfance, periodes, notions liees) avant la selection de sections.
     * Un appel LLM leger ; en cas d'echec on renvoie une chaine vide.
     */
    function expand_query_intent_via_node(string $question): string {
        $question = trim($question);
        if ($question === '') {
            return '';
        }
        $payload = [
            'text'    => "Question : " . $question,
            'purpose' => 'query-expand',
        ];
        $raw = call_reformulator_service($payload);
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * CORRECTIF 03/08/2026 : apres extraction d'un fichier importe, compare
     * le texte extrait avec le plan / extraits d'instructions.md et propose
     * une fusion (chevauchements, nouveautes, sections cibles).
     */
    function merge_check_via_node(string $importedText, string $instructionsContext = ''): string {
        $text = trim($importedText);
        if ($text === '') {
            return '';
        }
        // Limiter la taille envoyee pour rester sous les quotas des tiers gratuits
        if (mb_strlen($text, 'UTF-8') > 12000) {
            $text = mb_substr($text, 0, 12000, 'UTF-8') . "\n...[texte tronque pour analyse de fusion]...";
        }
        $payload = [
            'text'    => $text,
            'purpose' => 'merge-check',
        ];
        if ($instructionsContext !== '') {
            $payload['instructionsContext'] = $instructionsContext;
        } else {
            $outline = extract_instructions_outline();
            $excerpt = load_instructions_excerpt();
            $payload['instructionsContext'] = "Plan des sections : " . implode(' ; ', $outline) . "\n\nExtraits :\n" . $excerpt;
        }
        return call_reformulator_service($payload);
    }

    /**
     * CORRECTIF 08/08/2026 : fusion intelligente (bouton Comparer / Fusionner).
     * Envoie le texte NOUVEAU + un contexte memoire deja construit comme Interroger
     * (sections + preuves). Le LLM (purpose=merge-smart) produit :
     * deja / nouveau / contradictions / texte fusionne / emplacement.
     */
    function merge_smart_via_node(string $newText, string $memoryContext = ''): string {
        global $selected_engine;
        $text = trim($newText);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') > 14000) {
            $text = mb_substr($text, 0, 14000, 'UTF-8') . "\n...[texte tronque pour fusion]...";
        }
        $payload = [
            'text'    => $text,
            'purpose' => 'merge-smart',
        ];
        if ($memoryContext !== '') {
            $payload['instructionsContext'] = $memoryContext;
        }
        if (!empty($selected_engine)) {
            $payload['engine'] = $selected_engine;
        }
        return call_reformulator_service($payload);
    }

    /**
     * Decoupe la reponse merge-smart en blocs A_COLLER / EMPLACEMENT / DETAILS
     * (balises <<<...>>> imposees par MERGE_SMART_PROMPT). Fallback si absentes.
     */
    function parse_merge_smart_result(string $raw): array {
        $raw = trim($raw);
        $out = ['a_coller' => '', 'emplacement' => '', 'details' => '', 'raw' => $raw];
        if ($raw === '') {
            return $out;
        }
        if (preg_match('/<<<A_COLLER\s*(.*?)\s*>>>A_COLLER/is', $raw, $m)) {
            $out['a_coller'] = trim($m[1]);
        }
        if (preg_match('/<<<EMPLACEMENT\s*(.*?)\s*>>>EMPLACEMENT/is', $raw, $m)) {
            $out['emplacement'] = trim($m[1]);
        }
        if (preg_match('/<<<DETAILS\s*(.*?)\s*>>>DETAILS/is', $raw, $m)) {
            $out['details'] = trim($m[1]);
        }
        if ($out['a_coller'] === '') {
            if (preg_match('/###\s*Texte fusionn[eé].*?\n(.*?)(?=###\s*Emplacement|\z)/is', $raw, $m)) {
                $out['a_coller'] = trim($m[1]);
            } else {
                // Aucune balise A_COLLER : on retombe sur la reponse entiere,
                // debarrassee du bloc HUMAIN qui, lui, est affiche a part.
                $out['a_coller'] = trim(preg_replace('/<<<HUMAIN\s*.*?\s*>>>HUMAIN/is', '', $raw));
            }
        }
        if ($out['emplacement'] === '' && preg_match('/###\s*Emplacement.*?\n(.*?)(?=###|\z)/is', $raw, $m)) {
            $out['emplacement'] = trim($m[1]);
        }
        return $out;
    }

    // =========================================================================
    // PIPELINE COMPRENDRE -> CHERCHER -> REPONDRE (CORRECTIF 16/08/2026)
    // =========================================================================
    // Probleme resolu : le bouton Interroger repondait "je n'ai pas trouve"
    // alors que le sujet etait ecrit noir sur blanc dans instructions.md, dans
    // des termes quasi identiques. Trois causes cumulees :
    //   1. le fichier n'etait envoye en entier qu'en dessous de 60 000
    //      caracteres, or il en fait plus de 560 000 : ce chemin etait mort ;
    //   2. au-dela, seules 3 a 6 sections de premier niveau etaient retenues,
    //      puis tronquees : un passage situe au milieu d'un gros chapitre etait
    //      simplement absent de ce qui partait au moteur ;
    //   3. les synonymes produits par le LLM servaient a choisir les sections
    //      mais PAS a chercher les preuves, filtrees sur les mots litteraux de
    //      la question. "chiens" ne pouvait donc jamais trouver "Luna",
    //      "animal" ou "faune".
    //
    // Nouveau parcours, identique pour les quatre boutons de texte :
    //   1. COMPRENDRE : un appel LLM court (purpose=understand) renvoie du JSON
    //      structure : intention, attendu, mots clefs ponderes, synonymes,
    //      entites, periodes. Aucune redaction a ce stade.
    //   2. CHERCHER : reformulator/retrieval.php decoupe instructions.md en
    //      blocs fins, cherche AVEC les synonymes, score, diversifie par
    //      chapitre et rend un contexte cite (chemin + ligne).
    //   3. REPONDRE : l'appel LLM final ne recoit que des extraits reels.
    //
    // Aucune regle metier en dur : pas de liste de synonymes codee, pas de
    // section privilegiee. Si le contenu du fichier change, rien a modifier.
    // =========================================================================

    /**
     * Extrait le premier objet JSON d'une reponse LLM.
     * Les moteurs entourent volontiers leur JSON de texte ou de balises de bloc
     * de code, malgre la consigne : on tolere, on ne s'en remet pas au hasard.
     */
    function decode_json_object_from_llm(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Retire une eventuelle cloture de bloc de code (```json ... ```).
        $raw = preg_replace('/^```[a-zA-Z]*\s*/', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Deuxieme chance : la portion entre la premiere accolade ouvrante et
        // la derniere fermante.
        $start = mb_strpos($raw, '{', 0, 'UTF-8');
        $end   = mb_strrpos($raw, '}', 0, 'UTF-8');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $candidate = mb_substr($raw, $start, $end - $start + 1, 'UTF-8');
        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Etape 1 : COMPRENDRE la demande.
     *
     * $bouton indique ce que Mathieu attend (interroger, fusionner, emplacement,
     * reformuler, document) ; le prompt "understand" s'en sert pour orienter
     * l'elargissement. En cas d'echec du LLM (service arrete, quota, JSON
     * casse), on retombe sur une comprehension locale minimale construite a
     * partir des mots de la demande : la recherche fonctionne toujours, en
     * moins large.
     *
     * Retour :
     *   ['comprehension' => array, 'source' => 'llm'|'local', 'erreur' => string,
     *    'brut' => string]
     */
    function understand_request_via_node(string $text, string $bouton): array {
        $text = trim($text);
        $out = ['comprehension' => [], 'source' => 'local', 'erreur' => '', 'brut' => ''];
        if ($text === '') {
            return $out;
        }

        // Le texte soumis peut etre long (document importe) : la comprehension
        // n'a pas besoin de tout, le debut et la structure suffisent.
        $sample = mb_strlen($text, 'UTF-8') > 6000
            ? mb_substr($text, 0, 6000, 'UTF-8') . "\n...[suite non transmise a l'etape de comprehension]..."
            : $text;

        $raw = call_reformulator_service([
            'text'    => "Bouton clique : " . $bouton . "\n\nTexte saisi par Mathieu :\n" . $sample,
            'purpose' => 'understand',
        ]);
        $out['brut'] = is_string($raw) ? $raw : '';

        $decoded = is_string($raw) ? decode_json_object_from_llm($raw) : null;
        if (is_array($decoded)) {
            $out['comprehension'] = normalize_comprehension($decoded);
            $out['source'] = 'llm';
            return $out;
        }

        global $last_reformulator_error;
        $out['erreur'] = $last_reformulator_error !== ''
            ? $last_reformulator_error
            : 'reponse de comprehension illisible (JSON attendu)';
        $out['comprehension'] = normalize_comprehension([]);
        return $out;
    }

    /**
     * Met la comprehension recue en forme sure : clefs presentes, types
     * attendus, pas de valeur aberrante. Le reste du code peut ensuite lire
     * $comprehension['synonymes'] sans precautions.
     */
    function normalize_comprehension(array $data): array {
        $stringList = function ($value): array {
            if (is_string($value)) {
                $value = preg_split('/[,;]/u', $value) ?: [];
            }
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $item = $item['terme'] ?? ($item['mot'] ?? '');
                }
                $item = trim((string) $item);
                if ($item !== '' && mb_strlen($item, 'UTF-8') <= 60) {
                    $out[] = $item;
                }
            }
            return array_values(array_unique($out));
        };

        $keywords = [];
        $rawKeywords = $data['mots_clefs'] ?? ($data['mots_cles'] ?? []);
        // Certains moteurs renvoient "chien, animal" au lieu d'une liste :
        // on accepte les deux formes plutot que de perdre les mots clefs.
        if (is_string($rawKeywords)) {
            $rawKeywords = preg_split('/[,;]/u', $rawKeywords) ?: [];
        }
        if (is_array($rawKeywords)) {
            foreach ($rawKeywords as $entry) {
                if (is_array($entry)) {
                    $term = trim((string) ($entry['terme'] ?? ($entry['mot'] ?? '')));
                    $weight = (float) ($entry['poids'] ?? 2);
                } else {
                    $term = trim((string) $entry);
                    $weight = 2.0;
                }
                if ($term === '' || mb_strlen($term, 'UTF-8') > 60) {
                    continue;
                }
                $keywords[] = ['terme' => $term, 'poids' => max(1.0, min(4.0, $weight))];
            }
        }

        return [
            'intention'     => trim((string) ($data['intention'] ?? '')),
            'type_texte'    => trim((string) ($data['type_texte'] ?? '')),
            'attendu'       => trim((string) ($data['attendu'] ?? '')),
            'sujets'        => $stringList($data['sujets'] ?? []),
            'mots_clefs'    => $keywords,
            'synonymes'     => $stringList($data['synonymes'] ?? []),
            'entites'       => $stringList($data['entites'] ?? []),
            'periodes'      => $stringList($data['periodes'] ?? []),
            'reformulation' => trim((string) ($data['reformulation'] ?? '')),
        ];
    }

    /**
     * Rend une erreur de service lisible dans le bloc de debogage : les pages
     * d'erreur HTML et les traces multilignes sont reduites a une seule ligne
     * courte, sinon elles noient les informations utiles (termes cherches,
     * blocs retenus).
     */
    function debug_short_error(string $message, int $max = 300): string {
        $plat = trim(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? '');
        if ($plat === '') {
            return '(erreur sans message)';
        }
        return mb_strlen($plat, 'UTF-8') > $max
            ? mb_substr($plat, 0, $max, 'UTF-8') . ' [...]'
            : $plat;
    }

    /**
     * Resume lisible de la comprehension, place en tete du contexte envoye au
     * moteur final : il sait ainsi ce qui a ete cherche, et pourquoi ces
     * extraits lui arrivent.
     */
    function format_comprehension_for_prompt(array $c): string {
        if (empty($c)) {
            return '';
        }
        $lines = [];
        if ($c['intention'] !== '')     { $lines[] = 'Intention comprise : ' . $c['intention']; }
        if ($c['attendu'] !== '')       { $lines[] = 'Attendu : ' . $c['attendu']; }
        if (!empty($c['sujets']))       { $lines[] = 'Sujets : ' . implode(', ', $c['sujets']); }
        if (!empty($c['entites']))      { $lines[] = 'Entites citees ou probables : ' . implode(', ', $c['entites']); }
        if (!empty($c['periodes']))     { $lines[] = 'Periodes : ' . implode(', ', $c['periodes']); }
        if (empty($lines)) {
            return '';
        }
        return "Comprehension de la demande :\n" . implode("\n", $lines);
    }

    /**
     * Trace lisible de la comprehension pour le bloc de debogage.
     */
    function format_comprehension_for_debug(array $understood): string {
        $c = $understood['comprehension'] ?? [];
        $lines = [];
        $lines[] = 'Comprehension : ' . ($understood['source'] === 'llm'
            ? 'par le moteur (purpose=understand)'
            : 'LOCALE de secours (le moteur n\'a pas repondu en JSON)');
        if (!empty($understood['erreur'])) {
            $lines[] = 'Avertissement comprehension : ' . debug_short_error($understood['erreur']);
        }
        if (!empty($c['intention']))  { $lines[] = 'Intention : ' . $c['intention']; }
        if (!empty($c['attendu']))    { $lines[] = 'Attendu : ' . $c['attendu']; }
        if (!empty($c['type_texte'])) { $lines[] = 'Type de texte : ' . $c['type_texte']; }
        if (!empty($c['sujets']))     { $lines[] = 'Sujets : ' . implode(', ', $c['sujets']); }
        if (!empty($c['mots_clefs'])) {
            $parts = [];
            foreach ($c['mots_clefs'] as $entry) {
                $parts[] = $entry['terme'] . ' (' . rtrim(rtrim(number_format($entry['poids'], 1, ',', ''), '0'), ',') . ')';
            }
            $lines[] = 'Mots clefs proposes : ' . implode(', ', $parts);
        }
        if (!empty($c['synonymes'])) { $lines[] = 'Synonymes et notions reliees : ' . implode(', ', $c['synonymes']); }
        if (!empty($c['entites']))   { $lines[] = 'Entites : ' . implode(', ', $c['entites']); }
        if (!empty($c['periodes']))  { $lines[] = 'Periodes : ' . implode(', ', $c['periodes']); }
        return implode("\n", $lines);
    }

    /**
     * Etapes 1 et 2 reunies : comprendre la demande puis chercher dans
     * instructions.md. C'est LE point d'entree commun aux quatre boutons de
     * texte ; toute amelioration de la recherche profite donc a tous.
     *
     * Options transmises a mem_retrieve() : 'budget', 'min_blocs', 'max_blocs'.
     * L'option 'avec_plan' ajoute le plan complet du fichier au contexte
     * (utile pour Proposer emplacement, qui doit nommer des sections exactes).
     *
     * Retour :
     *   ['contexte'      => string,  contexte pret a envoyer au moteur
     *    'comprehension' => array,   comprehension normalisee
     *    'recherche'     => array,   retour brut de mem_retrieve()
     *    'debug'         => string,  trace complete pour le panneau de debogage
     *    'resume'        => string]  une ligne de resume pour l'interface
     */
    function prepare_memory_context(string $text, string $bouton, array $options = []): array {
        $text = trim($text);
        if ($text === '') {
            return ['contexte' => '', 'comprehension' => [], 'recherche' => [], 'debug' => '', 'resume' => 'aucun texte soumis'];
        }

        $understood = understand_request_via_node($text, $bouton);
        $comprehension = $understood['comprehension'];

        $retrieval = mem_retrieve($text, $comprehension, $options);

        $parts = [];
        $resume = format_comprehension_for_prompt($comprehension);
        if ($resume !== '') {
            $parts[] = $resume;
        }
        if (!empty($options['avec_plan'])) {
            $plan = mem_outline_text();
            if ($plan !== '') {
                $parts[] = "Plan complet d'instructions.md (chapitres et sous-sections, orthographe exacte) :\n" . $plan;
            }
        }
        if ($retrieval['contexte'] !== '') {
            $parts[] = "Extraits retrouves dans instructions.md :\n\n" . $retrieval['contexte'];
        } else {
            $parts[] = "Aucun extrait n'a ete retrouve pour cette demande. Ne conclus pas pour autant "
                . "que le sujet est absent du fichier : dis que la recherche automatique n'a rien ramene "
                . "et propose d'autres formulations.";
        }

        $stats = $retrieval['stats'];
        $debug = "Bouton : " . $bouton . "\n"
            . format_comprehension_for_debug($understood) . "\n"
            . mem_debug_report($retrieval);

        $resumeLigne = $stats['blocs_retenus'] . ' extrait(s) sur ' . $stats['blocs_trouves']
            . ' trouve(s), ' . number_format((float) $stats['contexte_car'], 0, ',', ' ')
            . ' caracteres transmis, source ' . $stats['source'];

        return [
            'contexte'      => implode("\n\n", $parts),
            'comprehension' => $comprehension,
            'recherche'     => $retrieval,
            'debug'         => $debug,
            'resume'        => $resumeLigne,
        ];
    }

    /**
     * Etape 3 pour le bouton Interroger : le moteur ne voit que des extraits
     * reels, cites avec leur emplacement.
     */
    function answer_query_via_node(string $question, string $memoryContext): string {
        return call_reformulator_service([
            'text'                => $question,
            'purpose'             => 'query',
            'instructionsContext' => $memoryContext,
        ]);
    }

    /**
     * Bouton Reformulation avancee : reponse en deux blocs (texte pret a coller
     * et explication humaine). Le decoupage tolere l'absence de balises, un
     * moteur pouvant les oublier.
     */
    function parse_rewrite_result(string $raw): array {
        $raw = trim($raw);
        $out = ['texte' => '', 'humain' => '', 'raw' => $raw];
        if ($raw === '') {
            return $out;
        }
        if (preg_match('/<<<TEXTE\s*(.*?)\s*>>>TEXTE/is', $raw, $m)) {
            $out['texte'] = trim($m[1]);
        }
        if (preg_match('/<<<HUMAIN\s*(.*?)\s*>>>HUMAIN/is', $raw, $m)) {
            $out['humain'] = trim($m[1]);
        }
        if ($out['texte'] === '') {
            // Aucune balise : tout le texte est considere comme la reformulation.
            $out['texte'] = $raw;
        }
        return $out;
    }

    /**
     * Bouton Comparer / Fusionner : le bloc humain s'ajoute aux blocs deja
     * geres par parse_merge_smart_result().
     */
    function parse_merge_human_block(string $raw): string {
        if (preg_match('/<<<HUMAIN\s*(.*?)\s*>>>HUMAIN/is', $raw, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * Bouton Charger & Extraire : transforme le texte brut extrait d'un
     * document en une restitution lisible, prete a etre relue puis soumise a un
     * autre bouton. Si le moteur ne repond pas, on rend le texte brut : mieux
     * vaut une extraction technique qu'un champ vide.
     */
    function summarize_document_via_node(string $rawText, string $fileName, string $fileType): string {
        $text = trim($rawText);
        if ($text === '') {
            return '';
        }
        $limit = 30000;
        $tronque = false;
        if (mb_strlen($text, 'UTF-8') > $limit) {
            $text = mb_substr($text, 0, $limit, 'UTF-8');
            $tronque = true;
        }
        $entete = "Nom du fichier : " . $fileName . "\n"
            . "Type de fichier : " . $fileType . "\n"
            . ($tronque ? "Attention : seuls les " . $limit . " premiers caracteres du document sont transmis.\n" : '')
            . "\n";

        $result = call_reformulator_service([
            'text'    => $entete . $text,
            'purpose' => 'extract-summary',
        ]);
        return is_string($result) ? trim($result) : '';
    }

    /**
     * Construit un contexte memoire cible sur un sujet (meme logique qu'Interroger :
     * intention, selection de sections, preuves rankees). Utilise par Interroger
     * et par Comparer/Fusionner pour ne pas dupliquer la logique.
     * Retourne ['context' => string, 'debug' => string].
     */
    function build_memory_context_for_topic(string $topicText): array {
        $topicText = trim($topicText);
        $debug = '';
        if ($topicText === '') {
            return ['context' => '', 'debug' => ''];
        }

        $intentExpanded = expand_query_intent_via_node($topicText);
        $sections = extract_instructions_sections();
        $fullDocument = load_instructions_content();
        $fullDocumentLength = mb_strlen($fullDocument, 'UTF-8');
        $fullDocumentSizeLimit = 60000;

        if ($fullDocumentLength > 0 && $fullDocumentLength <= $fullDocumentSizeLimit) {
            $ctx = '';
            if ($intentExpanded !== '') {
                $ctx .= "Intention elargie (boussole de recherche) :\n" . $intentExpanded . "\n\n";
            }
            $ctx .= "Voici le contenu INTEGRAL du fichier d'instructions.md :\n\n" . $fullDocument;
            $debug = 'fichier integral (' . number_format($fullDocumentLength, 0, ',', ' ') . ' caracteres)';
            return ['context' => $ctx, 'debug' => $debug];
        }

        $outline = extract_instructions_outline();
        $selectQuestion = $topicText;
        if ($intentExpanded !== '') {
            $selectQuestion = $topicText . "\n\nIntention elargie :\n" . $intentExpanded;
        }
        $selectedTitles = select_relevant_sections_via_node($selectQuestion, $outline);
        $relevantSections = [];
        if (!empty($selectedTitles)) {
            foreach ($selectedTitles as $title) {
                if (isset($sections[$title])) {
                    $relevantSections[$title] = $sections[$title];
                }
            }
        }
        if (empty($relevantSections)) {
            $localSearch = search_with_counts_light($topicText, $sections);
            foreach (($localSearch['sections'] ?? []) as $title => $info) {
                if (isset($sections[$title])) {
                    $relevantSections[$title] = $sections[$title];
                }
            }
        }
        if (empty($relevantSections)) {
            $relevantSections = array_slice($sections, 0, 3, true);
        }

        $maxSections = 6;
        $localSearchPad = search_with_counts_light($topicText . ' ' . $intentExpanded, $sections);
        foreach (($localSearchPad['sections'] ?? []) as $title => $info) {
            if (count($relevantSections) >= $maxSections) {
                break;
            }
            if (isset($sections[$title]) && !isset($relevantSections[$title])) {
                $relevantSections[$title] = $sections[$title];
            }
        }

        $perSectionLimit = 12000;
        $relevantSections = array_slice($relevantSections, 0, $maxSections, true);

        $primaryTerms = extract_keywords($topicText);
        foreach (preg_split('/\s+/u', $topicText, -1, PREG_SPLIT_NO_EMPTY) as $w) {
            $w = normalize_for_matching(preg_replace('/[^\p{L}\p{N}]/u', '', $w));
            if ($w !== '' && mb_strlen($w, 'UTF-8') >= 3) {
                $primaryTerms[] = $w;
            }
        }
        $genericNoise = ['tous','toutes','tout','toute','mes','mon','ma','les','des','une','listes','liste','parle','moi','donc','aussi','comme','avec','dans','pour','plus','tres','bien'];
        $primaryTerms = array_values(array_filter(array_unique($primaryTerms), function ($t) use ($genericNoise) {
            return !in_array($t, $genericNoise, true);
        }));
        $queryTerms = $primaryTerms;

        $ctx = "Le fichier d'instructions contient les sections suivantes : " . implode(' ; ', $outline) . ".\n\n";
        if ($intentExpanded !== '') {
            $ctx .= "Intention elargie (boussole de recherche) :\n" . $intentExpanded . "\n\n";
        }

        $rankedLines = collect_ranked_evidence_lines($sections, $queryTerms, 60, $primaryTerms);
        if (!empty($rankedLines)) {
            $ctx .= "PREUVES DIRECTES du fichier (citations prioritaires) :\n";
            foreach ($rankedLines as $item) {
                $ctx .= '- [' . $item['title'] . '] ' . $item['line'] . "\n";
            }
            $ctx .= "\n";
        }

        $sectionBoost = [];
        foreach ($rankedLines as $item) {
            $t = $item['title'];
            $sectionBoost[$t] = ($sectionBoost[$t] ?? 0) + (int) ($item['score'] ?? 1);
        }
        $orderedTitles = array_keys($relevantSections);
        usort($orderedTitles, function ($a, $b) use ($sectionBoost) {
            $sa = $sectionBoost[$a] ?? 0;
            $sb = $sectionBoost[$b] ?? 0;
            if ($sb !== $sa) {
                return $sb <=> $sa;
            }
            return 0;
        });
        $ctx .= "Contenu des sections (contexte elargi) :\n";
        foreach ($orderedTitles as $title) {
            $content = $relevantSections[$title];
            $block = trim($content);
            if (mb_strlen($block, 'UTF-8') > $perSectionLimit) {
                $block = build_section_excerpt_for_query($content, $queryTerms, $perSectionLimit);
            }
            $ctx .= "\n--- Section : $title ---\n" . $block . "\n";
        }

        $debug = count($relevantSections) . ' section(s)'
            . ($intentExpanded !== '' ? ' + intention elargie' : '')
            . ' (fichier : ' . number_format($fullDocumentLength, 0, ',', ' ') . ' car.)';
        if (!empty($rankedLines)) {
            $debug .= "\nPreuves retenues (" . count($rankedLines) . ") :\n";
            foreach (array_slice($rankedLines, 0, 8) as $item) {
                $debug .= '  [' . $item['title'] . '] ' . mb_substr($item['line'], 0, 100, 'UTF-8') . "\n";
            }
        }
        return ['context' => $ctx, 'debug' => $debug];
    }

    /**
     * CORRECTIF 05/08/2026 : extrait centre sur les OCCURRENCES des termes
     * dans la section (pas seulement le debut du texte). Les grosses sections
     * (Famille, Chronologie) mettaient les passages utiles au milieu / en fin ;
     * un simple tronquage debut faisait croire au LLM que "VILLIERS" n'existait pas.
     * Aucune personnalisation metier : uniquement les termes fournis (question
     * + intention elargie LLM).
     */
    function build_section_excerpt_for_query(string $content, array $terms, int $maxChars = 10000): string {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        if (mb_strlen($content, 'UTF-8') <= $maxChars) {
            return $content;
        }

        $terms = array_values(array_filter(array_map(function ($t) {
            return normalize_for_matching((string) $t);
        }, $terms), function ($t) {
            return $t !== '' && mb_strlen($t, 'UTF-8') >= 3;
        }));

        // 1) Decoupage fin : paragraphes, sinon lignes, sinon phrases
        $chunks = preg_split('/\n\s*\n/', $content);
        if (count($chunks) < 3) {
            $chunks = preg_split('/\n+/', $content);
        }
        if (count($chunks) < 3) {
            $chunks = preg_split('/(?<=[.!?])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        }

        $scored = [];
        foreach ($chunks as $idx => $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $normalized = normalize_for_matching($chunk);
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count($normalized, $term) * 3;
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'text' => $chunk, 'idx' => $idx];
            }
        }

        // 2) Si aucun chunk ne matche, fenetres glissantes autour des positions
        if (empty($scored) && !empty($terms)) {
            $normFull = normalize_for_matching($content);
            $window = 900;
            $positions = [];
            foreach ($terms as $term) {
                $offset = 0;
                while (($pos = strpos($normFull, $term, $offset)) !== false) {
                    $positions[] = $pos;
                    $offset = $pos + mb_strlen($term, 'UTF-8');
                    if (count($positions) > 40) {
                        break 2;
                    }
                }
            }
            $positions = array_values(array_unique($positions));
            sort($positions);
            $lenFull = mb_strlen($content, 'UTF-8');
            foreach ($positions as $p) {
                // Approximation : indices sur texte normalise ~ proches du brut
                $start = max(0, (int) ($p * 0.95) - (int) ($window / 2));
                $piece = mb_substr($content, $start, $window, 'UTF-8');
                if (trim($piece) !== '') {
                    $scored[] = ['score' => 5, 'text' => trim($piece), 'idx' => $start];
                }
            }
        }

        if (empty($scored)) {
            // Dernier recours : debut + milieu + fin
            $len = mb_strlen($content, 'UTF-8');
            $third = (int) ($maxChars / 3);
            return mb_substr($content, 0, $third, 'UTF-8')
                . "\n\n[...]\n\n"
                . mb_substr($content, (int) ($len / 2) - (int) ($third / 2), $third, 'UTF-8')
                . "\n\n[...]\n\n"
                . mb_substr($content, max(0, $len - $third), $third, 'UTF-8');
        }

        usort($scored, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $a['idx'] <=> $b['idx'];
        });

        $selected = [];
        $totalLen = 0;
        $seen = [];
        foreach ($scored as $item) {
            $key = md5($item['text']);
            if (isset($seen[$key])) {
                continue;
            }
            $len = mb_strlen($item['text'], 'UTF-8');
            if ($totalLen + $len > $maxChars && !empty($selected)) {
                break;
            }
            $seen[$key] = true;
            $selected[] = $item;
            $totalLen += $len + 2;
        }

        usort($selected, function ($a, $b) {
            return $a['idx'] <=> $b['idx'];
        });

        $excerpt = implode("\n\n", array_map(function ($item) {
            return $item['text'];
        }, $selected));

        if (mb_strlen($excerpt, 'UTF-8') > $maxChars) {
            $excerpt = mb_substr($excerpt, 0, $maxChars, 'UTF-8') . '...';
        }
        return $excerpt;
    }

    /**
     * CORRECTIF 03/08/2026 : selection semantique des sections par le LLM
     * (a partir des titres uniquement). Remplace le simple recouvrement de
     * mots-cles local qui echouait sur les questions conceptuelles
     * (ex. "cousins cote paternel").
     */
    function select_relevant_sections_via_node(string $question, array $outline): array {
        if (empty($outline)) {
            return [];
        }
        $titlesList = implode("\n", $outline);
        $payloadText = "Question : " . $question . "\n\nTitres de sections disponibles :\n" . $titlesList;
        $payload = [
            'text'    => $payloadText,
            'purpose' => 'query-select',
        ];
        $raw = call_reformulator_service($payload);
        $selected = [];

        if ($raw !== '' && strtoupper(trim($raw)) !== 'AUCUNE') {
            // Garder uniquement les titres qui existent dans l'outline
            $candidates = array_map('trim', preg_split('/[,;\n]+/', $raw));
            foreach ($candidates as $cand) {
                $cand = trim($cand, " \t\"'");
                if ($cand === '') {
                    continue;
                }
                foreach ($outline as $title) {
                    $titleNorm = mb_strtolower($title, 'UTF-8');
                    $candNorm  = mb_strtolower($cand, 'UTF-8');
                    if ($titleNorm === $candNorm
                        || mb_strpos($titleNorm, $candNorm) !== false
                        || mb_strpos($candNorm, $titleNorm) !== false
                        || similar_text($titleNorm, $candNorm) > (mb_strlen($titleNorm) * 0.7)) {
                        $selected[] = $title;
                        break;
                    }
                }
            }
        }
        return array_values(array_unique($selected));
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

    // CORRECTIF 05/08/2026 : plus d'expansion de synonymes / entites en dur.
    // Variantes morphologiques legeres UNIQUEMENT (pluriel / feminin courant) :
    // "cousins" doit aussi matcher "cousin" et "cousine" dans le texte.
    // Ce n'est PAS une liste d'entites personnelles (pas de Luna, 2CV, etc.).
    function extract_keywords(string $text): array {
        $normalized = normalize_for_matching($text);
        $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = ['et','la','le','les','des','du','de','un','une','pour','avec','dans','sur','au','aux','par','plus','se','qui','que','est','pas','ou','son','sa','ses','a','il','elle','ils','elles','ne','ce','cette','ces','etre','avoir','sous','entre','sans','donc','mais','comme','car','afin','lors','tres','deja','parle','jai','tu','te','toi','moi','combien','avais','avait','ai','eu','ete','moi','mes','mon','ma','tout','cote','listes','liste'];
        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') < 3) continue;
            if (in_array($word, $stopwords, true)) continue;
            $keywords[$word] = ($keywords[$word] ?? 0) + 1;
            // Racine sans s final (cousins -> cousin, enfants -> enfant)
            if (mb_strlen($word, 'UTF-8') >= 5 && mb_substr($word, -1, 1, 'UTF-8') === 's') {
                $stem = mb_substr($word, 0, -1, 'UTF-8');
                if (!in_array($stem, $stopwords, true)) {
                    $keywords[$stem] = ($keywords[$stem] ?? 0) + 1;
                }
            }
            // Feminin courant en e : si le mot finit par in/ain (cousin), ajouter form e
            // Inversement : cousine -> cousin
            if (mb_strlen($word, 'UTF-8') >= 5 && mb_substr($word, -1, 1, 'UTF-8') === 'e') {
                $stem = mb_substr($word, 0, -1, 'UTF-8');
                if (mb_strlen($stem, 'UTF-8') >= 4 && !in_array($stem, $stopwords, true)) {
                    $keywords[$stem] = ($keywords[$stem] ?? 0) + 1;
                }
            }
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
                $sentences = extract_section_match_sentences($content, $matchedTerms, 16);

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

    /**
     * CORRECTIF 05/08/2026 : lignes matchees, scorees, puis diversifiees PAR SECTION
     * (quota) pour qu'une grosse section (Famille/Domaine) n'ecrase pas la Chronologie.
     * Generique : aucun filtre metier type "cousins" en dur.
     */
    function collect_ranked_evidence_lines(array $sections, array $terms, int $maxLines = 48, array $primaryTerms = []): array {
        $normalizeList = function (array $list): array {
            return array_values(array_filter(array_map(function ($t) {
                return normalize_for_matching((string) $t);
            }, $list), function ($t) {
                return $t !== '' && mb_strlen($t, 'UTF-8') >= 3;
            }));
        };
        $terms = $normalizeList($terms);
        $primaryTerms = $normalizeList($primaryTerms);
        if (empty($terms) || empty($sections)) {
            return [];
        }
        $primarySet = array_fill_keys($primaryTerms, true);

        $bySection = [];
        foreach ($sections as $title => $content) {
            $lines = preg_split('/\R/u', (string) $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || mb_strlen($line, 'UTF-8') < 20) {
                    continue;
                }
                $norm = normalize_for_matching($line);
                $hitTerms = 0;
                $hitPrimary = 0;
                $score = 0;
                foreach ($terms as $term) {
                    if (strpos($norm, $term) !== false) {
                        $hitTerms++;
                        $weight = isset($primarySet[$term]) ? 5 : 2;
                        $score += $weight + min(3, substr_count($norm, $term));
                        if (isset($primarySet[$term])) {
                            $hitPrimary++;
                        }
                    }
                }
                if ($hitTerms === 0) {
                    continue;
                }
                // Sans terme de la question d'origine : ignorer la ligne
                // (empeche l'intention elargie d'inonder les preuves).
                if (!empty($primarySet) && $hitPrimary === 0) {
                    continue;
                }
                if ($hitTerms >= 2) {
                    $score += 8 * $hitTerms;
                }
                if ($hitPrimary >= 1) {
                    $score += 12 * $hitPrimary;
                }
                if (preg_match('/\b(cousin|cousine|neveu|niece|tante|oncle|fils|fille|pere|mere|epoux|epouse|heritier|naissance)\b/u', $norm)) {
                    $score += 6;
                }
                if (preg_match('/\b[\p{Lu}][\p{L}]{2,}/u', $line)) {
                    $score += 2;
                }
                $bySection[$title][] = [
                    'score' => $score,
                    'title' => $title,
                    'line'  => $line,
                ];
            }
        }

        // Quota genereux par section pour ne pas perdre les listes nominatives
        // (ex. 5 lignes Chronologie "Futur cousin paternel" + lignes Domaine).
        $perSectionCap = max(15, (int) ceil($maxLines / max(1, min(5, count($bySection)))));
        $pool = [];
        foreach ($bySection as $title => $items) {
            usort($items, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            $pool = array_merge($pool, array_slice($items, 0, $perSectionCap));
        }

        usort($pool, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $out = [];
        $seen = [];
        foreach ($pool as $item) {
            $key = md5($item['line']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $maxLines) {
                break;
            }
        }
        return $out;
    }

    function extract_section_match_sentences(string $content, array $terms, int $maxSentences = 12): array {
        // Preferer les lignes markdown (puces Chronologie) aux phrases coupees
        // au point : "Naissance d'Anne PAULY. Future cousine..." doit rester
        // recuperable en entier ou en deux morceaux adjacents.
        $lines = preg_split('/\R/u', $content);
        $candidates = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line, 'UTF-8') < 12) {
                continue;
            }
            $candidates[] = $line;
        }
        // Completer avec decoupage phrase si peu de lignes utiles
        if (count($candidates) < 5) {
            $flat = preg_replace('/\s+/', ' ', $content);
            foreach (preg_split('/(?<=[.!?])\s+/', $flat, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
                $candidates[] = trim($sentence);
            }
        }
        $matches = [];
        foreach ($candidates as $sentence) {
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
            $rawNode = trim($nodeResult['text']);
            if ($rawNode === '') {
                $error = "Le service Node.js n'a retourne aucun texte pour $originalName (document scanne, protege, ou vide ?).";
                error_log("EXTRACT_TEXT_FROM_FILE echec - $error");
                return ['success' => false, 'text' => '', 'error' => $error];
            }
            // CORRECTIF 03/08/2026 : PDF scanne / structure invalide / OCR inutile
            // -> message clair (ne plus pretendre que le fichier est exploitable).
            if (strpos($rawNode, 'NON_EXPLOITABLE:') === 0
                || stripos($rawNode, 'texte non extractible') !== false
                || stripos($rawNode, 'Invalid PDF structure') !== false
                || preg_match('/^\[Erreur extraction\b/i', $rawNode)) {
                $error = "Fichier non exploitable : PDF scanne, protege ou structure invalide (OCR insuffisant). "
                    . "Fournis un PDF texte, un DOCX, ou un document deja OCR-ise. ($originalName)";
                error_log("EXTRACT_TEXT_FROM_FILE non exploitable - $originalName - " . mb_substr($rawNode, 0, 120));
                return ['success' => false, 'text' => '', 'error' => $error];
            }
            $prefix = "Mathieu vient de soumettre ce document en $format :\n\n";
            return ['success' => true, 'text' => $prefix . $rawNode, 'error' => ''];
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
    $merge_result              = '';  // CORRECTIF 08/08/2026 : resultat Comparer/Fusionner
    // CORRECTIF 16/08/2026 : chaque bouton rend desormais, en plus de son
    // resultat brut, une explication adressee a Mathieu (bloc <<<HUMAIN).
    $reformule_humain          = '';
    $merge_humain              = '';
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
                    // CORRECTIF 16/08/2026 : le champ de saisie recevait le texte
                    // brut de l'extracteur (scories d'OCR, en-tetes de page,
                    // coupures), inutilisable tel quel. Le moteur en fait
                    // desormais une restitution lisible qui commence par
                    // "Mathieu vient de soumettre un fichier ...". Si le moteur
                    // ne repond pas, on rend le texte brut : mieux vaut une
                    // extraction technique qu'un champ vide.
                    $extension = strtoupper(pathinfo($fileName, PATHINFO_EXTENSION));
                    $lisible = '';
                    try {
                        $lisible = summarize_document_via_node($extraction['text'], $fileName, $extension !== '' ? $extension : 'inconnu');
                    } catch (Throwable $e) {
                        error_log('EXTRACT_SUMMARY echec pour ' . $fileName . ' : ' . $e->getMessage());
                    }

                    // Comparaison avec la memoire, sur le texte le plus propre
                    // disponible (n'echoue pas l'extraction si le moteur est HS).
                    $mergeSuggestion = '';
                    try {
                        $prep = prepare_memory_context($lisible !== '' ? $lisible : $extraction['text'], 'Charger & Extraire fichier', ['budget' => 30000]);
                        $mergeSuggestion = merge_check_via_node($extraction['text'], $prep['contexte']);
                    } catch (Throwable $e) {
                        error_log('MERGE_CHECK echec apres extract : ' . $e->getMessage());
                    }
                    echo json_encode([
                        'success' => true,
                        'text' => $lisible !== '' ? $lisible : $extraction['text'],
                        'text_brut' => $extraction['text'],
                        'resume_genere' => $lisible !== '',
                        'merge_suggestion' => $mergeSuggestion,
                    ], JSON_UNESCAPED_UNICODE);
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

        // Le contexte memoire est construit par chaque bouton via
        // prepare_memory_context() (comprehension + recherche fine). Le champ
        // cache instructions_context du navigateur n'est donc plus lu ici.
        // Moteur IA selectionne par l'utilisateur (vide = auto/fallback)
        $selected_engine = trim($_POST['selected_engine'] ?? '');
        $instructions_context = '';

        // =================================================================
        // LES QUATRE BOUTONS DE TEXTE (refonte 16/08/2026)
        // =================================================================
        // Tous suivent le meme parcours : prepare_memory_context() comprend la
        // demande puis cherche dans instructions.md, et le moteur final ne
        // recoit que des extraits reels. Seuls le "purpose" et le budget de
        // contexte changent d'un bouton a l'autre. Voir le bloc de
        // documentation du pipeline plus haut dans ce fichier.
        //
        // Le champ cache instructions_context envoye par le navigateur n'est
        // plus utilise pour la recherche : il ne contenait qu'un extrait
        // generique des premiers caracteres du fichier, parfois vieux de
        // plusieurs minutes, et c'est lui qui partait au moteur a la place du
        // vrai contenu.
        // =================================================================

        // Bouton "Reformulation avancee avec IA"
        if (isset($_POST['reformuler']) && $input_text !== '') {
            $reformule_original = $input_text;
            // Budget modere : la reformulation a besoin du ton et des graphies
            // deja employes sur le sujet, pas de tout le dossier.
            $prep = prepare_memory_context($input_text, 'Reformulation avancee avec IA', [
                'budget' => 20000,
            ]);
            $instructions_context = $prep['contexte'];
            $query_debug = $prep['debug'];
            log_reformulator_request($input_text);

            $cleaned = reformuler_via_node($input_text, $instructions_context);
            if ($cleaned !== '') {
                $parsed = parse_rewrite_result($cleaned);
                $reformule_interpretation = $parsed['texte'];
                $reformule_humain = $parsed['humain'];
                $reformule_msg = 'Reformulation proposee (' . $prep['resume'] . ').';
            } else {
                $reformule_msg = 'Erreur : le reformulator n\'a pas répondu. Vérifiez que Node.js tourne.';
                if ($last_reformulator_error !== '') {
                    $query_debug .= "\nErreur moteur : " . debug_short_error($last_reformulator_error);
                }
            }
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }

        // Bouton "Proposer emplacement"
        if (isset($_POST['proposer_emplacement']) && $input_text !== '') {
            $reformule_original = $input_text;
            // avec_plan : le moteur doit nommer des sections qui existent
            // vraiment, donc il recoit le plan complet en plus des extraits.
            $prep = prepare_memory_context($input_text, 'Proposer emplacement', [
                'budget'    => 30000,
                'avec_plan' => true,
            ]);
            $instructions_context = $prep['contexte'];
            $query_debug = $prep['debug'];

            $proposed_location = propose_emplacement_via_node($input_text, $instructions_context);
            if ($proposed_location !== '') {
                $reformule_msg = 'Emplacement propose (' . $prep['resume'] . ').';
            } else {
                $reformule_msg = 'Erreur : le service n\'a pas pu proposer un emplacement.';
                if ($last_reformulator_error !== '') {
                    $query_debug .= "\nErreur moteur : " . debug_short_error($last_reformulator_error);
                }
            }
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }

        // Bouton "Interroger le fichier"
        // Le budget est le plus large des quatre : repondre "ce sujet est
        // absent" exige d'avoir regarde le plus loin possible.
        if (isset($_POST['query_instructions']) && $input_text !== '') {
            $reformule_original = $input_text;
            $prep = prepare_memory_context($input_text, 'Interroger le fichier', [
                'budget'    => 48000,
                'min_blocs' => 6,
            ]);
            $instructions_context = $prep['contexte'];
            $query_debug = $prep['debug'];

            $finalResponse = answer_query_via_node($input_text, $instructions_context);

            if ($finalResponse !== '') {
                // On n'ecrase plus une reponse negative du moteur par un
                // message maison : s'il explique ce qu'il n'a pas trouve, cette
                // explication vaut mieux qu'une phrase toute faite, et le bloc
                // de debogage dit exactement ce qui a ete cherche.
                $query_result = $finalResponse;
                $reformule_msg = 'Reponse du moteur (' . $prep['resume'] . ').';
            } else {
                $query_result = "Le moteur n'a pas repondu. Le detail de la recherche est dans le bloc de debogage ci-dessous.";
                $reformule_msg = 'Erreur : aucune reponse du moteur.';
                if ($last_reformulator_error !== '') {
                    $query_debug .= "\nErreur moteur : " . debug_short_error($last_reformulator_error);
                }
            }
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
        }

        // Bouton "Comparer / Fusionner avec la memoire"
        if (isset($_POST['merge_smart']) && $input_text !== '') {
            $reformule_original = $input_text;
            $prep = prepare_memory_context($input_text, 'Comparer / Fusionner', [
                'budget'    => 40000,
                'min_blocs' => 6,
            ]);
            $instructions_context = $prep['contexte'];
            $query_debug = $prep['debug'];

            $merged = merge_smart_via_node($input_text, $instructions_context);
            if ($merged !== '') {
                $merge_result = $merged;
                $merge_humain = parse_merge_human_block($merged);
                $reformule_msg = 'Fusion proposee (' . $prep['resume'] . ').';
            } else {
                $merge_result = '';
                $reformule_msg = 'Erreur : la fusion n\'a pas abouti. Verifiez que Node.js tourne.';
                if ($last_reformulator_error !== '') {
                    $query_debug .= "\nErreur moteur : " . debug_short_error($last_reformulator_error);
                }
            }
            $instructions_loaded = true;
            $instructions_line_count = count_instructions_lines();
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