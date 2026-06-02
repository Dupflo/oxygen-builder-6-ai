# Oxygen Builder 6 AI

WordPress plugin that exposes **Oxygen 6** as an **MCP** (Model Context
Protocol) server, through the WordPress 7.0 **Abilities API** + the **MCP
Adapter**. An AI agent (Claude Desktop, Claude Code, or any MCP client) can then
read and write the Oxygen page tree, selectors (classes), variables, and lint a
page.

- **Version:** 0.6.0
- **Requires:** WordPress 7.0+, PHP 8.1+, Oxygen 6
- **MCP endpoint:** `/wp-json/oxygen-mcp/mcp`

## Two ways to use it

After activating the plugin, open the **Oxygen 6 AI** admin page (left menu). It
shows your site's MCP endpoint and walks you through both options below.

### Option A — Connect your own MCP client (free)

Point **Claude Desktop** or **Claude Code** (or any MCP client) at the endpoint.
The AI runtime is yours, so there is no extra cost. Example with the
`mcp-remote` bridge:

```
npx -y mcp-remote https://YOUR-SITE/wp-json/oxygen-mcp/mcp \
  --header "Authorization: Basic BASE64(user:application_password)"
```

### Option B — Use the hosted chat (bring your own API key)

Use the hosted chat service and provide **your own Anthropic API key** (BYOK).
The key is used only for the duration of the request — never stored, never
logged. You provide your key, this site's MCP endpoint, and a WordPress
Application Password.

## Exposed abilities

**Read**
- `get-page-tree` — the node tree of a page
- `get-selectors` — the classes (selectors), filterable by collection
- `get-variables` — the variables (colors, etc.)
- `verify-page` — **the linter**: detects the spacing bug (silent drop), empty
  groups, unknown keys, unwanted custom_css, unresolved `{var-…}` tokens,
  "missing" nodes, and orphan classes

**Write**
- `set-page-tree`, `set-selectors`, `set-variables`
- `set-page-css`, `set-seo`, `set-front-page`
- `sideload-image` — imports an image into the media library
- `apply-responsive-preset`, `create-doc`, `ping`

## Installation

1. Copy the plugin folder into `wp-content/plugins/oxygen-mcp-abilities/`.
2. Install the MCP Adapter dependency (not bundled, see `.gitignore`):
   ```bash
   composer require wordpress/mcp-adapter
   ```
   (The plugin stays inert on the MCP side until `vendor/autoload.php` exists —
   it does not fatal.)
3. Activate the plugin in WordPress.
4. Open the **Oxygen 6 AI** admin page for setup instructions. The MCP server is
   exposed at `/wp-json/oxygen-mcp/mcp` (authenticated with a WordPress
   Application Password).

## Authentication

Create a WordPress **Application Password** (Users → Profile → Application
Passwords) — never your login password. The MCP endpoint authenticates with
HTTP Basic auth: `base64("user:application_password")`.

## License

To be defined.
