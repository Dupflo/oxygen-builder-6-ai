<?php
/**
 * Plugin Name: Oxygen Builder 6 AI
 * Description: Expose des "abilities" Oxygen via l'Abilities API + MCP Adapter (WordPress 7.0). Lecture/écriture de l'arbre de page Oxygen 6. Inclut une page d'accueil de configuration (admin).
 * Version: 0.6.4
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author: Florian Dupuis
 */

// Garde-fou WP : ABSPATH n'est défini que lorsque le fichier est chargé PAR
// WordPress. Sans ça, un accès direct au .php exécuterait le code hors contexte.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Le MCP Adapter (classes \WP\MCP\...) n'est PAS dans le core : on le bundle
// via Composer (`composer require wordpress/mcp-adapter`). On charge son
// autoload PSR-4 ici. `require_once` ≈ un import résolu une seule fois.
// Garde sur file_exists : si le `vendor/` n'a pas été build, le plugin reste
// inerte côté MCP plutôt que de fataliser (l'ability core marche quand même).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';

	// PIÈGE Composer : l'autoload PSR-4 rend les classes \WP\MCP\* chargeables
	// mais N'EXÉCUTE PAS le bootstrap du package (son mcp-adapter.php n'est lancé
	// que s'il est activé comme plugin autonome). Il faut donc amorcer l'adapter
	// nous-mêmes : c'est `McpAdapter::instance()` qui câble le hook rest_api_init
	// déclenchant `mcp_adapter_init` (où notre create_server s'accroche).
	if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		\WP\MCP\Core\McpAdapter::instance();
	}
}

/**
 * PAGE D'ACCUEIL (admin).
 *
 * Après l'activation du plugin, l'utilisateur a besoin de savoir QUOI brancher.
 * On enregistre une page de menu d'admin (hook `admin_menu` ≈ on "monte une
 * route" dans le back-office) qui explique les 2 façons d'utiliser le plugin :
 *   - Mode 1 : connecter SON client MCP (Claude Desktop / Claude Code) à
 *     l'endpoint exposé ici → gratuit, son propre runtime.
 *   - Mode 2b : le chat hébergé (BYOK) → l'utilisateur fournit sa clé Anthropic.
 *
 * Tout le texte affiché est en ANGLAIS (convention repo public). Les commentaires
 * restent pédagogiques en FR (profil dev). PIÈGE WP : toujours échapper les
 * sorties (esc_html / esc_url / esc_attr) — pas de chaîne brute dans le HTML.
 */
add_action( 'admin_menu', 'oxygen_mcp_register_admin_page' );
function oxygen_mcp_register_admin_page() {
	add_menu_page(
		'Oxygen Builder 6 AI',          // <title> de la page
		'Oxygen 6 AI',                  // libellé du menu
		'manage_options',               // capability : admins seulement
		'oxygen-builder-6-ai',          // slug de la page (?page=...)
		'oxygen_mcp_render_admin_page', // callback de rendu
		'dashicons-superhero',          // icône du menu
		81                              // position (sous Réglages)
	);

	// Sous-page « Chat » (Mode 2b). add_submenu_page renvoie le "hook suffix"
	// de la page : on le mémorise pour n'enfiler les assets JS/CSS QUE sur cette
	// page (pas sur tout l'admin → bonne pratique de perf WP).
	$GLOBALS['oxymcp_chat_hook'] = add_submenu_page(
		'oxygen-builder-6-ai',         // slug du parent
		'Oxygen 6 AI — Chat',          // <title>
		'Chat',                        // libellé du sous-menu
		'manage_options',
		'oxygen-mcp-chat',             // slug de la sous-page
		'oxygen_mcp_render_chat_page'  // callback de rendu
	);
}

/**
 * RÉGLAGES du chat (Settings API).
 *
 * On enregistre 4 options dans le MÊME groupe `oxygen_mcp_chat`. Le formulaire de
 * la page chat poste vers `options.php` (cœur WP) avec `settings_fields()` → WP
 * gère la sauvegarde, le nonce (anti-CSRF) et la notice « Réglages enregistrés ».
 *
 * RAPPEL SÉCU : `oxymcp_anthropic_key` est stockée en clair en DB (choix produit
 * assumé). La page est `manage_options` (admins seulement). La clé ne sert que
 * relayée au backend, jamais loggée côté plugin.
 */
// URL du backend agent hébergé. CONSTANTE (pas un réglage) : c'est toujours le
// même service pour tous les sites, et ce N'EST PAS un secret. On ne l'expose
// donc pas à l'utilisateur. Surchargeable en dev via wp-config.php
// (define('OXYMCP_BACKEND_URL', 'http://127.0.0.1:8000');) — le guard ci-dessous
// respecte une définition antérieure.
if ( ! defined( 'OXYMCP_BACKEND_URL' ) ) {
	define( 'OXYMCP_BACKEND_URL', 'https://oxygen-agent.onrender.com' );
}

