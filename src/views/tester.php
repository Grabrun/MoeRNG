<?php require_once __DIR__ . '/partials/icons.php'; ?>
<?php require __DIR__ . '/partials/front_header.php'; ?>

    <main id="main-content">
    <div class="container">
        <section id="tester" class="section reveal">
            <h2 class="section-title">在线测试</h2>
            <div class="api-tester" id="api-tester">
                <div class="flex gap-2 flex-wrap mb-3">
                    <div class="form-group tester-field">
                        <label for="test-category">分类</label>
                        <select class="form-control" id="test-category">
                            <option value="">全部（随机）</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat->getSlug()) ?>"><?= h($cat->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group tester-field">
                        <label for="test-type">返回格式</label>
                        <select class="form-control" id="test-type">
                            <option value="json">JSON</option>
                            <option value="redirect">重定向（直接输出图片）</option>
                        </select>
                    </div>
                    <!-- 首项 value="" 表示不传 size，保持缺省即原图的既有语义。 -->
                    <div class="form-group tester-field">
                        <label for="test-size">图片尺寸</label>
                        <select class="form-control" id="test-size">
                            <option value="">默认（不传 size）</option>
                            <option value="sm">sm · 320px</option>
                            <option value="md">md · 640px</option>
                            <option value="lg">lg · 1280px</option>
                            <option value="original">original · 原图</option>
                        </select>
                    </div>
                    <div class="tester-actions">
                        <button class="btn btn-primary" id="test-run">发送请求</button>
                    </div>
                </div>
                <p class="text-muted text-small">
                    传什么 <code>size</code> 就返回哪一张：<code>url</code> 与宽高、格式、字节数
                    <strong>全部描述</strong>同一张图。<strong>缺省是原图</strong>；所选尺寸尚未生成时按
                    「请求尺寸 → md → 原图」回退，响应里的 <code>size</code> 是实际生效的档位。
                </p>
                <p class="mb-2"><strong>请求 URL：</strong> <code id="test-url" class="wrap-all">-</code>
                    <button type="button" class="copy-btn copy-btn-inline" data-copy="test-url" aria-label="复制 URL" title="复制 URL"><?= icon('copy', 14) ?></button>
                </p>
                <p class="mb-2"><strong>cURL：</strong> <code id="test-curl" class="wrap-all">-</code>
                    <button type="button" class="copy-btn copy-btn-inline" data-copy="test-curl" aria-label="复制 cURL" title="复制 cURL"><?= icon('copy', 14) ?></button>
                </p>
                <div class="preview-box" id="test-result">
                    <span class="text-muted">点击「发送请求」查看结果</span>
                </div>
                <div class="test-meta hidden" id="test-meta">
                    <span id="test-status" class="badge"></span>
                    <span id="test-duration" class="test-duration"></span>
                </div>
            </div>
        </section>
    </main>

<?php require __DIR__ . '/partials/front_footer.php'; ?>
