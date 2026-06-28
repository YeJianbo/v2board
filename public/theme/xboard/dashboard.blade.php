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
      margin: 0 0 10px;
      padding: 0;
      background: transparent;
      box-shadow: none;
    }
    .bc-node-traffic-range {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .bc-node-traffic-range input {
      width: 162px;
      height: 32px;
      box-sizing: border-box;
      padding: 0 9px;
      border: 1px solid var(--bc-line-strong);
      border-radius: 6px;
      background: #fff;
      color: var(--bc-text);
      font-size: 12px;
      line-height: 30px;
      outline: none;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .bc-node-traffic-range input:hover,
    .bc-node-traffic-range input:focus {
      border-color: var(--bc-primary-border);
      box-shadow: 0 0 0 2px var(--bc-primary-soft);
    }
    .bc-node-traffic-range span {
      color: var(--bc-text-soft);
      font-size: 12px;
    }
    .bc-node-traffic-range button {
      height: 32px;
      box-sizing: border-box;
      padding: 0 12px;
      border: 1px solid var(--bc-line-strong);
      border-radius: 6px;
      background: #fff;
      color: var(--bc-text-soft);
      font-size: 12px;
      line-height: 30px;
      cursor: pointer;
      transition: color .15s ease, background-color .15s ease, border-color .15s ease;
    }
    .bc-node-traffic-range button:hover,
    .bc-node-traffic-range button:focus-visible {
      border-color: var(--bc-primary-border);
      background: var(--bc-primary-soft);
      color: var(--bc-primary);
      outline: none;
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
      box-sizing: border-box;
      padding: 0 14px;
      border: 1px solid var(--bc-line-strong);
      border-radius: 6px;
      background: #fff;
      color: var(--bc-text-soft);
      font-size: 12px;
      line-height: 30px;
      cursor: pointer;
      transition: color .15s ease, background-color .15s ease, border-color .15s ease, box-shadow .15s ease;
    }
    .bc-node-traffic-periods button:hover:not(.is-active),
    .bc-node-traffic-periods button:focus-visible:not(.is-active) {
      border-color: var(--bc-primary-border);
      background: var(--bc-primary-soft);
      color: var(--bc-primary);
      outline: none;
    }
    .bc-node-traffic-periods button.is-active {
      background: var(--bc-primary);
      border-color: var(--bc-primary);
      color: #fff;
      box-shadow: var(--bc-shadow-xs);
    }
    .bc-node-traffic-periods button.is-active:hover,
    .bc-node-traffic-periods button.is-active:focus-visible {
      background: var(--bc-primary-strong);
      border-color: var(--bc-primary-strong);
      outline: none;
    }
    table.bc-node-traffic-legacy-table {
      width: 100%;
      table-layout: auto;
    }
    html.bc-node-traffic-route table:not(.bc-node-traffic-legacy-table) tbody,
    html.bc-node-traffic-route table:not(.bc-node-traffic-legacy-table) .n-data-table-tbody {
      opacity: 0;
    }
    table.bc-node-traffic-legacy-table th:nth-child(1) .n-data-table-th__title,
    table.bc-node-traffic-legacy-table td:nth-child(1) .n-data-table-td__content {
      white-space: nowrap;
    }
    table.bc-node-traffic-legacy-table th:nth-child(2) .n-data-table-th__title,
    table.bc-node-traffic-legacy-table td:nth-child(2) .n-data-table-td__content {
      min-width: 180px;
      white-space: normal;
    }
    table.bc-node-traffic-legacy-table th:nth-child(3) .n-data-table-th__title,
    table.bc-node-traffic-legacy-table td:nth-child(3) .n-data-table-td__content {
      min-width: 72px;
    }
    .bc-node-traffic-empty {
      color: var(--bc-text-soft);
      text-align: center;
    }
    .bc-node-traffic-loading {
      height: 112px;
      text-align: center;
    }
    .bc-node-traffic-loading .n-data-table-td__content {
      justify-content: center;
    }
    .bc-node-traffic-spinner {
      display: inline-flex;
      width: 26px;
      height: 26px;
      border: 2px solid rgba(24, 160, 88, .16);
      border-top-color: var(--bc-primary);
      border-radius: 999px;
      animation: bc-node-traffic-spin .75s linear infinite;
    }
    @keyframes bc-node-traffic-spin {
      to {
        transform: rotate(360deg);
      }
    }
    .bc-node-traffic-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      height: 22px;
      padding: 0 8px;
      border: 1px solid rgba(31, 34, 37, .14);
      border-radius: 4px;
      background: #fff;
      color: var(--bc-text);
      font-size: 12px;
      line-height: 20px;
      white-space: nowrap;
      box-shadow: none;
    }
    .bc-node-traffic-pill--protocol {
      color: var(--bc-primary);
      border-color: rgba(24, 160, 88, .34);
      background: rgba(24, 160, 88, .035);
      font-weight: 500;
      text-transform: none !important;
    }
    .bc-node-traffic-pill--rate {
      min-width: 54px;
      color: var(--bc-text);
      border-color: rgba(31, 34, 37, .16);
      background: #fff;
    }
    .bc-node-traffic-pagination {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      min-height: 32px;
      margin: 10px 0 0;
      color: var(--bc-text-soft);
      font-size: 13px;
      line-height: 32px;
    }
    .bc-node-traffic-pagination button {
      min-width: 32px;
      height: 32px;
      box-sizing: border-box;
      padding: 0 10px;
      border: 1px solid var(--bc-line-strong);
      border-radius: 6px;
      background: #fff;
      color: var(--bc-text);
      font-size: 13px;
      line-height: 30px;
      cursor: pointer;
      transition: color .15s ease, background-color .15s ease, border-color .15s ease;
      vertical-align: top;
    }
    .bc-node-traffic-pagination button:hover:not(:disabled):not(.is-active),
    .bc-node-traffic-pagination button:focus-visible:not(:disabled):not(.is-active) {
      border-color: var(--bc-primary-border);
      background: var(--bc-primary-soft);
      color: var(--bc-primary);
      outline: none;
    }
    .bc-node-traffic-pagination button.is-active {
      border-color: var(--bc-primary);
      background: var(--bc-primary);
      color: #fff;
    }
    .bc-node-traffic-pagination button.is-active:hover,
    .bc-node-traffic-pagination button.is-active:focus-visible {
      border-color: var(--bc-primary-strong);
      background: var(--bc-primary-strong);
      outline: none;
    }
    .bc-node-traffic-pagination button:disabled {
      cursor: not-allowed;
      opacity: .45;
    }
    .bc-node-traffic-page-info {
      margin-right: 8px;
      line-height: 32px;
      white-space: nowrap;
    }
    .bc-node-traffic-page-gap {
      min-width: 18px;
      line-height: 32px;
      text-align: center;
      color: var(--bc-text-soft);
    }
    .bc-subscribe-client-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: var(--bc-primary-soft);
      color: var(--bc-primary);
      line-height: 1;
      overflow: hidden;
    }
    .bc-subscribe-client-badge img {
      display: block;
      width: 24px;
      height: 24px;
      object-fit: contain;
    }
    .bc-subscribe-import-row {
      border-radius: 6px;
      transition: background-color .15s ease, color .15s ease;
    }
    .bc-subscribe-import-row:hover,
    .bc-subscribe-import-row:focus-visible {
      background: var(--bc-primary-soft);
      outline: none;
    }
    .bc-docs-panel {
      margin: 16px 0 24px;
      color: var(--bc-text);
    }
    .bc-docs-hero {
      margin-bottom: 14px;
      padding: 18px 20px;
      border: 1px solid var(--bc-border);
      border-radius: 12px;
      background: var(--bc-surface);
      box-shadow: 0 10px 28px rgba(15, 23, 42, .06);
    }
    .bc-docs-kicker {
      margin-bottom: 6px;
      color: var(--bc-primary);
      font-size: 12px;
      font-weight: 700;
    }
    .bc-docs-title {
      margin: 0 0 8px;
      font-size: 20px;
      font-weight: 700;
      line-height: 1.35;
    }
    .bc-docs-lead {
      margin: 0;
      color: var(--bc-text-soft);
      font-size: 14px;
      line-height: 1.75;
    }
    .bc-docs-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }
    .bc-docs-card {
      padding: 16px;
      border: 1px solid var(--bc-border);
      border-radius: 12px;
      background: var(--bc-surface);
      box-shadow: 0 8px 22px rgba(15, 23, 42, .05);
    }
    .bc-docs-card h3 {
      margin: 0 0 10px;
      font-size: 15px;
      font-weight: 700;
      line-height: 1.4;
    }
    .bc-docs-card ol,
    .bc-docs-card ul {
      margin: 0;
      padding-left: 18px;
      color: var(--bc-text-soft);
      font-size: 13px;
      line-height: 1.75;
    }
    .bc-docs-card li + li {
      margin-top: 5px;
    }
    .bc-docs-tag-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 12px;
    }
    .bc-docs-tag {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 3px 8px;
      border: 1px solid var(--bc-primary-soft);
      border-radius: 999px;
      background: rgba(34, 197, 94, .08);
      color: var(--bc-primary-strong);
      font-size: 12px;
      font-weight: 600;
      line-height: 1.2;
    }
    @media (max-width: 768px) {
      .bc-node-traffic-table-toolbar {
        align-items: flex-end;
        flex-direction: column;
      }
      .bc-node-traffic-periods {
        width: 100%;
        justify-content: flex-end;
      }
      .bc-node-traffic-range {
        width: 100%;
      }
      .bc-node-traffic-range input {
        flex: 1 1 150px;
        min-width: 0;
      }
      .bc-node-traffic-pagination {
        justify-content: flex-start;
        overflow-x: auto;
      }
      .bc-docs-grid {
        grid-template-columns: minmax(0, 1fr);
      }
      .bc-docs-hero,
      .bc-docs-card {
        border-radius: 10px;
        padding: 14px;
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
      var subscribePatchTimer = 0
      var docsPatchTimer = 0
      var nodeTrafficRenderCache = {}
      var nodeTrafficPayloadCache = {}
      var nodeTrafficPageMap = {}
      var nodeTrafficPageSize = 10
      var nodeTrafficStartAt = ''
      var nodeTrafficEndAt = ''
      var nodeTrafficPatchVersion = '20260628-traffic-range-filter'
      var subscribeInfoCache = null
      var subscribeInfoCacheExpiresAt = 0
      var subscribeInfoLoading = null
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
        if (!isTrafficRoute()) {
          removeNodeTrafficInlineControls()
          return
        }
        updateTrafficRouteClass()

        var patched = patchLegacyTrafficTable(!!shouldScroll)
        if (patched) {
          return
        }

        if (attempt >= 30) {
          return
        }
        setTimeout(function () {
          ensureNodeTrafficInline(attempt + 1, shouldScroll)
        }, 150)
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

      function isTrafficRoute() {
        var hash = String(window.location.hash || '')
        var path = String(window.location.pathname || '')
        return hash.indexOf('/traffic') !== -1 || path === '/traffic'
      }

      function updateTrafficRouteClass() {
        document.documentElement.classList.toggle('bc-node-traffic-route', isTrafficRoute())
      }

      function removeNodeTrafficInlineControls() {
        Array.prototype.slice.call(document.querySelectorAll('.bc-node-traffic-table-toolbar, .bc-node-traffic-pagination')).forEach(function (node) {
          node.remove()
        })
      }

      function removeTrafficNotice() {
        Array.prototype.slice.call(document.querySelectorAll('div, section')).forEach(function (node) {
          if (node.dataset && node.dataset.bcTrafficNoticeRemoved) return
          var text = textOf(node)
          if (text.indexOf('流量明细仅保留近一个月数据以供查询') === -1) return
          var rect = node.getBoundingClientRect()
          if (!rect.width || !rect.height || rect.height > 140) return
          node.dataset.bcTrafficNoticeRemoved = '1'
          node.style.display = 'none'
        })
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
        var priorityKeys = ['auth_data', 'authorization', 'access_token', 'user_token', 'Vue_Naive_access_token', 'VUE_NAIVE_ACCESS_TOKEN']
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
        updateTrafficRouteClass()
        insertMenuTimer = window.setTimeout(function () {
          insertMenuTimer = 0
          if (!isTrafficRoute()) {
            removeNodeTrafficInlineControls()
            updateTrafficRouteClass()
            return
          }
          removeLegacyNodeTrafficMenu()
          ensureNodeTrafficInline(0, false)
        }, isTrafficRoute() ? 16 : 80)
      }

      function buildNodeTrafficUrl() {
        var url = '/api/v1/user/stat/getNodeTrafficLog?period=' + encodeURIComponent(nodeTrafficPeriod) + '&include_total=1'
        var startAt = getNodeTrafficTimestamp(nodeTrafficStartAt)
        var endAt = getNodeTrafficTimestamp(nodeTrafficEndAt)
        if (startAt) url += '&start_at=' + encodeURIComponent(startAt)
        if (endAt) url += '&end_at=' + encodeURIComponent(endAt)
        return url
      }

      function getNodeTrafficHeaders(token) {
        return token ? { authorization: token } : {}
      }

      function getAuthHeaders() {
        var token = getAuthToken()
        return token ? { authorization: token } : {}
      }

      function getUserSubscribeInfo() {
        var now = Date.now()
        if (subscribeInfoCache && (!subscribeInfoCache.subscribe_url_dynamic || now < subscribeInfoCacheExpiresAt - 15000)) {
          return Promise.resolve(subscribeInfoCache)
        }
        if (subscribeInfoLoading) return subscribeInfoLoading
        subscribeInfoLoading = fetch('/api/v1/user/getSubscribe', {
          headers: getAuthHeaders()
        }).then(function (response) {
          if (!response.ok) throw new Error('getSubscribe ' + response.status)
          return response.json()
        }).then(function (payload) {
          subscribeInfoCache = payload && payload.data ? payload.data : null
          if (subscribeInfoCache && subscribeInfoCache.subscribe_url_dynamic) {
            var ttl = Number(subscribeInfoCache.subscribe_url_expire_seconds || 300)
            subscribeInfoCacheExpiresAt = Date.now() + Math.max(60, ttl) * 1000
          } else {
            subscribeInfoCacheExpiresAt = Number.POSITIVE_INFINITY
          }
          subscribeInfoLoading = null
          return subscribeInfoCache
        }).catch(function (error) {
          subscribeInfoLoading = null
          if (window.console && console.warn) console.warn('[subscribe-import] load failed', error)
          throw error
        })
        return subscribeInfoLoading
      }

      function buildImportUrl(client, subscribeUrl) {
        var title = encodeURIComponent((window.settings && window.settings.title) || document.title || 'BunCloud')
        var encodedUrl = encodeURIComponent(subscribeUrl || '')
        if (client === 'cmfa') return 'clashmeta://install-config?url=' + encodedUrl + '&name=' + title
        if (client === 'nekobox') return 'nekobox://import?url=' + encodedUrl + '&name=' + title
        if (client === 'surfboard') return 'surfboard:///install-config?url=' + encodedUrl + '&name=' + title
        if (client === 'flclash') return 'flclash://install-config?url=' + encodedUrl + '&name=' + title
        if (client === 'v2rayn') return 'v2rayn://install-config?url=' + encodedUrl
        if (client === 'singbox') return 'sing-box://import-remote-profile?url=' + encodedUrl + '&name=' + title
        if (client === 'verge') return 'clash://install-config?url=' + encodedUrl + '&name=' + title
        return ''
      }

      function openSubscribeClient(client) {
        getUserSubscribeInfo().then(function (info) {
          var subscribeUrl = info && info.subscribe_url ? info.subscribe_url : ''
          var importUrl = buildImportUrl(client, subscribeUrl)
          if (importUrl) window.location.href = importUrl
        })
      }

      function getSubscribePlatform() {
        var ua = String(navigator.userAgent || '').toLowerCase()
        var platform = String(navigator.platform || '').toLowerCase()
        if (ua.indexOf('android') !== -1) return 'android'
        if (ua.indexOf('windows') !== -1 || platform.indexOf('win') === 0) return 'windows'
        return 'other'
      }

      function getSubscribeClients() {
        var android = [
          { label: 'CMFA', client: 'cmfa' },
          { label: 'NekoBox', client: 'nekobox' },
          { label: 'Surfboard', client: 'surfboard' },
          { label: 'FlClash', client: 'flclash' }
        ]
        var windows = [
          { label: 'v2rayN', client: 'v2rayn' },
          { label: 'sing-box', client: 'singbox' },
          { label: 'FlClash', client: 'flclash' },
          { label: 'Clash Verge Rev', client: 'verge' }
        ]
        var platform = getSubscribePlatform()
        if (platform === 'android') return android
        if (platform === 'windows') return windows
        return windows.concat(android.filter(function (item) {
          return item.client !== 'flclash'
        }))
      }

      function getSubscribeClientIcon(client, label) {
        var icons = {
          cmfa: '/theme/xboard/assets/images/clients/cmfa.png',
          nekobox: '/theme/xboard/assets/images/clients/nekobox.png',
          surfboard: '/theme/xboard/assets/images/clients/surfboard.png',
          flclash: '/theme/xboard/assets/images/clients/flclash.png',
          v2rayn: '/theme/xboard/assets/images/clients/v2rayn.png',
          singbox: '/theme/xboard/assets/images/clients/sing-box.svg',
          verge: '/theme/xboard/assets/images/clash-verge-rev.png'
        }
        var src = icons[client]
        if (!src) return escapeHtml(String(label || '?').charAt(0).toUpperCase())
        return '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(label || client) + '" loading="lazy" decoding="async">'
      }

      function createSubscribeImportItem(label, client) {
        var item = document.createElement('li')
        item.className = 'n-list-item p-0!'
        item.dataset.bcSubscribeImport = client
        item.dataset.bcSubscribeLabel = label
        item.dataset.bcSubscribeManaged = '1'
        item.innerHTML = '<div class="n-list-item__main">' +
          '<div class="bc-subscribe-import-row flex cursor-pointer items-center p-2.5" role="button" tabindex="0">' +
          '<div class="w-16 flex justify-center"><span class="bc-subscribe-client-badge">' + getSubscribeClientIcon(client, label) + '</span></div>' +
          '<div class="text-gray-500">导入到 ' + escapeHtml(label) + '</div>' +
          '</div>' +
          '</div><div class="n-list-item__divider"></div>'
        item.addEventListener('click', function () {
          openSubscribeClient(client)
        })
        item.addEventListener('keydown', function (event) {
          if (event.key !== 'Enter' && event.key !== ' ') return
          event.preventDefault()
          openSubscribeClient(client)
        })
        return item
      }

      function isSubscribeImportItem(item) {
        var text = textOf(item)
        return text.indexOf('导入到') !== -1 && (
          text.indexOf('ClashVergeRev') !== -1 ||
          text.indexOf('ClashVerge') !== -1 ||
          text.indexOf('Hiddify') !== -1 ||
          text.indexOf('NekoBox') !== -1 ||
          text.indexOf('v2rayNG') !== -1 ||
          text.indexOf('v2rayN') !== -1 ||
          text.indexOf('sing-box') !== -1 ||
          text.indexOf('FlClash') !== -1 ||
          text.indexOf('Surfboard') !== -1 ||
          text.indexOf('CMFA') !== -1
        )
      }

      function getSubscribeClientSignature(clients) {
        return clients.map(function (client) {
          return client.client + ':' + client.label
        }).join('|')
      }

      function getManagedSubscribeSignature(list) {
        return Array.prototype.slice.call(list.querySelectorAll('[data-bc-subscribe-managed="1"]')).map(function (item) {
          return (item.dataset.bcSubscribeImport || '') + ':' + (item.dataset.bcSubscribeLabel || '')
        }).join('|')
      }

      function ensurePlatformSubscribeClients(list) {
        var items = Array.prototype.slice.call(list.querySelectorAll('.n-list-item'))
        var clients = getSubscribeClients()
        var desiredSignature = getSubscribeClientSignature(clients)
        var legacyItems = items.filter(function (item) {
          return item.dataset.bcSubscribeManaged !== '1' && isSubscribeImportItem(item)
        })
        if (!legacyItems.length && getManagedSubscribeSignature(list) === desiredSignature) return

        var insertBefore = null
        var anchor = null
        items.forEach(function (item) {
          if (!anchor && textOf(item).indexOf('复制订阅地址') !== -1) anchor = item
          if (!insertBefore && isSubscribeImportItem(item)) insertBefore = item
        })
        if (!insertBefore && anchor) insertBefore = anchor.nextSibling
        items.forEach(function (item) {
          if (item.dataset.bcSubscribeManaged === '1' || isSubscribeImportItem(item)) item.remove()
        })
        var next = insertBefore && insertBefore.parentNode === list ? insertBefore : (anchor && anchor.nextSibling ? anchor.nextSibling : null)
        clients.forEach(function (client) {
          list.insertBefore(createSubscribeImportItem(client.label, client.client), next)
        })
      }

      function normalizeSubscribeClientLabels(list) {
        Array.prototype.slice.call(list.querySelectorAll('.n-list-item')).forEach(function (item) {
          if (textOf(item).indexOf('ClashVergeRev') === -1) return
          var walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT)
          var textNode
          while ((textNode = walker.nextNode())) {
            textNode.nodeValue = textNode.nodeValue.replace(/ClashVergeRev/g, 'Clash Verge Rev')
          }
        })
      }

      function patchSubscribeModal() {
        if (!document.querySelector('.n-modal, [role="dialog"]')) return
        var lists = Array.prototype.slice.call(document.querySelectorAll('.n-modal .n-list, [role="dialog"] .n-list'))
        lists.forEach(function (list) {
          var text = textOf(list)
          if (text.indexOf('复制订阅地址') === -1) return
          if (text.indexOf('扫描二维码订阅') === -1 && text.indexOf('不会使用') === -1 && text.indexOf('导入到') === -1) return
          normalizeSubscribeClientLabels(list)
          ensurePlatformSubscribeClients(list)
        })
      }

      function scheduleSubscribePatch() {
        if (subscribePatchTimer) return
        subscribePatchTimer = window.setTimeout(function () {
          subscribePatchTimer = 0
          patchSubscribeModal()
        }, 120)
      }

      function isDocsRoute() {
        var hash = String(window.location.hash || '').toLowerCase()
        var path = String(window.location.pathname || '').toLowerCase()
        if (hash.indexOf('/knowledge') !== -1 || hash.indexOf('/docs') !== -1 || hash.indexOf('/document') !== -1) return true
        if (path === '/knowledge' || path === '/docs' || path === '/document') return true
        var title = findTopTitle()
        return !!title && textOf(title) === '使用文档'
      }

      function getDocsHtml() {
        return '<section class="bc-docs-panel" data-bc-docs-panel="1">' +
          '<div class="bc-docs-hero">' +
          '<div class="bc-docs-kicker">BunCloud 使用文档</div>' +
          '<h2 class="bc-docs-title">快速完成订阅导入和日常排障</h2>' +
          '<p class="bc-docs-lead">推荐优先使用页面里的“一键导入”。如果客户端没有响应，再复制订阅地址到客户端内手动添加。订阅地址属于账号凭证，不要发给他人。</p>' +
          '<div class="bc-docs-tag-row">' +
          '<span class="bc-docs-tag">Windows</span><span class="bc-docs-tag">Android</span><span class="bc-docs-tag">Clash Verge Rev</span><span class="bc-docs-tag">NekoBox</span><span class="bc-docs-tag">FlClash</span><span class="bc-docs-tag">sing-box</span>' +
          '</div>' +
          '</div>' +
          '<div class="bc-docs-grid">' +
          '<article class="bc-docs-card"><h3>1. 获取订阅</h3><ol><li>进入仪表盘，点击订阅或导入按钮。</li><li>按当前设备选择对应客户端导入。</li><li>客户端导入后先更新订阅，再选择节点连接。</li></ol></article>' +
          '<article class="bc-docs-card"><h3>2. Windows 客户端</h3><ul><li>Clash Verge Rev：使用“导入到 Clash Verge Rev”，适合 Meta 系列配置。</li><li>v2rayN：导入后检查系统代理和路由模式。</li><li>sing-box / FlClash：导入失败时复制订阅地址手动添加远程配置。</li></ul></article>' +
          '<article class="bc-docs-card"><h3>3. Android 客户端</h3><ul><li>CMFA、NekoBox、Surfboard、FlClash 会按安卓设备优先显示。</li><li>导入后请在客户端内更新订阅，确认节点列表刷新完成。</li><li>移动网络和 Wi-Fi 切换后，建议重新连接一次节点。</li></ul></article>' +
          '<article class="bc-docs-card"><h3>4. 连接异常排查</h3><ul><li>先更新订阅，确认不是旧配置。</li><li>换同协议其他节点测试，区分节点问题和本机网络问题。</li><li>Reality、HY2、TUIC 等协议需要较新的客户端内核。</li></ul></article>' +
          '<article class="bc-docs-card"><h3>5. 流量明细</h3><ul><li>在“流量明细”查看按节点统计的实际用量和倍率。</li><li>可按小时或天查看，也可以设置时间范围。</li><li>客户端显示流量和面板扣费流量可能因倍率不同而不一致。</li></ul></article>' +
          '<article class="bc-docs-card"><h3>6. 账号安全</h3><ul><li>订阅地址泄露后，别人可以直接使用你的流量。</li><li>发现异常流量，先重置订阅或联系管理员处理。</li><li>不要在公开截图中展示完整 token、订阅链接或二维码。</li></ul></article>' +
          '</div>' +
          '</section>'
      }

      function removeDocsPanel() {
        Array.prototype.slice.call(document.querySelectorAll('[data-bc-docs-panel="1"]')).forEach(function (node) {
          node.remove()
        })
      }

      function findDocsInsertHost() {
        var title = findTopTitle()
        if (!title || textOf(title) !== '使用文档') return null
        var layout = measureLayout()
        var current = title
        for (var i = 0; i < 7 && current && current.parentElement; i += 1) {
          var parent = current.parentElement
          var rect = parent.getBoundingClientRect()
          if (rect.width >= 280 && rect.left >= layout.left - 24 && rect.top >= layout.top - 36) {
            if (parent.children && parent.children.length <= 3) return parent.parentElement || parent
            return parent
          }
          current = parent
        }
        return title.parentElement || null
      }

      function patchDocsPage() {
        if (!isDocsRoute()) {
          removeDocsPanel()
          return
        }
        if (document.querySelector('[data-bc-docs-panel="1"]')) return
        var host = findDocsInsertHost()
        if (!host) return
        var wrapper = document.createElement('div')
        wrapper.innerHTML = getDocsHtml()
        var panel = wrapper.firstElementChild
        var title = findTopTitle()
        if (title) {
          var titleBlock = title
          for (var i = 0; i < 3 && titleBlock.parentElement && titleBlock.parentElement !== host; i += 1) {
            titleBlock = titleBlock.parentElement
          }
          if (titleBlock.parentElement === host && titleBlock.nextSibling) host.insertBefore(panel, titleBlock.nextSibling)
          else host.appendChild(panel)
        } else {
          host.appendChild(panel)
        }
      }

      function scheduleDocsPatch() {
        if (docsPatchTimer) return
        docsPatchTimer = window.setTimeout(function () {
          docsPatchTimer = 0
          patchDocsPage()
        }, 120)
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
        return rate.toFixed(2) + ' x'
      }

      function getNodeTrafficRate(row) {
        var rate = Number(row.rate != null ? row.rate : row.server_rate)
        return isFinite(rate) ? rate : 1
      }

      function getNodeTrafficCacheKey() {
        return [nodeTrafficPeriod, nodeTrafficStartAt || '', nodeTrafficEndAt || ''].join('|')
      }

      function getNodeTrafficTimestamp(value) {
        if (!value) return 0
        var parsed = Date.parse(String(value))
        if (!isFinite(parsed)) return 0
        return Math.floor(parsed / 1000)
      }

      function updateNodeTrafficRangeFromToolbar(toolbar) {
        var startInput = toolbar.querySelector('input[data-range="start"]')
        var endInput = toolbar.querySelector('input[data-range="end"]')
        nodeTrafficStartAt = startInput ? String(startInput.value || '') : ''
        nodeTrafficEndAt = endInput ? String(endInput.value || '') : ''
      }

      function syncTrafficRangeInputs(toolbar) {
        var startInput = toolbar.querySelector('input[data-range="start"]')
        var endInput = toolbar.querySelector('input[data-range="end"]')
        if (startInput && startInput.value !== nodeTrafficStartAt) startInput.value = nodeTrafficStartAt
        if (endInput && endInput.value !== nodeTrafficEndAt) endInput.value = nodeTrafficEndAt
      }

      function reloadNodeTrafficTable(table) {
        nodeTrafficPageMap[getNodeTrafficCacheKey()] = 1
        table.dataset.bcNodeTrafficLoaded = ''
        table.dataset.bcNodeTrafficLoading = ''
        table.dataset.bcNodeTrafficFailed = ''
        nodeTrafficAuthRetry = 0
        patchLegacyTrafficTable(false, true)
      }

      function getNodeTrafficTotal(row) {
        var total = Number(row.total)
        if (isFinite(total) && total >= 0) return total
        return Number(row.u || 0) + Number(row.d || 0)
      }

      function getNodeTrafficCost(row) {
        var cost = Number(row.cost)
        if (isFinite(cost) && cost >= 0) return cost
        return getNodeTrafficTotal(row) * getNodeTrafficRate(row)
      }

      function formatProtocol(row) {
        var serverType = String(row.server_type || row.node_type || '').trim().toLowerCase()
        var protocol = String(row.protocol || row.display_protocol || row.type || '').trim().toLowerCase()
        if (!protocol || protocol === 'v2node') {
          protocol = serverType === 'v2node' ? '' : serverType
        }
        var labels = {
          shadowsocks: 'Shadowsocks',
          trojan: 'Trojan',
          vmess: 'VMess',
          vless: 'VLESS',
          hysteria: 'Hysteria2',
          hysteria2: 'Hysteria2',
          anytls: 'AnyTLS',
          tuic: 'TUIC',
          socks: 'SOCKS',
          socks5: 'SOCKS5',
          http: 'HTTP'
        }
        return labels[protocol] || (protocol ? protocol.toUpperCase() : '未知')
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
        var placement = getNodeTrafficControlPlacement(table)
        if (!placement.host) return null
        var toolbar = Array.prototype.slice.call(placement.host.children).find(function (child) {
          return child.classList && child.classList.contains('bc-node-traffic-table-toolbar')
        })
        if (!toolbar) {
          toolbar = document.createElement('div')
          toolbar.className = 'bc-node-traffic-table-toolbar'
          toolbar.innerHTML = '<div class="bc-node-traffic-periods"><button type="button" data-period="day">按天</button><button type="button" data-period="hour">按小时</button><button type="button" data-period="minute">按分钟</button></div>' +
            '<div class="bc-node-traffic-range">' +
            '<input type="datetime-local" data-range="start" aria-label="开始时间">' +
            '<span>至</span>' +
            '<input type="datetime-local" data-range="end" aria-label="结束时间">' +
            '<button type="button" data-range-action="apply">查询</button>' +
            '<button type="button" data-range-action="reset">重置</button>' +
            '</div>'
          placement.host.insertBefore(toolbar, placement.frame || table)
          toolbar.addEventListener('click', function (event) {
            var periodButton = event.target && event.target.closest ? event.target.closest('button[data-period]') : null
            if (periodButton) {
              nodeTrafficPeriod = periodButton.dataset.period || 'day'
              reloadNodeTrafficTable(table)
              return
            }
            var rangeButton = event.target && event.target.closest ? event.target.closest('button[data-range-action]') : null
            if (!rangeButton) return
            if (rangeButton.dataset.rangeAction === 'reset') {
              nodeTrafficStartAt = ''
              nodeTrafficEndAt = ''
              syncTrafficRangeInputs(toolbar)
            } else {
              updateNodeTrafficRangeFromToolbar(toolbar)
            }
            reloadNodeTrafficTable(table)
          })
          toolbar.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return
            var input = event.target && event.target.closest ? event.target.closest('input[data-range]') : null
            if (!input) return
            event.preventDefault()
            updateNodeTrafficRangeFromToolbar(toolbar)
            reloadNodeTrafficTable(table)
          })
        }
        Array.prototype.slice.call(toolbar.querySelectorAll('button[data-period]')).forEach(function (button) {
          button.classList.toggle('is-active', button.dataset.period === nodeTrafficPeriod)
        })
        syncTrafficRangeInputs(toolbar)
        return toolbar
      }

      function getNodeTrafficControlPlacement(table) {
        if (!table) return { host: null, frame: null }
        var frame = table.closest ? table.closest('.n-data-table') : null
        if (!frame) frame = table.closest ? table.closest('.n-data-table-wrapper') : null
        if (!frame) frame = table.parentElement
        var host = frame && frame.parentElement ? frame.parentElement : table.parentElement
        return { host: host, frame: frame }
      }

      function setupNodeTrafficTable(table) {
        table.dataset.bcNodeTrafficTable = '1'
        table.dataset.bcNodeTrafficVersion = nodeTrafficPatchVersion
        table.classList.add('bc-node-traffic-legacy-table')
        ensureNodeTrafficColgroup(table)
        var thead = table.tHead || table.createTHead()
        var headHtml = '<tr>' + [
          '时间',
          '节点',
          '协议',
          '倍率',
          '实际上行',
          '实际下行',
          '实际使用',
          '计费流量'
        ].map(renderNodeTrafficHeadCell).join('') + '</tr>'
        if (thead.innerHTML !== headHtml) thead.innerHTML = headHtml
        return table.tBodies[0] || table.createTBody()
      }

      function setNodeTrafficMessage(table, message) {
        var tbody = setupNodeTrafficTable(table)
        tbody.innerHTML = '<tr>' + renderNodeTrafficBodyCell(message || '暂无节点维度流量数据', { colspan: 8, extraClass: 'bc-node-traffic-empty' }) + '</tr>'
        removeNodeTrafficPagination(table)
      }

      function setNodeTrafficLoading(table) {
        var tbody = setupNodeTrafficTable(table)
        var html = '<tr>' + renderNodeTrafficBodyCell(
          '<span class="bc-node-traffic-spinner" aria-label="加载中"></span>',
          { colspan: 8, extraClass: 'bc-node-traffic-loading', html: true }
        ) + '</tr>'
        if (tbody.innerHTML !== html) tbody.innerHTML = html
        removeNodeTrafficPagination(table)
      }

      function ensureNodeTrafficColgroup(table) {
        var colgroup = table.querySelector('colgroup')
        if (!colgroup) {
          colgroup = document.createElement('colgroup')
          table.insertBefore(colgroup, table.firstChild)
        }
        var colgroupHtml = [
          '124px',
          '250px',
          '96px',
          '86px',
          '104px',
          '104px',
          '104px',
          '104px'
        ].map(function (width) {
          return '<col style="width: ' + width + ';">'
        }).join('')
        if (colgroup.innerHTML !== colgroupHtml) colgroup.innerHTML = colgroupHtml
      }

      function renderNodeTrafficHeadCell(text) {
        return '<th class="n-data-table-th" colspan="1">' +
          '<div class="n-data-table-th__title-wrapper">' +
          '<span class="n-data-table-th__title">' + escapeHtml(text) + '</span>' +
          '</div>' +
          '</th>'
      }

      function renderNodeTrafficBodyCell(value, options) {
        var opts = options || {}
        var classes = ['n-data-table-td']
        if (opts.extraClass) classes.push(opts.extraClass)
        var attrs = ' class="' + classes.join(' ') + '"'
        if (opts.colspan) attrs += ' colspan="' + Number(opts.colspan) + '"'
        if (opts.title) attrs += ' title="' + escapeHtml(opts.title) + '"'
        var content = opts.html ? String(value == null ? '' : value) : escapeHtml(value)
        return '<td' + attrs + '>' +
          '<div class="n-data-table-td__content">' + content + '</div>' +
          '</td>'
      }

      function renderNodeTrafficPill(value, type) {
        return '<span class="bc-node-traffic-pill bc-node-traffic-pill--' + escapeHtml(type || 'default') + '">' +
          escapeHtml(value) +
          '</span>'
      }

      function getNodeTrafficPage() {
        var page = Number(nodeTrafficPageMap[getNodeTrafficCacheKey()] || 1)
        return isFinite(page) && page > 0 ? Math.floor(page) : 1
      }

      function setNodeTrafficPage(page, totalPages) {
        var maxPage = Math.max(1, Number(totalPages || 1))
        var nextPage = Math.max(1, Math.min(maxPage, Math.floor(Number(page || 1))))
        nodeTrafficPageMap[getNodeTrafficCacheKey()] = nextPage
        return nextPage
      }

      function removeNodeTrafficPagination(table) {
        if (!table) return
        var placement = getNodeTrafficControlPlacement(table)
        var host = placement.host || table.parentElement
        if (!host) return
        Array.prototype.slice.call(host.querySelectorAll('.bc-node-traffic-pagination')).forEach(function (node) {
          node.remove()
        })
      }

      function getPageNumbers(currentPage, totalPages) {
        var pages = []
        var add = function (page) {
          if (page >= 1 && page <= totalPages && pages.indexOf(page) === -1) pages.push(page)
        }
        add(1)
        for (var page = currentPage - 2; page <= currentPage + 2; page += 1) add(page)
        add(totalPages)
        pages.sort(function (a, b) { return a - b })
        return pages
      }

      function renderNodeTrafficPagination(table, totalRows, totalPages, currentPage) {
        var placement = getNodeTrafficControlPlacement(table)
        if (!placement.host) return
        if (totalRows <= nodeTrafficPageSize) {
          removeNodeTrafficPagination(table)
          return
        }

        var pagination = Array.prototype.slice.call(placement.host.children).find(function (node) {
          return node.classList && node.classList.contains('bc-node-traffic-pagination')
        })
        if (!pagination) {
          pagination = document.createElement('div')
          pagination.className = 'bc-node-traffic-pagination'
          if (placement.frame && placement.frame.nextSibling) placement.host.insertBefore(pagination, placement.frame.nextSibling)
          else placement.host.appendChild(pagination)
          pagination.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('button[data-page], button[data-page-action]') : null
            if (!button || button.disabled) return
            var targetPage = getNodeTrafficPage()
            if (button.dataset.pageAction === 'prev') targetPage -= 1
            else if (button.dataset.pageAction === 'next') targetPage += 1
            else targetPage = Number(button.dataset.page || targetPage)
            setNodeTrafficPage(targetPage, Number(pagination.dataset.totalPages || 1))
            var payload = nodeTrafficPayloadCache[getNodeTrafficCacheKey()]
            if (payload) renderNodeTrafficRows(table, payload, { fromCache: true })
          })
        }

        pagination.dataset.totalPages = String(totalPages)
        var html = '<span class="bc-node-traffic-page-info">共 ' + totalRows + ' 条，每页 ' + nodeTrafficPageSize + ' 条</span>'
        html += '<button type="button" data-page-action="prev"' + (currentPage <= 1 ? ' disabled' : '') + '>上一页</button>'
        var pages = getPageNumbers(currentPage, totalPages)
        for (var i = 0; i < pages.length; i += 1) {
          if (i > 0 && pages[i] - pages[i - 1] > 1) html += '<span class="bc-node-traffic-page-gap">...</span>'
          html += '<button type="button" data-page="' + pages[i] + '"' + (pages[i] === currentPage ? ' class="is-active"' : '') + '>' + pages[i] + '</button>'
        }
        html += '<button type="button" data-page-action="next"' + (currentPage >= totalPages ? ' disabled' : '') + '>下一页</button>'
        if (pagination.innerHTML !== html) pagination.innerHTML = html
      }

      function cacheNodeTrafficTable(table, pageMeta) {
        var thead = table.tHead
        var tbody = table.tBodies[0]
        if (!thead || !tbody) return
        nodeTrafficRenderCache[getNodeTrafficCacheKey()] = {
          version: nodeTrafficPatchVersion,
          thead: thead.innerHTML,
          tbody: tbody.innerHTML,
          pageMeta: pageMeta || null
        }
      }

      function restoreNodeTrafficTable(table) {
        var cache = nodeTrafficRenderCache[getNodeTrafficCacheKey()]
        if (!cache) return false
        if (cache.version !== nodeTrafficPatchVersion) return false
        table.dataset.bcNodeTrafficTable = '1'
        table.dataset.bcNodeTrafficVersion = nodeTrafficPatchVersion
        table.classList.add('bc-node-traffic-legacy-table')
        var thead = table.tHead || table.createTHead()
        var tbody = table.tBodies[0] || table.createTBody()
        if (thead.innerHTML !== cache.thead) thead.innerHTML = cache.thead
        if (tbody.innerHTML !== cache.tbody) tbody.innerHTML = cache.tbody
        table.dataset.bcNodeTrafficLoaded = getNodeTrafficCacheKey()
        table.dataset.bcNodeTrafficLoading = ''
        table.dataset.bcNodeTrafficFailed = ''
        table.dataset.bcNodeTrafficHasRows = '1'
        ensureTrafficToolbar(table)
        if (cache.pageMeta) {
          renderNodeTrafficPagination(table, cache.pageMeta.totalRows, cache.pageMeta.totalPages, cache.pageMeta.currentPage)
        } else {
          removeNodeTrafficPagination(table)
        }
        return true
      }

      function renderNodeTrafficRows(table, payload, options) {
        var opts = options || {}
        if (!opts.fromCache) nodeTrafficPayloadCache[getNodeTrafficCacheKey()] = payload || {}
        var rows = payload && Array.isArray(payload.data) ? payload.data : []
        var meta = payload && payload.meta ? payload.meta : {}
        var tbody = setupNodeTrafficTable(table)
        if (!rows.length) {
          tbody.innerHTML = '<tr>' + renderNodeTrafficBodyCell(meta.note || '暂无节点维度流量数据', { colspan: 8, extraClass: 'bc-node-traffic-empty' }) + '</tr>'
          removeNodeTrafficPagination(table)
          cacheNodeTrafficTable(table, null)
          return
        }
        var totalPages = Math.max(1, Math.ceil(rows.length / nodeTrafficPageSize))
        var currentPage = setNodeTrafficPage(getNodeTrafficPage(), totalPages)
        var start = (currentPage - 1) * nodeTrafficPageSize
        var pageRows = rows.slice(start, start + nodeTrafficPageSize)
        tbody.innerHTML = pageRows.map(function (row) {
          var nodeName = row.name || row.server_name || '未命名节点'
          var protocol = formatProtocol(row)
          var rate = getNodeTrafficRate(row)
          return '<tr>' +
            renderNodeTrafficBodyCell(formatTrafficTime(row.record_at, nodeTrafficPeriod)) +
            renderNodeTrafficBodyCell(nodeName, { title: nodeName }) +
            renderNodeTrafficBodyCell(renderNodeTrafficPill(protocol, 'protocol'), { html: true }) +
            renderNodeTrafficBodyCell(renderNodeTrafficPill(formatRate(rate), 'rate'), { html: true }) +
            renderNodeTrafficBodyCell(formatBytes(row.u)) +
            renderNodeTrafficBodyCell(formatBytes(row.d)) +
            renderNodeTrafficBodyCell(formatBytes(getNodeTrafficTotal(row))) +
            renderNodeTrafficBodyCell(formatBytes(getNodeTrafficCost(row))) +
            '</tr>'
        }).join('')
        renderNodeTrafficPagination(table, rows.length, totalPages, currentPage)
        cacheNodeTrafficTable(table, {
          totalRows: rows.length,
          totalPages: totalPages,
          currentPage: currentPage
        })
      }

      function patchLegacyTrafficTable(shouldScroll, forceReload) {
        var table = findLegacyTrafficTable()
        if (!table) return false
        return patchNodeTrafficTable(table, shouldScroll, forceReload)
      }

      function patchNodeTrafficTable(table, shouldScroll, forceReload) {
        if (!table) return false
        syncMenuState(true)
        setTopTitleActive(true)
        removeTrafficNotice()
        ensureTrafficToolbar(table)
        if (table.dataset.bcNodeTrafficVersion && table.dataset.bcNodeTrafficVersion !== nodeTrafficPatchVersion) {
          table.dataset.bcNodeTrafficLoaded = ''
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = ''
        }
        var cacheKey = getNodeTrafficCacheKey()
        if (table.dataset.bcNodeTrafficLoaded === cacheKey && !forceReload) {
          return true
        }
        if (table.dataset.bcNodeTrafficLoading === cacheKey && !forceReload) {
          return true
        }
        if (table.dataset.bcNodeTrafficFailed === cacheKey && !forceReload && Date.now() - nodeTrafficLastFailedAt < 4000) {
          return true
        }
        if (restoreNodeTrafficTable(table) && !forceReload) {
          if (shouldScroll) table.scrollIntoView({ block: 'start', behavior: 'smooth' })
          return true
        }
        table.dataset.bcNodeTrafficLoaded = ''
        table.dataset.bcNodeTrafficLoading = cacheKey
        table.dataset.bcNodeTrafficFailed = ''
        setNodeTrafficLoading(table)
        var requestId = ++nodeTrafficRequestId
        var token = getAuthToken()
        if (!token) {
          if (nodeTrafficAuthRetry < 20) {
            nodeTrafficAuthRetry += 1
            setNodeTrafficLoading(table)
            setTimeout(function () {
              if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
              patchNodeTrafficTable(table, false, true)
            }, 250)
            return true
          }
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = cacheKey
          nodeTrafficLastFailedAt = Date.now()
          if (!restoreNodeTrafficTable(table) && !table.dataset.bcNodeTrafficHasRows) setNodeTrafficMessage(table, '暂无节点维度流量数据')
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
          table.dataset.bcNodeTrafficLoaded = cacheKey
          table.dataset.bcNodeTrafficHasRows = '1'
          ensureTrafficToolbar(table)
          if (shouldScroll) table.scrollIntoView({ block: 'start', behavior: 'smooth' })
        }).catch(function (error) {
          if (requestId !== nodeTrafficRequestId || !document.documentElement.contains(table)) return
          table.dataset.bcNodeTrafficLoading = ''
          table.dataset.bcNodeTrafficFailed = cacheKey
          nodeTrafficLastFailedAt = Date.now()
          if (window.console && console.warn) console.warn('[node-traffic] load failed', error)
          if (!restoreNodeTrafficTable(table) && !table.dataset.bcNodeTrafficHasRows) {
            setNodeTrafficMessage(table, '暂无节点维度流量数据')
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
        removeNodeTrafficInlineControls()
        removeDocsPanel()
        setTopTitleActive(false)
        syncMenuState(false)
        updateTrafficRouteClass()
        schedulePatch()
        scheduleDocsPatch()
      }

      function removeLegacyNodeTrafficMenu() {
        Array.prototype.slice.call(document.querySelectorAll('.bc-node-traffic-menu')).forEach(function (node) {
          node.remove()
        })
      }

      function handleMutation() {
        updateTrafficRouteClass()
        schedulePatch()
        scheduleSubscribePatch()
        scheduleDocsPatch()
      }

      var observer = new MutationObserver(handleMutation)
      observer.observe(document.documentElement, { childList: true, subtree: true })
      window.addEventListener('hashchange', handleRouteChange)
      window.addEventListener('resize', handleMutation)
      window.addEventListener('load', handleMutation)
      updateTrafficRouteClass()
      setTimeout(handleMutation, 800)
      setTimeout(handleMutation, 2000)
      setInterval(function () {
        if (!isTrafficRoute()) removeNodeTrafficInlineControls()
        scheduleSubscribePatch()
        scheduleDocsPatch()
      }, 1500)
    })()
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
