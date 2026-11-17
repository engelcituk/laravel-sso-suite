#!/usr/bin/env bash
set -euo pipefail

# Only this generated branch is pushed. No tags, releases, Pages or package registry.
task_root="$(git rev-parse --show-toplevel)"
cd "$task_root"
task_source="${1:?Supply the full source commit}"
[[ "$task_source" =~ ^[a-f0-9]{40}$ ]] || { echo 'Full source commit required' >&2; exit 1; }
task_repository="${GITHUB_REPOSITORY:-engelcituk/laravel-sso-suite}"
[[ "$task_repository" =~ ^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$ ]] || exit 1
task_run="$task_root/.runtime/distribution-publish/$task_source-$(date +%s)-$$"
mkdir -p "$task_run/build"
task_checkout="$task_run/checkout"
git fetch origin main
if [[ "$(git rev-parse refs/remotes/origin/main)" != "$task_source" ]]; then
    echo 'Skipped: a newer main commit exists; its quality run owns the next distribution.'
    exit 0
fi
php tools/build-distribution.php build --source="$task_source" --output="$task_run/build" --repository="$task_repository"
task_remote="$(git ls-remote --heads origin refs/heads/composer)"
if [[ -n "$task_remote" ]]; then
    git fetch origin refs/heads/composer:refs/remotes/origin/composer
    git -c core.autocrlf=false worktree add --detach "$task_checkout" refs/remotes/origin/composer
else
    git -c core.autocrlf=false worktree add --detach "$task_checkout" "$task_source"
    git -C "$task_checkout" switch --orphan composer
fi
if [[ -e "$task_checkout/dist/$task_source" ]]; then
    diff -qr "$task_run/build/dist/$task_source" "$task_checkout/dist/$task_source"
else
    mkdir -p "$task_checkout/dist"
    cp -R "$task_run/build/dist/$task_source" "$task_checkout/dist/"
fi
task_commit() {
    git -C "$task_checkout" -c user.name='github-actions[bot]' -c user.email='41898282+github-actions[bot]@users.noreply.github.com' commit -m "$1"
}
git -C "$task_checkout" add "dist/$task_source"
if ! git -C "$task_checkout" diff --cached --quiet; then
    task_commit "build: package SSO source $task_source"
fi
task_archives_ref="$(git -C "$task_checkout" log -1 --format=%H -- "dist/$task_source")"
php tools/build-distribution.php index --source="$task_source" --output="$task_checkout" --archives-ref="$task_archives_ref" --repository="$task_repository"
git -C "$task_checkout" add packages.json README.md .gitattributes
if ! git -C "$task_checkout" diff --cached --quiet; then
    task_commit "build: index SSO source $task_source"
fi
git fetch origin main
if [[ "$(git rev-parse refs/remotes/origin/main)" != "$task_source" ]]; then
    echo 'Skipped publication: main advanced during packaging.'
    exit 0
fi
git -C "$task_checkout" push origin HEAD:refs/heads/composer
echo "Composer repository: https://raw.githubusercontent.com/$task_repository/composer"
