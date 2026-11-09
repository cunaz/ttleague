#!/usr/bin/env bash
# Prüft Dateien auf KI-Verläufe, Zugangsdaten, lokale Pfade und andere sensible Inhalte.
#
# Aufruf:
#   check-sensitive.sh --staged   vorgemerkte Dateien (Pre-Commit-Hook)
#   check-sensitive.sh --all      alle versionierten Dateien (CI)
#   check-sensitive.sh --message <datei>   Commit-Nachricht (Commit-Msg-Hook)
#
# Exit-Code 1 bei Funde. Ausnahmen pro Repository in SKIP_CONTENT (nur Inhaltsprüfung).

set -uo pipefail

MODE="${1:---staged}"
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT" || exit 2

# --- Repository-spezifisch -------------------------------------------------------
# Inhalte dieser Pfade werden nicht geprüft (Rohdaten, Lockfiles, diese Prüfung selbst).
SKIP_CONTENT='^(composer\.lock|package-lock\.json|LICENSE|bin/check-sensitive\.sh|\.gitignore)$'
# Erlaubte E-Mail-Domänen bzw. -Adressen.
ALLOWED_EMAIL='(users\.noreply\.github\.com|@example\.(org|com|net)|noreply@anthropic\.com)$'

# --- Verbotene Pfade -----------------------------------------------------------
FORBIDDEN_PATHS='(^|/)(\.claude|\.specstory|\.cursor|\.continue|\.codeium|\.windsurf|\.cline|\.roo|memory-bank|\.gemini|\.codex|\.junie|\.copilot|\.tabnine|scratchpad|\.scratch)(/|$)|(^|/)CLAUDE\.local\.md$|(^|/)\.aider|\.jsonl$|(^|/)\.env(\.[^/]*)?$|(^|/)(auth\.json|\.npmrc|\.netrc|\.htpasswd|wp-config\.php|wp-config-local\.php)$|\.(pem|key|p12|pfx|kdbx|sql|sqlite3?|db|dump|bak|bundle|log)$|(^|/)(id_rsa|id_ecdsa|id_ed25519)'
ALLOWED_PATHS='(^|/)\.env\.example$'

# --- Verbotene Inhalte (Perl-kompatible Ausdrücke) ----------------------------------
PATTERNS=(
	'-----BEGIN [A-Z ]*PRIVATE KEY-----|privater Schlüssel'
	'\bgh[pousr]_[A-Za-z0-9]{36,}|GitHub-Token'
	'\bgithub_pat_[A-Za-z0-9_]{40,}|GitHub-Token'
	'\bsk-ant-[A-Za-z0-9_-]{20,}|Anthropic-Schlüssel'
	'\bsk-(proj-)?[A-Za-z0-9]{32,}|OpenAI-Schlüssel'
	'\bAKIA[0-9A-Z]{16}\b|AWS-Schlüssel'
	'\bxox[baprs]-[A-Za-z0-9-]{10,}|Slack-Token'
	'\bAIza[0-9A-Za-z_-]{35}\b|Google-Schlüssel'
	'/tmp/claude-|lokaler Pfad einer KI-Sitzung'
	'/root/\.claude|lokaler Pfad einer KI-Sitzung'
	'\.claude/projects/|lokaler Pfad einer KI-Sitzung'
	'claude\.ai/(code/)?(session|chat)|Link auf eine KI-Sitzung'
	'chatgpt\.com/(c|share)/|Link auf eine KI-Sitzung'
	'"(role|type)"\s*:\s*"(assistant|human)"|Struktur eines KI-Verlaufs'
	'/home/[a-z][a-z0-9_-]*/|lokaler Benutzerpfad'
)

findings=()
add() { findings+=("$1"); }

check_message() {
	local file="$1"
	local text
	text="$(grep -v '^#' "$file")"
	for entry in "${PATTERNS[@]}"; do
		local regex="${entry%|*}" reason="${entry##*|}"
		if grep -qP -- "$regex" <<<"$text"; then
			add "Commit-Nachricht: $reason"
		fi
	done
}

check_file() {
	local path="$1" source="$2"
	if [[ "$path" =~ $FORBIDDEN_PATHS ]] && ! [[ "$path" =~ $ALLOWED_PATHS ]]; then
		add "$path: Dateiart darf nicht versioniert werden"
		return
	fi
	if [[ "$path" =~ $SKIP_CONTENT ]]; then
		return
	fi
	local content
	# Nullbytes entfernen: Auch Binärdateien (Bilder mit Metadaten) werden durchsucht,
	# ohne dass die Shell vor Nullbytes warnt.
	if [ "$source" = "index" ]; then
		content="$(git show ":$path" 2>/dev/null | LC_ALL=C tr -d '\000')" || return 0
	else
		[ -f "$path" ] || return 0
		content="$(LC_ALL=C tr -d '\000' < "$path")" || return 0
	fi
	[ -n "$content" ] || return 0
	for entry in "${PATTERNS[@]}"; do
		local regex="${entry%|*}" reason="${entry##*|}"
		if grep -qP -- "$regex" <<<"$content"; then
			add "$path: $reason"
		fi
	done
	while IFS= read -r email; do
		[ -z "$email" ] && continue
		if ! [[ "$email" =~ $ALLOWED_EMAIL ]]; then
			add "$path: E-Mail-Adresse $email"
		fi
	done < <(grep -oP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}' <<<"$content" | sort -u)
}

case "$MODE" in
	--message)
		check_message "${2:?Datei der Commit-Nachricht fehlt}"
		;;
	--staged)
		while IFS= read -r -d '' path; do
			check_file "$path" index
		done < <(git diff --cached --name-only --diff-filter=ACMR -z)
		;;
	--all)
		while IFS= read -r -d '' path; do
			check_file "$path" worktree
		done < <(git ls-files -z)
		;;
	*)
		echo "Aufruf: $0 --staged | --all | --message <datei>" >&2
		exit 2
		;;
esac

if [ "${#findings[@]}" -gt 0 ]; then
	echo "Sensible Inhalte gefunden, abgebrochen:" >&2
	printf '  - %s\n' "${findings[@]}" >&2
	echo "Wenn ein Fund harmlos ist: Inhalt anpassen oder die Ausnahme im Skript begründet ergänzen." >&2
	exit 1
fi
exit 0
