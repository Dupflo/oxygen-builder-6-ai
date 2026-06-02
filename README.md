# Oxygen MCP Abilities

Plugin WordPress qui expose **Oxygen 6** comme un serveur **MCP** (Model Context
Protocol), via l'**Abilities API** de WordPress 7.0 + le **MCP Adapter**. Un
agent (Claude Desktop, Claude Code, ou tout client MCP) peut alors lire et écrire
l'arbre des pages Oxygen, les sélecteurs (classes), les variables, et linter une
page.

- **Version :** 0.5.0
- **Requiert :** WordPress 7.0+, PHP 8.1+, Oxygen 6
- **Endpoint MCP :** `/wp-json/oxygen-mcp/mcp`

## Abilities exposées

**Lecture**
- `get-page-tree` — l'arbre des nœuds d'une page
- `get-selectors` — les classes (sélecteurs), filtrables par collection
- `get-variables` — les variables (couleurs, etc.)
- `verify-page` — **le lint** : détecte le bug de spacing (drop silencieux),
  groupes vides, clés inconnues, custom_css indésirable, tokens `{var-…}` non
  résolus, nœuds « missing » et classes orphelines

**Écriture**
- `set-page-tree`, `set-selectors`, `set-variables`
- `set-page-css`, `set-seo`, `set-front-page`
- `sideload-image` — importe une image dans la médiathèque
- `apply-responsive-preset`, `create-doc`, `ping`

## Installation

1. Copier le dossier du plugin dans `wp-content/plugins/oxygen-mcp-abilities/`.
2. Installer la dépendance MCP Adapter (non incluse, voir `.gitignore`) :
   ```bash
   composer require wordpress/mcp-adapter
   ```
   (Le plugin reste inerte côté MCP tant que `vendor/autoload.php` n'existe pas,
   il ne fatalise pas.)
3. Activer le plugin dans WordPress.
4. Le serveur MCP est exposé sur `/wp-json/oxygen-mcp/mcp` (auth par Application
   Password WordPress).

## Connexion d'un client MCP

Exemple avec un pont `mcp-remote` :

```
npx -y mcp-remote https://VOTRE-SITE/wp-json/oxygen-mcp/mcp \
  --header "Authorization: Basic BASE64(user:application_password)"
```

## Licence

À définir.
