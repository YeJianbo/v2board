# 定制版 V2Board + v2node 部署文档

本文档适用于 `YeJianbo/v2board` 与配套 `YeJianbo/v2node`。本分支基于 V2Board V1 后端，集成了打包后的 XBoard 管理后台、v2node 协议节点、机器探针和探针直接创建 v2node 节点能力。

## 部署流程变化

相比原版 V2Board，当前分支的部署流程有这些变化：

1. 后台前端不再单独拉取源码构建。公开仓库只包含后端和已打包的管理后台静态资源，部署时直接使用仓库内的 `public/assets/admin`。
2. 管理后台走安全路径。访问地址为 `https://你的域名/{secure_path}`，`secure_path` 可在后台系统设置中修改；首次安装时命令行会输出默认路径。
3. Redis 必须正常工作。缓存、队列、Horizon、探针一次性安装密钥、探针状态和重启指令都依赖 Redis。
4. 新增 `v2_machine` 机器探针表，并给 v2node 节点增加 `machine_id` 关联字段。升级已有站点时必须执行迁移。
5. v2node 安装脚本改为默认使用 `YeJianbo/v2node`，release 下载也优先走 fork。
6. v2node 已支持两种部署方式：传统单节点安装和机器探针安装。探针模式可以让后台直接针对在线服务器创建 v2node 节点并下发配置。
7. 已安装 v2node 的服务器再次执行传统节点安装命令时，会向现有 `/etc/v2node/config.json` 追加节点，不再覆盖整份配置。
8. 探针页面和通用安装密钥生成只对登录后台开放。探针运行时接口需要公网可访问给节点服务器调用，但必须携带一次性安装 token 或机器密钥 HMAC 签名。

