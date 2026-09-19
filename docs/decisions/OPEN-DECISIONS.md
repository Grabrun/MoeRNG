# 悬而未决登记册（OPEN-DECISIONS）

> 规则：只追加 + 就地关闭（OPEN → RESOLVED，补 Resolution）。每次 Phase 开始时把未决项复现到工作上下文最前面。
> 三类固定 slug：`waiting-on-external-condition` / `design-decision-to-evaluate` / `existing-design-boundary`

| Date | Source | Open Item | Related Constraints | Current Leaning | Blocked By | Resolves When | Status |
|------|--------|-----------|---------------------|-----------------|------------|---------------|--------|
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 尺寸参数名用哪个 | 需与既有 `category`/`type` 命名风格一致；避免与 `thumbs` 字段混淆 | `size`（与 `THUMB_SIZES` 术语同源） | 用户确认 | 用户回复方案确认时 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 非法 `size` 值的处理 | 既有风格：参数错误应可诊断（不静默） | 400 + 列出合法值 | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 目标尺寸不存在时的行为 | 历史图片可能没有 sm/lg（未跑「补全历史缩略图」）→ 若直接 404 会让调用方炸 | 兜底链 请求尺寸→md→原图（复用 `displayUrl`），绝不 404 | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 是否同时给 `/api/v1/images` 加 `size` | 列表页是缩略图最大消费者；两端点参数应一致 | 加（同一套解析与白名单） | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | API 是否按需生成缺失的缩略图尺寸 | 写路径 + 高并发 + 限流；与只读 API 定位冲突；已有后台「补全历史缩略图」 | 默认关，仅留后台开关（默认 off） | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 302 响应能否加 `Cache-Control` | **加长缓存会破坏"随机"语义**（同一 URL 在缓存期内反复返回同一张图） | `/random` 加 `no-store`；只有非随机端点才考虑短缓存 | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 签名 URL 时效（默认 `signed_ttl=300`）对列表页是否够用 | 本地 `/files` 已 `private, max-age=60`；云端预签名同样 300s | 不改默认值，改为在文档里讲清"返回 URL 有时效、别长期存库" | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 本次迭代的版本号 | 1.5.0 线未发布（`1.5.0-beta.1`）；新增并发兼容功能**不得**落在 patch 段 | `1.5.0-beta.2`（同一未发布 MINOR 线，功能同批收口） | 用户确认 | 同上 | OPEN |
| 2026-09-19 | Phase 0 / 随机图 API 缩略图获取 | 是否提供 `type=proxy`（源站代理字节，可长缓存） | 与"云端走预签名直链、不经过源站"的既有架构原则冲突，且消耗源站带宽 | 不做（默认关，如未来确有需求再单独立项） | 用户确认 | 同上 | OPEN |

## 关闭记录

（暂无）
