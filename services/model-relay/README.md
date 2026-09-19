# Model API Relay

独立 Go 数据面，PHP 只处理鉴权、额度预留和结算。上游通过后台的模型分发页面单独配置；每台机器生成独立 API Key，仅首次展示，数据库保存 SHA-256。重置 Key 不清零额度。

## 直接 API 分发

在“上游设置”明确选择供应商支持的协议，填写 API 基础地址、API Key 和实际模型 ID，再通过“接入机器”分配模型白名单及额度。支持给不同协议单独填写基础地址。客户端使用面板展示的协议入口和机器 Key，不使用供应商 Key。此流程不依赖客户端反代、订阅账号登录或账号转换。

协议按管理员选择启用，不自动猜测，也不做跨协议转换。新上游必须选择协议；旧配置按原 OpenAI Chat 处理。`/models` 连通测试只证明模型列表能读取，不代表其他协议可用。更换默认或协议地址时需要重新填写 Key，避免旧凭据被静默发送到新地址。

上游配置与 Agent 助手独立保存，修改其中一处不会自动修改另一处。绑定机器仅用于用量归属，不安装或改动机器上的任何程序。

## 接口

- OpenAI Chat：`POST /api/model/v1/chat/completions`，上游基础地址通常以 `/v1` 结束。
- OpenAI Responses：`POST /api/model/v1/responses`，支持文本、函数工具和 SSE。
- Responses WebSocket：`GET /api/model/v1/responses` Upgrade，使用 `wss://`，上游需同时勾选 Responses 和 WebSocket。
- Anthropic Messages：`POST /api/model/v1/messages`，支持文本、工具块和 SSE；使用 `x-api-key`、`anthropic-version` 请求头。
- Gemini：`POST /api/model/v1beta/models/{model}:generateContent` 或 `:streamGenerateContent`，流式上游使用 `alt=sse`，基础地址通常以 `/v1beta` 结束。
- `GET /api/model/v1/models`、`GET /api/model/v1beta/models` 返回当前 Key 获准使用的模型。
- 未实现图片、音频、Realtime/Live 音频 WS、批处理、后台 Responses、独立 compact 和外部 conversation/cache ID。此版本是文本和函数工具分发，不宣称所有供应商接口完整覆盖。

访问头为 `Authorization: Bearer <机器 Key>`，原生 Anthropic/Gemini 客户端也可分别使用 `x-api-key` / `x-goog-api-key`。不支持将 Key 放进 URL 查询参数，以免写入访问日志。绑定机器是用量归属，不做来源 IP 限制；需要避免将机器 Key 共享给其他设备。

WS 每连接一轮在途响应，最多保持 55 分钟，空闲两分钟关闭；重连由客户端处理。不支持同连接多路并行，新的并行创建会收到明确的 429 事件。每个 `response.create` 重新鉴权和预留，重置/停用 Key 后即使连接还在也不能继续发起请求。`previous_response_id` 仅接受属于同一 Key、同一上游且用量已确认的响应，并把历史上下文计入预留。HTTP/WS 的失败、中断及缺失 usage 都沿用保守计量。

## 计量与运行

额度按输入和输出 Token 合计，支持 UTC 自然日、自然月及累计额度。请求发出前按输入字节数加协议余量及最大输出量预留；这是保守估算，不是所有模型的精确 tokenizer。完成后按上游 usage 结算。未返回 usage 时保留估算扣额并标记；后续收到实际 usage 会修正，重复回调不会重复计费。上游不遵守输出上限时仍记录实际消耗，供应商账单以其计费规则为准。

Anthropic 输入包含普通输入、缓存写入和缓存读取；Gemini 使用总 Token 减输入作为输出（含思考 Token），不重复叠加缓存或每个 SSE 分片的累计计数。Responses 读取终态事件内的 usage；Anthropic 合并 message_start 与 message_delta 计数。

每个模型可分别配置普通输入、缓存读取、缓存写入、输出的 USD/百万 Token 单价。普通输入计费量为总输入减缓存读写，缓存部分只计一次；OpenAI/Responses 与 Gemini 的缓存读来自各自 usage 明细，不额外叠加到输入总量。请求预留时保存价格快照，使用 BCMath 十进制定点计算到美元小数点后十二位。缓存明细缺失时费用标估算，usage 缺失或缓存计数矛盾时费用标未确认，未配置单价时标未定价；不拿新的费率回填旧记录。机器 Token 限额仍按真实用量，费用只统计、不从用户余额扣款。能力测试也保留缓存读写与费用，独立于机器额度。

