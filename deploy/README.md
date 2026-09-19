# BunCloud Panel deployment

## Fresh Debian/Ubuntu installation

Run as root on a clean server. The script installs PHP-FPM, MariaDB, Redis,
Nginx, Supervisor, Composer, Cron and rclone, creates the database and admin
account, configures Horizon and the Laravel scheduler, and attempts to issue a
Let's Encrypt certificate after Nginx is reachable.

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/YeJianbo/v2board/main/deploy/install.sh) \
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
- Google Drive can be authorized directly in the admin panel. It uses a
  dedicated config at `storage/app/backup/rclone-panel.conf` and never reads or
  overwrites the operating-system user's existing rclone configuration.
- Custom rclone destinations remain available for FTP, FTPS, SFTP and existing
  remotes, preserving the previous upload and retention behavior.

For a custom rclone destination, configure a remote once on the panel server:

```bash
rclone config
rclone lsd gdrive:
```

Then enter a destination such as `gdrive:buncloud-backups` or
`ftp:buncloud-backups` in the panel. Leaving it empty keeps backups locally in
`storage/app/backups`.

For the isolated Google Drive integration, enable Google Drive API in Google
Cloud, create an OAuth 2.0 Web application, add the callback URL displayed by
the panel, and finish authorization from **System settings -> Backup and
migration**. The requested `drive.file` scope only manages files created by
this panel integration.

Manual verification:

```bash
cd /www/wwwroot/v2.151376.xyz
sudo -u www php artisan panel:backup --type=database
sudo -u www php artisan panel:backup --type=migration
cat storage/app/backup/status.json
```
