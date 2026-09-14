# Doctrine r3M3M83r

Source unique des principes. A relire AVANT toute modification.
Date d'ancrage : 14/09/2026.

Les commentaires "REGLES D'OR" dans `moteurs/functions.php`, `moteurs/server.js`
et `rebecca/Chat-Prompts.js` restent des rappels locaux. En cas de conflit,
CE fichier gagne.

---

## 0. Ce que c'est

r3M3M83r = memoire personnelle de Mathieu CHARREYRE.
- Verite = `instructions.md` (il GROSSIT, il ne se resume pas).
- Rebecca = tchat qui interroge cette memoire.
- Reformulator = saisie / fusion / emplacement, meme memoire, memes moteurs.

Le LLM n'est PAS la memoire. Il ne fait que formuler une reponse a partir
d'extraits deja selectionnes en local.

---

## 1. Interdits (non negociables)

1. **Aucun nom propre dans le code.** Ni prenom, ni lieu, ni parcelle, ni animal.
   Le parseur doit repondre a n'importe quelle question future sans retoucher
   le PHP. Si demain un etre cher disparait, on ne retape pas le moteur.
2. **Aucun `if (la question contient X)` metier.** Les formes detectees sont
   des TYPES (qui-est, inventaire, liste, civil), jamais des sujets.
3. **Aucun scotch cas par cas.** Un correctif qui ne marche que pour une
   question precise est refuse. On corrige la REGLE, pas la reponse.
4. **Aucun moteur payant.** Groq, Cerebras, OpenRouter :free, Gemini free,
   Mistral free. Pas d'OpenAI, pas d'Anthropic, pas de credit.
5. **N'invente rien.** Chaque fait de la reponse doit figurer dans le contexte
   envoye. Sinon : "non mentionne dans le fichier".
6. **Pas de liste de synonymes metier en dur.** L'elargissement d'intention
   (QUERY_EXPAND) est un appel LLM, reserve aux questions FLOUES. Un prenom
   ou un toponyme clair se parse en local.

---

## 2. Architecture (qui fait quoi)

| Fichier | Role | Restart Node ? |
|---|---|---|
| `instructions.md` | Memoire. Unique source de faits. | non |
| `DOCTRINE.md` | Principes. Ce fichier. | non |
| `moteurs/functions.php` | Parse local, preuves, extraits, pont PHP -> Node. | non |
| `moteurs/server.js` | Moteurs LLM, fallback, timeouts, extraction fichiers. | OUI |
| `moteurs/prompts.js` | Tous les prompts LLM. Source unique. | OUI |
| `moteurs/llm.php` | Selection moteur cote PHP. | non |
| `rebecca/index.php` | Tchat, routeur, historique, UI. | non |
| `rebecca/Chat-Prompts.js` | Persona Rebecca + historique (CHAT_ADDON). | non (PHP le relit) |
| `reformulator/saisie.php` | Saisie / fusion. Meme pipeline memoire. | non |

Pipeline unique (saisie ET Rebecca) :

    question
      -> parse local (titres, preuves, extraits)
      -> [LLM expand/select UNIQUEMENT si le local est faible]
      -> 1 appel LLM de reponse (query / query-chat)
      -> affichage + logs

Rebecca et Reformulator ne dupliquent PAS ce pipeline.

---

## 3. Parse local d'abord

Pour une question memoire :

