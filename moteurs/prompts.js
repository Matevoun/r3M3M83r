/**
 * r3M3M83r/moteurs/prompts.js
 * Blocs GENERIQUES partages uniquement.
 * Pas de persona, pas de "Tu es Rebecca", pas de "Reformulator".
 * Les prompts specifiques sont dans :
 *   - rebecca/Chat-Prompts.js
 *   - reformulator/Reformulator-Prompts.js
 * Editer ici, puis redemarrer Node.
 */

const STYLE_RULES_ORTHOGRAPHY = `
- Orthographe : CLEF (jamais "cle"), NENUPHAR (jamais "nenuphar").
- Pas de ligature oe : ecrire OE separes (COEUR, VOEUX, soeurs avec o et e distincts).
- Pas de tiret cadratin/demi-cadratin a la place d'une virgule ou parenthese.
- Noms propres en MAJUSCULES (ex. : CHARREYRE, SAINT-ANTONIN).
- Domaine Saint-Antonin : majuscule a Domaine ; jamais "Domaine de Saint-Antonin".
`;

const STYLE_RULES_FORMATTING = `
- Utilise des guillemets Francais (<< >>).
- Les dates sont au format JJ/MM/AAAA.
- Toujours en Francais, jamais en anglais.
`;

const STYLE_RULES_NUMBERS = `
- Les nombres de 0 a 16 s'ecrivent en lettres, SAUF n de parcelle, section cadastrale, surface (ha/a/ca) et codes : garder 0077, 0 ha 30 a 80 ca.
`;

const STYLE_RULES_TONE = `
- Parler de Mathieu a la 3e personne (jamais "je" a sa place).
- Ton clair et direct.
- Aucun emoji, aucun smiley, jamais.
`;

const STYLE_RULES = STYLE_RULES_ORTHOGRAPHY + STYLE_RULES_FORMATTING + STYLE_RULES_NUMBERS + STYLE_RULES_TONE;

const CHAT_FORM_RULES = `
- Toujours en Francais.
- Prose claire. Listes a tirets si utile. Inventaire long : toutes les lignes.
- Interdit : titres markdown (##, ###), blocs "Faits etablis".
- Source discrete en fin si besoin.
- Si l'info n'est pas dans le contexte : "Rien la-dessus dans le fichier memoire — un trou, ou une autre formulation ?"
- Ne jamais inventer dates, ages, lieux, noms, surnoms.
`;

const FACTUALITY_RULES = `
REGLE D'OR :
** N'INVENTE RIEN. JAMAIS. **
** CHAQUE FAIT DOIT ETRE PRESENT DANS LE CONTEXTE FOURNI. **
** Si l'info n'y est pas : "Rien la-dessus dans le fichier memoire — un trou, ou une autre formulation ?" **
** Interdit d'inventer. **
`;

// Prompts generiques (le purpose decide cote Node)
const QUERY_PROMPT = `Reponds a la question UNIQUEMENT a partir du contexte fourni.
` + FACTUALITY_RULES + `
- Extrais uniquement ce qui est ecrit.
- Reponse courte et directe. Listes completes si demandees.
` + STYLE_RULES;

const QUERY_CHAT_PROMPT = `Tu reponds UNIQUEMENT a partir des PREUVES DIRECTES fournies.
Si une ligne contient plusieurs prenoms separes par des virgules suivis d'un seul nom de famille (ex: "Antoine, Christian, Maxence VILLIERS"), c'est UNE SEULE personne. Ne la divise pas en plusieurs individus.
Liste les personnes distinctes, pas chaque prenom separement.
Si les preuves sont vides ou ne contiennent rien sur le sujet : "Rien la-dessus dans le fichier memoire — un trou, ou une autre formulation ?"
Ne recrache pas les preuves brutes. Synthetise.
` + FACTUALITY_RULES + CHAT_FORM_RULES + STYLE_RULES;

const SAISIE_PROMPT = `Reformule le texte pour un fichier memoire.
- 1re personne -> 3e personne ("Mathieu").
- Pas d'invention. Garde noms, dates, lieux.
- Style clair, pret a coller.
Sortie : uniquement le texte reformule.
` + STYLE_RULES;

const LOCATION_PROMPT = `Indique ou placer le texte dans le fichier memoire.
Reponse courte : section cible + action (ajouter / completer).
` + STYLE_RULES;

const MERGE_SMART_PROMPT = `Propose une fusion propre.
Respecte les balises exactes :
<<<A_COLLER
(bloc pret a coller)
>>>A_COLLER
<<<EMPLACEMENT
(ou le mettre)
>>>EMPLACEMENT
<<<DETAILS
### Deja
### Nouveau
### Contradictions
>>>DETAILS
` + STYLE_RULES;

const MERGE_CHECK_PROMPT = `Compare le texte avec le contexte memoire. Liste chevauchements et nouveautes. Concision.
` + STYLE_RULES;

const CHAT_ROUTE_PROMPT = `Classe la question.
Reponds EXACTEMENT par un de ces deux mots :
MEMORY
CHAT

MEMORY = question sur la vie, la famille, le Domaine, les faits du fichier.
CHAT = salutation, politesse, meta, blague, discussion generale.`;

const CHAT_TALK_PROMPT = `Reponds naturellement a un message de tchat hors memoire.
Pas de consultation de fichier.
` + CHAT_FORM_RULES;

const QUERY_EXPAND_PROMPT = `Prepare une recherche. Donne l'intention et des axes de recherche (synonymes). Ne reponds pas a la question.`;
const QUERY_SELECT_PROMPT = `Choisis 2 a 5 titres de sections exacts, separes par des virgules. Ou AUCUNE.`;
const QUERY_KEYWORD_PROMPT = `Retourne uniquement une liste de mots-cles separes par des virgules (max 8).`;

module.exports = {
  STYLE_RULES,
  FACTUALITY_RULES,
  CHAT_FORM_RULES,
  QUERY_PROMPT,
  QUERY_CHAT_PROMPT,
  SAISIE_PROMPT,
  LOCATION_PROMPT,
  MERGE_SMART_PROMPT,
  MERGE_CHECK_PROMPT,
  CHAT_ROUTE_PROMPT,
  CHAT_TALK_PROMPT,
  QUERY_EXPAND_PROMPT,
  QUERY_SELECT_PROMPT,
  QUERY_KEYWORD_PROMPT
};