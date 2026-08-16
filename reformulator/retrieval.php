<?php
/**
 * reformulator/retrieval.php - Moteur de recherche generique dans instructions.md
 *
 * POURQUOI CE FICHIER (CORRECTIF 16/08/2026, Mathieu CHARREYRE)
 * -------------------------------------------------------------
 * Constat : les moteurs repondaient "ce sujet n'est pas dans le fichier"
 * alors que le sujet y etait, parfois presque dans les memes termes.
 * Trois causes cumulees, toutes cotees PHP (pas cotees LLM) :
 *
 *   1. Le fichier entier n'etait transmis au LLM que sous 60 000 caracteres.
 *      instructions.md pese aujourd'hui plus de 580 000 caracteres : ce
 *      chemin n'etait donc JAMAIS emprunte, et personne ne s'en rendait
 *      compte puisque le code de secours, lui, repondait quand meme.
 *   2. Le secours ne retenait que 6 sections de niveau ## sur 13, alors que
 *      certaines sections pesent a elles seules plus de 50 000 caracteres :
 *      le passage utile etait tronque au milieu.
 *   3. Surtout : les "preuves" transmises au LLM etaient filtrees sur les
 *      mots LITTERALEMENT presents dans la question. L'expansion de
 *      synonymes produite par le LLM ne servait qu'a choisir des sections,
 *      jamais a chercher. Une question sur "les chiens" ne pouvait donc
 *      structurellement pas trouver "Luna", "animal" ou "faune".
 *
 * CE QUE FAIT CE MODULE
 * ---------------------
 *   - Charge instructions.md (local, puis miroir GitHub brut en secours).
 *   - Le decoupe en blocs fins (## > ### > paragraphes) avec fil d'Ariane
 *     et numero de ligne, et met ce decoupage en cache.
 *   - Cherche dans ces blocs avec un score de type BM25, sur des termes
 *     PONDERES : termes de la demande (poids fort), entites nommees (poids
 *     fort), synonymes et notions reliees fournis par la couche de
 *     comprehension (poids plus faible mais REELLEMENT pris en compte).
 *   - Construit un contexte diversifie par section, sous un budget de
 *     caracteres, pret a etre envoye au moteur LLM.
 *
 * IL N'Y A AUCUNE REGLE METIER EN DUR ICI : pas de liste de synonymes
 * personnels, pas de nom d'animal, pas de section privilegiee. Tout ce qui
 * est specifique a une demande vient de la couche de comprehension (LLM) ou
 * du texte lui-meme. Ce module reste donc valable si instructions.md double
 * de taille ou change completement de contenu.
 *
 * REGLES D'OR RESPECTEES (voir functions.php) : orthographe archaique
 * (CLEF, NENUPHAR), pas de ligature oe, pas de tiret cadratin, pas d'emoji,
 * commentaires sans accents pour eviter tout probleme d'encodage.
 *
 * POINTS D'ENTREE PRINCIPAUX (tout le reste est interne) :
 *   mem_source_info()                 informations sur la source chargee
 *   mem_outline()                     plan du fichier (## et ###) avec tailles
 *   mem_chunks()                      blocs indexes (avec cache)
 *   mem_build_query_terms()           termes ponderes a partir d'une comprehension
 *   mem_retrieve()                    recherche + contexte + trace de debogage
 */

// ---------------------------------------------------------------------------
// CONFIGURATION (surchargeable : definir la constante AVANT l'include)
// ---------------------------------------------------------------------------

// Fichier memoire local. functions.php definit deja SOURCE_FILE ; on ne le
// redefinit ici que si ce module est utilise seul (tests en ligne de commande).
if (!defined('SOURCE_FILE')) {
    define('SOURCE_FILE', dirname(__DIR__) . '/instructions.md');
}

// Miroir public utilise UNIQUEMENT si la lecture locale echoue ou renvoie du
// vide (droits, deploiement partiel, chemin casse apres un refactor...).
if (!defined('MEM_REMOTE_URL')) {
    define('MEM_REMOTE_URL', 'https://raw.githubusercontent.com/Matevoun/r3M3M83r/refs/heads/main/instructions.md');
}

// Dossier de cache (decoupage en blocs + copie du miroir distant).
if (!defined('MEM_CACHE_DIR')) {
    define('MEM_CACHE_DIR', __DIR__ . '/cache');
}

// Duree de validite de la copie du miroir distant, en secondes.
if (!defined('MEM_REMOTE_TTL')) {
    define('MEM_REMOTE_TTL', 3600);
}

// Taille visee d'un bloc, en caracteres. Assez grand pour garder une anecdote
// entiere, assez petit pour ne pas noyer le LLM sous une section de 50 000.
if (!defined('MEM_CHUNK_TARGET')) {
    define('MEM_CHUNK_TARGET', 1400);
}

// Taille maximale d'un bloc avant decoupe forcee (paragraphe fleuve).
if (!defined('MEM_CHUNK_MAX')) {
    define('MEM_CHUNK_MAX', 2600);
}

