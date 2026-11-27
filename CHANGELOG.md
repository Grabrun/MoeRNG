# Changelog

本文件记录 MoeRNG 各版本的变更。格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，版本遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

> **版本谱系说明**：`v1.5.0-beta.1` 是 2026-09-13 开启的开发线（本地媒体根移出 web 根、WebP 转换、
> 缩略图字节回填、API 尺寸选择等能力均在此线实装），开线提交 `b91204e` 明确「发布仍等待许可」；
> 随后该线被 v2 重构取代（`c888590` 取消全部 v1.5 兼容与迁移）——**v1.5 系列从未正式发版**，
> 无 tag / 无 GitHub Release，其能力已并入 v2.0.0-beta 系列。

## [未发布]

### 新增 · 随机图按设备返回横图/竖图

`/api/v1/random?device=pc|pe|any`：`pc` 取 `width >= height`（方图归横屏），`pe` 取 `height > width`，
`any` 强制不过滤；
不传时按 UA 判断（含 `Mobile` / `iPhone` / `iPod` → `pe`），无 UA 的 curl 与服务端调用不加过滤，
取图集合与旧版一致。指定方向没有图时去掉条件重取一次，不返回 404。

判定直接比较已有宽高列，不新增字段、索引与迁移，响应结构不变。

### 修复 · 宽高不等于显示尺寸

上传时按 EXIF 方向 5–8 交换宽高（仅未转码的 JPEG）；批量转码成功后回写输出文件的真实宽高，
存量竖拍照片跑一次「维护 · 媒体维护 → 转换历史原图为 WebP」即可纠正。

### 修复 · 安全审计与过度开发审计（全量）

**会导致功能损坏或数据风险**

- `/admin/images/ids` 调用了不存在的 `Image::query()`，跨页全选必然 500 —— 改为直接预处理查询；
- 自动备份在请求线程同步执行且开始前不落标记：进程被杀后下一个请求立刻重试 —— 先写 `started`，1 小时内不重复；
- 安装向导用原始 SQL 写 `storage_profiles.config`，对象存储 AK/SK 明文入库 —— 补 `CredentialCipher::sealConfig()`；
- `backup` / `backup-delete` / `cache-clear` / `test-mail` 只校验登录、不校验管理员 —— 补 `RequireAdminMiddleware`；
- 上传大小取 `$_FILES['size']`（客户端可伪造）—— 改为 `filesize($tmpName)`；
- 显式选择的存储实例不可用时静默回落默认实例，图片会落进非预期的桶 —— 改为明确报错；
- `Image::delete()` 先删对象再删库，DB 失败即「文件没了、记录还在」—— 改为先删记录、存储失败只记审计。

**静默失败与兼容**

- `Setting::get()` 每请求重复 SELECT，且与 Config 内存副本形成双通道 —— 优先读内存、`set()` 同步回写；
- 日志导出 CSV 未防公式注入（detail 含登录名）—— `= + - @` 开头加前缀；
- `mail_encryption=tls` 实际是明文 —— 实现 STARTTLS，并按域名校验证书（连的仍是已校验 IP）；
- remember cookie 自动登录不轮换 session id —— 补 `Session::regenerate()`；
- `config/.htaccess` 与 `public/uploads/.htaccess` 只有 Apache 2.2 语法 —— 双语法兼容；
- nginx 伪静态片段缺 `backups|var` 与 `.zip`，与正文不一致 —— 同步；
- `Category::getTree()` 逐层查询 —— 一次取全表在内存建树；
- `device` 补 `any`（强制不过滤），文档同步。

**删除不会执行/没有执行点的代码**

- `permissions` 权限勾选（UI + 写入 + 展示）但无任何鉴权分支 —— 移除交互，DB 列保留；
- `backfill-thumbs.php` / `backfill-thumbs-batch.php`（依赖被禁用的 `exec`）、
  `migrate-legacy-refs.php` / `backfill-content-hashes.php`（已被 `migrate-to-latest.php` 覆盖）—— 删除；
- `app.js` 中永不执行的 API 测试器副本（真正生效的是 `front.js`）—— 删除；
- `ApiKey::validate()`、`Category::images()`、`ContentHash::forImage()`、`Image::processingState()` —— 删除；
- Router 的 DELETE / ANY / `{param}` 与 `Request::$params`（全项目无使用者）—— 删除；
- 图库渲染失败只显示空态、不留痕 —— 补 `error_log`。

## [2.1.1] - 2026-11-28

### 文案对齐实现，顺带修掉一处 XSS

全站文案以前是「有想法就写」，不少描述早于实现变更，逐渐变成误导。这次按代码实际行为重写，
并把判断依据固化成 `docs/copy/COPY-GUIDE.md`（读者、语气、术语表、禁止项）。

**纠正的事实错误**：

- README 技术栈写「会话用 Redis」——代码里没有 Redis，是 PHP 原生文件会话；
- README 接口表写 `images?page=1&per_page=20`——实际参数是 `limit`，`per_page` 不生效；
- 首页特性写「数据库级 `ORDER BY RAND()`」——早已改为 COUNT + 随机偏移（正是为避开 filesort）；
- 首页无可用性数据时显示 **99.9%**——纯属编造，改为 `—`；
- 图片页按钮 title 仍写「补算 MD5」——MD5 已退役，只算 SHA-256；
- `sha256File()` 的 docblock 声称返回 sha256+md5 两个摘要——实际只返回一个字符串；
- README 特性列「双语界面」——无此功能；
- 安装完成页的清理路径写成 `/views/install/`——部署后是 `/install/`，且装完已自动锁定。

**修复**：

- `front.js` 在线测试页用 `innerHTML` 拼接 API 路径（含用户可控的 category 参数）→ 反射型 XSS，
  改走 `renderSafeText`；
- 图片管理页搜索框写了两个 `class` 属性，第二个被浏览器丢弃，`flex-1 min-w-200` 一直没生效；
- 删除零引用的 `views/admin/layout.php`（审计报告 MR-62 已记录其未转义输出风险）；
- 哈希回填按钮的两段式确认曾把文案硬编码在 JS 里，改视图文案后还原失效——改为记住初始 `textContent`。

**统一**：

- 前台、后台、安装向导、系统提示统一中文与术语；44 处「中文 + 半角冒号」改全角；
- 后台标题与侧栏品牌名改读 `settings.site_name`，不再写死 MoeRNG；
- 空状态统一为「还没有 X + 下一步怎么做」，不再出现「暂无」「敬请期待」。

## [2.1.0] - 2026-11-28

### 队列消费者可在服务端自行循环（不依赖 exec / fork）

宝塔默认把 `exec` / `proc_open` / `popen` / `shell_exec` / `pcntl_fork` 全部列入
`disable_functions`，原先「父脚本 exec 起子进程」的 worker 在这种环境里根本起不来 ——
唯一的触发器就只剩浏览器标签页，关掉即停摆。

- `ImageController::processQueueBatch()` 拆出来：只返回结果数组，不输出、不 `exit`；
  HTTP 端点 `processQueue()` 退化成「调它 + `json()`」，两条入口共用同一份实现；
- `cli/process-queue.php` 改为**单进程内循环**：不再 fork、不再 exec，直接调上面的方法；
- 新增内存看门狗（`--max-mem=MiB`，默认取 `memory_limit` 的 75%）：单进程长跑无法靠换进程
  回收内存，到阈值主动 `exit(0)`，交给进程守护重启；
- `--loop[=秒]` 常驻、`--batch=N` 单批张数，语义不变；`cron` 仍可每 5 分钟跑一次清空即退；
- 致命错误统一走 `markInflightFailed()`：HTTP 的 shutdown 钩子与 CLI 共用，在途行标 failed
  （不再等 5 分钟复位窗口）；
- 每轮写 `storage/queue.heartbeat`，健康检查「图片处理」一项显示「消费者 N 秒前心跳」，
  没心跳就明说「未检测到队列消费者」——不再只报「待处理 N 张」让人猜；
- `docs/BT-DEPLOY.md` 第 5 节重写：路径改回部署后的 `cli/`（原先写成 `src/cli/`，照抄报
  `Could not open input file`），补宝塔「进程守护管理器」配置与 `exec` 自检；
- `cli/backfill-thumbs.php` 仍依赖 exec，检测到被禁用时给出可执行替代（后台「补全缩略图」按钮），
  不再抛未捕获的 `Error`。

### 上传判重重构：内容哈希索引 + 原子占位

**为什么改**：旧判据是「先查后插」——两个人同时传同一张图必然各插一行；判重还在转码之后，
重复的大图要先白做一次解码 + 编码；MD5 存在 `CHAR(64)` 里，尾空格比较受排序规则影响，
NO PAD 下裸比较永不命中。

**新链路**：算源哈希 → 抢位 → 转码 → 产出哈希二次查 → 落库并回指

- 新增 `content_hashes` 索引表（主键 `sha256`，`image_id` 外键 `ON DELETE CASCADE`），承载四类哈希：
  `source`（源字节，唯一去重键）、`stored`（落盘产物）、`thumb_sm/md/lg`（三档缩略图）。
- 抢位用 `INSERT` 撞主键判并发（errno 1062），不再有「先查后插」的时间窗；
  `image_id` 为空的占位表示「正在处理」，10 分钟不动由队列每轮 `sweepStale` 回收。
- **判重提前到转码之前**：重复图不再解码/编码，直接返回已有图 —— 这是本次最大的收益。
- 产出哈希只承担两类识别：老行只存了落盘字节、用户把本站产出的 WebP 或缩略图又传回来。
  同一张原创图是否重复仍只看源哈希（转码参数变了也不会失效）。
