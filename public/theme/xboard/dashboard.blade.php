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
    .bc-node-traffic-menu {
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 42px;
      margin: 4px 8px 4px 12px;
      padding: 0 18px 0 28px;
      border-radius: 4px;
      color: inherit;
      font-size: 14px;
      text-decoration: none;
      cursor: pointer;
    }
    .bc-node-traffic-menu:hover,
    .bc-node-traffic-menu.is-active {
      background: rgba(15, 118, 110, .10);
      color: #0f766e;
    }
    .bc-node-traffic-menu::before {
      content: "";
      width: 18px;
      height: 18px;
      background:
        linear-gradient(currentColor, currentColor) 2px 10px / 4px 8px no-repeat,
        linear-gradient(currentColor, currentColor) 8px 5px / 4px 13px no-repeat,
        linear-gradient(currentColor, currentColor) 14px 0 / 4px 18px no-repeat;
    }
    .bc-node-traffic-frame-wrap {
      position: fixed;
      left: var(--bc-node-traffic-left, 274px);
      right: 0;
      top: var(--bc-node-traffic-top, 74px);
      bottom: 0;
      z-index: 2147483000;
      background: #f5f7fb;
      overflow: hidden;
      box-shadow: inset 1px 0 0 #eef2f7;
    }
    .bc-node-traffic-frame {
      width: 100%;
      height: 100%;
      border: 0;
      background: #f5f7fb;
    }
    body.bc-node-traffic-open {
      overflow: hidden;
    }
    @media (max-width: 768px) {
      .bc-node-traffic-frame-wrap {
        left: 0;
        top: 56px;
      }
    }
  </style>
  <script>
    (function () {
      var pageUrl = '/user-node-traffic.html'
      var menuText = '节点流量明细'
      var inserted = false
      var nodeTrafficOpen = false
      var activeTitle = null
      var activeFrame = null

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

      function openNodeTraffic() {
        renderFrame()
      }

      function findTopTitle() {
        if (activeTitle && document.documentElement.contains(activeTitle)) return activeTitle
        var nodes = Array.prototype.slice.call(document.querySelectorAll('a, div, span, h1, h2'))
        return nodes.find(function (node) {
          if (textOf(node) !== '流量明细') return false
          var rect = node.getBoundingClientRect()
          return rect.width > 0 && rect.height > 0 && rect.left > 220 && rect.top < 90
        })
      }

      function setTopTitleActive(active) {
        var title = findTopTitle()
        if (!title) return
        if (active) {
          activeTitle = title
          if (!title.dataset.bcOriginalText) title.dataset.bcOriginalText = title.textContent
          title.textContent = menuText
        } else if (title.dataset.bcOriginalText) {
          title.textContent = title.dataset.bcOriginalText
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

      function buildFrameUrl() {
        return pageUrl + '?embed=1'
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

      function updateFrameLayout() {
        var wrap = document.querySelector('.bc-node-traffic-frame-wrap')
        if (!wrap) return
        var layout = measureLayout()
        wrap.style.setProperty('--bc-node-traffic-left', layout.left + 'px')
        wrap.style.setProperty('--bc-node-traffic-top', layout.top + 'px')
      }

      function closeNodeTraffic() {
        nodeTrafficOpen = false
        var old = document.querySelector('.bc-node-traffic-frame-wrap')
        if (old) old.remove()
        activeFrame = null
        document.body.classList.remove('bc-node-traffic-open')
        var menu = document.querySelector('.bc-node-traffic-menu')
        if (menu) menu.classList.remove('is-active')
        setTopTitleActive(false)
      }

      function renderFrame() {
        nodeTrafficOpen = true
        var menu = document.querySelector('.bc-node-traffic-menu')
        if (menu) menu.classList.add('is-active')
        setTopTitleActive(true)
        setTimeout(function () { setTopTitleActive(true) }, 50)
        var existing = document.querySelector('.bc-node-traffic-frame-wrap')
        if (existing) {
          updateFrameLayout()
          syncFrameAuth(existing.querySelector('iframe'))
          return
        }

        var token = primeFrameAuth()
        var wrap = document.createElement('div')
        wrap.className = 'bc-node-traffic-frame-wrap'
        var frame = document.createElement('iframe')
        frame.className = 'bc-node-traffic-frame'
        frame.src = buildFrameUrl()
        frame.title = menuText
        frame.addEventListener('load', function () {
          syncFrameAuth(frame, token)
        })
        wrap.appendChild(frame)
        document.body.appendChild(wrap)
        activeFrame = frame
        updateFrameLayout()
        syncFrameAuth(frame, token)
        document.body.classList.add('bc-node-traffic-open')
      }

      function insertMenu() {
        var existingMenu = document.querySelector('.bc-node-traffic-menu')
        if (existingMenu) {
          inserted = true
          if (nodeTrafficOpen) {
            existingMenu.classList.add('is-active')
            renderFrame()
          }
          return
        }
        inserted = false

        var traffic = findTrafficMenu()
        if (!traffic) return

        var root = clickableRoot(traffic)
        var item = document.createElement('a')
        item.className = 'bc-node-traffic-menu'
        item.href = 'javascript:void(0)'
        item.innerHTML = '<span>' + menuText + '</span>'
        item.addEventListener('click', function (event) {
          event.preventDefault()
          event.stopPropagation()
          openNodeTraffic()
        })

        if (root.parentElement) {
          root.parentElement.insertBefore(item, root.nextSibling)
          inserted = true
        }
      }

      var observer = new MutationObserver(function () {
        insertMenu()
      })
      observer.observe(document.documentElement, { childList: true, subtree: true })
      window.addEventListener('hashchange', closeNodeTraffic)
      window.addEventListener('resize', updateFrameLayout)
      window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) return
        if (!event.data || event.data.type !== 'bc-node-traffic-need-auth') return
        syncFrameAuth(activeFrame)
      })
      window.addEventListener('load', insertMenu)
      setTimeout(insertMenu, 800)
      setTimeout(insertMenu, 2000)
    })()
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
