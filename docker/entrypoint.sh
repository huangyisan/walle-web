#!/usr/bin/env bash
set -e

APP_DIR=/opt/walle-web
DEPLOY_ROOT=/data/walle-deploy

# www-data 作为 PHP-FPM / git / rsync 执行用户
install -d -m 700 -o www-data -g www-data /var/www/.ssh

# 挂载宿主机 SSH 目录 → 复制到 www-data 家目录（避免只读挂载权限不对）
if [ -d /mnt/ssh ] && [ -n "$(ls -A /mnt/ssh 2>/dev/null)" ]; then
    for f in /mnt/ssh/*; do
        [ -e "$f" ] || continue
        [ -f "$f" ] || continue
        base=$(basename "$f")
        case "$base" in
            *.pub|known_hosts|authorized_keys)
                install -m 644 -o www-data -g www-data "$f" "/var/www/.ssh/${base}"
                ;;
            README*|*.md)
                ;;
            *)
                install -m 600 -o www-data -g www-data "$f" "/var/www/.ssh/${base}"
                ;;
        esac
    done
    [ -f /var/www/.ssh/config ] && chmod 600 /var/www/.ssh/config
    echo "SSH keys installed for www-data under /var/www/.ssh"
else
    echo "WARN: /mnt/ssh is empty — git clone / 目标机 SSH 需在 docker/ssh 放置密钥，见 docker/ssh/README.md"
fi

install -d -m 775 -o www-data -g www-data "${DEPLOY_ROOT}" /tmp/walle "${APP_DIR}/runtime" "${APP_DIR}/web/assets"

# 本地配置：镜像内无 local.php 时按环境变量生成（也可在构建前 cp config/local.php.dist）
if [ ! -f "${APP_DIR}/config/local.php" ]; then
    WALLE_ENV_VAL="${WALLE_ENV:-prod}"
    cat > "${APP_DIR}/config/local.php" <<PHP
<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', '${WALLE_ENV_VAL}');

return [
    'language' => 'zh-CN',
    'components' => [
        'db' => [
            'dsn'      => getenv('WALLE_DB_DSN') ?: 'mysql:host=mysql;dbname=walle',
            'username' => getenv('WALLE_DB_USER') ?: 'root',
            'password' => getenv('WALLE_DB_PASS') ?: '',
        ],
        'request' => [
            'cookieValidationKey' => getenv('WALLE_COOKIE_KEY') ?: 'CHANGE_ME',
        ],
        'mail' => [
            'useFileTransport' => true,
        ],
    ],
];
PHP
    chown www-data:www-data "${APP_DIR}/config/local.php"
fi

# 等待 MySQL（compose healthcheck 后通常已就绪，此处双保险）
if [ -n "${WALLE_DB_DSN}" ]; then
    for i in $(seq 1 30); do
        if php -r "
            \$dsn = getenv('WALLE_DB_DSN');
            \$u = getenv('WALLE_DB_USER');
            \$p = getenv('WALLE_DB_PASS');
            new PDO(\$dsn, \$u, \$p, [PDO::ATTR_TIMEOUT => 2]);
        " 2>/dev/null; then
            break
        fi
        sleep 2
    done
fi

cd "${APP_DIR}"
php yii walle/setup --interactive=0 || true
php yii migrate/up --interactive=0 || true

exec "$@"