// Budget de contexte envoye au LLM, en caracteres (~4 caracteres par token).
// 48 000 caracteres = environ 12 000 tokens : tient chez tous les moteurs
// configures, y compris les modeles gratuits a fenetre reduite.
if (!defined('MEM_CONTEXT_BUDGET')) {
    define('MEM_CONTEXT_BUDGET', 48000);
}

// ---------------------------------------------------------------------------
// SOURCE : lecture locale, secours distant, cache
// ---------------------------------------------------------------------------

/**
 * Cree le dossier de cache si besoin. Retourne false si impossible (le module
 * fonctionne alors sans cache, simplement plus lentement).
 */
function mem_ensure_cache_dir(): bool {
    if (is_dir(MEM_CACHE_DIR)) {
        return is_writable(MEM_CACHE_DIR);
    }
    if (!@mkdir(MEM_CACHE_DIR, 0755, true) && !is_dir(MEM_CACHE_DIR)) {
        return false;
    }
    return is_writable(MEM_CACHE_DIR);
}

/**
 * Telecharge le miroir distant d'instructions.md, avec cache disque.
 * Retourne '' en cas d'echec (jamais d'exception : la memoire locale reste
 * prioritaire, ce chemin n'est qu'un filet de securite).
 */
function mem_fetch_remote_source(): string {
    $cacheFile = MEM_CACHE_DIR . '/instructions_remote.md';
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < MEM_REMOTE_TTL) {
        $cached = @file_get_contents($cacheFile);
        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }
    }

    $content = '';
    if (function_exists('curl_init')) {
        $ch = curl_init(MEM_REMOTE_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Reformulator-Retrieval/1.0');
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        if (function_exists('curl_close')) {
            call_user_func('curl_close', $ch);
        }
        if (is_string($raw) && $httpCode < 400 && trim($raw) !== '') {
            $content = $raw;
        } else {
            error_log('MEM_FETCH_REMOTE echec - HTTP ' . $httpCode . ' - ' . $curlError);
        }
    }

    if ($content === '' && ini_get('allow_url_fopen')) {
        $raw = @file_get_contents(MEM_REMOTE_URL);
        if (is_string($raw) && trim($raw) !== '') {
            $content = $raw;
        }
    }

    if ($content !== '' && mem_ensure_cache_dir()) {
        @file_put_contents($cacheFile, $content, LOCK_EX);
    }
    return $content;
}

/**
 * Charge le contenu du fichier memoire et decrit d'ou il vient.
 * Le resultat est memorise le temps de la requete PHP (static) : plusieurs
 * boutons ou plusieurs passes de recherche ne relisent pas 580 Ko a chaque fois.
 *
 * Retour : ['content','origin','length','mtime','error']
 *   origin = 'local' | 'distant' | 'aucune'
 */
function mem_source(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $info = [
        'content' => '',
        'origin'  => 'aucune',
        'length'  => 0,
        'mtime'   => 0,
        'error'   => '',
    ];

    if (is_file(SOURCE_FILE) && is_readable(SOURCE_FILE)) {
        $content = @file_get_contents(SOURCE_FILE);
        if (is_string($content) && trim($content) !== '') {
            $info['content'] = $content;
            $info['origin']  = 'local';
            $info['mtime']   = (int) @filemtime(SOURCE_FILE);
        } else {
            $info['error'] = 'Lecture locale vide ou impossible (' . SOURCE_FILE . ').';
        }
    } else {
        $info['error'] = 'Fichier local introuvable ou illisible (' . SOURCE_FILE . ').';
    }

    if ($info['content'] === '') {
        $remote = mem_fetch_remote_source();
        if ($remote !== '') {
            $info['content'] = $remote;
            $info['origin']  = 'distant';
            $info['mtime']   = time();
            $info['error']   = trim($info['error'] . ' Bascule sur le miroir GitHub.');
            error_log('MEM_SOURCE bascule sur le miroir distant - ' . $info['error']);
        }
    }

    $info['length'] = mb_strlen($info['content'], 'UTF-8');
    $cache = $info;
    return $cache;
}

/**
 * Informations sur la source, sans le contenu (pour le bloc de debogage).
 */
function mem_source_info(): array {
    $source = mem_source();
    unset($source['content']);
    return $source;
}

// ---------------------------------------------------------------------------
// NORMALISATION ET VARIANTES MORPHOLOGIQUES
// ---------------------------------------------------------------------------

/**
 * Normalisation utilisee pour TOUTES les comparaisons : sans accents, en
 * minuscules, ponctuation reduite a des espaces. Les espaces de bordure sont
 * conserves par mem_norm_padded() pour permettre la recherche de mot entier.
 */