- 命中处理失败的旧行时删掉它（级联释放哈希）再按新图处理，不再永久占住哈希。
- 前端预检：`crypto.subtle` 本地算 SHA-256 → `POST /admin/images/precheck` 批量问一次，
  命中就不传字节；文件数 > 40 或总量 > 256MB 时跳过，预检失败自动退化为照常上传。
- 缩略图哈希在生成端记录（tmp 删除后就没机会），队列与补全两条路径都写索引。
- 历史原图转码、哈希回填都同步维护索引；旧值只在确实指向本行时才释放。
- 健康检查「上传去重判据」改为索引自证：报出索引覆盖率、历史重复组数、待回收占位数，
  表缺失可一键重建；`doctor.php` 增加该表的存在性检查。
- 新增 `php src/cli/backfill-content-hashes.php [--apply] [--limit=N]`（默认干跑）。

**兼容**：索引未覆盖的历史行按 `file_sha256` 列兜底一次（只比 SHA-256，不碰 MD5），命中即补写索引。

### MD5 退役与一次性迁移脚本

**为什么不只是删一处判重**：MD5 曾同时出现在写入、判重兜底、哈希回填、健康检查、`schema.sql`、
`schemaExpectations()` 六处，任何一处漏改都会让「判重看起来正常但静默失效」。

**运行时彻底不再出现 MD5**：

- `images.file_hash` 从 `$fillable`、`schema.sql`、`schemaExpectations()` 中移除；
- `Image::findByContentHash()`（含 `TRIM()` 尾空格兜底）删除，替换为只按强哈希查的 `findBySha256()`；
- 上传、历史原图转码、哈希回填都不再计算或写入 MD5；
- 限流键、又拍云签名里的 `md5()` 与图片内容无关，保持不变。

**`php src/cli/migrate-to-latest.php`：一次性迁移到当前架构**（默认干跑，`--apply` 才写）：

| 阶段 | 做什么 |
|---|---|
| 1 | 表 / 列 / 索引 / `status` 死值收敛 —— 直接调 `Application::migrateSchema()`，与运行时同一处实现 |
| 2 | `storage_profile_id` 按旧 `storage`/`storage_provider` 推断回填、清空历史 `url` |
| 3 | 补 `file_sha256` 并写入 `content_hashes`（列内已有则跳过回源） |
| 4 | 清理悬空索引与超时占位 |
| 5 | 门禁：仍有图片读不到字节时拒绝删列（`--force` 才越过） |
| 6 | 导出 MD5 映射到 `storage/backups/images-md5-<ts>.jsonl` |
| 7 | `DROP INDEX idx_file_hash` → `DROP COLUMN file_hash`（限 `lock_wait_timeout=10`） |
| 8 | 写 `settings.schema_version`，运行时启动不再重跑迁移 |
| 9 | 终态校验：表 / 列 / 索引 / 归属 / url / status / 索引覆盖率逐项核对 |

- 幂等：每个阶段都可重复执行；列已删时只报告不重复 `ALTER`。
- 分批：`--limit=N` 限制本次回源张数；`--skip-remote` 不下载云端对象。
- 不再各自一套：限流表建表逻辑收敛到 `Application::ensureRateLimitTable()`，
  存储归属推断收敛到 `StorageProfile::resolveLegacy()`，`migrate-legacy-refs.php` 同步改为调用它。
- API Key 明文迁移仍由 `migrate-apikey-hash.php` 单独执行（需要分发新 Key，不适合与通用迁移混跑），
  通用脚本只在终态校验里提示。

**升级顺序（重要）**：先覆盖部署新代码，再跑迁移 —— 旧代码带 `WHERE file_hash = ?`，删列后会报错。

### 修复：CLI 脚本连不上数据库（`2002 Connection refused`）

`Config::init()` / `Database::init()` 只写在 `Application::bootstrap()` 里，而那只在 HTTP 入口
（`index.php` / `admin.php` / `api.php`）被调用。CLI 脚本 `require bootstrap.php` 后直接
`Database::getInstance()`，拿到的是**空配置** → 默认连 `127.0.0.1:3306`、空库名空账号，
在 socket-only 或非默认端口的 MySQL 上必然 2002，站点本身却一切正常。

- `Application::bootstrapCli()`：CLI 专用引导，只加载配置、连接参数与站点设置，
  不启 session、不发响应头、不跑自迁移；
- `bootstrap.php` 在 `PHP_SAPI === 'cli'` 时调用它，所有 CLI 脚本一次性受益；
- 连接参数收敛到 `databaseConfig()`，HTTP 与 CLI 不再各写一份；
- 未安装站点直接返回，保留「站点尚未安装」的提示语义。

影响面：`migrate-to-latest.php`、`process-queue*.php`、`backfill-*.php`、
`migrate-legacy-refs.php`、`migrate-apikey-hash.php` 此前在「MySQL 不走 127.0.0.1:3306」
的环境上全部不可用（只有 Web 后台能跑）。

### 设置页保存条改为悬浮按钮

原来是一条通栏卡片：满宽背景 + 边框 + 顶部圆角，里面只放一个按钮，四分之三是空白，
看起来像个空盒子；同一条里还挂着一个从来没人写入的 `save-hint` 占位。

- 改成贴在右下角的胶囊按钮：不再有容器背景/边框，靠阴影与页面区分层次；
- 空白区域 `pointer-events: none`，悬在内容上时不会挡住下面的控件点击；
- 按钮补齐 `:hover` 位移与阴影（否则会被基础按钮样式覆盖，失去反馈）；
- 删掉 `save-hint` 元素与它的两份重复 CSS。

### 修复：后台移动端布局被桌面规则覆盖

`.admin-layout` / `.sidebar` / `.main-content` 在样式表里被**完整定义了两遍**，后一份
排在移动端媒体查询之后，于是它的 `margin-left: 240px` 恒胜出 —— 手机上面包屑式的抽屉
侧栏虽然被 `translateX(-100%)` 移出屏幕，主内容仍留 240px 空位，整页被挤出视口。

- 让位宽度改为只在 `@media (min-width: 769px)` 生效；
- 移动端恢复 `margin-left: 0`，并用 `padding-top: 64px` 避开固定的菜单按钮；
- 删掉重复的第二份后台布局定义。

### 常驻队列消费者：断连自愈与日志实时化

守护进程要能长期无人值守，光有 `--loop` 不够，还有三处会让它「活着但不干活」：

- **连接自愈**：MySQL 的 `wait_timeout` 会掐掉空闲连接，而 PDO 懒连接不会自己重建 ——
  空闲几小时后 worker 每轮都拿到同一个死连接、无限报错。新增 `Database::ping()`：
  空闲超过 60 秒先探活，失败即丢弃实例；异常分支同样 `reset()`，下一轮重建连接。
- **执行时限**：`processQueueBatch()` 里原来无条件 `set_time_limit(120)`。CLI 下这个时限按
  **累计 CPU 时间**计（GD 转码全算），常驻进程迟早触发 fatal 被杀。改为仅 `PHP_SAPI !== 'cli'` 时设置，
  HTTP 端点行为不变。
- **日志实时落盘**：stdout 被重定向到文件/管道时 stdio 是块缓冲，不 flush 要攒够 4KB 才写 ——
  守护里表现为日志永远 0 字节，排障等于没有日志。`$say` 改为 `fwrite(STDOUT, ...)` + `fflush()`。
- `docs/BT-DEPLOY.md` 进程守护章节补齐四个坑：必须用 CLI 的 php（不是 php-fpm）、运行目录靠字段
  而非 `cd &&`、`storage` 需 www 可写、**覆盖部署后要重启守护**（CLI 不随请求重载代码）；
  systemd 单元补全 `[Unit]`/`[Install]` 与 `User=www`。

## [2.0.0] - 2026-11-17

### 注释治理：全库去历史化（代码零改动）

- 注释量 3,292 → 650 行（PHP 2,600→569、JS 495→59、CSS 166→12、schema.sql 31→10）。
- 只保留解释"为什么"的一行注释：反直觉实现、安全边界、外部硬约束（MySQL 索引 3072 字节、
  `post_max_size`、GD 内存模型），以及 PHP 类型表达不出的语义（`null = 未知，不是 0`）。
- 删除全部版本/迭代表述与审计编号（`v1.x`、`MR-xx`、`CVE-xx`）、事故复盘段落、分节横幅、
  复述代码的注释、只重复签名类型的 `@param/@return`。
- 校验：`php -w`（去注释与空白）逐文件对比 HEAD，88 个 PHP 文件代码 token 完全一致；
  唯一差异是 `CredentialCipher` 写出的 `config/credentials.php` 内的注释文案。
- `.dsh` 中锚定注释文本的断言改为锚定代码结构（CSRF 归属、OOM 预算公式、快速路径顺序、
  size() 签名等），避免"删注释即测试红"。

### 架构收敛：过度防御与重复规则治理（审计后全量修复，不新增功能）

> 审计结论：真正的成本不是"抽象太多"，而是**同一规则被复制到 2–4 处**，且已出现可验证的漂移。
> 本次按"删除 > 内联 > 收敛 > 复用"的顺序处理，未引入任何新框架/新抽象。

**单一来源（消除已发生的漂移）**

- Schema 期望清单四处收敛为一处：新增 `Application::schemaExpectations()`，
  运行时自迁移、后台健康检查、`doctor.php` 全部消费它。此前 doctor 的清单缺
  `thumb_meta` 生成列与 `idx_status_thumb_meta` / `idx_path`（真缺索引时仍报"全部就位"），
  且把 `storage_profile_id` 写成 `INT UNSIGNED`（其余三处与 schema.sql 都是 signed INT）。
