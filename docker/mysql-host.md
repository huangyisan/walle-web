# Docker 容器连接宿主机 MySQL

compose 已去掉 MySQL 服务，默认通过 **`host.docker.internal`** 连宿主机（Linux 需 `extra_hosts: host-gateway`，已在 `docker-compose.yml` 配置）。

## 1. 建库

```sql
CREATE DATABASE walle DEFAULT CHARSET utf8 COLLATE utf8_general_ci;
```

## 2. 配置 `.env`

```bash
WALLE_DB_HOST=host.docker.internal
WALLE_DB_NAME=walle
WALLE_DB_USER=root
WALLE_DB_PASS=你的密码
```

若 `host.docker.internal` 不通，在 `.env` 里改为宿主机局域网 IP（`ip addr` 查看）。

## 3. 允许远程连接（容器算“远程”）

编辑 MySQL（如 `/etc/mysql/mysql.conf.d/mysqld.cnf`）：

```ini
bind-address = 0.0.0.0
```

```sql
CREATE USER 'walle'@'%' IDENTIFIED BY '你的密码';
GRANT ALL ON walle.* TO 'walle'@'%';
FLUSH PRIVILEGES;
```

或仅允许 Docker 网段（更安全）：

```sql
CREATE USER 'walle'@'172.%' IDENTIFIED BY '你的密码';
GRANT ALL ON walle.* TO 'walle'@'172.%';
FLUSH PRIVILEGES;
```

重启 MySQL：`sudo systemctl restart mysql`

## 4. 验证

```bash
docker compose exec walle php -r "
  new PDO(getenv('WALLE_DB_DSN'), getenv('WALLE_DB_USER'), getenv('WALLE_DB_PASS'));
  echo 'OK';
"
```
