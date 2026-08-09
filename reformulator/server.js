/**
 * reformulator/server.js
 * Version avec support upload de fichiers + Mistral par defaut + logique dynamique modeles
 * Mise a jour 03/08/2026 - Mathieu CHARREYRE
 *
 * REGLES D'OR (a relire avant toute modification) :
 *   1. Documenter chaque correctif dans ce fichier (commentaires sans accents
 *      ni caracteres speciaux pour eviter les bugs d'encodage).
 *   2. Orthographe archaique obligatoire : CLEF (jamais "cle"), NENUPHAR
 *      (jamais "nenufar"), soeurs avec O et E separes (jamais ligature oe).
 *      Pas de tiret cadratin, pas d'emoji en dur dans le code source.
 *   3. Le prompt QUERY doit faire comprendre l'INTENTION de la question
 *      (synthese, decompte, liste) et non se contenter de compter un mot.
 *      Luna est un chien ; une 2CV est une voiture. Les noms propres comptent.
 *   3b. Le prompt SAISIE (bouton Reformulation avancee) doit COMPRENDRE
 *      l'anecdote, la transposer en 3e personne (Mathieu), et synthetiser
 *      intelligemment (ni mot a mot, ni resume seche). Voir SAISIE_PROMPT.
 *   3c. STYLE_RULES (Regles d'Or d'instructions.md) est appende a tous les
 *      prompts qui produisent du texte francais : SAISIE, QUERY, LOCATION,
 *      MERGE_CHECK, MERGE_SMART. CLEF, NENUPHAR, noms en MAJUSCULES, etc.
 *   3d. Bouton Comparer/Fusionner (purpose=merge-smart) : contexte memoire
 *      comme Interroger + texte nouveau du champ -> fusion prete a coller
 *      + emplacement. Voir MERGE_SMART_PROMPT et merge_smart_via_node().
 *   4. Les modeles OpenRouter :free tournent souvent (HTTP 404). Preferer
 *      openrouter/free ou verifier la disponibilite avant de changer le defaut.
 *   5. Ne jamais laisser une erreur Multer ou Express renvoyer du HTML :
 *      toujours repondre en JSON et logger dans reformulator/log/error.log.
 *
 * CORRECTIF 04/07/2026 :
 *   - Creation explicite du dossier uploads/ avant Multer.
 *   - Middleware d'erreur Multer + middleware Express global (reponse JSON).
 *   - Route de compatibilite sans double parse multipart.
 */

const fs = require('fs');
const path = require('path');
const express = require('express');
const axios = require('axios');
const dotenv = require('dotenv');
const cors = require('cors');
const { execFileSync } = require('child_process');

const multer = require('multer');
const pdf = require('pdf-parse');
const mammoth = require('mammoth');
const WordExtractor = require('word-extractor'); // CORRECTIF 05/07/2026 (v3) : support .doc

dotenv.config();
process.env.TZ = 'Europe/Paris';

const LOG_DIR = path.join(__dirname, 'log');
const ERROR_LOG_FILE = path.join(LOG_DIR, 'error.log');
const REQUESTS_LOG_FILE = path.join(LOG_DIR, 'requests.log');
const LEGACY_ERROR_LOG_FILE = path.join(__dirname, '..', 'error_log');

// ==================== LOGGING ====================
const formatTimestamp = () => {
  const now = new Date();
  now.setHours(now.getHours() + 2);
  return now.toISOString().replace('T', ' ').replace(/\.\d{3}Z$/, '');
};
const writeLog = (filePath, line) => {
  try {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    fs.appendFileSync(filePath, line, 'utf8');
  } catch (writeError) {
    console.error("Impossible d'ecrire dans " + filePath + ":", writeError && writeError.stack ? writeError.stack : String(writeError));
  }
};
const logError = (message) => {
  const line = '[' + formatTimestamp() + '] ' + message.replace(/\r?\n/g, ' ') + '\n';
  writeLog(ERROR_LOG_FILE, line);
  console.error(message);
};
const logRequest = (req) => {
  const clientIp = (req.headers['x-forwarded-for'] || req.ip || (req.socket && req.socket.remoteAddress) || 'inconnue').toString().split(',')[0].trim();
  const textLength = typeof (req.body && req.body.text) === 'string' ? req.body.text.length : 0;
  const line = '[' + formatTimestamp() + '] ' + req.method + ' ' + req.originalUrl + ' IP=' + clientIp + ' text_length=' + textLength + '\n';
  writeLog(REQUESTS_LOG_FILE, line);
};
const ensureLogFile = (filePath) => {
  try {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    if (!fs.existsSync(filePath)) {
      fs.writeFileSync(filePath, '', { flag: 'a', encoding: 'utf8' });
      try { fs.chmodSync(filePath, 0o644); } catch (e) {}
    }
  } catch (error) {
    console.error('Impossible de creer ' + filePath + ':', error && error.stack ? error.stack : String(error));
  }
};
const migrateOldErrorLog = () => {
  // Migration ancien log
  if (fs.existsSync(LEGACY_ERROR_LOG_FILE) && !fs.existsSync(ERROR_LOG_FILE)) {
    try { fs.renameSync(LEGACY_ERROR_LOG_FILE, ERROR_LOG_FILE); } catch (e) {}
  }
};

migrateOldErrorLog();
ensureLogFile(ERROR_LOG_FILE);
ensureLogFile(REQUESTS_LOG_FILE);

process.on('uncaughtException', (error) => {
  logError('uncaughtException: ' + (error && error.stack ? error.stack : String(error)));
});
process.on('unhandledRejection', (reason) => {
  logError('unhandledRejection: ' + (reason && reason.stack ? reason.stack : String(reason)));
});

// ==================== APP & UPLOAD ====================
const app = express();
app.use(cors());
// CORRECTIF 05/07/2026 (v2) : la limite par defaut d'express.json() est 100kb,
// beaucoup trop petite pour un fichier PDF/DOCX encode en base64 (~+35% de
// volume par rapport au fichier brut). Necessaire pour le contournement du
// blocage mod_security o2switch sur les uploads multipart (voir /reformuler).
app.use(express.json({ limit: '25mb' }));

// CORRECTIF 04/07/2026 : le dossier de destination Multer doit exister et
// être inscriptible AVANT toute requête, sinon Multer échoue silencieusement
// en dehors du handler de route (donc en dehors de logRequest()/logError()).
const UPLOAD_DIR = path.join(__dirname, 'uploads');
try {
  fs.mkdirSync(UPLOAD_DIR, { recursive: true });
} catch (mkdirError) {
  logError("Impossible de creer le dossier uploads (" + UPLOAD_DIR + ") : " + (mkdirError && mkdirError.stack ? mkdirError.stack : String(mkdirError)));
}