add_action( 'admin_init', 'oxygen_mcp_register_chat_settings' );
function oxygen_mcp_register_chat_settings() {
	$group = 'oxygen_mcp_chat';
	// Seuls deux secrets appartiennent à l'utilisateur : SA clé Anthropic (BYOK)
	// et un Application Password de SON WordPress. Le backend (mode ouvert BYOK)
	// n'exige aucun jeton — un secret partagé dans un plugin PUBLIC ne protège
	// rien — donc plus de champ "Backend token". L'URL est une constante.
	// sanitize_text_field : on retire balises/retours parasites sans réécrire la valeur.
	register_setting( $group, 'oxymcp_anthropic_key', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
	register_setting( $group, 'oxymcp_app_password', array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
}

/**
 * Enfile les assets du chat UNIQUEMENT sur la sous-page chat.
 *
 * `$hook` = le hook suffix de la page courante ; on le compare à celui mémorisé.
 * wp_localize_script injecte la config (URLs + secrets) dans une variable JS
 * globale `OXYMCP_CHAT` → le JS vanilla la lit pour appeler le backend.
 */
add_action( 'admin_enqueue_scripts', 'oxygen_mcp_chat_assets' );
function oxygen_mcp_chat_assets( $hook ) {
	if ( $hook !== ( $GLOBALS['oxymcp_chat_hook'] ?? '' ) ) {
		return;
	}

	$base = plugin_dir_url( __FILE__ ) . 'assets/';
	$ver  = '0.6.4';
	wp_enqueue_style( 'oxymcp-chat', $base . 'chat.css', array(), $ver );
	wp_enqueue_script( 'oxymcp-chat', $base . 'chat.js', array(), $ver, true );

	// mcp_auth = base64("login:app_password"). On le calcule côté PHP pour ne pas
	// exposer la mécanique au JS ; vide si l'app password n'est pas renseigné.
	$login   = wp_get_current_user()->user_login;
	$app_pwd = (string) get_option( 'oxymcp_app_password', '' );
	$mcp_auth = '' !== $app_pwd ? base64_encode( $login . ':' . $app_pwd ) : '';

	wp_localize_script(
		'oxymcp-chat',
		'OXYMCP_CHAT',
		array(
			'backendUrl'   => OXYMCP_BACKEND_URL,
			'mcpUrl'       => rest_url( 'oxygen-mcp/mcp' ),
			'anthropicKey' => (string) get_option( 'oxymcp_anthropic_key', '' ),
			'mcpAuth'      => $mcp_auth,
		)
	);
}

/**
 * Rendu HTML de la page d'accueil. `home_url()`/`rest_url()` donnent l'endpoint
 * réel du site courant (donc l'utilisateur copie SA propre URL, pas un exemple).
 */
function oxygen_mcp_render_admin_page() {
	// L'endpoint MCP réel de CE site (namespace inchangé : oxygen-mcp/mcp).
	$mcp_endpoint   = esc_url( rest_url( 'oxygen-mcp/mcp' ) );
	$app_pwd_url    = esc_url( admin_url( 'profile.php#application-passwords-section' ) );
	$current_user   = wp_get_current_user();
	$login          = esc_html( $current_user->user_login );
	?>
	<div class="wrap">
		<h1>Oxygen Builder 6 AI</h1>
		<p style="font-size:14px;max-width:820px;">
			This plugin turns your WordPress + Oxygen 6 install into an
			<strong>MCP server</strong>: an AI agent can read and write your page
			tree, selectors (classes), variables, and lint a page.
		</p>

		<h2 style="margin-top:24px;">How do you want to use Oxygen AI?</h2>

		<?php // Deux cartes côte à côte (flex). Le choix est binaire et explicite. ?>
		<div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:12px;max-width:980px;">

			<?php // CARTE 1 — MCP, 100% gratuit (le runtime IA est celui du user). ?>
			<div style="flex:1 1 380px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:20px;">
				<h3 style="margin-top:0;font-size:18px;">1 — Via MCP <span style="color:#00a32a;">(100% free)</span></h3>
				<p>
					Connect your own MCP client — <strong>Claude Desktop</strong>,
					<strong>Claude Code</strong>, or any MCP client — straight to this
					site. The AI runtime is yours, so it costs nothing extra.
				</p>
				<p style="margin-bottom:4px;"><strong>Your MCP endpoint:</strong></p>
				<p>
					<code style="display:inline-block;padding:8px 12px;background:#f0f0f1;border-radius:4px;font-size:13px;word-break:break-all;">
						<?php echo $mcp_endpoint; ?>
					</code>
				</p>
				<p>Example with the <code>mcp-remote</code> bridge:</p>
				<pre style="padding:12px;background:#1d2327;color:#f0f0f1;border-radius:4px;overflow:auto;font-size:12px;line-height:1.5;">npx -y mcp-remote <?php echo $mcp_endpoint; ?> \
  --header "Authorization: Basic BASE64(<?php echo $login; ?>:app_password)"</pre>
			</div>

			<?php // CARTE 2 — Chat conversationnel (BYOK aujourd'hui ; abonnement plus tard). ?>
			<div style="flex:1 1 380px;border:1px solid #c3c4c7;border-radius:8px;background:#fff;padding:20px;">
				<h3 style="margin-top:0;font-size:18px;">2 — Via the conversational chat</h3>
				<p style="color:#646970;margin-top:-6px;">API key or subscription</p>
				<p>
					Prefer not to set up a desktop client? Use the hosted chat and
					bring <strong>your own Anthropic API key</strong> (a managed
					subscription is planned). Your key is used only for the duration
					of the request — never stored, never logged.
				</p>
				<p style="margin-bottom:4px;"><strong>You will need:</strong></p>
				<ul style="list-style:disc;margin-left:20px;">
					<li>your Anthropic API key (<code>sk-ant-...</code>);</li>
					<li>this site's MCP endpoint (shown on the left);</li>
					<li>a WordPress Application Password (see below).</li>
				</ul>
				<p style="margin-top:16px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=oxygen-mcp-chat' ) ); ?>" class="button button-primary">
						Open the chat &rarr;
					</a>
				</p>
			</div>
		</div>

		<h2 style="margin-top:28px;">Authentication — create an Application Password</h2>
		<p style="max-width:820px;">
			Both options authenticate with a WordPress
			<strong>Application Password</strong> (not your login password).
			Go to <a href="<?php echo $app_pwd_url; ?>">your profile &rarr;
			Application Passwords</a>, create one named e.g. <code>oxygen-mcp</code>,
			and copy the generated value (shown only once).
			Your username is <code><?php echo $login; ?></code>.
			<code>BASE64(...)</code> above is the base64 encoding of
			<code>username:app_password</code>.
		</p>
	</div>
	<?php
}

/**
 * Rendu de la PAGE CHAT (Mode 2b — chat hébergé BYOK).
 *
 * Deux blocs : (1) un formulaire de réglages (Settings API → options.php),
 * (2) l'UI de chat (liste de messages + zone de saisie). Le JS (chat.js) lit la
 * config injectée par wp_localize_script et streame la réponse du backend en SSE.
 */
function oxygen_mcp_render_chat_page() {
	$mcp_endpoint = esc_url( rest_url( 'oxygen-mcp/mcp' ) );
	// L'URL backend est une constante (toujours présente) -> "ready" ne dépend que
	// des deux secrets de l'utilisateur : sa clé Anthropic + un Application Password.
	$has_key      = '' !== (string) get_option( 'oxymcp_anthropic_key', '' );
	$has_app_pwd  = '' !== (string) get_option( 'oxymcp_app_password', '' );
	$ready        = $has_key && $has_app_pwd;
	?>
	<div class="wrap">
		<h1>Oxygen 6 AI — Chat</h1>
		<p style="max-width:820px;">
			Talk to your Oxygen site in plain language. Your Anthropic API key is
			sent per request to the hosted backend and used only for that request.
		</p>

		<h2>Settings</h2>
		<form method="post" action="options.php" style="max-width:680px;">
			<?php settings_fields( 'oxygen_mcp_chat' ); // nonce + champs cachés WP ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="oxymcp_anthropic_key">Anthropic API key</label></th>
					<td>
						<input name="oxymcp_anthropic_key" id="oxymcp_anthropic_key" type="password"
							class="regular-text" autocomplete="off" placeholder="sk-ant-..."
							value="<?php echo esc_attr( get_option( 'oxymcp_anthropic_key', '' ) ); ?>" />
						<p class="description">
							Bring your own key (you pay Anthropic directly for usage).
							<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">Get a key &rarr;</a><br />
							Stored in this site's database. Used only to call Anthropic on your behalf.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="oxymcp_app_password">Application Password</label></th>
					<td>
						<input name="oxymcp_app_password" id="oxymcp_app_password" type="password"
							class="regular-text" autocomplete="off" placeholder="xxxx xxxx xxxx xxxx" />
						<p class="description">
							A WordPress Application Password for <code><?php echo esc_html( wp_get_current_user()->user_login ); ?></code>
							(used to authenticate the agent back to this site<?php echo $has_app_pwd ? ' — already saved, leave blank to keep' : ''; ?>).
							<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>" target="_blank" rel="noopener noreferrer">Create one &rarr;</a>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">MCP endpoint</th>
					<td><code><?php echo $mcp_endpoint; ?></code> <span class="description">(auto)</span></td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>

		<h2>Chat</h2>
		<?php if ( ! $ready ) : ?>
			<div class="notice notice-warning inline"><p>
				Fill in your Anthropic API key and an Application Password above, then save, to enable the chat.
			</p></div>
		<?php endif; ?>

		<details style="margin:8px 0 14px;max-width:820px;">
			<summary style="cursor:pointer;color:#646970;">Chat can’t see your site? (host blocking the hosted agent)</summary>
			<div class="notice notice-info inline" style="margin:8px 0;"><p>
				The hosted chat reaches your site from a cloud server. Some hosts and
				security layers (Imunify360, BitNinja, Sucuri, Cloudflare “Bot Fight Mode”,
				Wordfence) block datacenter IPs, so the agent can’t load the Oxygen tools.
				Fix it by asking your host to allow the agent’s IP for <code>/wp-json/</code>,
				or use a local MCP client (Claude Desktop / Claude Code), which connects from
				your own machine.
				<a href="https://github.com/Dupflo/oxygen-builder-6-ai#troubleshooting" target="_blank" rel="noopener noreferrer">Troubleshooting&nbsp;&rarr;</a>
			</p></div>
		</details>

		<div id="oxymcp-chat-app" class="oxymcp-chat<?php echo $ready ? '' : ' is-disabled'; ?>">
			<div id="oxymcp-chat-log" class="oxymcp-chat__log" aria-live="polite"></div>
			<form id="oxymcp-chat-form" class="oxymcp-chat__form">
				<textarea id="oxymcp-chat-input" class="oxymcp-chat__input" rows="2"
					placeholder="e.g. Lint page 33 of the CastelBox collection and summarize the result"
					<?php echo $ready ? '' : 'disabled'; ?>></textarea>
				<button type="submit" class="button button-primary" id="oxymcp-chat-send"
					<?php echo $ready ? '' : 'disabled'; ?>>Send</button>
			</form>
		</div>
	</div>
	<?php
}

/**
 * SEO front-end (porté de l'ancien MU-plugin oxygen-mcp-seo.php).
 *
 * Oxygen tourne SANS thème (pas de functions.php où s'accrocher) → on enregistre
 * ces filtres ici, dans notre plugin toujours chargé. set-seo écrit juste les
 * post meta ; c'est ce code qui les applique. Lit _oxymcp_seo_title /
 * _oxymcp_seo_description, fallback titre = titre de la page.
 */
function oxygen_mcp_seo_current_id() {
	if ( is_admin() ) {
		return 0;
	}
	if ( is_front_page() ) {
		return (int) get_option( 'page_on_front' );
	}
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	return 0;
}

// pre_get_document_title : renvoyer non-vide court-circuite le <title> par défaut
// de WP (qui, en front page statique, vaut le NOM DU SITE, pas celui de la page).
add_filter(
	'pre_get_document_title',
	function ( $title ) {
		$id = oxygen_mcp_seo_current_id();
		if ( ! $id ) {
			return $title;
		}
		$seo = (string) get_post_meta( $id, '_oxymcp_seo_title', true );
		if ( '' === $seo ) {
			$seo = get_the_title( $id );
		}
		return '' !== $seo ? $seo : $title;
	},
	99
);

// Meta description (si renseignée), tôt dans le <head>.
add_action(
	'wp_head',
	function () {
		$id = oxygen_mcp_seo_current_id();
		if ( ! $id ) {
			return;
		}
		$desc = (string) get_post_meta( $id, '_oxymcp_seo_description', true );
		if ( '' !== $desc ) {
			echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
	},
	1
);

// CSS de page custom (posé par set-page-css). MÊME logique que le SEO : Oxygen
// tourne sans thème, donc c'est notre plugin qui injecte le <style>. Priorité 20
// (après le wp_head SEO) ; on émet APRÈS le CSS Oxygen pour pouvoir surcharger.
// Garde-fou anti-breakout : on neutralise un éventuel `</style>`/`</script>` dans
// le CSS (seul vrai vecteur d'évasion de la balise). On NE touche PAS aux `>` :
// le combinateur enfant `.a > .b` est du CSS valide à préserver.
add_action(
	'wp_head',
	function () {
		$id = oxygen_mcp_seo_current_id();
		if ( ! $id ) {
			return;
		}
		$css = (string) get_post_meta( $id, '_oxymcp_custom_css', true );
		if ( '' === trim( $css ) ) {
			return;
		}
		$css = preg_replace( '#</\s*(style|script)#i', '', $css );
		echo "\n" . '<style id="oxymcp-custom-css">' . "\n" . $css . "\n</style>\n";
	},
	20
);

/**
 * 0) Enregistrement de la CATÉGORIE.
 *
 * Une ability DOIT référencer une catégorie déjà enregistrée, et les
 * catégories se déclarent sur un hook DISTINCT, `wp_abilities_api_categories_init`
 * (tiré AVANT `wp_abilities_api_init`). Oublier cette étape = l'ability est
 * silencieusement rejetée (_doing_it_wrong "category not registered").
 */
add_action( 'wp_abilities_api_categories_init', 'oxygen_mcp_register_categories' );
function oxygen_mcp_register_categories() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	wp_register_ability_category(
		'oxygen-mcp',
		array(
			'label'       => __( 'Oxygen MCP', 'oxygen-mcp' ),
			'description' => 'Abilities de pilotage Oxygen 6.',
		)
	);
}

/**
 * 1) Enregistrement des abilities.
 *
 * Hook `wp_abilities_api_init` : tiré par le core une fois l'Abilities API
 * chargée. Une "ability" = unité de fonction typée (schémas entrée/sortie),
 * avec contrôle d'accès — c'est exactement la définition d'un tool MCP, mais
 * normalisée par le core. Le MCP Adapter (§2) se chargera de l'exposer.
 */
add_action( 'wp_abilities_api_init', 'oxygen_mcp_register_abilities' );
function oxygen_mcp_register_abilities() {
	// Si l'API n'est pas là (WordPress < 7.0), on n'enregistre rien plutôt
	// que de fataliser : le plugin reste inerte mais n'casse pas le site.
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'oxygen-mcp/ping',
		array(
			// `category` est OBLIGATOIRE (sinon wp_register_ability rejette via
			// _doing_it_wrong et n'enregistre rien). Regroupe les abilities par thème.
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen MCP Ping', 'oxygen-mcp' ),
			'description'   => 'Renvoie un pong + l’URL du site. Sert à valider que le serveur MCP du site répond à l’agent.',
			// Schémas = JSON Schema (≈ un schéma zod sérialisé). Aucune entrée.
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'pong'     => array( 'type' => 'boolean' ),
					'site_url' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input ) {
				return array(
					'pong'     => true,
					'site_url' => home_url(),
				);
			},
			// Contrôle d'accès : seul un admin peut appeler cette ability.
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/get-page-tree : LECTURE de l'arbre Oxygen d'un post. ---
	wp_register_ability(
		'oxygen-mcp/get-page-tree',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Get Page Tree', 'oxygen-mcp' ),
			'description'   => 'Lit l’arbre Oxygen (postmeta _oxygen_data) d’un post/page. Lecture seule. has_tree=false si la page n’a jamais été ouverte dans Oxygen.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID du post ou de la page WordPress.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'       => array( 'type' => 'boolean' ),
					'post_id'  => array( 'type' => 'integer' ),
					'has_tree' => array( 'type' => 'boolean' ),
					// L'arbre est une structure récursive arbitraire → on laisse
					// `object` permissif (additionalProperties par défaut = true),
					// nullable quand la page n'a pas encore d'arbre.
					'tree'     => array( 'type' => array( 'object', 'null' ) ),
					'error'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_get_page_tree_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/set-page-tree : ÉCRITURE de l'arbre + régén. cache. ---
	wp_register_ability(
		'oxygen-mcp/set-page-tree',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set Page Tree', 'oxygen-mcp' ),
			'description'   => 'Écrit l’arbre Oxygen d’un post/page puis régénère le cache (OBLIGATOIRE sinon page sans CSS). L’arbre est passé comme objet structuré {root,...}. Injecte _nextNodeId + status="exported" si absents (compat. builder).',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID du post ou de la page WordPress cible.',
					),
					'tree'    => array(
						'type'        => 'object',
						'description' => 'Arbre Oxygen : { root: { id, data, children }, ... }. Voir le contrat Oxygen 6.',
					),
				),
				'required'   => array( 'post_id', 'tree' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'      => array( 'type' => 'boolean' ),
					'post_id' => array( 'type' => 'integer' ),
					'bytes'   => array( 'type' => 'integer' ),
					'error'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_page_tree_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/set-variables : palette / tokens du design system. ---
	wp_register_ability(
		'oxygen-mcp/set-variables',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set Variables', 'oxygen-mcp' ),
			'description'   => 'Écrit les variables Oxygen (couleurs, tokens d’unité) puis régénère le CSS. MERGE-SAFE : upsert par id (n’écrase pas les variables existantes) sauf replace=true. Utilisables ensuite en var(--<cssVariableName>) ou token {var-<id>}.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'variables'   => array(
						'type'        => 'array',
						'description' => 'Liste d’objets {id,type,label,cssVariableName,collection,value}.',
						'items'       => array( 'type' => 'object' ),
					),
					'collections' => array(
						'type'        => 'array',
						'description' => 'Groupes affichés dans le panneau. Si absent : dérivé de l’union des variables fusionnées (sinon des groupes seraient masqués).',
						'items'       => array( 'type' => 'string' ),
					),
					'replace'     => array(
						'type'        => 'boolean',
						'description' => 'true = remplace TOUTES les variables au lieu d’un merge par id (défaut false).',
					),
				),
				'required'   => array( 'variables' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'    => array( 'type' => 'boolean' ),
					'kind'  => array( 'type' => 'string' ),
					'count' => array( 'type' => 'integer' ),
					'total' => array( 'type' => 'integer' ),
					'bytes' => array( 'type' => 'integer' ),
					'error' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_variables_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/set-selectors : classes nommées (la feuille de style). ---
	wp_register_ability(
		'oxygen-mcp/set-selectors',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set Selectors', 'oxygen-mcp' ),
			'description'   => 'Écrit les sélecteurs Oxygen (classes → propriétés visuelles responsive) puis régénère le CSS. MERGE-SAFE : upsert par id sauf replace=true. Référencés par properties.meta.classes des nœuds via leur id.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'selectors'   => array(
						'type'        => 'array',
						'description' => 'Liste d’objets {id,name,type,collection,properties:{breakpoint_base:{…}}}.',
						'items'       => array( 'type' => 'object' ),
					),
					'collections' => array(
						'type'        => 'array',
						'description' => 'Groupes affichés. Si absent : dérivé de l’union des sélecteurs fusionnés.',
						'items'       => array( 'type' => 'string' ),
					),
					'replace'     => array(
						'type'        => 'boolean',
						'description' => 'true = remplace TOUS les sélecteurs au lieu d’un merge par id (défaut false).',
					),
				),
				'required'   => array( 'selectors' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'    => array( 'type' => 'boolean' ),
					'kind'  => array( 'type' => 'string' ),
					'count' => array( 'type' => 'integer' ),
					'total' => array( 'type' => 'integer' ),
					'bytes' => array( 'type' => 'integer' ),
					'error' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_selectors_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/apply-responsive-preset : socle de conventions (tokens). ---
	wp_register_ability(
		'oxygen-mcp/apply-responsive-preset',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Apply Responsive Preset', 'oxygen-mcp' ),
			'description'   => 'Installe le système de tokens responsive : variables d’unité (container-width, padding-*, space-1..8, radius-1..3, size-1..6, default-size) + sélecteurs fondation câblés (container, section, wrapper, heading-1..6 qui descendent d’un cran en tablette). MERGE-SAFE. Aucun px en dur : tout pointe une variable → changer un token mv à jour tout le site.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'overrides' => array(
						'type'        => 'object',
						'description' => 'Surcharge de valeurs px par nom de token, ex. {"container-width":1320,"size-1":60}.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'              => array( 'type' => 'boolean' ),
					'variables'       => array( 'type' => 'integer' ),
					'selectors'       => array( 'type' => 'integer' ),
					'variables_total' => array( 'type' => 'integer' ),
					'selectors_total' => array( 'type' => 'integer' ),
					'error'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_apply_responsive_preset_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/create-doc : CRÉE un document Oxygen (ou page/post). ---
	wp_register_ability(
		'oxygen-mcp/create-doc',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Create Doc', 'oxygen-mcp' ),
			'description'   => 'Crée un document nommé : composant (oxygen_block), en-tête (oxygen_header), pied (oxygen_footer), gabarit (oxygen_template), ou page/post. Idempotent par (post_type, slug). Le post_title EST le nom affiché dans Oxygen ; l’arbre se pose ensuite via set-page-tree (même postmeta _oxygen_data).',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => 'oxygen_block | oxygen_header | oxygen_footer | oxygen_template | page | post.',
					),
					'title'     => array(
						'type'        => 'string',
						'description' => 'Titre = nom affiché dans Oxygen.',
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => 'Slug (post_name). Défaut : dérivé du titre (sanitize_title). Sert de clé d’idempotence.',
					),
				),
				'required'   => array( 'post_type', 'title' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'        => array( 'type' => 'boolean' ),
					'status'    => array( 'type' => 'string' ),
					'post_id'   => array( 'type' => 'integer' ),
					'post_type' => array( 'type' => 'string' ),
					'slug'      => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
					'error'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_create_doc_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/set-front-page : page d'accueil statique (Réglages→Lecture). ---
	wp_register_ability(
		'oxygen-mcp/set-front-page',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set Front Page', 'oxygen-mcp' ),
			'description'   => 'Définit la page d’accueil statique (show_on_front=page + page_on_front=<post_id>). Sinon WordPress affiche les derniers articles → racine blog. Idempotent.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID de la page à mettre en accueil.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'      => array( 'type' => 'boolean' ),
					'status'  => array( 'type' => 'string' ),
					'post_id' => array( 'type' => 'integer' ),
					'error'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_front_page_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/set-seo : <title> + meta description d'une page. ---
	wp_register_ability(
		'oxygen-mcp/set-seo',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set SEO', 'oxygen-mcp' ),
			'description'   => 'Force le <title> (et meta description) d’une page, y compris en accueil statique (où WP met le nom du site). Stocke _oxymcp_seo_title / _oxymcp_seo_description en post meta ; les filtres pre_get_document_title + wp_head de CE plugin (toujours chargé) les appliquent. Fallback titre = titre de la page.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array(
						'type'        => 'integer',
						'description' => 'ID de la page ciblée.',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'Titre SEO. Si absent : titre de la page.',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'Meta description. Si absente : pas de balise meta description.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'          => array( 'type' => 'boolean' ),
					'status'      => array( 'type' => 'string' ),
					'post_id'     => array( 'type' => 'integer' ),
					'title'       => array( 'type' => array( 'string', 'null' ) ),
					'description' => array( 'type' => array( 'string', 'null' ) ),
					'error'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_seo_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/sideload-image : importe une image distante en médiathèque. ---
	wp_register_ability(
		'oxygen-mcp/sideload-image',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Sideload Image', 'oxygen-mcp' ),
			'description'   => 'Télécharge une image depuis une URL distante (Unsplash, etc.) DANS la médiathèque WordPress, puis renvoie l’URL LOCALE + attachment_id à utiliser dans l’arbre (content.image.from="url"). Évite le hotlinking. Idempotent par URL source. Optionnel : attach_to_post (rattache à un post) et alt (texte alternatif).',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'url'            => array(
						'type'        => 'string',
						'description' => 'URL absolue de l’image distante à importer.',
					),
					'alt'            => array(
						'type'        => 'string',
						'description' => 'Texte alternatif (stocké en _wp_attachment_image_alt). Optionnel.',
					),
					'attach_to_post' => array(
						'type'        => 'integer',
						'description' => 'ID du post auquel rattacher le média (post_parent). 0 = médiathèque non rattachée. Optionnel.',
					),
				),
				'required'   => array( 'url' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'            => array( 'type' => 'boolean' ),
					'status'        => array( 'type' => 'string' ),
					'attachment_id' => array( 'type' => 'integer' ),
					'url'           => array( 'type' => array( 'string', 'null' ) ),
					'error'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_sideload_image_exec',
			'permission_callback' => function () {
				return current_user_can( 'upload_files' );
			},
		)
	);

	// --- oxygen-mcp/set-page-css : CSS arbitraire fiable (injecté via wp_head). ---
	wp_register_ability(
		'oxygen-mcp/set-page-css',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Set Page CSS', 'oxygen-mcp' ),
			'description'   => 'Attache un bloc CSS arbitraire à une page, rendu de façon FIABLE via wp_head (priorité 20, après le CSS Oxygen → surcharge possible). Contourne le piège de l’élément CssCode (non compilé par generateCacheForPost). Stocké en postmeta _oxymcp_custom_css. css vide ou absent = efface le bloc. Idempotent (remplace). Cibler les NAMES des sélecteurs (.card, .hero), pas leurs id.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'ID de la page ciblée.',
					),
					'css'     => array(
						'type'        => 'string',
						'description' => 'CSS brut (sans balise <style>). Vide/absent = efface le bloc custom de cette page.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'      => array( 'type' => 'boolean' ),
					'status'  => array( 'type' => 'string' ),
					'post_id' => array( 'type' => 'integer' ),
					'bytes'   => array( 'type' => 'integer' ),
					'error'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_set_page_css_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/get-selectors : LECTURE des sélecteurs du design system. ---
	// Symétrique de set-selectors. Filtre optionnel par collection (≈ un WHERE)
	// pour ne relire que les classes d'une maquette donnée (ex. CastelBox).
	wp_register_ability(
		'oxygen-mcp/get-selectors',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Get Selectors', 'oxygen-mcp' ),
			'description'   => 'Lit les sélecteurs (classes) Oxygen stockés (option oxygen_oxy_selectors_json_string). Lecture seule. Filtre optionnel `collection` pour ne renvoyer qu’un design system donné. Renvoie aussi la liste des collections présentes.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'collection' => array(
						'type'        => 'string',
						'description' => 'Filtre : ne renvoyer que les sélecteurs de cette collection (ex. "CastelBox"). Absent = tous.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'          => array( 'type' => 'boolean' ),
					'count'       => array( 'type' => 'integer' ),
					'total'       => array( 'type' => 'integer' ),
					'collections' => array( 'type' => 'array' ),
					'selectors'   => array( 'type' => 'array' ),
					'error'       => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_get_selectors_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/get-variables : LECTURE des variables / tokens. ---
	wp_register_ability(
		'oxygen-mcp/get-variables',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Get Variables', 'oxygen-mcp' ),
			'description'   => 'Lit les variables/tokens du design system (option oxygen_variables_json_string). Lecture seule.',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'        => array( 'type' => 'boolean' ),
					'count'     => array( 'type' => 'integer' ),
					'variables' => array( 'type' => 'array' ),
					'error'     => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_get_variables_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);

	// --- oxygen-mcp/verify-page : LINT (QA automatique). ---
	// Auto-détecte la classe de bugs jusqu'ici attrapés à la main en PHP :
	// nesting spacing erroné (drop silencieux), groupes vides, custom_css,
	// clés de propriété inconnues, tokens {var-…} non résolus dans le CSS
	// compilé, nœuds "missing" dans l'arbre. Lecture seule, n'écrit rien.
	wp_register_ability(
		'oxygen-mcp/verify-page',
		array(
			'category'      => 'oxygen-mcp',
			'label'         => __( 'Oxygen Verify / Lint', 'oxygen-mcp' ),
			'description'   => 'LINT lecture seule. Analyse sélecteurs (collection optionnelle) + CSS compilé + arbre de page (post_id optionnel). Détecte : nesting spacing erroné (bug du drop silencieux padding/margin), groupes de propriétés vides, custom_css présent, clés de groupe inconnues, tokens {var-…} non résolus, occurrences "missing" dans l’arbre. Renvoie un rapport structuré + un tableau `warnings` lisible (vide = page saine).',
			'input_schema'  => array(
				'type'       => 'object',
				'properties' => array(
					'collection' => array(
						'type'        => 'string',
						'description' => 'Restreindre l’analyse des sélecteurs à cette collection (ex. "CastelBox").',
					),
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'Page à inspecter (arbre + custom_css). Absent = on saute la partie page.',
					),
				),
			),
			'output_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'ok'            => array( 'type' => 'boolean' ),
					'warning_count' => array( 'type' => 'integer' ),
					'warnings'      => array( 'type' => 'array' ),
					'report'        => array( 'type' => 'object' ),
					'error'         => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'oxygen_mcp_verify_page_exec',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		)
	);
}

/**
 * Callback get-page-tree. `$input` = arguments typés (≈ req.body validé). On
 * renvoie un tableau associatif (sérialisé en JSON par l'adapter MCP). Pas de
 * marqueurs ni d'extraction : on tourne DANS WordPress, contrairement à l'ancien
 * `wp eval-file`.
 */
function oxygen_mcp_get_page_tree_exec( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id manquant ou invalide' );
	}
	if ( ! get_post( $post_id ) ) {
		return array( 'ok' => false, 'error' => "post $post_id introuvable" );
	}
	if ( ! function_exists( 'Breakdance\\Data\\get_tree' ) ) {
		return array( 'ok' => false, 'error' => 'API Oxygen indisponible (plugin actif ?)' );
	}

	// get_tree() renvoie l'arbre décodé OU false si _oxygen_data absent/invalide
	// (ex. page jamais ouverte dans Oxygen).
	$tree = \Breakdance\Data\get_tree( $post_id );

	return array(
		'ok'       => true,
		'post_id'  => $post_id,
		'has_tree' => false !== $tree,
		'tree'     => false === $tree ? null : $tree,
	);
}

/**
 * Callback set-page-tree. Sous-ensemble de save_document() restreint à l'arbre
 * (on ne touche ni global settings ni singularity meta). Cf. set_tree.php (ancien
 * payload eval-file) dont ceci reprend la logique de validation/persistance.
 */
function oxygen_mcp_set_page_tree_exec( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$tree    = isset( $input['tree'] ) ? $input['tree'] : null;

	if ( $post_id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id manquant ou invalide' );
	}
	if ( ! get_post( $post_id ) ) {
		return array( 'ok' => false, 'error' => "post $post_id introuvable" );
	}
	if (
		! function_exists( 'Breakdance\\Data\\set_meta' )
		|| ! function_exists( 'Breakdance\\Render\\generateCacheForPost' )
	) {
		return array( 'ok' => false, 'error' => 'API Oxygen indisponible (plugin actif ?)' );
	}

	// Validation = logique de Breakdance\Data\is_valid_tree : on refuse un arbre
	// cassé qui rendrait la page non éditable.
	if (
		! is_array( $tree )
		|| ! isset( $tree['root'] ) || ! is_array( $tree['root'] )
		|| ! array_key_exists( 'id', $tree['root'] )
		|| ! array_key_exists( 'data', $tree['root'] )
		|| ! array_key_exists( 'children', $tree['root'] )
	) {
		return array( 'ok' => false, 'error' => 'arbre invalide : root.{id,data,children} requis' );
	}

	// Enveloppe builder (cf. contrat Oxygen §2) : sans `_nextNodeId` ET `status`,
	// le décodeur IO-TS du builder rejette l'arbre. On les injecte si absents.
	if ( ! array_key_exists( 'status', $tree ) ) {
		$tree['status'] = 'exported';
	}
	if ( ! array_key_exists( '_nextNodeId', $tree ) ) {
		$tree['_nextNodeId'] = oxygen_mcp_max_node_id( $tree['root'] ) + 1;
	}

	// On stocke la CHAÎNE JSON (comme save_document : 'tree_json_string' => string).
	// UNESCAPED_SLASHES/UNICODE = sortie compacte et lisible, alignée sur l'export.
	$tree_json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	\Breakdance\Data\set_meta(
		$post_id,
		'_oxygen_data',
		array( 'tree_json_string' => $tree_json )
	);

	// Maj date de modif + déclenche une révision (cf. save.php).
	wp_update_post( array( 'ID' => $post_id ) );

	// OBLIGATOIRE : sans ça la page s'affiche sans CSS (cache.php).
	\Breakdance\Render\generateCacheForPost( $post_id );

	if ( function_exists( 'clean_post_cache' ) ) {
		clean_post_cache( $post_id );
	}

	return array(
		'ok'      => true,
		'post_id' => $post_id,
		'bytes'   => strlen( $tree_json ),
	);
}

/** Types de documents Oxygen acceptés par create-doc (+ page/post WP). */
function oxygen_mcp_doc_types() {
	return array( 'oxygen_block', 'oxygen_header', 'oxygen_footer', 'oxygen_template', 'page', 'post' );
}

/**
 * Callback create-doc. Idempotent par (post_type, slug) : on cherche d'abord un
 * post existant de ce type/slug (tous statuts), sinon on l'insère. Tous ces types
 * partagent le même postmeta _oxygen_data → l'arbre se pose ensuite via set-page-tree.
 */
function oxygen_mcp_create_doc_exec( $input ) {
	$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
	$title     = isset( $input['title'] ) ? (string) $input['title'] : '';

	if ( ! in_array( $post_type, oxygen_mcp_doc_types(), true ) ) {
		return array( 'ok' => false, 'error' => "post_type '$post_type' non supporté ; attendus : " . implode( ', ', oxygen_mcp_doc_types() ) );
	}
	if ( '' === trim( $title ) ) {
		return array( 'ok' => false, 'error' => 'title manquant' );
	}

	// sanitize_title ≈ slugify de WP (accents → ascii, espaces → tirets). Stable
	// donc utilisable comme clé d'idempotence (passé en post_name ET recherché).
	$slug = isset( $input['slug'] ) && '' !== trim( (string) $input['slug'] )
		? sanitize_title( (string) $input['slug'] )
		: sanitize_title( $title );
	if ( '' === $slug ) {
		$slug = 'doc';
	}

	// Recherche existant : WP_Query plutôt que get_page_by_path car les types
	// Oxygen ne sont pas hiérarchiques et 'name' suffit. post_status=any inclut
	// brouillons/publish (l'idempotence ne dépend pas du statut).
	$existing = get_posts(
		array(
			'post_type'        => $post_type,
			'name'             => $slug,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);
	if ( ! empty( $existing ) ) {
		return array(
			'ok'        => true,
			'status'    => 'exists',
			'post_id'   => (int) $existing[0],
			'post_type' => $post_type,
			'slug'      => $slug,
			'title'     => $title,
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
		),
		true // $wp_error = true → renvoie un WP_Error au lieu de 0 en cas d'échec.
	);
	if ( is_wp_error( $post_id ) ) {
		return array( 'ok' => false, 'error' => $post_id->get_error_message() );
	}

	return array(
		'ok'        => true,
		'status'    => 'created',
		'post_id'   => (int) $post_id,
		'post_type' => $post_type,
		'slug'      => $slug,
		'title'     => $title,
	);
}

/**
 * Callback set-front-page. update_option crée ou met à jour (idempotent par
 * nature). On vérifie d'abord que le post existe (sinon accueil cassé) et on
 * ne réécrit pas si déjà configuré ainsi.
 */
function oxygen_mcp_set_front_page_exec( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id manquant ou invalide' );
	}
	if ( ! get_post( $post_id ) ) {
		return array( 'ok' => false, 'error' => "post $post_id introuvable" );
	}

	$already = 'page' === get_option( 'show_on_front' )
		&& (int) get_option( 'page_on_front' ) === $post_id;

	if ( ! $already ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $post_id );
	}

	return array(
		'ok'      => true,
		'status'  => $already ? 'exists' : 'set',
		'post_id' => $post_id,
	);
}

/**
 * Callback set-seo. Stocke titre/description en post meta ; ce sont les filtres
 * oxygen_mcp_seo_* (enregistrés au chargement du plugin, plus bas) qui les
 * appliquent côté front. Pas de MU-plugin séparé : notre plugin est déjà chargé.
 */
function oxygen_mcp_set_seo_exec( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id manquant ou invalide' );
	}
	if ( ! get_post( $post_id ) ) {
		return array( 'ok' => false, 'error' => "post $post_id introuvable" );
	}

	// isset → on ne touche la meta QUE si la clé est fournie (un appel ne donnant
	// que `title` ne doit pas effacer une `description` déjà posée).
	$title = null;
	$desc  = null;
	if ( isset( $input['title'] ) ) {
		$title = (string) $input['title'];
		update_post_meta( $post_id, '_oxymcp_seo_title', $title );
	}
	if ( isset( $input['description'] ) ) {
		$desc = (string) $input['description'];
		update_post_meta( $post_id, '_oxymcp_seo_description', $desc );
	}

	return array(
		'ok'          => true,
		'status'      => 'set',
		'post_id'     => $post_id,
		'title'       => $title,
		'description' => $desc,
	);
}

/**
 * Callback sideload-image. Importe une image distante dans la médiathèque.
 *
 * PIÈGE WP : download_url / media_handle_sideload vivent dans wp-admin/includes/
 * (chargés seulement en contexte admin). En REST/MCP on est hors admin → il faut
 * les require_once explicitement, sinon "Call to undefined function".
 *
 * Idempotence : on tague chaque média importé avec sa _oxymcp_source_url ; un 2e
 * appel sur la même URL retrouve l'attachment au lieu de re-télécharger (≈ upsert).
 */
function oxygen_mcp_sideload_image_exec( $input ) {
	// esc_url_raw ≈ normalise/valide l'URL pour stockage (pas pour l'affichage).
	$url = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
	if ( '' === $url ) {
		return array( 'ok' => false, 'error' => 'url manquante ou invalide' );
	}

	// Déjà importée ? meta_query par URL source. fields=ids → on ne charge que l'id.
	$existing = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => '_oxymcp_source_url',
			'meta_value'       => $url,
			'suppress_filters' => false,
		)
	);
	if ( ! empty( $existing ) ) {
		$id = (int) $existing[0];
		return array(
			'ok'            => true,
			'status'        => 'exists',
			'attachment_id' => $id,
			'url'           => wp_get_attachment_url( $id ),
		);
	}

	// Helpers média (absents hors wp-admin). require_once = idempotent.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Télécharge dans un fichier temp. 2e arg = timeout (s). Renvoie path ou WP_Error.
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return array( 'ok' => false, 'error' => 'téléchargement échoué : ' . $tmp->get_error_message() );
	}

	// Nom de fichier + extension. Beaucoup d'URLs (Unsplash) n'ont pas d'extension
	// dans le path → media_handle_sideload rejette ("type de fichier non autorisé").
	// On dérive donc l'extension du MIME réel du fichier téléchargé.
	$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
	$base     = '' !== $path ? basename( $path ) : 'image';
	$base     = preg_replace( '/\.[a-z0-9]+$/i', '', $base ); // retire ext. existante
	if ( '' === $base ) {
		$base = 'image';
	}
	$mime_map = array(
		'image/jpeg'    => 'jpg',
		'image/png'     => 'png',
		'image/gif'     => 'gif',
		'image/webp'    => 'webp',
		'image/avif'    => 'avif',
		'image/svg+xml' => 'svg',
	);
	$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
	$ext  = isset( $mime_map[ $mime ] ) ? $mime_map[ $mime ] : 'jpg';
	$name = $base . '.' . $ext;

	$attach_to  = isset( $input['attach_to_post'] ) ? (int) $input['attach_to_post'] : 0;
	$file_array = array( 'name' => $name, 'tmp_name' => $tmp );

	// media_handle_sideload : déplace le temp en médiathèque, crée l'attachment +
	// génère les tailles. En cas d'échec il NE nettoie pas toujours le temp → on @unlink.
	$attachment_id = media_handle_sideload( $file_array, $attach_to );
	if ( is_wp_error( $attachment_id ) ) {
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}
		return array( 'ok' => false, 'error' => 'import média échoué : ' . $attachment_id->get_error_message() );
	}

	update_post_meta( $attachment_id, '_oxymcp_source_url', $url );
	if ( isset( $input['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	return array(
		'ok'            => true,
		'status'        => 'created',
		'attachment_id' => (int) $attachment_id,
		'url'           => wp_get_attachment_url( (int) $attachment_id ),
	);
}

/**
 * Callback set-page-css. Stocke le CSS en post meta ; c'est le hook wp_head de CE
 * plugin (plus haut) qui l'émet côté front. Même esprit que set-seo : on découple
 * le style de l'arbre Oxygen → CSS fiable, sans dépendre du compilateur Breakdance.
 */
function oxygen_mcp_set_page_css_exec( $input ) {
	$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

	if ( $post_id <= 0 ) {
		return array( 'ok' => false, 'error' => 'post_id manquant ou invalide' );
	}
	if ( ! get_post( $post_id ) ) {
		return array( 'ok' => false, 'error' => "post $post_id introuvable" );
	}

	$css = isset( $input['css'] ) ? (string) $input['css'] : '';

	// CSS vide = on efface le bloc (delete_post_meta) plutôt que stocker du vide.
	if ( '' === trim( $css ) ) {
		delete_post_meta( $post_id, '_oxymcp_custom_css' );
		return array(
			'ok'      => true,
			'status'  => 'cleared',
			'post_id' => $post_id,
			'bytes'   => 0,
		);
	}

	update_post_meta( $post_id, '_oxymcp_custom_css', $css );

	return array(
		'ok'      => true,
		'status'  => 'set',
		'post_id' => $post_id,
		'bytes'   => strlen( $css ),
	);
}

/**
 * Plus grand `id` entier présent dans l'arbre (parcours récursif). Sert à dériver
 * `_nextNodeId` quand l'enveloppe builder ne le fournit pas.
 */
function oxygen_mcp_max_node_id( $node ) {
	$max = isset( $node['id'] ) ? (int) $node['id'] : 0;
	if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $child ) {
			$child_max = oxygen_mcp_max_node_id( $child );
			if ( $child_max > $max ) {
				$max = $child_max;
			}
		}
	}
	return $max;
}

