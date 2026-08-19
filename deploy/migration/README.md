# V2Board 快速迁移

从空服务器直接安装或恢复时，可优先使用上一级的统一入口：

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/YeJianbo/v2board-backend-custom/main/deploy/install.sh) \
  --domain panel.example.com \
  --archive /root/v2board-migration.tar.gz \
  --force
```

这套工具把当前站点打成一个可校验的迁移包，并在新的 Debian/Ubuntu 服务器上自动恢复。支持干净系统和已有宝塔环境：

- 网站目录（包含 `.env`、`vendor` 和前后台静态资源）
- MySQL/MariaDB 数据库完整转储
- SSL 证书和私钥
- 当前 Nginx、Supervisor、Cron、PHP 模块快照
- Nginx、PHP-FPM、MariaDB、Redis、Supervisor、Composer、Cron
- Laravel Horizon 和 `schedule:run`

恢复时会合并源服务器的 root crontab，不覆盖目标机已有任务，并自动跳过重复的 Laravel Scheduler。宝塔定时任务会连同引用的 `/www/server/cron` 脚本一起迁移到宝塔目标；普通 Debian/Ubuntu 目标只恢复可移植任务，例如每日重启，宝塔专属任务会明确跳过。

检测到宝塔时，恢复脚本会复用宝塔的 PHP-FPM、Nginx 虚拟主机、`www` 用户和 Supervisor，不会再安装一套冲突的系统 Nginx/PHP。缺少 Redis 或 PHP Redis 扩展时会自动补齐。

Redis 数据不迁移。Redis 仅承载缓存、Session 和队列，迁移后由新服务器重新建立，避免复制过期锁、旧队列和临时在线状态。

## 1. 源服务器导出

```bash
cd /www/wwwroot/v2.151376.xyz/deploy/migration
sudo bash export.sh
```

默认输出：

```text
/root/v2board-migration/
├── restore.sh
├── v2board-migration-YYYYmmdd-HHMMSS.tar.gz
└── v2board-migration-YYYYmmdd-HHMMSS.tar.gz.sha256
```

归档含数据库、`.env` 和 TLS 私钥，默认权限为 `600`。需要额外加密时：

```bash
export MIGRATION_PASSWORD='使用单独的高强度迁移密码'
sudo -E bash export.sh
unset MIGRATION_PASSWORD
```

正式切换时可冻结源站，避免导出后数据继续变化：

```bash
sudo bash export.sh --cutover
```

`--cutover` 会开启 Laravel 维护模式并停止 Horizon，源服务器保持冻结状态。

## 2. 传输到新服务器

```bash
scp /root/v2board-migration/restore.sh root@NEW_SERVER:/root/
scp /root/v2board-migration/v2board-migration-*.tar.gz* root@NEW_SERVER:/root/
```

传输后先核对 SHA256：

```bash
cd /root
sha256sum -c v2board-migration-*.sha256
```

## 3. 新服务器恢复

在干净 Debian/Ubuntu 服务器上执行：

```bash
sudo bash /root/restore.sh --archive /root/v2board-migration-YYYYmmdd-HHMMSS.tar.gz
```

脚本会自动安装系统组件、创建数据库账户、导入数据、生成 Nginx/Supervisor/Cron 配置并启动服务。新数据库密码保存到：

```text
/root/v2board-migration-credentials-YYYYmmdd-HHMMSS.txt
```

如果目标机已有同名目录或数据库，必须显式允许备份并替换：

```bash
sudo bash /root/restore.sh --archive /root/v2board-migration.tar.gz --force
```

原站目录会保留为 `SITE_ROOT.pre-migration-时间`，数据库和 Nginx 配置备份保存在 `/root/v2board-pre-migration-时间/`。如果目标 MySQL 禁止 root 免密登录，脚本会在数据库名一致且权限足够时复用目标原站数据库账号，不修改原密码。

## 预部署模式

需要先验证文件和数据库、稍后再切换 DNS 时：

```bash
sudo bash /root/restore.sh --archive /root/v2board-migration.tar.gz --force --standby
```

`--standby` 不启动 Horizon，并注释 Laravel 定时任务，避免新旧服务器同时发通知、处理订单或执行统计任务。
源服务器的 root crontab 会写入 `/root/v2board-migrated-crontab-*.pending`，在切换前不会执行。

切换 DNS 前，在旧服务器停止 Horizon 和 Scheduler；切换后在新服务器执行：

```bash
sed -i 's/^# \(\* \* \* \* \*\)/\1/' /etc/cron.d/v2board-scheduler
supervisorctl start v2board-horizon
systemctl restart cron
crontab /root/v2board-migrated-crontab-*.pending
```

宝塔环境使用：

```bash
/www/server/panel/pyenv/bin/supervisorctl start v2board-horizon
systemctl restart cron
```

## 验证

```bash
nginx -t
supervisorctl status
systemctl status nginx redis-server mariadb supervisor cron
cd /www/wwwroot/v2.151376.xyz
sudo -u www-data php artisan schedule:list
sudo -u www-data php artisan horizon:status
curl -I -H 'Host: v2.151376.xyz' http://127.0.0.1/
```

宝塔环境可将 `supervisorctl` 替换为 `/www/server/panel/pyenv/bin/supervisorctl`，PHP-FPM 服务通常为 `php-fpm-81` 等版本化名称。

DNS 切换完成并确认新站正常后，再下线旧服务器。不要让两台服务器长期同时运行 Horizon 和 Laravel Scheduler。