const upload = multer({
  dest: UPLOAD_DIR,
  limits: { fileSize: 15 * 1024 * 1024 }
});

// ==================== CONFIG LLM ====================
const PORT = process.env.PORT || 3000;

const DEFAULT_LLM_ENGINE = 'mistral';
const LLM_ENGINE = (process.env.LLM_ENGINE || DEFAULT_LLM_ENGINE).toLowerCase();
const DEFAULT_FALLBACK_ORDER = 'mistral,groq,cerebras,openrouter';
const LLM_FALLBACK_ORDER = (process.env.LLM_FALLBACK_ORDER || DEFAULT_FALLBACK_ORDER)
  .split(',')
  .map(function(item) { return item.trim().toLowerCase(); })
  .filter(Boolean);

// Prompts optimises - Mise a jour 08/08/2026
// STYLE_RULES : extrait des Regles d'Or d'instructions.md (section debut).
// Applique a TOUS les prompts qui produisent du texte francais (4 boutons :
// Reformulation, Interroger, Proposer emplacement, Charger/Extraire+merge).
// Commentaires code : sans accents ni caracteres speciaux.
const STYLE_RULES = `
Regles de style OBLIGATOIRES (calquees sur instructions.md - Regles d'Or) :
- Orthographe archaique : ecrire CLEF (jamais "cle" ni "cles"), NENUPHAR (jamais "nenufar"). Preferer les graphies classiques elegantes.
- Pas de ligature oe fusionnee : ecrire OE separes (COEUR, VOEUX, soeurs avec o et e distincts).
- Noms de famille en MAJUSCULES completes (CHARREYRE, MONTJOL). Prenoms : majuscule initiale seulement (Mathieu).
- Domaine Saint-Antonin : majuscule a Domaine ; jamais "Domaine de Saint-Antonin".
- Pas de tiret cadratin ni demi-cadratin (— –) a la place d'une virgule ou d'une parenthese : utiliser virgules, parentheses ou deux-points.
- Pas d'emoji ni de smiley dans le texte.
- Parler de Mathieu a la troisieme personne (jamais "je" a sa place).
- Ne pas recopier mot a mot sans reformuler avec nuance quand le contexte le demande.
`;

