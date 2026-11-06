<?php
// Moteur de lecture + bascule LLM. Inclus par Rebecca, pas appele en URL.
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const REBECCA_PERSONA = <<<'TXT'
CONSIGNE TCHAT (Rebecca / Rebbye) :
- Tu es Rebecca (Rebbye), avatar feminin virtuel de Mathieu dans le projet r3M3M83r.
- Tu ne sais pas qui t'ecrit. Ce n'est pas forcement Mathieu.
- Parle toujours de Mathieu a la troisieme personne : "Mathieu a achete". Jamais "tu as achete", jamais "ton", "ta" ou "tes" pour lui.
- N'adresse aucun surnom a la personne qui ecrit.
- Ton : humain, clair, chaleureux, un peu complice. Legerement aguicheuse si le contexte s'y pret, jamais en automatisme.
- Ne commence pas par une formule d'ouverture si la conversation est deja engagee.
- L'historique sert au suivi. Les FAITS viennent des extraits.
- Si une reponse precedente contredit les preuves, les preuves GAGNENT.
TXT;

function rebecca_engines(): array
{
    return [
        'gemini' => [
            'name' => 'Gemini',
            'apiKeyEnv' => 'GEMINI_API_KEY',
            'modelEnv' => 'GEMINI_MODEL',
            'apiBase' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'apiBaseEnv' => 'GEMINI_API_BASE',
            'defaultModel' => 'gemini-2.5-flash-lite',
            'models' => ['gemini-2.5-flash-lite', 'gemini-2.5-flash'],
        ],
        'groq' => [
            'name' => 'Groq',
            'apiKeyEnv' => 'GROQ_API_KEY',
            'modelEnv' => 'GROQ_MODEL',
            'apiBase' => 'https://api.groq.com/openai/v1',
            'apiBaseEnv' => 'GROQ_API_BASE',
            'defaultModel' => 'openai/gpt-oss-120b',
            'models' => ['openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'apiKeyEnv' => 'OPENROUTER_API_KEY',
            'modelEnv' => 'OPENROUTER_MODEL',
            'apiBase' => 'https://openrouter.ai/api/v1',
            'apiBaseEnv' => 'OPENROUTER_API_BASE',
            'defaultModel' => 'openrouter/free',
            'models' => ['openrouter/free', 'google/gemini-2.0-flash-lite-preview:free', 'mistralai/mistral-small-3.2-2409:free'],
        ],
        'mistral' => [
            'name' => 'Mistral',
            'apiKeyEnv' => 'MISTRAL_API_KEY',
            'modelEnv' => 'MISTRAL_MODEL',
            'apiBase' => 'https://api.mistral.ai/v1',
            'apiBaseEnv' => 'MISTRAL_API_BASE',
            'defaultModel' => 'mistral-small-latest',
            'models' => ['mistral-small-latest'],
        ],
        'sambanova' => [
            'name' => 'SambaNova',
            'apiKeyEnv' => 'SAMBANOVA_API_KEY',
            'modelEnv' => 'SAMBANOVA_MODEL',
            'apiBase' => 'https://api.sambanova.ai/v1',
            'apiBaseEnv' => 'SAMBANOVA_API_BASE',
            'defaultModel' => 'Meta-Llama-3.3-70B-Instruct',
            'models' => ['Meta-Llama-3.3-70B-Instruct', 'gpt-oss-120b', 'gemma-4-31B-it'],
        ],
        'nvidia' => [
            'name' => 'NVIDIA',
            'apiKeyEnv' => 'NVIDIA_API_KEY',
            'modelEnv' => 'NVIDIA_MODEL',
            'apiBase' => 'https://integrate.api.nvidia.com/v1',
            'apiBaseEnv' => 'NVIDIA_API_BASE',
            'defaultModel' => 'openai/gpt-oss-20b',
            'models' => ['openai/gpt-oss-20b', 'nvidia/nemotron-3-super-120b-a12b', 'google/gemma-4-31b-it'],
        ],
        'grok' => [
            'name' => 'Grok',
            'apiKeyEnv' => 'XAI_API_KEY',
            'modelEnv' => 'XAI_MODEL',
            'apiBase' => 'https://api.x.ai/v1',
            'apiBaseEnv' => 'XAI_API_BASE',
            'defaultModel' => 'grok-4.5',
            'models' => ['grok-4.5'],
            'rescue' => true,
        ],
    ];
}

function rebecca_fold(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    if (class_exists('Normalizer')) {
        $norm = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($norm)) {
            $value = $norm;
        }
    }
    $stripped = preg_replace('/\p{M}+/u', '', $value);
    return is_string($stripped) ? $stripped : $value;
}

