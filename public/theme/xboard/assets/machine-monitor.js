(function () {
  let panel, original, busy = false, last = 0, controller;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const active = () => /^#\/node\/?(?:\?|$)/.test(location.hash);
  function metric(label, value) {
    return '<div><span>' + label + '</span><strong>' + escape(value) + '</strong></div>';
  }
  function percent(value) { return value == null ? '--' : Number(value).toFixed(1) + '%'; }
  function render(rows) {
    const content = panel.querySelector('.bc-monitor-grid');
    content.innerHTML = rows.length ? rows.map(row => {
      const code = /^[A-Za-z]{2}$/.test(row.country_code || '') ? row.country_code.toLowerCase() : '';
      const system = String(row.system || '').toLowerCase();
      const icon = ['debian', 'ubuntu', 'alpine', 'centos', 'rocky', 'alma', 'fedora', 'freebsd', 'opensuse', 'windows', 'linux'].find(name => system.includes(name)) || (system.includes('arch') ? 'arch' : 'server');
      return '<article class="bc-monitor-card' + (row.is_online ? '' : ' offline') + '"><header>' +
        (code ? '<img alt="' + escape(row.country) + '" src="/assets/flags/' + code + '.svg">' : '') +
        '<img alt="' + escape(row.system) + '" title="' + escape(row.system) + '" src="/assets/os/' + icon + '.svg">' +
        '<h3>' + escape(row.name) + '</h3><span class="bc-monitor-state">' + (row.is_online ? '在线' : '离线') + '</span></header>' +
        '<div class="bc-monitor-metrics">' + metric('CPU', row.is_online ? percent(row.cpu) : '--') +
        metric('内存', row.is_online ? percent(row.mem) : '--') + metric('负载', row.is_online ? row.load : '--') +
        metric('下载', row.is_online ? row.net_in : '--') + metric('上传', row.is_online ? row.net_out : '--') +
        '</div></article>';
    }).join('') : '<p class="bc-monitor-empty">暂无可查看的主机</p>';
    panel.querySelector('.bc-monitor-summary').textContent = rows.filter(row => row.is_online).length + ' 在线 / ' + rows.length + ' 台主机';
  }
  async function load(headers) {
    if (busy || !panel || document.hidden) return;
    busy = true;
    last = Date.now();
    controller = new AbortController();
    const target = panel;
    const button = target.querySelector('button');
    button.disabled = true;
    const timeout = setTimeout(() => controller?.abort(), 12000);
    try {
      const response = await fetch('/api/v1/user/machine/monitor', { headers: headers(), signal: controller.signal, cache: 'no-store' });
      if (!response.ok) throw new Error(response.status === 403 ? '登录已失效，请重新登录' : '监控数据暂时无法读取，请重试');
      const payload = await response.json();
      if (target !== panel || !active()) return;
      render(Array.isArray(payload.data) ? payload.data : []);
      target.querySelector('.bc-monitor-error').textContent = '';
    } catch (error) {
      if (target === panel && error.name !== 'AbortError') target.querySelector('.bc-monitor-error').textContent = error.message;
      else if (target === panel) target.querySelector('.bc-monitor-error').textContent = '请求超时，请重试';
    } finally {
      clearTimeout(timeout);
      busy = false;
      button.disabled = false;
    }
  }
  window.bunCloudMachineMonitor = function (headers) {
    document.querySelectorAll('a, .n-menu-item-content-header, .n-breadcrumb-item, h1, header span').forEach(node => {
      const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
      let text;
      while ((text = walker.nextNode())) {
        if (text.nodeValue.trim() === '节点状态') text.nodeValue = text.nodeValue.replace('节点状态', '主机监控');
      }
    });
    if (!active()) {
      if (panel) panel.remove();
      if (original) original.hidden = false;
      panel = original = null;
      controller?.abort();
      return;
    }
    if (panel && !panel.isConnected) panel = null;
    if (!panel) {
      const table = Array.from(document.querySelectorAll('.n-data-table, .n-list')).find(node => node.textContent.includes('倍率'));
      if (!table) return;
      original = table;
      original.hidden = true;
      panel = document.createElement('section');
      panel.className = 'bc-machine-monitor';
      panel.innerHTML = '<div class="bc-monitor-toolbar"><h2>主机监控</h2><span class="bc-monitor-summary"></span><button type="button">刷新</button></div><p class="bc-monitor-error" role="status"></p><div class="bc-monitor-grid">正在读取监控数据…</div>';
      table.parentNode.insertBefore(panel, table);
      panel.querySelector('button').onclick = () => load(headers);
      last = 0;
    }
    const title = '主机监控 | ' + (window.settings?.title || 'BunCloud');
    if (document.title !== title) document.title = title;
    if (Date.now() - last >= 15000) load(headers);
  };
})();