const QUERY_KEYWORD_PROMPT = `Tu es un expert en extraction de mots-cles. A partir de la question suivante sur la vie de Mathieu CHARREYRE, retourne UNIQUEMENT une liste de mots-cles ou expressions separes par des virgules (maximum 8 termes). Pas de phrase, pas de salutation, pas d'explication.`;
// CORRECTIF 18/07/2026 : le prompt precedent n'interdisait pas explicitement
// d'inventer une citation quand le contexte local ne contenait rien de
// pertinent -- ce qui produisait des extraits fabriques, plausibles mais
// inexistants dans le fichier reel (constate sur une question dont la
// recherche locale ne remontait aucune preuve, cote saisie.php -- voir
// aussi le correctif de search_with_counts_light() le meme jour).
// CORRECTIF 05/08/2026 : expansion d'intention avant selection (ancetres,
// amis d'enfance, etc.) puis reponse en deux lectures.
const QUERY_EXPAND_PROMPT = `Tu elargis une question sur la memoire de Mathieu CHARREYRE pour preparer une recherche dans instructions.md.

Comprends l'intention humaine, pas les mots isoles. Elargis vers les notions RELIEES, periodes, lieux et types de personnes.

Exemples :
- "mes ancetres" -> parents, grands-parents, arbre familial, lignées maternelle et paternelle, origines
- "amis d'enfance" -> amities / camarades ~1976-1992, ecole, quartier, Vie Sociale, Chronologie jeunesse
- "combien de voitures" -> vehicules, 2CV, Titine, permis, deplacements
- "parcelles a Saint-Antonin" -> Domaine, lieudits, natures Bois/Taillis, indivision

Reponds en 4 a 8 lignes max, structure libre mais claire :
Intention :
Axes de recherche : (mots, periodes, lieux, types de personnes)
Sections utiles probables : (famille, chronologie, vie sociale, domaine...)

Pas de reponse a la question elle-meme. Pas d'invention de faits.`;
// CORRECTIF 05/08/2026 : FACTUEL STRICT — tout doit etre dans le fichier.
const QUERY_PROMPT = `Tu es Reformulator. REGLE D'OR NUMERO 1 (prioritaire sur tout le reste) :

************************************************************
** N'INVENTE RIEN. JAMAIS. **
** CHAQUE FAIT DOIT ETRE PRESENT DANS LE CONTEXTE FOURNI. **
** Si l'info n'y est pas : dis "non mentionne dans le fichier". **
** Pas de prenom invente, pas de degre de parente invente, **
** pas de recommandation d'archives externes, pas de "il faudrait consulter". **
************************************************************

Tu ne connais la memoire de Mathieu CHARREYRE QUE via le contexte fourni.

Methode :
1. Lis d'abord le bloc "PREUVES DIRECTES" s'il existe : ce sont les citations prioritaires.
2. Ensuite le reste du contexte. Releves UNIQUEMENT ce qui est ecrit noir sur blanc.
3. Tu PEUX relier deux faits TOUS DEUX ecrits (ex. "fils d'Elisabeth" + "Elisabeth tante de Mathieu" => cousin) MAIS seulement si les deux sont dans le texte. Sinon abstiens-toi.
4. Si PLUSIEURS personnes sont nommees avec le meme type de libelle (ex. plusieurs lignes
   "Futur cousin paternel" / "Future cousine paternelle" avec des prenoms differents),
   liste TOUS les prenoms. Ne resume PAS en "N enfants non nommes" si les prenoms sont dans le contexte.

Exemples de preuves directes valides :
- "Future cousine paternelle de Mathieu CHARREYRE" / "Futur cousin paternel"
- "ses cousins VILLIERS (Antoine et Charlotte)"
- Une liste de naissances avec le libelle cousin/cousine

INTERDITS :
- Dire "non nommes" ou "prenoms non cites" alors qu'un prenom figure dans PREUVES DIRECTES ou Chronologie
- Inventer des prenoms absents du contexte
- Extrapoler un lien de parente : verifie chaque lien uniquement d'apres ce qui est ecrit
  (ne reclasse pas une personne sous un autre degre que celui indique par le texte)
- Inventer "germain", "eloigne", "second degre" si non ecrit
- Compter les occurrences d'un mot au lieu de repondre
- Inventer des sources externes (registres, notaires, archives)

Reponse : factuelle, structuree, concise, avec section source. Francais clair.
` + STYLE_RULES;
// Selection des categories / sections a partir des titres (appel leger).
const QUERY_SELECT_PROMPT = `Tu choisis les sections d'un fichier memoire (instructions.md de Mathieu CHARREYRE) les plus utiles pour repondre a une question.

On te donne les titres, la question, et parfois une "intention elargie". Elargis mentalement :
- ancetres / parents -> Introduction, Famille, Chronologie
- amis d'enfance / jeunesse -> Vie Sociale, Chronologie, Experiences personnelles
- Domaine / parcelles / proprietaires -> Famille/Patrimoine, Entites, Chronologie, Defis
- vehicules / animaux -> Experiences personnelles, Chronologie, Techniques

Regles :
- Reponds UNIQUEMENT par 2 a 5 titres EXACTS separes par des virgules.
- Aucun texte, numero ou guillemet en plus.
- Si aucun titre ne convient : AUCUNE.
- Respecte l'orthographe exacte des titres fournis.`;
// Apres extraction d'un fichier importe : chevauchements + proposition de fusion.
const MERGE_CHECK_PROMPT = `Tu compares un TEXTE FRAICHEMENT EXTRAIT d'un document importe avec le plan / extraits du fichier memoire instructions.md de Mathieu CHARREYRE.

Objectif : detecter si le nouveau texte contient des infos deja presentes, complementaires ou contradictoires, et proposer une fusion.

Reponds en francais, structure clair :
1. Chevauchements (sujets deja traites dans instructions.md)
2. Informations nouvelles (a integrer)
3. Contradictions eventuelles
4. Proposition de fusion : pour chaque bloc utile, section cible (titre exact si possible) et action (completer / remplacer / ignorer)
5. Si peu ou pas de lien avec la memoire : dis-le franchement

Ne reformule pas tout le document. Sois concis et actionnable. N'invente pas de sections inexistantes.
` + STYLE_RULES;
// CORRECTIF 08/08/2026 : fusion intelligente (bouton Comparer / Fusionner).
// Nouveau texte (champ ou import) + contexte memoire deja recupere comme Interroger.
// Produit un texte pret a coller + emplacement cible.
const MERGE_SMART_PROMPT = `Tu es Reformulator. Tu fusionnes des informations nouvelles avec la memoire instructions.md de Mathieu CHARREYRE.

On te donne :
A) CONTEXTE MEMOIRE : extraits / preuves deja presents dans instructions.md sur le sujet
B) TEXTE NOUVEAU : infos fraiches (Geneanet, notes, PDF, etc.)

Objectif : un TEXTE PRET A COLLER, puis ou le mettre. Le reste est secondaire.

Methode :
1. Identifie le sujet principal (personne, lieu, evenement...).
2. Pour le texte a coller : integre faits memoire + nouveautes, sans doublon, sans invention.
   Ne confonds pas conjoint et fratrie. 3e personne. Noms/dates/lieux exacts.
3. Emplacement : section exacte si possible, action (completer / remplacer / ajouter),
   amorce du paragraphe existant a modifier si connu.
4. Details (deja / nouveau / contradictions) : courts, UNIQUEMENT lies au sujet
   (ignore le hors-sujet du contexte memoire).

ORDRE OBLIGATOIRE de la reponse (respecte les balises exactes) :

<<<A_COLLER
(ici UNIQUEMENT le paragraphe ou bloc pret a coller dans instructions.md, rien d'autre)
>>>A_COLLER

<<<EMPLACEMENT
(section, action, eventuelle amorce a remplacer)
>>>EMPLACEMENT

<<<DETAILS
### Deja dans la memoire (sujet uniquement)
...
### Nouveau ou plus precis
...
### Contradictions (ou : aucune)
...
>>>DETAILS

Ne coupe jamais en plein milieu. Pas de texte hors de ces trois blocs.
` + STYLE_RULES;
// Proposer emplacement
const LOCATION_PROMPT = `Tu es un expert en organisation de memoire personnelle. Recois un texte sur la vie de Mathieu CHARREYRE et propose les sections les plus pertinentes ou l'inserer dans instructions.md.
Reponds de facon courte, precise et structuree. Mentionne explicitement les titres de sections recommandees. Ne reformule pas le texte lui-meme.
` + STYLE_RULES;
// CORRECTIF 08/08/2026 : reformulation intelligente (pas un simple correcteur).
// Objectif : saisir la nature de l'anecdote / du souvenir, le reformuler proprement
// a la 3e personne pour insertion dans instructions.md, sans le diluer ni le
// densifier a l'extreme. Commentaires sans accents ni caracteres speciaux.
const SAISIE_PROMPT = `Tu es Reformulator, redacteur de memoire pour Mathieu CHARREYRE.

Mission : comprendre le sens et la nature du texte (anecdote, souvenir, fait, impression, conflit, detail technique...) puis le reformuler en prose claire, a la troisieme personne, pret a etre inserer dans instructions.md.

Regles obligatoires :
1. Personne : si le texte est a la premiere personne (je, moi, mon, ma, mes, nous...), transpose TOUT en troisieme personne avec "Mathieu" comme sujet. Aucune forme a la premiere personne dans la reponse.
2. Comprehension : saisis l'intention et le ton (ironie, colere, nostalgie, factuel...). Ne traite pas le texte comme une liste de mots a corriger.
3. Fidelite : ne change pas les faits, ne invente rien, ne supprime rien d'important (noms, dates, lieux, chiffres, liens de parente).
4. Longueur : synthese intelligente, pas un resume seche ni une paraphrase mot a mot.
   - Texte court (quelques phrases) : corrige et fluidifie, garde une longueur proche.
   - Texte moyen/long (ex. 10-20 lignes) : vise environ le tiers a la moitie (ex. 15 lignes -> 4 a 7 lignes utiles), en gardant le coeur de l'anecdote.
   - Evite les paves inutiles et les listes artificielles sauf si le contenu l'exige.
5. Style : francais clair, paragraphes naturels, orthographe et grammaire correctes. Pas de titre, pas de puces forcees, pas de meta-commentaire.
6. Sortie : UNIQUEMENT le texte reformule. Pas d'introduction, pas de guillemets, pas de "Voici la version...", pas de salutation.

Exemples de transposition :
- "J'ai rachete une 2CV en 1998" -> "Mathieu a rachete une 2CV en 1998"
- "Ma tante Dom m'a toujours surnomme..." -> "Sa tante Dom l'a toujours surnomme..."
` + STYLE_RULES;

