<?php
/**
 * reformulator/tests/pipeline_cli.php
 *
 * Verifie hors ligne (sans moteur LLM, sans serveur web) les fonctions
 * charnieres du pipeline "comprendre -> chercher -> repondre" :
 *
 *   - decode_json_object_from_llm() : tolerance aux reponses JSON sales
 *     (bloc de code, bavardage avant ou apres) ;
 *   - normalize_comprehension()     : mise en forme sure de la comprehension ;
 *   - mem_build_query_terms()       : les synonymes deviennent de VRAIS termes
 *     de recherche, ce qui etait precisement le defaut d'origine ;
 *   - mem_retrieve()                : la recherche trouve un sujet decrit avec
 *     d'autres mots que ceux de la question ;
 *   - parse_rewrite_result() et parse_merge_smart_result() : decoupage des
 *     reponses en blocs, y compris quand le moteur oublie les balises.
 *
 * Lancement :
 *   php reformulator/tests/pipeline_cli.php
 *
 * Sortie : une ligne par test, puis un bilan. Code de sortie 1 si un test
 * echoue (utilisable dans une integration continue).
 *
 * Ce fichier ne contacte aucun service : il peut tourner partout, y compris
 * quand Node.js est arrete ou qu'aucune clef d'API n'est disponible.
 */

declare(strict_types=1);

// functions.php execute du code de page en fin de fichier : on lui fournit un
// contexte de requete minimal pour qu'il s'execute proprement en ligne de
// commande, sans rien envoyer ni afficher.
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/saisie.php';

require_once dirname(__DIR__) . '/functions.php';

$echecs = 0;
$total  = 0;

function verifier(string $intitule, bool $condition, string $detail = ''): void {
    global $echecs, $total;
    $total++;
    if ($condition) {
        echo "  OK   " . $intitule . "\n";
        return;
    }
    $echecs++;
    echo "  ECHEC " . $intitule . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
}

echo "== Lecture d'une reponse JSON de comprehension ==\n";

$propre = '{"intention":"savoir si les chiens sont evoques","mots_clefs":[{"terme":"chien","poids":4}],"synonymes":["animal","faune"]}';
$sale = "Bien sur, voici le JSON demande :\n```json\n" . $propre . "\n```\nJ'espere que cela convient.";

verifier('JSON propre decode', is_array(decode_json_object_from_llm($propre)));
verifier('JSON entoure de bavardage et de balises de code decode', is_array(decode_json_object_from_llm($sale)));
verifier('Texte sans JSON rejete proprement', decode_json_object_from_llm('Je ne sais pas repondre.') === null);

echo "\n== Normalisation de la comprehension ==\n";

$c = normalize_comprehension(json_decode($propre, true));
verifier('Clefs obligatoires presentes', isset($c['intention'], $c['mots_clefs'], $c['synonymes'], $c['entites'], $c['periodes']));
verifier('Poids borne entre 1 et 4', $c['mots_clefs'][0]['poids'] === 4.0, 'poids obtenu : ' . var_export($c['mots_clefs'][0]['poids'] ?? null, true));

$degrade = normalize_comprehension([
    'mots_clefs' => 'chien, animal',       // le moteur a renvoye une chaine
    'synonymes'  => ['faune', '', '  '],   // entrees vides
    'entites'    => 'Luna',
]);
verifier('Chaine de mots clefs acceptee', count($degrade['mots_clefs']) === 2);
verifier('Entrees vides ecartees', $degrade['synonymes'] === ['faune']);
verifier('Comprehension vide toleree', normalize_comprehension([])['intention'] === '');

echo "\n== Les synonymes deviennent de vrais termes de recherche ==\n";