/**
 * Lit une wp_option qui stocke un tableau JSON (ex. oxygen_variables_json_string).
 * Renvoie [] si absente/vide/non-JSON → base sûre pour un merge (on n'écrase rien).
 */
function oxygen_mcp_read_option_array( $option ) {
	$raw = get_option( $option, '' );
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( ! is_string( $raw ) || '' === $raw ) {
		return array();
	}
	$val = json_decode( $raw, true );
	return is_array( $val ) ? $val : array();
}

/**
 * Fusionne deux listes d'objets `{id,...}` : garde l'existant, remplace/ajoute
 * par `id`. ≈ un upsert (Map keyée par id). Les ids du design system sont
 * stables → rejouable sans doublon.
 */
function oxygen_mcp_upsert_by_id( $existing, $incoming ) {
	$by_id = array();
	foreach ( $existing as $o ) {
		if ( is_array( $o ) && isset( $o['id'] ) ) {
			$by_id[ $o['id'] ] = $o;
		}
	}
	foreach ( $incoming as $o ) {
		if ( is_array( $o ) && isset( $o['id'] ) ) {
			$by_id[ $o['id'] ] = $o;
		}
	}
	return array_values( $by_id );
}

/**
 * Union des `collection` d'une liste d'items, fallback si aucune. Le panneau
 * Oxygen n'affiche un groupe que s'il figure dans cette liste → si on n'envoie
 * qu'une collection, les autres groupes deviennent invisibles (bug observé).
 */
