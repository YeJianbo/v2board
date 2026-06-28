<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
</head>

<body>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  <style>
    html.bc-user-polish {
      --bc-bg: #f5f3ef;
      --bc-bg-soft: #f8f7f3;
      --bc-panel: #fbfaf6;
      --bc-panel-soft: #f8f7f3;
      --bc-line: rgba(42, 37, 32, .08);
      --bc-line-strong: rgba(42, 37, 32, .14);
      --bc-text: #2a2520;
      --bc-text-soft: #7a7470;
      --bc-primary: #c94f2e;
      --bc-primary-strong: #a83d20;
      --bc-primary-soft: rgba(201, 79, 46, .08);
      --bc-primary-border: rgba(201, 79, 46, .22);
      --bc-shadow-xs: 0 1px 2px rgba(42, 37, 32, .04);
      --bc-shadow-sm: 0 1px 3px rgba(42, 37, 32, .04), 0 4px 12px rgba(42, 37, 32, .03);
      --bc-shadow-md: 0 4px 16px rgba(42, 37, 32, .06), 0 12px 32px rgba(42, 37, 32, .04);
      color-scheme: light;
    }
    .bc-node-traffic-table-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
      padding: 12px 14px;
      border: 1px solid var(--bc-line);
      border-radius: 8px;
      background: var(--bc-panel);
      box-shadow: var(--bc-shadow-sm);
    }
    .bc-node-traffic-table-title {
      margin: 0;
      color: var(--bc-text);
      font-size: 15px;
      font-weight: 700;
      line-height: 1.3;
    }
    .bc-node-traffic-table-desc {
      margin: 2px 0 0;
      color: var(--bc-text-soft);
      font-size: 12px;
      line-height: 1.5;
    }
    .bc-node-traffic-periods {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px;
      border-radius: 8px;
      background: var(--bc-panel-soft);
    }
    .bc-node-traffic-periods button {
      min-width: 54px;
      height: 30px;
      padding: 0 10px;
      border: 0;
      border-radius: 6px;
      background: transparent;
      color: var(--bc-text-soft);
      font-size: 12px;
      cursor: pointer;
    }
    .bc-node-traffic-periods button.is-active {
      background: var(--bc-primary);
      color: #fff;
      box-shadow: var(--bc-shadow-xs);
    }
    table.bc-node-traffic-legacy-table {
      table-layout: fixed;
    }
    table.bc-node-traffic-legacy-table th,
    table.bc-node-traffic-legacy-table td {
      vertical-align: middle;
      white-space: nowrap;
    }
    table.bc-node-traffic-legacy-table th:nth-child(2),
    table.bc-node-traffic-legacy-table td:nth-child(2) {
      width: 26%;
      white-space: normal;
    }
    .bc-node-traffic-node {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }
    .bc-node-traffic-node strong {
      overflow: hidden;
      color: var(--bc-text);
      font-size: 13px;
      font-weight: 700;
      line-height: 1.35;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .bc-node-traffic-node span {
      color: var(--bc-text-soft);
      font-size: 11px;
      line-height: 1.3;
    }
    .bc-node-traffic-protocol {
      display: inline-flex;
      align-items: center;
      min-height: 22px;
      padding: 0 8px;
      border-radius: 999px;
      background: var(--bc-primary-soft);
      color: var(--bc-primary-strong);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
    }
    .bc-node-traffic-empty {
      padding: 28px 12px !important;
      color: var(--bc-text-soft);
      text-align: center;
    }
    .bc-sub-import-row {
      display: flex;
      align-items: center;
      gap: 18px;
      min-height: 64px;
      padding: 10px 20px;
      color: var(--bc-text);
      cursor: pointer;
      border-top: 1px solid var(--bc-line);
      transition: background .15s ease, color .15s ease;
    }
    .bc-sub-import-row:hover {
      background: var(--bc-primary-soft);
      color: var(--bc-primary-strong);
    }
    .bc-sub-import-icon {
      display: grid;
      place-items: center;
      flex: 0 0 auto;
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: var(--bc-primary-soft);
      color: var(--bc-primary-strong);
      font-size: 12px;
      font-weight: 800;
      line-height: 1.05;
      text-align: center;
    }
    .bc-sub-import-icon img {
      display: block;
      width: 28px;
      height: 28px;
      object-fit: contain;
    }
    .bc-sub-import-main {
      min-width: 0;
      font-size: 16px;
      line-height: 1.35;
    }
    .bc-sub-import-main small {
      display: block;
      margin-top: 2px;
      color: var(--bc-text-soft);
      font-size: 12px;
    }
    @media (max-width: 768px) {
      .bc-node-traffic-table-toolbar {
        align-items: stretch;
        flex-direction: column;
      }
      .bc-node-traffic-periods {
        justify-content: space-between;
      }
    }
  </style>
  <script>
    (function () {
      document.documentElement.classList.add('bc-user-polish')
      var menuText = '流量明细'
      var activeTitle = null
      var insertMenuTimer = 0
      var subscribeDataPromise = null
      var nodeTrafficPeriod = 'day'
      var nodeTrafficRequestId = 0
      var titleCandidates = [
        '仪表盘',
        '使用文档',
        '我的订单',
        '我的邀请',
        '购买订阅',
        '节点状态',
        '个人中心',
        '我的工单',
        '流量明细'
      ]

      function textOf(node) {
        return (node && node.textContent ? node.textContent : '').replace(/\s+/g, '')
      }

      function findTrafficMenu() {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('a, li, div, span'))
        var matches = nodes.filter(function (node) {
          if (textOf(node) !== '流量明细') return false
          var rect = node.getBoundingClientRect()
          return rect.width > 0 && rect.height > 0
        })

        return matches.find(function (node) {
          var rect = node.getBoundingClientRect()
          return rect.left < 280 && rect.top > 50
        }) || matches[0]
      }

      function clickableRoot(node) {
        var current = node
        for (var i = 0; current && i < 5; i += 1) {
          if (current.tagName === 'A' || current.tagName === 'LI' || current.getAttribute('role') === 'menuitem') {
            return current
          }
          current = current.parentElement
        }
        return node
      }

      function replaceMenuText(node, text) {
        var candidates = Array.prototype.slice.call(node.querySelectorAll('span, div, a'))
        var target = candidates.find(function (item) {
          return textOf(item) === '流量明细'
        })
        if (!target) target = node
        target.textContent = text
      }

      function resetClonedMenuState(node) {
        var statePattern = /(^|[-_])(selected|active|open|current)([-_]|$)/
        Array.prototype.slice.call(node.querySelectorAll('*')).concat([node]).forEach(function (item) {
          Array.prototype.slice.call(item.classList || []).forEach(function (className) {
            if (statePattern.test(className)) item.classList.remove(className)
          })
          item.removeAttribute('aria-current')
          item.removeAttribute('aria-selected')
          item.removeAttribute('data-active')
          item.removeAttribute('data-selected')
        })
      }

      function ensureNodeTrafficInline(attempt, shouldScroll) {
        if (isTrafficDetailPage()) {
          patchLegacyTrafficTable(!!shouldScroll)
          return
        }
        if (attempt >= 2) {
          return
        }
        setTimeout(function () {
          ensureNodeTrafficInline(attempt + 1, shouldScroll)
        }, 120)
      }

      function findTopTitle() {
        if (activeTitle && document.documentElement.contains(activeTitle)) return activeTitle
        var layout = measureLayout()
        var nodes = Array.prototype.slice.call(document.querySelectorAll('a, div, span, h1, h2'))
        return nodes.filter(function (node) {
          var text = textOf(node)
          if (titleCandidates.indexOf(text) === -1) return false
          var rect = node.getBoundingClientRect()
          return rect.width > 0 &&
            rect.height > 0 &&
            rect.left >= layout.left - 8 &&
            rect.left < layout.left + 380 &&
            rect.top >= 0 &&
            rect.top < layout.top + 8
        }).sort(function (a, b) {
          var ar = a.getBoundingClientRect()
          var br = b.getBoundingClientRect()
          return (ar.top - br.top) || (ar.left - br.left)
        }).pop()
      }

      function findLegacyTrafficTable() {
        var tables = Array.prototype.slice.call(document.querySelectorAll('table'))
        return tables.find(function (table) {
          var rect = table.getBoundingClientRect()
          if (!rect.width || !rect.height) return false
          if (table.dataset && table.dataset.bcNodeTrafficTable === '1') return true
          var text = textOf(table)
          return text.indexOf('记录时间') !== -1 &&
            text.indexOf('实际上行') !== -1 &&
            text.indexOf('实际下行') !== -1 &&
            text.indexOf('扣费倍率') !== -1
        }) || null
      }

      function findLegacyTrafficRoot() {
        var table = findLegacyTrafficTable()
        if (!table) return null
        var layout = measureLayout()
        var current = table.parentElement
        var best = null
        for (var i = 0; current && current !== document.body && i < 10; i += 1) {
          var rect = current.getBoundingClientRect()
          if (rect.width >= 420 &&
            rect.height >= 220 &&
            rect.left >= layout.left - 24 &&
            rect.top >= layout.top - 36 &&
            !(current.closest && current.closest('aside, nav'))) {
            best = current
          }
          current = current.parentElement
        }
        return best
      }

      function isTrafficDetailPage() {
        var title = findTopTitle()
        if (title && textOf(title).indexOf('流量明细') !== -1) return true
        return !!findLegacyTrafficTable()
      }

      function syncMenuState(active) {
        Array.prototype.slice.call(document.querySelectorAll('.n-menu-item-content--selected')).forEach(function (node) {
          if (!node.dataset.bcWasSelected) node.dataset.bcWasSelected = '1'
        })
        if (!active) {
          Array.prototype.slice.call(document.querySelectorAll('[data-bc-was-selected]')).forEach(function (node) {
            delete node.dataset.bcWasSelected
          })
        }
      }

      function setTopTitleActive(active) {
        var title = findTopTitle()
        if (!title) return
        if (active) {
          activeTitle = title
          if (!title.dataset.bcOriginalText) title.dataset.bcOriginalText = title.textContent
          if (title.textContent !== menuText) title.textContent = menuText
        } else if (title.dataset.bcOriginalText) {
          if (title.textContent !== title.dataset.bcOriginalText) title.textContent = title.dataset.bcOriginalText
          delete title.dataset.bcOriginalText
          activeTitle = null
        }
      }

      function findTokenInValue(value) {
        if (!value) return ''
        var jwt = String(value).match(/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/)
        if (jwt) return jwt[0]

        try {
          var parsed = typeof value === 'string' ? JSON.parse(value) : value
          var stack = [parsed]
          while (stack.length) {
            var item = stack.pop()
            if (!item || typeof item !== 'object') continue
            Object.keys(item).forEach(function (key) {
              var child = item[key]
              var lower = key.toLowerCase()
              if (typeof child === 'string') {
                var childJwt = child.match(/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/)
                if (childJwt && !stack.token) stack.token = childJwt[0]
                if (['auth_data', 'authorization', 'token', 'access_token'].indexOf(lower) !== -1 && child.length > 20 && !stack.token) {
                  stack.token = child
                }
              } else if (child && typeof child === 'object') {
                stack.push(child)
              }
            })
            if (stack.token) return stack.token
          }
        } catch (error) {}

        return ''
      }

      function getAuthToken() {
        try {
          var urlToken = new URLSearchParams(window.location.search).get('auth_data')
          if (urlToken) return urlToken
        } catch (error) {}
        var stores = [window.localStorage, window.sessionStorage]
        var priorityKeys = ['Vue_Naive_access_token', 'access_token', 'auth_data', 'authorization', 'token', 'user_token']
        for (var s = 0; s < stores.length; s += 1) {
          var store = stores[s]
          for (var p = 0; p < priorityKeys.length; p += 1) {
            var direct = findTokenInValue(store.getItem(priorityKeys[p]))
            if (direct) return direct
          }
          for (var i = 0; i < store.length; i += 1) {
            var key = store.key(i)
            var token = findTokenInValue(store.getItem(key))
            if (token) return token
          }
        }
        return ''
      }

      function schedulePatch() {
        if (insertMenuTimer) return
        insertMenuTimer = window.setTimeout(function () {
          insertMenuTimer = 0
          removeLegacyNodeTrafficMenu()
          ensureNodeTrafficInline(0, false)
          patchSubscribeImports()
        }, 80)
      }

      function getNodeTrafficAuth() {
        var token = getAuthToken()
        return token ? { Authorization: token } : {}
      }

      function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
          return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
          }[char]
        })
      }

      function formatBytes(value) {
        var bytes = Number(value || 0)
        if (!isFinite(bytes) || bytes <= 0) return '0 B'
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
        var index = 0
        while (bytes >= 1024 && index < units.length - 1) {
          bytes /= 1024
          index += 1
        }
        return (bytes >= 100 || index === 0 ? bytes.toFixed(0) : bytes.toFixed(2)).replace(/\.00$/, '') + ' ' + units[index]
      }

      function formatRate(value) {
        var rate = Number(value)
        if (!isFinite(rate)) return '-'
        return rate.toFixed(rate % 1 === 0 ? 0 : 2) + 'x'
      }

      function formatTrafficTime(value, period) {
        var timestamp = Number(value || 0)
        if (!timestamp) return '-'
        var date = new Date(timestamp * 1000)
        var pad = function (number) {
          return number < 10 ? '0' + number : String(number)
        }
        var day = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
        if (period === 'day') return day
        return day + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes())
      }

      function ensureTrafficToolbar(table) {
        var parent = table.parentElement
        if (!parent) return null
        var toolbar = Array.prototype.slice.call(parent.children).find(function (child) {
          return child.classList && child.classList.contains('bc-node-traffic-table-toolbar')
        })
        if (!toolbar) {
          toolbar = document.createElement('div')
          toolbar.className = 'bc-node-traffic-table-toolbar'
          toolbar.innerHTML = '<div><p class="bc-node-traffic-table-title">节点流量明细</p><p class="bc-node-traffic-table-desc">直接按节点列出实际用量、倍率和计费流量。</p></div><div class="bc-node-traffic-periods"><button type="button" data-period="day">按天</button><button type="button" data-period="hour">按小时</button><button type="button" data-period="minute">按分钟</button></div>'
          parent.insertBefore(toolbar, table)
          toolbar.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('button[data-period]') : null
            if (!button) return
            nodeTrafficPeriod = button.dataset.period || 'day'
            table.dataset.bcNodeTrafficLoaded = ''
            patchLegacyTrafficTable(false, true)
          })
        }
        Array.prototype.slice.call(toolbar.querySelectorAll('button[data-period]')).forEach(function (button) {
          button.classList.toggle('is-active', button.dataset.period === nodeTrafficPeriod)
        })
        return toolbar
      }

      function setNodeTrafficLoading(table, message) {
        table.dataset.bcNodeTrafficTable = '1'
        table.classList.add('bc-node-traffic-legacy-table')
        var thead = table.tHead || table.createTHead()
        var tbody = table.tBodies[0] || table.createTBody()
        thead.innerHTML = '<tr><th>时间</th><th>节点</th><th>协议</th><th>倍率</th><th>实际上行</th><th>实际下行</th><th>实际使用</th><th>计费流量</th></tr>'
        tbody.innerHTML = '<tr><td class="bc-node-traffic-empty" colspan="8">' + escapeHtml(message || '加载中...') + '</td></tr>'
      }

      function renderNodeTrafficRows(table, payload) {
        var rows = payload && Array.isArray(payload.data) ? payload.data : []
        var meta = payload && payload.meta ? payload.meta : {}
        var tbody = table.tBodies[0] || table.createTBody()
        if (!rows.length) {
          tbody.innerHTML = '<tr><td class="bc-node-traffic-empty" colspan="8">' + escapeHtml(meta.note || '暂无节点维度流量数据') + '</td></tr>'
          return
        }
        tbody.innerHTML = rows.map(function (row) {
          var serverType = row.server_type || row.node_type || ''
          var nodeId = row.server_id || row.id || ''
          var nodeName = row.name || ('Node ' + nodeId)
          var protocol = row.type || serverType || '-'
          return '<tr>' +
            '<td>' + escapeHtml(formatTrafficTime(row.record_at, nodeTrafficPeriod)) + '</td>' +
            '<td><div class="bc-node-traffic-node"><strong title="' + escapeHtml(nodeName) + '">' + escapeHtml(nodeName) + '</strong><span>' + escapeHtml(serverType + (nodeId ? ':' + nodeId : '')) + '</span></div></td>' +
            '<td><span class="bc-node-traffic-protocol">' + escapeHtml(protocol) + '</span></td>' +
            '<td>' + escapeHtml(formatRate(row.rate)) + '</td>' +
            '<td>' + escapeHtml(formatBytes(row.u)) + '</td>' +
            '<td>' + escapeHtml(formatBytes(row.d)) + '</td>' +
            '<td>' + escapeHtml(formatBytes(row.total || (Number(row.u || 0) + Number(row.d || 0)))) + '</td>' +
            '<td>' + escapeHtml(formatBytes(row.cost)) + '</td>' +
            '</tr>'
        }).join('')
      }

      function patchLegacyTrafficTable(shouldScroll, forceReload) {
        var table = findLegacyTrafficTable()
        if (!table) return false
        syncMenuState(true)
        setTopTitleActive(true)
        ensureTrafficToolbar(table)
        if (table.dataset.bcNodeTrafficLoaded === nodeTrafficPeriod && !forceReload) {
          return true
        }
        if (table.dataset.bcNodeTrafficLoading === nodeTrafficPeriod && !forceReload) {
          return true
        }
        table.dataset.bcNodeTrafficLoaded = ''
        table.dataset.bcNodeTrafficLoading = nodeTrafficPeriod
        setNodeTrafficLoading(table, '加载节点流量明细...')
        var requestId = ++nodeTrafficRequestId
        fetch('/api/v1/user/stat/getNodeTrafficLog?period=' + encodeURIComponent(nodeTrafficPeriod) + '&include_total=1', {
          headers: getNodeTrafficAuth()
        }).then(function (response) {
          if (!response.ok) throw new Error('getNodeTrafficLog ' + response.status)
          return response.json()
        }).then(function (payload) {
          if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
          setNodeTrafficLoading(table, '加载节点流量明细...')
          renderNodeTrafficRows(table, payload || {})
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficLoaded = nodeTrafficPeriod
          ensureTrafficToolbar(table)
          if (shouldScroll) table.scrollIntoView({ block: 'start', behavior: 'smooth' })
        }).catch(function () {
          if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
          table.dataset.bcNodeTrafficLoading = ''
          setNodeTrafficLoading(table, '节点流量明细加载失败，请刷新后重试')
        })
        return true
      }

      function getSubscribeData() {
        if (subscribeDataPromise) return subscribeDataPromise
        var token = getAuthToken()
        if (!token) return Promise.resolve(null)
        subscribeDataPromise = fetch('/api/v1/user/getSubscribe', {
          headers: { Authorization: token }
        }).then(function (response) {
          if (!response.ok) throw new Error('getSubscribe ' + response.status)
          return response.json()
        }).then(function (payload) {
          return payload && payload.data ? payload.data : null
        }).catch(function () {
          subscribeDataPromise = null
          return null
        })
        return subscribeDataPromise
      }

      function importRows(subscribeUrl) {
        var encodedUrl = encodeURIComponent(subscribeUrl)
        var title = encodeURIComponent((window.settings && window.settings.title) || document.title || 'Subscription')
        return [
          {
            id: 'clash-verge',
            icon: '<img src="/theme/xboard/assets/images/clash-verge-rev.png" alt="Clash Verge Rev">',
            title: '导入到 Clash Verge Rev',
            desc: 'Windows/macOS/Linux，推荐 Clash Verge Rev，兼容 Mihomo Party 等',
            url: 'clash://install-config?url=' + encodedUrl + '&name=' + title
          },
          {
            id: 'singbox',
            icon: 'SB',
            title: '导入到 sing-box / Hiddify Next',
            desc: '适合 Hiddify Next、SFI、SFA 等 sing-box 系客户端',
            url: 'sing-box://import-remote-profile?url=' + encodedUrl + '#' + title
          },
          {
            id: 'hiddify',
            icon: 'HD',
            title: '导入到 Hiddify',
            desc: '兼容 Hiddify 自有导入协议',
            url: 'hiddify://import/' + encodedUrl + '#' + title
          },
          {
            id: 'nekobox',
            icon: 'NK',
            title: '导入到 NekoBox Android',
            desc: 'Android NekoBox 订阅分组导入',
            url: 'nekobox://install-config?name=' + title + '&type=SUBSCRIPTION&AUTOUPDATE=true&updatetime=1440&url=' + encodedUrl
          }
        ]
      }

      function findSubscribePanel() {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('div, section, article'))
        var matches = nodes.filter(function (node) {
          var text = textOf(node)
          return text.indexOf('复制订阅地址') !== -1 && text.indexOf('扫描二维码订阅') !== -1
        }).sort(function (a, b) {
          var ar = a.getBoundingClientRect()
          var br = b.getBoundingClientRect()
          return (ar.width * ar.height) - (br.width * br.height)
        })
        return matches.find(function (node) {
          var rect = node.getBoundingClientRect()
          return rect.width >= 220 && rect.height >= 120 && rect.width <= 560
        }) || null
      }

      function patchSubscribeImports() {
        var panel = findSubscribePanel()
        if (!panel || panel.dataset.bcImportEnhanced === '1') return
        panel.dataset.bcImportEnhanced = '1'
        getSubscribeData().then(function (data) {
          if (!data || !data.subscribe_url || !document.documentElement.contains(panel)) {
            if (panel) delete panel.dataset.bcImportEnhanced
            return
          }
          importRows(data.subscribe_url).forEach(function (item) {
            if (panel.querySelector('[data-bc-import-id="' + item.id + '"]')) return
            var row = document.createElement('div')
            row.className = 'bc-sub-import-row'
            row.dataset.bcImportId = item.id
            row.innerHTML = '<span class="bc-sub-import-icon">' + item.icon + '</span><span class="bc-sub-import-main">' + item.title + '<small>' + item.desc + '</small></span>'
            row.addEventListener('click', function (event) {
              event.preventDefault()
              event.stopPropagation()
              window.location.href = item.url
            })
            var footer = Array.prototype.slice.call(panel.querySelectorAll('button, a')).find(function (node) {
              var text = textOf(node)
              return text.indexOf('不会使用') !== -1 || text.indexOf('查看使用教程') !== -1
            })
            var footerBlock = footer
            while (footerBlock && footerBlock.parentElement && footerBlock.parentElement !== panel) {
              footerBlock = footerBlock.parentElement
            }
            if (footerBlock && footerBlock.parentElement === panel) {
              panel.insertBefore(row, footerBlock)
            } else {
              panel.appendChild(row)
            }
          })
        })
      }

      function measureLayout() {
        if (window.innerWidth <= 768) {
          return { left: 0, top: 56 }
        }

        var sidebarRight = 274
        var headerBottom = 74
        var nodes = Array.prototype.slice.call(document.body.querySelectorAll('aside, nav, header, div, section'))
        nodes.slice(0, 600).forEach(function (node) {
          var rect = node.getBoundingClientRect()
          if (!rect.width || !rect.height) return
          if (rect.left <= 2 && rect.width >= 180 && rect.width <= 360 && rect.height > window.innerHeight * 0.55) {
            sidebarRight = Math.max(sidebarRight, Math.round(rect.right))
          }
        })
        nodes.slice(0, 600).forEach(function (node) {
          var rect = node.getBoundingClientRect()
          if (!rect.width || !rect.height) return
          if (rect.top <= 2 && rect.left >= sidebarRight - 4 && rect.height >= 48 && rect.height <= 96) {
            headerBottom = Math.max(headerBottom, Math.round(rect.bottom))
          }
        })
        return { left: sidebarRight, top: headerBottom }
      }

      function handleRouteChange() {
        setTopTitleActive(false)
        syncMenuState(false)
        schedulePatch()
      }

      function removeLegacyNodeTrafficMenu() {
        Array.prototype.slice.call(document.querySelectorAll('.bc-node-traffic-menu')).forEach(function (node) {
          node.remove()
        })
      }

      var observer = new MutationObserver(schedulePatch)
      observer.observe(document.documentElement, { childList: true, subtree: true })
      window.addEventListener('hashchange', handleRouteChange)
      window.addEventListener('resize', schedulePatch)
      window.addEventListener('load', schedulePatch)
      setTimeout(schedulePatch, 800)
      setTimeout(schedulePatch, 2000)
    })()
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