- CSRF 单一归属 `CsrfMiddleware`：删除 `Controller::validateCsrf()` 及 38 处调用。
  此前中间件先返回 419，控制器里为"POST 体被 post_max_size 丢弃"写的 413 提示
  **在生产路径上永远到不了**（超大文件上传只看到"CSRF token validation failed"）。
  413 判定随之搬进中间件；`/admin/login` 改为挂载该中间件，`/admin/logout` 保留手写
  `verifyCsrf`（导航动作需要 flash+302，而不是 419 JSON）。
- 存储实例解析收敛到 `StorageProfile::resolve()`（复用 `find`/`defaultProfile` 的请求级缓存），
  删除控制器自建的第三层 memo（`$profileMemo`/`resolveProfile`，`flushCache()` 管不到它）。
- 存储凭据必填规则收敛到 `StorageProfile::missingCredentialFields()`
  （模型 `isUsable()` 与驱动 `assertCredentials()` 共用；此前两处各写一套 provider 规则）。
- `S3Driver` 6 个分发工厂 + 5 条链式 `if` 折叠为单个 `sdk()` 分发点
  （新增 provider 从改 11 处降为改 1 处）。

**行为修正**

- `/api/v1/stats` 的 `total_images` 改为与 `/images`、`/random` 同口径
  （`status='active' AND process_status='done'`）。此前把 pending/processing/failed 也算进去，
  调用方看到的总数永远大于实际可访问数。
- 图片按钮可用性只保留 `refreshButtons()` 一份规则（`onTick('done')` 改为调用它）。
  此前内联重算且漏了 `uploading`，两条规则已开始漂移。
- `backfillHashes` 的 SELECT 补回 `file_sha256`：此前漏列导致 `$hasSha` 恒为 false（死判断）。
- `StorageProfile::instanceLabel()` 修掉 `region` 未定义键警告（又拍云/OBS 无该键）。
- `Image::driverUrlFor()` 的异常降级补 `error_log`：图片地址生成失败不再完全静默。
- CLI 单批子进程（`process-queue-batch.php` / `backfill-thumbs-batch.php`）修掉实参错位
  （`''` 被传给 `array $headers`，strict_types 下必然 TypeError），并删除伪造 CSRF/启动 session
  的代码 —— 它们直接调控制器，不经过 Router 与中间件。
- `CspNonce::token()` 改为**请求内幂等**：`bootstrap()` 两次 `sendSecurityHeaders()` 会生成
  两个 nonce，此前只靠 `header()` 默认 replace 才没出错；合并后头与 HTML 的 nonce 必然一致。
- SSRF 自检（MR-39）从"每次构造驱动都 error_log"前移到"保存存储实例时校验一次"：
  合法的自建 MinIO（RFC1918）不再让每个请求都刷一条警告。
- 健康检查「上传去重判据」改走 `Image::findByContentHash()`，与运行时同一判据：
  此前自检另写一条 `WHERE file_hash = ?` 裸 SQL，而运行时对 `CHAR(64)` 只存 32 位 MD5 的情况
  带 `TRIM()` 兜底 —— 库排序规则为 NO PAD 时会误报「file_hash 回查不到自己」，
  面板红字与真实去重行为相反。（正式版并入）

**删除（无调用方 / 无收益）**

- `StorageInterface::hashFile()` + 8 个驱动实现（应用代码 0 调用；去重早已改用上传端本地
  `hash_file()`），以及随之失去消费者的 `S3Driver::hashUrl()`。
- 9 个全局助手函数（`config/session/csrf_token/csrf_field/old/asset/url/redirect/json_response`，
  精确匹配 0 调用，仅留 `h()`）；`Request::json()` / `Request::validate()` / `Controller::input()`。
- `Response::text()/file()/image()`（0 调用）、`Model::exists()`、`Image::srcset()` 保留
  （**有 2 处视图在用**，审计初判"死代码"是 grep 误报，已撤回）。
- `stampThumbMetaState()`（纯转发）、`listFilesRecursive()` 的 `$excludeTop`（唯一调用点不传）、
  `getCategoryAndChildIds()` 的 `$depth`（实现根本不读，两参调用会静默返回整棵子树）、
  `assetParts()` 的 `layout` 键与 `LAYOUT_V2`（恒为 'v2' 的单值字段）。
- `migration_images_storage` 旗标（写而不读）、`ensureStatsTables` 的 `!== false` 死判断、
  `RateLimiter::reset()` 的不可达分支、`FileController::profileRoots()` 的重复默认实例分支。
- 前端 6 个批量循环的请求样板收敛为 `postBatch()`（终止条件仍各自保留），
  新增批处理入口不必再抄一遍 FormData/CSRF/解析/抛错。

**兼容数据：改为"检测 + 一次性迁移"，不再运行时兼容**

- `Image::url()` 不再回退 `images.url` 列（所有写入路径都写 `''`，回退永不命中）。
- `StorageProfile::driverForImage()` 不再按 `storage_provider` 猜实例：缺 `storage_profile_id`
  时明确报错并提示迁移。猜测会把图片指向同 provider 的另一个桶，且没有任何报错。
- 新增 `src/cli/migrate-legacy-refs.php`（默认干跑，`--apply` 才写）：
  回填 `storage_profile_id`、清空残留 `url`；后台健康检查与 doctor 新增
  `legacy_refs` / `Legacy data migration` 检测项。

### 上传链路修复：去重失效、`size=original`、转码 OOM

- **跨批次去重失效**（三处缺陷，任一都足以静默失效，界面无报错）：`file_hash` 是
  `CHAR(64)` 却只存 32 位 MD5，尾空格比较受排序规则影响 → 改先比定长 `file_sha256`、
  MD5 补 `TRIM`；转码前后各算一套哈希（历史行记上传字节、新行记落盘字节）→ 两套都参与
  查找；旧行缺强哈希时曾用*本次*上传的存储实例去读别人的 path（跨桶必然读不到而放行）
  → 改为按该行自己的 `storage_profile_id` 解析实例。判重统一走 `Image::findByContentHash()`。
- **`size=original` 返回缩略图**：`displayUrlWithSize()` 把 `original` 当成"该档缩略图
  不存在"回退到 md，`/api/v1/random`、`/api/v1/images` 与 `?type=redirect` 全部命中，
  连响应里的 `size/width/height/mime_type/file_size` 五个字段一起错。现于链首短路，
  `'original'` 收敛为 `Image::SIZE_ORIGINAL` 单一字面量。
- **上传路径转码 OOM**：转码预检借用了缩略图口径（≈5P），而 `imagewebp` 编码期还要一块
  同级画布（真实 ≈9P），5600 万–9000 万像素的图正好落在缝隙里，且该调用在 `try` 之外、
  OOM 不可捕获。改用 `originalConvertBudget()`，上传侧分界收紧到约 4400 万像素。
- `backfillHashes` 的 SELECT 补回 `file_sha256`：此前漏列导致 `$hasSha` 恒为 false（死判断）。

### 队列与图片处理管线

- **3 处 P0 资源泄漏**：`backfillThumbs` 取回的原图副本只在 `makeThumbnails()` 之后删除
  （异常路径永不执行）→ 移入 `finally`；`thumbKey()` 在数组字面量里求值，对 v1 布局抛
  异常时已生成的 webp 随栈帧丢失 → 生成端 `try/catch` 自清理。
- **整批卡死在 processing**：单批 20 张常超网关 60s，FPM 被强杀时连 shutdown 钩子都来不及
  跑，本批行留在 processing 且无失败计数。新增 `QUEUE_TIME_BUDGET = 45s`：到点且已做完
  至少一张，就把未碰的行原样还回 `pending` 再正常返回。
- **统计卡显示假数字**：删除请求发出前的 `inflight` 相位预支（「待处理」显示的是
  "上一轮真实值 − batch"）；每批请求加 `AbortController` 90s 超时，挂死的请求不再把按钮
  永久锁在 disabled；处理中也继续轮询 `/queue-stats`。统计三处各写一套 7 条 COUNT，
  收敛为 `queueStatsSnapshot()`（GROUP BY + SUM(CASE) 两条）。
- **移除右下角进度浮层**（视图 + JS 双清）：它由同一批请求驱动，请求一断就永久停在
  「处理中… 0/N」。改用按钮 `busy()` 状态 + 上方四张状态卡。
- **性能**：每批 8 条 COUNT → 3 条（其中两条 JSON 派生计数要对全部 done 行回表解析）；
  新增 `thumb_meta` VIRTUAL 生成列 + `idx_status_thumb_meta` 让队列判据可走索引
  （选 VIRTUAL 因 MySQL 8.0 追加列是 INSTANT，STORED 会重建表）；
  `memory_get_usage(true)` 改为请求级基线快照 —— 该值在请求内单调不减，逐张现取会让
  靠后的图被误判 `too-large`，换个处理顺序结果就不同。
- **新增 CLI worker**：`php src/cli/process-queue.php [--loop[=秒]] [--batch=张数]`。

### 全项目审计：37 项发现，修复 35 项

- **阻断 5**：`schema.sql` 注释含分号致全新安装少建两张表（切分前剥离整行注释）、
  `batchCategorize` 调 protected 无参 `Model::update()`（该端点自上线起每次 500）、
  `.htaccess` 根级 `<FilesMatch "\.php$">` 致 Apache 全站 403（且 `storage/` 漏在拒绝
  列表外，可绕过 `/files` 签名直下）、限流被任意 `X-API-Key` 头换桶绕过、
  `rate_limits` 唯一键在存量库永不建立（`ON DUPLICATE KEY` 退化为纯 INSERT）。
- **严重 7**：`queueClear` 与 `processQueue` 竞争产生孤儿对象（改条件 UPDATE 原子认领）、
  存储实例删除 TOCTOU、`/tester` 存储型 XSS、`/files` 缺 `nosniff` 且 SVG 内联可执行、
  `api_stats` 计数不可达（`Response::json` 结尾 `exit`）、分类 slug 冲突 500、
  限流 DB 异常 fail-open 改 fail-closed。
