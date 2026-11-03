#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! $1 =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected a full commit SHA" >&2
    exit 1
fi
revision=$1
cd "$(dirname "$0")/.."
if [[ $(git rev-parse HEAD) != "$revision" ]]; then
    echo "Checkout does not match the tested revision" >&2
    exit 1
fi
if [[ $(git branch --show-current) != stage ]]; then
    echo "Only the stage branch may be deployed" >&2
    exit 1
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Tracked files have local changes" >&2
    exit 1
fi

compose=(docker compose --project-name escriptorium --env-file .env.production -f docker-compose.production.yml)
"${compose[@]}" config --quiet
release="escriptorium-proxy-release:$revision"
docker build --pull --target production -f docker/Dockerfile.proxy -t "$release" .
docker run --rm --entrypoint sh "$release" -ec '
    composer check-platform-reqs --no-dev
    php -r '\''exit(PHP_VERSION_ID < 80427 || ini_get("session.upload_progress.enabled") !== "0" ? 1 : 0);'\''
    test -s public/build/manifest.json
    test ! -e .env
'

php_container=$("${compose[@]}" ps -q proxy-php)
queue_container=$("${compose[@]}" ps -q proxy-queue | head -n 1)
old_php=$(docker inspect --format '{{.Image}}' "$php_container")
old_queue=$(docker inspect --format '{{.Image}}' "$queue_container")
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup="${DEPLOY_BACKUP_ROOT:-/var/backups/escriptorium}/$stamp-${revision:0:12}"
mkdir -p "$backup"
chmod 700 "$backup"
docker tag "$old_php" "escriptorium-proxy-php:rollback-$stamp"
docker tag "$old_queue" "escriptorium-proxy-queue:rollback-$stamp"
printf '%s\n' "revision=$revision" "previous_php=$old_php" "previous_queue=$old_queue" > "$backup/release.txt"
docker run --rm --entrypoint sh \
    -v escriptorium_proxy-public:/public:ro -v "$backup:/backup" "$release" \
    -ec 'tar -czf /backup/public.tar.gz -C /public .'

rollback() {
    result=${1:-$?}
    trap - ERR INT TERM HUP
    set +e
    echo "Deployment failed; restoring previous images and public assets from $backup" >&2
    docker tag "$old_php" escriptorium-proxy-php:latest
    docker tag "$old_queue" escriptorium-proxy-queue:latest
    docker run --rm --entrypoint sh \
        -v escriptorium_proxy-public:/public -v "$backup:/backup:ro" "$release" \
        -ec 'tar -xzf /backup/public.tar.gz -C /public'
    "${compose[@]}" up -d --no-deps --no-build --wait --wait-timeout 180 proxy-php proxy-queue
    "${compose[@]}" restart nginx
    exit "$result"
}
trap rollback ERR
trap 'rollback 130' INT
trap 'rollback 143' TERM
trap 'rollback 129' HUP

# Keep previous hashed assets for clients that loaded the old manifest.
docker run --rm --entrypoint sh -v escriptorium_proxy-public:/published "$release" -ec '
    mkdir -p /published/build/assets
    cp -a public/build/assets/. /published/build/assets/
    cp public/build/manifest.json /published/build/manifest.json
    chown -R www-data:www-data /published/build
'
docker tag "$release" escriptorium-proxy-php:latest
docker tag "$release" escriptorium-proxy-queue:latest
"${compose[@]}" up -d --no-deps --no-build --wait --wait-timeout 180 proxy-php proxy-queue
"${compose[@]}" restart nginx

for container in $("${compose[@]}" ps -q proxy-php proxy-queue); do
    docker exec "$container" php -r 'echo "PHP ".PHP_VERSION.PHP_EOL; exit(PHP_VERSION_ID < 80427 || ini_get("session.upload_progress.enabled") !== "0" ? 1 : 0);'
    docker exec "$container" php artisan --version
done
php_container=$("${compose[@]}" ps -q proxy-php)
docker exec "$php_container" php-fpm-healthcheck
expected_manifest=$(docker run --rm --entrypoint sha256sum "$release" /var/www/html/public/build/manifest.json)
actual_manifest=$(docker exec "$php_container" sha256sum /var/www/html/public/build/manifest.json)
[[ "$actual_manifest" == "$expected_manifest" ]]
curl --fail --silent --show-error --retry 5 --retry-delay 3 --retry-all-errors --max-time 20 \
    -o /dev/null https://escriptorium.netseven.work/
trap - ERR INT TERM HUP
echo "Staging deployed successfully: $revision (backup: $backup)"
"${compose[@]}" ps proxy-php proxy-queue nginx
