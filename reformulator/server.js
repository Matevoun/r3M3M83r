/**
 * reformulator/server.js
 * Version avec support upload de fichiers + Mistral par défaut + logique dynamique modèles
 * Mise à jour 29/06/2026 - Mathieu CHARREYRE
 */

const fs = require('fs');
const path = require('path');
const express = require('express');
const axios = require('axios');
const dotenv = require('dotenv');
const cors = require('cors');

const multer = require('multer');
const pdf = require('pdf-parse');
const mammoth = require('mammoth');

const upload = multer({
  dest: 'uploads/',
  limits: { fileSize: 15 * 1024 * 1024 }
});

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
app.use(express.json());

// ==================== CONFIG LLM ====================
const PORT = process.env.PORT || 3000;

const DEFAULT_LLM_ENGINE = 'mistral';
const LLM_ENGINE = (process.env.LLM_ENGINE || DEFAULT_LLM_ENGINE).toLowerCase();
const DEFAULT_FALLBACK_ORDER = 'mistral,groq,cerebras,openrouter';
const LLM_FALLBACK_ORDER = (process.env.LLM_FALLBACK_ORDER || DEFAULT_FALLBACK_ORDER)
  .split(',')
  .map(function(item) { return item.trim().toLowerCase(); })
  .filter(Boolean);

// Prompts optimisés - Mise à jour 29/06/2026
// Reformulation avancée avec IA
const QUERY_KEYWORD_PROMPT = `Tu es un expert en extraction de mots-clés. À partir de la question suivante sur la vie de Mathieu CHARREYRE, retourne UNIQUEMENT une liste de mots-clés ou expressions séparés par des virgules (maximum 8 termes). Pas de phrase, pas de salutation, pas d'explication.`;
const QUERY_PROMPT = `Tu es Reformulator, assistant très précis qui connaît parfaitement la mémoire personnelle de Mathieu CHARREYRE.

Réponds de façon naturelle et concise en français.
- Recherche activement toute mention du sujet demandé.
- Cite les extraits exacts avec le titre de la section.
- Indique le nombre d'occurrences.
- Si rien n'est trouvé, dis-le franchement.
Ne tourne pas autour du pot.`;
// Proposer emplacement
const LOCATION_PROMPT = `Tu es un expert en organisation de mémoire personnelle. Reçois un texte sur la vie de Mathieu CHARREYRE et propose les sections les plus pertinentes où l'insérer dans instructions.md.
Réponds de façon courte, précise et structurée. Mentionne explicitement les titres de sections recommandées. Ne reformule pas le texte lui-même.`;
// Interroger le fichier
const SAISIE_PROMPT = `Tu es Reformulator, un assistant orthographique et stylistique précis pour Mathieu CHARREYRE.
Règles strictes :
- Si le texte est à la première personne (je, moi, mon, ma, mes, nous...), transforme-le obligatoirement en troisième personne avec "Mathieu" comme sujet.
- Ne conserve AUCUNE forme à la première personne dans la réponse finale.
- Corrige uniquement l'orthographe, la grammaire, la ponctuation et améliore légèrement la fluidité.
- Ne change pas les faits, n'ajoute rien, ne supprime rien d'important.
- Structure en paragraphes clairs et naturels.
- Réponds UNIQUEMENT par le texte corrigé/reformulé, sans introduction, sans explication, sans guillemets, sans salutation.
- Si le texte est très court, corrige-le simplement.`;

// LLM_ENGINES contient la configuration de chaque moteur LLM supporté, avec la fonction createPayload() pour construire la requête API.
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
async function extractTextFromFile(file) {
  try {
    const ext = path.extname(file.originalname).toLowerCase();
    console.log(`[EXTRACTION] Fichier : ${file.originalname} (${ext}) - taille ${file.size} octets`);

    if (ext === '.pdf') {
      const dataBuffer = fs.readFileSync(file.path);
      const data = await pdf(dataBuffer);
      let extracted = data.text ? data.text.trim() : '';

      if (extracted.length < 30) {
        extracted = "[PDF chargé : " + file.originalname + " — texte non extractible (PDF scanné ou protégé)]";
        console.log(`[WARN] PDF trop court ou vide (${extracted.length} caractères)`);
      } else {
        console.log(`[SUCCESS] PDF extrait avec succès : ${extracted.length} caractères`);
      }
      return extracted;
    }

    if (ext === '.docx') {
      const result = await mammoth.extractRawText({ path: file.path });
      return result.value.trim() || `[DOCX vide ou illisible : ${file.originalname}]`;
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

// Route principale avec upload + mode extraction
app.post('/reformuler', upload.single('file'), async function(req, res) {
  logRequest(req);

  let text = (req.body && req.body.text) || '';
  const file = req.file;

  // Récupération du purpose même en multipart
  const purpose = (req.body && req.body.purpose) ? req.body.purpose.toLowerCase() : 'rewrite';

  console.log(`[ROUTE] Purpose reçu : ${purpose} | Fichier : ${file ? file.originalname : 'aucun'}`);

  // === EXTRACTION DE FICHIER ===
  if (file) {
    const extracted = await extractTextFromFile(file);
    text = extracted + "\n\n" + text;
    try { fs.unlinkSync(file.path); } catch(e) {}
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
app.post('/r3M3M83r/reformulator/reformuler', upload.single('file'), function(req, res) {
  req.url = '/reformuler';
  app.handle(req, res);
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