- **一般 14**：列表缺 `process_status='done'`、分类 N+1、响应式 grid 退回裸 `1fr` 等，
  明细见 `docs/audit/`。

### 工程化

- GitHub Actions 三道门禁：`php -l`（真实解释器全量）、`php-parser` AST 级检查
  （语法 / 未定义变量 / 跨文件引用对账）、`composer audit` 占位。
- 新增 `docs/audit/` 两份报告（全项目审计、图片处理架构分析与优化方案）。

**部署注意（覆盖部署必读）**

1. 覆盖部署即可，**不要删 `config/`**：包内不含 `config/app.php` 与 `credentials.php`。
2. 升级后打开后台「系统设置 → 健康检查」，确认四项：`schema` 全部就位、`legacy_refs`
   为 0、`image_queue` 正常、「上传去重判据」正常。
3. 若 `legacy_refs` 非零：`php src/cli/migrate-legacy-refs.php`（默认干跑）→ 确认映射后
   加 `--apply`。未迁移的历史图片读取时会**明确报错**，不再静默猜存储实例。
4. 存量库由运行时自迁移追加 `thumb_meta` 生成列与 `idx_status_thumb_meta`
   （`ALGORITHM=INSTANT`，不重建表、不锁写）。
5. 队列可用后台按钮驱动，也可 `php src/cli/process-queue.php --loop=10`；两者不要同时跑。
6. 传一张此前传过的图片，验证跨批次去重生效。
7. 本地开发环境：PHP 8.5 起 `$http_response_header` 有 deprecation 提示，不影响 8.4 目标版本。

**验证**

- PHP 语法：90 个非 SDK 文件 `php -l` 全绿 + `php-parser` 2920 文件解析通过。
- harness 28 个脚本全部通过；`click_test` / `scope_check` / `hero_stats` 通过。
- 本轮注释治理额外做了 `php -w` 逐文件对比：88 个 PHP 文件代码 token 与改前完全一致。
- **未做运行时验证**（本机无 PHP+GD+MySQL）：「5000 万像素上传不转码但正常入库」、
  「跨批次去重」、「迁移脚本干跑映射」三项需部署后实测。

## [2.0.0-beta.18] - 2026-10-12

### 安全加固：深度审计 P1 修复

- MR-01 会话硬化：CspNonce 三处裸 session_start() 统一改走 Session::start()，
  bootstrap 增加统一硬化会话入口，PHPSESSID 恒带 HttpOnly/SameSite=Lax/Secure。
- MR-02 角色守卫：新增 RequireAdminMiddleware，用户管理/API Key/存储配置/站点保存/
  图片删除等 23 条高危路由强制 admin；editor 仅保留上传/分类/浏览/队列操作。
- MR-03 API Key 哈希化：落库改为 SHA-256 哈希 + 前缀，明文仅生成时返回一次；
  新增 src/cli/migrate-apikey-hash.php 存量 Key 重发工具（默认 dry-run，幂等）。
- MR-23 API Key 管理页不再嵌入完整明文（移除 data-key），「仅展示一次」承诺成立。
- MR-04 又拍云签名按官方规范重写（_upt=md5 中段 8 位+etime，路径参与签名），
  新增独立 token_key 防盗链密钥字段并纳入密封；未配置时不签名返回裸 URL。
- MR-05 孤儿对象回收：上传失败 catch 分支回滚已上云 key 并审计
  （image_rollback_failed）；queueClear 先逐行回收对象再删库，orphan_risk 改实际残留计数。
- MR-06 COS SDK 钉 tag：受环境限制（本机无 composer）未完成，
  见 docs/audit/MR-06-COS-SDK-pin-note-2026-10-12.md。
- MR-07 发布链：打包/发布脚本迁入版本控制 scripts/（令牌改环境变量、确定性选包），
  发布时产出 .sha256 校验和资产。
- MR-08 CI：新增 .github/workflows/ci.yml（PHP 语法门禁 + node harness 门禁 +
  依赖审计预留）+ .github/dependabot.yml。
- MR-12 存储凭据回显脱敏：控制器响应与列表 data-config 对 secret/key/token_key
  掩码/过滤，编辑留空=保留原值。
- MR-36 密封裸 AccessKeyId：CredentialCipher::isSecretField 增补 key/token_key
  精确匹配，下次保存 profile 时自动密封。
- 验证：PHP 语法 2916/2916 全绿、未定义变量检查 58 文件零命中、
  五个 MR 复刻断言脚本全部通过；运行时行为（真实 DB/对象存储/签名 URL）待部署环境验证。

## [2.0.0-beta.17] - 2026-10-03

### 修复：删除图片时对象存储残留缩略图（软删除疑云根因）

- 此前 `Image::delete()` 只删除主文件 `path`，同一资产前缀下的三档缩略图
  （`thumb-sm/md/lg.webp`）残留在对象存储——删图后存储不释放，看起来像
  「软删除」。数据库侧始终是硬删除（DELETE 行），无软删除逻辑。
- 修复：删除时按 `thumbs` JSON（权威登记，`thumb_path` 列 = md 冗余）
  逐 key 删除全部缩略图，主文件与缩略图去空去重后一次性处理。
- 存储删除失败不再静默吞掉：DB 删除成功后若存储删除有失败项，写入审计
  `image_delete_storage_failed`（含 id 与失败路径清单），便于排查孤儿文件。
- 说明：历史已删除图片留下的孤儿缩略图无法自动清扫（`StorageInterface` 无
  LIST 能力），需到对象存储控制台按 `{yyyy}/{mm}/{uuid}/` 前缀人工清理。
- 验证：PHP syntax 全绿；删除覆盖逻辑三用例断言通过（3 档全删 / 仅 md
  去重 / 无缩略图仅主文件）。

## [2.0.0-beta.16] - 2026-10-03

### 前台各界面文案整体重写

- 首页：hero 副标题改为价值导向（调用一次/拿来即用/完全自控）；
  特性卡全部重写为顺畅叙述（无缓存无规律、子分类一起参与、低于 50ms 等）；
  抽图占位改为「点『试试手气』，随机取一张看看效果」
- 图库：引导语与空态重写（刷新页面换一批 / 去后台上传第一批）
- 关于页：简介改为定位式陈述（图片托管与随机接口一站搞定）；
  技术特性逐条打磨；开源寄语补充
- API 文档：统一全角标点与空格（分类标识（slug）、302 重定向、嵌套 JSON、
  sm（320）等），技术语义零改动
- 回归：PHP syntax 全绿

## [2.0.0-beta.15] - 2026-09-29

### 前台交互文案中文化（front.js 全面审计）

- **修复按钮文字不一致**：在线测试按钮 HTML 已为「发送请求」，但 JS 运行时
  点击后重置为英文「Send Request」——统一为「发送请求」
- 加载态「Loading...」→「加载中…」
- 状态徽标：OK→成功、Failed→失败、Network Error→网络错误、Error→错误
  （HTTP 标准 statusText 如 404 Not Found 保留不动）
- 测试历史记录文案全角化：「302 → 图片（200 成功，X）」；「错误：X」
- 随机图预览 alt「Random Image」→「随机图片」；meta 冒号全角化
  「分类：X」
- 回归：JS 语法（node --check）通过，英文残留复查为 0

## [2.0.0-beta.14] - 2026-09-29

### 前台文案优化（中文优先 + 一致性 + 渲染修复）

- 修复 docs 页渲染 bug：`**功能性要求**` markdown 星号残留 → `<strong>`
- 在线测试页中文化：按钮「Send Request」→「发送请求」；
  「Redirect (图片直出)」→「重定向（直接输出图片）」；结果提示同步
- 页脚中文化：「Open-source under MIT License」→「开源项目（MIT License）」
- 首页统计说明精简：「实时统计 · 图片与分类随后台更新」
- 关于页 GitHub 未配置提示改为面向访客的明确表述
- 回归：PHP syntax 全绿

## [2.0.0-beta.13] - 2026-09-29

### 图片处理页按钮合并：「重试失败项」

- 合并原「重试全部失败」+「重试元数据补全」两个按钮为单一「重试失败项」
- 一键依次完成三步：① 主图失败项（process_status='failed'）重新入队；
  ② 处理队列（含刚入队的失败项，进度条 + 统计联动）；
  ③ 缩略图元数据失败项（partial/failed）定向重试
- 快路径：两者皆空时提示「没有需要重试的失败项」，不再空跑请求
- 按钮禁用逻辑合并：主图失败与元数据失败皆无时才置灰
- 删除 queue-retry-meta 按钮（HTML + JS 事件），runThumbMetaRetry 函数保留复用；
  提示条措辞同步更新
- 回归：JS 语法（node --check）、PHP syntax 全绿

## [2.0.0-beta.12] - 2026-09-29

### 处理队列触发策略重构（按用户诉求）

- **移除**「页面加载 1.5s 后自动消费处理队列」—— 后台访问图片页不再随时触发处理
- **保留两个触发时机**（用户指定）：① 上传批次完成后自动跑一次（一次清空全部
  pending）；② 用户手动触发（图片处理页「处理」按钮 / 图片管理页「重试失败项」）
- **并发防护**：processQueue 标记 processing 改为条件更新（AND process_status='pending'），
  并发请求（上传完成 + 手动同点）不再重复拾取同一批；被抢先时整批放弃、
  下次轮询自然拾取剩余
- **batch 3 → 10**：前端 BATCH 与服务端默认值（processQueue / backfillThumbs）
  同步放大，积压处理轮数减少约 2/3
