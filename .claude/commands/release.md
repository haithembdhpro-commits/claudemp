---
description: Construit weconvert-suite.zip prêt à téléverser dans WordPress
---
1. Vérifie la syntaxe de tous les fichiers de `weconvert-suite/` avec `php -l`. Arrête-toi s'il y a une erreur.
2. Vérifie que `Version:` dans `weconvert-suite/weconvert-suite.php` et la constante `WECONVERT_SUITE_VERSION` sont identiques.
3. Construis le zip à la racine : `rm -f weconvert-suite.zip && zip -r weconvert-suite.zip weconvert-suite -x '*.DS_Store'`.
4. Donne-moi la version, la taille du zip et la liste des fichiers inclus.