// LLM_ENGINES contient la configuration de chaque moteur LLM supporté, avec la fonction createPayload() pour construire la requête API.
const createOpenAICompatiblePayload = (text, model, context, purpose) => {
  const messages = [];
  if (purpose === 'location') messages.push({ role: 'system', content: LOCATION_PROMPT });
  else if (purpose === 'query-keywords') messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
  else if (purpose === 'query-expand') messages.push({ role: 'system', content: QUERY_EXPAND_PROMPT });
  else if (purpose === 'query-select') messages.push({ role: 'system', content: QUERY_SELECT_PROMPT });
  else if (purpose === 'query') messages.push({ role: 'system', content: QUERY_PROMPT });
  else if (purpose === 'merge-check') messages.push({ role: 'system', content: MERGE_CHECK_PROMPT });
  else if (purpose === 'merge-smart') messages.push({ role: 'system', content: MERGE_SMART_PROMPT });
  else messages.push({ role: 'system', content: SAISIE_PROMPT });
  if (context) messages.push({ role: 'system', content: 'Contexte instructions (memoire) : ' + context });
  const userContent = (purpose === 'query')
    ? 'Recherche dans instructions.md : ' + text
    : (purpose === 'query-select' || purpose === 'query-expand')
      ? text
      : (purpose === 'merge-check')
        ? 'Texte importe a comparer avec la memoire :\n' + text
        : (purpose === 'merge-smart')
          ? 'TEXTE NOUVEAU a fusionner avec la memoire ci-dessus :\n' + text
          : 'Texte a reformuler pour le memoire : ' + text;
  messages.push({ role: 'user', content: userContent });
  const temperature = (purpose === 'rewrite' || purpose === 'location' || purpose === 'query-select' || purpose === 'query-expand' || purpose === 'merge-check' || purpose === 'merge-smart') ? 0.2
    : purpose === 'query-keywords' ? 0.0 : 0.4;
  // merge-smart : reponses longues (deja + nouveau + texte fusionne + emplacement)
  const maxTokens = (purpose === 'merge-smart') ? 4000
    : (purpose === 'query') ? 2200
    : 1500;
  return { model: model, messages: messages, temperature: temperature, max_tokens: maxTokens };
};

const LLM_ENGINES = {
  // Mistral
  mistral: {
    name: 'Mistral',
    apiKeyEnv: 'MISTRAL_API_KEY',
    modelEnv: 'MISTRAL_MODEL',
    apiBase: process.env.MISTRAL_API_BASE || 'https://api.mistral.ai/v1',
    engineUrl: 'https://console.mistral.ai',
    defaultModel: 'mistral-small-latest',
    models: ['mistral-small-latest','open-mistral-7b','mistral-medium-latest'],
    createPayload: createOpenAICompatiblePayload,
  },
  // Groq
  groq: {
    name: 'Groq',
    apiKeyEnv: 'GROQ_API_KEY',
    modelEnv: 'GROQ_MODEL',
    apiBase: process.env.GROQ_API_BASE || 'https://api.groq.com/openai/v1',
    engineUrl: 'https://console.groq.com/home',
    defaultModel: 'llama-3.3-70b-versatile',
    models: ['llama-3.3-70b-versatile','llama-3.1-8b-instant','llama-4-scout-17b-16e-instruct','gemma2-9b-it','qwen-qwq-32b','compound-beta-mini'],
    createPayload: function(text, model, context, purpose) {
      context = context || '';
      purpose = purpose || 'rewrite';
      const messages = [];
      if (purpose === 'location') messages.push({ role: 'system', content: LOCATION_PROMPT });
      else if (purpose === 'query-keywords') messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
      else if (purpose === 'query-expand') messages.push({ role: 'system', content: QUERY_EXPAND_PROMPT });
      else if (purpose === 'query-select') messages.push({ role: 'system', content: QUERY_SELECT_PROMPT });
      else if (purpose === 'query') messages.push({ role: 'system', content: QUERY_PROMPT });
      else if (purpose === 'merge-check') messages.push({ role: 'system', content: MERGE_CHECK_PROMPT });
      else if (purpose === 'merge-smart') messages.push({ role: 'system', content: MERGE_SMART_PROMPT });
      else messages.push({ role: 'system', content: SAISIE_PROMPT });
      if (context) messages.push({ role: 'system', content: 'Contexte instructions (memoire) : ' + context });
      const userContent = (purpose === 'query')
        ? 'Recherche dans instructions.md : ' + text
        : (purpose === 'query-select' || purpose === 'query-expand')
          ? text
          : (purpose === 'merge-check')
            ? 'Texte importe a comparer avec la memoire :\n' + text
            : (purpose === 'merge-smart')
              ? 'TEXTE NOUVEAU a fusionner avec la memoire ci-dessus :\n' + text
              : 'Texte a reformuler pour le memoire : ' + text;
      messages.push({ role: 'user', content: userContent });
      const temperature = (purpose === 'rewrite' || purpose === 'location' || purpose === 'query-select' || purpose === 'query-expand' || purpose === 'merge-check' || purpose === 'merge-smart') ? 0.2
        : purpose === 'query-keywords' ? 0.0 : 0.4;
      const maxTokens = (purpose === 'merge-smart') ? 4000
        : (purpose === 'query') ? 2200
        : 1500;
      return { model: model, messages: messages, temperature: temperature, max_tokens: maxTokens };
    }
  },
  // Cerebras
  cerebras: {
    name: 'Cerebras',
    apiKeyEnv: 'CEREBRAS_API_KEY',
    modelEnv: 'CEREBRAS_MODEL',
    apiBase: process.env.CEREBRAS_API_BASE || 'https://api.cerebras.ai/v1',
    engineUrl: 'https://cloud.cerebras.ai',
    defaultModel: 'gpt-oss-120b',
    models: ['gpt-oss-120b', 'zai-glm-4.7'],
    createPayload: createOpenAICompatiblePayload,
  },
  // OpenRouter
  // CORRECTIF 03/08/2026 : les slugs :free tournent tres souvent (404).
  // On privilegie openrouter/free (auto-route vers un modele gratuit disponible).
  openrouter: {
    name: 'OpenRouter',
    apiKeyEnv: 'OPENROUTER_API_KEY',
    modelEnv: 'OPENROUTER_MODEL',
    apiBase: process.env.OPENROUTER_API_BASE || 'https://openrouter.ai/api/v1',
    engineUrl: 'https://openrouter.ai',
    defaultModel: 'openrouter/free',
    models: [
      'openrouter/free',
      'meta-llama/llama-3.3-8b-instruct:free',
      'google/gemma-3-27b-it:free',
      'meta-llama/llama-4-scout:free',
      'openai/gpt-oss-120b',
    ],
    createPayload: createOpenAICompatiblePayload,
  },
};

const getEngineConfig = function(engineName) { return LLM_ENGINES[engineName] || null; };