function mem_normalize(string $text): string {
    if (function_exists('remove_accents')) {
        $text = remove_accents($text);
    } else {
        $text = mem_remove_accents_fallback($text);
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

/**
 * Repli si functions.php n'est pas charge (utilisation autonome du module).
 */
function mem_remove_accents_fallback(string $text): string {
    $trans = [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','ß'=>'ss',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','œ'=>'oe','Œ'=>'OE',
    ];
    return strtr($text, $trans);
}

/**
 * Texte normalise entoure d'espaces : permet de tester la presence d'un mot
 * entier avec un simple strpos(' terme ') sans expression reguliere couteuse
 * (on parcourt plusieurs milliers de blocs a chaque recherche).
 */
function mem_norm_padded(string $text): string {
    return ' ' . mem_normalize($text) . ' ';
}

/**
 * Mots vides francais : ils ne portent aucun signal de recherche.
 */
function mem_stopwords(): array {
    static $stopwords = null;
    if ($stopwords === null) {
        $stopwords = array_fill_keys([
            'le','la','les','un','une','des','du','de','au','aux','ce','cet','cette','ces','mon','ma','mes',
            'ton','ta','tes','son','sa','ses','notre','nos','votre','vos','leur','leurs','et','ou','mais','donc',
            'or','ni','car','que','qui','quoi','dont','ouest','pour','par','avec','sans','sous','sur','dans',
            'chez','entre','vers','apres','avant','depuis','pendant','est','sont','etait','etaient','ete','etre',
            'avoir','ai','as','avons','avez','ont','avais','avait','avions','aviez','avaient','fait','faire',
            'je','tu','il','elle','on','nous','vous','ils','elles','me','te','se','lui','moi','toi','soi','y',
            'pas','plus','moins','tres','bien','tout','tous','toute','toutes','meme','aussi','comme','alors',
            'quand','si','ne','non','oui','deja','encore','sais','savoir','connais','parle','parler','parlais',
            'dit','dire','peux','peut','pouvoir','veux','veut','vouloir','faut','falloir','crois','pense',
            'quelque','quelques','autre','autres','chose','choses','truc','trucs','svp','stp','merci','salut',
        ], true);
    }
    return $stopwords;
}

/**
 * Variantes morphologiques legeres d'un terme, en francais : singulier,
 * pluriel, feminin, formes verbales courantes. Purement mecanique, aucune
 * connaissance metier. Sert a ce que "chiens" trouve "chien" et "chienne",
 * "voitures" trouve "voiture", "adopte" trouve "adoption" reste hors sujet
 * (c'est le role des synonymes fournis par la couche de comprehension).
 */
function mem_morph_variants(string $term): array {
    $term = mem_normalize($term);
    if ($term === '') {
        return [];
    }
    // Expression a plusieurs mots : on la garde telle quelle, les variantes
    // n'ont pas de sens sur un groupe nominal complet.
    if (strpos($term, ' ') !== false) {
        return [$term];
    }

    $variants = [$term];
    $len = mb_strlen($term, 'UTF-8');

    if ($len >= 5 && mb_substr($term, -1, 1, 'UTF-8') === 'x') {
        // chevaux -> cheval, bateaux -> bateau
        $variants[] = mb_substr($term, 0, -3, 'UTF-8') . 'al';
        $variants[] = mb_substr($term, 0, -1, 'UTF-8');
    }
    if ($len >= 4 && mb_substr($term, -1, 1, 'UTF-8') === 's') {
        $variants[] = mb_substr($term, 0, -1, 'UTF-8');
    } elseif ($len >= 3) {
        $variants[] = $term . 's';
    }
    if ($len >= 5 && mb_substr($term, -1, 1, 'UTF-8') === 'e') {
        // chienne -> chien (via chienn -> on ne garde que la troncature simple)
        $variants[] = mb_substr($term, 0, -1, 'UTF-8');
    } else {
        $variants[] = $term . 'e';
    }
    if ($len >= 4 && mb_substr($term, -2, 2, 'UTF-8') === 'er') {
        // adopter -> adopt, sert a rapprocher les formes conjuguees
        $variants[] = mb_substr($term, 0, -2, 'UTF-8');
    }

    $variants = array_values(array_unique(array_filter($variants, function ($variant) {
        return mb_strlen($variant, 'UTF-8') >= 3;
    })));
    return $variants;
}

// ---------------------------------------------------------------------------
// DECOUPAGE DU FICHIER EN BLOCS INDEXES
// ---------------------------------------------------------------------------

/**
 * Plan du fichier : titres ## et ### avec leur taille et leur ligne.
 * Utilise pour la selection de sections et pour le bouton Proposer emplacement
 * (qui a besoin de connaitre les sous-sections, pas seulement les 13 chapitres).
 *
 * Retour : liste de ['niveau' => 2|3, 'titre' => string, 'chemin' => string,
 *                    'ligne' => int, 'taille' => int]
 */
function mem_outline(): array {
    static $outline = null;
    if ($outline !== null) {
        return $outline;
    }

    $source = mem_source();
    $lines = preg_split('/\R/u', $source['content']);
    $entries = [];
    $current = null;
    $currentH2 = '';

    foreach ($lines as $index => $line) {
        if (preg_match('/^(##|###)\s+(.+?)\s*$/', $line, $matches)) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $level = strlen($matches[1]);
            $title = trim($matches[2]);
            if ($level === 2) {
                $currentH2 = $title;
            }
            $current = [
                'niveau' => $level,
                'titre'  => $title,
                'chemin' => $level === 2 ? $title : ($currentH2 !== '' ? $currentH2 . ' > ' . $title : $title),
                'ligne'  => $index + 1,
                'taille' => 0,
            ];
            continue;
        }
        if ($current !== null) {
            $current['taille'] += mb_strlen($line, 'UTF-8') + 1;
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }

    $outline = $entries;
    return $outline;
}

/**
 * Liste des titres ## uniquement (compatibilite avec l'existant).
 */
function mem_outline_h2(): array {
    $titles = [];
    foreach (mem_outline() as $entry) {
        if ($entry['niveau'] === 2) {
            $titles[] = $entry['titre'];
        }
    }
    return $titles;
}

/**
 * Plan lisible destine aux prompts : chapitres et sous-sections avec taille.
 * Tronque si le plan devient enorme, pour ne pas manger le budget de contexte.
 */
function mem_outline_text(int $maxChars = 6000): string {
    $lines = [];
    foreach (mem_outline() as $entry) {
        $prefix = $entry['niveau'] === 2 ? '## ' : '   ### ';
        $lines[] = $prefix . $entry['titre'] . ' (' . $entry['taille'] . ' car., ligne ' . $entry['ligne'] . ')';
    }
    $text = implode("\n", $lines);
    if (mb_strlen($text, 'UTF-8') > $maxChars) {
        $text = mb_substr($text, 0, $maxChars, 'UTF-8') . "\n[...plan tronque...]";
    }
    return $text;
}

/**
 * Decoupe le fichier en blocs de taille raisonnable.
 *
 * Strategie : on suit la hierarchie ## puis ###, et a l'interieur d'une
 * sous-section on regroupe les paragraphes jusqu'a MEM_CHUNK_TARGET
 * caracteres. Un paragraphe seul plus long que MEM_CHUNK_MAX est coupe en
 * morceaux. Chaque bloc porte son fil d'Ariane (chapitre > sous-section) et
 * sa ligne de depart : le LLM peut donc citer un emplacement precis, et
 * Mathieu retrouve le passage dans son fichier.
 *
 * Retour : liste de blocs
 *   ['id','h2','h3','chemin','ligne','texte','norm','taille']
 */
function mem_build_chunks(string $content): array {
    $lines = preg_split('/\R/u', $content);
    $chunks = [];
    $h2 = '';
    $h3 = '';
    $buffer = [];
    $bufferLen = 0;
    $bufferStartLine = 1;

    $flush = function () use (&$chunks, &$buffer, &$bufferLen, &$bufferStartLine, &$h2, &$h3) {
        if ($bufferLen === 0) {
            $buffer = [];
            return;
        }
        $texte = trim(implode("\n", $buffer));
        $buffer = [];
        $bufferLen = 0;
        if ($texte === '') {
            return;
        }
        $chemin = trim($h2 . ($h3 !== '' ? ' > ' . $h3 : ''), ' >');
        $chunks[] = [
            'id'     => count($chunks),
            'h2'     => $h2,
            'h3'     => $h3,
            'chemin' => $chemin !== '' ? $chemin : 'Preambule',
            'ligne'  => $bufferStartLine,
            'texte'  => $texte,
            'norm'   => mem_norm_padded($texte),
            'taille' => mb_strlen($texte, 'UTF-8'),
        ];
    };

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        if (preg_match('/^##\s+(.+?)\s*$/', $line, $matches)) {
            $flush();
            $h2 = trim($matches[1]);
            $h3 = '';
            $bufferStartLine = $lineNumber;
            continue;
        }
        if (preg_match('/^###\s+(.+?)\s*$/', $line, $matches)) {
            $flush();
            $h3 = trim($matches[1]);
            $bufferStartLine = $lineNumber;
            continue;
        }
        if (preg_match('/^#\s+(.+?)\s*$/', $line, $matches)) {
            $flush();
            $h2 = trim($matches[1]);
            $h3 = '';
            $bufferStartLine = $lineNumber;
            continue;
        }

        $trimmed = trim($line);
        if ($trimmed === '') {
            // Fin de paragraphe : on ferme le bloc s'il a atteint la taille visee.
            if ($bufferLen >= MEM_CHUNK_TARGET) {
                $flush();
                $bufferStartLine = $lineNumber + 1;
            } elseif ($bufferLen > 0) {
                $buffer[] = '';
            }
            continue;
        }

        if ($bufferLen === 0) {
            $bufferStartLine = $lineNumber;
        }
        $buffer[] = $line;
        $bufferLen += mb_strlen($line, 'UTF-8') + 1;

        // Ligne fleuve ou accumulation trop grosse : on coupe sans attendre la
        // ligne vide (certaines sections sont des listes continues de 400 lignes).
        if ($bufferLen >= MEM_CHUNK_MAX) {
            $flush();
            $bufferStartLine = $lineNumber + 1;
        }
    }
    $flush();

    return $chunks;
}

/**
 * Blocs indexes, avec cache disque invalide par la taille et la date du
 * fichier source. Le decoupage de 580 Ko prend une fraction de seconde, mais
 * il est fait plusieurs fois par requete (jusqu'a deux passes par bouton) :
 * le cache evite de le refaire.
 */
function mem_chunks(): array {
    static $chunks = null;
    if ($chunks !== null) {
        return $chunks;
    }

    $source = mem_source();
    if ($source['content'] === '') {
        $chunks = [];
        return $chunks;
    }

    $signature = md5($source['origin'] . '|' . $source['length'] . '|' . $source['mtime'] . '|' . MEM_CHUNK_TARGET . '|' . MEM_CHUNK_MAX);
    $cacheFile = MEM_CACHE_DIR . '/chunks_' . $signature . '.ser';

    if (is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if (is_string($raw) && $raw !== '') {
            $decoded = @unserialize($raw);
            if (is_array($decoded) && !empty($decoded)) {
                $chunks = $decoded;
                return $chunks;
            }
        }
    }

    $chunks = mem_build_chunks($source['content']);

    if (mem_ensure_cache_dir()) {
        @file_put_contents($cacheFile, serialize($chunks), LOCK_EX);
        // Menage : on ne garde que les caches du decoupage courant.
        foreach ((array) @glob(MEM_CACHE_DIR . '/chunks_*.ser') as $old) {
            if ($old !== $cacheFile && (time() - (int) @filemtime($old)) > 86400) {
                @unlink($old);
            }
        }
    }
    return $chunks;
}

// ---------------------------------------------------------------------------
// CONSTRUCTION DES TERMES DE RECHERCHE
// ---------------------------------------------------------------------------

/**
 * Termes extraits d'un texte brut (demande de Mathieu), sans LLM.
 * Sert de socle et de secours si la couche de comprehension echoue.
 */
function mem_terms_from_text(string $text): array {
    $normalized = mem_normalize($text);
    if ($normalized === '') {
        return [];
    }
    $stopwords = mem_stopwords();
    $terms = [];
    foreach (preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (mb_strlen($word, 'UTF-8') < 3) {
            continue;
        }
        if (isset($stopwords[$word])) {
            continue;
        }
        $terms[] = $word;
    }
    // Noms propres de la demande : ils gardent un poids fort meme courts.
    if (preg_match_all('/\b\p{Lu}[\p{L}\'-]{2,}/u', $text, $matches)) {
        foreach ($matches[0] as $name) {
            $normName = mem_normalize($name);
            if ($normName !== '' && !isset($stopwords[$normName])) {
                $terms[] = $normName;
            }
        }
    }
    return array_values(array_unique($terms));
}

/**
 * Construit la table de termes PONDERES utilisee par la recherche.
 *
 * C'EST LE POINT CENTRAL DU CORRECTIF : les synonymes et notions reliees
 * fournis par la couche de comprehension entrent ici dans la recherche avec
 * un poids reel. Avant, ils ne servaient qu'a choisir des sections et etaient
 * ensuite jetes, ce qui rendait impossible de trouver "faune" en demandant
 * "les chiens".
 *
 * $comprehension attend (toutes les clefs sont optionnelles) :
 *   'mots_clefs' => ['chien', ...] ou [['terme' => 'chien', 'poids' => 3], ...]
 *   'synonymes'  => ['animal', 'faune', ...]
 *   'entites'    => ['Luna', 'Saint-Antonin', ...]
 *
 * Retour : ['terme' => ['poids' => float, 'origine' => string, 'variantes' => []]]
 */
function mem_build_query_terms(string $rawText, array $comprehension = []): array {
    $stopwords = mem_stopwords();
    $terms = [];

    $add = function (string $term, float $weight, string $origin) use (&$terms, $stopwords) {
        $term = mem_normalize($term);
        if ($term === '' || mb_strlen($term, 'UTF-8') < 3) {
            return;
        }
        if (isset($stopwords[$term])) {
            return;
        }
        if (!isset($terms[$term]) || $terms[$term]['poids'] < $weight) {
            $terms[$term] = [
                'poids'     => $weight,
                'origine'   => isset($terms[$term]) ? $terms[$term]['origine'] . '+' . $origin : $origin,
                'variantes' => mem_morph_variants($term),
            ];
        }
    };

    // 1. Termes de la demande elle-meme : poids fort, ce sont eux qui portent
    //    l'intention litterale.
    foreach (mem_terms_from_text($rawText) as $term) {
        $add($term, 3.0, 'demande');
    }

    // 2. Mots-clefs designes par la couche de comprehension (ponderes ou non).
    foreach (($comprehension['mots_clefs'] ?? []) as $entry) {
        if (is_array($entry)) {
            $term = (string) ($entry['terme'] ?? $entry['mot'] ?? '');
            $weight = (float) ($entry['poids'] ?? 2.5);
        } else {
            $term = (string) $entry;
            $weight = 2.5;
        }
        $add($term, max(1.0, min(4.0, $weight)), 'comprehension');
    }

    // 3. Entites nommees (personnes, lieux, animaux, vehicules...) : poids le
    //    plus fort, ce sont les ancres les plus discriminantes du fichier.
    foreach (($comprehension['entites'] ?? []) as $entity) {
        if (is_array($entity)) {
            foreach ($entity as $value) {
                $add((string) $value, 4.0, 'entite');
            }
            continue;
        }
        $add((string) $entity, 4.0, 'entite');
    }

    // 4. Synonymes et notions reliees : poids plus faible, mais bien utilises.
    foreach (($comprehension['synonymes'] ?? []) as $synonym) {
        $add((string) $synonym, 1.2, 'synonyme');
    }

    return $terms;
}

// ---------------------------------------------------------------------------
// RECHERCHE
// ---------------------------------------------------------------------------

/**
 * Compte les occurrences d'un terme (mot entier, puis sous-chaine) dans un
 * texte deja normalise et entoure d'espaces.
 *
 * On accepte la sous-chaine avec un poids reduit : "cousine" doit etre trouve
 * dans "petite-cousine", et "montjol" dans "montjolissime". Le mot entier
 * reste toujours mieux score.
 */
function mem_count_term(string $paddedNorm, string $term): array {
    $whole = substr_count($paddedNorm, ' ' . $term . ' ');
    $partial = substr_count($paddedNorm, $term) - $whole;
    if ($partial < 0) {
        $partial = 0;
    }
    return ['entier' => $whole, 'partiel' => $partial];
}

/**
 * Recherche BM25 simplifiee sur les blocs.
 *
 * Options :
 *   'min_score'        score minimal pour retenir un bloc (defaut 0.0001)
 *   'exiger_primaire'  true = un bloc doit contenir au moins un terme de poids
 *                      >= 2.5 (1re passe) ; false = les synonymes suffisent
 *                      (2e passe, plus large)
 *   'max_blocs'        nombre maximal de blocs retournes
 *
 * Retour : liste triee de ['bloc' => chunk, 'score' => float,
 *          'termes' => ['terme' => occurrences], 'primaire' => bool]
 */
function mem_search(array $weightedTerms, array $options = []): array {
    $chunks = mem_chunks();
    if (empty($chunks) || empty($weightedTerms)) {
        return [];
    }

    $minScore       = (float) ($options['min_score'] ?? 0.0001);
    $requirePrimary = (bool) ($options['exiger_primaire'] ?? true);
    $maxBlocks      = (int) ($options['max_blocs'] ?? 120);

    // Formes a chercher pour chaque terme : le terme et ses variantes
    // morphologiques, toutes rattachees au meme poids.
    $forms = [];
    foreach ($weightedTerms as $term => $meta) {
        $variants = !empty($meta['variantes']) ? $meta['variantes'] : [$term];
        foreach ($variants as $variant) {
            if (!isset($forms[$variant]) || $forms[$variant]['poids'] < $meta['poids']) {
                $forms[$variant] = ['poids' => (float) $meta['poids'], 'racine' => $term];
            }
        }
    }

    // Frequence documentaire : un terme present partout (ex. "mathieu") ne doit
    // pas peser autant qu'un terme rare (ex. "nenuphar").
    $documentFrequency = array_fill_keys(array_keys($forms), 0);
    $totalLength = 0;
    foreach ($chunks as $chunk) {
        $totalLength += $chunk['taille'];
        foreach ($forms as $form => $meta) {
            if (strpos($chunk['norm'], $form) !== false) {
                $documentFrequency[$form]++;
            }
        }
    }
    $chunkCount = count($chunks);
    $averageLength = $chunkCount > 0 ? ($totalLength / $chunkCount) : 1;

    $k1 = 1.2;
    $b = 0.6;
    $results = [];

    foreach ($chunks as $chunk) {
        $score = 0.0;
        $matched = [];
        $primaryHit = false;
        $distinctRoots = [];
        $headingNorm = mem_norm_padded($chunk['chemin']);

        foreach ($forms as $form => $meta) {
            $df = $documentFrequency[$form];
            if ($df === 0) {
                continue;
            }
            $counts = mem_count_term($chunk['norm'], $form);
            $occurrences = $counts['entier'] + (0.35 * $counts['partiel']);
            $inHeading = strpos($headingNorm, $form) !== false;
            if ($occurrences <= 0 && !$inHeading) {
                continue;
            }

            $idf = log(1 + (($chunkCount - $df + 0.5) / ($df + 0.5)));
            $tf = ($occurrences * ($k1 + 1)) / ($occurrences + $k1 * (1 - $b + $b * ($chunk['taille'] / max(1, $averageLength))));
            $contribution = $meta['poids'] * $idf * $tf;
            if ($inHeading) {
                // Un terme dans le titre de la sous-section est un signal fort :
                // c'est souvent la que se trouve le sujet demande.
                $contribution += $meta['poids'] * $idf * 1.5;
            }
            $score += $contribution;

            if ($occurrences > 0 || $inHeading) {
                $matched[$form] = $counts['entier'] + $counts['partiel'] + ($inHeading ? 1 : 0);
                $distinctRoots[$meta['racine']] = true;
                if ($meta['poids'] >= 2.5) {
                    $primaryHit = true;
                }
            }
        }

        if ($score <= 0) {
            continue;
        }
        if ($requirePrimary && !$primaryHit) {
            continue;
        }

        // Bonus de couverture : un bloc qui croise plusieurs notions demandees
        // vaut bien mieux qu'un bloc qui repete dix fois le meme mot.
        $coverage = count($distinctRoots);
        if ($coverage > 1) {
            $score *= (1 + (0.35 * ($coverage - 1)));
        }

        if ($score < $minScore) {
            continue;
        }

        $results[] = [
            'bloc'     => $chunk,
            'score'    => $score,
            'termes'   => $matched,
            'primaire' => $primaryHit,
            'couverture' => $coverage,
        ];
    }

    usort($results, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    if ($maxBlocks > 0 && count($results) > $maxBlocks) {
        $results = array_slice($results, 0, $maxBlocks);
    }
    return $results;
}

/**
 * Selection diversifiee sous budget de caracteres.
 *
 * Sans diversification, une section fleuve (Famille, Domaine) rafle tout le
 * budget et les mentions eparpillees ailleurs (Chronologie, Annexes)
 * disparaissent : c'est exactement le symptome "il n'a pas bien cherche".
 * On plafonne donc la part de chaque chapitre ## tant que d'autres chapitres
 * ont des resultats a proposer, puis on remplit le budget restant.
 */
function mem_select_context_blocks(array $hits, int $budget): array {
    if (empty($hits)) {
        return [];
    }

    $sectionCount = [];
    foreach ($hits as $hit) {
        $sectionCount[$hit['bloc']['h2']] = true;
    }
    $distinctSections = max(1, count($sectionCount));
    $perSectionBudget = $distinctSections > 1
        ? (int) max(6000, $budget * 0.55)
        : $budget;

    $selected = [];
    $used = 0;
    $usedBySection = [];

    // Premier tour : on respecte le plafond par chapitre.
    foreach ($hits as $hit) {
        $size = $hit['bloc']['taille'] + 120; // marge pour l'en-tete du bloc
        $section = $hit['bloc']['h2'];
        if ($used + $size > $budget) {
            continue;
        }
        if (($usedBySection[$section] ?? 0) + $size > $perSectionBudget) {
            continue;
        }
        $selected[$hit['bloc']['id']] = $hit;
        $used += $size;
        $usedBySection[$section] = ($usedBySection[$section] ?? 0) + $size;
    }

    // Deuxieme tour : on remplit ce qui reste, plafond ignore.
    foreach ($hits as $hit) {
        if (isset($selected[$hit['bloc']['id']])) {
            continue;
        }
        $size = $hit['bloc']['taille'] + 120;
        if ($used + $size > $budget) {
            continue;
        }
        $selected[$hit['bloc']['id']] = $hit;
        $used += $size;
    }

    // Remise en ordre du fichier : le LLM lit un texte coherent, pas un
    // patchwork desordonne (important pour la chronologie notamment).
    $ordered = array_values($selected);
    usort($ordered, function ($a, $b) {
        return $a['bloc']['ligne'] <=> $b['bloc']['ligne'];
    });
    return $ordered;
}

/**
 * Met en forme les blocs retenus pour le LLM : fil d'Ariane et ligne de depart
 * en tete de chaque extrait, afin que le moteur puisse citer un emplacement
 * exact ("## 3 > ### Famille et Tribu, vers la ligne 512").
 */
function mem_format_context(array $selected): string {
    if (empty($selected)) {
        return '';
    }
    $parts = [];
    foreach ($selected as $hit) {
        $bloc = $hit['bloc'];
        $parts[] = '[EXTRAIT ' . $bloc['chemin'] . ' | ligne ' . $bloc['ligne'] . ']' . "\n" . $bloc['texte'];
    }
    return implode("\n\n", $parts);
}

/**
 * Recherche complete pour un texte donne : une passe stricte, puis, si la
 * moisson est maigre, une passe elargie ou les synonymes suffisent a retenir
 * un bloc. C'est cette deuxieme passe qui evite de conclure trop vite que le
 * sujet est absent du fichier.
 *
 * Options :
 *   'budget'         budget de contexte en caracteres (defaut MEM_CONTEXT_BUDGET)
 *   'min_blocs'      en dessous de ce nombre de blocs, on declenche la 2e passe
 *   'max_blocs'      nombre maximal de blocs examines par passe
 *
 * Retour :
 *   ['contexte','blocs','termes','passes','stats']
 */
function mem_retrieve(string $rawText, array $comprehension = [], array $options = []): array {
    $budget    = (int) ($options['budget'] ?? MEM_CONTEXT_BUDGET);
    $minBlocks = (int) ($options['min_blocs'] ?? 4);
    $maxBlocks = (int) ($options['max_blocs'] ?? 120);

    $start = microtime(true);
    $terms = mem_build_query_terms($rawText, $comprehension);
    $passes = [];

    $hits = mem_search($terms, [
        'exiger_primaire' => true,
        'max_blocs'       => $maxBlocks,
    ]);
    $passes[] = [
        'nom'    => 'stricte (au moins un terme de la demande)',
        'blocs'  => count($hits),
    ];

    if (count($hits) < $minBlocks) {
        // Passe elargie : les synonymes et notions reliees suffisent. C'est
        // le filet qui rattrape "chiens" -> "Luna", "faune", "animaux".
        $wide = mem_search($terms, [
            'exiger_primaire' => false,
            'max_blocs'       => $maxBlocks,
        ]);
        $passes[] = [
            'nom'   => 'elargie (synonymes et notions reliees seuls acceptes)',
            'blocs' => count($wide),
        ];
        if (count($wide) > count($hits)) {
            $hits = $wide;
        }
    }

    $selected = mem_select_context_blocks($hits, $budget);
    $context = mem_format_context($selected);

    $source = mem_source_info();
    $stats = [
        'source'          => $source['origin'],
        'source_erreur'   => $source['error'],
        'taille_fichier'  => $source['length'],
        'blocs_indexes'   => count(mem_chunks()),
        'blocs_trouves'   => count($hits),
        'blocs_retenus'   => count($selected),
        'contexte_car'    => mb_strlen($context, 'UTF-8'),
        'budget_car'      => $budget,
        'duree_ms'        => (int) round((microtime(true) - $start) * 1000),
    ];

    return [
        'contexte' => $context,
        'blocs'    => $selected,
        'hits'     => $hits,
        'termes'   => $terms,
        'passes'   => $passes,
        'stats'    => $stats,
    ];
}

/**
 * Trace de debogage lisible pour le panneau de saisie.php.
 * Volontairement verbeuse : c'est le seul endroit ou Mathieu (ou une autre IA
 * reprenant le projet) peut verifier ce qui a REELLEMENT ete cherche et envoye.
 */
function mem_debug_report(array $retrieval, int $maxBlocks = 15): string {
    $stats = $retrieval['stats'] ?? [];
    $lines = [];
    $lines[] = 'Source memoire : ' . ($stats['source'] ?? '?')
        . ' (' . number_format((float) ($stats['taille_fichier'] ?? 0), 0, ',', ' ') . ' caracteres, '
        . ($stats['blocs_indexes'] ?? 0) . ' blocs indexes)';
    if (!empty($stats['source_erreur'])) {
        $lines[] = 'Avertissement source : ' . $stats['source_erreur'];
    }

    $termLines = [];
    foreach (($retrieval['termes'] ?? []) as $term => $meta) {
        $termLines[] = $term . ' (' . rtrim(rtrim(number_format($meta['poids'], 1, ',', ''), '0'), ',') . ', ' . $meta['origine'] . ')';
    }
    $lines[] = 'Termes de recherche (' . count($termLines) . ') : ' . (empty($termLines) ? 'aucun' : implode(' | ', $termLines));

    foreach (($retrieval['passes'] ?? []) as $index => $pass) {
        $lines[] = 'Passe ' . ($index + 1) . ' ' . $pass['nom'] . ' : ' . $pass['blocs'] . ' bloc(s) trouve(s)';
    }

    $lines[] = 'Blocs retenus : ' . ($stats['blocs_retenus'] ?? 0) . ' / ' . ($stats['blocs_trouves'] ?? 0)
        . ' - contexte transmis : ' . number_format((float) ($stats['contexte_car'] ?? 0), 0, ',', ' ')
        . ' caracteres (budget ' . number_format((float) ($stats['budget_car'] ?? 0), 0, ',', ' ') . ')'
        . ' - recherche en ' . ($stats['duree_ms'] ?? 0) . ' ms';

    $ranked = $retrieval['hits'] ?? [];
    if (!empty($ranked)) {
        $lines[] = 'Meilleurs blocs :';
        foreach (array_slice($ranked, 0, $maxBlocks) as $position => $hit) {
            $bloc = $hit['bloc'];
            $termList = implode(', ', array_keys($hit['termes']));
            $lines[] = sprintf(
                '  %2d. score %6.2f | %s (ligne %d) | termes : %s',
                $position + 1,
                $hit['score'],
                $bloc['chemin'],
                $bloc['ligne'],
                $termList !== '' ? $termList : '-'
            );
        }
    }

    return implode("\n", $lines);
}
