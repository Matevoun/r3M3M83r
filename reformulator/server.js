/**
 * reformulator/server.js
 * (voir entête complet dans la version précédente — inchangé)
 */
const fs = require('fs');
const path = require('path');
const express = require('express');
const axios = require('axios');
const dotenv = require('dotenv');
const cors = require('cors');

dotenv.config();
process.env.TZ = 'Europe/Paris';

const LOG_DIR = path.join(__dirname, 'log');
const ERROR_LOG_FILE = path.join(LOG_DIR, 'error.log');
const REQUESTS_LOG_FILE = path.join(LOG_DIR, 'requests.log');
const LEGACY_ERROR_LOG_FILE = path.join(__dirname, '..', 'error_log');

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

const app = express();
app.use(cors());
app.use(express.json());

const PORT = process.env.PORT || 3000;

const DEFAULT_LLM_ENGINE = 'groq';
const LLM_ENGINE = (process.env.LLM_ENGINE || DEFAULT_LLM_ENGINE).toLowerCase();
const DEFAULT_FALLBACK_ORDER = 'groq,cerebras,mistral,openrouter';
const LLM_FALLBACK_ORDER = (process.env.LLM_FALLBACK_ORDER || DEFAULT_FALLBACK_ORDER)
  .split(',').map(function(item) { return item.trim().toLowerCase(); }).filter(Boolean);

const SAISIE_PROMPT = "Tu es un assistant correcteur orthographique et de style appelé Reformulator. Mathieu, l'utilisateur, te donne un texte brut avec des fautes. Il s'agit de ses souvenirs personnels. Si le texte utilise la première personne (je, moi, mon, ma, mes, nous, notre, nos), tu le transforms impérativement en troisième personne avec Mathieu comme sujet. Ne conserve jamais de formes de première personne dans la réponse. Ne commences jamais par une salutation ni par une formule d'accueil. Ne réponds pas par une phrase de présentation. Ensuite, tu dois UNIQUEMENT corriger les fautes d'orthographe, de grammaire et de frappe, et améliorer légèrement la fluidité. Tu réponds toujours en français correct et naturel. Structure en paragraphes clairs. Tu ne poses JAMAIS de questions. Tu ne commentes JAMAIS le texte. Ne change pas les faits, tu ne rajoutes RIEN. Ne reformule pas en ajoutant du contenu ou des détails inexistants. Tu retournes UNIQUEMENT le texte reformulé, sans introduction, sans explication, sans guillemets. Si le texte est très court, tu le retournes simplement corrigé.";
const LOCATION_PROMPT = "Tu es un assistant expert en placement de contenu dans un document de référence appelé Reformulator. Reçois un texte à ajouter dans instructions.md et, en te basant sur la structure des sections disponibles, propose l'endroit ou les endroits les plus pertinents où insérer ce texte. Comprends que le texte peut être un souvenir raconté à la première personne pour Mathieu, et choisis la ou les sections les plus pertinentes du mémoire. Donne une réponse courte, claire et précise, en mentionnant la section ou la position d'insertion. N'ajoute pas de contenu supplémentaire. Ne reformule pas le texte, répond seulement à la question de placement poliment.";
const QUERY_KEYWORD_PROMPT = "Tu es un assistant expert en extraction d'intention de recherche appelé Reformulator. Reçois une question à propos d'un document et retourne seulement les mots-clefs ou expressions clefs qui permettent de chercher la réponse dans ce document. Répond en une seule ligne, avec des mots ou expressions séparés par des virgules. Ne réponds pas par une phrase complète, ne donne pas de salutations et ne rajoute pas de texte inutile.";
const QUERY_PROMPT = `Tu es un assistant appelé Reformulator, expert et chaleureux qui connaît très bien la mémoire personnelle de Mathieu CHARREYRE.
Réponds de façon naturelle, humaine et bienveillante en français.
- Indique clairement le nombre d'occurrences si pertinent.
- Cite 2 à 4 extraits concrets et pertinents.
- Termine par un petit avis constructif ou une réflexion intelligente (jamais moralisateur).
Si le sujet n'est pas mentionné : dis-le gentiment et propose éventuellement des pistes proches.`;

const createOpenAICompatiblePayload = (text, model, context, purpose) => {
  const messages = [];
  if (purpose === 'location') messages.push({ role: 'system', content: LOCATION_PROMPT });
  else if (purpose === 'query-keywords') messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
  else if (purpose === 'query') messages.push({ role: 'system', content: QUERY_PROMPT });
  else messages.push({ role: 'system', content: SAISIE_PROMPT });
  if (context) messages.push({ role: 'system', content: 'Contexte instructions : ' + context });
  const userContent = purpose === 'query'
    ? 'Recherche dans instructions.md : ' + text
    : 'Texte a reformuler pour le memoire : ' + text;
  messages.push({ role: 'user', content: userContent });
  const temperature = (purpose === 'rewrite' || purpose === 'location') ? 0.2 : purpose === 'query-keywords' ? 0.0 : 0.4;
  return { model: model, messages: messages, temperature: temperature, max_tokens: 1500 };
};

const LLM_ENGINES = {
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
      else if (purpose === 'query') messages.push({ role: 'system', content: QUERY_PROMPT });
      else messages.push({ role: 'system', content: SAISIE_PROMPT });
      if (context) messages.push({ role: 'system', content: 'Contexte instructions : ' + context });
      const userContent = purpose === 'query'
        ? 'Recherche dans instructions.md : ' + text
        : 'Texte à reformuler pour le mémoire : ' + text;
      messages.push({ role: 'user', content: userContent });
      const temperature = (purpose === 'rewrite' || purpose === 'location') ? 0.2 : purpose === 'query-keywords' ? 0.0 : 0.4;
      return { model: model, messages: messages, temperature: temperature, max_tokens: 1500 };
    }
  },
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
  openrouter: {
    name: 'OpenRouter',
    apiKeyEnv: 'OPENROUTER_API_KEY',
    modelEnv: 'OPENROUTER_MODEL',
    apiBase: process.env.OPENROUTER_API_BASE || 'https://openrouter.ai/api/v1',
    engineUrl: 'https://openrouter.ai',
    defaultModel: 'meta-llama/llama-3.3-70b-instruct:free',
    models: [
      'meta-llama/llama-3.3-70b-instruct:free',
      'google/gemma-2-9b-it:free',
      'openai/gpt-oss-120b:free',
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

const buildRequestUrl = function(baseUrl) {
  var normalized = String(baseUrl).trim().replace(/\/+$/, '');
  if (normalized.endsWith('/chat/completions') || normalized.endsWith('/completions')) return normalized;
  return normalized + '/chat/completions';
};

const sleep = function(ms) { return new Promise(function(resolve) { setTimeout(resolve, ms); }); };

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

registerReformulationRoute('/reformuler');
registerReformulationRoute('/r3M3M83r/reformulator/reformuler');

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