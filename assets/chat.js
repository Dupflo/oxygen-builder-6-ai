/**
 * Oxygen 6 AI — chat client (vanilla JS, no build step).
 *
 * Parle au backend agent hébergé via POST /chat puis lit le flux SSE
 * (text/event-stream) au fil de l'eau. On N'utilise PAS EventSource : il ne sait
 * faire que du GET sans corps ni header custom. fetch() + ReadableStream lit le
 * même flux en POST, avec le header Authorization et le corps JSON.
 *
 * La config (URLs + secrets) vient de WordPress via wp_localize_script ->
 * variable globale `OXYMCP_CHAT`. Les secrets ne sont jamais loggés ici.
 */
( function () {
	'use strict';

	var cfg = window.OXYMCP_CHAT || {};
	var form = document.getElementById( 'oxymcp-chat-form' );
	var input = document.getElementById( 'oxymcp-chat-input' );
	var sendBtn = document.getElementById( 'oxymcp-chat-send' );
	var log = document.getElementById( 'oxymcp-chat-log' );

	// Mémoire de conversation : on garde le fil côté CLIENT et on le renvoie à
	// chaque tour. Le backend est SANS état (robuste aux redémarrages Render).
	// [{ role: 'user' | 'assistant', content: '...' }, ...]
	var history = [];
	var MAX_HISTORY = 20; // on borne le payload (cf. backend _MAX_HISTORY_MESSAGES)

	// Garde-fou : si le markup n'est pas là (autre page), on ne fait rien.
	if ( ! form || ! input || ! log ) {
		return;
	}

	// --- Helpers d'affichage ---------------------------------------------

	// Crée une bulle de message et la renvoie (pour pouvoir y ajouter du texte
	// au fur et à mesure du streaming). textContent => pas d'injection HTML.
	function addBubble( role, text ) {
		var el = document.createElement( 'div' );
		el.className = 'oxymcp-chat__msg oxymcp-chat__msg--' + role;
		el.textContent = text || '';
		log.appendChild( el );
		log.scrollTop = log.scrollHeight;
		return el;
	}

	function setBusy( busy ) {
		input.disabled = busy;
		sendBtn.disabled = busy;
		sendBtn.textContent = busy ? 'Sending…' : 'Send';
	}

	// Indicateur "réflexion en cours" : une bulle assistant avec 3 points animés.
	// Affichée DÈS l'envoi (essentiel pendant le cold start Render ~30-60 s, sinon
	// "rien ne se passe"), retirée au 1er token ou en cas d'erreur.
	function addThinking() {
		var el = document.createElement( 'div' );
		el.className = 'oxymcp-chat__msg oxymcp-chat__msg--assistant oxymcp-chat__thinking';
		var dots = document.createElement( 'span' );
		dots.className = 'oxymcp-chat__dots';
		for ( var i = 0; i < 3; i++ ) {
			dots.appendChild( document.createElement( 'i' ) );
		}
		el.appendChild( dots );
		// Libellé d'aide (vide au départ ; rempli si le cold start traîne).
		var hint = document.createElement( 'span' );
		hint.className = 'oxymcp-chat__hint';
		el.appendChild( hint );
		log.appendChild( el );
		log.scrollTop = log.scrollHeight;
		return el;
	}

	// Retire l'indicateur + annule le timer de cold start. Idempotent.
	function removeThinking( ctx ) {
		if ( ctx.thinking ) {
			ctx.thinking.remove();
			ctx.thinking = null;
		}
		if ( ctx.coldTimer ) {
			clearTimeout( ctx.coldTimer );
			ctx.coldTimer = null;
		}
	}

	// Traduit une erreur technique en message clair (+ lien d'action si utile).
	// On matche sur des bouts de message connus (insensible à la casse).
	function friendlyError( raw ) {
		var s = ( raw || '' ).toLowerCase();
		if ( s.indexOf( 'credit balance is too low' ) !== -1 ) {
			return {
				text: 'Crédit Anthropic épuisé. Recharge ton compte pour utiliser le chat (la même clé remarchera).',
				link: { href: 'https://console.anthropic.com/settings/billing', label: 'Plans & Billing →' },
			};
		}
		if ( s.indexOf( 'invalid x-api-key' ) !== -1 || s.indexOf( 'authentication' ) !== -1 || s.indexOf( '401' ) !== -1 ) {
			return { text: 'Clé Anthropic invalide ou manquante. Vérifie-la dans les réglages ci-dessus.' };
		}
		if ( s.indexOf( 'overloaded' ) !== -1 || s.indexOf( 'rate limit' ) !== -1 || s.indexOf( '429' ) !== -1 ) {
			return { text: 'Service momentanément surchargé. Réessaie dans quelques secondes.' };
		}
		if ( s.indexOf( '422' ) !== -1 ) {
			return { text: 'Réglages pas encore chargés dans cette page. Recharge-la (Ctrl/Cmd+Shift+R) puis réessaie.' };
		}
		if ( s.indexOf( 'returned an error result' ) !== -1 ) {
			return { text: 'L’agent s’est interrompu. Réessaie ; si ça persiste, vérifie ta clé et ton crédit Anthropic.' };
		}
		return { text: raw || 'Erreur inconnue.' };
	}

	// Affiche une bulle d'erreur. DOM construit à la main (textContent + <a>
	// éventuel) -> aucune injection HTML possible.
	function addError( raw ) {
		var info = friendlyError( raw );
		var el = document.createElement( 'div' );
		el.className = 'oxymcp-chat__msg oxymcp-chat__msg--error';
		var span = document.createElement( 'span' );
		span.textContent = info.text;
		el.appendChild( span );
		if ( info.link ) {
			el.appendChild( document.createTextNode( ' ' ) );
			var a = document.createElement( 'a' );
			a.href = info.link.href;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.textContent = info.link.label;
			el.appendChild( a );
		}
		log.appendChild( el );
		log.scrollTop = log.scrollHeight;
		return el;
	}

	// Certaines erreurs (ex. solde Anthropic) arrivent comme un message NORMAL de
	// l'agent (TextBlock), pas comme un event "error". On les repère pour les
	// afficher en rouge plutôt qu'en fausse réponse. Renvoie la chaîne ou null.
	function fatalInText( text ) {
		return /credit balance is too low/i.test( text || '' ) ? text : null;
	}

	// Cas spécifique : le backend a démarré mais n'a PAS pu joindre l'endpoint MCP
	// du site (event warning/mcp_not_connected). Quasi toujours : l'hébergeur du
	// site (antibot / pare-feu / WAF) bloque l'IP du service hébergé. On affiche
	// un message ACTIONNABLE plutôt que le vague « je ne vois pas les outils ».
	function addHostBlockedError() {
		var el = document.createElement( 'div' );
		el.className = 'oxymcp-chat__msg oxymcp-chat__msg--error';
		var span = document.createElement( 'span' );
		span.textContent =
			'Le chat n’a pas pu atteindre ton site : ton hébergeur (antibot / pare-feu) ' +
			'bloque probablement notre agent hébergé. Deux solutions : (1) demande à ton ' +
			'hébergeur d’autoriser l’IP de l’agent, ou (2) branche un client MCP local ' +
			'(Claude Desktop / Claude Code), qui se connecte depuis ta propre machine.';
		el.appendChild( span );
		el.appendChild( document.createTextNode( ' ' ) );
		var a = document.createElement( 'a' );
		a.href = 'https://github.com/Dupflo/oxygen-builder-6-ai#troubleshooting';
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = 'Guide de dépannage →';
		el.appendChild( a );
		log.appendChild( el );
		log.scrollTop = log.scrollHeight;
		return el;
	}

	// --- Client MCP same-origin (mode relais navigateur) ------------------
	// Au lieu que le BACKEND (IP datacenter, bloquée par l'antibot de
	// l'hébergeur) appelle WordPress, c'est le NAVIGATEUR de l'admin qui parle
	// au endpoint MCP du SITE — même origine, IP résidentielle + session WP par
	// cookie. Ça annule le mur antibot ET évite d'exposer l'Application Password.
	//
	// Le protocole MCP Streamable HTTP est SESSION-BASED : `initialize` crée une
	// session et renvoie son id dans le header de réponse `Mcp-Session-Id` ; on
	// doit le rejouer en header de requête pour `tools/list` et `tools/call`.
	var mcp = {
		url: ( cfg.mcpUrl || '' ),
		sessionId: null, // capturé depuis le header de réponse d'initialize
		nextId: 1, // compteur d'id JSON-RPC (≈ un auto-increment)
		tools: null, // specs d'outils mises en cache après le 1er handshake
	};

	// Lit une réponse MCP : soit JSON direct, soit un flux SSE (text/event-stream
	// — Streamable HTTP peut répondre dans les deux formats). Renvoie le message
	// JSON-RPC parsé, ou null (réponses 202 sans corps : notifications).
	async function mcpReadResponse( resp ) {
		var ct = resp.headers.get( 'content-type' ) || '';
		var body = await resp.text();
		if ( ! body ) {
			return null;
		}
		if ( ct.indexOf( 'text/event-stream' ) !== -1 ) {
			// On recolle les lignes `data:` (le message JSON-RPC tient dans l'event).
			var joined = body
				.split( /\r?\n/ )
				.filter( function ( l ) {
					return l.indexOf( 'data:' ) === 0;
				} )
				.map( function ( l ) {
					return l.slice( 5 ).replace( /^ /, '' );
				} )
				.join( '\n' );
			if ( ! joined ) {
				return null;
			}
			try {
				return JSON.parse( joined );
			} catch ( e ) {
				return null;
			}
		}
		try {
			return JSON.parse( body );
		} catch ( e ) {
			return null;
		}
	}

	// Rafraîchit le nonce REST via l'action ajax cœur `rest-nonce` (renvoie un
	// `wp_rest` frais). Best-effort : si indisponible, on renverra null et le
	// 403 remontera en erreur claire « recharge la page ».
	async function refreshNonce() {
		if ( ! cfg.ajaxUrl ) {
			return null;
		}
		try {
			var r = await fetch( cfg.ajaxUrl + '?action=rest-nonce', {
				credentials: 'same-origin',
			} );
			if ( ! r.ok ) {
				return null;
			}
			var t = ( await r.text() ).trim();
			return t || null;
		} catch ( e ) {
			return null;
		}
	}

	// POST JSON-RPC vers le endpoint MCP du site. Porte cookie + X-WP-Nonce
	// (auth WP) + Accept JSON/SSE (exigé par Streamable HTTP) + Mcp-Session-Id si
	// connu. Retry UNE fois sur 403 (nonce périmé -> on tente un refresh).
	async function mcpPost( payload, opts ) {
		opts = opts || {};
		var headers = {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		};
		if ( cfg.restNonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.restNonce;
		}
		if ( mcp.sessionId ) {
			headers[ 'Mcp-Session-Id' ] = mcp.sessionId;
		}
		var resp = await fetch( mcp.url, {
			method: 'POST',
			credentials: 'same-origin', // envoie le cookie de session WP
			headers: headers,
			body: JSON.stringify( payload ),
		} );
		// initialize renvoie l'id de session dans ce header -> on le mémorise.
		var sid = resp.headers.get( 'Mcp-Session-Id' );
		if ( sid ) {
			mcp.sessionId = sid;
		}
		if ( resp.status === 403 && ! opts._retried ) {
			var fresh = await refreshNonce();
			if ( fresh ) {
				cfg.restNonce = fresh;
				opts._retried = true;
				return mcpPost( payload, opts );
			}
		}
		return resp;
	}

	// Handshake MCP + récupération des outils (mis en cache). Le résultat sert à
	// déclarer le serveur MCP in-process côté backend (mode relais).
	async function ensureMcpTools() {
		if ( mcp.tools ) {
			return mcp.tools;
		}
		// 1) initialize (pas de header session -> en crée une).
		var initResp = await mcpPost( {
			jsonrpc: '2.0',
			id: mcp.nextId++,
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'oxygen-chat-browser', version: '1.0.0' },
			},
		} );
		if ( ! initResp.ok ) {
			throw new Error( 'MCP initialize HTTP ' + initResp.status );
		}
		await mcpReadResponse( initResp ); // header session déjà capturé dans mcpPost
		// 2) notification initialized (notification = pas d'id -> 202 sans corps).
		await mcpPost( {
			jsonrpc: '2.0',
			method: 'notifications/initialized',
			params: {},
		} );
		// 3) tools/list (rejoue le header Mcp-Session-Id).
		var listResp = await mcpPost( {
			jsonrpc: '2.0',
			id: mcp.nextId++,
			method: 'tools/list',
			params: {},
		} );
		if ( ! listResp.ok ) {
			throw new Error( 'MCP tools/list HTTP ' + listResp.status );
		}
		var listMsg = await mcpReadResponse( listResp );
		var tools = ( listMsg && listMsg.result && listMsg.result.tools ) || [];
		// On normalise pour le backend (ToolSpec) : inputSchema -> input_schema.
		mcp.tools = tools.map( function ( t ) {
			return {
				name: t.name,
				description: t.description || '',
				input_schema: t.inputSchema || {},
			};
		} );
		return mcp.tools;
	}

	// Exécute UN appel d'outil dans le navigateur (tools/call same-origin) et
	// renvoie le champ `result` de la réponse MCP ({content, isError}).
	async function mcpToolsCall( name, args ) {
		var resp = await mcpPost( {
			jsonrpc: '2.0',
			id: mcp.nextId++,
			method: 'tools/call',
			params: { name: name, arguments: args || {} },
		} );
		if ( ! resp.ok ) {
			throw new Error( 'tools/call HTTP ' + resp.status );
		}
		var msg = await mcpReadResponse( resp );
		if ( msg && msg.error ) {
			throw new Error( ( msg.error && msg.error.message ) || 'tool error' );
		}
		return ( msg && msg.result ) || null;
	}

	// Renvoie le résultat d'un tool_call au backend pour débloquer l'agent
	// (résout la Future en attente côté serveur). Corrélé par (session_id, id).
	async function postToolResult( sessionId, id, ok, payload ) {
		try {
			await fetch( cfg.backendUrl.replace( /\/$/, '' ) + '/chat/tool_result', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					session_id: sessionId,
					id: id,
					ok: ok,
					payload: payload,
				} ),
			} );
		} catch ( e ) {
			// Réseau coupé : le backend finira par timeout (45 s) -> erreur propre.
		}
	}

	// Reçu un event `tool_call` du backend : on exécute l'appel WP DANS le
	// navigateur puis on POST le résultat. Fire-and-forget (pas d'await dans le
	// parseur SSE) : le backend attend le résultat sur sa propre Future.
	function handleToolCall( payload, ctx ) {
		mcpToolsCall( payload.name, payload.args )
			.then( function ( result ) {
				return postToolResult( ctx.sessionId, payload.id, true, result );
			} )
			.catch( function ( err ) {
				return postToolResult(
					ctx.sessionId,
					payload.id,
					false,
					( err && err.message ) || String( err )
				);
			} );
	}

	// --- Parsing SSE ------------------------------------------------------
	// Le serveur envoie des blocs séparés par une ligne vide ; chaque bloc a une
	// ou plusieurs lignes `data: <json>`. On accumule dans un buffer et on
	// découpe sur "\n\n" (frontière d'event SSE).
	function handleEvent( raw, ctx ) {
		// On ne garde que les lignes data: et on recolle leur contenu.
		// split(/\r?\n/) : tolère les fins de ligne CRLF (sse-starlette en émet)
		// comme LF -> sinon un \r résiduel traîne en fin de chaque ligne.
		var dataLines = raw
			.split( /\r?\n/ )
			.filter( function ( l ) {
				return l.indexOf( 'data:' ) === 0;
			} )
			.map( function ( l ) {
				return l.slice( 5 ).replace( /^ /, '' );
			} );

		if ( ! dataLines.length ) {
			return;
		}

		var payload;
		try {
			payload = JSON.parse( dataLines.join( '\n' ) );
		} catch ( e ) {
			return; // bloc incomplet/non-JSON : on ignore
		}

		if ( payload.type === 'ready' ) {
			// 1er event du mode relais : le backend nous donne le session_id à
			// renvoyer avec chaque résultat d'outil (POST /chat/tool_result).
			ctx.sessionId = payload.session_id;
		} else if ( payload.type === 'tool_call' ) {
			// L'agent veut appeler un outil Oxygen : on l'exécute dans le
			// navigateur (même origine) -> contourne l'antibot de l'hébergeur.
			handleToolCall( payload, ctx );
		} else if ( payload.type === 'warning' && payload.code === 'mcp_not_connected' ) {
			// Les "mains" Oxygen ne sont pas connectées (hébergeur qui bloque).
			// On note l'état ; le texte qui suivra (« je ne vois pas les outils »)
			// sera supprimé et remplacé par un message actionnable en fin de flux.
			removeThinking( ctx );
			ctx.mcpBlocked = true;
		} else if ( payload.type === 'text' && payload.text ) {
			// Si les outils sont bloqués, on n'affiche PAS la réponse trompeuse de
			// l'agent (on accumule quand même rien : pas de mémoire pour ce tour).
			if ( ctx.mcpBlocked ) {
				return;
			}
			if ( ! ctx.assistant ) {
				removeThinking( ctx ); // 1er token : on enlève les points animés
				ctx.assistant = addBubble( 'assistant', '' );
			}
			ctx.assistant.textContent += payload.text;
			ctx.text += payload.text; // accumulé pour l'historique (mémoire)
			log.scrollTop = log.scrollHeight;
		} else if ( payload.type === 'error' ) {
			// On ne RENDS pas tout de suite : on mémorise et on tranche en fin de
			// flux (pour fusionner avec une éventuelle erreur "fatale" en texte).
			removeThinking( ctx );
			ctx.errored = true;
			ctx.errorMsg = payload.message || 'unknown error';
		}
		// type === 'done' : rien à afficher, la session est finie.
	}

	// --- Envoi + lecture du flux -----------------------------------------
	async function send( prompt ) {
		if ( ! cfg.backendUrl ) {
			addError( 'Backend URL is not configured (see Settings above).' );
			return;
		}

		addBubble( 'user', prompt );
		setBusy( true );

		var ctx = { assistant: null, text: '', errored: false, errorMsg: '', thinking: null, coldTimer: null, mcpBlocked: false, sessionId: null };

		// Mode RELAIS si on a de quoi parler au MCP du site depuis le navigateur
		// (nonce REST + URL MCP same-origin). Sinon repli sur le mode DIRECT
		// (legacy : le backend appelle WP -> bloqué par l'antibot de l'hébergeur).
		var relayMode = !! ( cfg.restNonce && cfg.mcpUrl );
		// Feedback immédiat : points animés. Indispensable pendant le cold start.
		ctx.thinking = addThinking();
		// Si ça traîne (> 5 s), on explique que c'est sûrement le réveil du service.
		ctx.coldTimer = setTimeout( function () {
			if ( ctx.thinking ) {
				var h = ctx.thinking.querySelector( '.oxymcp-chat__hint' );
				if ( h ) {
					h.textContent = 'Réveil du service (cold start, ~30 s)…';
				}
			}
		}, 5000 );

		// Mode ouvert BYOK : pas de jeton d'accès. Le backend borne l'abus par
		// rate-limit (par IP) et l'utilisateur paie sa propre inférence. Aucun
		// header Authorization à envoyer.
		var headers = { 'Content-Type': 'application/json' };

		// Historique = les tours PRÉCÉDENTS (le message courant part dans `prompt`).
		// On envoie au plus MAX_HISTORY derniers messages pour borner le payload.
		var priorHistory = history.slice( -MAX_HISTORY );

		try {
			// Corps de requête commun aux deux modes.
			var body = {
				prompt: prompt,
				anthropic_api_key: cfg.anthropicKey,
				history: priorHistory,
			};

			if ( relayMode ) {
				// On fait le handshake MCP dans le navigateur et on envoie les
				// schémas d'outils -> le backend monte un serveur MCP in-process
				// qui relaie chaque appel vers cet onglet. L'Application Password
				// ne transite JAMAIS par le backend dans ce mode.
				var tools = await ensureMcpTools();
				if ( ! tools.length ) {
					removeThinking( ctx );
					addError(
						'Aucun outil Oxygen détecté sur ton site. Vérifie que le plugin est actif puis recharge la page.'
					);
					return;
				}
				body.tools = tools;
			} else {
				// Mode DIRECT (legacy) : le backend appelle WP en HTTP.
				body.mcp_url = cfg.mcpUrl;
				body.mcp_auth = cfg.mcpAuth;
			}

			var resp = await fetch( cfg.backendUrl.replace( /\/$/, '' ) + '/chat', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( body ),
			} );

			if ( ! resp.ok ) {
				removeThinking( ctx );
				var msg = 'HTTP ' + resp.status;
				try {
					var j = await resp.json();
					if ( j && j.detail ) {
						msg += ' — ' + ( typeof j.detail === 'string' ? j.detail : JSON.stringify( j.detail ) );
					}
				} catch ( e ) {}
				addError( msg );
				return;
			}

			// Lecture incrémentale du corps (ReadableStream) -> décodage UTF-8.
			var reader = resp.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';

			while ( true ) {
				var chunk = await reader.read();
				if ( chunk.done ) {
					break;
				}
				buffer += decoder.decode( chunk.value, { stream: true } );

				// Découpe sur la frontière d'event SSE (ligne vide). On tolère les
				// trois variantes possibles : CRLF (\r\n\r\n, ce qu'émet
				// sse-starlette), LF (\n\n) et CR seul (\r\r).
				var parts = buffer.split( /\r\n\r\n|\n\n|\r\r/ );
				buffer = parts.pop(); // dernier morceau = potentiellement incomplet
				parts.forEach( function ( part ) {
					if ( part.trim() ) {
						handleEvent( part, ctx );
					}
				} );
			}
			// Flush d'un éventuel dernier event sans double saut de ligne final.
			if ( buffer.trim() ) {
				handleEvent( buffer, ctx );
			}

			// --- Réconciliation de fin de flux ---
			removeThinking( ctx ); // si le flux finit sans texte ni erreur (done seul)

			// Cas hébergeur qui bloque : on retire toute bulle assistant (réponse
			// trompeuse) et on affiche le message actionnable. Pas de mémoire.
			if ( ctx.mcpBlocked ) {
				if ( ctx.assistant ) {
					ctx.assistant.remove();
					ctx.assistant = null;
				}
				addHostBlockedError();
				return;
			}

			var fatal = fatalInText( ctx.text );
			if ( ctx.errored || fatal ) {
				// Si la bulle "assistant" ne contient QUE l'erreur (ou rien), on la
				// retire pour ne pas afficher deux fois la même chose.
				if ( ctx.assistant && ( fatal || ! ctx.text.trim() ) ) {
					ctx.assistant.remove();
					ctx.assistant = null;
				}
				addError( fatal || ctx.errorMsg );
				// Un tour en erreur n'entre PAS dans la mémoire (sinon on rejouerait
				// "Crédit épuisé" comme contexte au tour suivant).
			} else if ( ctx.text ) {
				// Tour complet réussi -> mémoire pour les prochains messages.
				history.push( { role: 'user', content: prompt } );
				history.push( { role: 'assistant', content: ctx.text } );
			}
		} catch ( err ) {
			removeThinking( ctx );
			addError( 'Network error: ' + ( err && err.message ? err.message : err ) );
		} finally {
			setBusy( false );
			input.focus();
		}
	}

	// --- Liaison UI -------------------------------------------------------
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var prompt = ( input.value || '' ).trim();
		if ( ! prompt || input.disabled ) {
			return;
		}
		input.value = '';
		send( prompt );
	} );

	// Entrée = envoyer ; Shift+Entrée = nouvelle ligne (UX chat classique).
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		}
	} );
} )();
