<?php
    // Fichier r3M3M83r/saisie.php — Interface de saisie memoire pour reformulator
    // Toute la logique metier (interrogation, extraction, appels Node) vit dans
    // moteurs/functions.php. Ici : HTML, styles, JS d'interface uniquement.
    include_once __DIR__ . '/../moteurs/functions.php';
    include_once __DIR__ . '/../moteurs/llm.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Saisie memoire — CHARREYRE</title>
    <link rel="icon" type="image/png" sizes="96x96" href="../favicon/favicon-96x96.png">
    <link rel="icon" href="../favicon/favicon.svg" type="image/svg+xml" sizes="any">
    <link rel="icon" href="../favicon/favicon.ico">
    <link rel="apple-touch-icon" href="../favicon/apple-touch-icon.png">
    <link rel="manifest" href="../favicon/site.webmanifest">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <style>
        *{box-sizing:border-box}
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;color:#1d1d1d}
        .page{width:100%;max-width:1100px;margin:0 auto;padding:1rem}
        .header{display:flex;flex-wrap:wrap;justify-content:space-between;margin-bottom:1rem}
        .header h1{font-size:1.4rem;margin:0}
        .header .meta{font-size:.95rem;color:#555;line-height:1.5}
        .card{background:#fff;border:1px solid #dadada;border-radius:8px;padding:1rem;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:1rem}
        textarea{width:100%;min-height:200px;border:1px solid #bbb;border-radius:6px;padding:.8rem;font:1rem/1.5rem Arial,Helvetica,sans-serif;resize:vertical}
        .btn-row{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem}
        button{border:none;background:#16275b;color:#fff;padding:.72rem .95rem;border-radius:6px;font-size:.95rem;cursor:pointer;min-width:104px}
        .btn-row button{min-width:110px}
        button:hover{background:#0f1f4a}
        button.ia{background:#0a3d62}
        button.ia:hover{background:#072d4a}
        button.test{background:#2d6aa5}
        button.test:hover{background:#244f7b}
        button.secondary{background:#8795b3;color:#fff;padding:.65rem .85rem;min-width:96px}
        button.secondary:hover{background:#6d7a9b}
        .btn-row-secondary{margin-top:.5rem}
        .msg-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:6px;padding:.7rem 1rem;margin-bottom:.8rem}
        .loading-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.48);display:none;align-items:center;justify-content:center;z-index:2000;padding:1rem}
        .loading-overlay.open{display:flex}
        .loading-box{background:#ffffff;display:flex;flex-direction:column;align-items:center;gap:.75rem;padding:1.3rem 1.4rem;border-radius:14px;box-shadow:0 18px 45px rgba(0,0,0,.22);max-width:320px;width:100%;text-align:center}
        .loading-spinner{width:48px;height:48px;border:5px solid rgba(22,39,91,.15);border-top-color:#16275b;border-radius:50%;animation:spin 1s linear infinite}
        .loading-text{font-size:.98rem;color:#16275b;line-height:1.4}
        @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
        .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.72);display:none;align-items:center;justify-content:center;z-index:1000;padding:1rem}
        .modal-overlay.open{display:flex}
        .modal{background:#fff;border-radius:14px;max-width:min(900px,100%);width:auto;min-width:320px;max-height:calc(100vh - 2rem);box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden}
        .modal-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.2rem;background:#0f1f4a;color:#fff}
        .modal-header h2{font-size:1rem;margin:0}
        .modal-close{border:none;background:transparent;color:#fff;font-size:1.1rem;cursor:pointer;padding:.25rem .5rem}
        .modal-body{flex:1;background:#fff;display:flex;flex-direction:column;overflow:hidden}
        #test-output{flex:1;overflow:auto;margin:0; padding:1rem; background:#f7f9ff; color:#111; white-space:pre-wrap; word-break:break-word;}
        .modal-footer{padding:.8rem 1rem;background:#f5f5f5;text-align:right;font-size:.93rem;color:#333}
        @media(max-width:900px){.modal{max-width:calc(100% - 2rem);max-height:calc(100vh - 2rem);border-radius:10px}.modal-header,.modal-footer{padding:.8rem}.btn-row{flex-direction:column}button{width:100%}}
        .msg-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:6px;padding:.7rem 1rem;margin-bottom:.8rem}
        .section-suggestion{font-size:.95rem;margin:.25rem 0;color:#333}
        .blocks{width:100%;display:grid;grid-template-columns:1fr;gap:1rem}
        .block{border:1px solid #d2d2d2;border-radius:8px;padding:1rem;background:#fcfcff}
        .block h2{font-size:1rem;margin:0 0 .65rem}
        .block p{margin:.35rem 0;color:#444}
        .block pre{white-space:pre-wrap;background:#f2f2ff;padding:.85rem;border-radius:6px;border:1px solid #e0e0ff;overflow:auto}
        .block .copy{display:inline-block;margin-top:.5rem;padding:.45rem .8rem;background:#374e8c;color:#fff;border-radius:5px;font-size:.9rem;text-decoration:none;border:none;cursor:pointer}
        .block .copy:hover{background:#2e4475}
        .ia-result{border:1px solid #d2d2d2;border-radius:8px;padding:1rem;background:#fcfcff;margin-bottom:1rem}
        .ia-result h2{font-size:1.05rem;margin:0 0 .8rem}
        .ia-result .block-label{font-size:.88rem;font-style:italic;font-weight:normal;color:#6b7280;margin:0 0 .65rem;letter-spacing:.01em}
        .ia-result pre{white-space:pre-wrap;background:#f7f9ff;padding:.85rem;border-radius:6px;border:1px solid #e0e0ff;overflow:auto;margin:0}
        .debug-panel{margin:.8rem 0 1rem;border:1px solid #d0d8ef;border-radius:8px;background:#f4f6fb;color:#4b5563}
        .debug-panel summary{cursor:pointer;list-style:none;padding:.55rem .85rem;font-size:.88rem;font-style:italic;color:#6b7280;user-select:none}
        .debug-panel summary::-webkit-details-marker{display:none}
        .debug-panel summary::before{content:"▸ ";font-style:normal;color:#9ca3af}
        .debug-panel[open] summary::before{content:"▾ "}
        .debug-panel summary:hover{color:#374151;background:#eef2ff;border-radius:8px}
        .debug-panel pre{margin:0;padding:.65rem .9rem .85rem;font-size:.82rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;color:#4b5563;border-top:1px solid #e5e7eb;background:#fafbff;border-radius:0 0 8px 8px}
        .summary{display:grid;grid-template-columns:1fr;gap:.5rem}
        .summary .item{padding:.8rem;background:#eef2ff;border-radius:6px;border:1px solid #d6dff6}
        .notice{font-size:.94rem;color:#333}
        .footer{text-align:right;font-size:.85rem;color:#666;margin-top:1.2rem}
        .meta-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.55rem;margin-top:.45rem}
        .meta-line{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin:.25rem 0}
        .meta-line + .meta-line{margin-top:.8rem}
        .meta-line strong{margin-right:.5rem}
        .mini-button{font-size:.92rem;padding:.45rem .75rem;border-radius:6px;border:none;background:#16275b;color:#fff;cursor:pointer;white-space:nowrap;min-width:140px;max-width:180px;width:auto !important;transition:transform .14s ease,background .14s ease}
        .mini-button:hover{background:#1f3d83;transform:translateY(-1px)}
        .engine-select-row{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.7rem;padding:.55rem .7rem;background:#f0f3fa;border:1px solid #d0d8ef;border-radius:7px}
        .engine-select-row label{font-size:.93rem;color:#333;white-space:nowrap}
        .engine-select-row select{font-size:.93rem;padding:.35rem .6rem;border:1px solid #b0bdd8;border-radius:5px;background:#fff;color:#1d1d1d;cursor:pointer}
        .engine-badge{display:inline-block;font-size:.82rem;padding:.2rem .55rem;border-radius:4px;background:#d4e4f7;color:#0a3d62;font-weight:bold;white-space:nowrap}
        @media(max-width:720px){.meta-actions{flex-direction:column;align-items:flex-start} .mini-button{width:auto !important}}
        @media(min-width:720px){.blocks{grid-template-columns:1fr 1fr}}
        @media(max-width:520px){.header{flex-direction:column}button{width:100%}}
    </style>
</head>

<body>
<div class="page">
  <div class="header">
    <div>
      <h1>Reformulator - Interface de saisie mémoriel de MaT.</h1>
      <p class="notice">Saisir un texte libre. L'outil propose des blocs rédigés et un classement par section. Aucune modification automatique n'est effectuée.</p>
    </div>
    <div class="meta">
      <p class="meta-line">
        <button type="button" id="reset-page" class="mini-button" title="Recharge la page saisie.php à zéro, sans contexte ni historique">Reset</button>
      </p>
      <p class="meta-line">
        Cible analysee : <strong><a href="<?php echo html_escape($source_url ?: basename(SOURCE_FILE)); ?>" target="_blank" rel="noopener noreferrer" title="Ouvrir <?php echo html_escape(basename(SOURCE_FILE)); ?> dans un nouvel onglet"><?php echo html_escape(basename(SOURCE_FILE)); ?></a></strong>
        <button type="button" id="load-instructions" class="mini-button" title="Recharger le contexte du fichier d'instructions">Recharger contexte</button>
      </p>
      <?php if ($source_date !== ''): ?>
      <p>Date du fichier cible : <?php echo html_escape($source_date); ?></p>
      <?php endif; ?>
      <div class="instructions-status-container">
        <?php if ($instructions_loaded && $instructions_line_count > 0): ?>
          <div class="msg-ok" style="margin:0 0 1rem 0;">Contexte d'instructions chargé — <?php echo html_escape((string)$instructions_line_count); ?> lignes lues.</div>
        <?php endif; ?>
      </div>
      <p>La reformulation avancée utilise des services LLM externes.</p>
      <div class="meta-actions">
        <p style="margin:0">Moteur LLM demandé : <strong><?php
            $defaultEngine = strtolower($llmInfo['defaultEngine'] ?? 'mistral');
            $engineName = html_escape(strtoupper($llmInfo['engineName'] ?? $defaultEngine));
            $modelName = html_escape($llmInfo['selectedModel'] ?? 'inconnu');
            $displayText = $engineName . ' (' . $modelName . ')';

            if ($selected_engine !== '') {
                $displayText = html_escape(strtoupper($selected_engine)) . ' <em>(manuel)</em>';
            }

            $engineUrl = $llmInfo['engineUrl'] ?? '';
            if ($engineUrl !== '') {
                echo '<a href="' . html_escape($engineUrl) . '" target="_blank" rel="noopener noreferrer">' . $displayText . '</a>';
            } else {
                echo $displayText;
            }
        ?></strong></p>
        <button type="button" id="open-test-modal" class="mini-button" title="Tester le moteur LLM">Tester</button>
      </div>
    </div>
  </div>

  <?php if (!($llmInfo['reachable'] ?? true)):
        $diagnosis = $llmInfo['diagnosis'] ?? 'down';
        $diagnosisDetail = $llmInfo['diagnosis_detail'] ?? ($llmInfo['last_error'] ?? '');
  ?>

  <div class="msg-err" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:.3rem;">
    <?php if ($diagnosis === 'blocked'): ?>
      <span>&#9888;&#65039; <strong>Requête bloquée avant d'atteindre Node.js</strong> (probable filtre mod_security o2switch) — le service tourne peut-être normalement.</span>
    <?php elseif ($diagnosis === 'route'): ?>
      <span>&#9888;&#65039; <strong>Route /llm-info introuvable.</strong> Node.js tourne peut-être, mais cette route n'existe pas côté serveur.</span>
    <?php elseif ($diagnosis === 'app_error'): ?>
      <span>&#9888;&#65039; <strong>Node.js a répondu avec une erreur interne.</strong> Le service tourne, mais plante sur cette requête.</span>
    <?php elseif ($diagnosis === 'malformed'): ?>
      <span>&#9888;&#65039; <strong>Réponse inattendue du service.</strong> Node.js a répondu (HTTP 200) mais le contenu n'est pas exploitable.</span>
    <?php elseif ($diagnosis === 'unknown'): ?>
      <span>&#9888;&#65039; <strong>Échec non catégorisé.</strong> Voir le détail technique ci-dessous.</span>
      <a href="<?php echo html_escape(CPANEL_URL); ?>" target="_blank" rel="noopener noreferrer" style="background:#0a3d62;color:#fff;padding:.45rem .85rem;border-radius:6px;text-decoration:none;white-space:nowrap;font-size:.9rem;font-weight:bold;">Ouvrir cPanel o2switch &#8594;</a>
    <?php else: ?>
      <span>&#9888;&#65039; <strong>Service Node.js inaccessible.</strong> La reformulation IA ne fonctionnera pas tant que le service n&rsquo;est pas d&eacute;marr&eacute;.</span>
      <a href="<?php echo html_escape(CPANEL_URL); ?>" target="_blank" rel="noopener noreferrer" style="background:#0a3d62;color:#fff;padding:.45rem .85rem;border-radius:6px;text-decoration:none;white-space:nowrap;font-size:.9rem;font-weight:bold;">Ouvrir cPanel o2switch &#8594;</a>
    <?php endif; ?>
  </div>
  <div class="msg-err" style="font-size:.9rem;margin-top:-.4rem;margin-bottom:.8rem;">
    <?php if ($diagnosis === 'down'): ?>
      Dans cPanel &#8594; <strong>Node.js Apps</strong> &#8594; chercher l&rsquo;application <strong>moteurs</strong> (ex-reformulator) &#8594; bouton <strong>Restart</strong>. Si l&rsquo;app n&rsquo;appara&icirc;t pas, elle a peut-&ecirc;tre &eacute;t&eacute; arr&ecirc;t&eacute;e ou d&eacute;sactiv&eacute;e.
      <?php if ($diagnosisDetail !== ''): ?>
        <br><span style="color:#555;">Détail technique : <?php echo html_escape($diagnosisDetail); ?></span>
      <?php endif; ?>
    <?php elseif ($diagnosis === 'app_error'): ?>
      <strong>Détail technique :</strong> <?php echo html_escape($diagnosisDetail); ?>
      <br>Piste fréquente : une dépendance npm ajoutée à <code>package.json</code> sans avoir relancé <strong>"Run NPM Install"</strong> côté cPanel &#8594; Node.js Apps, avant de redémarrer.
    <?php else: ?>
      <strong>Détail technique :</strong> <?php echo html_escape($diagnosisDetail); ?>
    <?php endif; ?>
  </div>

  <?php endif; ?>

  <?php if ($reformule_msg !== ''): ?>
  <div class="<?php echo (str_contains($reformule_msg, 'Erreur')) ? 'msg-err' : 'msg-ok'; ?>">
    <?php echo html_escape($reformule_msg); ?>
  </div>
  <?php endif; ?>

  <?php if ($reformule_original !== ''): ?>
  <div class="ia-result">
    <p class="block-label">Rendu</p>
    <p><strong>Ce que vous écriviez :</strong></p>
    <pre><?php echo html_escape($reformule_original); ?></pre>
    <?php if ($reformule_interpretation !== ''): ?>
      <div style="margin-top:1rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <p style="margin:0"><strong>Mon interprétation :</strong></p>
        <button class="copy" type="button" data-target="interpretation-output">Copier l'interprétation</button>
      </div>
      <pre id="interpretation-output"><?php echo html_escape($reformule_interpretation); ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($proposed_location !== ''): ?>
  <div class="ia-result">
    <p class="block-label">Emplacement proposé</p>
    <pre><?php echo html_escape($proposed_location); ?></pre>
  </div>
  <?php endif; ?>

  <!-- Affichage du résultat de la requête d'interrogation du fichier d'instructions -->
    <?php if ($query_result !== ''): ?>
    <div class="ia-result">
        <p class="block-label">Réponse du fichier d'instructions</p>
        <pre><?php echo html_escape($query_result); ?></pre>
    </div>
    <?php endif; ?>

    <?php if (!empty($merge_result)):
        $mergeParts = function_exists('parse_merge_smart_result')
            ? parse_merge_smart_result($merge_result)
            : ['a_coller' => $merge_result, 'emplacement' => '', 'details' => '', 'raw' => $merge_result];
    ?>
    <div class="ia-result">
        <p class="block-label">Comparer / Fusionner avec la memoire</p>

        <div style="border:2px solid #16275b;border-radius:8px;padding:.85rem 1rem;background:#f0f4ff;margin-bottom:.85rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem;">
            <strong style="color:#16275b;font-size:.95rem;">A coller dans instructions.md</strong>
            <button class="copy" type="button" data-target="merge-a-coller">Copier ce bloc</button>
          </div>
          <pre id="merge-a-coller" style="margin:0;background:#fff;"><?php echo html_escape($mergeParts['a_coller']); ?></pre>
        </div>

        <?php if ($mergeParts['emplacement'] !== ''): ?>
        <div style="border:1px solid #c5d0f0;border-radius:8px;padding:.75rem .9rem;background:#fafbff;margin-bottom:.75rem;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.4rem;">
            <strong style="font-size:.9rem;color:#374151;">Ou le mettre</strong>
            <button class="copy" type="button" data-target="merge-emplacement" style="font-size:.85rem;padding:.35rem .65rem;">Copier</button>
          </div>
          <pre id="merge-emplacement" style="margin:0;font-size:.9rem;"><?php echo html_escape($mergeParts['emplacement']); ?></pre>
        </div>
        <?php endif; ?>

        <?php if ($mergeParts['details'] !== ''): ?>
        <details class="debug-panel" style="margin:0;">
          <summary>Details (deja / nouveau / contradictions)</summary>
          <pre><?php echo html_escape($mergeParts['details']); ?></pre>
        </details>
        <?php elseif ($mergeParts['a_coller'] !== $mergeParts['raw']): ?>
        <details class="debug-panel" style="margin:0;">
          <summary>Reponse brute complete</summary>
          <pre><?php echo html_escape($mergeParts['raw']); ?></pre>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // === NOUVEL AFFICHAGE PROPRE DES STATS ===
    if (isset($localSearch) && !empty($localSearch['sections'])):
    ?>
    <div class="msg-ok" style="margin:1rem 0; padding:1rem; border-radius:8px;">
        <strong>Recherche terminee</strong><br>
        <strong>Moteur :</strong> <?php echo html_escape($selected_engine ?: strtoupper($llmInfo['engineName'] ?? 'AUTO')); ?><br>
        <strong>Occurrences detectees :</strong> <?php echo number_format($localSearch['total_occ'] ?? 0, 0, ',', ' '); ?><br>
        <strong>Sections trouvees :</strong> <?php echo count($localSearch['sections'] ?? []); ?>
    </div>
    <?php endif; ?>

    <?php if ($query_debug !== ''): ?>
    <details class="debug-panel">
      <summary>Debug interrogation (cliquer pour afficher)</summary>
      <pre><?php echo html_escape($query_debug); ?></pre>
    </details>
    <?php endif; ?>

  <div class="card">
    <form method="post" action="" enctype="multipart/form-data">
      <label for="story"><strong>Texte libre</strong></label>
      <textarea id="story" name="story" placeholder="Salut Mathieu, raconte moi une anecdote, à la première personne ; reformulator s'occupe du reste ..." required><?php echo html_escape($input_text); ?></textarea>
      <!-- === Upload de fichiers === -->
        <div style="margin: 15px 0 20px 0; padding: 12px; background: #f8f9ff; border: 1px solid #d0d8ef; border-radius: 8px;">
        <label><strong>Ou charger un fichier à analyser :</strong></label><br>
        <input type="file" id="uploaded_file" name="uploaded_file" accept=".pdf,.docx,.doc,.rtf,.txt,.md" style="margin-top:8px;">
        <small style="color:#555; display:block; margin-top:4px;">(Optionnel) PDF, DOCX, DOC, RTF, TXT, MD — max 15 Mo</small>
        </div>
      <p class="notice">Le bouton "Reformulation avancee avec IA" corrige, reformule et transpose à la troisième personne, "Proposer emplacement" propose où ranger le souvenir dans le fichier d'instructions, et “Interroger” interroge le fichier d'instructions pour répondre à une question.</p>
      <input type="hidden" name="instructions_context" value="<?php echo html_escape($instructions_context); ?>">
      <!-- Selection moteur : moteurs/llm.php (partage Rebecca / Reformulator) -->
      <div class="engine-select-row">
        <label for="selected_engine">Moteur IA :</label>
        <select id="selected_engine" name="selected_engine">
          <?php echo llm_render_engine_options($selected_engine, $llmInfo); ?>
        </select>
        <?php if ($selected_engine !== ''): ?>
          <span class="engine-badge"><?php echo html_escape(strtoupper($selected_engine)); ?> selectionne</span>
        <?php endif; ?>
      </div>
      <div class="btn-row">
            <button type="button" id="extract-file-btn" class="test">Charger & Extraire fichier</button>
            <button type="submit" name="query_instructions" class="test" title="Interroger le fichier d'instructions">Interroger le fichier</button>
            <button type="submit" name="merge_smart" class="test" title="Comparer le texte du champ avec la memoire et proposer une version fusionnee pret a coller">Comparer / Fusionner</button>
            <button type="submit" name="proposer_emplacement" class="test" title="Proposer un emplacement dans le fichier d'instructions">Proposer emplacement</button>
            <button type="submit" name="reformuler" class="ia" title="Reformuler le texte en utilisant le moteur LLM">Reformulation avancee avec IA</button>
        </div>
    </form>
  </div>

  <?php if ($feedback !== ''): ?>
  <div class="card">
    <div class="summary">
      <div class="item"><strong>Resultat</strong><br><?php echo html_escape($feedback); ?></div>
      <div class="item"><strong>Instructions de securite</strong><br>Copier les blocs qui conviennent, puis inserer manuellement dans le fichier memoriel ou le fichier d'instructions.</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal-overlay" id="test-modal">
    <div class="modal">
      <div class="modal-header">
        <h2>Test du moteur de reformulation</h2>
        <button class="modal-close" id="close-test-modal" type="button">Fermer</button>
      </div>
      <div class="modal-body">
        <pre id="test-output" style="margin:0; padding:1rem; background:#f7f9ff; color:#111; overflow:auto; min-height:240px; white-space:pre-wrap; word-break:break-word;">Chargement du benchmark en cours ...</pre>
      </div>
      <div class="modal-footer" id="modal-footer" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
        <span style="font-size:.9rem;">Si le chargement n&rsquo;aboutit pas : <a id="modal-fallback" href="../moteurs/test_curl.php" target="_blank" rel="noopener noreferrer" style="color:#0f1f4a;text-decoration:underline;">Ouvrir dans un nouvel onglet</a></span>
        <button id="copy-test-output" type="button" style="background:#374e8c;color:#fff;border:none;padding:.4rem .85rem;border-radius:5px;cursor:pointer;font-size:.9rem;flex-shrink:0;">Copier le rapport</button>
      </div>
    </div>
  </div>

  <?php if (!empty($blocks)): ?>
  <div class="blocks">
    <?php foreach ($blocks as $block): ?>
      <section class="block" id="<?php echo html_escape($block['id']); ?>">
        <h2>Bloc <?php echo html_escape(substr($block['id'], 6)); ?></h2>
        <p class="section-suggestion"><strong>Section proposee :</strong> <?php echo html_escape($block['section']); ?></p>
        <?php foreach ($block['suggestions'] as $suggestion): ?>
          <p class="notice"><?php echo html_escape($suggestion); ?></p>
        <?php endforeach; ?>
        <p><strong>Texte propose :</strong></p>
        <pre id="output-<?php echo html_escape($block['id']); ?>"><?php echo html_escape($block['rewrite']); ?></pre>
        <button class="copy" type="button" data-target="output-<?php echo html_escape($block['id']); ?>">Copier le bloc redige</button>
        <details>
          <summary>Voir le texte original</summary>
          <pre><?php echo html_escape($block['content']); ?></pre>
        </details>
      </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="footer">
    <p>Pour plus de securite, inserer toujours a la main dans le fichier cible.</p>
    <p style="font-size:.82rem; margin-top:.5rem; color:#444;">
      <a href="<?php echo html_escape(CPANEL_URL); ?>" target="_blank" rel="noopener noreferrer" alt="Ouvrir cPanel o2switch Node.js" title="Ouvrir le cPanel o2switch Node.js">Ouvrir cPanel o2switch</a>
      •
      <a href="../moteurs/log_proxy.php?name=requests_log" target="_blank" rel="noopener noreferrer" alt="Voir les requêtes effectuées" title="Voir les requêtes effectuées">Voir les requêtes</a>
      •
      <a href="../moteurs/log_proxy.php?name=error_log" target="_blank" rel="noopener noreferrer" alt="Voir les retours d'erreurs" title="Voir les retours d'erreurs">Voir les erreurs</a>
    </p>
  </div>
</div>
<div class="loading-overlay" id="loading-overlay" aria-hidden="true">
  <div class="loading-box">
    <div class="loading-spinner" aria-hidden="true"></div>
    <div class="loading-text">Traitement en cours… Merci de patienter.</div>
  </div>
</div>
<script>
for (const button of document.querySelectorAll('.copy')) {
    button.addEventListener('click', function () {
        const target = document.getElementById(this.dataset.target);
        if (!target) return;
        navigator.clipboard.writeText(target.textContent).then(() => {
            const originalText = button.textContent;
            button.textContent = 'Copie effectuee';
            setTimeout(() => { button.textContent = originalText; }, 1600);
        }).catch(() => {
            const originalText = button.textContent;
            button.textContent = 'Erreur de copie';
            setTimeout(() => { button.textContent = originalText; }, 1600);
        });
    });
}

const modal = document.getElementById('test-modal');
const output = document.getElementById('test-output');
const openModal = document.getElementById('open-test-modal');
const closeModal = document.getElementById('close-test-modal');
const modalFooter = document.getElementById('modal-footer');

function openTestModal() {
    if (output && modal) {
        output.textContent = 'Chargement du benchmark en cours ...';
        modal.classList.add('open');

        // Récupère le moteur actuellement sélectionné dans le formulaire
        const engineSelect = document.getElementById('selected_engine');
        let engineParam = '';
        if (engineSelect && engineSelect.value !== '') {
            engineParam = '?engine=' + encodeURIComponent(engineSelect.value);
        }

        fetch('../moteurs/test_curl.php' + engineParam, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/plain'
            },
            cache: 'no-store'
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' ' + response.statusText);
                }
                return response.text();
            })
            .then((text) => {
                output.textContent = text;
            })
            .catch((error) => {
                output.textContent = 'Erreur de chargement : ' + error.message + '\n\n' +
                    'Essayer le lien ci-dessous si le chargement direct échoue.';
            });
    }
}

function closeTestModal() {
    if (modal) {
        modal.classList.remove('open');
    }
    if (output) {
        output.textContent = 'Chargement du benchmark en cours ...';
    }
}

const loadInstructionsButton = document.getElementById('load-instructions');
const resetPageButton = document.getElementById('reset-page');
const instructionsStatusContainers = document.querySelectorAll('.instructions-status-container');
const instructionsContextInput = document.querySelector('input[name="instructions_context"]');

const storedInstructions = sessionStorage.getItem('saisie_instructions_context');
const storedInstructionsLoaded = sessionStorage.getItem('saisie_instructions_loaded') === '1';
const storedInstructionsLineCount = parseInt(sessionStorage.getItem('saisie_instructions_line_count') || '0', 10);
if (storedInstructions) {
    instructionsContextInput.value = storedInstructions;
}
if (storedInstructionsLoaded) {
    showInstructionsLoadedStatus(storedInstructionsLineCount);
    if (loadInstructionsButton) {
        loadInstructionsButton.textContent = 'Recharger';
    }
} else {
    hideInstructionsLoadedStatus();
    if (loadInstructionsButton) {
        loadInstructionsButton.textContent = 'Charger instructions';
        // Chargement automatique du contexte au premier affichage de la page
        setTimeout(function() { if (loadInstructionsButton) loadInstructionsButton.click(); }, 0);
    }
}

if (loadInstructionsButton) {
    loadInstructionsButton.addEventListener('click', function () {
        // Reset any previous instructions state before reloading from zero.
        sessionStorage.removeItem('saisie_instructions_context');
        sessionStorage.removeItem('saisie_instructions_loaded');
        sessionStorage.removeItem('saisie_instructions_line_count');
        hideInstructionsLoadedStatus();
        instructionsContextInput.value = '';
        loadInstructionsButton.disabled = true;
        loadInstructionsButton.textContent = 'Rechargement...';
        fetch(window.location.pathname + '?action=load_instructions', {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        })
            .then((response) => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then((data) => {
                showInstructionsLoadedStatus(data.lines || 0);
                sessionStorage.setItem('saisie_instructions_context', data.context);
                sessionStorage.setItem('saisie_instructions_loaded', '1');
                sessionStorage.setItem('saisie_instructions_line_count', String(data.lines || 0));
                instructionsContextInput.value = data.context;
                loadInstructionsButton.textContent = 'Recharger';
            })
            .catch((error) => {
                console.error(error);
                loadInstructionsButton.textContent = 'Erreur de chargement';
            })
            .finally(() => {
                loadInstructionsButton.disabled = false;
            });
    });
}

if (resetPageButton) {
    resetPageButton.addEventListener('click', function () {
        sessionStorage.removeItem('saisie_instructions_context');
        sessionStorage.removeItem('saisie_instructions_loaded');
        sessionStorage.removeItem('saisie_instructions_line_count');
        window.location.href = window.location.pathname;
    });
}

const form = document.querySelector('form');
const loadingOverlay = document.getElementById('loading-overlay');
if (form) {
    form.addEventListener('submit', function () {
        const stored = sessionStorage.getItem('saisie_instructions_context');
        if (instructionsContextInput && stored) {
            instructionsContextInput.value = stored;
        }
        if (loadingOverlay) {
            loadingOverlay.classList.add('open');
        }
    });
}

function hideLoadingOverlay() {
    if (loadingOverlay) {
        loadingOverlay.classList.remove('open');
    }
}

function showInstructionsLoadedStatus(lineCount) {
    lineCount = lineCount || 0;
    if (!instructionsStatusContainers) return;
    instructionsStatusContainers.forEach(function(container) {
        if (container) {
            var lineInfo = lineCount > 0 ? ' \u2014 ' + lineCount + ' lignes lues' : '';
            container.innerHTML = '<div class="msg-ok" style="margin:0 0 1rem 0;">Contexte d\'instructions charg\u00e9' + lineInfo + '.</div>';
        }
    });
}

function hideInstructionsLoadedStatus() {
    if (!instructionsStatusContainers) return;
    instructionsStatusContainers.forEach((container) => {
        if (container) {
            container.innerHTML = '';
        }
    });
}

function renderInstructions() {
    showInstructionsLoadedStatus();
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

if (openModal) {
    openModal.addEventListener('click', openTestModal);
}

// Mise à jour dynamique du moteur affiché en haut
const engineSelect = document.getElementById('selected_engine');
const engineDisplay = document.querySelector('.meta-actions p strong');

function updateEngineDisplay() {
    if (!engineSelect || !engineDisplay) return;

    const selectedValue = engineSelect.value.trim();
    let displayText = '';

    if (selectedValue === '') {
        // Auto = ce que le serveur PHP nous dit
        const currentEngine = '<?php echo html_escape(strtoupper($llmInfo['engineName'] ?? $llmInfo['defaultEngine'] ?? 'MISTRAL')); ?>';
        displayText = currentEngine + ' (défaut)';
    } else {
        displayText = selectedValue.toUpperCase() + ' (manuel)';
    }

    engineDisplay.textContent = displayText;

    // Petit effet visuel
    engineDisplay.style.transition = 'color 0.4s';
    engineDisplay.style.color = '#0a3d62';
    setTimeout(() => { engineDisplay.style.color = ''; }, 1200);
}

// Appel initial
if (engineSelect && engineDisplay) {
    engineSelect.addEventListener('change', updateEngineDisplay);
    setTimeout(updateEngineDisplay, 200);  // Mise à jour immédiate
}

if (closeModal) {
    closeModal.addEventListener('click', closeTestModal);
}
if (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeTestModal();
    });
}

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal && modal.classList.contains('open')) {
        closeTestModal();
    }
});

const copyTestOutputButton = document.getElementById('copy-test-output');
if (copyTestOutputButton) {
    copyTestOutputButton.addEventListener('click', function () {
        var text = output ? output.textContent : '';
        navigator.clipboard.writeText(text).then(function () {
            var orig = copyTestOutputButton.textContent;
            copyTestOutputButton.textContent = 'Copie effectuee !';
            setTimeout(function () { copyTestOutputButton.textContent = orig; }, 1800);
        }).catch(function () {
            var orig = copyTestOutputButton.textContent;
            copyTestOutputButton.textContent = 'Erreur de copie';
            setTimeout(function () { copyTestOutputButton.textContent = orig; }, 1800);
        });
    });
}

// Extraction seule du fichier (fetch hors submit formulaire :
// il faut ouvrir manuellement le meme overlay de chargement).
document.getElementById('extract-file-btn').addEventListener('click', function() {
  const fileInput = document.getElementById('uploaded_file');
  if (!fileInput.files || fileInput.files.length === 0) {
    alert("Veuillez choisir un fichier d'abord.");
    return;
  }

  const formData = new FormData();
  formData.append('uploaded_file', fileInput.files[0]);

  const btn = this;
  const originalText = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Extraction en cours...';
  if (loadingOverlay) {
    loadingOverlay.classList.add('open');
  }

  fetch('saisie.php?extract_only=1', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success && data.text) {
      document.getElementById('story').value = data.text;
      alert("Fichier extrait.");
    } else {
      alert("Erreur : " + (data.error || "Impossible d'extraire (Node.js ? )"));
    }
  })
  .catch(err => {
    alert("Erreur serveur : " + err);
  })
  .finally(() => {
    btn.disabled = false;
    btn.textContent = originalText;
    if (loadingOverlay) {
      loadingOverlay.classList.remove('open');
    }
  });
});
</script>
</body>
</html>