function rebecca_stop(): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip([
            'qui', 'que', 'quoi', 'quel', 'quelle', 'quels', 'quelles', 'comment', 'pourquoi',
            'est', 'sont', 'faits', 'quelques', 'aussi', 'etre', 'dans', 'pour', 'avec', 'sans',
            'les', 'des', 'une', 'uns', 'son', 'ses', 'sur', 'par', 'pas', 'plus', 'moins',
            'tout', 'tous', 'cette', 'ceci', 'cela', 'mais', 'donc', 'alors', 'comme', 'quand',
            'depuis', 'entre', 'vers', 'chez', 'aux', 'leur', 'leurs', 'nous', 'vous', 'elle',
            'elles', 'ils', 'lui', 'moi', 'toi', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes',
            'du', 'au', 'en', 'de', 'le', 'la', 'un', 'et', 'ou', 'or', 'ni', 'ne', 'ce',
            'se', 'sa', 'il', 'on', 'fait', 'faire', 'dit', 'dire', 'parle', 'bonjour',
            'bonsoir', 'salut', 'merci', 'coucou', 'hello', 'hey', 'oui', 'non', 'stp',
            'svp', 'plait', 'sil', 'rebecca', 'rebbye', 'ca', 'va',
        ]);
    }
    return $stop;
}

function rebecca_terms(string $question): array
{
    $source = rebecca_fold(str_replace(['"', '“', '”', '«', '»'], ' ', $question));
    $parts = preg_split('/[^a-z0-9]+/', $source) ?: [];
    $terms = [];
    $seen = [];
    $stop = rebecca_stop();
    foreach ($parts as $part) {
        if (strlen($part) < 3 || isset($stop[$part]) || isset($seen[$part])) {
            continue;
        }
        $seen[$part] = true;
        $terms[] = $part;
        if (count($terms) >= 8) {
            break;
        }
    }
    return $terms;
}

function rebecca_memory_path(): ?string
{
    $root = dirname(__DIR__);
    foreach (['instructions.md', 'profil', 'profil.md', 'profil.php'] as $name) {
        $path = $root . '/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $head = file_get_contents($path, false, null, 0, 8);
        if (is_string($head) && str_starts_with(ltrim($head), '<?')) {
            continue;
        }
        return $path;
    }
    return null;
}

function rebecca_memory(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = rebecca_memory_path();
    if ($path === null) {
        $cached = ['ok' => false, 'text' => '', 'lines' => 0, 'name' => ''];
        return $cached;
    }
    $text = file_get_contents($path);
    if (!is_string($text)) {
        $cached = ['ok' => false, 'text' => '', 'lines' => 0, 'name' => basename($path)];
        return $cached;
    }
    $cached = [
        'ok' => true,
        'text' => $text,
        'lines' => substr_count($text, "\n") + 1,
        'name' => basename($path),
    ];
    return $cached;
}

function rebecca_server_keys(): array
{
    $keys = [];
    $file = __DIR__ . '/clefs.php';
    if (is_file($file)) {
        $loaded = include $file;
        if (is_array($loaded)) {
            foreach ($loaded as $name => $value) {
                if (is_string($name) && is_string($value) && trim($value) !== '') {
                    $keys[$name] = trim($value);
                }
            }
        }
    }
    foreach (rebecca_engines() as $engine) {
        $envName = $engine['apiKeyEnv'];
        if (isset($keys[$envName])) {
            continue;
        }
        $env = getenv($envName);
        if (is_string($env) && trim($env) !== '') {
            $keys[$envName] = trim($env);
        }
    }
    return $keys;
}

function rebecca_status(): array
{
    $memory = rebecca_memory();
    $names = [];
    foreach (rebecca_engines() as $id => $engine) {
        if (isset(rebecca_server_keys()[$engine['apiKeyEnv']])) {
            $names[] = $id;
        }
    }
    return [
        'ok' => $memory['ok'],
        'lines' => $memory['lines'],
        'file' => $memory['name'],
        'serverEngines' => $names,
    ];
}