## 能力测试

“能力测试”可手动开始，或单独开启 15 分钟至 7 天的定时间隔，默认关闭。手动请求先排队，`model-relay:quality` 每分钟处理最多两轮；普通分发请求不会被注入测试题。每轮最多八道题、每题最多 1,024 输出 Token，逐题调用并记录真实用量，未知用量不伪装为零。检测使用独立的上游请求，消耗供应商额度但不扣机器 Key 额度。

默认三道随机数字的糖果收支、逆推和多轮条件题，也可替换为自定义题干和精确标准答案。输出要求 JSON `answer`，比较由应用代码完成，不让另一个模型打分。题库、模型或输出参数变化时清除旧基线；完整测试可手动设为基线。未达阈值或较基线下降达到设定值，连续指定次数后标记并可选通过已有 Telegram 管理员通知发送提醒。连接失败、超时或未完成不记为零分“降智”；不会据此自动切模型或停用上游。少量题只提供能力抽样信号，不鉴定模型身份或整体智力。

结果包含每题输入、标准答案、实际文本、计分、耗时和用量。最多保留九十天记录，基线记录例外。定时检测和 Telegram 提醒均需单独开启，不修改原备份与通知配置。

协议参考：[Responses WebSocket](https://developers.openai.com/api/docs/guides/websocket-mode)、[Anthropic Messages](https://platform.claude.com/docs/en/api/http/messages/create)、[Gemini 内容生成](https://ai.google.dev/api/generate-content)。

网关将待结算计数写入私有 spool，定期重试。`model-relay:maintain` 每十分钟处理超过二十分钟的未完成请求，并分批保留最近九十天的请求明细；累计额度计数不随明细清理。日志不保存提示词、回答、API Key 或上游错误页面，只保存模型、用量、状态、错误类别和耗时。

Go 进程只监听 `127.0.0.1:18941`；Nginx 只公开 `/api/model/v1/`。内部接口使用独立随机密钥，Nginx 限制回环来源；上游 TLS 校验开启，不跟随重定向，不允许上游解析到内网地址。此服务不修改系统默认路由或代理配置。

## 构建与检查

```sh
go test ./...
go vet ./...
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags='-s -w' -o relay .
systemctl status buncloud-model-relay
journalctl -u buncloud-model-relay --since '15 minutes ago'
```

`buncloud-model-relay.service` 和 `nginx.conf` 是部署模板。接入上游后可在页面测试已保存的 `/models` 配置，此测试不执行对话。开发测试使用本地模拟上游；部署检查不自动导入任何已有账号或 Agent Key，也不自动发出计费对话。真实请求测试需另行指定上游和低额度实验 Key。

## Grok 实测

2026-09-12 经授权使用现有供应商的 `grok-4.6` API，通过生产公开分发入口完成低额度实验：

- 独立 Key 的模型列表只返回获准的 `grok-4.6`。
- 普通对话返回 200，约 6.1 秒，实际结算 632 Token。
- SSE 返回 200，约 3.6 秒收到首个事件、3.7 秒结束，收到 `[DONE]` 和 usage，实际结算 484 Token。
- 未授权模型返回 403、超过 Key 输出参数上限返回 422、额度不足返回 429。
- 重置后旧 Key 返回 401、新 Key 返回 200；实验完成后停用 Key，返回 401。实验记录保留。
- 工具兼容性未通过：强制指定函数在分发入口 90 秒超时；同样请求直接使用 PHP cURL 调上游也在 40 秒超时。自动工具选择返回 200、实际结算 609 Token，但没有返回要求的工具调用。不能据此宣称该供应商工具功能可靠。

此次上游返回的 `completion_tokens` 包含推理用量，超过请求中的 `max_tokens: 128`。分发模块按实际 usage 结算，不截断计数；上游输出参数不是绝对消费上限。超时且缺少 usage 的请求单独标记估算扣额，不计为零消费。

最终账本为 1,725 Token 已确认用量、4,680 Token 估算扣额，预留归零，实验 Key 已停用。强制工具请求虽然收到上游 HTTP 200 头，响应体在八分钟内未完成；已将此类读取中断或超时的后续诊断改为 `upstream_network`，不再误报为 JSON 格式错误。既有实验记录保留原值。