- 历史积压不依赖页面访问：`php src/cli/backfill-thumbs.php` 一次补齐
- 回归：PHP syntax 全绿、php_undef 全绿、JS 语法（node --check）通过

## [2.0.0-beta.11] - 2026-09-29

### 新增：前台图库缩略图一键补全（CLI 工具）

- **背景**：前台 /gallery 对「缩略图缺失」的图片回退加载原图 —— 275×206 的显示空间
  加载数 MB 原图，带宽浪费严重（用户反馈「使用了原图而不是合适的缩略图」）
- **根因**：缩略图为异步队列生成（processing_state.thumb_meta 停在 pending）；
  历史图（v2 迁移前）与从未触发队列的图没有缩略图 → displayUrl('sm') 回退链
  （sm → md → 原图）到底 → 前台 /gallery 加载原图。前台代码本身已正确使用缩略图
  （displayUrl('sm') + srcset sm,md + sizes 240px），无需改动
- **交付**：`src/cli/backfill-thumbs.php`（父循环脚本）+ `src/cli/backfill-thumbs-batch.php`
  （单批子进程）—— 构造带合法 CSRF 的 Request，走 ImageController::backfillThumbs()
  同一份生成/上传/落库逻辑（零分叉），部署后执行一次即补齐全部历史缩略图，
  前台 /gallery 自动改用 sm 320w 缩略图（1.16x 超采样，带宽 -90%+）
- **用法**：`php src/cli/backfill-thumbs.php [--batch 10] [--retry]`；
  亦可使用后台「图片管理 → 补全历史缩略图」按钮（同一逻辑）
- 回归：syntax 全绿、php_undef 全绿

## [2.0.0-beta.10] - 2026-09-29

### 修复：首页白屏（hotfix）

- **根因**：beta.9 在视图（front_header.php / admin/layout.php）中引入 `<script<?= CspNonce::attr() ?>>`，
  未限定名调用依赖「视图在 App\Core 命名空间上下文被 Controller::render() include」的隐式约定；
  若任一渲染路径不在该上下文（install 旁路等），解析为 \CspNonce 触发 PHP Fatal Error（Class not found）
  → 输出缓冲（ob_start）被丢弃 → 整页白屏，banner 未渲染（preload not used 伴随警告）
- **修复**：视图内改用全限定名 `\App\Core\CspNonce::attr()`（不依赖命名空间继承，任何上下文均正确解析）；
  字体切换脚本 try/catch 静默降级（异常不中断页面）
- **伴随**：CSP `connect-src` 放行字体两跳域（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com），
  消除 preconnect 被 `connect-src 'self'` 拦截的控制台报错
- 回归：全量 harness 全绿（syntax/design 63/api 67/hero 36/perf 74/layout 125/矩阵/scope/click/front 运行时）

## [2.0.0-beta.9] - 2026-09-29

### 前端性能优化（不影响功能）

- P1 零感知层：字体 preconnect ×2（miaoda.feishu.cn + sf3-scmcdn-cn.feishucdn.com）与 dns-prefetch；
  .htaccess 静态资源长缓存（public, max-age=31536000, immutable，ASSET_VER 指纹安全，Apache 对齐 nginx）
- P2 渲染层：字体 CSS 异步化（media=print + CSP nonce 合规脚本立即切回 all，display=swap 兜底无空白）
- P3 结构性：前台 JS 拆分 front.js（24.5KB 子集：toast/copyText/API 测试/随机图预览/reveal/统计滚动/动态样式），
  前台 JS 传输 157KB→56KB（gzip 后约省 30KB+）；后台 app.js 保持原样零回归
- 维护：layout_contract 版本断言语义化（v2 beta 线，不再写死 beta.2）；新增 docs/scripts/verify_front_runtime.js
  （front.js 前台 DOM 运行时等价验证）；新增 docs/scripts/patch_m6_perf.py（本次变更的可复现脚本）
- 回归：syntax 2912/2912、design_contract 63、api_contract 67、hero_stats 36、perf_contract 74、
  layout_contract 125、page/click/xref/ref 全绿、front.js 运行时链零异常

## [2.0.0-beta.8] - 2026-09-29

> 字体 CSP 修复：思源宋体/黑体恢复正常加载（此前被内容安全策略批量拦截，页面回退系统字体）。

### 🐛 修复

- **字体加载被 CSP 拦截**：M2 引入的字体镜像 `miaoda.feishu.cn` 的 `css2` 入口返回的 `@font-face` 实际指向 `sf3-scmcdn-cn.feishucdn.com` 的 woff2 分片（入口与字体文件两跳分离）；CSP `font-src` 此前仅放行入口域名，导致全部 505 个字体分片被浏览器阻止（控制台批量报错 `violates font-src 'self' https://miaoda.feishu.cn`，页面回退系统字体）
- **修复**：`font-src 'self' https://miaoda.feishu.cn https://sf3-scmcdn-cn.feishucdn.com` —— 放行实测唯一的字体文件域名（精确最小化，未使用宽泛子域通配）

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix / hero_stats 全绿

## [2.0.0-beta.7] - 2026-09-28

> 覆盖安装重置修复：发布包与仓库不再携带 `config/app.php`（`installed=false`），覆盖部署不再把已安装站点重置回安装向导。

### 🐛 修复

- **覆盖安装重置根因**：`src/config/app.php` 被 git 跟踪且被打进 release 归档——该文件由安装器写入（安装完成 `installed=true`），仓库/归档里的 `installed=false` 版本在覆盖部署时把安装状态覆盖重置，导致每次覆盖后重新显示安装界面
- **修复措施**：① `src/config/*.php` 整体移出版本控制（`git rm --cached` + `.gitignore`；database.php / signing_key.php 两条旧规则同步清理尾随注释——本项目 git 会把行中 `#` 及之后当作模式内容，带注释的规则静默失效）；② `build_release.py` 归档排除 `config/*.php`，仅保留 `config/.htaccess`（防目录列出的安全文件）
- 全新部署仍正常：归档无 config/ 时 `Config::load()` 自愈生成默认 `app.php`（`installed=false`）供安装向导引导，安装完成写盘 `installed=true`；此后任何覆盖（解压 release / git pull）都不会再触碰该文件

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、page_matrix 全绿；归档校验：`config/` 仅含 `.htaccess`，无 `*.php`

## [2.0.0-beta.6] - 2026-09-28

> 空库 404 优雅化：无图/分类无图时的 API 与测试页表现从「裸 404」改为可读引导。

### ✨ 改进

- **`/api/v1/random` 空库响应**：图库或所选分类为空时仍返回 HTTP 404，但 body 为结构化 JSON —— `error` 保持英文机器码 `No images found`（既有调用方零破坏），新增 `code: NO_IMAGES_AVAILABLE` 与中文引导 `message`（「图库暂无可用图片…请先在管理后台上传」）；分类为用户可控输入，插入前已 HTML 转义
- **在线测试页错误渲染**：JSON 分支对非 2xx 响应优雅化 —— 响应体是 JSON 时直接展示服务端 `message`/`error`；响应体非 JSON（nginx 默认错误页等）时提示「网关/伪静态（URL Rewrite）未配置，请求未到达应用层」并保留原始响应供诊断，不再裸显示 `404 Not Found / nginx`
- **API 文档**：补充空库行为说明（404 + JSON 错误体语义）

### ⚠️ 部署提示

- 若线上请求 `/api/v1/random` 仍见 nginx 默认 404 页，根因是 Web 服务器未启用伪静态（URL Rewrite）：`src/nginx.conf.example` 已注明，`location /api { try_files $uri /api.php$is_args$args; }` 未配置时请求不会到达应用层（与图库是否为空无关）

### ✅ 回归

- syntax 2912/2912；API 契约 67 项、design_contract 63 项、hero_stats 36 项、page_matrix / click_matrix / scope / click / xref 全绿

## [2.0.0-beta.5] - 2026-09-28

> M2「晴空画册」视觉重设计落地：全站换肤 + 前台/后台关键结构增强（按已评审设计提案 `design/ui-redesign.html` 执行）。

### 🎨 视觉重设计（全站）

- **双主题设计 token 换肤**：浅色（暖米白 `#FAF6F3` 底 + 珊瑚粉 `#E8597A` 主色 + 鼠尾草青 `#5F9E8C` 辅色 + 蜜橘 `#E8A24B` 点缀）/ 深色（炭紫黑 `#211E26` 底 + 提亮珊瑚粉 `#F2789A`）；圆角收敛（卡片 14 / 控件 10 / 大容器 18），阴影中性化，全面去霓虹与粉紫渐变
- **字体体系**：标题/数字走思源宋体（`--font-display: 'Noto Serif SC'`，含宋体/Georgia 回退栈），正文思源黑体 + 系统中文栈；`miaoda.feishu.cn` 自托管字体镜像（禁直连 Google Fonts），CSP 新增 `font-src` 并放行 `style-src` 字体域
- **前台 hero**：新增 kicker 眉标「自托管 · 真随机 · 治愈感」；banner 容器圆角收敛；stats 三数字加细分隔线（仅桌面横排态，不影响 hero_stats 对齐契约）；feature 卡改左对齐 + 图标前置
- **后台**：侧栏品牌走 display 字体；stat-card hover 上浮保留；设置页 tab 柔光/健康徽标残影随主题色（color-mix）

### 🔧 体积治理

- CSS 重复声明合并（btn/card/th/stat-card/toast/table-wrap/truncate 等 18 处）、reduced-motion 四块收敛为一、粉紫硬编码 rgba 换 color-mix 跟随主题
- 新增 `.gitattributes`（`style.css eol=lf`）：稳定字节口径，消除 CRLF 抖动；最终 `src/public/css/style.css` **90711B < 90KB 预算**
- 分页（transition/hover 上浮）、模态过渡、代码块高度限制等行为规则原样保留，无回归

