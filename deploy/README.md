# BunCloud Panel deployment

## Fresh Debian/Ubuntu installation

Run as root on a clean server. The script installs PHP-FPM, MariaDB, Redis,
Nginx, Supervisor, Composer, Cron and rclone, creates the database and admin
account, configures Horizon and the Laravel scheduler, and attempts to issue a
Let's Encrypt certificate after Nginx is reachable.

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/YeJianbo/v2board-backend-custom/main/deploy/install.sh) \
  --domain panel.example.com \
  --admin-email admin@example.com
```

Use another fork or branch:

```bash
bash install.sh \
  --repo https://github.com/OWNER/REPOSITORY.git \
  --branch main \
  --domain panel.example.com \
  --admin-email admin@example.com
```

The generated database and administrator credentials are written to a
root-only file under `/root/v2board-credentials-*.txt`.

## One-command migration

The archive may be a local path or an HTTP(S) URL:

```bash
bash install.sh \
  --domain panel.example.com \
  --archive /root/v2board-migration-YYYYmmdd-HHMMSS.tar.gz \
  --force
```

Use `--standby` to restore files and data without starting Horizon or the
scheduler before DNS cutover. Encrypted packages use `MIGRATION_PASSWORD`.

## Automatic backup

Configure **System settings -> Backup and migration** in the admin panel.

- `database`: transaction-safe compressed database dump.
- `migration`: site files, database, environment, TLS files when readable,
  Nginx/Supervisor/Cron configuration, referenced BT cron scripts, and the
  restore script. Portable root cron entries are restored without duplicating
  Laravel Scheduler; BT-only jobs are restored only on a BT target.
- Remote storage uses rclone. Google Drive, FTP, FTPS and SFTP therefore share
  the same reliable upload and retention implementation.

Configure a remote once on the panel server:

```bash
rclone config
rclone lsd gdrive:
```

Then enter a destination such as `gdrive:buncloud-backups` or
`ftp:buncloud-backups` in the panel. Leaving it empty keeps backups locally in
`storage/app/backups`.

Manual verification:

```bash
cd /www/wwwroot/v2.151376.xyz
sudo -u www php artisan panel:backup --type=database
sudo -u www php artisan panel:backup --type=migration
cat storage/app/backup/status.json
```
