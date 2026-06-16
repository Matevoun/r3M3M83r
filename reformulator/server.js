/**
 * reformulator/server.js
 *
 * Service Node.js local utilise par saisie.php pour reformuler du texte via
 * l'API Groq / OpenAI. Ce serveur expose deux routes POST en alias :
 *   - /reformuler
 *   - /r3M3M83r/reformulator/reformuler
 *
 * Le texte est envoye en tant que message utilisateur, precede d'un prompt
 * systeme unique qui fixe les consignes de correction et de reformulation.
 *
 * Ce fichier est la source de verite pour la configuration LLM :
 *   - fournisseur actif (LLM_ENGINE)
 *   - ordre de fallback (LLM_FALLBACK_ORDER)
 *   - modele par fournisseur (GROQ_MODEL, OPENAI_MODEL, ...)
 *   - liste des moteurs et des URL de console
 *   - routage de l'API pour les interfaces clientes
 *
 * O2Switch / cPanel Node.js :
 *   - Placer ce dossier sous public_html/CHARREYRE/r3M3M83r/reformulator.
 *   - Dans cPanel, ajouter/modifier l'application Node.js pour utiliser
 *     server.js comme "Application startup file".
 *   - Définir la variable d'environnement GROQ_API_KEY dans l'interface
 *     de configuration Node.js de cPanel. Ne pas mettre la clef en dur dans
 *     le code source.
 *   - Pour choisir un modèle spécifique par fournisseur, utiliser aussi :
 *       GROQ_MODEL=llama-3.1-8b-instant
 *       OPENAI_MODEL=gpt-4o-mini
 *   - Pour un basculement automatique, utiliser aussi :
 *       LLM_ENGINE=groq
 *       LLM_FALLBACK_ORDER=groq,openai
 *     et ajouter la clef OPENAI_API_KEY si OpenAI en second.
 *   - Si cPanel fixe le port de l'application, laisser PORT configurable via
 *     process.env.PORT. Sinon, la valeur par défaut 3000 est utilisée.
 *   - Après chaque mise à jour du code, du package ou des variables d'env,
 *     redémarrer l'application Node.js depuis cPanel pour prendre en compte
 *     les changements.
 *   - Si l'application Node.js est arrêtée ou mal configurée, saisie.php
 *     ne pourra pas atteindre /reformuler et la reformulation échouera.
 *
 * Note de compatibilité cPanel/o2switch :
 *   - Certaines versions de Node.js fournies par cPanel/Passenger ne supportent
 *     pas la syntaxe moderne `?.` (optional chaining).
 *   - Le code ici a été volontairement écrit sans optional chaining pour rester
 *     compatible avec ces environnements.
 *   - Si vous réintroduisez `?.`, le démarrage peut échouer directement.
 *
 * En local :
 *   - créer un fichier .env avec GROQ_API_KEY=... et éventuellement PORT=...
 *   - lancer `node server.js` ou `npm start` selon la configuration.
 *
 * Remarque : La configuration LLM peut être changée directement dans ce fichier.
 *   C’est le moyen le plus simple si cPanel ne permet pas de gérer les
 *   variables d'environnement. Modifier `LLM_ENGINE` ou `LLM_ENGINES` ci-dessous
 *   suffit pour choisir un moteur et un modèle.
 */
const fs = require('fs');
const path = require('path');
const express = require('express');
const axios = require('axios');
const dotenv = require('dotenv');
const cors = require('cors');

dotenv.config();

const LOG_DIR = path.join(__dirname, 'log');
const ERROR_LOG_FILE = path.join(LOG_DIR, 'error.log');
const REQUESTS_LOG_FILE = path.join(LOG_DIR, 'requests.log');
const LEGACY_ERROR_LOG_FILE = path.join(__dirname, '..', 'error_log');

const formatTimestamp = () => new Date().toISOString().replace('T', ' ').replace('Z', '');
const writeLog = (filePath, line) => {
  try {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    fs.appendFileSync(filePath, line, 'utf8');
  } catch (writeError) {
    console.error(`Impossible d'écrire dans ${filePath}:`, writeError && writeError.stack ? writeError.stack : String(writeError));
  }
};

