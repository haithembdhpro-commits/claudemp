#!/bin/bash
# Installe l'outillage qualité au démarrage d'une session cloud.
# Idempotent, non interactif, ne fait jamais échouer la session.
set -uo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}" || exit 0
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

# Outils PHP (PHPCS + WordPress Coding Standards). Les paquets viennent de GitHub :
# si le réseau les refuse, on continue — le hook de lint retombe sur `php -l`.
if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
  if [ ! -x vendor/bin/phpcs ]; then
    timeout 300 composer install --no-progress >/tmp/session-start-composer.log 2>&1 \
      || echo "session-start: composer install a échoué (voir /tmp/session-start-composer.log) — lint limité à php -l" >&2
  fi
fi

# Plateforme e-learning (Next.js) quand elle existe.
if [ -f elearning/package.json ]; then
  (cd elearning && timeout 300 npm install --no-audit --no-fund >/tmp/session-start-npm.log 2>&1) \
    || echo "session-start: npm install a échoué (voir /tmp/session-start-npm.log)" >&2
fi

# Préchauffe le serveur MCP Playwright pour un premier démarrage rapide.
timeout 120 npx -y @playwright/mcp@0.0.83 --help >/dev/null 2>&1 || true

exit 0