### ✅ 回归

- design_contract 63 项全过（CSS 体积达标）；syntax 2912/2912；page_matrix / click_matrix / scope / click 全绿；hero_stats 36 项全过；xref_audit 367 个 class 全部可溯源；桌面+移动、浅色+深色截图自检正常

## [2.0.0-beta.4] - 2026-09-28

> 后台「系统设置」排版与 UI 优化（全 CSS 增强，结构/交互挂钩零变动）。

### 🎨 视觉增强

- **设置标签行 pill 化**：active 标签渐变底 + 柔光，非 active 悬停高亮
- **分组卡片标题左缘强调条**：渐变竖条区分分组层级，描述区留白对齐
- **设置项 hover 高亮**：浅紫底 + 圆角，label 加粗；输入控件聚焦环已有，整体层级更清晰
- **批处理面板标题状态点**：统一视觉锚点
- **健康检查结果状态徽标**：`[ OK ]` 绿 pill / `[待修复]` 红 pill（renderHealth 输出 health-ok/health-bad 类）
- **保存栏**：未保存提示改为警告色；工具栏窄屏自动换行

### ✅ 约束保持

- design_contract 63 项全过：分组头/工具栏/3×batch-panel/22 个 JS 挂钩 id/关键文案零变动；CSS 92141B < 90KB 预算；CSP 无 inline style
- scope/click/page_matrix/syntax 全绿；桌面+移动截图自检正常
## [2.0.0-beta.3] - 2026-09-28

> 全站代码检查 + 图片处理架构审计：真实性能修复 3 处 + 死代码清理；域服务抽取因 harness 契约锁定暂缓（路径已文档化）。

### 🔧 维护与性能

- **上传循环 finfo 提出循环**：批量上传时 fileinfo 库不再每张图重建（仅循环外打开一次）
- **大图流式下载**：`FileController::show()` 清 gzip 输出缓冲后 `readfile` —— 此前整文件进缓冲再压缩（图片已压缩，gzip 白费 CPU 且内存翻倍）
- **基线余量常量化**：两处裸 `16777216`（解码/转码 16 MiB 基线）收敛为 `MEMORY_BASELINE_HEADROOM`
- **死代码清理**：helpers.php 删除与 `h()` 完全重复且全站零调用的 `e()`

### 📄 文档

- docs/audit/2026-09-28-image-arch-audit.md：全站检查结论 + 图片处理架构审计 + 域服务抽取边界（harness 方法体级契约锁定；建议先解耦测试再迁移，顺序与落点已给出）

### ✅ 回归

- 全量 19 项 harness 全绿：layout 125 / thumbs 118 / convert 71 / memory_guard 46 / queue 143 / perf 74 / api 67 / design 63 / hero 36 / click_matrix / page_matrix / ref_check / xref / php_undef / data_security / scope / click / verify_autoload；语法 2912/2912

## [2.0.0-beta.2] - 2026-09-28

> 前台「服务可用性」实装：从静态字面量 99.9% 变为近 7 天 API 请求成功率（SLA 口径），随真实请求流量变化。

### ✨ 新功能

- **服务可用性实装**（首页 hero 第三个统计）：`api_stats` 新增 `fail` 列；`api.php` 未捕获异常（5xx）单独记失败（`Stats::bumpFail`），正常响应（含 4xx 业务校验失败——服务正常响应即「可用」）仍走成功计数；`Stats::availability()` 计算近 7 天成功率，首页动态渲染
- **无样本兜底**：新装 / 尚无流量时 `availability = null`，前台显示默认宣传值 99.9%；有样本后显示真实值（1 位小数，如 100.0% / 97.3%）
- 首页统计注释与 hero_stats 契约测试同步（第三个数字为动态值语义，`data-count` 仍仅前两项）

### 🔧 维护

- 深度审计（2026-09-28）：`Application.php` backfill 改为从默认 profile 推导（移除旧设置键 `storage_driver` / `storage_s3_provider` 读取）；`helpers.php` 删除死调试函数 `dd()`；全量 19 项 harness + 语法 2912/2912 全绿
- `SCHEMA_VERSION` 升至 `2026-09-28-2`（api_stats.fail 幂等迁移：SHOW COLUMNS probe → ALTER，权限被拒时降级不破坏站点）

### ✅ 回归

- hero_stats_contract（35）/ layout（125）/ thumbs（118）/ design（63）/ perf（74）/ queue（143）/ api（67）/ convert（71）/ memory_guard（46）/ click_matrix / page_matrix / scope / click / ref_check / xref_audit / php_undef / sdk_integrity / verify_autoload / model_fillable 全部通过

## [2.0.0-beta.1] - 2026-09-28

> **破坏性变更**（用户指令「取消对之前的所有兼容与迁移，使用新版本方案」）：强制统一资产中心方案（v2 布局 + 本地媒体根 storage/uploads），拆除旧布局 / 旧根 / 旧凭据的全部兼容层与迁移工具。升级前务必阅读 src/docs/STORAGE-LAYOUT.md 与 src/docs/BT-DEPLOY.md 的升级注意。

### 💥 破坏性变更

- **存储布局唯一化**：只认 v2 {yyyy}/{mm}/{uuid}/original.{ext} + thumb-{size}.webp；Image::assetParts() 不再识别 v1 形态（返回 null），thumbKey() 对非 v2 路径抛 RuntimeException
- **迁移工具整体移除**：POST /admin/images/migrate-layout 路由、「系统设置 → 存储结构与迁移」面板、app.js::runLayoutMigration/initLayoutMigration 全部删除
- **本地媒体根收口**：/files 读取不再回退 public/uploads 历史根（仅默认根 storage/uploads + 存储实例自定义根）；LocalDriver::legacyUploadDir() / LEGACY_REL_DIR 删除；升级前仍留在 public/uploads 的媒体需手工迁根
- **旧凭据迁移链删除**：migrateProviderCredentials() / migrateLegacyToProfiles() / insertProfile() / hasDefaultProfile() 移除，storage_profiles 是唯一配置来源；doctor 孤儿键检查清单仅保留真正无引用的键
- **清理工具收窄**：cleanupStorage 只保留 storage/incoming 暂存清理（删旧布局对象 / 历史根残留两类分支与统计）

### 🧹 死 API 收口

- StorageInterface::configFields()/name() 声明与 8 个驱动实现（LocalDriver/CosSdkDriver/ObsSdkDriver/AwsSdkDriver/OssSdkDriver/S3Driver/UpyunSdkDriver/QiniuSdkDriver）删除；providerFieldDefs() 保留
- Controller::isPost()、Request::isPost()、StorageProfile::defaultDriver() 删除（全库零调用，xref/ref_check 双验证）
- Application.php 删除整条本地根迁移链（runLocalRootMigration/migrateLocalMediaRoot/rollbackMedia/moveMediaEntry/rewriteLocalProfilePaths/revertLocalProfilePaths）与 LOCAL_ROOT_LAYOUT 门禁；DB 结构自迁移（runStorageMigration + ensure*）保留为部署机制，SCHEMA_VERSION 升至 2026-09-28

### 📄 文档

- src/docs/STORAGE-LAYOUT.md 重写为「仅 v2 布局」单一方案（删除兼容/迁移章节）
- docs/MAINTAINER-GUIDE.md 同步（存储抽象 / 读取快路径 / 已知缺口收口）
- src/docs/BT-DEPLOY.md 升级注意补 v2.0.0 迁根提示
- docs/PLAN-CANCEL-COMPAT-2026-09-28.md 拆除计划（范围表 + A-H 分层 + 验收标准）

### ✅ 回归

- 全量 harness 更新并全绿：layout_contract（125）/ thumbs_contract（118）/ click_matrix / design_contract（63）/ perf_contract（74）按 v2 语义同步；syntax 2912/2912；queue 143 / api 67 / convert 71 / memory_guard 46 / ref_check / scope_check / xref_audit / sdk_integrity / php_undef 全部通过
- 修复 Application.php 常量误删回归（SCHEMA_VERSION 使用未定义 → 补回声明）

## [1.5.0-beta.3] - 2026-09-22

> 深度审计（2026-09-21）驱动的收口迭代：API 缩略图选择、缩略图字节数落库、按处理项补全状态与定向重试。

### ✨ 新功能

- 随机图片 API 缩略图选择：`size=sm|md|lg|original` 四档位，在线测试器接线（ADR-001）
- API 响应只返回请求的图片：单 URL + 短 size 语义（不再混返回多档位）

### ✨ 增强

- 缩略图生成时记录实际字节数（`thumb_bytes`），存量库一键回填
- `processing_state` 列：逐图记录处理状态，支持定向重试失败项

### 🔧 修复

- `Image::$fillable` 补 `thumb_bytes` —— 此前该字段在 hydrate 时被静默丢弃
- 全站 18 项原生 harness 基线 + 4 项工具缺陷修复（syntax_check SKIP、verify_autoload 路径、audit_data_security 补列、p0_scan 范围收敛）
- 空 catch 补 best-effort 降级说明；doctor / 设置页输出符号改为纯文本（P0 纪律对齐）

### 📄 文档

- docs/audit/2026-09-23-deep-audit.md 深度审计报告（基线 18 绿 + 4 工具缺陷 + P1/P2/P3 发现清单）
- docs/MAINTAINER-GUIDE.md 接管基线、docs/DEV-PLAN.md 开发计划

## [1.5.0-beta.2] - 2026-09-14

> WebP 化 + 全站排版与页面节奏统一。

### ✨ 新功能

- 原图转 WebP：新上传默认转换 + 存量库批量转换工具

### 🎨 视觉