const logError = (message) => {
  const line = `[${formatTimestamp()}] ${message.replace(/\r?\n/g, ' ')}\n`;
  writeLog(ERROR_LOG_FILE, line);
  console.error(message);
};

const logRequest = (req) => {
  // Important : ne pas utiliser optional chaining ici, car certains environnements
  // cPanel/Passenger utilisent une version de Node.js qui ne le supporte pas.
  const clientIp = (req.headers['x-forwarded-for'] || req.ip || (req.socket && req.socket.remoteAddress) || 'inconnue').toString().split(',')[0].trim();
  const textLength = typeof (req.body && req.body.text) === 'string' ? req.body.text.length : 0;
  const line = `[${formatTimestamp()}] ${req.method} ${req.originalUrl} IP=${clientIp} text_length=${textLength}\n`;
  writeLog(REQUESTS_LOG_FILE, line);
};

const ensureLogFile = (filePath) => {
  try {
    fs.mkdirSync(path.dirname(filePath), { recursive: true });
    if (!fs.existsSync(filePath)) {
      fs.writeFileSync(filePath, '', { flag: 'a', encoding: 'utf8' });
      try {
        fs.chmodSync(filePath, 0o644);
      } catch (chmodError) {
        // Continue même si la modification de permissions échoue.
      }
    }
  } catch (error) {
    console.error(`Impossible de créer ou vérifier ${filePath}:`, error && error.stack ? error.stack : String(error));
  }
};

const migrateOldErrorLog = () => {
  if (fs.existsSync(LEGACY_ERROR_LOG_FILE) && !fs.existsSync(ERROR_LOG_FILE)) {
    try {
      fs.renameSync(LEGACY_ERROR_LOG_FILE, ERROR_LOG_FILE);
      console.log(`Ancien error_log déplacé vers ${ERROR_LOG_FILE}`);
    } catch (error) {
      console.error('Impossible de déplacer l ancien error_log:', error && error.stack ? error.stack : String(error));
    }
  }
};

migrateOldErrorLog();
ensureLogFile(ERROR_LOG_FILE);
ensureLogFile(REQUESTS_LOG_FILE);

console.log('server.js loaded with Groq fallback improvements');

process.on('uncaughtException', (error) => {
  const message = error && error.stack ? error.stack : String(error);
  logError(`uncaughtException: ${message}`);
});
process.on('unhandledRejection', (reason) => {
  const message = reason && reason.stack ? reason.stack : String(reason);
  logError(`unhandledRejection: ${message}`);
});

const app = express();
app.use(cors());
app.use(express.json());

const PORT = process.env.PORT || 3000;

// -----------------------------------------------------------------------------
// Configuration LLM principale
// -----------------------------------------------------------------------------
// Valeurs par défaut utilisées par l'application. Modifier ces constantes
// permet de changer le moteur et le modèle sans toucher au reste du code.
//
// - DEFAULT_LLM_ENGINE : nom du moteur par défaut. Ce nom doit exister dans
//   la liste `LLM_ENGINES` plus bas, par exemple `groq`.
//
// - LLM_ENGINE : moteur effectif utilisé en runtime. Il prend la valeur de
//   process.env.LLM_ENGINE si elle existe, sinon DEFAULT_LLM_ENGINE.
//
// - LLM_FALLBACK_ORDER : chaîne de noms de moteurs séparés par des virgules.
//   Par défaut, il vaut le même moteur que LLM_ENGINE, donc il n'y a pas de
//   fallback automatique.
//   Exemple si vous ajoutez un moteur `openai` dans `LLM_ENGINES` :
//     LLM_FALLBACK_ORDER = 'groq,openai'
//   ou en variable d'environnement :
//     LLM_FALLBACK_ORDER=groq,openai
//
// #sym:LLM_FALLBACK_ORDER — modifier ici pour changer la stratégie de secours.
//   Exemple :
//     const LLM_FALLBACK_ORDER = 'groq,openai';
//   Cela signifie : tester d'abord groq, puis openai si groq ne répond pas.
//
// Si cPanel ne permet pas de définir des variables d'environnement, vous
// pouvez modifier directement ces constantes dans ce fichier.
const DEFAULT_LLM_ENGINE = 'groq';
const LLM_ENGINE = (process.env.LLM_ENGINE || DEFAULT_LLM_ENGINE).toLowerCase();
// Ordre de tentative par defaut : Groq, puis Cerebras, Mistral, OpenRouter.
// Seuls les moteurs dont la variable d'environnement API_KEY est definie sont utilises.
// Configurable via la variable d'env : LLM_FALLBACK_ORDER=groq,cerebras,mistral,openrouter
const DEFAULT_FALLBACK_ORDER = 'groq,cerebras,mistral,openrouter';
const LLM_FALLBACK_ORDER = (process.env.LLM_FALLBACK_ORDER || DEFAULT_FALLBACK_ORDER)
  .split(',')
  .map((item) => item.trim().toLowerCase())
  .filter(Boolean);