官方 [V2Board changelog](https://v2board.com/CHANGELOG.html) 中仍然适用的部署要点包括：Laravel 8、Redis 队列、`secure_path` 安全后台路径和保存主题配置。

## 服务器要求

- Linux 64 位系统，推荐 Debian 11/12、Ubuntu 20.04/22.04。
- Nginx 或 OpenResty。
- PHP 7.3+ 或 PHP 8.x，推荐 PHP 8.1/8.2。
- MySQL 5.7+ 或 MariaDB 10.3+。
- Redis。
- Composer 2。
- Supervisor 或 PM2，用于守护 Horizon 队列。

PHP 扩展至少需要：

```bash
php-cli php-fpm php-mysql php-redis php-mbstring php-curl php-xml php-zip php-gd php-bcmath
```

## 面板首次部署

### 1. 拉取代码

```bash
cd /www/wwwroot
git clone https://github.com/YeJianbo/v2board.git v2board
cd v2board
```

生产环境建议固定到一个已验证的 tag 或 commit。直接使用 `main` 时，需要先在测试机验证迁移和后台功能。

### 2. 安装依赖

```bash
composer install --no-dev --optimize-autoloader
```

如果服务器 Composer 较慢，可先配置国内镜像或在本地打包 `vendor` 后上传。

### 3. 配置环境变量

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env`：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的面板域名

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=v2board
DB_USERNAME=v2board
DB_PASSWORD=你的数据库密码

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

`APP_URL` 必须填写真实公网地址。v2node 和探针会优先使用后台系统设置里的站点 URL；设置为空或错误时会回退到 `.env`。

### 4. 初始化数据库

新站点：

```bash
php artisan v2board:install
```

已有数据库升级：

```bash
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan config:cache
```

升级后需要确认以下表和字段存在：

- `v2_machine`
- `v2_server_v2node.machine_id`
- 其他节点表的 `machine_id` 字段

### 5. 权限

```bash
chown -R www-data:www-data /www/wwwroot/v2board
chmod -R 755 /www/wwwroot/v2board
chmod -R 775 storage bootstrap/cache
```

按实际 PHP-FPM 用户替换 `www-data`，宝塔常见为 `www`。

### 6. Nginx

站点根目录必须指向 `public`：

```nginx
server {
    listen 80;
    server_name example.com;
    root /www/wwwroot/v2board/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

配置 HTTPS 后，把后台系统设置里的站点 URL 改成 `https://你的域名`。

### 7. 启动队列

使用 PM2：

```bash
npm install -g pm2
pm2 start pm2.yaml
pm2 save
pm2 startup
```

或使用 Supervisor 守护：

```ini
[program:v2board-horizon]
process_name=%(program_name)s
command=php /www/wwwroot/v2board/artisan horizon
directory=/www/wwwroot/v2board
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/www/wwwroot/v2board/storage/logs/horizon.log
stopwaitsecs=3600
```

修改配置、更新代码或迁移后执行：

```bash
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
```

### 8. 后台基础设置

进入后台后先完成这些设置：

1. 系统设置里配置站点 URL。
2. 系统设置里配置通讯密钥 `server_token`。
3. 保存主题配置，选择当前主题后点击保存。
4. 在节点管理里创建权限组和路由。
5. 在节点管理里创建 v2node 节点，或进入探针页面添加机器。

后台入口为：

```text
https://你的面板域名/{secure_path}
```

`secure_path` 可在后台系统设置中修改。

## 管理后台静态资源

本仓库部署时不需要构建后台前端。

当前公开仓库包含的是已打包后的管理后台文件：

```text
public/assets/admin
```

不要把本地 `XBoard-admin-src`、`admin_frontend` 等前端源码目录上传到公开仓库。需要更新后台界面时，在私有前端仓库构建后，把构建产物同步到 `public/assets/admin`，再提交后端公开仓库。

## 性能与缓存策略

当前分支把机器探针、v2node 节点、中转规则和流量统计放进同一个后台，但已部署机器不能依赖后台页面持续在线才能转发。性能优化按这个边界处理：

1. 机器本地已经生成的 `/etc/v2node/config.json`、gost 转发规则和防火墙端口继续由机器本地服务维持。面板短暂宕机或后台页面关闭，不会主动清空已部署机器的本地规则。
2. 后台 `machine/fetch` 只做面板展示用途，不缓存整份机器列表和机器密钥，也不在轮询响应中返回机器密钥；复制安装命令或机器 token 时才通过登录后台单独按机器读取。自动中转规则计算使用 2 秒短缓存，避免机器页面轮询造成 N+1 查询。
3. 前端机器页面有请求防重入。上一轮机器列表请求未结束时，不会继续叠加新的轮询请求。
4. 探针状态优先写 Redis 状态缓存，数据库只按固定间隔或关键字段变化落盘，降低频繁上报造成的数据库写入。
5. 历史流量、节点用户排行、连通性测试等重接口应按需打开，不应放进机器列表轮询主链路。

生产环境建议：

```bash
php artisan config:cache
php artisan route:cache
php artisan horizon:terminate
redis-cli ping
```

如果 CPU 异常升高，优先检查：

```bash
top -c
tail -f storage/logs/laravel.log
redis-cli info stats
mysqladmin processlist
```

不要通过缩短探针轮询到 1 秒以内来追求“实时感”。机器卡片展示适合 3-5 秒级刷新，连通性测试、节点明细和历史图表按需加载。

## v2node 部署方式

配套 v2node 仓库：

```text
https://github.com/YeJianbo/v2node
```

当前已发布版本：

```text
v0.4.2-yjb1
```

### 方式一：探针模式

这是推荐方式。适合一台服务器承载一个或多个 v2node 节点，后台可以看到机器 CPU、内存、磁盘、网络状态，并可以从后台给在线机器添加 v2node 节点。

后台操作：

1. 进入 `节点管理 -> 探针`。
2. 点击“复制通用安装”生成一次性安装密钥。
3. 在目标服务器执行复制出来的命令。
4. 探针上线后，在探针页面选择协议和配置，直接创建 v2node 节点。

通用命令格式：

```bash
wget -N https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh && \
bash install.sh v0.4.2-yjb1 --mode machine --panel 'https://你的面板域名' --enroll-token '后台生成的通用安装密钥'
```

指定已有探针：

```bash
wget -N https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh && \
bash install.sh v0.4.2-yjb1 --mode machine --panel 'https://你的面板域名' --enroll-token '后台生成的通用安装密钥' --machine-id 1
```

使用固定机器密钥接入已有探针：

```bash
wget -N https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh && \
bash install.sh v0.4.2-yjb1 --mode machine --panel 'https://你的面板域名' --token '机器通信密钥' --machine-id 1
```

探针配置写入：

```text
/etc/v2node/machine.env
```

探针同步脚本：

```text
/usr/local/v2node/v2node-probe.sh
```

探针会定时拉取本机绑定的 v2node 节点，生成 `/etc/v2node/config.json`，并在后台下发重启指令后重启 v2node 服务。

### 方式二：传统节点模式

这是原 v2node 的单节点安装方式。适合只部署一个节点，或暂时不使用探针页面。

先在后台创建 v2node 节点，然后复制节点面板里的安装命令。命令格式：

```bash
wget -N https://raw.githubusercontent.com/YeJianbo/v2node/main/script/install.sh && \
bash install.sh v0.4.2-yjb1 --api-host 'https://你的面板域名' --node-id 1 --api-key '后台通讯密钥'
```

传统模式支持重复执行。若 `/etc/v2node/config.json` 已存在，并传入了 `--api-host`、`--node-id`、`--api-key`，脚本会向 `Nodes` 追加一个节点。

### v2node 服务管理

常用命令：

```bash
v2node status
v2node restart
v2node stop
v2node log
v2node update
v2node update_shell
v2node generate
```

直接查看 systemd：

```bash
systemctl status v2node
journalctl -u v2node -f
```

更新 v2node：

```bash
v2node update
```

当前 fork 的脚本会优先从 `YeJianbo/v2node` release 下载。若指定版本在 fork 中不存在，安装脚本才会回退上游 release。

## 探针鉴权说明

探针注册分两步：

1. 登录后台后生成一次性 `enroll_token`。
2. 目标服务器用 `enroll_token` 调用 `/api/v1/server/machine/enroll`，换取 `machine_id` 和机器通信密钥。

探针运行时的配置拉取、状态上报和重启确认都使用 HMAC 签名，请求头包含：

```text
X-V2Node-Machine-Id
X-V2Node-Timestamp
X-V2Node-Nonce
X-V2Node-Signature
```

后端会校验时间窗口、nonce 重放和机器密钥。`v2_machine.host` 只作为机器入口地址和显示地址，不作为默认来源 IP 白名单。

当前安全边界：

1. 后台机器管理、通用安装密钥、DDNS 配置和节点创建接口都挂在 `/api/v1/admin/*`，必须登录后台并通过管理员鉴权。
2. 探针公开接口只允许固定 action，路由层有 allowlist；不存在通配控制器任意调用。
3. 旧版 unsigned `machineapi` 已禁用，返回 410。
4. 通用远程命令下发已禁用，返回 410；探针只支持配置同步、DDNS、端口转发、v2node 重启、BBR 和连通性测试这类受限操作。
5. DDNS 由面板服务端调用 Cloudflare API，机器端不接收 Cloudflare token。
6. 默认不启用来源 IP 白名单。动态 IP、NAT、双栈出口机器以 HMAC 签名为准，避免出口变化导致机器全部离线。
7. 探针不提供 WebSSH，也不应加入任意 shell 执行能力。

不要把机器通信密钥、通用安装密钥、后台 cookie、Cloudflare token 或 `.env` 提交到仓库。怀疑泄露时，重新生成机器 token 或删除旧机器重新注册。

### 面板宕机时的机器行为

已部署机器必须保持“最后一次成功同步”的本地状态：

- v2node 服务继续使用本地 `/etc/v2node/config.json`。
- gost 继续使用本地已有转发规则。
- 防火墙端口不会因为面板不可用被关闭。
- 面板恢复后，探针下一次同步再应用新的节点和转发规则。

因此升级面板时不要删除机器本地配置，也不要在面板不可用时下发空规则。需要回滚时，优先回滚面板代码和数据库迁移，不要 SSH 到每台节点机清空 v2node/gost。

### 探针接口检查

可在面板服务器检查公开路由是否被限制：

```bash
php artisan route:list | grep 'server/machine'
php artisan route:list | grep 'machineapi'
```

预期：

- `/api/v1/server/machine/enroll` 只接受一次性安装 token。
- `/api/v1/server/machine/config`、`push`、`v2nodeconfig`、`restartack`、`bbrack`、`connectivitytestack` 均要求 HMAC 签名。
- `/api/v1/server/machineapi/*` 不再可用。

## 升级已有站点

从原版 V2Board 或旧定制版升级时，推荐流程：

```bash
cd /www/wwwroot/v2board
git fetch --all
git checkout main
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan horizon:terminate
```

低风险升级流程：

1. 先备份代码目录、`.env` 和数据库。
2. 不停止节点机器上的 `v2node` 与 `gost` 服务。
3. 面板代码更新后只重载 PHP-FPM、清理 Laravel 缓存和重启 Horizon。
4. 登录后台确认机器页面可打开，再逐台观察探针是否自然恢复在线。
5. 只有确认新版本正常后，才考虑升级节点机器上的 v2node 探针。

升级后检查：

1. 后台能通过 `/{secure_path}` 打开。
2. `节点管理` 页面不卡顿，节点 ID 按各协议原始 ID 展示。
3. `节点流量排行` 显示节点名称和协议。
4. `节点管理 -> 探针` 可以生成通用安装命令。
5. 探针上线后能显示 IP、CPU、内存、磁盘和网络数据。
6. 探针页面可以创建 v2node 节点。
7. v2node 传统安装命令能追加节点。

### 回滚

如果升级后后台异常，但节点机器仍在转发，先不要动节点机器。按下面顺序回滚面板：

```bash
cd /www/wwwroot/v2board
git log --oneline -5
git checkout <上一个可用 commit>
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan horizon:terminate
systemctl reload php-fpm-81 || systemctl restart php-fpm-81
```

如果这次发布包含数据库迁移，先确认迁移是否可逆。不能确认时不要直接回滚数据库结构，优先用代码兼容旧字段或恢复数据库备份。

## 故障排查

### 后台白屏或静态资源 404

确认 Nginx root 指向 `public`，并确认文件存在：

```bash
ls -lah public/assets/admin
```

清理浏览器缓存后重新访问 `/{secure_path}`。

### 登录后接口 401 或 403

确认：

```bash
php artisan config:clear
php artisan cache:clear
```

并检查 `.env` 中 `APP_URL`、Session、Redis 是否正确。

### 探针离线

在节点服务器检查：

```bash
cat /etc/v2node/machine.env
/usr/local/v2node/v2node-probe.sh
systemctl status v2node
journalctl -u v2node -n 100 --no-pager
```

在面板服务器检查 Redis 和 Laravel 日志：

```bash
redis-cli ping
tail -f storage/logs/laravel.log
```

### v2node 下载失败

确认 release asset 可访问：

```bash
curl -I -L https://github.com/YeJianbo/v2node/releases/download/v0.4.2-yjb1/v2node-linux-64.zip
```

服务器无法访问 GitHub 时，需要配置代理或手动下载 release zip 后上传到服务器。

### 节点不上报流量

确认后台系统设置里的通讯密钥和节点配置一致：

```bash
cat /etc/v2node/config.json
```

传统模式检查 `ApiHost`、`NodeID`、`ApiKey`；探针模式先检查探针是否能拉取到绑定节点。

## 发布与仓库约定

公开仓库：

- `https://github.com/YeJianbo/v2board`
- `https://github.com/YeJianbo/v2node`

私有仓库：

- 后台前端源码
- 用户前端源码
- 未打包的私有定制代码

发布新版本时建议顺序：

1. 私有前端构建后台静态资源。
2. 同步打包产物到 `public/assets/admin`。
3. 后端执行测试和迁移检查。
4. 提交并推送 `YeJianbo/v2board`。
5. v2node 编译并发布 release asset。
6. 在测试机验证面板、探针和传统 v2node 安装。