const getEngineModel = function(engineName) {
  var engine = getEngineConfig(engineName);
  if (!engine) return null;
  var envModel = process.env[engine.modelEnv] || '';
  if (envModel) {
    if (engine.models.indexOf(envModel) !== -1) return envModel;
    console.warn('Modele non supporté pour ' + engine.name + ' : ' + envModel + '. Défaut : ' + engine.defaultModel);
    return engine.defaultModel;
  }
  return engine.defaultModel;
};

// Retourne la liste des moteurs LLM disponibles (avec clé API présente)
const getAvailableEngines = function() {
  return Object.keys(LLM_ENGINES).filter(function(name) {
    var eng = LLM_ENGINES[name];
    return !!(eng && process.env[eng.apiKeyEnv]);
  });
};

const getCurrentEngineInfo = function() {
  var engine = getEngineConfig(LLM_ENGINE);
  var selectedModel = getEngineModel(LLM_ENGINE);
  return {
    defaultEngine: LLM_ENGINE,
    engineName: engine && engine.name ? engine.name : LLM_ENGINE,
    engineUrl: engine && engine.engineUrl ? engine.engineUrl : '',
    selectedModel: selectedModel,
    fallbackOrder: LLM_FALLBACK_ORDER,
    availableEngines: getAvailableEngines(),
    modelCandidates: engine && engine.models ? engine.models : [],
  };
};

// Construire l'URL de requête pour le moteur LLM, en ajoutant /chat/completions si nécessaire
const buildRequestUrl = function(baseUrl) {
  var normalized = String(baseUrl).trim().replace(/\/+$/, '');
  if (normalized.endsWith('/chat/completions') || normalized.endsWith('/completions')) return normalized;
  return normalized + '/chat/completions';
};

// Fonction utilitaire pour attendre un certain temps (en ms)
const sleep = function(ms) { return new Promise(function(resolve) { setTimeout(resolve, ms); }); };

// Extraire le délai de retry à partir de l'erreur HTTP (429) ou du message d'erreur
const extractRetryAfterSeconds = function(error) {
  var headers = error.response && error.response.headers ? error.response.headers : {};
  if (headers['retry-after']) {
    var retry = parseFloat(headers['retry-after']);
    if (!isNaN(retry) && retry > 0) return retry;
  }
  var responseData = error.response && error.response.data ? error.response.data : null;
  var message = responseData && responseData.error && responseData.error.message ? String(responseData.error.message) : '';
  var match = message.match(/([0-9]+(?:\.[0-9]+)?)s/);
  if (match && match[1]) {
    var value = parseFloat(match[1]);
    if (!isNaN(value) && value > 0) return value;
  }
  return 0;
};

// ─────────────────────────────────────────────────────────────────────────────
// reformulate() — fallback naturel avec historique des tentatives
//
// Comportement :
//   - Si preferredEngine est fourni, il passe EN PREMIER dans la séquence.
//   - En cas d'échec, on CONTINUE dans l'ordre naturel (LLM_FALLBACK_ORDER)
//     en sautant le moteur qui vient d'échouer.
//   - Chaque tentative est enregistrée dans `attempts` et renvoyée au client
//     pour affichage dans test_curl.php.
// ─────────────────────────────────────────────────────────────────────────────
const reformulate = async function(text, context, purpose, preferredEngine) {
  context = context || '';
  purpose = purpose || 'rewrite';
  preferredEngine = preferredEngine || null;

  var lastError = null;
  var attempts = [];

  // Ordre naturel défini par LLM_FALLBACK_ORDER.
  // Si un moteur est demandé, il passe devant ; les autres suivent dans l'ordre naturel.
  var naturalOrder = LLM_FALLBACK_ORDER.filter(Boolean);
  var engineOrder;
  if (preferredEngine && LLM_ENGINES[preferredEngine]) {
    engineOrder = [preferredEngine].concat(naturalOrder.filter(function(e) { return e !== preferredEngine; }));
    console.log('[FORCE ENGINE] Client a demandé ' + preferredEngine + ' → ordre : ' + engineOrder.join(', '));
  } else {
    engineOrder = naturalOrder;
    console.log('[AUTO ENGINE] Ordre fallback : ' + engineOrder.join(', '));
  }

  for (var i = 0; i < engineOrder.length; i++) {
    var engineName = engineOrder[i];
    var engine = getEngineConfig(engineName);
    if (!engine) continue;

    var apiKey = process.env[engine.apiKeyEnv];
    if (!apiKey) {
      console.log('[SKIP] Pas de clé API pour ' + engineName + ' (' + engine.apiKeyEnv + ')');
      attempts.push({ engine: engineName, model: null, status: 'skipped', error: 'clé API absente' });
      continue;
    }

    // Tentative de requête au moteur LLM
    var retryCount = 0;
    var maxRetries = 2;

    while (true) {
      var model = getEngineModel(engineName);
      var payload = engine.createPayload(text, model, context, purpose);
      var requestUrl = buildRequestUrl(engine.apiBase);

      console.log('[TRY] ' + engineName + ' (' + model + ') → ' + requestUrl);

      try {
        var response = await axios.post(requestUrl, payload, {
          headers: {
            'Authorization': 'Bearer ' + apiKey,
            'Content-Type': 'application/json',
            'HTTP-Referer': 'https://charreyre.net',
            'X-Title': 'Reformulator'
          }
        });

        console.log('[SUCCESS] ' + engineName + ' a répondu');
        attempts.push({ engine: engineName, model: model, status: 'success', error: null });
        return {
          cleaned: response.data.choices[0].message.content.trim(),
          engine: engineName,
          model: model,
          attempts: attempts
        };

      } catch (error) {
        lastError = error;
        var status = (error.response && error.response.status) ? error.response.status : 'unknown';

        // Extraire le message d'erreur réel de la réponse API
        var errMsg = '';
        if (error.response && error.response.data) {
          var d = error.response.data;
          if (d.error && d.error.message) errMsg = d.error.message;
          else if (typeof d.error === 'string') errMsg = d.error;
          else if (d.message) errMsg = d.message;
          else errMsg = JSON.stringify(d).substring(0, 200);
        } else {
          errMsg = error.message || String(error);
        }

        console.log('[FAIL] ' + engineName + ' HTTP ' + status + ' : ' + errMsg);

        if (status === 429 && retryCount < maxRetries) {
          retryCount++;
          var waitMs = 3000;
          var retryAfter = extractRetryAfterSeconds(error);
          if (retryAfter > 0) waitMs = Math.min(retryAfter * 1000, 10000);
          console.log('[RETRY] ' + engineName + ' dans ' + (waitMs / 1000) + 's (tentative ' + retryCount + '/' + maxRetries + ')');
          await sleep(waitMs);
          continue;
        }

        attempts.push({ engine: engineName, model: model, status: 'HTTP ' + status, error: errMsg });
        logError('Moteur ' + engineName + ' échoué (HTTP ' + status + ') : ' + errMsg);
        break; // passer au moteur suivant
      }
    }
  }

  // Tous les moteurs ont échoué
  var summary = attempts.map(function(a) { return a.engine + ':' + a.status; }).join(', ');
  logError('Tous les moteurs ont échoué. Séquence : ' + summary);
  var finalErr = lastError || new Error('Aucun moteur LLM disponible');
  finalErr.attempts = attempts;
  throw finalErr;
};