const SAISIE_PROMPT = "Tu es un assistant correcteur orthographique et de style appelé Reformulator. Mathieu, l'utilisateur, te donne un texte brut avec des fautes. Il s'agit de ses souvenirs personnels. Si le texte utilise la première personne (je, moi, mon, ma, mes, nous, notre, nos), tu le transforms impérativement en troisième personne avec Mathieu comme sujet. Ne conserve jamais de formes de première personne dans la réponse. Ne commences jamais par une salutation ni par une formule d'accueil. Ne réponds pas par une phrase de présentation. Ensuite, tu dois UNIQUEMENT corriger les fautes d'orthographe, de grammaire et de frappe, et améliorer légèrement la fluidité. Tu réponds toujours en français correct et naturel. Structure en paragraphes clairs. Tu ne poses JAMAIS de questions. Tu ne commentes JAMAIS le texte. Ne change pas les faits, tu ne rajoutes RIEN. Ne reformule pas en ajoutant du contenu ou des détails inexistants. Tu retournes UNIQUEMENT le texte reformulé, sans introduction, sans explication, sans guillemets. Si le texte est très court, tu le retournes simplement corrigé.";

const LOCATION_PROMPT = "Tu es un assistant expert en placement de contenu dans un document de référence. Reçois un texte à ajouter dans instructions.md et, en te basant sur la structure des sections disponibles, propose l'endroit le plus pertinent où insérer ce texte. Comprends que le texte peut être un souvenir raconté à la première personne pour Mathieu, et choisis la section la plus pertinente du mémoire. Donne une réponse courte, claire et précise, en mentionnant la section ou la position d'insertion. N'ajoute pas de contenu supplémentaire. Ne reformule pas le texte, répond seulement à la question de placement.";

const QUERY_KEYWORD_PROMPT = "Tu es un assistant expert en extraction d'intention de recherche. Reçois une question à propos d'un document et retourne seulement les mots-clés ou expressions clés qui permettent de chercher la réponse dans ce document. Répond en une seule ligne, avec des mots ou expressions séparés par des virgules. Ne réponds pas par une phrase complète, ne donne pas de salutations et ne rajoute pas de texte inutile.";

const QUERY_PROMPT = "Tu es un assistant expert en recherche dans un document. Tu reçois une question à propos du contenu de instructions.md. Ignore totalement toute notion de conversation antérieure, d'historique ou de mémoire de chat. Ne réponds jamais sur une conversation passée : comprends qu'il s'agit d'une recherche dans instructions.md. Cherche uniquement dans le contexte fourni. Si la question utilise un langage familier, des termes vagues ou des synonymes, recherche aussi les idées équivalentes dans le contexte. Si la question demande si un sujet a déjà été mentionné, répond en donnant les sections ou extraits précis du contexte où le sujet apparaît. Si l'information ne figure pas dans le contexte, réponds exactement : 'Aucune mention trouvée dans instructions.md'. Ne reformule pas, ne commente pas et n'écris pas de salutations. Ne rajoute aucun détail, opinion ou anecdote qui n'est pas dans le contexte. Répond uniquement avec la mention, sans introduction. Si la question concerne un souvenir personnel ou l'histoire de Mathieu, prends en compte que le contexte représente le fichier mémoire de Mathieu et réponds uniquement à partir de ce contexte.";

