#!/usr/bin/env bash
# release.sh — verdict-console release script (derived from Verdict's; adds first-release support)
# Usage: bash release.sh [patch|minor|major]
# Dependencies: git, php
#
# A repository with no tag yet releases the version already in VERSION, with no bump — the org
# script's semantics. Verdict's variant required a previous tag because its changelog preparer
# extended an existing link footer; the preparer here creates that footer on a first release.

set -e

# --- helpers ---

die()     { printf 'error: %s\n' "$1" >&2; exit 1; }
confirm() { printf '%s [y/N] ' "$1"; read -r ans; [[ "$ans" =~ ^[Yy]$ ]]; }

# In-place edit that leaves no backup on either sed. GNU sed treats `-i''` as "no backup" and
# `-e` as the script flag; BSD sed (macOS) takes `-e` as the backup *suffix* and silently leaves
# a `<file>-e` beside the original. That artifact is untracked and not ignored, so a later
# `git add -A` would commit it. Writing through a temp file avoids the difference entirely.
replace_in_file() {
    local file=$1 script=$2 tmp
    tmp=$(mktemp) || die "could not create a temporary file"
    sed "$script" "$file" > "$tmp" && mv "$tmp" "$file" || { rm -f "$tmp"; die "could not rewrite $file"; }
}

# --- preflight checks ---

[[ -f VERSION ]] || die "VERSION file not found — are you in a fissible repo root?"
[[ -f scripts/prepare-release-changelog.php ]] \
    || die "scripts/prepare-release-changelog.php not found"
command -v php >/dev/null 2>&1 || die "php not found"
command -v git >/dev/null 2>&1 || die "git not found"

current_branch=$(git rev-parse --abbrev-ref HEAD)
[[ "$current_branch" == "main" ]] \
    || die "Releases must be cut from main (currently on: $current_branch)"

git diff --quiet && git diff --cached --quiet \
    || die "Working tree is dirty — commit or stash changes first"

# Releasing from a stale main produces a wrong commit list and a wrong-or-empty Unreleased section
# (v0.9.1 was first attempted from a main three commits behind origin). Verify before doing anything;
# offer the fast-forward rather than pulling silently, and refuse a divergent main outright.
git fetch origin main --quiet || die "could not fetch origin/main to verify main is current"
behind=$(git rev-list --count HEAD..origin/main)
if (( behind > 0 )); then
    if confirm "main is $behind commit(s) behind origin/main. Fast-forward now?"; then
        git merge --ff-only origin/main \
            || die "fast-forward failed — main has diverged from origin/main; reconcile it first"
    else
        die "main is behind origin/main — pull first"
    fi
fi

# --- state ---

current=$(cat VERSION)
last_tag=$(git describe --tags --abbrev=0 2>/dev/null || printf '')

printf 'Current version : %s\n' "$current"
printf 'Last tag        : %s\n' "${last_tag:-none}"
printf '\n'

# --- show commits since last tag ---

if [[ -n "$last_tag" ]]; then
    log_args=("${last_tag}..HEAD" "--oneline" "--no-merges")
    label="Commits since ${last_tag}"
else
    log_args=("--oneline" "--no-merges")
    label="All commits (no previous tag)"
fi

commits=()
while IFS= read -r line; do
    commits+=("$line")
done < <(git log "${log_args[@]}")

count=${#commits[@]}

if [[ $count -eq 0 ]]; then
    printf '%s:\n' "$label"
    printf '  (no commits since last tag)\n'
else
    printf '%s (%d total):\n' "$label" "$count"
    shown=$(( count < 10 ? count : 10 ))
    for (( i=0; i<shown; i++ )); do
        printf '  %s\n' "${commits[$i]}"
    done
    if [[ $count -gt 10 ]]; then
        printf '  ... (%d more)\n' $(( count - 10 ))
        printf '\n'
        confirm "View all?" && git log "${log_args[@]}" | less
    fi
fi
printf '\n'

# --- first release: an untagged VERSION is itself unreleased ---

first_release=0
if [[ -z "$last_tag" ]]; then
    printf 'No previous tag exists, so %s in VERSION is itself unreleased.\n' "$current"
    if confirm "Release ${current} as the first release (no bump)?"; then
        first_release=1
    fi
    printf '\n'
fi

# --- determine bump type ---

if [[ $first_release -eq 1 ]]; then
    bump="none"
elif [[ -n "$1" ]]; then
    bump="$1"
else
    # Suggest bump from conventional commit types
    recent=$(git log "${last_tag:+${last_tag}..}HEAD" --oneline --no-merges 2>/dev/null || git log --oneline --no-merges)
    has_breaking=0; has_feat=0
    while IFS= read -r line; do
        case "$line" in *'!:'*|*'BREAKING CHANGE'*) has_breaking=1 ;; esac
        case "$line" in *' feat'*|*'	feat'*) has_feat=1 ;; esac
    done <<EOF