function rebecca_heading_path(array $lines, int $lineNumber): string
{
    $stack = [];
    $limit = min($lineNumber, count($lines));
    for ($i = 0; $i < $limit; $i++) {
        if (!preg_match('/^(#{1,5})\s+(.*)$/', $lines[$i], $match)) {
            continue;
        }
        $level = strlen($match[1]);
        $title = trim($match[2]);
        while ($stack !== [] && $stack[count($stack) - 1]['level'] >= $level) {
            array_pop($stack);
        }
        $stack[] = ['level' => $level, 'title' => $title];
    }
    $tail = array_slice($stack, -3);
    return implode(' > ', array_map(static fn (array $entry): string => $entry['title'], $tail));
}

function rebecca_items(string $markdown): array
{
    $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
    $items = [];
    $index = 0;
    $count = count($lines);
    while ($index < $count) {
        $line = $lines[$index];
        if (!preg_match('/^(#{1,5})\s+/', $line) && !str_starts_with($line, '- ')) {
            $index++;
            continue;
        }
        $start = $index;
        $index++;
        while ($index < $count) {
            $next = $lines[$index];
            if (preg_match('/^(#{1,5})\s+/', $next) || str_starts_with($next, '- ')) {
                break;
            }
            $index++;
        }
        $end = $index - 1;
        $text = trim(implode("\n", array_slice($lines, $start, $end - $start + 1)));
        if ($text === '') {
            continue;
        }
        $items[] = [
            'startLine' => $start + 1,
            'endLine' => $end + 1,
            'heading' => rebecca_heading_path($lines, $start + 1),
            'text' => $text,
            'folded' => rebecca_fold($text),
        ];
    }
    return $items;
}

function rebecca_occurrences(string $haystack, string $needle): int
{
    if ($needle === '') {
        return 0;
    }
    $count = 0;
    $from = 0;
    $length = strlen($haystack);
    while ($from < $length) {
        $at = strpos($haystack, $needle, $from);
        if ($at === false) {
            break;
        }
        $count++;
        $from = $at + strlen($needle);
    }
    return $count;
}

function rebecca_score(array $item, array $terms): int
{
    $score = 0;
    $hits = 0;
    foreach ($terms as $term) {
        $count = rebecca_occurrences($item['folded'], $term);
        if ($count === 0) {
            continue;
        }
        $hits++;
        $score += min(28, 10 + strlen($term)) + min($count, 6) * 6;
    }
    if (count($terms) >= 2 && $hits >= 2) {
        $score += 36;
    }
    if ($hits === count($terms) && count($terms) >= 2) {
        $score += 24;
    }
    if (count($terms) >= 2 && str_contains($item['folded'], implode(' ', $terms))) {
        $score += 70;
    }
    $lead = substr($item['folded'], 0, 320);
    $leadHits = 0;
    foreach ($terms as $term) {
        if (str_contains($lead, $term)) {
            $leadHits++;
        }
    }
    if ($leadHits === count($terms) && $leadHits > 0) {
        $score += 55;
    } elseif ($leadHits > 0) {
        $score += 16 * $leadHits;
    }
    $firstLine = rebecca_fold(strtok($item['text'], "\n") ?: '');
    $allInFirst = $terms !== [];
    foreach ($terms as $term) {
        if (!str_contains($firstLine, $term)) {
            $allInFirst = false;
            break;
        }
    }
    if ($allInFirst && strlen($firstLine) < 90) {
        $score += 45;
    }
    $title = trim((string) preg_replace('/^#+\s*/', '', $firstLine));
    if (count($terms) === 1 && $title === $terms[0]) {
        $score += 35;
    }
    return $score;
}

function rebecca_clip(string $text, int $cap): string
{
    if (strlen($text) <= $cap) {
        return $text;
    }
    $slice = substr($text, 0, $cap);
    $cut = strrpos($slice, "\n");
    $body = ($cut !== false && $cut > (int) ($cap * 0.55)) ? substr($slice, 0, $cut) : $slice;
    return rtrim($body) . "\n\n[... suite de l'entree non envoyee, budget atteint ...]";
}

function rebecca_clip_for_terms(string $text, int $cap, array $terms): string
{
    if (strlen($text) <= $cap || $terms === []) {
        return rebecca_clip($text, $cap);
    }
    $parts = preg_split("/\n\s*\n/", $text) ?: [$text];
    $head = '';
    $rest = [];
    $headBudget = (int) ($cap * 0.72);
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if ($rest === [] && strlen($head) + strlen($part) + 2 <= $headBudget) {
            $head .= ($head === '' ? '' : "\n\n") . $part;
        } else {
            $rest[] = $part;
        }
    }
    if ($head === '') {
        $head = rebecca_clip($text, $cap);
    }
    $extraBudget = 1600;
    $need = min(2, count($terms));
    foreach ($rest as $part) {
        $folded = rebecca_fold($part);
        $hits = 0;
        foreach ($terms as $term) {
            if (str_contains($folded, $term)) {
                $hits++;
            }
        }
        if ($hits < $need || strlen($part) > $extraBudget) {
            continue;
        }
        $head .= "\n\n[...]\n\n" . $part;
        $extraBudget -= strlen($part);
        if ($extraBudget < 240) {
            break;
        }
    }
    return $head;
}

