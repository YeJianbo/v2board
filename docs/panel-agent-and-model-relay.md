# Agent 与自用模型 API 分发

## 当前实现

后台 Agent 使用管理员配置的 OpenAI Chat Completions 兼容 API。启用和工具授权默认关闭；Key 由 Laravel Crypt 加密保存到 storage/app/integrations/panel-agent.json，不返回浏览器。

工具注册表只允许面板汇总、机器状态、节点列表、入口变更预览，以及节点排序、批量重命名、批量入口 IP 修改的预览。工具调用经过应用服务/控制器，不接收 SQL、Shell、任意 URL 或任意控制器名称。修改只生成五分钟有效、绑定管理员的确认令牌，只有管理员点击确认后才执行。排序的已选节点按提供顺序置顶，其余节点保留原相对顺序；重命名只更新 name；改 IP 只更新订阅元数据。审计记录管理员 ID 与工具名，不记录模型 Key。

当前每个提问独立，不上传聊天历史；点击发送意味着将提问及授权工具查询结果发送给指定 API。没有配置生产模型 Key 时，只能验证本地模拟 API 交互，不代表生产模型已经联通。

## Agent 时段与模型选择

Agent 固定使用 Asia/Shanghai（北京时间）：00:00–09:00、12:00–14:00、18:00–24:00 为谷时，优先 deepseek-v4-flash-0731；其他时段优先 gemini-3.8-flash。自动模式的备用模型为 grok-4.6。每轮模型请求重新核对时段。管理员手动选择时，只允许当前时段主模型或 Grok；手动选择失败不自动切换。三个模型的 HTTPS API 地址和加密 Key 分开保存，更换地址需要重新填写 Key，空 Key 保持原值。

对话回复记录实际使用模型，图标使用本地 Lobe Icons 静态 SVG。系统提示词要求先查询真实 ID、明确范围、区分订阅入口与部署地址，并禁止把预览当作已执行。

## 操作快照与撤销

Agent 的排序、重命名、按节点或入口机器改 IP 统一生成 `agent-action` 预览，只能由管理员确认。模型工具列表中没有确认、撤销、数据库或 Shell 工具。预览五分钟过期，可手动取消；确认时重新检查管理员身份、Agent 启停和工具授权。

批准界面是对话内的卡片，不弹模态窗口。重命名和改 IP 每个节点生成独立批准令牌，可单独批准、勾选批准或批准全部；排序作为一个不可拆分的整体，避免部分执行造成顺序冲突。拒绝只作废对应项，其他项仍待批准。批量批准在单个事务中执行，有冲突则本批全部回滚；之前已经单独批准的操作保持不变。服务端只接收批准令牌，不接受客户端重新提交的字段值。

确认后的字段快照由 Laravel Crypt 加密，存入 `v2_agent_operation`，与字段修改共用同一个数据库事务。快照保存失败或任一字段冲突时整批回滚。只记录此次涉及的名称、顺序或订阅入口覆盖值，不备份提示词、模型 Key 或完整机器配置。确认令牌只存哈希，重复确认返回原操作收据，即使此操作后来撤销也不会重新执行。

浮窗顶部的“操作记录”提供管理员自己的分页记录和差异详情。撤销先生成五分钟有效的预览，确认时再次比较当前字段与本次修改结果；字段变化、节点删除或入口重新绑定时整批拒绝，不覆盖后续修改。撤销仅恢复原字段值，入口的空覆盖恢复为跟随节点默认入口。原来的整站、数据库定时备份和 Google 授权不变；字段快照不能替代数据库灾备。

## 自用模型分发

采用独立 Go 网关处理 Chat Completions 和 SSE，不占用面板 PHP 工作进程处理长时间流式响应。后台支持独立上游、每机器 Key、模型白名单、UTC 日/月或累计 Token 额度、Key 重置与停用、连接测试及请求明细。尚未配置时显示空数据，不自动复用 Agent 凭据。具体运行边界见 `services/model-relay/README.md`。

边界：机器专用 Key 与 Ravel Token、用户机场订阅 Token 分开；Key 只存哈希，绑定单台机器、模型白名单、周期额度，可独立撤销。日/月周期使用明确时区和边界，不以不明含义的“一个月”滚动结算。

当前账本记录 request_id、机器、上游、模型、输入/输出 Token、预留及扣除 Token、耗时、HTTP 状态和错误类别；缓存 Token 分项和供应商货币费用尚未独立计量。账本不记录用户提示词；重试与流式中断幂等结算；未返回 usage 时按预留量扣除并标记估算，后续实际 usage 可修正。

借鉴来源（2026-09-12 阅读）：

- https://github.com/router-for-me/CLIProxyAPI/blob/main/config.example.yaml ：管理 API 与客户端 API Key 分离、上游适配和路由策略。
- https://github.com/Wei-Shaw/sub2api/blob/main/backend/internal/service/api_key.go ：Key 状态、独立 quota/usage、限额时间窗口。
- https://github.com/Wei-Shaw/sub2api/blob/main/backend/ent/schema/subscription_plan.go ：套餐有效期和分组关联。本站自用场景不引入商品售卖、充值或订阅账号自动注册。

## 订阅入口独立绑定

v2_server_metadata.entry_machine_id 与部署 machine_id、relay_machine_id 无关。entry_host 只在 ServerService.getAvailableServers 的客户端输出阶段覆盖 host，原节点表、监听端口和 GOST 目标不变。无覆盖时沿用原 host。后台批量更换需预览与确认；快照过期或入口覆盖已变动时拒绝旧预览。
