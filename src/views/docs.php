<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="docs" class="section reveal">
            <h2 class="section-title">API 文档</h2>

            <div class="docs-layout">
            <aside class="docs-sidebar" aria-label="文档目录">
                <h4>API 文档</h4>
                <nav>
                    <a href="#endpoint-random" data-doc-target="#endpoint-random" class="active">随机图片</a>
                    <a href="#endpoint-images" data-doc-target="#endpoint-images">图片列表</a>
                    <a href="#endpoint-categories" data-doc-target="#endpoint-categories">分类列表</a>
                    <a href="#endpoint-stats" data-doc-target="#endpoint-stats">服务统计</a>
                </nav>
            </aside>
            <div class="docs-content">
            <div class="doc-endpoint doc-pane active" id="endpoint-random">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/random</span>
                    <span class="text-muted doc-endpoint-label">获取随机图片</span>
                </div>
                <div class="body">
                    <p class="mb-2">返回一张随机图片。可通过参数指定分类和返回格式。</p>
                    <h3 class="mb-1">请求参数</h3>
                    <table class="param-table">
                        <thead><tr><th>参数</th><th>类型</th><th>必需</th><th>默认值</th><th>说明</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><code>category</code></td>
                                <td data-label="类型">string</td>
                                <td data-label="必需">否</td>
                                <td data-label="默认值">-</td>
                                <td data-label="说明">分类 slug（含全部子分类）</td>
                            </tr>
                            <tr>
                                <td><code>type</code></td>
                                <td data-label="类型">string</td>
                                <td data-label="必需">否</td>
                                <td data-label="默认值"><code>json</code></td>
                                <td data-label="说明"><code>json</code> 结构化 / <code>redirect</code> 302 跳转</td>
                            </tr>
                            <tr>
                                <td><code>size</code></td>
                                <td data-label="类型">string</td>
                                <td data-label="必需">否</td>
                                <td data-label="默认值"><code>original</code>（原图）</td>
                                <td data-label="说明"><code>sm</code> 320 / <code>md</code> 640 / <code>lg</code> 1280 / <code>original</code> 原图。
                                    取值非法时返回 <code>400</code>；该尺寸未生成时按
                                    <code>请求尺寸 → md → 原图</code> 回退，<strong>绝不返回 404</strong>。
                                    <code>size</code> 字段如实回报实际生效的档位，<code>url</code> 与宽高、字节数描述同一张图。</td>
                            </tr>
                            <tr>
                                <td><code>device</code></td>
                                <td data-label="类型">string</td>
                                <td data-label="必需">否</td>
                                <td data-label="默认值">-</td>
                                <td data-label="说明"><code>pc</code> 横图（含方图）/ <code>pe</code> 竖图 / <code>any</code> 不限构图。
                                    不传按 UA 判断，无 UA 的请求不过滤；该方向没有图时回退全部。</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="text-muted text-small">
                        图库或所选分类为空时返回 <code>404</code>：<code>error</code> 是英文机器码，
                        <code>message</code> 给中文引导。
                    </p>

                    <h3 class="mb-1">请求示例</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?category=landscape&type=json"'>复制</button>
                        <pre><code>curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?category=landscape&type=json"</code></pre>
                    </div>

                    <h3 class="mb-1">JSON 响应示例</h3>
                                        <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def/original.png",
    "size": "original",
    "width": 1920,
    "height": 1080,
    "mime_type": "image/png",
    "file_size": 2048576,
    "category": "landscape"
  }
}'>复制</button>
                        <pre><code>{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def/original.png",
    "size": "original",
    "width": 1920,
    "height": 1080,
    "mime_type": "image/png",
    "file_size": 2048576,
    "category": "landscape"
  }
}</code></pre>
                    </div>

                    <p class="text-muted text-small">
                        传 <code>size=sm</code> 时整组字段一起变：
                    </p>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def/thumb-sm.webp",
    "size": "sm",
    "width": 320,
    "height": 180,
    "mime_type": "image/webp",
    "file_size": 18432,
    "category": "landscape"
  }
}'>复制</button>
                        <pre><code>{
  "success": true,
  "data": {
    "id": 42,
    "url": "https://cdn.example.com/2026/08/abc123def/thumb-sm.webp",
    "size": "sm",
    "width": 320,
    "height": 180,
    "mime_type": "image/webp",
    "file_size": 18432,
    "category": "landscape"
  }
}</code></pre>
                    </div>
                    <p class="text-muted text-small">
                        <code>file_size</code> 是生成时实测值；早期图片可能为 <code>null</code>（未知，不是 0），
                        后台「补全缩略图」可补齐。
                    </p>

                    <h3 class="mb-1">缩略图示例</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='# JSON，取 320px 缩略图
curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?size=sm"

# 302 直接跳到缩略图
curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect&size=sm"

# 批量取列表，比逐张调 random 省配额
curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/images?limit=20&size=sm"'>复制</button>
                        <pre><code># JSON，取 320px 缩略图
curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?size=sm"

# 302 直接跳到缩略图
curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect&size=sm"

# 批量取列表，比逐张调 random 省配额
curl -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/images?limit=20&size=sm"</code></pre>
                    </div>

                    <h3 class="mb-1">返回 URL 的时效性</h3>
                    <p class="text-muted text-small">
                        <code>url</code> 是<strong>带签名的直链</strong>，按存储实例的 <code>signed_ttl</code> 生效
                        （默认 300 秒；云端预签名，本地为 <code>/files</code> 短时签名）。
                        请勿长期存库或嵌到第三方页面，过期后重新请求即可；需要稳定直链请配 CDN 域名。
                    </p>
                    <p class="text-muted text-small">
                        <code>/random</code> 的响应带 <code>Cache-Control: no-store</code>：URL 固定但语义是「每次换一张」，
                        一旦被缓存，随机性会在缓存期内失效。
                    </p>

                    <h3 class="mb-1">重定向模式</h3>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect"
# HTTP 302 → 图片直接输出'>复制</button>
                        <pre><code>curl -L -H "X-API-Key: mr_your_api_key_here" "<?= h($baseUrl ?: 'https://your-domain.com') ?>/api/v1/random?type=redirect"
# HTTP 302 → 图片直接输出</code></pre>
                    </div>
                </div>
            </div>

            <div class="doc-endpoint doc-pane" id="endpoint-images">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/images</span>
                    <span class="text-muted doc-endpoint-label">图片列表（分页）</span>
                </div>
                <div class="body">
                    <h3 class="mb-1">请求参数</h3>
                    <table class="param-table">
                        <thead><tr><th>参数</th><th>类型</th><th>默认值</th><th>说明</th></tr></thead>
                        <tbody>
                            <tr><td><code>page</code></td><td data-label="类型">int</td><td data-label="默认值">1</td><td data-label="说明">页码</td></tr>
                            <tr><td><code>limit</code></td><td data-label="类型">int</td><td data-label="默认值">20</td><td data-label="说明">每页数量（最大100）</td></tr>
                            <tr><td><code>category</code></td><td data-label="类型">string</td><td data-label="默认值">-</td><td data-label="说明">分类过滤（含子分类）；slug 不存在时返回 404</td></tr>
                            <tr><td><code>size</code></td><td data-label="类型">string</td><td data-label="默认值">original</td><td data-label="说明">返回哪一档图片：<code>original</code> / <code>lg</code> / <code>md</code> / <code>sm</code>；非法值返回 400</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="doc-endpoint doc-pane" id="endpoint-categories">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/categories</span>
                    <span class="text-muted doc-endpoint-label">分类列表</span>
                </div>
                <div class="body">
                    <p>返回完整分类树结构（嵌套 JSON），包含所有分类及其子分类。</p>
                </div>
            </div>

            <div class="doc-endpoint doc-pane" id="endpoint-stats">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/stats</span>
                    <span class="text-muted doc-endpoint-label">服务统计</span>
                </div>
                <div class="body">
                    <p>返回图片总数与分类总数。</p>
                    <p class="text-muted">出于安全考虑，本接口不返回版本号与存储驱动等信息（v1.2.1 起移除）。</p>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15
  }
}'>复制</button>
                    <pre><code>{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15
  }
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </section>

        <section id="rate-limits" class="section reveal">
            <h2 class="section-title">速率限制</h2>
            <div class="card">
                <p class="mb-2">限流默认关闭。开启后匿名请求按 IP、携带 Key 的请求按 Key 分别计数，配额信息随响应头返回：</p>
                <table class="param-table mb-3">
                    <thead><tr><th>响应头</th><th>说明</th></tr></thead>
                    <tbody>
                        <tr><td><code>X-RateLimit-Limit</code></td><td>时间窗口内最大请求数</td></tr>
                        <tr><td><code>X-RateLimit-Remaining</code></td><td>当前窗口剩余请求数</td></tr>
                        <tr><td><code>X-RateLimit-Reset</code></td><td>窗口重置时间（Unix 时间戳）</td></tr>
                        <tr><td><code>Retry-After</code></td><td>限流后建议重试等待秒数（仅触发限流时返回）</td></tr>
                    </tbody>
                </table>
                <div class="rate-tier">
                    <div class="tier-card">
                        <div class="tier-name">匿名请求（按 IP）</div>
                        <div class="tier-limit">100 <small>/ 分钟（默认）</small></div>
                    </div>
                    <div class="tier-card">
                        <div class="tier-name">携带 API Key（按 Key）</div>
                        <div class="tier-limit">60 <small>/ 分钟（默认）</small></div>
                    </div>
                </div>
                <p class="text-muted mt-1"><small>数字为默认值，本站实际配额以后台配置为准；每个 Key 的限额可在「API Key」页单独调整。</small></p>
                <p class="text-muted"><small>超限返回 HTTP 429，响应体带 <code>retry_after</code> 提示等待秒数。</small></p>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
