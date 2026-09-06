<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <!-- API Documentation -->
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
                                <td>string</td>
                                <td>否</td>
                                <td>-</td>
                                <td>分类标识(slug)，指定后从该分类及子分类中随机返回</td>
                            </tr>
                            <tr>
                                <td><code>type</code></td>
                                <td>string</td>
                                <td>否</td>
                                <td><code>json</code></td>
                                <td>返回类型：<code>json</code> 返回结构化数据，<code>redirect</code> 302重定向至图片</td>
                            </tr>
                        </tbody>
                    </table>

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
    "url": "https://cdn.example.com/2026/08/abc123def.png",
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
    "url": "https://cdn.example.com/2026/08/abc123def.png",
    "width": 1920,
    "height": 1080,
    "mime_type": "image/png",
    "file_size": 2048576,
    "category": "landscape"
  }
}</code></pre>
                    </div>

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
                            <tr><td><code>page</code></td><td>int</td><td>1</td><td>页码</td></tr>
                            <tr><td><code>limit</code></td><td>int</td><td>20</td><td>每页数量（最大100）</td></tr>
                            <tr><td><code>category</code></td><td>string</td><td>-</td><td>分类过滤</td></tr>
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
                    <p>返回完整分类树结构（嵌套JSON），包含所有分类及其子分类。</p>
                </div>
            </div>

            <div class="doc-endpoint doc-pane" id="endpoint-stats">
                <div class="header">
                    <span class="method get">GET</span>
                    <span class="endpoint-path">/api/v1/stats</span>
                    <span class="text-muted doc-endpoint-label">服务统计</span>
                </div>
                <div class="body">
                    <p>返回图片总数、分类总数、版本号、当前存储驱动等信息。</p>
                    <div class="copy-wrap">
                        <button type="button" class="copy-btn" data-copy-text='{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15,
    "version": "<?= APP_VERSION ?>",
    "storage_driver": "local"
  }
}'>复制</button>
                    <pre><code>{
  "success": true,
  "data": {
    "total_images": 1234,
    "total_categories": 15,
    "version": "<?= APP_VERSION ?>",
    "storage_driver": "local"
  }
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </section>

        <!-- Rate Limits -->
        <section id="rate-limits" class="section reveal">
            <h2 class="section-title">速率限制</h2>
            <div class="card">
                <p class="mb-2">API 使用令牌桶算法实现速率限制，通过响应头返回实时配额信息：</p>
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
                        <div class="tier-name">匿名用户（IP 限流）</div>
                        <div class="tier-limit">60 <small>/ 分钟</small></div>
                    </div>
                    <div class="tier-card">
                        <div class="tier-name">API Key（默认配额）</div>
                        <div class="tier-limit">60 <small>/ 分钟</small></div>
                    </div>
                    <div class="tier-card">
                        <div class="tier-name">API Key（可自定义）</div>
                        <div class="tier-limit">∞ <small>可配置</small></div>
                    </div>
                </div>
                <p class="text-muted mt-1"><small>超限时返回 HTTP 429 状态码，响应体包含 <code>retry_after</code> 字段提示等待时间。</small></p>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
