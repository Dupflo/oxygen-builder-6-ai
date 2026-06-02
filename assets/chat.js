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

		var ctx = { assistant: null, text: '', errored: false, errorMsg: '', thinking: null, coldTimer: null };
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

			// --- Réconciliation de fin de flux ---
			removeThinking( ctx ); // si le flux finit sans texte ni erreur (done seul)

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
