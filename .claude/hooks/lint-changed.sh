#!/bin/bash
# PostToolUse : vérifie le fichier que Claude vient de modifier.
# - Erreur bloquante (syntaxe, lint JS/TS) → sortie 2, le message est renvoyé à Claude.
# - Alertes sécurité PHPCS → ajoutées au contexte de Claude, sans bloquer
#   (le code existant contient déjà des alertes, on ne veut pas bloquer chaque édition).
set -uo pipefail

input=$(cat)
file=$(printf '%s' "$input" | python3 -c 'import sys,json; d=json.load(sys.stdin); print((d.get("tool_input") or {}).get("file_path",""))' 2>/dev/null)
[ -n "$file" ] && [ -f "$file" ] || exit 0

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
errors=""
context=""

case "$file" in
  *.php)
    out=$(php -l "$file" 2>&1) || errors+="$out"$'\n'
    if [ -z "$errors" ] && [ -x "$root/vendor/bin/phpcs" ]; then
      sniffs="WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.SafeRedirect,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders"
      out=$(cd "$root" && vendor/bin/phpcs -q --no-colors --report=emacs --sniffs="$sniffs" "$file" 2>&1 | head -40)
      [ -n "$out" ] && context="Alertes sécurité PHPCS dans $file (vérifie surtout les lignes que tu viens de modifier) :"$'\n'"$out"
    fi
    ;;
  *.ts|*.tsx|*.js|*.jsx|*.mjs)
    case "$file" in
      "$root"/elearning/*)
        if [ -x "$root/elearning/node_modules/.bin/eslint" ]; then
          out=$(cd "$root/elearning" && node_modules/.bin/eslint --no-warn-ignored "$file" 2>&1) || errors+="$out"$'\n'
        fi
        ;;
    esac
    ;;
  *.json)
    python3 -m json.tool "$file" >/dev/null 2>&1 || errors+="JSON invalide : $file"$'\n'
    ;;
esac

if [ -n "$errors" ]; then
  printf 'Erreurs dans %s :\n%s' "$file" "$errors" >&2
  exit 2
fi

if [ -n "$context" ]; then
  CTX="$context" python3 -c 'import json,os; print(json.dumps({"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":os.environ["CTX"]}}))'
fi
exit 0
