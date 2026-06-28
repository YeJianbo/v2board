<?php

namespace Database\Seeders;

use App\Models\Knowledge;
use Illuminate\Database\Seeder;

class BunCloudKnowledgeSeeder extends Seeder
{
    public function run()
    {
        $now = time();
        $items = [
            [
                'category' => '快速开始',
                'title' => '如何导入订阅',
                'sort' => 10,
                'body' => <<<'HTML'
<h2>如何导入订阅</h2>
<p>订阅地址是你的账号凭证。不要把完整链接、二维码或 token 发给他人。</p>
<ol>
  <li>进入仪表盘，点击订阅或导入按钮。</li>
  <li>按当前设备选择客户端一键导入。</li>
  <li>客户端打开后先更新订阅，再选择节点连接。</li>
  <li>如果一键导入没有响应，复制订阅地址，在客户端里手动添加远程订阅。</li>
</ol>
<p>当前订阅地址：<code>{{subscribeUrl}}</code></p>
HTML,
            ],
            [
                'category' => '客户端',
                'title' => 'Windows 客户端推荐',
                'sort' => 20,
                'body' => <<<'HTML'
<h2>Windows 客户端推荐</h2>
<ul>
  <li><strong>Clash Verge Rev</strong>：推荐用于 Meta 系列订阅配置。</li>
  <li><strong>v2rayN</strong>：适合 VLESS、VMess、Trojan 等常见协议。</li>
  <li><strong>sing-box</strong>：适合 sing-box 内核用户，导入后检查出站选择。</li>
  <li><strong>FlClash</strong>：导入失败时复制订阅地址手动添加。</li>
</ul>
<p>导入后如无法访问，请先确认系统代理已开启，并在客户端里刷新订阅。</p>
HTML,
            ],
            [
                'category' => '客户端',
                'title' => 'Android 客户端推荐',
                'sort' => 30,
                'body' => <<<'HTML'
<h2>Android 客户端推荐</h2>
<ul>
  <li><strong>CMFA</strong>：适合 Clash Meta 系列配置。</li>
  <li><strong>NekoBox</strong>：适合多协议节点。</li>
  <li><strong>Surfboard</strong>：适合规则分流和日常使用。</li>
  <li><strong>FlClash</strong>：导入后建议先刷新订阅。</li>
</ul>
<p>移动网络和 Wi-Fi 切换后，如果节点无响应，可以断开后重新连接。</p>
HTML,
            ],
            [
                'category' => '排障',
                'title' => '连接异常排查',
                'sort' => 40,
                'body' => <<<'HTML'
<h2>连接异常排查</h2>
<ol>
  <li>先更新订阅，确认不是旧配置。</li>
  <li>换同协议其他节点测试，区分节点问题和本机网络问题。</li>
  <li>Reality、Hysteria2、TUIC、AnyTLS 等协议需要较新的客户端内核。</li>
  <li>如果只有某个网站打不开，检查客户端规则模式和 DNS 设置。</li>
  <li>如果所有节点都不可用，检查本机时间、系统代理、防火墙和网络环境。</li>
</ol>
HTML,
            ],
            [
                'category' => '流量统计',
                'title' => '流量明细与倍率说明',
                'sort' => 50,
                'body' => <<<'HTML'
<h2>流量明细与倍率说明</h2>
<p>在“流量明细”页面可以查看按节点统计的实际用量、倍率和扣费流量。</p>
<ul>
  <li>实际使用：节点上报的真实上行和下行流量。</li>
  <li>扣费流量：实际使用乘以节点倍率后的计费结果。</li>
  <li>按小时或按天查看时，统计结果会按所选时间粒度聚合。</li>
</ul>
<p>客户端显示流量和面板扣费流量可能不同，通常是倍率、统计窗口或客户端本地缓存造成的。</p>
HTML,
            ],
            [
                'category' => '安全',
                'title' => '订阅安全说明',
                'sort' => 60,
                'body' => <<<'HTML'
<h2>订阅安全说明</h2>
<ul>
  <li>订阅链接、二维码和 token 都等同于账号访问凭证。</li>
  <li>不要在公开截图、聊天记录或日志里展示完整订阅内容。</li>
  <li>发现异常流量后，先重置订阅或联系管理员处理。</li>
  <li>公共设备上使用后，记得删除客户端里的订阅配置。</li>
</ul>
HTML,
            ],
        ];

        foreach ($items as $item) {
            Knowledge::updateOrCreate(
                [
                    'language' => 'zh-CN',
                    'title' => $item['title'],
                ],
                [
                    'category' => $item['category'],
                    'body' => $item['body'],
                    'sort' => $item['sort'],
                    'show' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