$recent
EOF
    if [[ $has_breaking -eq 1 ]]; then suggested="major"
    elif [[ $has_feat -eq 1 ]];    then suggested="minor"
    else                                suggested="patch"
    fi
    printf 'Suggested bump: %s\n' "$suggested"
    printf 'Bump type [patch/minor/major, default: %s]: ' "$suggested"
    read -r bump
    bump="${bump:-$suggested}"
fi

case "$bump" in
    none|patch|minor|major) ;;
    *) die "Unknown bump type: '$bump' — use patch, minor, or major" ;;
esac

# --- calculate new version ---

IFS='.' read -r major minor patch <<< "$current"
case "$bump" in
    none)  ;;
    major) major=$((major + 1)); minor=0; patch=0 ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    patch) patch=$((patch + 1)) ;;
esac

new_version="${major}.${minor}.${patch}"
new_tag="v${new_version}"

# --- remaining guards: nothing past the Proceed prompt may refuse ---
#
# Every check that can die runs before the changelog preparer touches a file. A refusal after
# mutation leaves a dirty tree, and the retry then dies on "Working tree is dirty" with no hint
# that the script itself dirtied it — verdict-console-filament's first release hit exactly that.
#
# Composer's caret pins the leftmost non-zero component, so on a 0.x line ^0.5
# means >=0.5.0 <0.6.0. A minor bump therefore leaves a README that still says
# ^0.4 documenting a range that excludes the release it ships with — readers
# following it install the previous minor and silently miss every fix in this
# one. Derive the constraint here rather than hand-editing it each release.

package=""
constraint=""
if [[ -f README.md && -f composer.json ]]; then
    package=$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["name"] ?? "";')
    [[ -n "$package" ]] || die "composer.json has no package name — cannot update the README constraint"

    if (( major == 0 )); then
        constraint="^0.${minor}"      # pre-1.0: the minor is the compatibility boundary
    else
        constraint="^${major}.0"      # post-1.0: the major is
    fi

    # A silent no-op here would reintroduce exactly the drift this guards against,
    # so a README that no longer carries the line stops the release.
    grep -q "composer require ${package}:" README.md \
        || die "no 'composer require ${package}:' line in README.md — refusing to release with an unverifiable install constraint"
fi

printf '\nNew version: %s → %s\n\n' "$current" "$new_version"
confirm "Proceed?" || { printf 'Aborted.\n'; exit 0; }

# --- update files ---

# The repository URL is only consumed on a first release, to create the changelog's link footer.
# Prefer composer.json's homepage; fall back to the origin remote, normalised to a browsable URL.
repo_url=$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["homepage"] ?? "";' 2>/dev/null || printf '')
if [[ -z "$repo_url" ]]; then
    repo_url=$(git remote get-url origin 2>/dev/null \
        | sed -E 's#^git@([^:]+):#https://\1/#; s#^ssh://git@#https://#; s#\.git$##' || printf '')
fi

# UTC, not local time. The annotated tag and the GitHub Release are both stamped in UTC, so a local
# date makes the changelog disagree with them for anyone west of Greenwich cutting a release in the
# evening -- and makes the same commit produce different changelog dates depending on who ran it.
php scripts/prepare-release-changelog.php \
    CHANGELOG.md "$new_version" "$last_tag" "$new_tag" "$(date -u +%F)" "$repo_url"
printf '%s\n' "$new_version" > VERSION

# --- update package.json if present ---

if [[ -f package.json ]]; then
    replace_in_file package.json "s/\"version\": \"[^\"]*\"/\"version\": \"${new_version}\"/"
fi

# --- update the documented install constraint ---

if [[ -n "$package" ]]; then
    replace_in_file README.md "s|composer require ${package}:[^[:space:]]*|composer require ${package}:${constraint}|g"
    printf 'README install constraint: %s\n' "$constraint"
fi

# --- commit and tag ---

git add VERSION CHANGELOG.md
[[ -f package.json ]] && git add package.json
[[ -f README.md ]] && git add README.md
git commit -m "chore: release ${new_tag}"
git tag -a "$new_tag" -m "$new_tag"

printf '\nTagged %s locally.\n' "$new_tag"

# --- push ---

if confirm "Push to origin?"; then
    git push && git push --tags
    printf '\nReleased %s\n' "$new_tag"
else
    printf 'Skipped push. When ready:\n  git push && git push --tags\n'
fi