// Routes statiques
app.get('/status', function(req, res) { res.type('text/plain').send('Reformulator service is alive'); });
app.get('/error.log', function(req, res) { res.redirect(301, '/log/error.log'); });
app.get('/requests.log', function(req, res) { res.redirect(301, '/log/requests.log'); });
app.get('/log/error.log', function(req, res) {
  ensureLogFile(ERROR_LOG_FILE);
  res.type('text/plain; charset=UTF-8');
  res.set('Cache-Control', 'no-store');
  res.sendFile(ERROR_LOG_FILE);
});
app.get('/log/requests.log', function(req, res) {
  ensureLogFile(REQUESTS_LOG_FILE);
  res.type('text/plain; charset=UTF-8');
  res.set('Cache-Control', 'no-store');
  res.sendFile(REQUESTS_LOG_FILE);
});
app.get('/', function(req, res) {
  res.json(Object.assign({ status: 'ok', routes: ['/status','/llm-info','/reformuler'] }, getCurrentEngineInfo()));
});
app.get('/llm-info', function(req, res) { res.json(getCurrentEngineInfo()); });
app.get('/r3M3M83r/reformulator/llm-info', function(req, res) { res.json(getCurrentEngineInfo()); });

const registerReformulationRoute = function(routePath) {
  app.post(routePath, async function(req, res) {
    logRequest(req);
    var text = (req.body && req.body.text) || '';
    var context = (req.body && typeof req.body.instructionsContext === 'string') ? req.body.instructionsContext : '';
    var purpose = (req.body && typeof req.body.purpose === 'string') ? req.body.purpose : 'rewrite';
    var preferredEngine = null;
    if (req.body && typeof req.body.engine === 'string' && req.body.engine.trim() !== '') {
      var eng = req.body.engine.trim().toLowerCase();
      if (LLM_ENGINES[eng]) {
        preferredEngine = eng;
        console.log('[DEBUG] Moteur demandé par le client : ' + preferredEngine);
      }
    }
    if (!text) return res.status(400).json({ error: 'Texte absent' });
    try {
      var result = await reformulate(text, context, purpose, preferredEngine);
      res.json({ cleaned: result.cleaned, engine: result.engine, model: result.model, attempts: result.attempts });
    } catch (error) {
      var errorMessage = error && error.message ? error.message : String(error);
      logError(errorMessage);
      res.status(500).json({ error: 'Erreur LLM', details: errorMessage, attempts: error.attempts || [] });
    }
  });
};

// ====================== GESTION UPLOAD FICHIERS ======================
// CORRECTIF 03/08/2026 : OCR de secours pour PDF scannes (pdf-parse ne lit
// que la couche texte). Pipeline : pdftoppm (pages -> PNG) puis tesseract.
// Langue dispo sur l'hebergeur : eng uniquement (pas de pack fra). Mieux que
// rien pour un scan ; si echec total, message clair NON_EXPLOITABLE.
const OCR_MAX_PAGES = 12;
const OCR_MIN_CHARS = 30;

function ocrPdfWithTesseract(pdfPath, originalName) {
  const workDir = path.join(UPLOAD_DIR, 'ocr_' + Date.now());
  fs.mkdirSync(workDir, { recursive: true });
  try {
    // Rasteriser les premieres pages (150 DPI : compromis taille / lisibilite)
    execFileSync('pdftoppm', [
      '-png', '-r', '150', '-f', '1', '-l', String(OCR_MAX_PAGES),
      pdfPath, path.join(workDir, 'page')
    ], { timeout: 120000, maxBuffer: 20 * 1024 * 1024 });

    const pages = fs.readdirSync(workDir)
      .filter(function (f) { return /^page-\d+\.png$/i.test(f); })
      .sort();
    if (pages.length === 0) {
      console.log('[OCR] Aucune page rasterisee pour ' + originalName);
      return '';
    }

    var texts = [];
    for (var i = 0; i < pages.length; i++) {
      var imgPath = path.join(workDir, pages[i]);
      try {
        var out = execFileSync('tesseract', [imgPath, 'stdout', '-l', 'eng', '--psm', '3'], {
          timeout: 60000,
          maxBuffer: 10 * 1024 * 1024,
          encoding: 'utf8'
        });
        out = (out || '').trim();
        if (out) texts.push(out);
      } catch (pageErr) {
        console.log('[OCR] Echec page ' + pages[i] + ' : ' + (pageErr.message || pageErr));
      }
    }
    return texts.join('\n\n').trim();
  } catch (err) {
    console.log('[OCR] Echec global pour ' + originalName + ' : ' + (err.message || err));
    return '';
  } finally {
    try {
      fs.readdirSync(workDir).forEach(function (f) {
        try { fs.unlinkSync(path.join(workDir, f)); } catch (e) {}
      });
      fs.rmdirSync(workDir);
    } catch (e) {}
  }
}