function oxygen_mcp_derive_collections( $items, $fallback ) {
	$cols = array();
	foreach ( $items as $o ) {
		if ( is_array( $o ) && ! empty( $o['collection'] ) ) {
			$cols[ $o['collection'] ] = true;
		}
	}
	$list = array_keys( $cols );
	sort( $list );
	return empty( $list ) ? array( $fallback ) : $list;
}

/**
 * Cœur partagé set-variables / set-selectors : merge-safe (sauf replace),
 * dérive les collections, appelle la fonction de save Oxygen (qui régénère le
 * CSS global elle-même). `$save` = callable( string $json ) → la fonction
 * \Breakdance\…\save*. `$key` = "variables" | "selectors".
 */
function oxygen_mcp_save_design( $input, $key, $option, $fallback_collection, $save ) {
	$incoming = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : null;
	if ( null === $incoming ) {
		return array( 'ok' => false, 'error' => "$key manquant ou invalide" );
	}
	$replace = ! empty( $input['replace'] );

	$items = $replace
		? $incoming
		: oxygen_mcp_upsert_by_id( oxygen_mcp_read_option_array( $option ), $incoming );

	$collections = ( isset( $input['collections'] ) && is_array( $input['collections'] ) )
		? $input['collections']
		: oxygen_mcp_derive_collections( $items, $fallback_collection );

	$json = wp_json_encode(
		array( $key => $items, 'collections' => $collections ),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	$save( $json );

	// Régén. FORCÉE : saveSelectors/saveVariables ne recompilent le CSS QUE si
	// le master a changé (cf. `if ($variables !== $currentVariables)`). Sur un
	// re-push idempotent (même design), ils sautent la régén → le CSS compilé
	// peut rester PÉRIMÉ si un push précédent a écrit le master sans compiler
	// (ex. front-end cassé pendant le push). Notre contrat = « le CSS live
	// reflète CE design », donc on réconcilie toujours. Sûr vis-à-vis du static
	// getOxySelectors() : le master est déjà écrit en DB ici, la régén lit la
	// liste complète. Au pire une recompilation redondante (négligeable).
	if ( function_exists( 'Breakdance\\Render\\generateCacheForGlobalSettings' ) ) {
		\Breakdance\Render\generateCacheForGlobalSettings();
	}

	return array(
		'ok'    => true,
		'kind'  => $key,
		'count' => count( $incoming ),
		'total' => count( $items ),
		'bytes' => strlen( $json ),
	);
}

/** Callback set-variables. */
function oxygen_mcp_set_variables_exec( $input ) {
	if ( ! function_exists( 'Breakdance\\Variables\\saveVariables' ) ) {
		return array( 'ok' => false, 'error' => 'API variables indisponible (Oxygen actif ?)' );
	}
	return oxygen_mcp_save_design(
		$input,
		'variables',
		'oxygen_variables_json_string',
		'Global',
		function ( $json ) {
			\Breakdance\Variables\saveVariables( $json );
		}
	);
}

/** Callback set-selectors. */
function oxygen_mcp_set_selectors_exec( $input ) {
	if ( ! function_exists( 'Breakdance\\BreakdanceOxygen\\Selectors\\saveSelectors' ) ) {
		return array( 'ok' => false, 'error' => 'API selectors indisponible (Oxygen actif ?)' );
	}
	return oxygen_mcp_save_design(
		$input,
		'selectors',
		'oxygen_oxy_selectors_json_string',
		'Default',
		function ( $json ) {
			\Breakdance\BreakdanceOxygen\Selectors\saveSelectors( $json );
		}
	);
}

/**
 * Normalise une option design (sélecteurs/variables) en LISTE plate d'items.
 * Le master peut être stocké soit en liste plate `[ {id,...}, ... ]`, soit
 * enveloppé `{ <key>:[...], collections:[...] }`. array_is_list() (PHP 8.1)
 * ≈ Array.isArray + clés 0..n-1 → on distingue les deux cas sans ambiguïté.
 */
function oxygen_mcp_design_items( $option, $key ) {
	$raw = oxygen_mcp_read_option_array( $option );
	if ( empty( $raw ) ) {
		return array();
	}
	if ( array_is_list( $raw ) ) {
		return $raw;
	}
	if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
		return $raw[ $key ];
	}
	return array();
}