function rebecca_search(string $markdown, string $question, int $totalCap = 7800, bool $deep = false): array
{
    $terms = rebecca_terms($question);
    $logs = [];
    if ($terms === []) {
        $logs[] = '// pas de terme solide : message traite comme echange, sans recherche';
        return ['blocks' => [], 'terms' => [], 'logs' => $logs];
    }
    $logs[] = '// termes : ' . implode(', ', $terms);
    $ranked = [];
    foreach (rebecca_items($markdown) as $item) {
        $score = rebecca_score($item, $terms);
        if ($score >= 18) {
            $ranked[] = ['item' => $item, 'score' => $score];
        }
    }
    usort($ranked, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $a['item']['startLine'] <=> $b['item']['startLine'];
        }
        return $b['score'] <=> $a['score'];
    });
    $blocks = [];
    $used = 0;
    foreach ($ranked as $entry) {
        if (count($blocks) >= ($deep ? 8 : 5)) {
            break;
        }
        $room = $totalCap - $used;
        if ($room < 400 && $blocks !== []) {
            break;
        }
        $cap = min($deep ? 9000 : 6200, max($room, 400));
        $text = rebecca_clip_for_terms($entry['item']['text'], $cap, $terms);
        $used += strlen($text);
        $blocks[] = [
            'score' => $entry['score'],
            'startLine' => $entry['item']['startLine'],
            'endLine' => $entry['item']['endLine'],
            'heading' => $entry['item']['heading'],
            'text' => $text,
        ];
    }
    if ($blocks === []) {
        $logs[] = '// recherche locale vide, y compris sans accent';
        return ['blocks' => [], 'terms' => $terms, 'logs' => $logs];
    }
    $where = [];
    foreach ($blocks as $block) {
        $where[] = 'l.' . $block['startLine'] . '-' . $block['endLine'] . ' (' . $block['score'] . ')';
    }
    $logs[] = '// blocs retenus : ' . count($blocks) . ' / ' . implode(' ; ', $where);
    $logs[] = '// volume preuves : ' . $used . ' car.';
    return ['blocks' => $blocks, 'terms' => $terms, 'logs' => $logs];
}

function rebecca_evidence(array $blocks): string
{
    if ($blocks === []) {
        return '';
    }
    $parts = [];
    foreach ($blocks as $index => $block) {
        $place = $block['heading'] !== '' ? $block['heading'] . ' / ' : '';
        $parts[] = '### Extrait ' . ($index + 1) . ' (' . $place . 'lignes ' . $block['startLine'] . '-' . $block['endLine'] . ")\n" . $block['text'];
    }
    return implode("\n\n", $parts);
}

function rebecca_messages(string $persona, string $evidence, array $history, string $userMessage, bool $social, bool $corrective): array
{
    if ($social) {
        $factRule = "Aucune recherche n'a ete lancee : Présente toi, sans fait precis sur une personne.";
    } elseif ($evidence !== '') {
        $factRule = "Les seuls faits autorises sont dans EXTRAITS. Si le sujet y figure, reponds avec ces elements. Interdit de dire qu'il est absent. Si un detail n'y est pas, dis que ces passages ne le disent pas, sans le completer et sans pretendre que tout le fichier a ete lu.";
    } else {
        $factRule = "EXTRAITS est vide. Dis que le fichier ne contient pas la reponse. N'invente jamais aucun fait, aucune date, aucun lien.";
    }
    $system = implode("\n", [
        trim($persona),
        '',
        'REGLES DE FAITS :',
        '- Tu reponds en Francais.',
        '- ' . $factRule,
        '- Noms de famille en MAJUSCULES, comme dans les extraits.',
        "- Pas d'emoji. Pas de tiret cadratin. Pas d'epanorthose. Pas de rythme ternaire.",
        "- Pas de formule d'ouverture si l'historique n'est pas vide.",
        '- Mathieu est toujours a la troisieme personne. La personne qui ecrit n\'est pas forcément Mathieu.',
        "- Si l'historique contredit EXTRAITS, EXTRAITS gagnent.",
        "- Besoin d'un autre passage, et seulement dans ce cas : Reponds par une seule ligne du type SEARCH: termes precis",
        '- Sinon reponds sans prefixe et sans citer le mot EXTRAITS.',
        '',
        'EXTRAITS :',
        $evidence !== '' ? $evidence : '(vide)',
    ]);
    $messages = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($history, -6) as $turn) {
        if (!isset($turn['role'], $turn['content'])) {
            continue;
        }
        $role = $turn['role'] === 'assistant' ? 'assistant' : 'user';
        $messages[] = ['role' => $role, 'content' => mb_substr((string) $turn['content'], 0, 700)];
    }
    $messages[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 2000)];
    if ($corrective) {
        $messages[] = [
            'role' => 'user',
            'content' => "Ta reponse precedente contredit les extraits ou dit que le sujet est absent alors qu'il y figure. Reecris la reponse uniquement a partir des extraits. Interdit de nier leur contenu.",
        ];
    }
    return $messages;
}

