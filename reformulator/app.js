// Node startup bridge for cPanel.
// cPanel may launch app.js by default, mais notre vrai serveur LLM est dans server.js.
require('./server.js');