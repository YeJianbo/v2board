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
    html.bc-user-polish body.bc-node-traffic-open .n-menu-item-content--selected {
      background: transparent !important;
      box-shadow: none !important;
    }
    html.bc-user-polish .bc-node-traffic-content-host > :not(.bc-node-traffic-frame-wrap) {
      display: none !important;
    }
    .bc-node-traffic-frame-wrap {
      display: block;
      width: 100%;
      margin-top: 0;
      min-height: 540px;
      padding: 0;
      background: transparent;
      overflow: hidden;
    }
    .bc-node-traffic-inline-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
      padding: 16px 18px;
      border: 1px solid var(--bc-line);
      border-radius: 8px;
      background: var(--bc-panel);
      box-shadow: var(--bc-shadow-sm);
    }
    .bc-node-traffic-inline-title {
      margin: 0;
      color: var(--bc-text);
      font-size: 18px;
      font-weight: 700;
      line-height: 1.3;
    }
    .bc-node-traffic-inline-desc {
      margin: 4px 0 0;
      color: var(--bc-text-soft);
      font-size: 12px;
      line-height: 1.5;
    }
    .bc-node-traffic-frame {
      width: 100%;
      min-height: 520px;
      border: 0;
      background: transparent;
    }
    body.bc-node-traffic-open {
      overflow: auto;
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
      .bc-node-traffic-frame-wrap {
        min-height: calc(100vh - 56px);
      }
      .bc-node-traffic-frame {
        min-height: calc(100vh - 56px);
      }
    }
  </style>
  <script>
    (function () {
      document.documentElement.classList.add('bc-user-polish')
      var pageUrl = '/user-node-traffic.html'
      var menuText = '流量明细'
      var nodeTrafficOpen = false
      var activeTitle = null
      var activeFrame = null
      var activeHost = null
      var insertMenuTimer = 0
      var subscribeDataPromise = null
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
          renderFrame(!!shouldScroll)
          return
        }
        if (nodeTrafficOpen) closeNodeTraffic()
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
          if (table.closest && table.closest('.bc-node-traffic-frame-wrap')) return false
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
        document.body.classList.toggle('bc-node-traffic-open', !!active)
        Array.prototype.slice.call(document.querySelectorAll('.n-menu-item-content--selected')).forEach(function (node) {
          if (!node.dataset.bcWasSelected) node.dataset.bcWasSelected = '1'
        })
        if (!active) {
          Array.prototype.slice.call(document.querySelectorAll('[data-bc-was-selected]')).forEach(function (node) {
            delete node.dataset.bcWasSelected
          })
        }
      }

      function keepNodeTrafficChrome() {
        if (!nodeTrafficOpen) return
        syncMenuState(true)
      }

      function scheduleChromeSync() {
        setTimeout(keepNodeTrafficChrome, 30)
        setTimeout(keepNodeTrafficChrome, 160)
        setTimeout(keepNodeTrafficChrome, 500)
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
                  if (lower !== 'token') stack.token = child
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
          ensureNodeTrafficInline(0)
          patchSubscribeImports()
        }, 80)
      }

      function buildFrameUrl(token) {
        var url = pageUrl + '?embed=1&inline=1'
        if (token) url += '&auth_data=' + encodeURIComponent(token)
        return url
      }

      function primeFrameAuth() {
        var token = getAuthToken()
        if (!token) return
        try {
          window.sessionStorage.setItem('bc_node_traffic_auth_data', token)
        } catch (error) {}
        return token
      }

      function syncFrameAuth(frame, token) {
        token = token || primeFrameAuth()
        if (!token || !frame || !frame.contentWindow) return
        frame.contentWindow && frame.contentWindow.postMessage({
          type: 'bc-node-traffic-auth',
          auth_data: token
        }, window.location.origin)
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

      function findPageRootFromTitle() {
        var title = findTopTitle()
        if (!title) return null

        var layout = measureLayout()
        var current = title.parentElement
        var best = null
        for (var i = 0; current && current !== document.body && i < 10; i += 1) {
          var rect = current.getBoundingClientRect()
          if (rect.width >= 420 &&
            rect.height >= 220 &&
            rect.left >= layout.left - 24 &&
            rect.top >= layout.top - 24 &&
            !(current.closest && current.closest('aside, nav'))) {
            best = current
          }
          current = current.parentElement
        }
        return best
      }

      function findLargestContentRoot() {
        var layout = measureLayout()
        var nodes = Array.prototype.slice.call(document.body.querySelectorAll('#app > *, .n-layout-content, main, [class*="content"], [class*="page"]'))
        var candidates = nodes.filter(function (node) {
          if (node.classList && node.classList.contains('bc-node-traffic-frame-wrap')) return false
          if (node.closest && node.closest('aside, nav')) return false
          var rect = node.getBoundingClientRect()
          if (!rect.width || !rect.height) return false
          return rect.left >= layout.left - 20 &&
            rect.top >= layout.top - 36 &&
            rect.width >= Math.min(520, window.innerWidth - layout.left - 32) &&
            rect.height >= 180
        }).sort(function (a, b) {
          var ar = a.getBoundingClientRect()
          var br = b.getBoundingClientRect()
          return (br.width * br.height) - (ar.width * ar.height)
        })

        return candidates[0] || null
      }

      function findContentHost() {
        if (activeHost && document.documentElement.contains(activeHost)) return activeHost

        var legacyRoot = findLegacyTrafficRoot()
        if (legacyRoot) {
          activeHost = legacyRoot
          return activeHost
        }

        var pageRoot = findPageRootFromTitle()
        if (pageRoot) {
          activeHost = pageRoot
          return activeHost
        }

        var largestRoot = findLargestContentRoot()
        if (largestRoot) {
          activeHost = largestRoot
          return activeHost
        }

        var layout = measureLayout()
        var selectors = '.n-layout-content, main, [class*="content"], [class*="page"]'
        var nodes = Array.prototype.slice.call(document.body.querySelectorAll(selectors))
        var candidates = nodes.filter(function (node) {
          if (node.classList && node.classList.contains('bc-node-traffic-frame-wrap')) return false
          if (node.closest && node.closest('aside, nav')) return false
          var rect = node.getBoundingClientRect()
          if (!rect.width || !rect.height) return false
          return rect.left >= layout.left - 12 &&
            rect.top >= layout.top - 12 &&
            rect.width >= 420 &&
            rect.height >= 260
        }).sort(function (a, b) {
          var ar = a.getBoundingClientRect()
          var br = b.getBoundingClientRect()
          var aScore = Math.abs(ar.left - layout.left) + Math.abs(ar.top - layout.top)
          var bScore = Math.abs(br.left - layout.left) + Math.abs(br.top - layout.top)
          return aScore - bScore || (br.width * br.height) - (ar.width * ar.height)
        })

        activeHost = candidates[0] || document.querySelector('#app') || document.body
        return activeHost
      }

      function updateFrameLayout() {
        var wrap = document.querySelector('.bc-node-traffic-frame-wrap')
        if (!wrap) return
        var frame = wrap.querySelector('iframe')
        var layout = measureLayout()
        var height = Math.max(520, window.innerHeight - layout.top)
        wrap.style.minHeight = height + 'px'
        if (frame) frame.style.minHeight = height + 'px'
      }

      function closeNodeTraffic() {
        nodeTrafficOpen = false
        var old = document.querySelector('.bc-node-traffic-frame-wrap')
        if (old) old.remove()
        activeFrame = null
        if (activeHost) activeHost.classList.remove('bc-node-traffic-content-host')
        activeHost = null
        syncMenuState(false)
      }

      function handleRouteChange() {
        closeNodeTraffic()
      }

      function renderFrame(shouldScroll) {
        var title = findTopTitle()
        if (!isTrafficDetailPage()) {
          syncMenuState(false)
          return
        }

        nodeTrafficOpen = true
        syncMenuState(true)
        var legacyRoot = findLegacyTrafficRoot()
        var legacyTable = findLegacyTrafficTable()
        var existing = document.querySelector('.bc-node-traffic-frame-wrap')
        if (existing) {
          if (legacyTable || (legacyRoot && !legacyRoot.contains(existing))) {
            existing.remove()
            if (activeHost) activeHost.classList.remove('bc-node-traffic-content-host')
            activeFrame = null
            activeHost = null
          } else {
            scheduleChromeSync()
            updateFrameLayout()
            syncFrameAuth(existing.querySelector('iframe'))
            scheduleChromeSync()
            if (shouldScroll) existing.scrollIntoView({ block: 'start', behavior: 'smooth' })
            return
          }
        }

        if (legacyRoot) {
          activeHost = legacyRoot
        }
        var token = primeFrameAuth()
        var host = legacyRoot || findContentHost()
        scheduleChromeSync()
        var wrap = document.createElement('div')
        wrap.className = 'bc-node-traffic-frame-wrap'
        var head = document.createElement('div')
        head.className = 'bc-node-traffic-inline-head'
        head.innerHTML = '<div><h2 class="bc-node-traffic-inline-title">流量明细</h2><p class="bc-node-traffic-inline-desc">按节点查看实际用量、倍率和计费流量。</p></div>'
        var frame = document.createElement('iframe')
        frame.className = 'bc-node-traffic-frame'
        frame.src = buildFrameUrl(token)
        frame.title = menuText
        frame.addEventListener('load', function () {
          syncFrameAuth(frame, token)
        })
        wrap.appendChild(head)
        wrap.appendChild(frame)
        if (legacyRoot && host && host !== document.body && host.id !== 'app') {
          host.innerHTML = ''
          host.appendChild(wrap)
          host.classList.remove('bc-node-traffic-content-host')
        } else {
          host.appendChild(wrap)
          if (host !== document.body && host.id !== 'app') {
            host.classList.add('bc-node-traffic-content-host')
          }
        }
        activeHost = host
        activeFrame = frame
        updateFrameLayout()
        syncFrameAuth(frame, token)
        syncMenuState(true)
        scheduleChromeSync()
        if (shouldScroll) wrap.scrollIntoView({ block: 'start', behavior: 'smooth' })
      }

      function removeLegacyNodeTrafficMenu() {
        Array.prototype.slice.call(document.querySelectorAll('.bc-node-traffic-menu')).forEach(function (node) {
          node.remove()
        })
      }

      var observer = new MutationObserver(schedulePatch)
      observer.observe(document.documentElement, { childList: true, subtree: true })
      window.addEventListener('hashchange', handleRouteChange)
      window.addEventListener('resize', updateFrameLayout)
      window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) return
        if (!event.data || event.data.type !== 'bc-node-traffic-need-auth') return
        syncFrameAuth(activeFrame)
      })
      window.addEventListener('load', schedulePatch)
      setTimeout(schedulePatch, 800)
      setTimeout(schedulePatch, 2000)
    })()
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
