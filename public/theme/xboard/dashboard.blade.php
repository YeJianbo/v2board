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
      --bc-bg: #f2f2f2;
      --bc-bg-soft: #f6f7f7;
      --bc-panel: #ffffff;
      --bc-panel-soft: #f7f8f8;
      --bc-line: rgba(31, 34, 37, .09);
      --bc-line-strong: rgba(31, 34, 37, .14);
      --bc-text: #1f2328;
      --bc-text-soft: #6b7280;
      --bc-primary: var(--primary-color, #18a058);
      --bc-primary-strong: var(--primary-color, #18a058);
      --bc-primary-soft: rgba(24, 160, 88, .09);
      --bc-primary-border: rgba(24, 160, 88, .28);
      --bc-shadow-xs: 0 1px 2px rgba(31, 34, 37, .04);
      --bc-shadow-sm: 0 1px 2px rgba(31, 34, 37, .04);
      --bc-shadow-md: 0 6px 18px rgba(31, 34, 37, .06);
      color-scheme: light;
    }
    .bc-node-traffic-table-toolbar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      margin: 0 0 12px;
      padding: 0;
      background: transparent;
      box-shadow: none;
    }
    .bc-node-traffic-periods {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 0;
      border: 0;
      border-radius: 8px;
      background: transparent;
    }
    .bc-node-traffic-periods button {
      min-width: 58px;
      height: 32px;
      padding: 0 14px;
      border: 1px solid var(--bc-line-strong);
      border-radius: 6px;
      background: #fff;
      color: var(--bc-text-soft);
      font-size: 12px;
      line-height: 30px;
      cursor: pointer;
      transition: color .15s ease, background-color .15s ease;
    }
    .bc-node-traffic-periods button.is-active {
      background: var(--bc-primary);
      border-color: var(--bc-primary);
      color: #fff;
      box-shadow: var(--bc-shadow-xs);
    }
    table.bc-node-traffic-legacy-table {
      width: 100%;
      table-layout: auto;
    }
    table.bc-node-traffic-legacy-table th:nth-child(2),
    table.bc-node-traffic-legacy-table td:nth-child(2) {
      min-width: 180px;
      white-space: normal;
    }
    table.bc-node-traffic-legacy-table th:nth-child(3),
    table.bc-node-traffic-legacy-table td:nth-child(3) {
      min-width: 84px;
    }
    .bc-node-traffic-empty {
      color: var(--bc-text-soft);
      text-align: center;
    }
    @media (max-width: 768px) {
      .bc-node-traffic-periods {
        width: 100%;
        justify-content: flex-end;
      }
    }
  </style>
  <script>
    (function () {
      document.documentElement.classList.add('bc-user-polish')
      var menuText = '流量明细'
      var activeTitle = null
      var insertMenuTimer = 0
      var nodeTrafficPeriod = 'day'
      var nodeTrafficRequestId = 0
      var capturedAuthToken = ''
      var nodeTrafficAuthRetry = 0
      var nodeTrafficLastFailedAt = 0
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

      function extractJwt(value) {
        if (!value) return ''
        var match = String(value).match(/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/)
        return match ? match[0] : ''
      }

      function rememberAuthToken(value) {
        var jwt = extractJwt(value)
        if (jwt) capturedAuthToken = jwt
        return jwt
      }

      function captureAuthFromHeaders(headers) {
        if (!headers) return ''
        try {
          if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            return rememberAuthToken(headers.get('authorization') || headers.get('Authorization') || headers.get('auth_data') || '')
          }
          if (Array.isArray(headers)) {
            for (var i = 0; i < headers.length; i += 1) {
              if (!headers[i]) continue
              var key = String(headers[i][0] || '').toLowerCase()
              if (['authorization', 'auth_data', 'access_token'].indexOf(key) !== -1) {
                var found = rememberAuthToken(headers[i][1])
                if (found) return found
              }
            }
          } else if (typeof headers === 'object') {
            return rememberAuthToken(headers.Authorization || headers.authorization || headers.auth_data || headers.access_token || '')
          }
        } catch (error) {}
        return ''
      }

      function installAuthCapture() {
        if (window.__bcAuthCaptureInstalled) return
        window.__bcAuthCaptureInstalled = true

        var originalFetch = window.fetch
        if (typeof originalFetch === 'function') {
          window.fetch = function bcFetch(input, init) {
            try {
              if (typeof input === 'string') {
                var url = new URL(input, window.location.origin)
                rememberAuthToken(url.searchParams.get('auth_data'))
              } else if (input && input.url) {
                var requestUrl = new URL(input.url, window.location.origin)
                rememberAuthToken(requestUrl.searchParams.get('auth_data'))
                captureAuthFromHeaders(input.headers)
              }
              if (init) captureAuthFromHeaders(init.headers)
            } catch (error) {}
            return originalFetch.apply(this, arguments)
          }
        }

        var xhrProto = window.XMLHttpRequest && window.XMLHttpRequest.prototype
        if (xhrProto && xhrProto.setRequestHeader) {
          var originalSetRequestHeader = xhrProto.setRequestHeader
          xhrProto.setRequestHeader = function bcSetRequestHeader(name, value) {
            if (['authorization', 'auth_data', 'access_token'].indexOf(String(name || '').toLowerCase()) !== -1) {
              rememberAuthToken(value)
            }
            return originalSetRequestHeader.apply(this, arguments)
          }
        }
      }

      installAuthCapture()

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
        var jwt = extractJwt(value)
        if (jwt) return jwt

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
                var childJwt = extractJwt(child)
                if (childJwt && !stack.token) stack.token = childJwt
                if (['auth_data', 'authorization', 'access_token', 'user_token'].indexOf(lower) !== -1 && child.length > 20 && !stack.token) {
                  stack.token = extractJwt(child)
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

      function getNamedCookieToken() {
        var allowed = ['auth_data', 'authorization', 'access_token', 'user_token', 'Vue_Naive_access_token']
        var cookies = String(document.cookie || '').split(';')
        for (var i = 0; i < cookies.length; i += 1) {
          var pair = cookies[i].split('=')
          var key = decodeURIComponent(String(pair.shift() || '').trim())
          if (allowed.indexOf(key) === -1) continue
          var value = decodeURIComponent(pair.join('=') || '')
          var token = findTokenInValue(value)
          if (token) return rememberAuthToken(token)
        }
        return ''
      }

      function getAuthToken() {
        if (capturedAuthToken) return capturedAuthToken
        try {
          var urlToken = new URLSearchParams(window.location.search).get('auth_data')
          var urlJwt = rememberAuthToken(urlToken)
          if (urlJwt) return urlJwt
        } catch (error) {}
        var cookieToken = getNamedCookieToken()
        if (cookieToken) return cookieToken
        var stores = [window.localStorage, window.sessionStorage]
        var priorityKeys = ['auth_data', 'authorization', 'access_token', 'user_token', 'Vue_Naive_access_token']
        for (var s = 0; s < stores.length; s += 1) {
          var store = stores[s]
          for (var p = 0; p < priorityKeys.length; p += 1) {
            var direct = findTokenInValue(store.getItem(priorityKeys[p]))
            if (direct) return rememberAuthToken(direct)
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
        }, 80)
      }

      function buildNodeTrafficUrl() {
        var url = '/api/v1/user/stat/getNodeTrafficLog?period=' + encodeURIComponent(nodeTrafficPeriod) + '&include_total=1'
        return url
      }

      function getNodeTrafficHeaders(token) {
        return token ? { Authorization: token, authorization: token } : {}
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

      function formatProtocol(row) {
        var serverType = String(row.server_type || row.node_type || '').toLowerCase()
        var protocol = String(row.protocol || row.type || '').toLowerCase()
        if (!protocol || protocol === 'v2node') {
          protocol = serverType === 'v2node' ? '' : serverType
        }
        return protocol || '未知'
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
          toolbar.innerHTML = '<div class="bc-node-traffic-periods"><button type="button" data-period="day">按天</button><button type="button" data-period="hour">按小时</button><button type="button" data-period="minute">按分钟</button></div>'
          parent.insertBefore(toolbar, table)
          toolbar.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('button[data-period]') : null
            if (!button) return
            nodeTrafficPeriod = button.dataset.period || 'day'
            table.dataset.bcNodeTrafficLoaded = ''
            table.dataset.bcNodeTrafficFailed = ''
            nodeTrafficAuthRetry = 0
            patchLegacyTrafficTable(false, true)
          })
        }
        Array.prototype.slice.call(toolbar.querySelectorAll('button[data-period]')).forEach(function (button) {
          button.classList.toggle('is-active', button.dataset.period === nodeTrafficPeriod)
        })
        return toolbar
      }

      function setupNodeTrafficTable(table) {
        table.dataset.bcNodeTrafficTable = '1'
        table.classList.add('bc-node-traffic-legacy-table')
        var thead = table.tHead || table.createTHead()
        thead.innerHTML = '<tr><th>时间</th><th>节点</th><th>协议</th><th>倍率</th><th>实际上行</th><th>实际下行</th><th>实际使用</th><th>计费流量</th></tr>'
        return table.tBodies[0] || table.createTBody()
      }

      function setNodeTrafficMessage(table, message) {
        var tbody = setupNodeTrafficTable(table)
        tbody.innerHTML = '<tr><td class="bc-node-traffic-empty" colspan="8">' + escapeHtml(message || '加载中...') + '</td></tr>'
      }

      function renderNodeTrafficRows(table, payload) {
        var rows = payload && Array.isArray(payload.data) ? payload.data : []
        var meta = payload && payload.meta ? payload.meta : {}
        var tbody = setupNodeTrafficTable(table)
        if (!rows.length) {
          tbody.innerHTML = '<tr><td class="bc-node-traffic-empty" colspan="8">' + escapeHtml(meta.note || '暂无节点维度流量数据') + '</td></tr>'
          return
        }
        tbody.innerHTML = rows.map(function (row) {
          var serverType = row.server_type || row.node_type || ''
          var nodeName = row.name || '未命名节点'
          var protocol = formatProtocol(row)
          return '<tr>' +
            '<td>' + escapeHtml(formatTrafficTime(row.record_at, nodeTrafficPeriod)) + '</td>' +
            '<td title="' + escapeHtml(nodeName) + '">' + escapeHtml(nodeName) + '</td>' +
            '<td>' + escapeHtml(protocol) + '</td>' +
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
        if (table.dataset.bcNodeTrafficFailed === nodeTrafficPeriod && !forceReload && Date.now() - nodeTrafficLastFailedAt < 4000) {
          return true
        }
        table.dataset.bcNodeTrafficLoaded = ''
        table.dataset.bcNodeTrafficLoading = nodeTrafficPeriod
        table.dataset.bcNodeTrafficFailed = ''
        if (!table.dataset.bcNodeTrafficHasRows) {
          setNodeTrafficMessage(table, '加载中...')
        } else {
          setupNodeTrafficTable(table)
        }
        var requestId = ++nodeTrafficRequestId
        var token = getAuthToken()
        if (!token) {
          if (nodeTrafficAuthRetry < 20) {
            nodeTrafficAuthRetry += 1
            if (!table.dataset.bcNodeTrafficHasRows) setNodeTrafficMessage(table, '正在等待当前登录态...')
            setTimeout(function () {
              if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
              table.dataset.bcNodeTrafficLoading = ''
              patchLegacyTrafficTable(false, true)
            }, 250)
            return true
          }
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = nodeTrafficPeriod
          nodeTrafficLastFailedAt = Date.now()
          if (!table.dataset.bcNodeTrafficHasRows) setNodeTrafficMessage(table, '当前登录态读取失败，请刷新后重新登录')
          return true
        }
        nodeTrafficAuthRetry = 0
        fetch(buildNodeTrafficUrl(token), {
          headers: getNodeTrafficHeaders(token)
        }).then(function (response) {
          if (!response.ok) throw new Error('getNodeTrafficLog ' + response.status)
          return response.json()
        }).then(function (payload) {
          if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
          renderNodeTrafficRows(table, payload || {})
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = ''
          table.dataset.bcNodeTrafficLoaded = nodeTrafficPeriod
          table.dataset.bcNodeTrafficHasRows = '1'
          ensureTrafficToolbar(table)
          if (shouldScroll) table.scrollIntoView({ block: 'start', behavior: 'smooth' })
        }).catch(function (error) {
          if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = nodeTrafficPeriod
          nodeTrafficLastFailedAt = Date.now()
          if (window.console && console.warn) console.warn('[node-traffic] load failed', error)
          if (!table.dataset.bcNodeTrafficHasRows) {
            setNodeTrafficMessage(table, '节点流量明细加载失败，请刷新后重试')
          }
        })
        return true
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
