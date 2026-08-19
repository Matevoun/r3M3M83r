/**
 * ============================================================================
 * r3M3M83r/chat_prompts.js — Prompt perenne modulable pour chat.php
 * ============================================================================
 *
 * ROLE :
 *  - Fichier JS externe, editable sans toucher au PHP
 *  - Ne remplace PAS QUERY_PROMPT de reformulator/prompts.js
 *  - Il AJOUTE une couche tchat pour gerer la memoire conversation
 *
 * COMMENT CA MARCHE :
 *  - chat.php lit ce fichier via regex CHAT_ADDON
 *  - Il remplace {{CHAT_HISTORY}} par les 8 derniers tours (user + assistant)
 *  - Ce bloc est prepend a la question actuelle avant d'appeler
 *    finalize_query_response_via_node()
 *  - Le contexte memoire (PREUVES DIRECTES) reste separe et strict
 *    -> Le LLM ne peut pas inventer, il doit citer le fichier
 *  - L'historique sert uniquement a comprendre les pronoms :
 *    "et lui ?" "et sa soeur ?" "il avait quel age ?"
 *
 * EDITER :
 *  - Modifie le texte ci-dessous, puis recharge chat.php (pas besoin de restart Node)
 *  - Garde {{CHAT_HISTORY}} dans le template
 *
 * REGLES D'OR (rappel) :
 *  - N'INVENTE RIEN : chaque fait doit venir de instructions.md
 *  - Si pas dans fichier : "Non mentionne dans le fichier"
 *  - Orthographe archaique : CLEF, NENUPHAR, noms en MAJUSCULES
 */

const CHAT_ADDON = `
CONSIGNE TCHAT — MEMOIRE CONVERSATION (pour pronoms et suivi) :
- Tu es dans un tchat continu. L'historique ci-dessous te permet de comprendre a qui/quoi fait reference la question actuelle.
- Exemple : si historique contient "Qui est Tonin ?" et question = "et son age ?", tu dois comprendre "Quel est l'age de Tonin ?"
- MAIS ta reponse doit TOUJOURS venir UNIQUEMENT du contexte memoire fourni (bloc PREUVES DIRECTES + sections). Tu ne peux pas inventer.
- Si le fichier ne contient pas la reponse : dis exactement "Non mentionne dans le fichier pour cette question." et propose de reformuler.
- Garde le ton factuel, concis, avec sources [titre section] comme le bouton Interroger le fichier.
- Tu gardes en memoire tous les echanges precedents tant que l'utilisateur n'a pas clique Clean ou Reset.

HISTORIQUE TCHAT (8 derniers tours) :
{{CHAT_HISTORY}}
`.trim();

// Compatibilite ancien nom utilise dans v2/v3
const CHAT_SYSTEM_PROMPT = CHAT_ADDON;

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { CHAT_ADDON, CHAT_SYSTEM_PROMPT };
}