function rebecca_search_only(string $reply): ?array
{
    $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $reply) ?: []), static fn (string $line): bool => $line !== ''));
    if ($lines === [] || count($lines) > 2) {
        return null;
    }
    $queries = [];
    foreach ($lines as $line) {
        if (!preg_match('/^SEARCH:\s*(.+)$/i', $line, $match)) {
            return null;
        }
        $query = trim($match[1]);
        if ($query !== '') {
            $queries[] = mb_substr($query, 0, 180);
        }
    }
    return $queries === [] ? null : $queries;
}

function rebecca_denies(string $reply): bool
{
    $folded = trim(rebecca_fold($reply));
    if (mb_strlen($folded) > 420) {
        return false;
    }
    return preg_match('/ne (figure|trouve|contient) pas|ne mentionn|ces passages ne|ne le disent pas|aucune information|pas dans (la |le )?fichier|introuvable|je ne (sais|trouve) pas|n.ai rien trouve|rien dans le fichier|pas mentionn/u', $folded) === 1;
}

function rebecca_scrub(string $value): string
{
    $value = preg_replace('/sk-[A-Za-z0-9_-]{6,}/', 'sk-...', $value) ?? $value;
    $value = preg_replace('/Bearer\s+\S+/i', 'Bearer ...', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_substr($value, 0, 160);
}

function rebecca_read_content(mixed $content): string
{
    if (is_string($content)) {
        return trim($content);
    }
    if (!is_array($content)) {
        return '';
    }
    $text = '';
    foreach ($content as $part) {
        if (is_string($part)) {
            $text .= $part;
        } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    return trim($text);
}

function rebecca_post(array $engine, string $model, string $apiKey, array $messages, string $tokenField, array $extra = []): array
{
    $payload = array_merge([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.4,
        $tokenField => 2200,
    ], $extra);
    $base = $engine['apiBase'];
    $envBase = getenv($engine['apiBaseEnv']);
    if (is_string($envBase) && trim($envBase) !== '') {
        $base = trim($envBase);
    }
    $url = rtrim($base, '/') . '/chat/completions';
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if (($engine['id'] ?? '') === 'openrouter') {
        $headers[] = 'HTTP-Referer: https://mathieu.charreyre.net/r3M3M83r/';
        $headers[] = 'X-Title: Rebecca r3M3M83r';
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['status' => 0, 'body' => 'encodage json impossible'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['status' => 0, 'body' => $error !== '' ? $error : 'reseau'];
    }
    return ['status' => $status, 'body' => (string) $body];
}

function rebecca_visible_answer(array $message): string
{
    $content = rebecca_read_content($message['content'] ?? null);
    if (preg_match('/<\/think>\s*(.+)$/su', $content, $match)) {
        $content = trim($match[1]);
    }
    $content = trim((string) preg_replace('/<think>.*?<\/think>/su', '', $content));
    return trim($content);
}

function rebecca_call_model(array $engine, string $model, string $apiKey, array $messages): array
{
    $reasoning = str_contains($model, 'gpt-oss');
    $extra = $reasoning ? ['reasoning_effort' => 'low', 'reasoning_format' => 'hidden'] : [];
    $field = $reasoning ? 'max_completion_tokens' : 'max_tokens';
    $attempt = rebecca_post($engine, $model, $apiKey, $messages, $field, $extra);
    if ($attempt['status'] === 400 && preg_match('/max_tokens|max_completion_tokens/i', $attempt['body'])) {
        $field = $field === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
        $attempt = rebecca_post($engine, $model, $apiKey, $messages, $field, $extra);
    }
    if ($attempt['status'] === 400 && $extra !== [] && preg_match('/reasoning_|unsupported|unknown|extra/i', $attempt['body'])) {
        $attempt = rebecca_post($engine, $model, $apiKey, $messages, $field, []);
    }
    if ($attempt['status'] < 200 || $attempt['status'] >= 300) {
        $miss = $attempt['status'] === 404 || preg_match('/model.*(not found|does not exist|unknown|unavailable)|unknown model|invalid model/i', $attempt['body']) === 1;
        return ['ok' => false, 'status' => $attempt['status'], 'detail' => rebecca_scrub($attempt['body']), 'modelMiss' => $miss];
    }
    $parsed = json_decode($attempt['body'], true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'status' => $attempt['status'], 'detail' => 'reponse non json', 'modelMiss' => false];
    }
    $message = $parsed['choices'][0]['message'] ?? null;
    $text = is_array($message) ? rebecca_visible_answer($message) : '';
    if ($text === '' && $reasoning) {
        $retry = rebecca_post($engine, $model, $apiKey, $messages, 'max_completion_tokens', [
            'reasoning_effort' => 'low',
            'reasoning_format' => 'hidden',
            'max_completion_tokens' => 4000,
        ]);
        $again = json_decode($retry['body'], true);
        $message = is_array($again) ? ($again['choices'][0]['message'] ?? null) : null;
        $text = is_array($message) ? rebecca_visible_answer($message) : '';
        if ($text === '' && is_array($message) && is_string($message['reasoning'] ?? null)) {
            return ['ok' => false, 'status' => 200, 'detail' => 'le modele a raisonne sans donner de reponse', 'modelMiss' => false];
        }
    }
    if ($text === '') {
        $detail = is_string($parsed['error']['message'] ?? null) ? $parsed['error']['message'] : 'reponse vide';
        return ['ok' => false, 'status' => $attempt['status'], 'detail' => rebecca_scrub($detail), 'modelMiss' => false];
    }
    return ['ok' => true, 'text' => $text];
}

function rebecca_should_skip(int $status, string $body): bool
{
    if (in_array($status, [401, 402, 403, 408, 429], true) || $status >= 500 || $status === 0) {
        return true;
    }
    return preg_match('/credit|quota|rate limit|insufficient|billing|capacity|overloaded|too many|payment|expired/i', $body) === 1;
}

function rebecca_complete(array $messages, array $keys, string $preferred): array
{
    $logs = [];
    $pool = [];
    foreach (rebecca_engines() as $id => $engine) {
        $engine['id'] = $id;
        if (!empty($engine['rescue']) && trim($keys[$engine['apiKeyEnv']] ?? '') === '') {
            continue;
        }
        $pool[] = $engine;
    }
    if ($preferred !== '' && $preferred !== 'auto') {
        $front = [];
        $rest = [];
        foreach ($pool as $engine) {
            if ($engine['id'] === $preferred) {
                $front[] = $engine;
            } else {
                $rest[] = $engine;
            }
        }
        $pool = array_merge($front, $rest);
    }
    $missing = [];
    $usable = [];
    foreach ($pool as $engine) {
        $key = trim($keys[$engine['apiKeyEnv']] ?? '');
        if ($key === '') {
            $missing[] = $engine['name'];
            continue;
        }
        $engine['key'] = $key;
        $usable[] = $engine;
    }
    if ($missing !== []) {
        $logs[] = '// sans clef : ' . implode(', ', $missing);
    }
    if ($usable === []) {
        $logs[] = '// aucun moteur disponible';
        return ['ok' => false, 'logs' => $logs];
    }
    if (!function_exists('curl_init')) {
        $logs[] = '// extension curl absente sur cet hebergement';
        return ['ok' => false, 'logs' => $logs];
    }
    $deadline = microtime(true) + 36;
    foreach ($usable as $engine) {
        if (microtime(true) >= $deadline) {
            $logs[] = '// temps depasse, arret des bascules';
            break;
        }
        $envModel = getenv($engine['modelEnv']);
        $first = is_string($envModel) && trim($envModel) !== '' ? trim($envModel) : $engine['defaultModel'];
        $models = [$first];
        foreach ($engine['models'] as $model) {
            if ($model !== $first) {
                $models[] = $model;
            }
        }
        $tried = 0;
        foreach ($models as $model) {
            if ($tried >= 2) {
                break;
            }
            $tried++;
            $logs[] = '// tentative : ' . $engine['name'] . ' / ' . $model;
            $result = rebecca_call_model($engine, $model, $engine['key'], $messages);
            if ($result['ok']) {
                $logs[] = '// moteur retenu : ' . $engine['name'] . ' / ' . $model;
                return [
                    'ok' => true,
                    'text' => $result['text'],
                    'engineName' => $engine['name'],
                    'model' => $model,
                    'logs' => $logs,
                ];
            }
            $why = $result['modelMiss']
                ? 'modele indisponible'
                : (rebecca_should_skip($result['status'], $result['detail']) ? 'quota, credit ou indisponible, bascule' : 'echec, bascule');
            $logs[] = '// echec ' . $engine['name'] . ' : HTTP ' . ($result['status'] ?: 'reseau') . ' / ' . $why . ' / ' . $result['detail'];
            if (!$result['modelMiss']) {
                break;
            }
        }
    }
    $logs[] = '// tous les moteurs ont echoue, aucune reponse inventee';
    return ['ok' => false, 'logs' => $logs];
}

function rebecca_merge(array $current, array $extra): array
{
    $all = $current;
    foreach ($extra as $block) {
        $overlap = false;
        foreach ($all as $known) {
            if ($block['startLine'] <= $known['endLine'] && $block['endLine'] >= $known['startLine']) {
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $all[] = $block;
        }
    }
    usort($all, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice($all, 0, 5);
}

function rebecca_absence(array $terms): string
{
    $subject = trim(implode(' ', $terms));
    if ($subject === '') {
        return "Rien dans le fichier pour cette demande, meme en elargissant (nom seul, sans accent). Je n'invente pas la suite.";
    }
    return "Rien dans le fichier pour {$subject}, meme en elargissant (nom seul, sans accent). Je n'invente pas la suite.";
}

function rebecca_carry(string $question, array $history): array
{
    $own = rebecca_terms($question);
    if ($own === []) {
        return [];
    }
    $folded = rebecca_fold($question);
    $pronoun = preg_match('/\b(elle|elles|ils|lui|son|ses|leur|leurs)\b/', $folded) === 1;
    if (!$pronoun && count($own) >= 2) {
        return [];
    }
    $carried = [];
    $previous = [];
    foreach ($history as $turn) {
        if (($turn['role'] ?? '') === 'user' && is_string($turn['content'] ?? null)) {
            $previous[] = $turn['content'];
        }
    }
    foreach (array_slice($previous, -2) as $text) {
        foreach (rebecca_terms($text) as $term) {
            if (!in_array($term, $own, true) && !in_array($term, $carried, true)) {
                $carried[] = $term;
            }
        }
    }
    return array_slice($carried, 0, 4);
}

function rebecca_needs_more(string $reply): bool
{
    $folded = rebecca_fold($reply);
    if (mb_strlen($folded) > 700) {
        return false;
    }
    return rebecca_denies($reply)
        || str_contains($folded, 'ces passages ne')
        || str_contains($folded, 'ne le disent pas')
        || str_contains($folded, 'rien dans le fichier');
}

function rebecca_phrase(array $terms, array $sources): string
{
    $wanted = array_fill_keys($terms, true);
    $found = [];
    foreach ($sources as $source) {
        if (!is_string($source) || $source === '') {
            continue;
        }
        preg_match_all("/[\\p{L}\\p{N}][\\p{L}\\p{N}'’-]+/u", $source, $matches);
        foreach ($matches[0] as $word) {
            $folded = rebecca_fold($word);
            if (isset($wanted[$folded]) && !isset($found[$folded])) {
                $found[$folded] = $word;
            }
        }
    }
    $words = [];
    foreach ($terms as $term) {
        if (isset($found[$term])) {
            $words[] = $found[$term];
        }
    }
    return implode(' ', $words);
}

function rebecca_finish(array $result, string $question, array $history, bool $deep): array
{
    $result['suggest'] = null;
    if ($deep || !rebecca_needs_more((string) ($result['reply'] ?? ''))) {
        return $result;
    }
    $terms = [];
    foreach (array_merge(rebecca_carry($question, $history), rebecca_terms($question)) as $term) {
        if (!in_array($term, $terms, true)) {
            $terms[] = $term;
        }
    }
    $sources = [$question];
    foreach ($history as $turn) {
        if (($turn['role'] ?? '') === 'user') {
            $sources[] = (string) ($turn['content'] ?? '');
        }
    }
    $phrase = rebecca_phrase($terms, $sources);
    if ($phrase !== '') {
        $result['suggest'] = $phrase;
    }
    return $result;
}

function rebecca_run(string $userMessage, array $history, array $keys, string $preferred, bool $deep = false): array
{
    $logs = ['// question : ' . mb_substr($userMessage, 0, 180)];
    if ($deep) {
        $logs[] = '// recherche complete demandee';
    }
    $memory = rebecca_memory();
    if (!$memory['ok']) {
        $logs[] = '// fichier memoire illisible a cote de ce dossier';
        return rebecca_finish([
            'reply' => "Le fichier memoire n'est pas lisible depuis le serveur. Je ne brode pas.",
            'logs' => $logs,
            'blocks' => [],
            'engine' => null,
            'model' => null,
        ], $userMessage, $history, $deep);
    }
    $probe = $userMessage;
    $carried = rebecca_carry($userMessage, $history);
    if ($carried !== []) {
        $probe = implode(' ', $carried) . ' ' . $userMessage;
        $logs[] = '// suivi : reprise du tour precedent : ' . implode(', ', $carried);
    }
    $first = rebecca_search($memory['text'], $probe, $deep ? 14000 : 7800, $deep);
    $logs = array_merge($logs, $first['logs']);
    $social = $first['terms'] === [];
    if (!$social && $first['blocks'] === []) {
        $logs[] = '// pas d appel moteur : fichier silencieux, reponse figee';
        return rebecca_finish([
            'reply' => rebecca_absence($first['terms']),
            'logs' => $logs,
            'blocks' => [],
            'engine' => null,
            'model' => null,
        ], $userMessage, $history, $deep);
    }
    $blocks = $first['blocks'];
    $corrective = false;
    for ($round = 0; $round < 3; $round++) {
        $messages = rebecca_messages(REBECCA_PERSONA, rebecca_evidence($blocks), $history, $userMessage, $social, $corrective);
        $call = rebecca_complete($messages, $keys, $preferred);
        foreach ($call['logs'] as $line) {
            $logs[] = $round === 0 ? $line : str_replace('// ', '// tour ' . $round . ' / ', $line);
        }
        if (!$call['ok']) {
            return rebecca_finish([
                'reply' => $blocks === []
                    ? "Aucun moteur n'a pu repondre. Rien n'a ete invente."
                    : "Aucun moteur n'a pu reformuler. Je ne brode pas : les passages trouves sont dans le journal.",
                'logs' => $logs,
                'blocks' => $blocks,
                'engine' => null,
                'model' => null,
            ], $userMessage, $history, $deep);
        }
        $extra = rebecca_search_only($call['text']);
        if ($extra !== null && $round < 2 && !$corrective) {
            $logs[] = '// recherche supplementaire : ' . implode(' | ', $extra);
            foreach ($extra as $query) {
                $found = rebecca_search($memory['text'], $query, $deep ? 14000 : 7800, $deep);
                $logs = array_merge($logs, $found['logs']);
                $blocks = rebecca_merge($blocks, $found['blocks']);
            }
            continue;
        }
        if (!$social && $blocks !== [] && rebecca_denies($call['text']) && !$corrective) {
            $logs[] = '// alerte : le modele nie un sujet present dans les blocs, second passage';
            $corrective = true;
            continue;
        }
        if (!$social && $blocks !== [] && rebecca_denies($call['text'])) {
            $logs[] = '// le second passage nie encore : la reponse est ecartee, les blocs restent';
            return rebecca_finish([
                'reply' => "Le moteur contredisait le fichier, je ne garde pas sa phrase. Les passages sont dans le journal, sans ajout.",
                'logs' => $logs,
                'blocks' => $blocks,
                'engine' => $call['engineName'],
                'model' => $call['model'],
            ], $userMessage, $history, $deep);
        }
        return rebecca_finish([
            'reply' => $call['text'],
            'logs' => $logs,
            'blocks' => $blocks,
            'engine' => $call['engineName'],
            'model' => $call['model'],
        ], $userMessage, $history, $deep);
    }
    return rebecca_finish([
        'reply' => "La recherche s'est arretee avant une reponse sure. Rien n'a ete invente.",
        'logs' => $logs,
        'blocks' => $blocks,
        'engine' => null,
        'model' => null,
    ], $userMessage, $history, $deep);
}