/** Callback get-selectors : lecture seule + filtre collection optionnel. */
function oxygen_mcp_get_selectors_exec( $input ) {
	$all    = oxygen_mcp_design_items( 'oxygen_oxy_selectors_json_string', 'selectors' );
	$filter = ( isset( $input['collection'] ) && '' !== $input['collection'] )
		? (string) $input['collection'] : null;

	$sel = ( null === $filter )
		? $all
		: array_values(
			array_filter(
				$all,
				function ( $s ) use ( $filter ) {
					return is_array( $s ) && ( $s['collection'] ?? '' ) === $filter;
				}
			)
		);

	return array(
		'ok'          => true,
		'count'       => count( $sel ),
		'total'       => count( $all ),
		'collections' => oxygen_mcp_derive_collections( $all, 'Default' ),
		'selectors'   => $sel,
	);
}

/** Callback get-variables : lecture seule. */
function oxygen_mcp_get_variables_exec( $input ) {
	$vars = oxygen_mcp_design_items( 'oxygen_variables_json_string', 'variables' );
	return array(
		'ok'        => true,
		'count'     => count( $vars ),
		'variables' => $vars,
	);
}

/**
 * Groupes de propriétés connus d'un sélecteur (whitelist du lint). Toute autre
 * clé au niveau d'un breakpoint est suspecte (faute de frappe, schéma changé).
 */