$termesSansSyn = mem_build_query_terms('est-ce que j\'ai parle des chiens ?', []);
$termesAvecSyn = mem_build_query_terms('est-ce que j\'ai parle des chiens ?', [
    'synonymes' => ['animal', 'faune'],
    'entites'   => ['Luna'],
]);
verifier('La demande seule produit des termes', count($termesSansSyn) > 0);
verifier('Les synonymes sont ajoutes aux termes cherches', isset($termesAvecSyn['animal'], $termesAvecSyn['faune']));
verifier('Les entites sont ajoutees aux termes cherches', isset($termesAvecSyn['luna']));
verifier(
    'Un terme de la demande pese plus qu\'un synonyme',
    ($termesAvecSyn['chiens']['poids'] ?? 0) > ($termesAvecSyn['animal']['poids'] ?? 99)
);

echo "\n== Recherche reelle dans instructions.md ==\n";

$source = mem_source_info();
if ($source['length'] === 0) {
    echo "  (ignore) aucune source memoire lisible : " . ($source['error'] ?: 'fichier absent') . "\n";
} else {
    verifier('Source memoire chargee', $source['length'] > 0, $source['origin']);
    verifier('Fichier decoupe en blocs', count(mem_chunks()) > 1);

    $r = mem_retrieve('est-ce que j\'ai parle des chiens ?', ['synonymes' => ['animal', 'animaux', 'faune']]);
    verifier('Des extraits sont retrouves', $r['stats']['blocs_trouves'] > 0);
    verifier('Un contexte non vide est construit', $r['stats']['contexte_car'] > 0);
    verifier('Le contexte respecte le budget', $r['stats']['contexte_car'] <= $r['stats']['budget_car']);
    verifier('Les extraits portent leur emplacement', str_contains($r['contexte'], '[EXTRAIT '));
    verifier('Le rapport de debogage est renseigne', mem_debug_report($r) !== '');

    // Une demande sans rapport ne doit pas ramener n'importe quoi.
    $vide = mem_retrieve('zzzqwertyuiop azertyuiopq', []);
    verifier('Une demande sans rapport ne ramene rien', $vide['stats']['blocs_trouves'] === 0);
}

echo "\n== Decoupage des reponses des moteurs ==\n";

$rewrite = "<<<TEXTE\nMathieu a rachete une 2CV en 1998.\n>>>TEXTE\n\n<<<HUMAIN\nJ'ai resserre le recit.\n>>>HUMAIN";
$parsed = parse_rewrite_result($rewrite);
verifier('Bloc TEXTE isole', $parsed['texte'] === 'Mathieu a rachete une 2CV en 1998.');
verifier('Bloc HUMAIN isole', $parsed['humain'] === "J'ai resserre le recit.");

$sansBalise = parse_rewrite_result('Mathieu a rachete une 2CV en 1998.');
verifier('Reponse sans balise conservee', $sansBalise['texte'] !== '' && $sansBalise['humain'] === '');

$merge = "<<<HUMAIN\nJ'ai trouve des passages semblables.\n>>>HUMAIN\n\n<<<A_COLLER\nBloc fusionne.\n>>>A_COLLER\n\n<<<EMPLACEMENT\nChapitre 3.\n>>>EMPLACEMENT\n\n<<<DETAILS\nDeja : rien.\n>>>DETAILS";
$mergeParts = parse_merge_smart_result($merge);
verifier('Fusion : bloc a coller isole', $mergeParts['a_coller'] === 'Bloc fusionne.');
verifier('Fusion : emplacement isole', $mergeParts['emplacement'] === 'Chapitre 3.');
verifier('Fusion : bloc humain isole', parse_merge_human_block($merge) === "J'ai trouve des passages semblables.");

$mergeSansBalise = parse_merge_smart_result("<<<HUMAIN\nExplication.\n>>>HUMAIN\n\nTexte fusionne libre.");
verifier(
    'Fusion sans balise A_COLLER : le bloc humain est retire du texte a coller',
    !str_contains($mergeSansBalise['a_coller'], 'Explication.')
);

echo "\n" . ($echecs === 0
    ? "Tous les tests passent (" . $total . ")\n"
    : $echecs . " test(s) en echec sur " . $total . "\n");

exit($echecs === 0 ? 0 : 1);