// Helper de creation de payload compatible API OpenAI.
// Utilise par Cerebras, Mistral, OpenRouter (et tout moteur OpenAI-compatible).
// Groq conserve son createPayload propre ci-dessous pour compatibilite.
const createOpenAICompatiblePayload = (text, model, context, purpose) => {
  const messages = [];
  if (purpose === 'location') {
    messages.push({ role: 'system', content: LOCATION_PROMPT });
  } else if (purpose === 'query-keywords') {
    messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
  } else if (purpose === 'query') {
    messages.push({ role: 'system', content: QUERY_PROMPT });
  } else {
    messages.push({ role: 'system', content: SAISIE_PROMPT });
  }
  if (context) {
    messages.push({ role: 'system', content: `Contexte instructions : ${context}` });
  }
  const userContent = purpose === 'query'
    ? `Recherche dans instructions.md : ${text}`
    : `Texte a reformuler pour le memoire : ${text}`;
  messages.push({ role: 'user', content: userContent });
  const temperature = purpose === 'rewrite' || purpose === 'location' ? 0.2 : purpose === 'query-keywords' ? 0.0 : 0.4;
  return { model, messages, temperature, max_tokens: 1500 };
};

const LLM_ENGINES = {
  groq: {
    name: 'Groq',
    apiKeyEnv: 'GROQ_API_KEY',
    modelEnv: 'GROQ_MODEL',
    apiBase: process.env.GROQ_API_BASE || 'https://api.groq.com/openai/v1',
    engineUrl: 'https://console.groq.com/home',
    // Modeles Groq valides (liste a jour juin 2025).
    // defaultModel est le modele utilise si GROQ_MODEL n'est pas defini.
    // Pour changer le modele par defaut, modifier la valeur ci-dessous ou definir GROQ_MODEL en env.
    defaultModel: 'llama-3.3-70b-versatile',
    models: [
      'llama-3.3-70b-versatile',
      'llama-3.1-8b-instant',
      'llama-4-scout-17b-16e-instruct',
      'gemma2-9b-it',
      'qwen-qwq-32b',
      'compound-beta-mini',
    ],
    createPayload: (text, model, context = '', purpose = 'rewrite') => {
      const messages = [];
        if (purpose === 'location') {
        messages.push({ role: 'system', content: LOCATION_PROMPT });
      } else if (purpose === 'query-keywords') {
        messages.push({ role: 'system', content: QUERY_KEYWORD_PROMPT });
      } else if (purpose === 'query') {
        messages.push({ role: 'system', content: QUERY_PROMPT });
      } else {
        messages.push({ role: 'system', content: SAISIE_PROMPT });
      }
      if (context) {
        messages.push({ role: 'system', content: `Contexte instructions : ${context}` });
      }
      const userContent = purpose === 'query'
        ? `Recherche dans instructions.md : ${text}`
        : `Texte à reformuler pour le mémoire : ${text}`;
      messages.push({ role: 'user', content: userContent });
      const temperature = purpose === 'rewrite' || purpose === 'location' ? 0.2 : purpose === 'query-keywords' ? 0.0 : 0.4;
      return {
        model,
        messages,
        temperature,
        max_tokens: 1500
      };
    }
  },
  // Moteurs de secours — tous compatibles avec le format API OpenAI.
  // Ajouter la cle API correspondante dans les variables d'environnement cPanel.
  cerebras: {
    name: 'Cerebras',
    apiKeyEnv: 'CEREBRAS_API_KEY',
    modelEnv: 'CEREBRAS_MODEL',
    apiBase: process.env.CEREBRAS_API_BASE || 'https://api.cerebras.ai/v1',
    engineUrl: 'https://cloud.cerebras.ai',
    defaultModel: 'llama-3.3-70b',
    models: [
      'llama-3.3-70b',
      'llama3.1-8b',
      'qwen-3-32b',
    ],
    createPayload: createOpenAICompatiblePayload,
  },
  mistral: {
    name: 'Mistral',
    apiKeyEnv: 'MISTRAL_API_KEY',
    modelEnv: 'MISTRAL_MODEL',
    apiBase: process.env.MISTRAL_API_BASE || 'https://api.mistral.ai/v1',
    engineUrl: 'https://console.mistral.ai',
    defaultModel: 'mistral-small-latest',
    models: [
      'mistral-small-latest',
      'open-mistral-7b',
      'mistral-medium-latest',
    ],
    createPayload: createOpenAICompatiblePayload,
  },
  openrouter: {
    name: 'OpenRouter',
    apiKeyEnv: 'OPENROUTER_API_KEY',
    modelEnv: 'OPENROUTER_MODEL',
    apiBase: process.env.OPENROUTER_API_BASE || 'https://openrouter.ai/api/v1',
    engineUrl: 'https://openrouter.ai',
    defaultModel: 'meta-llama/llama-3.1-8b-instruct:free',
    models: [
      'meta-llama/llama-3.1-8b-instruct:free',
      'mistralai/mistral-7b-instruct:free',
      'google/gemma-2-9b-it:free',
    ],
    createPayload: createOpenAICompatiblePayload,
  },
};