function oxygen_mcp_known_groups() {
	return array(
		'layout', 'flex_child', 'grid_child', 'position', 'size', 'typography',
		'spacing', 'background', 'borders', 'effects', 'content', 'overflow',
		'transform', 'filters',
	);
}

/**
 * Lint d'UN sélecteur (et de ses children = pseudo-états). Empile des messages
 * dans $w (warnings) par référence. `&$w` ≈ passage par référence (en JS on
 * muterait un tableau capturé) : indispensable car PHP copie les tableaux.
 */
function oxygen_mcp_lint_selector( $s, &$w ) {
	if ( ! is_array( $s ) ) {
		return;
	}
	$name  = (string) ( $s['name'] ?? $s['id'] ?? '?' );
	$known = oxygen_mcp_known_groups();

	// custom_css au niveau sélecteur = entorse à la RÈGLE #1.
	if ( ! empty( $s['css'] ) && is_string( $s['css'] ) && '' !== trim( $s['css'] ) ) {
		$w[] = "[$name] custom_css présent sur le sélecteur (RÈGLE #1 : 0 custom_css).";
	}

	$props = ( isset( $s['properties'] ) && is_array( $s['properties'] ) ) ? $s['properties'] : array();
	foreach ( $props as $bp => $groups ) {
		if ( ! is_array( $groups ) ) {
			continue;
		}
		foreach ( $groups as $gname => $gval ) {
			// Groupe vide {} → IO-TS du builder peut rejeter / no-op silencieux.
			if ( is_array( $gval ) && empty( $gval ) ) {
				$w[] = "[$name/$bp] groupe '$gname' vide.";
			}
			// Clé de groupe inconnue.
			if ( ! in_array( $gname, $known, true ) ) {
				$w[] = "[$name/$bp] clé de groupe inconnue : '$gname'.";
			}
			// LE bug historique : spacing doit être {spacing:{margin,padding}}.
			// Un margin/padding posé DIRECTEMENT sous 'spacing' est compilé en
			// silence sans effet → tout l'espacement disparaît.
			if ( 'spacing' === $gname && is_array( $gval ) ) {
				if ( isset( $gval['margin'] ) || isset( $gval['padding'] ) ) {
					$w[] = "[$name/$bp] nesting spacing ERRONÉ : margin/padding posés directement sous 'spacing' au lieu de spacing.spacing.* → DROP SILENCIEUX (le bug historique).";
				}
			}
		}
	}

	// Children = pseudo-états (hover…), même structure → on récurse.
	if ( isset( $s['children'] ) && is_array( $s['children'] ) ) {
		foreach ( $s['children'] as $c ) {
			oxygen_mcp_lint_selector( $c, $w );
		}
	}
}

