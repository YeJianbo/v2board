#!/usr/bin/env bash
set -euo pipefail
site=/www/wwwroot/v2.151376.xyz
stage="${1:?Pass the extracted release directory}"
php=/www/server/php/81/bin/php
ctl=/www/server/panel/pyenv/bin/supervisorctl
supervisor_config=/etc/supervisor/supervisord.conf
profile=/www/server/panel/plugin/supervisor/profile/buncloud-agent.ini
files=(app/Services/AgentStreamDecoder.php app/Services/AgentHttpTransport.php app/Services/PanelAgentService.php app/Services/AgentRunService.php app/Jobs/PanelAgentRunJob.php app/Logging/MysqlLoggerHandler.php app/Http/Controllers/V2/Admin/AgentController.php app/Http/Controllers/V2/Admin/AgentOperationController.php app/Http/Routes/V2/AdminRoute.php config/queue.php deploy/buncloud-agent.conf deploy/install-agent-worker.sh docs/agent-runtime.md)
test -f "$site/artisan"
test -x "$ctl"
already_installed=false
if test -f "$profile"; then already_installed=true; fi
for file in "${files[@]}"; do
  test -f "$stage/$file"
  if [[ "$file" == *.php ]]; then "$php" -l "$stage/$file"; fi
done
backup="$site/storage/app/agent-releases/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup"
chmod 700 "$backup"
for file in "${files[@]}"; do
  if test -f "$site/$file"; then
    mkdir -p "$backup/$(dirname "$file")"
    cp -p "$site/$file" "$backup/$file"
  fi
done
if test -f "$profile"; then cp -p "$profile" "$backup/supervisor.ini"; fi
for file in "${files[@]}"; do
  mkdir -p "$site/$(dirname "$file")"
  install -o www -g www -m 644 "$stage/$file" "$site/$file.new"
  mv "$site/$file.new" "$site/$file"
done
cd "$site"
if test -f bootstrap/cache/config.php; then "$php" artisan config:cache; fi
"$php" artisan route:clear
"$php" artisan route:list --path=agent
install -m 644 deploy/buncloud-agent.conf "$profile"
"$ctl" -c "$supervisor_config" reread
"$ctl" -c "$supervisor_config" update buncloud-agent
if "$already_installed"; then "$ctl" -c "$supervisor_config" restart buncloud-agent; fi
sleep 3
"$ctl" -c "$supervisor_config" status
printf 'Agent backup: %s\n' "$backup"
