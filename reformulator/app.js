// Node startup bridge for cPanel.
// r3M3M83r/reformulator/app.js
// cPanel may launch app.js by default, mais notre vrai serveur LLM est dans server.js.
require('./server.js');