/** Compte récursif des nœuds d'un arbre + collecte des classes référencées. */
function oxygen_mcp_walk_tree( $node, &$count, &$classes ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	++$count;
	$meta = $node['data']['properties']['design']['meta']['classes']
		?? ( $node['data']['properties']['meta']['classes'] ?? null );
	if ( is_array( $meta ) ) {
		foreach ( $meta as $cl ) {
			if ( is_string( $cl ) ) {
				$classes[ $cl ] = true;
			} elseif ( is_array( $cl ) && isset( $cl['name'] ) ) {
				$classes[ (string) $cl['name'] ] = true;
			}
		}
	}
	if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $child ) {
			oxygen_mcp_walk_tree( $child, $count, $classes );
		}
	}
}

/**
 * Callback verify-page : LINT lecture seule. Trois étages : sélecteurs (source),
 * CSS compilé (uploads/oxygen/css), arbre de page (si post_id). N'ÉCRIT RIEN.
 */
function oxygen_mcp_verify_page_exec( $input ) {
	$filter = ( isset( $input['collection'] ) && '' !== $input['collection'] )
		? (string) $input['collection'] : null;
	$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
	$warnings = array();
	$report   = array();

	// --- Étage 1 : sélecteurs (source) ---
	$all = oxygen_mcp_design_items( 'oxygen_oxy_selectors_json_string', 'selectors' );
	$scoped = ( null === $filter )
		? $all
		: array_values(
			array_filter(
				$all,
				function ( $s ) use ( $filter ) {
					return is_array( $s ) && ( $s['collection'] ?? '' ) === $filter;
				}
			)
		);
	$sel_warnings = array();
	$names        = array();
	foreach ( $scoped as $s ) {
		oxygen_mcp_lint_selector( $s, $sel_warnings );
		if ( isset( $s['name'] ) ) {
			$names[ (string) $s['name'] ] = true;
		}
		if ( isset( $s['id'] ) ) {
			$names[ (string) $s['id'] ] = true;
		}
	}
	$warnings        = array_merge( $warnings, $sel_warnings );
	$report['selectors'] = array(
		'collection'   => $filter,
		'analysed'     => count( $scoped ),
		'total'        => count( $all ),
		'warning_count' => count( $sel_warnings ),
	);

	// --- Étage 2 : CSS compilé ---
	$css_dir  = trailingslashit( WP_CONTENT_DIR ) . 'uploads/oxygen/css/';
	$sel_css  = $css_dir . 'oxy-selectors.css';
	$var_css  = $css_dir . 'variables.css';
	$sel_body = is_readable( $sel_css ) ? (string) file_get_contents( $sel_css ) : '';
	$var_body = is_readable( $var_css ) ? (string) file_get_contents( $var_css ) : '';

	$unresolved = array();
	if ( '' !== $sel_body && preg_match_all( '/\{var-[^}]+\}/', $sel_body, $m ) ) {
		$unresolved = array_values( array_unique( $m[0] ) );
	}
	foreach ( $unresolved as $tok ) {
		$warnings[] = "CSS compilé : token non résolu $tok (variable manquante ?).";
	}
	if ( '' === $sel_body ) {
		$warnings[] = 'CSS compilé : oxy-selectors.css introuvable/vide (cache non régénéré ?).';
	}
	$report['compiled'] = array(
		'selectors_css_bytes' => strlen( $sel_body ),
		'variables_css_bytes' => strlen( $var_body ),
		'unresolved_tokens'   => $unresolved,
	);

	// --- Étage 3 : arbre de page (optionnel) ---
	if ( $post_id > 0 ) {
		$page = array( 'post_id' => $post_id );
		if ( ! get_post( $post_id ) ) {
			$warnings[]   = "page : post $post_id introuvable.";
			$page['error'] = 'introuvable';
		} elseif ( function_exists( 'Breakdance\\Data\\get_tree' ) ) {
			$tree = \Breakdance\Data\get_tree( $post_id );
			$page['has_tree'] = false !== $tree;
			if ( false !== $tree && is_array( $tree ) && isset( $tree['root'] ) ) {
				$count   = 0;
				$classes = array();
				oxygen_mcp_walk_tree( $tree['root'], $count, $classes );
				$page['node_count'] = $count;

				$json    = (string) wp_json_encode( $tree );
				$missing = substr_count( $json, '"missing"' );
				$page['missing_count'] = $missing;
				if ( $missing > 0 ) {
					$warnings[] = "page : $missing nœud(s) \"missing\" dans l’arbre (élément non résolu).";
				}

				// Classes référencées par l'arbre mais absentes des sélecteurs.
				// On ne contrôle que dans le périmètre de la collection filtrée
				// (sinon faux positifs sur les classes d'autres collections).
				if ( null !== $filter && ! empty( $names ) ) {
					$prefix  = '';
					foreach ( array_keys( $names ) as $n ) {
						// préfixe = portion avant le 1er tiret (ex. cb-…).
						if ( false !== strpos( $n, '-' ) ) {
							$prefix = substr( $n, 0, strpos( $n, '-' ) + 1 );
							break;
						}
					}
					$dangling = array();
					foreach ( array_keys( $classes ) as $cl ) {
						if ( '' !== $prefix && 0 !== strpos( $cl, $prefix ) ) {
							continue; // classe d'une autre collection → ignorer
						}
						if ( ! isset( $names[ $cl ] ) ) {
							$dangling[] = $cl;
						}
					}
					$page['dangling_classes'] = $dangling;
					foreach ( $dangling as $cl ) {
						$warnings[] = "page : classe '$cl' référencée dans l’arbre mais sans sélecteur défini.";
					}
				}
			}
		} else {
			$warnings[]   = 'page : API Oxygen indisponible (get_tree).';
			$page['error'] = 'api_indisponible';
		}

		$css = (string) get_post_meta( $post_id, '_oxymcp_custom_css', true );
		$page['custom_css_bytes'] = strlen( $css );
		if ( strlen( $css ) > 0 ) {
			$warnings[] = 'page : custom_css de page présent (' . strlen( $css ) . ' o) — RÈGLE #1 : 0 custom_css.';
		}
		$report['page'] = $page;
	}

	return array(
		'ok'            => true,
		'warning_count' => count( $warnings ),
		'warnings'      => $warnings,
		'report'        => $report,
	);
}