const getEngineConfig = (engineName) => LLM_ENGINES[engineName] || null;

const getEngineModel = (engineName) => {
  const engine = getEngineConfig(engineName);
  if (!engine) return null;
  const envModel = process.env[engine.modelEnv] || '';
  if (envModel) {
    if (engine.models.includes(envModel)) {
      return envModel;
    }
    console.warn(`Modèle configuré non supporté pour ${engine.name} : ${envModel}. Utilisation du modèle par défaut ${engine.defaultModel}.`);
    return engine.defaultModel;
  }
  return engine.defaultModel;
};

const getEngineModelCandidates = (engineName) => {
  const engine = getEngineConfig(engineName);
  if (!engine) return [];
  const selected = getEngineModel(engineName);
  return [...new Set([selected, ...engine.models])];
};

const getFallbackModels = (engineName, failedModel) => {
  const engine = getEngineConfig(engineName);
  if (!engine || !engine.models) return [];
  return engine.models.filter((candidate) => candidate !== failedModel);
};

const buildRequestUrl = (baseUrl) => {
  const normalized = String(baseUrl).trim().replace(/\/+$/, '');
  if (normalized.endsWith('/chat/completions') || normalized.endsWith('/completions')) {
    return normalized;
  }
  return `${normalized}/chat/completions`;
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const extractRetryAfterSeconds = (error) => {
  const headers = error.response && error.response.headers ? error.response.headers : {};
  if (headers['retry-after']) {
    const retry = parseFloat(headers['retry-after']);
    if (!Number.isNaN(retry) && retry > 0) {
      return retry;
    }
  }
  const responseData = error.response && error.response.data ? error.response.data : null;
  const message = responseData && responseData.error && responseData.error.message ? String(responseData.error.message) : '';
  const match = message.match(/([0-9]+(?:\.[0-9]+)?)s/);
  if (match && match[1]) {
    const value = parseFloat(match[1]);
    if (!Number.isNaN(value) && value > 0) {
      return value;
    }
  }
  return 0;
};

const getCurrentEngineInfo = () => {
  const engine = getEngineConfig(LLM_ENGINE);
  const selectedModel = getEngineModel(LLM_ENGINE);
  return {
    defaultEngine: LLM_ENGINE,
    engineName: engine && engine.name ? engine.name : LLM_ENGINE,
    engineUrl: engine && engine.engineUrl ? engine.engineUrl : '',
    selectedModel,
    fallbackOrder: LLM_FALLBACK_ORDER,
    availableEngines: Object.keys(LLM_ENGINES),
    // Pas de optional chaining ici non plus : compatibilité Node.js cPanel.
    modelCandidates: engine && engine.models ? engine.models : [],
  };
};

const reformulate = async (text, context = '', purpose = 'rewrite') => {
  let lastError = null;

  for (const engineName of LLM_FALLBACK_ORDER) {
    const engine = getEngineConfig(engineName);
    if (!engine) {
      console.warn(`LLM engine inconnu ignore : ${engineName}`);
      continue;
    }

    const apiKey = process.env[engine.apiKeyEnv];
    if (!apiKey) {
      console.warn(`Cle API manquante pour le moteur ${engine.name} : ${engine.apiKeyEnv}`);
      continue;
    }

    let retryCount = 0;
    const maxRetries = 2;
    while (true) {
      try {
        const model = getEngineModel(engineName);
        const payload = engine.createPayload(text, model, context, purpose);
        const requestUrl = buildRequestUrl(engine.apiBase);
        console.log(`LLM request URL: ${requestUrl} for engine ${engineName} purpose=${purpose}`);
        const response = await axios.post(requestUrl, payload, {
          headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' }
        });
        return { cleaned: response.data.choices[0].message.content.trim(), engine: engineName, model };
      } catch (error) {
        lastError = error;
        const requestUrl = buildRequestUrl(engine.apiBase);
        const status = error.response && error.response.status ? error.response.status : 'unknown';
        logError(`LLM request failed for ${requestUrl} status=${status} engine=${engineName}`);
        if (error.response && error.response.data) {
          logError(`LLM response body: ${JSON.stringify(error.response.data)}`);
        }

        const responseData = error.response && error.response.data;
        const errorCode = responseData && responseData.error && responseData.error.code ? responseData.error.code : null;
        const retryAfter = extractRetryAfterSeconds(error);
        if (status === 429 && retryCount < maxRetries) {
          retryCount += 1;
          const waitSeconds = retryAfter > 0 && retryAfter <= 60 ? retryAfter : 3;
          logError(`Rate limit détecté pour ${engineName}, tentative ${retryCount}/${maxRetries}, attente de ${waitSeconds}s avant nouvelle tentative.`);
          await sleep(Math.ceil(waitSeconds * 1000));
          continue;
        }

        if (engineName === 'groq' && error.response && error.response.status === 404 && errorCode === 'unknown_url' && requestUrl.includes('/v1/chat/completions')) {
          const fallbackUrl = 'https://api.groq.com/openai/v1/chat/completions';
          try {
            logError(`Groq unknown_url détecté sur ${requestUrl} ; tentative fallback ${fallbackUrl}`);
            const model = getEngineModel(engineName);
            const payload = engine.createPayload(text, model, context, purpose);
            const response = await axios.post(fallbackUrl, payload, {
              headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' }
            });
            return { cleaned: response.data.choices[0].message.content.trim(), engine: engineName, model };
          } catch (fallbackError) {
            lastError = fallbackError;
            logError(`Fallback Groq failed: ${fallbackError && fallbackError.message ? fallbackError.message : String(fallbackError)}`);
            if (fallbackError.response && fallbackError.response.data) {
              logError(`Fallback response body: ${JSON.stringify(fallbackError.response.data)}`);
            }
          }
        }

        if (engineName === 'groq' && errorCode === 'model_not_found') {
          const failedModel = getEngineModel(engineName);
          const fallbackModels = getFallbackModels(engineName, failedModel);
          for (const fallbackModel of fallbackModels) {
            try {
              logError(`Groq model_not_found détecté pour ${failedModel} ; tentative avec ${fallbackModel}`);
              const payload = engine.createPayload(text, fallbackModel, context, purpose);
              const response = await axios.post(requestUrl, payload, {
                headers: { 'Authorization': `Bearer ${apiKey}`, 'Content-Type': 'application/json' }
              });
              return { cleaned: response.data.choices[0].message.content.trim(), engine: engineName, model: fallbackModel };
            } catch (fallbackError) {
              lastError = fallbackError;
              logError(`Fallback modèle Groq failed pour ${fallbackModel}: ${fallbackError && fallbackError.message ? fallbackError.message : String(fallbackError)}`);
              if (fallbackError.response && fallbackError.response.data) {
                logError(`Fallback modèle response body: ${JSON.stringify(fallbackError.response.data)}`);
              }
            }
          }
        }

        console.warn(`Moteur ${engine.name} (${engineName}) indisponible, passage au suivant:`, error.message || error);
        break;
      }
    }
  }

  throw lastError || new Error('Aucun moteur LLM disponible');
};

// Route de statut supplémentaire simple.
app.get('/status', (req, res) => {
  res.type('text/plain').send('Reformulator service is alive');
});

app.get('/error.log', (req, res) => {
  res.redirect(301, '/log/error.log');
});

app.get('/requests.log', (req, res) => {
  res.redirect(301, '/log/requests.log');
});

app.get('/log/error.log', (req, res) => {
  ensureLogFile(ERROR_LOG_FILE);
  res.type('text/plain; charset=UTF-8');
  res.set('Cache-Control', 'no-store');
  res.sendFile(ERROR_LOG_FILE);
});

app.get('/log/requests.log', (req, res) => {
  ensureLogFile(REQUESTS_LOG_FILE);
  res.type('text/plain; charset=UTF-8');
  res.set('Cache-Control', 'no-store');
  res.sendFile(REQUESTS_LOG_FILE);
});

// Route de debug
app.get('/', (req, res) => {
  res.json({
    status: 'ok',
    routes: ['/status', '/log/error.log', '/log/requests.log', '/reformuler', '/r3M3M83r/reformulator/reformuler', '/llm-info'],
    ...getCurrentEngineInfo(),
  });
});

// Route d'information LLM, utilisable par toutes les interfaces.
// `saisie.php` appelle cette route pour afficher le moteur et le modèle
// actifs sans hardcoder la configuration dans le frontend PHP.
app.get('/llm-info', (req, res) => {
  res.json(getCurrentEngineInfo());
});

const registerReformulationRoute = (path) => {
  app.post(path, async (req, res) => {
    logRequest(req);
    const text = (req.body && req.body.text) || '';
    const context = (req.body && typeof req.body.instructionsContext === 'string') ? req.body.instructionsContext : '';
    const purpose = (req.body && typeof req.body.purpose === 'string') ? req.body.purpose : 'rewrite';
    if (!text) {
      return res.status(400).json({ error: 'Texte absent' });
    }

    try {
      const result = await reformulate(text, context, purpose);
      res.json({ cleaned: result.cleaned, engine: result.engine, model: result.model });
    } catch (error) {
      const errorMessage = error && error.message ? error.message : String(error);
      logError(errorMessage);
      res.status(500).json({ error: 'Erreur LLM', details: errorMessage });
    }
  });
};

// Route 1
registerReformulationRoute('/reformuler');

// Route 2
registerReformulationRoute('/r3M3M83r/reformulator/reformuler');

app.listen(PORT, () => {
  const info = getCurrentEngineInfo();
  // Ecrit le port dans un fichier .port pour que PHP puisse se connecter
  // directement en 127.0.0.1:PORT sans passer par Apache/Passenger.
  // Ce fichier est bloque en acces web par le .htaccess du dossier.
  const portFile = path.join(__dirname, '.port');
  try {
    fs.writeFileSync(portFile, String(PORT), 'utf8');
  } catch (portWriteError) {
    logError('Impossible d ecrire le fichier .port : ' + String(portWriteError && portWriteError.message ? portWriteError.message : portWriteError));
  }
  console.log(`Reformulator prêt sur port ${PORT}`);
  console.log(`LLM engine actif : ${info.engineName}`);
  console.log(`LLM modèle actif : ${info.selectedModel}`);
  console.log(`server started port=${PORT} engine=${info.engineName} model=${info.selectedModel}`);
});