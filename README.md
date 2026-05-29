![](https://raw.github.com/meolu/walle-web/master/docs/logo.jpg)

Walle - A Deployment Tool
=========================
[![Build Status](https://travis-ci.org/meolu/walle-web.svg?branch=master)](https://travis-ci.org/meolu/walle-web)
[![Packagist](https://img.shields.io/packagist/v/meolu/walle-web.svg)](https://packagist.org/packages/meolu/walle-web)
[![Yii2](https://img.shields.io/badge/Powered_by-Yii_Framework-green.svg?style=flat)](http://www.yiiframework.com/)

A web deployment tool, Easy for configuration, Fully functional, Smooth interface, Out of the box.
support git/svn Version control system, no matter what language you are, php/java/ruby/python, just as jenkins. you can deploy the code or output to multiple servers easily by walle.

[Home Page](https://www.walle-web.io) | [官方主页](https://www.walle-web.io) | [中文说明](https://github.com/meolu/walle-web/blob/master/docs/README-zh.md) | [文档手册](https://www.walle-web.io/docs/).

Now, there are more than hundreds of companies hosted walle for deployment, star walle if you like : )

* Support git/svn Version control system.
* User signup by admin/develop identity.
* Developer submit a task, deploy task.
* Admin audit task.
* Multiple project.
* Multiple Task Parallel.
* Quick rollback.
* Group relation of project.
* Task of pre-deploy（e.g: test ENV var）.
* Task of post-deploy（e.g: mvn/ant, composer install for vendor）.
* Task of pre-release（e.g: stop service）.
* Task of post-release（e.g: restart service）.
* Check up file md5.
* Multi-process multi-server file transfer (Ansible).


Requirements
------------

* Bash(git、ssh)
* LNMP/LAMP (PHP 8.0+, 推荐 8.2)
* Composer
* Ansible(Optional)

That's all. It's base package of PHP environment!


Installation
------------
## php8.2以及需要的lib库安装
```shell
apt -y install software-properties-common
add-apt-repository ppa:ondrej/php
apt update
apt install php8.2 -y
php -v
apt install -y   php8.2-cli   php8.2-fpm   php8.2-mysql   php8.2-mbstring   php8.2-intl   php8.2-bcmath   php8.2-xml   php8.2-curl   php8.2-zip
```
## composer安装
```shell
php8.2 -v   # 先确认 8.2 可用
cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
php8.2 composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
rm composer-setup.php
composer -V
```

```
git clone git@github.com:meolu/walle-web.git
cd walle-web
cp config/local.php.dist config/local.php   # 生成本地配置（不入库）
vi config/local.php                           # 改数据库密码、cookieValidationKey 等
php8.2 /usr/local/bin/composer install
php8.2 yii migrate/up --interactive=0
php8.2 yii walle/setup --interactive=0
```

`config/local.php` 不会随 Git 提交。克隆后从模板复制即可；未复制时 Web/控制台会临时使用 `config/local.php.dist`（仅适合本地试跑，生产务必复制并修改 `cookieValidationKey`）。

Docker 部署（PHP 8.2，MySQL 用宿主机）
------------
```bash
# 1. 宿主机先建好库
mysql -e "CREATE DATABASE walle DEFAULT CHARSET utf8 COLLATE utf8_general_ci;"

# 2. 允许 Docker 访问（示例，按你环境改密码与网段）
#    bind-address 勿仅 127.0.0.1；或 WALLE_DB_HOST 填宿主机内网 IP（见 .env.example）

cp .env.example .env
# 编辑 .env：WALLE_DB_PASS、WALLE_COOKIE_KEY 等

# Git Deploy Key：见 docker/ssh/README.md
mkdir -p docker/ssh
cp ~/.ssh/id_ed25519 docker/ssh/
chmod 600 docker/ssh/id_ed25519
ssh-keyscan github.com >> docker/ssh/known_hosts

docker compose up -d --build
# 浏览器 http://localhost:8080（容器内 Nginx + PHP 8.2-FPM）
```

宿主机已有 Nginx 时，可反代到 `127.0.0.1:8080`，示例见 `docker/nginx-host.example.conf`。

Walle 项目配置里 **检出目录 deploy_from** 填：`/data/walle-deploy`。  
容器内 git/ssh 用户为 **www-data**。

Or [The Most Detailed Installation Guide](https://github.com/meolu/walle-web/blob/master/docs/install-en.md), any questions refer to [FAQ](https://github.com/meolu/walle-web/blob/master/docs/faq-en.md)

Quick Start
-------------

* Signup a admin user(`admin/admin` exists), then configure a project, add member to the project, detect it.
    * [git demo](https://github.com/meolu/walle-web/blob/master/docs/config-git-en.md)
    * [svn demo](https://github.com/meolu/walle-web/blob/master/docs/config-svn-en.md)
* Signup a develop user(`demo/demo` exists), submit a deployment.
* Project admin audit the deployment.
* Developer deploy the deployment.


Custom
--------
you would like to adjust some params to make walle suited for your company.

* Set suffix of email while signing in
    ```php
    vi config/params.php

    'mail-suffix'   => [  // specify the suffix of email, multiple suffixes are allow.
        'huamanshu.com',  // e.g: allow xyz@huamanshu.com only
    ]
    ```

* Configure email smtp（在 `config/local.php` 的 `components.mail` 中）
    ```php
    'components' => [
        'mail' => [
            'useFileTransport' => false,
            'transport' => [
                'scheme'   => 'smtp',
                'host'     => 'smtp.example.com',
                'username' => 'service@example.com',
                'password' => 'your-password',
                'port'     => 587,
            ],
            'messageConfig' => [
                'charset' => 'UTF-8',
                'from'    => ['service@example.com' => 'Walle'],
            ],
        ],
    ],
    ```

* Configure the path for log
    ```php
    vi config/params.php

    'log.dir'   => '/tmp/walle/',
    ```

* Configure language
    ```php
    vi config/web.php +73

    'language'   => 'en',  # zh => 中文,  en => English
    ```


To Do List
----------

- Travis CI integration
- Mail events：specify kinds of events
- Gray released：specify servers
- Websocket instead of poll
- A manager of static source
- Configure variables
- Support Docker
- Open api
- Command line

Update
-----------------
```
./yii walle/upgrade    # upgrade walle
```


Architecture
------------
#### git/svn, user, host, servers
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-flow-relation-en.png)

#### deployment flow
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-flow-en.png)

Screenshots
-----------

#### project config
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-config-edit-en.jpg)

#### sumbit a task
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-submit-en.jpg)

#### list of task
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-dev-list-en.jpg)

#### demo show
![](https://raw.github.com/meolu/docs/master/walle-web.io/docs/en/static/walle-en.gif)

## CHANGELOG
[CHANGELOG](https://github.com/meolu/walle-web/releases)


Discussing
----------
- [submit issue](https://github.com/meolu/walle-web/issues/new)
- email: wushuiyong@huamanshu.com