/**
 * Valeurs px par défaut des tokens (échelle moderne sobre, surchargeable).
 * L'ORDRE compte (préservé par PHP) → l'affichage du panneau suit cet ordre.
 * Pattern agp : aucun px en dur dans un sélecteur, tout pointe un de ces tokens.
 */
function oxygen_mcp_default_tokens() {
	return array(
		'container-width'  => 1200,
		'measure'          => 680,
		'padding-y'        => 96,
		'padding-x'        => 32,
		'padding-y-mobile' => 56,
		'padding-x-mobile' => 20,
		'space-1'          => 6,
		'space-2'          => 10,
		'space-3'          => 14,
		'space-4'          => 18,
		'space-5'          => 24,
		'space-6'          => 32,
		'space-7'          => 48,
		'space-8'          => 64,
		'radius-1'         => 8,
		'radius-2'         => 12,
		'radius-3'         => 16,
		'size-1'           => 52,
		'size-2'           => 40,
		'size-3'           => 30,
		'size-4'           => 24,
		'size-5'           => 20,
		'size-6'           => 17,
		'default-size'     => 16,
	);
}

/**
 * Construit (variables unit, sélecteurs fondation) du preset. Ids déterministes
 * lisibles : `tok-<name>` (variable) et `fnd-<name>` (sélecteur). Une propriété
 * pointe une variable via le token `{var-tok-<name>}` (résolu en var(--<name>)).
 * Réplique tokens.py (pattern agp). `$overrides` = map name→px.
 */
function oxygen_mcp_build_preset( $overrides ) {
	$values = array_merge( oxygen_mcp_default_tokens(), $overrides );

	$variables = array();
	foreach ( $values as $name => $px ) {
		$px          = (int) $px;
		$variables[] = array(
			'id'              => 'tok-' . $name,
			'type'            => 'unit',
			'label'           => ucwords( str_replace( '-', ' ', $name ) ),
			'cssVariableName' => $name,
			'collection'      => 'Tokens',
			'value'           => array( 'number' => $px, 'unit' => 'px', 'style' => "{$px}px" ),
		);
	}

	// Référence variable (token Oxygen) à partir du nom.
	$ref = function ( $name ) {
		$tok = '{var-tok-' . $name . '}';
		return array( 'number' => $tok, 'unit' => 'custom', 'style' => $tok );
	};
	$auto = array( 'number' => null, 'unit' => 'auto', 'style' => 'auto' );
	// Bloc padding (spacing DOUBLÉ : spacing.spacing.padding — cf. contrat §4).
	$pad = function ( $left, $right, $top = null, $bottom = null ) {
		$p = array( 'left' => $left, 'right' => $right );
		if ( null !== $top ) {
			$p['top'] = $top;
		}
		if ( null !== $bottom ) {
			$p['bottom'] = $bottom;
		}
		return array( 'spacing' => array( 'spacing' => array( 'padding' => $p ) ) );
	};
	$sel = function ( $name, $base, $responsive = null ) {
		$props = array( 'breakpoint_base' => $base );
		if ( is_array( $responsive ) ) {
			foreach ( $responsive as $bp => $p ) {
				$props[ $bp ] = $p;
			}
		}
		return array(
			'id'         => 'fnd-' . $name,
			'name'       => $name,
			'type'       => 'class',
			'collection' => 'Foundation',
			'locked'     => false,
			'children'   => array(),
			'properties' => $props,
		);
	};

	$full_width = array( 'number' => 100, 'unit' => '%', 'style' => '100%' );

	$selectors = array(
		// Largeur max + centrage + padding horizontal (mobile-aware).
		$sel(
			'container',
			array(
				'size'    => array( 'max_width' => $ref( 'container-width' ), 'width' => $full_width ),
				'spacing' => array(
					'spacing' => array(
						'margin'  => array( 'left' => $auto, 'right' => $auto ),
						'padding' => array( 'left' => $ref( 'padding-x' ), 'right' => $ref( 'padding-x' ) ),
					),
				),
			),
			array( 'breakpoint_phone_landscape' => $pad( $ref( 'padding-x-mobile' ), $ref( 'padding-x-mobile' ) ) )
		),
		// Rythme vertical de section (desktop → mobile).
		$sel(
			'section',
			$pad( $ref( 'padding-x' ), $ref( 'padding-x' ), $ref( 'padding-y' ), $ref( 'padding-y' ) ),
			array(
				'breakpoint_phone_landscape' => $pad(
					$ref( 'padding-x-mobile' ),
					$ref( 'padding-x-mobile' ),
					$ref( 'padding-y-mobile' ),
					$ref( 'padding-y-mobile' )
				),
			)
		),
		// Conteneur de contenu neutre (largeur max sans padding).
		$sel(
			'wrapper',
			array(
				'size'    => array( 'max_width' => $ref( 'container-width' ), 'width' => $full_width ),
				'spacing' => array( 'spacing' => array( 'margin' => array( 'left' => $auto, 'right' => $auto ) ) ),
			)
		),
	);

	// heading-1..6 : taille = size-N en base, descend d'un cran en tablette.
	$scale = array( 'size-1', 'size-2', 'size-3', 'size-4', 'size-5', 'size-6', 'default-size' );
	for ( $i = 1; $i <= 6; $i++ ) {
		$next        = $scale[ min( $i, count( $scale ) - 1 ) ];
		$selectors[] = $sel(
			"heading-$i",
			array(
				'typography' => array(
					'font_size'   => $ref( "size-$i" ),
					'font_weight' => $i <= 3 ? 700 : 600,
					'line_height' => array( 'style' => $i <= 3 ? '1.15' : '1.4' ),
				),
			),
			array( 'breakpoint_tablet_portrait' => array( 'typography' => array( 'font_size' => $ref( $next ) ) ) )
		);
	}

	return array( 'variables' => $variables, 'selectors' => $selectors );
}

/** Callback apply-responsive-preset : génère le preset puis écrit (merge-safe). */
function oxygen_mcp_apply_responsive_preset_exec( $input ) {
	$overrides = ( isset( $input['overrides'] ) && is_array( $input['overrides'] ) )
		? $input['overrides']
		: array();

	$preset = oxygen_mcp_build_preset( $overrides );

	// ORDRE CRITIQUE : sélecteurs AVANT variables. Chaque save*() régénère le
	// CSS global, qui lit \Breakdance\…\getOxySelectors() — mémoïsée par un
	// `static $selectors` (gelé pour toute la requête PHP au 1er appel). Faire
	// les variables d'abord gèlerait la liste de sélecteurs à son état pré-preset
	// → les 9 sélecteurs foundation n'entreraient jamais dans oxy-selectors.css.
	// getVariables(), elle, relit à chaque appel (pas de static) : la 2e régén
	// (déclenchée par saveVariables) recompile donc les 13 sélecteurs avec les 27
	// variables enfin présentes → les refs {var-*} se résolvent. Cf. JS : un
	// module-level cache vs une lecture fraîche à chaque call.
	$sr = oxygen_mcp_set_selectors_exec( array( 'selectors' => $preset['selectors'] ) );
	if ( empty( $sr['ok'] ) ) {
		return $sr;
	}
	$vr = oxygen_mcp_set_variables_exec( array( 'variables' => $preset['variables'] ) );
	if ( empty( $vr['ok'] ) ) {
		return $vr;
	}

	return array(
		'ok'              => true,
		'variables'       => count( $preset['variables'] ),
		'selectors'       => count( $preset['selectors'] ),
		'variables_total' => isset( $vr['total'] ) ? $vr['total'] : null,
		'selectors_total' => isset( $sr['total'] ) ? $sr['total'] : null,
	);
}

/**
 * 2) Exposition des abilities comme serveur MCP.
 *
 * Hook `mcp_adapter_init` : le MCP Adapter (core 7.0) est prêt. On déclare un
 * serveur MCP joignable sur /wp-json/oxygen-mcp/mcp, listant les abilities à
 * exposer. C'est le core qui gère le protocole : on n'écrit pas le transport.
 */
add_action( 'mcp_adapter_init', 'oxygen_mcp_create_server' );
function oxygen_mcp_create_server( $adapter ) {
	$adapter = \WP\MCP\Core\McpAdapter::instance();
	$adapter->create_server(
		'oxygen-mcp',                     // identifiant unique du serveur
		'oxygen-mcp',                     // namespace REST
		'mcp',                            // route REST → /wp-json/oxygen-mcp/mcp
		'Oxygen MCP',                     // nom lisible
		'Pilotage Oxygen 6 via abilities', // description
		'v0.6.4',                         // version
		array(                            // transports
			\WP\MCP\Transport\HttpTransport::class,
		),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		array(                            // abilities exposées comme tools MCP
			'oxygen-mcp/ping',
			'oxygen-mcp/get-page-tree',
			'oxygen-mcp/set-page-tree',
			'oxygen-mcp/set-variables',
			'oxygen-mcp/set-selectors',
			'oxygen-mcp/apply-responsive-preset',
			'oxygen-mcp/create-doc',
			'oxygen-mcp/set-front-page',
			'oxygen-mcp/set-seo',
			'oxygen-mcp/sideload-image',
			'oxygen-mcp/set-page-css',
			'oxygen-mcp/get-selectors',
			'oxygen-mcp/get-variables',
			'oxygen-mcp/verify-page',
		),
		array(),
		array(),
	);
}
