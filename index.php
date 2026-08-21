<?php
    /**
     * r3M3M83r/index.php — Accueil memoire virtuelle Mathieu CHARREYRE
     *
     * Point d'entree public : 3 interfaces.
     *   - Parcours (sections)  -> /r3M3M83r/sections  (data.php via rewrite)
     *   - Rebecca (tchat)       -> rebecca/
     *   - Reformulator (saisie) -> saisie.php
     *
     * Ambiance : equivalent "Halliday Journals" / memoire du createur
     * (Ready Player One) — trois portes d'acces a instructions.md.
     */
?>
<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <title>Memoire virtuelle — Mathieu CHARREYRE</title>
        <link rel="icon" type="image/png" sizes="96x96" href="favicon/favicon-96x96.png">
        <link rel="icon" href="favicon/favicon.svg" type="image/svg+xml" sizes="any">
        <style>
            *{box-sizing:border-box}
            html{min-height:100%; background:#0f172a}
            body {
                position:relative;
                isolation:isolate; /* <-- LA CLÉ */
                margin:0;
                font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
                background: linear-gradient(160deg,#0f172a 0%,#1e293b 45%,#0f172a 100%);
                color:#e2e8f0;min-height:100vh;display:flex;flex-direction:column
            }
            body::before {
                content:"";
                position:fixed;
                inset:0; /* pas -4% */
                background: url('picts/dark_library_bokeh.webp?v=1') center / cover no-repeat;
                opacity:.18; /* 0.18 = clin d'oeil, 0.28 = démo */
                filter: blur(.3px) brightness(.9) saturate(.9);
                pointer-events:none;
                z-index:-1; /* pas -2 */
            }
            body::after {
                content:"";
                position:fixed;
                inset:0;
                background: radial-gradient(120% 120% at 50% 0%, rgba(56,189,248,.08) 0%, transparent 58%, rgba(0,0,0,.6) 92%);
                pointer-events:none;
                z-index:-1;
            }
            .wrap{max-width:920px;margin:0 auto;padding:2.5rem 1.25rem 2rem;flex:1; position:relative; z-index:1}
            header{text-align:center;margin-bottom:2.2rem}
            header h1{
                font-size:1.75rem;
                font-weight:700;
                margin:0 0 .5rem;
                letter-spacing:.02em;
                line-height:1.15;
            }
            header h1 .l1,
            header h1 .l2,
            header h1 .l3{
                display:inline;
            }
            header h1 .l2{
                margin: 0 .25em; /* espace de sécurité sur desktop */
            }
            header p{margin:0;color:#94a3b8;font-size:1.02rem;line-height:1.5;max-width:36rem;margin-inline:auto}
            .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.1rem}
            a.card{
                display:flex;
                flex-direction:column;
                gap:.55rem;
                padding:1.35rem 1.25rem;
                background: rgba(30,41,59,.84);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                border:1px solid #334155;
                border-radius:14px;
                text-decoration:none;
                color:inherit;
                transition:border-color .15s,transform .15s,background .15s;
                min-height:9.5rem
            }
            a.card:hover{border-color:#38bdf8;background:rgba(51,65,85,.95);transform:translateY(-2px)}
            a.card .label{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#38bdf8;font-weight:600}
            a.card h2{margin:0;font-size:1.2rem;font-weight:650;color:#f8fafc}
            a.card p{margin:0;font-size:.92rem;color:#94a3b8;line-height:1.45;flex:1}
            a.card .go{font-size:.88rem;color:#7dd3fc;font-weight:600}
            footer{text-align:center;padding:1rem 1.25rem 1.4rem;font-size:.8rem;color:#64748b;line-height:1.55}
            footer .disclaimer{display:block;margin-bottom:.35rem;color:#64748b;text}
            footer a{color:#38bdf8;text-decoration:none}
            footer a:hover{text-decoration:underline}

            @media(max-width:800px){
                body::before{opacity:.14}
                header h1 .l1,
                header h1 .l2,
                header h1 .l3{
                    display:block;
                    text-align:center;
                }
                header h1 .l2{
                    font-size:.85em;
                    font-weight:400;
                    opacity:.7;
                    margin:.15em 0;
                    letter-spacing:.04em;
                }
                .grid{grid-template-columns:1fr}
            }
        </style>
    </head>
    <body>
        <div class="wrap">
            <header>
                <h1>
                    <span class="l1">Les journaux</span>
                    <span class="l2">de</span>
                    <span class="l3">Mathieu CHARREYRE</span>
                </h1>
                <p>Trois portes d'entree sur sa memoire virtuelle.</p>
            </header>
            <div class="grid">
                <a class="card" href="sections" target="_blank" rel="noopener noreferrer" title="Fichier mémoriel de Mathieu CHARREYRE">
                    <span class="label">Parcours</span>
                    <h2>Mots-clefs</h2>
                    <p>Parcourir instructions.md par sections et occurrences litterales, sans interpretation LLM.</p>
                    <span class="go">Ouvrir le parcours →</span>
                </a>
                <a class="card" href="rebecca/" target="_blank" rel="noopener noreferrer" title="Tchat avec Rebecca — Memoire virtuelle de Mathieu CHARREYRE">
                    <span class="label">Tchat</span>
                    <h2>Rebecca</h2>
                    <p>Discuter naturellement. Rebecca interroge la memoire quand c'est pertinent, sinon repond en conversation.</p>
                    <span class="go">Ouvrir le tchat →</span>
                </a>
                <a class="card" href="reformulator/saisie.php" target="_blank" rel="noopener noreferrer" title="Saisie — Memoire virtuelle de Mathieu CHARREYRE">
                    <span class="label">Saisie</span>
                    <h2>Reformulator</h2>
                    <p>Interroger, reformuler, proposer un emplacement, comparer / fusionner des notes avec le fichier.</p>
                    <span class="go">Ouvrir la saisie →</span>
                </a>
            </div>
        </div>
        <footer>
            <span class="disclaimer">Verifier les faits importants dans le <a href="https://mathieu.charreyre.net/r3M3M83r/instructions.md" target="_blank" rel="noopener noreferrer" title="Fichier mémoriel de Mathieu CHARREYRE">fichier source</a>.</span>
            Projet <a href="https://mathieu.charreyre.net/r3M3M83r" title="r3M3M83r" title="r3M3M83r">r3M3M83r</a>
        </footer>
    </body>
</html>