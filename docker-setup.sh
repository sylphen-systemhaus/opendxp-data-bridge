#!/usr/bin/env bash
# Sylphen Data Bridge — OpenDXP Docker-Demo (Port 2000). Portiert von Blackbit/Pimcore docker-setup.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

PHP_CONTAINER="${PHP_CONTAINER:-opendxp-dd-php}"
BUNDLE_MOUNT="/opendxp-data-bridge"
APP_ROOT="/var/www/opendxp"

echo "==> Building and starting containers (port 2000)..."
docker compose build
docker compose up -d --wait

echo "==> Preparing app volume permissions..."
docker exec -t "$PHP_CONTAINER" bash -c "chown -R www-data:www-data $APP_ROOT || true"

echo "==> Installing OpenDXP skeleton + Sylphen Data Bridge..."
docker exec -i --user www-data -e COMPOSER_HOME=/tmp/composer-www-data "$PHP_CONTAINER" bash -s <<'INSTALL'
set -euo pipefail
APP_ROOT=/var/www/opendxp
BUNDLE_MOUNT=/opendxp-data-bridge
export COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer-www-data}"
mkdir -p "$COMPOSER_HOME"

cd "$APP_ROOT"
rm -rf ./* ./.[!.]* 2>/dev/null || true

composer create-project --no-scripts --prefer-dist --no-dev open-dxp/skeleton tmp "1.x@dev"
rm -rf .docker .github
mv tmp/.[!.]* . 2>/dev/null || true
mv tmp/* .
rm -rf tmp

cp "$BUNDLE_MOUNT/docker-composer.lock" ./composer.lock
cp "$BUNDLE_MOUNT/docker-composer.json" ./composer.json
ln -sfn "$BUNDLE_MOUNT" ./opendxp-data-bridge

composer install --prefer-dist --no-interaction

./vendor/bin/opendxp-install \
  --admin-username=admin \
  --admin-password=admin \
  --mysql-host-socket=db \
  --mysql-username=opendxp \
  --mysql-password=opendxp \
  --mysql-database=opendxp \
  --no-interaction

register_bundle() {
  local class="$1"
  if ! grep -qF "$class" config/bundles.php; then
    sed -i "s|^];|    $class => ['all' => true],\n];|" config/bundles.php
  fi
}

register_bundle 'OpenDxp\\Bundle\\CustomReportsBundle\\OpenDxpCustomReportsBundle::class'
register_bundle 'OpenDxp\\Bundle\\SimpleBackendSearchBundle\\OpenDxpSimpleBackendSearchBundle::class'
register_bundle 'OpenDxp\\Bundle\\SystemInfoBundle\\OpenDxpSystemInfoBundle::class'
register_bundle 'Sylphen\\DataBridgeBundle\\SylphenDataBridgeBundle::class'

bin/console opendxp:bundle:install OpenDxpApplicationLoggerBundle -n
bin/console opendxp:bundle:install OpenDxpCustomReportsBundle -n
bin/console opendxp:bundle:install OpenDxpSimpleBackendSearchBundle -n
bin/console opendxp:bundle:install OpenDxpSystemInfoBundle -n
bin/console opendxp:bundle:install SylphenDataBridgeBundle -n

bin/console assets:install --symlink --relative -n
chmod -R u+w config bin composer.json var

if ! grep -q '^OPENDXP_DEV_MODE=1' .env 2>/dev/null; then
  echo 'OPENDXP_DEV_MODE=1' >> .env
fi
INSTALL

echo "==> Configuring Xdebug output directory..."
docker exec -t "$PHP_CONTAINER" bash -c "grep -q 'xdebug.client_host' /usr/local/etc/php/conf.d/20-xdebug.ini 2>/dev/null || echo 'xdebug.client_host = host.docker.internal' >> /usr/local/etc/php/conf.d/20-xdebug.ini"
docker exec -t "$PHP_CONTAINER" bash -c "grep -q 'xdebug.output_dir' /usr/local/etc/php/conf.d/20-xdebug.ini 2>/dev/null || echo 'xdebug.output_dir=$BUNDLE_MOUNT' >> /usr/local/etc/php/conf.d/20-xdebug.ini"
docker exec -t "$PHP_CONTAINER" bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-dd-memlimit.ini && kill -USR2 1" 2>/dev/null || true

echo "==> Fixing var/ ownership (www-data)..."
docker exec -t "$PHP_CONTAINER" bash -c "chown -R www-data:www-data $APP_ROOT/var || true"

echo ""
echo "Finished."
echo "  Admin:  http://localhost:2000/admin"
echo "  Login:  admin / admin"
echo "  Bundle: symlinked from $BUNDLE_MOUNT"
echo ""
echo "Reset demo:  docker compose down -v && ./docker-setup.sh"
