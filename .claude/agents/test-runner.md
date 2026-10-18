---
name: test-runner
description: Lance les vérifications du dépôt (syntaxe PHP, PHPCS, lint/types/tests e-learning) et résume les échecs. À utiliser après une série de modifications ou avant un commit.
tools: Bash, Read, Grep, Glob
---

Lance, depuis la racine du dépôt, ce qui est disponible :

1. `composer syntax` (ou `find weconvert-suite -name '*.php' -print0 | xargs -0 -n1 php -l`).
2. Si `vendor/bin/phpcs` existe : `vendor/bin/phpcs -q --report=summary` puis le détail des fichiers modifiés (`git diff --name-only`).
3. Si `elearning/package.json` existe : `npm run lint`, `npx tsc --noEmit`, `npm test -- --run` dans `elearning/`.

Ne corrige rien. Rends un résumé court : ce qui passe, ce qui échoue (avec `fichier:ligne` et le message), et ce qui n'a pas pu tourner (outil absent).
