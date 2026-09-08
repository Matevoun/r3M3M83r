/**
 * ============================================================================
 * r3M3M83r/moteurs/server.js
 * ============================================================================
 *
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
 *      toujours repondre en JSON et logger dans moteurs/log/error.log.
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
/**
 * Journal humain des requetes LLM.
 * Une ligne = date + IP + action + texte de la question (tronque a 500 car.).
 */
/**
 * Journal technique (moteurs/log/requests.log).
 * CORRECTIF 22/08/2026 : une seule ligne par action UTILISATEUR.
 * On ignore les etapes internes (chat-route, query-expand, query-select)
 * qui polluaient le log (4 lignes pour une question).
 */
const LOG_FINAL_PURPOSES = {
  'query': true,
  'query-chat': true,
  'rewrite': true,
  'location': true,
  'merge-smart': true,
  'merge-check': true,
  'extract': true,
  'chat-talk': true
};
const logRequest = function(req, textOverride) {
  var purpose = 'rewrite';
  if (req.body && typeof req.body.purpose === 'string' && req.body.purpose.trim() !== '') {
    purpose = req.body.purpose.trim().toLowerCase();
  }
  // Etapes pipeline : pas dans requests.log (bruit)
  if (!LOG_FINAL_PURPOSES[purpose]) {
    return;
  }
  const clientIp = (req.headers['x-forwarded-for'] || req.ip || (req.socket && req.socket.remoteAddress) || 'inconnue').toString().split(',')[0].trim();
  var rawText = '';
  if (typeof textOverride === 'string' && textOverride.length > 0) {
    rawText = textOverride;
  } else if (req.body && typeof req.body.text === 'string') {
    rawText = req.body.text;
  }
  var preview = String(rawText).replace(/\r?\n/g, ' ').replace(/\s+/g, ' ').trim();
  // Retirer le bloc persona Rebecca prepend (CHAT_ADDON) pour ne garder que la question
  var addonIdx = preview.indexOf('Historique recent');
  if (addonIdx === -1) addonIdx = preview.indexOf('CONSIGNE TCHAT');
  if (addonIdx === 0) {
    // Cherche la fin du addon : souvent la question humaine est apres le dernier bloc
    var qMark = preview.lastIndexOf('?');
    // Si le texte commence par CONSIGNE, essayer d'extraire apres "Historique..."
    var hist = preview.indexOf('{{CHAT_HISTORY}}');
    if (hist === -1) hist = preview.indexOf('Historique recent');
    if (hist !== -1) {
      var after = preview.substring(hist).replace(/^Historique recent[^:]*:\s*/i, '');
      // apres historique il reste parfois la question reelle en fin de payload PHP
      // Mieux : chercher "Question :" explicite
    }
  }
  if (/^CONSIGNE TCHAT/i.test(preview) || /^Tu es Rebecca/i.test(preview)) {
    // Payload tchat = addon + question : on loggue un marqueur court
    preview = '[message tchat — voir access.log pour la question exacte]';
  } else if (preview.indexOf('Question :') === 0 || preview.indexOf('Question:') === 0) {
    // expand/select ne passent plus ici ; si un residual
    preview = preview.replace(/^Question\s*:\s*/i, '');
  }
  if (preview.length > 400) {
    preview = preview.substring(0, 400) + '...';
  }
  var line = '[' + formatTimestamp() + '] IP=' + clientIp
    + ' | action=' + purpose
    + ' | requete=' + (preview !== '' ? preview : '(vide)')
    + '\n';
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

const DEFAULT_LLM_ENGINE = 'groq';
const LLM_ENGINE = (process.env.LLM_ENGINE || DEFAULT_LLM_ENGINE).toLowerCase();
// Liste des moteurs LLM fallback, dans l'ordre de preference.
// Peut etre surchargee par la variable d'environnement LLM_FALLBACK_ORDER.
// 'cerebras,groq,mistral,openrouter'
const DEFAULT_FALLBACK_ORDER = 'groq,mistral,openrouter';
const LLM_FALLBACK_ORDER = (process.env.LLM_FALLBACK_ORDER || DEFAULT_FALLBACK_ORDER)
  .split(',')
  .map(function(item) { return item.trim().toLowerCase(); })
  .filter(Boolean);

// Prompts : fichier dedie (editer prompts.js, puis restart Node).
// CORRECTIF 16/08/2026 : externalisation. CORRECTIF 19/08/2026 : chat-route / chat-talk.
const prompts = require('./prompts.js');
const STYLE_RULES = prompts.STYLE_RULES;
const QUERY_KEYWORD_PROMPT = prompts.QUERY_KEYWORD_PROMPT;
const QUERY_EXPAND_PROMPT = prompts.QUERY_EXPAND_PROMPT;
const QUERY_PROMPT = prompts.QUERY_PROMPT,
  QUERY_CHAT_PROMPT = prompts.QUERY_CHAT_PROMPT;
const QUERY_SELECT_PROMPT = prompts.QUERY_SELECT_PROMPT;
const MERGE_CHECK_PROMPT = prompts.MERGE_CHECK_PROMPT;
const MERGE_SMART_PROMPT = prompts.MERGE_SMART_PROMPT;
const LOCATION_PROMPT = prompts.LOCATION_PROMPT;
const SAISIE_PROMPT = prompts.SAISIE_PROMPT;
const CHAT_ROUTE_PROMPT = prompts.CHAT_ROUTE_PROMPT;
const CHAT_TALK_PROMPT = prompts.CHAT_TALK_PROMPT;

// LLM_ENGINES contient la configuration de chaque moteur LLM supporté, avec la fonction createPayload() pour construire la requête API.
const createOpenAICompatiblePayload = (text, model, context, purpose) => {
  const messages = [];
  if (purpose === 'location') messages.push({ role: 'system', content: LOCATION_PROMPT });
  else if (purpose === 'query-keywords') messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
  else if (purpose === 'query-expand') messages.push({ role: 'system', content: QUERY_EXPAND_PROMPT });
  else if (purpose === 'query-select') messages.push({ role: 'system', content: QUERY_SELECT_PROMPT });
  else if (purpose === 'query-chat') messages.push({ role: 'system', content: QUERY_CHAT_PROMPT });
  else if (purpose === 'query') messages.push({ role: 'system', content: QUERY_PROMPT });
  else if (purpose === 'merge-check') messages.push({ role: 'system', content: MERGE_CHECK_PROMPT });
  else if (purpose === 'merge-smart') messages.push({ role: 'system', content: MERGE_SMART_PROMPT });
  else if (purpose === 'chat-route') messages.push({ role: 'system', content: CHAT_ROUTE_PROMPT });
  else if (purpose === 'chat-talk') messages.push({ role: 'system', content: CHAT_TALK_PROMPT });
  else messages.push({ role: 'system', content: SAISIE_PROMPT });
    if (context && purpose !== 'chat-route' && purpose !== 'chat-talk') {
      messages.push({ role: 'system', content: 'Contexte instructions (memoire) : ' + context });
    }
  const userContent = (purpose === 'query' || purpose === 'query-chat')
    ? 'Recherche dans instructions.md : ' + text
    : (purpose === 'query-select' || purpose === 'query-expand' || purpose === 'chat-route' || purpose === 'chat-talk')
      ? text
      : (purpose === 'merge-check')
        ? 'Texte importe a comparer avec la memoire :\n' + text
        : (purpose === 'merge-smart')
          ? 'TEXTE NOUVEAU a fusionner avec la memoire ci-dessus :\n' + text
          : 'Texte a reformuler pour le memoire : ' + text;
  messages.push({ role: 'user', content: userContent });
  const temperature = (purpose === 'rewrite' || purpose === 'location' || purpose === 'query-select' || purpose === 'query-expand' || purpose === 'merge-check' || purpose === 'merge-smart' || purpose === 'chat-route' || purpose === 'chat-talk') ? 0.2
    : purpose === 'query-keywords' ? 0.0 : 0.4;
  // merge-smart : reponses longues (deja + nouveau + texte fusionne + emplacement)
  const maxTokens = (purpose === 'merge-smart') ? 4000
    : (purpose === 'chat-route') ? 400
    : (purpose === 'chat-talk') ? 400
    : (purpose === 'query' || purpose === 'query-chat') ? 1200
    : 1500;
  return { model: model, messages: messages, temperature: temperature, max_tokens: maxTokens };
};

// DOCUMENTATION DES MODELES PAR PROVIDER (2026/09) :
// - Cerebras   : 'llama-3.1-8b' (ultra rapide, idéal pour le routage chat-route)
// - Groq       : 'AUTODETECT' ou modèle explicite (ex: 'llama-3.3-70b-versatile')
// - Mistral    : 'mistral-small-latest'
// - OpenRouter : 'openrouter/free'

// Stockage en mémoire du dernier statut réel de chaque moteur
const engineRuntimeStatus = {
  cerebras: { status: 'unknown', code: null, cooldownUntil: 0 },
  groq: { status: 'unknown', code: null, cooldownUntil: 0 },
  mistral: { status: 'unknown', code: null, cooldownUntil: 0 },
  openrouter: { status: 'unknown', code: null, cooldownUntil: 0 }
};
const ENGINE_COOLDOWN_MS = {
  402: 30 * 60 * 1000,
  404: 10 * 60 * 1000,
  429: 90 * 1000,
  401: 15 * 60 * 1000
};

const LLM_ENGINES = {
  // Cerebras
  cerebras: {
    name: 'Cerebras',
    apiKeyEnv: 'CEREBRAS_API_KEY',
    modelEnv: 'CEREBRAS_MODEL',
    apiBase: process.env.CEREBRAS_API_BASE || 'https://api.cerebras.ai/v1',
    engineUrl: 'https://cloud.cerebras.ai',
    defaultModel: 'gpt-oss-120b',
    models: ['gpt-oss-120b'],
    createPayload: createOpenAICompatiblePayload,
  },
  // Groq
  groq: {
    name: 'Groq',
    apiKeyEnv: 'GROQ_API_KEY',
    modelEnv: 'GROQ_MODEL',
    apiBase: process.env.GROQ_API_BASE || 'https://api.groq.com/openai/v1',
    engineUrl: 'https://console.groq.com/home',
    defaultModel: 'openai/gpt-oss-120b',
    models: ['openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
    createPayload: createOpenAICompatiblePayload
  },
  // Mistral
  mistral: {
    name: 'Mistral',
    apiKeyEnv: 'MISTRAL_API_KEY',
    modelEnv: 'MISTRAL_MODEL',
    apiBase: process.env.MISTRAL_API_BASE || 'https://api.mistral.ai/v1',
    engineUrl: 'https://console.mistral.ai',
    defaultModel: 'mistral-small-latest',
    models: ['mistral-small-latest'],
    createPayload: createOpenAICompatiblePayload,
  },
  // OpenRouter
  openrouter: {
      name: 'OpenRouter',
      apiKeyEnv: 'OPENROUTER_API_KEY',
      modelEnv: 'OPENROUTER_MODEL',
      apiBase: process.env.OPENROUTER_API_BASE || 'https://openrouter.ai/api/v1',
      engineUrl: 'https://openrouter.ai',
      defaultModel: 'openrouter/free',
      models: [
        'openrouter/free',
        'deepseek/deepseek-chat',
        'google/gemini-2.0-flash-lite-preview:free',
        'mistralai/mistral-small-3.2-2409:free'
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

// Retourne la liste des moteurs LLM disponibles (avec clef API présente)
const getAvailableEngines = function() {
  return Object.keys(LLM_ENGINES).filter(function(name) {
    var eng = LLM_ENGINES[name];
    return !!(eng && process.env[eng.apiKeyEnv]);
  });
};

const getCurrentEngineInfo = function() {
  var engine = getEngineConfig(LLM_ENGINE);
  var selectedModel = getEngineModel(LLM_ENGINE);
  var apiKeys = {};
  Object.keys(LLM_ENGINES).forEach(function(name) {
    var eng = LLM_ENGINES[name];
    var key = process.env[eng.apiKeyEnv] || '';
    apiKeys[name] = key ? 'present' : 'absent';
  });
  return {
    defaultEngine: LLM_ENGINE,
    engineName: engine && engine.name ? engine.name : LLM_ENGINE,
    engineUrl: engine && engine.engineUrl ? engine.engineUrl : '',
    selectedModel: selectedModel,
    fallbackOrder: LLM_FALLBACK_ORDER,
    availableEngines: getAvailableEngines(),
    modelCandidates: engine && engine.models ? engine.models : [],
    apiKeysStatus: apiKeys,
    enginesRuntimeStatus: engineRuntimeStatus
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
const isEngineCooling = function(engineName) {
  var st = engineRuntimeStatus[engineName];
  if (!st || !st.cooldownUntil) return false;
  return Date.now() < st.cooldownUntil;
};
const markEngineCooldown = function(engineName, status) {
  var ms = ENGINE_COOLDOWN_MS[status] || 0;
  if (!ms) return;
  if (!engineRuntimeStatus[engineName]) {
    engineRuntimeStatus[engineName] = { status: 'error', code: status, cooldownUntil: 0 };
  }
  engineRuntimeStatus[engineName].cooldownUntil = Date.now() + ms;
  engineRuntimeStatus[engineName].status = 'error';
  engineRuntimeStatus[engineName].code = status;
};
const isPayloadTooLargeError = function(status, errMsg) {
  if (status === 413) return true;
  var s = String(errMsg || '');
  return /Request too large/i.test(s) || (/rate_limit_exceeded/i.test(s) && /TPM|tokens per minute|Requested [0-9]+/i.test(s));
};
const maxContextCharsForEngine = function(engineName, purpose) {
  if (purpose === 'chat-route' || purpose === 'chat-talk' || purpose === 'query-keywords') return 0;
  if (engineName === 'groq') return 12000;
  if (engineName === 'cerebras') return 16000;
  return 24000;
};
const truncateContext = function(context, maxChars) {
  context = String(context || '');
  if (maxChars <= 0) return '';
  if (context.length <= maxChars) return context;
  return context.slice(0, maxChars) + '\n...[contexte tronque pour rester sous les quotas TPM]...';
};
const modelsToTryForEngine = function(engineName) {
  var engine = getEngineConfig(engineName);
  if (!engine) return [];
  var primary = getEngineModel(engineName);
  var list = [];
  if (primary) list.push(primary);
  (engine.models || []).forEach(function(m) {
    if (list.indexOf(m) === -1) list.push(m);
  });
  return list;
};
const reformulate = async function(text, context, purpose, preferredEngine) {
  context = context || '';
  purpose = purpose || 'rewrite';
  preferredEngine = preferredEngine || null;

  var lastError = null;
  var attempts = [];

  var naturalOrder = LLM_FALLBACK_ORDER.filter(Boolean);
  var engineOrder;
  if (preferredEngine && LLM_ENGINES[preferredEngine]) {
    engineOrder = [preferredEngine].concat(naturalOrder.filter(function(e) { return e !== preferredEngine; }));
  } else {
    engineOrder = naturalOrder;
  }

  var allErrors = [];

  for (var i = 0; i < engineOrder.length; i++) {
    var engineName = engineOrder[i];
    var engine = getEngineConfig(engineName);
    if (!engine) continue;

    var apiKey = process.env[engine.apiKeyEnv];
    if (!apiKey) {
      attempts.push({ engine: engineName, model: null, status: 'skipped', error: 'clef API absente' });
      continue;
    }

    if (isEngineCooling(engineName)) {
      var remain = Math.ceil(((engineRuntimeStatus[engineName].cooldownUntil || 0) - Date.now()) / 1000);
      attempts.push({ engine: engineName, model: null, status: 'skipped', error: 'cooldown ' + remain + 's' });
      continue;
    }

    var workingContext = truncateContext(context, maxContextCharsForEngine(engineName, purpose));
    var modelCandidates = modelsToTryForEngine(engineName);
    var payloadTooLargeTries = 0;

    for (var m = 0; m < modelCandidates.length; m++) {
      var model = modelCandidates[m];

    var retryCount = 0;
    var maxRetries = 1;

    while (true) {
      var payload = engine.createPayload(text, model, workingContext, purpose);
        if (engineName === 'groq' && payload.max_tokens > 1200) {
          payload.max_tokens = 1200;
        }
      var requestUrl = buildRequestUrl(engine.apiBase);

      try {
        var response = await axios.post(requestUrl, payload, {
          timeout: 15000,
          headers: {
            'Authorization': 'Bearer ' + apiKey,
            'Content-Type': 'application/json',
            'HTTP-Referer': 'https://charreyre.net',
            'X-Title': 'Reformulator'
          }
        });

        attempts.push({ engine: engineName, model: model, status: 'success', error: null });
        engineRuntimeStatus[engineName] = { status: 'success', code: 200 };
        if (response.data && response.data.choices && response.data.choices[0] && response.data.choices[0].message) {
          var cleaned = response.data.choices[0].message.content;
          if (cleaned) {
            // SUCCES : on sort immédiatement de la fonction, le fallback a fait son job !
            return {
              cleaned: cleaned.trim(),
              engine: engineName,
              model: model,
              attempts: attempts
            };
          }
        }
        break;

      } catch (error) {
        lastError = error;
        var status = (error.response && error.response.status) ? error.response.status : 'unknown';
        var errMsg = '';
        if (error.response && error.response.data) {
          errMsg = JSON.stringify(error.response.data);
        } else {
          errMsg = error.message || String(error);
        }

        if (isPayloadTooLargeError(status, errMsg) && payloadTooLargeTries < 2 && workingContext.length > 800) {
          payloadTooLargeTries++;
          workingContext = truncateContext(workingContext, Math.max(800, Math.floor(workingContext.length * 0.45)));
          attempts.push({ engine: engineName, model: model, status: 'HTTP ' + status + ' retry-tronque', error: 'contexte ramene a ' + workingContext.length + ' car.' });
          continue;
        }

        if (status === 429 && retryCount < maxRetries) {
          retryCount++;
          var waitSec = extractRetryAfterSeconds(error);
          var waitMs = Math.min(8000, Math.max(1000, (waitSec > 0 ? waitSec * 1000 : 1500)));
          await sleep(waitMs);
          continue;
        }

        attempts.push({ engine: engineName, model: model, status: 'HTTP ' + status, error: errMsg });
        engineRuntimeStatus[engineName] = { status: 'error', code: status };
        allErrors.push(engineName + ':' + status + ' ' + errMsg);
          if (status === 404 && m < modelCandidates.length - 1) {
            break;
          }
          markEngineCooldown(engineName, status);
          break;
        }
      }
    }
  }

  // Si on arrive ici, c'est que TOUTE la chaîne a échoué. On logue l'échec global une seule fois proprement.
  if (allErrors.length > 0) {
    logError('Fallback global echoue. Sequence testee : ' + allErrors.join(' | '));
  }
  var finalErr = lastError || new Error('Tous les moteurs du fallback ont echoue');
  finalErr.attempts = attempts;
  throw finalErr;
};

// Routes statiques
app.get('/status', function(req, res) { res.type('text/plain').send('Moteurs service is alive'); });
app.get('/r3M3M83r/moteurs/status', function(req, res) { res.type('text/plain').send('Moteurs service is alive'); });
app.get('/moteurs/status', function(req, res) { res.type('text/plain').send('Moteurs service is alive'); });
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
  res.json(Object.assign({ status: 'ok', base: 'moteurs', routes: ['/status','/llm-info','/reformuler'] }, getCurrentEngineInfo()));
});
app.get('/r3M3M83r/moteurs', function(req, res) {
  res.json(Object.assign({ status: 'ok', base: 'moteurs', routes: ['/status','/llm-info','/reformuler'] }, getCurrentEngineInfo()));
});
app.get('/r3M3M83r/moteurs/', function(req, res) {
  res.json(Object.assign({ status: 'ok', base: 'moteurs', routes: ['/status','/llm-info','/reformuler'] }, getCurrentEngineInfo()));
});

var lastLlmInfoProbeAt = 0;
var LLM_INFO_PROBE_TTL_MS = 5 * 60 * 1000;

const handleLlmInfo = async function(req, res) {
  var force = String((req.query && (req.query.probe || req.query.reset)) || '') === '1';
  var now = Date.now();
  var unknownCount = 0;
  Object.keys(engineRuntimeStatus).forEach(function(name) {
    if (!engineRuntimeStatus[name] || engineRuntimeStatus[name].status === 'unknown') unknownCount++;
  });
  var shouldProbe = force || unknownCount > 0 || (now - lastLlmInfoProbeAt > LLM_INFO_PROBE_TTL_MS);

  if (shouldProbe) {
    lastLlmInfoProbeAt = now;
    const engines = getAvailableEngines();
    for (let i = 0; i < engines.length; i++) {
      const engName = engines[i];
      const eng = LLM_ENGINES[engName];
      if (!eng) continue;
      const apiKey = process.env[eng.apiKeyEnv];
      if (!apiKey) continue;
      if (isEngineCooling(engName) && !force) continue;
      try {
        const payload = eng.createPayload('ping', getEngineModel(engName), '', 'chat-route');
        await axios.post(buildRequestUrl(eng.apiBase), payload, {
          timeout: 3000,
          headers: { 'Authorization': 'Bearer ' + apiKey, 'Content-Type': 'application/json' }
        });
        engineRuntimeStatus[engName] = { status: 'success', code: 200, cooldownUntil: 0 };
      } catch (err) {
        const status = err.response && err.response.status ? err.response.status : 500;
        engineRuntimeStatus[engName] = { status: 'error', code: status, cooldownUntil: 0 };
        markEngineCooldown(engName, status);
      }
    }
  }
  res.json(getCurrentEngineInfo());
};

app.get('/llm-info', handleLlmInfo);
app.get('/r3M3M83r/moteurs/llm-info', handleLlmInfo);
app.get('/r3M3M83r/reformulator/llm-info', handleLlmInfo);
app.get('/moteurs/llm-info', handleLlmInfo);

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
    // Journal humain : IP + question complete (apres extraction fichier si besoin)
    logRequest(req, text);
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
// Compat chemins Passenger (URL complete avec prefixe application)
app.post('/r3M3M83r/moteurs/reformuler', function(req, res) {
  req.url = '/reformuler';
  app.handle(req, res);
});
app.post('/moteurs/reformuler', function(req, res) {
  req.url = '/reformuler';
  app.handle(req, res);
});
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