async function extractTextFromFile(file) {
  try {
    const ext = path.extname(file.originalname).toLowerCase();
    console.log(`[EXTRACTION] Fichier : ${file.originalname} (${ext}) - taille ${file.size} octets`);

    if (ext === '.pdf') {
      let extracted = '';
      let usedOcr = false;
      let parseError = null;

      // 1) Couche texte native (peut planter sur PDF mal formes / "Invalid PDF structure")
      try {
        const dataBuffer = fs.readFileSync(file.path);
        const data = await pdf(dataBuffer);
        extracted = data.text ? data.text.trim() : '';
      } catch (pdfErr) {
        parseError = pdfErr && pdfErr.message ? pdfErr.message : String(pdfErr);
        console.log('[WARN] pdf-parse echec pour ' + file.originalname + ' : ' + parseError);
        extracted = '';
      }

      // 2) OCR si couche texte absente OU parseur plante (scan / structure invalide)
      if (extracted.length < OCR_MIN_CHARS) {
        console.log('[WARN] Tentative OCR tesseract pour ' + file.originalname + (parseError ? ' (apres echec pdf-parse)' : ' (texte trop court)'));
        const ocrText = ocrPdfWithTesseract(file.path, file.originalname);
        if (ocrText && ocrText.length >= OCR_MIN_CHARS) {
          extracted = ocrText;
          usedOcr = true;
          console.log('[SUCCESS] OCR tesseract : ' + extracted.length + ' caracteres pour ' + file.originalname);
        } else {
          extracted = 'NON_EXPLOITABLE: PDF scanné, protégé ou structure invalide, OCR insuffisant ('
            + file.originalname + ')'
            + (parseError ? ' [pdf-parse: ' + parseError.substring(0, 80) + ']' : '')
            + '. Fournis un PDF texte, un DOCX, ou un document deja OCR-ise.';
          console.log('[WARN] PDF non exploitable apres OCR : ' + file.originalname);
        }
      } else {
        console.log('[SUCCESS] PDF extrait (couche texte) : ' + extracted.length + ' caracteres');
      }
      if (usedOcr) {
        extracted = '[Texte obtenu par OCR — qualite variable]\n\n' + extracted;
      }
      return extracted;
    }

    if (ext === '.docx') {
      const result = await mammoth.extractRawText({ path: file.path });
      return result.value.trim() || `[DOCX vide ou illisible : ${file.originalname}]`;
    }

    // CORRECTIF 05/07/2026 (v3) : support .doc (ancien format binaire Word
    // 97-2003, non lisible par mammoth qui ne gere que le .docx XML moderne).
    // word-extractor est une lib pure JS, aucun binaire externe requis.
    if (ext === '.doc') {
      try {
        const extractor = new WordExtractor();
        const doc = await extractor.extract(file.path);
        const extracted = (doc.getBody() || '').trim();
        return extracted || `[DOC vide ou illisible : ${file.originalname}]`;
      } catch (docError) {
        logError(`Extraction DOC échouée pour ${file.originalname} : ${docError.message}`);
        return `[Erreur extraction DOC ${file.originalname} — ${docError.message.substring(0, 100)}]`;
      }
    }

    // CORRECTIF 05/07/2026 (v3) : support .rtf via extraction texte par regex
    // (pas de parseur RTF Node leger et sans dependance systeme disponible
    // sur hebergement mutualise). Fonctionne bien sur du RTF simple ; peut
    // laisser des residus sur du RTF complexe (tableaux, objets OLE, images).
    if (ext === '.rtf') {
      try {
        const raw = fs.readFileSync(file.path, 'latin1');
        const extracted = extractPlainTextFromRtf(raw);
        return extracted || `[RTF vide ou illisible : ${file.originalname}]`;
      } catch (rtfError) {
        logError(`Extraction RTF échouée pour ${file.originalname} : ${rtfError.message}`);
        return `[Erreur extraction RTF ${file.originalname} — ${rtfError.message.substring(0, 100)}]`;
      }
    }

    if (ext === '.txt' || ext === '.md') {
      const content = fs.readFileSync(file.path, 'utf8').trim();
      return content || `[Fichier texte vide : ${file.originalname}]`;
    }

    return `[Fichier joint : ${file.originalname} — type non supporté]`;
  } catch (err) {
    logError(`Extraction échouée pour ${file.originalname} : ${err.message}`);
    console.error(`[ERROR] ${err.message}`);
    return `[Erreur extraction ${file.originalname} — ${err.message.substring(0, 100)}]`;
  }
}

// CORRECTIF 05/07/2026 (v3) : parseur a pile pour supprimer entierement les
// groupes de destination non textuels (polices, couleurs, styles, objets...),
// y compris lorsqu'ils sont imbriques (ex: {\fonttbl{\f0 Arial;}}) -- une
// simple regex non gourmande sur [^{}]* echoue sur les accolades imbriquees.
function stripRtfDestinationGroups(text, destinations) {
  const destSet = new Set(destinations.map(function(d) { return d.toLowerCase(); }));
  let result = '';
  let i = 0;
  const skipStack = [];
  while (i < text.length) {
    const ch = text[i];
    if (ch === '{') {
      let j = i + 1;
      if (text[j] === '\\' && text[j + 1] === '*') {
        j += 2;
      }
      let word = '';
      if (text[j] === '\\') {
        let k = j + 1;
        while (k < text.length && /[a-zA-Z]/.test(text[k])) {
          word += text[k];
          k++;
        }
      }
      const currentlySkipping = skipStack.length > 0 && skipStack[skipStack.length - 1];
      const shouldSkip = currentlySkipping || destSet.has(word.toLowerCase());
      skipStack.push(shouldSkip);
      if (!shouldSkip) result += ch;
      i++;
      continue;
    }
    if (ch === '}') {
      const wasSkipping = skipStack.length > 0 ? skipStack.pop() : false;
      if (!wasSkipping) result += ch;
      i++;
      continue;
    }
    const currentlySkipping = skipStack.length > 0 && skipStack[skipStack.length - 1];
    if (!currentlySkipping) result += ch;
    i++;
  }
  return result;
}

function extractPlainTextFromRtf(rtfContent) {
  let text = rtfContent;

  // Suppression des groupes non textuels (imbrication geree correctement)
  text = stripRtfDestinationGroups(text, [
    'fonttbl', 'colortbl', 'stylesheet', 'pict', 'object', 'info',
    'generator', 'xmlnstbl', 'listtable', 'listoverridetable',
    'rsidtbl', 'themedata', 'colorschememapping', 'latentstyles', 'datastore'
  ]);

  // \uNNNN : caractere unicode (NNNN decimal, negatif pour les points de code hauts)
  text = text.replace(/\\u(-?\d+)\s?/g, function(match, code) {
    let codePoint = parseInt(code, 10);
    if (codePoint < 0) codePoint += 65536;
    try {
      return String.fromCharCode(codePoint);
    } catch (e) {
      return '';
    }
  });

  // \'xx : caractere encode en hexadecimal (latin1/cp1252 selon la police)
  text = text.replace(/\\'([0-9a-fA-F]{2})/g, function(match, hex) {
    try {
      return Buffer.from([parseInt(hex, 16)]).toString('latin1');
    } catch (e) {
      return '';
    }
  });

  text = text.replace(/\\par[d]?\b/g, '\n');
  text = text.replace(/\\tab\b/g, '\t');
  text = text.replace(/\\line\b/g, '\n');

  // Mots de controle restants (ex: \rtf1, \ansi, \deff0, \f0, \fs24, \b, \i, \ul...)
  text = text.replace(/\\[a-zA-Z]+-?\d*\s?/g, '');

  // Accolades de regroupement restantes
  text = text.replace(/[{}]/g, '');

  // Nettoyage des espacements
  text = text.replace(/\r?\n[ \t]*\r?\n+/g, '\n\n');
  text = text.replace(/[ \t]+/g, ' ');
  text = text.split('\n').map(function(line) { return line.trim(); }).join('\n');

  return text.trim();
}

