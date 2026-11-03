#!/usr/bin/env bash
set -euo pipefail

# Installed as a root-owned SSH forced command; the CI key cannot open a shell.
if [[ $# -ne 1 || ! $1 =~ ^deploy\ [0-9a-f]{40}$ ]]; then
    echo "Only deploy <commit-sha> is allowed" >&2
    exit 1
fi
revision=${1#deploy }

exec 9>/var/lock/escriptorium-staging-deploy.lock
flock -n 9 || { echo "A staging deployment is already running" >&2; exit 1; }
cd /docker-data/configuration/escriptorium
git fetch origin stage
if [[ $(git rev-parse origin/stage) != "$revision" ]]; then
    echo "Refusing to deploy an outdated or unapproved stage revision" >&2
    exit 1
fi
if [[ $(git branch --show-current) != stage ]] || ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Staging checkout must be clean and on the stage branch" >&2
    exit 1
fi
git merge --ff-only "$revision"
exec bash ci/deploy-staging.sh "$revision"
