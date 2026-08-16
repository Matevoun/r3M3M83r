<?php
/**
 * reformulator/tests/retrieval_cli.php - Banc d'essai de la recherche
 *
 * Permet de verifier la recherche dans instructions.md SANS appeler le moindre
 * moteur LLM (donc sans clef API, sans Node.js, gratuitement et instantanement).
 * C'est l'outil a utiliser quand une reponse parait fausse : si le passage
 * attendu apparait ici, le probleme vient du prompt ou du moteur ; s'il
 * n'apparait pas, le probleme vient de la recherche.
 *
 * Usage :
 *   php reformulator/tests/retrieval_cli.php "as-tu parle des chiens ?"
 *   php reformulator/tests/retrieval_cli.php "les chiens" --syn=animal,animaux,faune,chienne
 *   php reformulator/tests/retrieval_cli.php "les chiens" --extraits=3
 *
 * Options :
 *   --syn=a,b,c        synonymes simules (ce que renverrait la comprehension LLM)
 *   --entites=a,b      entites nommees simulees
 *   --extraits=N       affiche le texte des N premiers blocs retenus
 *   --budget=N         budget de contexte en caracteres
 */

require_once __DIR__ . '/../retrieval.php';

$arguments = array_slice($argv, 1);
$question = '';
$synonyms = [];
$entities = [];
$showExcerpts = 0;
$budget = MEM_CONTEXT_BUDGET;

foreach ($arguments as $argument) {
    if (strpos($argument, '--syn=') === 0) {
        $synonyms = array_filter(array_map('trim', explode(',', substr($argument, 6))));
    } elseif (strpos($argument, '--entites=') === 0) {
        $entities = array_filter(array_map('trim', explode(',', substr($argument, 10))));
    } elseif (strpos($argument, '--extraits=') === 0) {
        $showExcerpts = (int) substr($argument, 11);
    } elseif (strpos($argument, '--budget=') === 0) {
        $budget = (int) substr($argument, 9);
    } else {
        $question = trim($question . ' ' . $argument);
    }
}

if ($question === '') {
    fwrite(STDERR, "Usage : php retrieval_cli.php \"votre question\" [--syn=a,b] [--entites=a,b] [--extraits=3]\n");
    exit(1);
}

$comprehension = [
    'synonymes' => $synonyms,
    'entites'   => $entities,
];

$retrieval = mem_retrieve($question, $comprehension, ['budget' => $budget]);

echo "QUESTION : " . $question . "\n";
echo str_repeat('-', 78) . "\n";
echo mem_debug_report($retrieval, 20) . "\n";

if ($showExcerpts > 0) {
    echo str_repeat('-', 78) . "\n";
    $shown = 0;
    foreach ($retrieval['hits'] as $hit) {
        if ($shown >= $showExcerpts) {
            break;
        }
        echo "\n### " . $hit['bloc']['chemin'] . ' (ligne ' . $hit['bloc']['ligne'] . ", score " . round($hit['score'], 2) . ")\n";
        echo mb_substr($hit['bloc']['texte'], 0, 900, 'UTF-8') . "\n";
        $shown++;
    }
}
