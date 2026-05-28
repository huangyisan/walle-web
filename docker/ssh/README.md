# Docker 内 Git / SSH 密钥

Walle 在容器里用 **`www-data`** 执行 `git clone`、`ssh`、`rsync`（见 `components/Command.php`）。  
请把密钥放在本目录，由 `docker-compose` 挂载到容器。

## 1. 准备文件

```text
docker/ssh/
  id_ed25519          # 或 id_rsa，Git 只读 Deploy Key 私钥
  id_ed25519.pub
  known_hosts         # 建议有，避免首次连接交互
  config              # 可选
```

生成 `known_hosts`（示例 GitHub / GitLab）：

```bash
ssh-keyscan -t rsa,ed25519 github.com gitlab.com >> docker/ssh/known_hosts
```

## 2. 权限（宿主机）

```bash
chmod 700 docker/ssh
chmod 600 docker/ssh/id_* 
chmod 644 docker/ssh/*.pub docker/ssh/known_hosts 2>/dev/null || true
# 不要用 777；私钥必须 600
```

## 3. 加到代码平台

把 **`id_ed25519.pub`（或 id_rsa.pub）** 加到仓库的 **Deploy Keys**（只读即可）。

## 4. Walle 项目配置

在 Walle 后台编辑项目：

| 配置项 | 容器内建议值 |
|--------|----------------|
| **检出仓库 / deploy_from** | `/data/walle-deploy` |
| **repo_url** | `git@github.com:org/repo.git` 等 SSH 地址 |

检测页提示的 php 用户名为 **`www-data`**（容器内），与宿主机 root 无关。

## 5. 发布到目标机

若还要 SSH 到业务服务器，可：

- 使用同一把 key（目标机 `authorized_keys` 加入公钥），或  
- 在 `docker/ssh/config` 里为不同 Host 指定不同 `IdentityFile`。

## 6. 使用宿主机已有密钥（可选）

在 `.env` 或启动前：

```bash
export WALLE_SSH_DIR=$HOME/.ssh
docker compose up -d --build
```

确保私钥对容器内可读；entrypoint 会复制到 `/var/www/.ssh` 并 `chown www-data`。

## 7. 验证

```bash
docker compose exec walle su -s /bin/bash www-data -c 'ssh -T git@github.com'
docker compose exec walle su -s /bin/bash www-data -c 'git ls-remote git@github.com:org/repo.git HEAD'
```