1. Extraire les termes (generique, pas de whitelist de noms).
2. Scorer les blocs de titres (forme de question + IDF + titre exact vs mention).
3. Ranger les preuves (ligne civile, parente, liste).
4. Si le local est FORT (assez d'occurrences + au moins 1 section) :
   **un seul appel LLM** = la reponse. Pas d'expand, pas de select.
5. Si le local est FAIBLE (question floue : "mes cousins cote paternel") :
   expand puis select, puis la reponse.

Le debug UI doit indiquer `parse local (sans expand/select)` dans le cas 4.

Le parseur reconnait des FORMES, jamais des sujets :
- `who`     : "qui est X" -> identite civile en premiere phrase si elle est dans le fichier.
- `inventory` : "liste / cadastre / parcelles" -> bloc entier, liste complete.
- `list`    : "quels sont" -> restitue TOUS les items du bloc, pas 3 exemples.
- `civil`   : lignes "nee le / fils / fille / epouse" boostees comme preuves.

Les titres stub (titre gras suivi de presque rien) se REPLIENT dans le bloc
suivant. On n'envoie pas un titre orphelin au LLM.

---

## 4. LLM : gratuits, fallback, quotas

Ordre par defaut : `groq, cerebras, openrouter, gemini, mistral`.

- Groq est le defaut (rapide, gratuit). Son quota saute : on enchaine.
- Un 429 met le moteur en cooldown (~90 s) et PASSE AU SUIVANT.
  Interdit de retenter le meme moteur dans la meme requete.
- Timeouts PHP : assez longs pour que Node finisse sa chaine.
  - `query` / `query-chat` / `merge-smart` : 90 s
  - `chat-route` / `chat-talk` / expand / select : 25 s
  Ne jamais remettre CURLOPT_TIMEOUT a 12 s : ca tuait le jonglage.
- `max_tokens` pour query / query-chat = 6000.
  **Interdit de recouper Groq a 2000** : ca coupe les listes (cadastre, cousins).
- Reponse LLM vide (tous les moteurs ont echoue, typiquement 429) :
  HTTP 200 + message clair cote tchat :
  "Les moteurs gratuits sont satures pour le moment (quota). Reessaie dans une minute, sans relancer Node."
  **Interdit de renvoyer HTTP 500** : ce n'est pas une panne Node.

---

## 5. Tchat et historique

- L'historique sert aux PRONOMS ("et elle ?", "son pere ?").
- Une question neuve autonome ("Quels sont mes cousins cote paternel")
  ne se colle PAS a la question precedente.
- Si l'historique contredit les PREUVES DIRECTES : les preuves gagnent.
- Salutation courte = CHAT, sans ouvrir `instructions.md` (evite de bruler
  18k tokens + un quota pour un "Salut").
- Question factuelle meme courte = MEMORY.

---

## 6. Prompts

Un seul endroit : `moteurs/prompts.js`.
Editer, puis **Restart Node** dans cPanel.

- FACTUALITY_RULES = n'invente rien.
- STYLE_RULES = CLEF, NENUPHAR, soeurs (o et e separes), noms en MAJUSCULES,
  3e personne pour Mathieu, dates JJ/MM/AAAA.
- QUERY_CHAT_PROMPT = tchat factuel. Listes COMPLETES. "Qui est X" : identite
  d'abord SI elle est dans le contexte.
- CHAT_ADDON (Chat-Prompts.js) = persona + historique. Pas de faits.

Les exemples dans les prompts restent GENERIQUES. Pas de cas nommes.

---

## 7. Logs

- `moteurs/log/requests.log` : une ligne par action UTILISATEUR
  (query, query-chat, chat-talk, rewrite, merge-smart...).
  Pas les etapes internes (chat-route, query-expand, query-select).
- `moteurs/log/error.log` : echecs, 429, reponses vides.
- `moteurs/log/retrieval.log` : forme de question, termes, titres retenus,
  nb de preuves / extraits / caracteres. Sert a debugger SANS recoder un cas.
- Fuseau : Europe/Paris. Une horloge, pas UTC + 2 bricole + toISOString.

---

## 8. Acces public (plus tard)

"Interrogez la memoire de Mathieu" sur mathieu.charreyre.net :
ne PAS brancher Rebecca tel quel. Quota gratuit, pas d'auth, risque de
vider Groq/Cerebras en une apres-midi. Couche publique a concevoir a part
(rate-limit, pas d'historique persistant, sous-ensemble de questions).

---

## 9. Comment modifier

1. Lire CE fichier.
2. Changer la REGLE (parseur, prompt, timeout), jamais un cas.
3. Documenter le correctif dans le fichier touche (date + pourquoi).
4. Si `server.js` ou `prompts.js` : Restart Node. Le PHP est immediat.
5. Tester 3 FORMES, pas 3 noms :
   - une identite ("qui est X")
   - un inventaire ("liste le cadastre de Y")
   - une liste de parentes ("quels sont mes cousins cote Z")
6. Si ca casse : lire retrieval.log + extraits, pas recoder le prenom.

Orthographe dans le code et les commentaires :
CLEF (jamais "cle"), NENUPHAR, soeurs avec o et e separes.
Pas de tiret cadratin, pas d'emoji en dur dans le source.

---

## 10. Correctifs figes (ne pas reintroduire)

| Date | Interdit de remettre |
|---|---|
| 14/09/2026 | CURLOPT_TIMEOUT 12 s sur query-chat |
| 14/09/2026 | 3 appels LLM (expand+select+reponse) quand le local est fort |
| 14/09/2026 | Melange moteurs payants dans le fallback |
| 14/09/2026 | `if (engineName === 'groq') payload.max_tokens = 2000` |
| 14/09/2026 | `http_response_code(500)` quand le LLM est muet (quota) |
| 14/09/2026 | Patchs nommes (prenoms, lieux, animaux) dans functions.php |