// CORRECTIF 04/07/2026 : middleware dédié qui encapsule upload.single('file')
// pour intercepter proprement les erreurs Multer (dossier manquant, fichier
// trop volumineux, type MIME rejeté, etc.). Sans ce middleware, une erreur
// Multer fait tomber Express sur sa page d'erreur HTML par défaut, AVANT
// d'atteindre le corps de la route -- donc avant logRequest()/logError().
const handleFileUpload = function(req, res, next) {
  upload.single('file')(req, res, function(multerError) {
    if (multerError) {
      var details = multerError.message || String(multerError);
      logError('Erreur upload Multer sur ' + req.originalUrl + ' : ' + details);
      return res.status(400).json({ error: 'Upload échoué', details: details, attempts: [] });
    }
    next();
  });
};

// Route principale avec upload + mode extraction
app.post('/reformuler', handleFileUpload, async function(req, res) {
  logRequest(req);

  let text = (req.body && req.body.text) || '';
  const file = req.file;

  // Récupération du purpose même en multipart
  const purpose = (req.body && req.body.purpose) ? req.body.purpose.toLowerCase() : 'rewrite';

  const hasBase64File = !file && req.body && typeof req.body.fileData === 'string' && req.body.fileData.trim() !== '';
  console.log(`[ROUTE] Purpose reçu : ${purpose} | Fichier multipart : ${file ? file.originalname : 'aucun'} | Fichier base64 : ${hasBase64File ? (req.body.fileName || 'sans nom') : 'aucun'}`);

  // === EXTRACTION DE FICHIER (multipart historique) ===
  if (file) {
    const extracted = await extractTextFromFile(file);
    text = extracted + "\n\n" + text;
    try { fs.unlinkSync(file.path); } catch(e) {}
  }

  // === EXTRACTION DE FICHIER (base64 JSON) ===
  // CORRECTIF 05/07/2026 (v2) : contournement du blocage mod_security o2switch
  // sur les requetes multipart/form-data (HTTP 406 avant meme d'atteindre
  // Passenger/Node.js). Le fichier arrive ici encode en base64 dans le JSON
  // (voir extract_via_node() cote saisie.php). On le decode vers un fichier
  // temporaire dans UPLOAD_DIR, puis on reutilise extractTextFromFile() sans
  // la modifier -- elle attend juste un objet { path, originalname, size }.
  if (hasBase64File) {
    const originalName = String(req.body.fileName || 'fichier_sans_nom');
    let tmpFilePath = null;
    try {
      const buffer = Buffer.from(req.body.fileData, 'base64');
      const safeName = Date.now() + '_' + originalName.replace(/[^a-zA-Z0-9._-]/g, '_');
      tmpFilePath = path.join(UPLOAD_DIR, safeName);
      fs.writeFileSync(tmpFilePath, buffer);
      const extracted = await extractTextFromFile({
        path: tmpFilePath,
        originalname: originalName,
        size: buffer.length
      });
      text = extracted + "\n\n" + text;
    } catch (b64Error) {
      const details = b64Error && b64Error.stack ? b64Error.stack : String(b64Error);
      logError('Erreur decodage fichier base64 (' + originalName + ') : ' + details);
      return res.status(400).json({ error: 'Fichier base64 invalide', details: b64Error && b64Error.message ? b64Error.message : String(b64Error), attempts: [] });
    } finally {
      if (tmpFilePath) { try { fs.unlinkSync(tmpFilePath); } catch (e) {} }
    }
  }

  // Mode extraction seule
  if (purpose === 'extract') {
    console.log(`[EXTRACT MODE] Texte extrait : ${text.length} caractères`);
    return res.json({
      cleaned: text.trim(),
      engine: 'extraction',
      model: 'local',
      attempts: []
    });
  }

  // Mode normal (reformulation, etc.)
  var context = (req.body && typeof req.body.instructionsContext === 'string') ? req.body.instructionsContext : '';
  var preferredEngine = null;
  if (req.body && typeof req.body.engine === 'string' && req.body.engine.trim() !== '') {
    var eng = req.body.engine.trim().toLowerCase();
    if (LLM_ENGINES[eng]) preferredEngine = eng;
  }

  if (!text.trim()) return res.status(400).json({ error: 'Texte absent' });

  try {
    var result = await reformulate(text, context, purpose, preferredEngine);
    res.json({ cleaned: result.cleaned, engine: result.engine, model: result.model, attempts: result.attempts });
  } catch (error) {
    var errorMessage = error && error.message ? error.message : String(error);
    logError(errorMessage);
    res.status(500).json({ error: 'Erreur LLM', details: errorMessage, attempts: error.attempts || [] });
  }
});

// Compatibilité ancienne route
// CORRECTIF 04/07/2026 : ne rappelle plus upload.single('file') ici -- la
// requête est redéléguée telle quelle vers /reformuler, qui applique déjà
// handleFileUpload. L'appliquer deux fois aurait tenté de reparser un corps
// multipart déjà consommé par le premier passage.
app.post('/r3M3M83r/reformulator/reformuler', function(req, res) {
  req.url = '/reformuler';
  app.handle(req, res);
});

// CORRECTIF 04/07/2026 : middleware d'erreur Express global (4 arguments).
// Filet de sécurité pour toute exception qui remonterait sans avoir été
// interceptée par un try/catch local : on logge systématiquement et on
// répond toujours en JSON plutôt qu'avec la page d'erreur HTML par défaut
// d'Express (ce qui, côté PHP, ferait échouer json_decode() silencieusement).
app.use(function(err, req, res, next) {
  var details = err && err.stack ? err.stack : String(err);
  logError('Erreur Express non interceptee sur ' + req.originalUrl + ' : ' + details);
  if (res.headersSent) {
    return next(err);
  }
  res.status(500).json({ error: 'Erreur serveur interne', details: err && err.message ? err.message : String(err) });
});

// ===================== LANCEMENT DU SERVEUR ======================
app.listen(PORT, function() {
  var info = getCurrentEngineInfo();
  var portFile = path.join(__dirname, '.port');
  try { fs.writeFileSync(portFile, String(PORT), 'utf8'); } catch (e) {
    logError('Impossible d ecrire .port : ' + String(e && e.message ? e.message : e));
  }
  console.log('Reformulator pret sur port ' + PORT);
  console.log('LLM engine actif : ' + info.engineName);
  console.log('LLM modele actif : ' + info.selectedModel);
});