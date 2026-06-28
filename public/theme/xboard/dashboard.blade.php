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
      margin: 4px 6px 4px 6px;
      padding: 0 20px;
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
      width: 14px;
      height: 14px;
      border-left: 3px solid currentColor;
      border-bottom: 3px solid currentColor;
      box-shadow: 5px 0 0 -1px currentColor, 10px -6px 0 -1px currentColor;
    }
    .bc-node-traffic-frame-wrap {
      position: fixed;
      left: 274px;
      right: 0;
      top: 74px;
      bottom: 0;
      z-index: 100;
      background: #f5f7fb;
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
        location.hash = '#/node-traffic'
        renderFrame()
      }

      function closeNodeTraffic() {
        var old = document.querySelector('.bc-node-traffic-frame-wrap')
        if (old) old.remove()
        document.body.classList.remove('bc-node-traffic-open')
        var menu = document.querySelector('.bc-node-traffic-menu')
        if (menu) menu.classList.remove('is-active')
      }

      function renderFrame() {
        var isNodeTraffic = location.hash.indexOf('/node-traffic') !== -1
        if (!isNodeTraffic) {
          closeNodeTraffic()
          return
        }

        var menu = document.querySelector('.bc-node-traffic-menu')
        if (menu) menu.classList.add('is-active')
        if (document.querySelector('.bc-node-traffic-frame-wrap')) return

        var wrap = document.createElement('div')
        wrap.className = 'bc-node-traffic-frame-wrap'
        var frame = document.createElement('iframe')
        frame.className = 'bc-node-traffic-frame'
        frame.src = pageUrl
        frame.title = menuText
        wrap.appendChild(frame)
        document.body.appendChild(wrap)
        document.body.classList.add('bc-node-traffic-open')
      }

      function insertMenu() {
        if (inserted || document.querySelector('.bc-node-traffic-menu')) {
          inserted = true
          renderFrame()
          return
        }

        var traffic = findTrafficMenu()
        if (!traffic) return

        var root = clickableRoot(traffic)
        var item = document.createElement('a')
        item.className = 'bc-node-traffic-menu'
        item.href = '#/node-traffic'
        item.innerHTML = '<span>' + menuText + '</span>'
        item.addEventListener('click', function (event) {
          event.preventDefault()
          openNodeTraffic()
        })

        if (root.parentElement) {
          root.parentElement.insertBefore(item, root.nextSibling)
          inserted = true
          renderFrame()
        }
      }

      var observer = new MutationObserver(function () {
        insertMenu()
      })
      observer.observe(document.documentElement, { childList: true, subtree: true })
      window.addEventListener('hashchange', renderFrame)
      window.addEventListener('load', insertMenu)
      setTimeout(insertMenu, 800)
      setTimeout(insertMenu, 2000)
    })()
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>
