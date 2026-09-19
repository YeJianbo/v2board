<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $title }}</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ccircle cx='32' cy='32' r='24' fill='%2320a464'/%3E%3C/svg%3E">
  <style>
    :root {
      color-scheme: light;
      font-family: "Noto Sans SC", "PingFang SC", "Microsoft YaHei", sans-serif;
      background: #f4f7f6;
      color: #17251d;
    }

    * { box-sizing: border-box; }
    body { margin: 0; min-width: 320px; }
    main { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 64px 0; }
    header { margin-bottom: 24px; }
    h1 { margin: 0; font-size: clamp(24px, 5vw, 34px); letter-spacing: -.03em; }
    .machines { display: grid; gap: 10px; }
    .machine {
      display: grid;
      grid-template-columns: 10px minmax(180px, .9fr) minmax(210px, .85fr) minmax(300px, 1.2fr);
      align-items: center;
      gap: 14px;
      min-width: 0;
      padding: 15px 16px;
      border: 1px solid #dfe8e2;
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 1px 2px rgba(17, 39, 26, .03);
      transition: border-color .2s ease, background-color .2s ease;
    }
    .indicator { width: 9px; height: 9px; border-radius: 999px; background: #aeb8b2; }
    .machine.is-online .indicator { background: #20a464; box-shadow: 0 0 0 4px rgba(32, 164, 100, .12); }
    .identity { display: grid; min-width: 0; grid-template-columns: 24px minmax(0, 1fr); align-items: center; column-gap: 9px; }
    .flag {
      grid-row: 1 / span 2;
      display: block;
      width: 24px;
      height: 18px;
      overflow: hidden;
      object-fit: contain;
    }
    .name { min-width: 0; overflow: hidden; color: #1c2d23; font-size: 15px; font-weight: 650; text-overflow: ellipsis; white-space: nowrap; }
    .country { overflow: hidden; color: #718077; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .metrics { display: grid; min-width: 0; gap: 6px; }
    .metric { display: grid; grid-template-columns: 34px minmax(50px, 1fr) 38px; align-items: center; gap: 7px; color: #718077; font-size: 11px; }
    .metric-label { font-weight: 600; }
    .metric-bar { height: 5px; overflow: hidden; border-radius: 999px; background: #e8efeb; }
    .metric-fill { display: block; height: 100%; border-radius: inherit; background: #6aa8e8; transition: width .35s ease; }
    .metric-fill.mem { background: #68c796; }
    .metric-value { color: #405249; font-family: Consolas, monospace; text-align: right; font-variant-numeric: tabular-nums; }
    .metric-value.is-empty { color: #a4afa8; }
    .facts { display: grid; min-width: 0; grid-template-columns: 30px minmax(96px, .8fr) minmax(150px, 1fr); gap: 8px; }
    .fact { display: grid; min-width: 0; gap: 3px; }
    .fact-label { color: #89968e; font-size: 10px; font-weight: 650; letter-spacing: .04em; }
    .fact-value { overflow: hidden; color: #405249; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .system-icon { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 7px; background: #f3f7f5; }
    .system-icon img { display: block; width: 19px; height: 19px; object-fit: contain; }
    .network-values { display: flex; min-width: 0; gap: 8px; color: #405249; font-family: Consolas, monospace; font-size: 11px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .network-values span { overflow: hidden; text-overflow: ellipsis; }
    .machine.is-offline { border-color: #e3e7e4; background: #f7f8f7; box-shadow: none; }
    .machine.is-offline .flag { filter: grayscale(1); opacity: .5; }
    .machine.is-offline .system-icon { filter: grayscale(1); opacity: .55; }
    .machine.is-offline .name,
    .machine.is-offline .metric-value,
    .machine.is-offline .fact-value,
    .machine.is-offline .network-values { color: #89928d; }
    .machine.is-offline .metric-fill { background: #bac1bd; }
    .empty { padding: 32px 18px; border: 1px dashed #cfdad3; border-radius: 12px; color: #718077; text-align: center; }
    @media (max-width: 780px) {
      .machine { grid-template-columns: 10px minmax(150px, 1fr) minmax(180px, 1fr); }
      .facts { grid-column: 2 / -1; }
    }
    @media (max-width: 520px) {
      main { width: min(100% - 24px, 980px); padding: 36px 0; }
      .machine { grid-template-columns: 10px minmax(0, 1fr); padding: 14px 12px; gap: 10px; }
      .metrics,
      .facts { grid-column: 2; }
      .facts { grid-template-columns: 30px minmax(0, 1fr); }
      .facts .fact:last-child { grid-column: 1 / -1; }
    }
  </style>
</head>
<body>
  <main>
    <header>
      <h1>主机监控</h1>
    </header>

    <section class="machines" aria-label="主机状态">
      @forelse ($machines as $machine)
        @php
          $countryCode = strtoupper((string) ($machine['country_code'] ?? ''));
          $flagCode = strtolower($countryCode);
          $hasFlag = preg_match('/^[a-z]{2}$/i', $countryCode) === 1;
          $systemName = strtolower(trim((string) ($machine['system'] ?? '')));
          $systemIcon = match (true) {
            str_contains($systemName, 'debian') => 'debian',
            str_contains($systemName, 'alpine') => 'alpine',
            str_contains($systemName, 'ubuntu') => 'ubuntu',
            str_contains($systemName, 'centos') => 'centos',
            str_contains($systemName, 'rocky') => 'rocky',
            str_contains($systemName, 'alma') => 'alma',
            str_contains($systemName, 'fedora') => 'fedora',
            str_contains($systemName, 'arch linux') => 'arch',
            str_contains($systemName, 'freebsd') => 'freebsd',
            str_contains($systemName, 'opensuse'), str_contains($systemName, 'suse') => 'opensuse',
            str_contains($systemName, 'windows') => 'windows',
            str_contains($systemName, 'linux') => 'linux',
            default => 'server',
          };
        @endphp
        <article
          class="machine {{ $machine['is_online'] ? 'is-online' : 'is-offline' }}"
          data-machine-id="{{ $machine['id'] }}"
          data-country-code="{{ $countryCode }}"
        >
          <span class="indicator" aria-label="{{ $machine['is_online'] ? '在线' : '离线' }}"></span>
          <div class="identity">
            @if ($hasFlag)
              <img class="flag" src="/assets/flags/{{ $flagCode }}.svg" alt="" role="img" aria-label="{{ $machine['country'] }}" decoding="async">
            @endif
            <span class="name" data-field="name">{{ $machine['name'] }}</span>
            <span class="country" data-field="country">{{ $machine['country'] }}</span>
          </div>
          <div class="metrics" aria-label="资源占用">
            <div class="metric">
              <span class="metric-label">CPU</span>
              <span class="metric-bar"><span class="metric-fill" data-field="cpu-bar" style="width: {{ $machine['cpu'] ?? 0 }}%"></span></span>
              <span class="metric-value {{ $machine['cpu'] === null ? 'is-empty' : '' }}" data-field="cpu">{{ $machine['cpu'] === null ? '--' : $machine['cpu'] . '%' }}</span>
            </div>
            <div class="metric">
              <span class="metric-label">内存</span>
              <span class="metric-bar"><span class="metric-fill mem" data-field="mem-bar" style="width: {{ $machine['mem'] ?? 0 }}%"></span></span>
              <span class="metric-value {{ $machine['mem'] === null ? 'is-empty' : '' }}" data-field="mem">{{ $machine['mem'] === null ? '--' : $machine['mem'] . '%' }}</span>
            </div>
          </div>
          <div class="facts">
            <div class="fact fact--system">
              <span class="system-icon" data-field="system" data-system-icon="{{ $systemIcon }}" title="{{ $machine['system'] }}" role="img" aria-label="{{ $machine['system'] }}">
                <img src="/assets/os/{{ $systemIcon }}.svg" alt="" decoding="async">
              </span>
            </div>
            <div class="fact">
              <span class="fact-label">负载</span>
              <span class="fact-value" data-field="load">{{ $machine['load'] }}</span>
            </div>
            <div class="fact">
              <span class="fact-label">实时网络</span>
              <span class="network-values">
                <span data-field="net-in">↓ {{ $machine['net_in'] }}</span>
                <span data-field="net-out">↑ {{ $machine['net_out'] }}</span>
              </span>
            </div>
          </div>
        </article>
      @empty
        <div class="empty">暂无可展示的主机状态</div>
      @endforelse
    </section>
  </main>

  <script>
    (() => {
      const list = document.querySelector('.machines');
      if (!list || !list.querySelector('[data-machine-id]')) return;

      const setText = (card, field, value) => {
        const element = card.querySelector(`[data-field="${field}"]`);
        if (element) element.textContent = value;
      };
      const resolveSystemIcon = (value) => {
        const system = String(value || '').trim().toLowerCase();
        if (system.includes('debian')) return 'debian';
        if (system.includes('alpine')) return 'alpine';
        if (system.includes('ubuntu')) return 'ubuntu';
        if (system.includes('centos')) return 'centos';
        if (system.includes('rocky')) return 'rocky';
        if (system.includes('alma')) return 'alma';
        if (system.includes('fedora')) return 'fedora';
        if (system.includes('arch linux')) return 'arch';
        if (system.includes('freebsd')) return 'freebsd';
        if (system.includes('opensuse') || system.includes('suse')) return 'opensuse';
        if (system.includes('windows')) return 'windows';
        if (system.includes('linux')) return 'linux';
        return 'server';
      };
      const updateMetric = (card, field, value) => {
        const numericValue = Number(value);
        const hasValue = value !== null && value !== '' && Number.isFinite(numericValue);
        const textElement = card.querySelector(`[data-field="${field}"]`);
        const barElement = card.querySelector(`[data-field="${field}-bar"]`);
        if (textElement) {
          textElement.textContent = hasValue ? `${numericValue}%` : '--';
          textElement.classList.toggle('is-empty', !hasValue);
        }
        if (barElement) barElement.style.width = `${hasValue ? Math.max(0, Math.min(100, numericValue)) : 0}%`;
      };

      let timer = null;
      const refresh = async () => {
        try {
          const response = await fetch('/status.json', { cache: 'no-store', headers: { Accept: 'application/json' } });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const payload = await response.json();
          const machines = Array.isArray(payload.data) ? payload.data : [];
          const cards = Array.from(list.querySelectorAll('[data-machine-id]'));
          const currentIds = cards.map((card) => card.dataset.machineId).join(',');
          const nextIds = machines.map((machine) => String(machine.id)).join(',');
          if (currentIds !== nextIds) {
            window.location.reload();
            return;
          }

          machines.forEach((machine, index) => {
            const card = cards[index];
            if (!card || card.dataset.countryCode !== String(machine.country_code || '')) {
              window.location.reload();
              return;
            }
            const online = Boolean(machine.is_online);
            card.classList.toggle('is-online', online);
            card.classList.toggle('is-offline', !online);
            const indicator = card.querySelector('.indicator');
            if (indicator) indicator.setAttribute('aria-label', online ? '在线' : '离线');
            setText(card, 'name', machine.name || 'Machine');
            setText(card, 'country', machine.country || '未知地区');
            const systemIcon = card.querySelector('[data-field="system"]');
            if (systemIcon) {
              const systemName = machine.system || '未知系统';
              const iconName = resolveSystemIcon(systemName);
              const iconImage = systemIcon.querySelector('img');
              if (iconImage && systemIcon.dataset.systemIcon !== iconName) {
                iconImage.src = `/assets/os/${iconName}.svg`;
                systemIcon.dataset.systemIcon = iconName;
              }
              systemIcon.setAttribute('title', systemName);
              systemIcon.setAttribute('aria-label', systemName);
            }
            setText(card, 'load', machine.load || '--');
            setText(card, 'net-in', `↓ ${machine.net_in || '--'}`);
            setText(card, 'net-out', `↑ ${machine.net_out || '--'}`);
            updateMetric(card, 'cpu', machine.cpu);
            updateMetric(card, 'mem', machine.mem);
          });
        } catch (_) {
          // Keep the latest rendered status when a refresh temporarily fails.
        } finally {
          timer = window.setTimeout(refresh, document.hidden ? 15000 : 5000);
        }
      };

      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
          window.clearTimeout(timer);
          refresh();
        }
      });
      timer = window.setTimeout(refresh, 5000);
    })();
  </script>
</body>
</html>