- 全站排版层级、页面节奏与表格密度统一；系统设置页 header 节奏 / 双栏网格 / 批量面板收拢

### 🐛 修复

- convert 干跑返回空 500：fatal 守卫 + 单图内存预算；imagewebp 需全画布缓冲，重校内存预算
- cleanup 干跑只扫一个批次（“0 items” 其实是没查全）
- 首页 hero 统计标签基线对齐，数字规则单一来源

## [1.5.0-beta.1] - 2026-09-13

> 统一资产中心布局 + 本地媒体根迁出 Web 根 —— 打开 1.5.0 测试线。

### ✨ 新功能

- 统一资产中心布局（asset-centric object keys）+ 原子迁移工具：老站点一键升级，迁移失败写入 `migration_last_error` 由 doctor 回显
- 本地媒体根迁出 Web 根（`storage/uploads`）；本地图片 URL 改为可缓存读路径
- cleanup 工具：清理升级残留 + 死代码 / 死 CSS / 多余产物

### 🚀 性能

- 读路径缓存存储 profile 与 driver（每请求一次解析）

## [1.4.0] - 2026-09-12

> 测试线 v1.4.0-beta.1 收口为正式版：处理队列重做 + 设置页补齐 + 性能热点清除。

### ✨ 新功能

- 处理队列重做：pending + failed 合并为可筛选列表，单行重试、失败详情面板、实时统计轮询
- 「清空队列」移到队列卡片右上角 popover；处理页去杂乱（按钮不换行、折叠文档与破坏性操作）
- 图片与存储设置 tab 补全（此前为空页）

### 🔧 修复

- 设置页卡死按钮（事件委托失效）；文件名 XSS 修复
- 全站三个每请求/每页热点清除（全站速度扫描）

### 📄 文档

- 统一存储布局提案（asset-centric 对象键）；部署文档同步

## [1.3.1-beta.1] - 2026-09-07

> v1.3.0 正式版之后的首个测试线：前台多页导航、公开图库页、资源缓存戳根治与性能优化。

### ✨ 新功能

- 前台多页导航：单页站点拆分为 `/`（首页）、`/docs`（API 文档 + 速率限制）、`/tester`（在线测试）、`/about`（关于）四个独立页面，导航当前页高亮
- 前台图库页 `/gallery`（公开访问）：按分类分区展示，每个分类随机 12 张（未分类单独区块），刷新换一批；点图新标签查看原图、一键复制链接
- 「管理面板」入口改为新标签打开（导航 + 首页按钮）

### ✨ 增强

- 资源缓存戳根治：`ASSET_VER = APP_VERSION + 静态资源 mtime`，全站 21 处 `?v=` 引用切换——发版或迭代改文件任一变化即刷新浏览器缓存，杜绝「代码已更新、样式不生效」
- 随机图片 API 性能优化：`ORDER BY RAND()` 全表 filesort 改为「COUNT + 随机 OFFSET」两步法，走索引直达目标行，图片量上万后延迟不再劣化（随机分布等价）
- nginx 静态资源长缓存：`/public/` 365d + immutable（配合 ASSET_VER 戳）；宝塔伪静态片段同步适配并补齐上传目录缓存头

### 🔧 修复

- 图库页排版：网格列数全断点固定（手机 2 / 平板 3 / 桌面 4），修复小屏碎片化；分页/空状态样式 CSP 合规

### 📄 文档

- BT-DEPLOY.md 伪静态配置更新（静态缓存块 + add_header 继承坑说明 + 缓存头校验命令）；前台介绍与 README 同步多页/图库形态

## [1.3.0] - 2026-09-05

> 测试线 v1.3.0-beta.1 / v1.3.0-beta.2 收口为正式版。1.3.0 聚合了 1.2.1 正式版以来的全部迭代：登录增强、三轮安全审计修复、凭据加密存储与密钥配置化、内网 SMTP 支持、以及多项 UI 修复。

### ✨ 新功能

- 登录增强：支持用户名或邮箱登录；「记住我」7 天自动登录（HttpOnly + Secure + SameSite Cookie，服务端只存 SHA-256 哈希，登出即注销）
- 存储凭据加密存储：`storage_profiles` 中的 AccessKey/SecretKey 以 AES-256-GCM 密封，密钥由统一配置体系管理（`config/credentials.php`，运行时生成）
- 自托管内网 SMTP 支持：`config/app.php` 显式设置 `allow_private_smtp => true` 后，`mail_host` 可指向局域网邮件服务器（云元数据地址始终拒绝）

### ✨ 增强

- API 文档侧边栏改为 tab 切换，支持四个 endpoint 间快速切换
- 系统设置分组 tab 分页展示；保存条改为贴底常驻工具栏
- 全站版本号单一来源化：`bootstrap.php` 的 `APP_VERSION` 是唯一定义处，页面展示 / 资源缓存戳 / 发布包文件名全部自动跟随
- 登录锁定升级为双维度：IP（原有阈值）+ 用户名（4 倍阈值），防换 IP 爆破单账号
- GitHub Token 改为项目级隔离（发布脚本自动读取，不落系统环境）

### 🔧 安全修复（三轮审计，共 15 项）

- Model 查询接口 SQL 注入加固（CWE-89）：标识符校验 / ORDER BY 白名单语法 / WHERE 片段危险字符拒绝 / LIMIT 强转
- 备份路径穿越（CWE-22）：绝对路径必须位于项目内，相对路径拒绝 `..` 段；备份目录加 Web 防护与随机文件名
- 审计日志敏感字段脱敏（CWE-532）：password / secret 类字段记录为 `***`
- API Key 不再在列表 / 编辑响应中返回完整明文（CWE-312），仅创建时一次性展示
- SMTP 主机防 SSRF（CWE-918）：解析后拒绝内网 / 保留 IP
- 全站 `hidden` 类与 `style.display` 配对冲突排查修复；三种形态重复 class 属性清理
- 管理后台时区显示规范化（PRC → Asia/Shanghai）；上传进度条 / 图片放大首击等多处 UI 修复

### 📄 文档

- 前台介绍与 GitHub README 全面更新（修正 API 参数示例 `format` → `type`，补充安全特性说明）；移除首页「跳到主要内容」skip link

## [1.3.0-beta.2] - 2026-09-05

beta.1 之后的增量：

### ✨ 增强

- 存储凭据加密密钥并入统一配置体系（`Config::get('credentials.key')`，持久化为 `config/credentials.php`，兼容迁移旧独立密钥文件）
- 自托管内网 SMTP 例外：`config/app.php` 的 `allow_private_smtp` 开关（默认 false，云元数据地址始终拒绝）

### 🔧 安全修复（审计第二批）

- 备份路径穿越加固 + 备份目录 Web 防护与随机文件名
- 审计日志 password / secret 字段脱敏
- CsrfMiddleware 显式短路返回；`Response::redirect` 拒绝 header 注入与危险 scheme
- API Key 列表 / 编辑响应移除完整明文

### 📄 文档

- 前台介绍更新（多存储实例卡片、技术特性列表）；README 修正 API 参数示例并补充安全特性；移除首页「跳到主要内容」skip link

## [1.2.1] - 2026-08-23

> 测试线 v1.2.1-beta.1 ~ v1.2.1-beta.3 收口为正式版。

### ✨ 新功能

- 随机图功能增强：Lightbox 大图预览 + 下载 + 元信息行
- 设置页按功能重排分组；Logo 上传合并单按钮
- 本地存储实例支持 CDN 配置；对象存储自定义源站域名（六家）
- SEO/a11y：JSON-LD / OG 补齐 / robots.txt

### 🔧 安全修复

- P0 级 install 重装漏洞：安装向导步骤全部加 installed 锁，杜绝已安装站点被重建管理员
- CSP nonce 加固；诊断端点保留管理员认证

## [1.2.1-beta.3] - 2026-08-23

### ✨ 增强

- API 文档侧边栏改为 tab 切换：点击左侧导航切换显示对应 endpoint（随机图片 / 图片列表 / 分类列表 / 服务统计），不再全部纵向堆叠
- 系统设置分组 tab 生效：设置页按分组分页展示，仅显示当前激活分组
- 设置保存条改贴底常驻工具栏：视觉上不再悬浮遮挡内容

### 🔧 修复

- 「存储用量」标题与数字遮挡：修复重复 class 属性导致 `.flow-summary-head` / `.text-small` 失效
- 系统设置 tab 首屏全板块同页显示：修复 PHP 条件输出与跨行两种重复 class 形态；tab 切换统一用 classList 并让高亮跟随点击
- 设置页滚到底保存条下方空档 + 孤悬「查看操作日志」链接：入口链接移至顶部工具条，页面撑满视口使保存条真正贴底
- API 文档首次进入空白：首个 pane 补初始 `active` 类，首屏即显示随机图片
- 在线测试重定向模式重复点击无请求：redirect 分支给 `<img>` src 加一次性缓存爆破参数；json 分支用 `fetch(cache: 'no-store')`，确保每次点击都发起新请求
- 取消自定义字体（Nunito / JetBrains Mono / ZCOOL KuaiLe），改用系统字体栈（system-ui / ui-monospace），移除 fonts.css 与 10 个 woff2 资源

## [1.2.1-beta.2] - 2026-08-21

### ✨ 新功能

- 随机图功能增强：Lightbox 大图预览 + 下载 + localStorage 历史记录（10 条）+ 元信息行
- 设置页按文档 §四 重排 5 组（基础/安全/图片与存储/系统维护/高级设置）
- Logo 上传合并单按钮（选择即自动上传）+ 默认 Logo 预览
- 本地存储实例支持 CDN 配置
- 对象存储自定义源站域名（COS/OSS/AWS/OBS/UPYUN/Qiniu 六家）
- SEO/a11y：Skip Link / JSON-LD / OG 补齐 / robots.txt

