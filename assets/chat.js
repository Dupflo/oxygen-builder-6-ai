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

	// --- Parsing SSE ------------------------------------------------------
	// Le serveur envoie des blocs séparés par une ligne vide ; chaque bloc a une
	// ou plusieurs lignes `data: <json>`. On accumule dans un buffer et on
	// découpe sur "\n\n" (frontière d'event SSE).
	function handleEvent( raw, ctx ) {
		// On ne garde que les lignes data: et on recolle leur contenu.
		var dataLines = raw
			.split( '\n' )
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

		if ( payload.type === 'text' && payload.text ) {
			if ( ! ctx.assistant ) {
				ctx.assistant = addBubble( 'assistant', '' );
			}
			ctx.assistant.textContent += payload.text;
			ctx.text += payload.text; // accumulé pour l'historique (mémoire)
			log.scrollTop = log.scrollHeight;
		} else if ( payload.type === 'error' ) {
			addBubble( 'error', 'Error: ' + ( payload.message || 'unknown error' ) );
		}
		// type === 'done' : rien à afficher, la session est finie.
	}

	// --- Envoi + lecture du flux -----------------------------------------
	async function send( prompt ) {
		if ( ! cfg.backendUrl ) {
			addBubble( 'error', 'Backend URL is not configured (see Settings above).' );
			return;
		}

		addBubble( 'user', prompt );
		setBusy( true );

		var ctx = { assistant: null, text: '' };
		var headers = { 'Content-Type': 'application/json' };
		if ( cfg.backendToken ) {
			headers.Authorization = 'Bearer ' + cfg.backendToken;
		}

		// Historique = les tours PRÉCÉDENTS (le message courant part dans `prompt`).
		// On envoie au plus MAX_HISTORY derniers messages pour borner le payload.
		var priorHistory = history.slice( -MAX_HISTORY );

		try {
			var resp = await fetch( cfg.backendUrl.replace( /\/$/, '' ) + '/chat', {
				method: 'POST',
				headers: headers,
				body: JSON.stringify( {
					prompt: prompt,
					anthropic_api_key: cfg.anthropicKey,
					mcp_url: cfg.mcpUrl,
					mcp_auth: cfg.mcpAuth,
					history: priorHistory,
				} ),
			} );

			if ( ! resp.ok ) {
				var msg = 'HTTP ' + resp.status;
				try {
					var j = await resp.json();
					if ( j && j.detail ) {
						msg += ' — ' + ( typeof j.detail === 'string' ? j.detail : JSON.stringify( j.detail ) );
					}
				} catch ( e ) {}
				addBubble( 'error', 'Request failed: ' + msg );
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

				// Découpe sur la frontière d'event SSE (ligne vide).
				var parts = buffer.split( '\n\n' );
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

			// Tour complet réussi -> on l'ajoute à la mémoire pour les prochains
			// messages. On stocke APRÈS le stream (et seulement si l'agent a
			// répondu) pour ne jamais mémoriser un tour vide/échoué.
			if ( ctx.text ) {
				history.push( { role: 'user', content: prompt } );
				history.push( { role: 'assistant', content: ctx.text } );
			}
		} catch ( err ) {
			addBubble( 'error', 'Network error: ' + ( err && err.message ? err.message : err ) );
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