### 🐛 修复

- 数值字段 min/max 误按字符串长度校验（保留天数填 30 误报）
- CSP 拦截对象存储跨域图片（img-src 按真实 driver URL 动态白名单）
- 设置页操作后跳回错误分组（redirect 缺 ?tab=）
- logo_url 相对路径校验误判
- 备份目录 backups/ 与 .zip 后缀未受 nginx/.htaccess 防护（安全）

### 🧹 其他

- 设置项文案与实际行为对齐（备份周期/邮件加密/保留天数 0 语义）
- 移除死配置 cdn_url（CDN 统一走存储管理）
- 输入框宽度收敛 / 保存栏 sticky / 设置页排版优化

## [1.2.1-beta.1] - 2026-08-20

### 🔒 安全增强

- 会话加固（Cookie 属性/use_strict_mode/防会话固定）
- 限流键可信化（不信任 X-Forwarded-For）
- 签名密钥独立化（文件存储，与 DB 完全隔离）
- 限流 fail-closed / 全站安全响应头 / 信息泄露收敛 / security.txt

### ✨ 新功能

- 移动端汉堡导航（details 折叠）
- HEAD 路由支持 / doctor 签名密钥自检

### 🐛 Bug 修复

- 本地图片加载失败（signing_key 生成回归）
- 移动端 hero 布局 / banner 宽高比

### 🧹 工程整理

- tools/ 归档 / 根目录 assets/ 归位

## [1.2.0] - 2026-08-13

### 🔒 安全增强

- 图片链接全面改为短时临时签名（可配置有效期）：COS/OSS/AWS S3/OBS 云商原生预签名 + 本地 /files 签名端点
- 存储多实例配置（storage_profiles），运行时自动迁移

### ✨ 新功能

- 流量统计（API 调用量 + 网站访问量，按日自动埋点）
- 仪表盘重做：最近上传/分类分布/存储用量/7 天趋势/最近操作/系统状态
- 品牌视觉体系全站落地

### ⬆️ 功能增强

- 分页每页数量选择 / 跨页全选 / Dashboard → 仪表盘语言统一

### 🐛 Bug 修复

- 批量超限 419 → 413 / 全选接口 500 / 进度条文字 / 仪表盘 shell_exec 500

### 🚀 性能

- WebP + LCP 预加载 + CLS 消除 + 对比度 AA + 可访问性修复

## [1.2.0-beta.4] - 2026-08-13

### ✨ 新功能

- 品牌视觉体系：像素猫耳 Logo 全站落地（导航/侧边栏/登录页/安装向导/Favicon/Hero 横幅/OG 分享图），透明背景多尺寸整数倍缩放
- 后台仪表盘重做：改名「仪表盘」——最近上传、分类分布、存储用量、7 天上传趋势、最近操作日志
- 流量统计：API 调用量 + 网站访问量（api_stats/visit_stats 按日计数表，自动埋点），今日/近 7 天/累计 + 7 天双色趋势图
- 系统状态：CPU 负载、PHP 进程内存、磁盘占用实时指标（纯 PHP 原生，无 shell 依赖）

### 🚀 性能优化

- 品牌资源 WebP 化（banner 145KB → 24KB）+ LCP 预加载 + fetchpriority
- 图片显式 width/height 消除 CLS；深色主题对比度提升（WCAG AA）

### 🐛 Bug 修复

- 仪表盘 500：宝塔 disable_functions 禁用 shell_exec → 改 /proc/cpuinfo + memory_get_usage
- 「全选」接口 500（Model 数组访问）
- 上传进度条文字裁剪/遮挡（文字行 + 胶囊渐变轨道）
- 批量上传超限误报 419 → 413
- 仪表盘视图防御兜底（混搭部署不 500）

### 🎨 可访问性

- `<main>` / meta description / sr-only 标题 / select 关联 label / 标题层级修正
- 后台「Dashboard」→「仪表盘」语言统一

## [1.2.0-beta.3] - 2026-08-12

### 🔒 安全增强

- 图片链接改为短时临时签名链接（可配置有效期，默认 300 秒）：对象存储云商原生预签名（COS/OSS/AWS/OBS/又拍云/七牛，不经服务器代理）；本地存储新增签名下载端点 `/files`，永久静态 URL 改为短时签名链接

### ⬆️ 功能增强

- 图片管理分页：每页数量选择（10/20/50/100），保留筛选条件
- 图片管理跨页全选：「全选」按筛选选全部（跨分页），选中集合跨页保留
- 存储管理：本地存储可配置签名链接有效期

### 🐛 Bug 修复

- 图片上传：进度条重做（文字独立行 + 胶囊渐变轨道，不裁剪不遮挡）
- 图片上传：批量超限误报 419 → 413 + 前端预检
- 图片管理：全选接口 500（Model 数组访问）→ 轻量 SELECT id

## [1.1.1] - 2026-08-11

### ⬆️ 功能增强

- 全站排版层级统一、按钮按压态与焦点环、表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化；后台侧边栏 active 指示条
- 分类管理「每个顶级分类一张卡片」树形分组，子分类连接线 + 层级缩进
- Modal ESC 关闭 + 背景滚动锁定；上传进度实时百分比；代码块限高滚动、复制按钮悬浮
- doctor.php 自动探测 config 目录防护

### 🐛 Bug 修复

- 分类管理：删除按钮失效（容器 id 变更致事件委托未挂载）→ document 级委托
- doctor.php 新增自动探测 config 目录防护（HTTP 状态码探测）

> 注：v1.1.1 正式版在 v1.2.0-beta.1 上线后补发收口（基于 v1.1.1-beta.5 代码状态）。

## [1.2.0-beta.2] - 2026-08-11

### ⬆️ 功能增强

- 存储管理表单改为服务商驱动：同一表单，选择服务商后字段/标签/占位/必填自动适配（又拍云：服务名/操作员名/操作员密码；OBS 显示 endpoint 等）

### 🐛 Bug 修复

- 存储管理：又拍云 USS 实例被统一校验（强制 Region）拦截 → 提交校验按服务商必填字段
- 图片管理：hover 时放大查看/复制链接按钮重叠 → `.copy-btn` 在图片卡内重置为静态 flex 项
- 图片上传：进度条百分比不显示（flex 布局修复）；上传完成后保存期显示「正在保存…」脉冲反馈

## [1.2.0-beta.1] - 2026-08-11

### 🚀 新功能

- 对象存储接入第 5、6 家：又拍云 USS + 七牛云 Kodo（全部官方 SDK）
  - sdk/upyun/：upyun/sdk 官方源码，psr7 v1→v2 适配后复用 COS vendor 的 Guzzle/PSR-7
  - sdk/qiniu/：qiniu/php-sdk 官方源码（自实现 curl 无 Guzzle），捆绑最小 MyCLabs Enum
  - 新增 UpyunSdkDriver（service + operator + password，无 region）与 QiniuSdkDriver（AK/SK + bucket + region z0-z3/as0/na0）
  - 存储管理页服务商下拉新增两家选项，doctor 新增对应 SDK 检查项

### 🐛 Bug 修复

- doctor.php：「Config dir not web-exposed」在宝塔/open_basedir 防护下的误报修复——HTTP 200 改为响应体关键词识别（宝塔拦截页 → OK；PHP 凭据特征 → FAIL；未知 → WARN 人工确认）

## [1.1.1-beta.3] - 2026-08-10

### ⬆️ 功能增强

- 后台分类管理改为「每个顶级分类一张卡片」的树形分组展示，子分类带连接线与层级缩进，二级以上子类用虚线边框区分
- 分类卡片头部显示顶级标识、子类数量与排序；子项操作按钮 hover 浮现，移动端始终可见

## [1.1.1-beta.2] - 2026-08-10

### ⬆️ 功能增强

- Modal 弹窗支持 ESC 关闭，打开时锁定背景滚动（body.modal-open）
- 图片上传进度条实时显示百分比
- 前台文档代码块限高滚动，复制按钮固定悬浮右上角

## [1.1.1-beta.1] - 2026-08-10

### ⬆️ 功能增强

- 全站排版层级统一（h1-h4 字号/行高/字距），按钮按压态与焦点环
- 前台导航小屏换行、Hero 统计卡微动效、在线测试面板容器化
- 后台侧边栏 active 菜单左侧指示条
- 表格/表单/模态/滚动条打磨，prefers-reduced-motion 无障碍降级，响应式断点补全

## [1.1.0] - 2026-08-10

### 🚀 新功能

- RESTful API：随机图片 / 图片列表 / 多级分类 / 服务统计
- 双模式返回：JSON 结构化数据 或 302 重定向直接输出图片
- API Key 鉴权 + 速率限制（DB token bucket）
- 对象存储：腾讯云 COS / 阿里云 OSS / AWS S3 / 华为云 OBS（官方 SDK）
- 管理后台：图片 / 分类 / 用户 / API Key / 存储实例（多实例配置）
- 系统设置 v2：站点信息 / 安全与访问 / 性能与缓存 / 邮件与通知 / 备份与恢复
- 登录验证码 + 失败锁定、SMTP 邮件通知、自动备份、操作审计日志
- 响应式前台：顶部导航、随机图展示、API 在线测试、关于页

### 📚 文档与依赖

- 新增 README（项目简介 / 安装部署 / API 用法）与 MIT LICENSE
- 对象存储 SDK 全部采用官方 SDK（qcloud/cos-sdk-v5、alibabacloud/oss-v2、aws/aws-sdk-php、esdk-obs-php）

---

完整变更日志请查看 [GitHub Releases](https://github.com/Grabrun/MoeRNG/releases)。
