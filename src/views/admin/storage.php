<?php include __DIR__ . '/helpers.php'; admin_header('存储管理'); ?>

<div class="page-header flex-between">
    <div>
        <h1>存储管理</h1>
        <p>管理多个存储实例：本地磁盘或对象存储（COS / OSS / AWS S3），上传时可动态选择。</p>
    </div>
    <button class="btn btn-primary btn-sm" id="profile-new"><?= icon('plus', 16) ?> 新增存储实例</button>
</div>

<?php
    $default = $defaultProfile ?? null;
    $defaultName = $default ? (string) $default->name : '—';
    $defaultType = $default ? $default->typeLabel() : '未设置';
    $defaultInstance = $default ? $default->instanceLabel() : '';
?>

<div class="card mb-3">
    <h3 class="mb-2">当前生效</h3>
    <p>
        默认上传方案：<strong><?= h($defaultName) ?></strong>
        <span class="text-muted">（<?= h($defaultType) ?><?= $defaultInstance !== '' ? ' · ' . h($defaultInstance) : '' ?>）</span>
    </p>
    <p style="color: var(--text-secondary); font-size: 0.85rem;">
        新上传的图片默认写入此方案；上传时可临时切换到其他已启用实例。已上传图片按各自记录的实例访问，切换默认方案不会让旧图片失效。
    </p>
</div>

<div class="card">
    <div class="table-wrap">
        <table id="profile-table">
            <thead>
                <tr>
                    <th>名称</th>
                    <th>类型</th>
                    <th>实例</th>
                    <th>状态</th>
                    <th>默认</th>
                    <th style="text-align:right">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($profiles as $p): ?>
                <tr data-id="<?= (int) $p->id ?>"
                    data-name="<?= h($p->name) ?>"
                    data-driver="<?= h($p->driver) ?>"
                    data-provider="<?= h($p->provider) ?>"
                    data-config='<?= h(json_encode($p->config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'
                    data-default="<?= $p->isDefault() ? '1' : '0' ?>"
                    data-enabled="<?= $p->isEnabled() ? '1' : '0' ?>"
                    data-usable="<?= $p->isUsable() ? '1' : '0' ?>">
                    <td><strong><?= h($p->name) ?></strong><?= $p->isUsable() ? '' : ' <span class="badge badge-warning">凭据不全</span>' ?></td>
                    <td><?= h($p->typeLabel()) ?></td>
                    <td class="text-muted" style="font-size:0.85rem"><?= h($p->instanceLabel()) ?></td>
                    <td>
                        <span class="badge <?= $p->isEnabled() ? 'badge-success' : 'badge-danger' ?>"><?= $p->isEnabled() ? '启用' : '停用' ?></span>
                    </td>
                    <td>
                        <?php if ($p->isDefault()): ?>
                        <span class="badge badge-primary">默认</span>
                        <?php else: ?>
                        <button type="button" class="btn btn-outline btn-sm" data-profile-action="default">设为默认</button>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <div class="flex gap-1" style="justify-content:flex-end">
                            <button type="button" class="btn btn-outline btn-sm" data-profile-action="edit"><?= icon('edit', 14) ?> 编辑</button>
                            <button type="button" class="btn btn-outline btn-sm" data-profile-action="toggle"><?= $p->isEnabled() ? '停用' : '启用' ?></button>
                            <button type="button" class="btn btn-danger btn-sm" data-profile-action="delete"><?= icon('trash', 14) ?></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="profile-empty" class="empty-state <?= empty($profiles) ? '' : 'hidden' ?>">
        <div class="empty-icon"><?= icon('cloud', 32) ?></div>
        <h3>还没有存储实例</h3>
        <p>点击「新增存储实例」创建第一个存储方案（本地磁盘或对象存储）</p>
    </div>
</div>

<!-- Create / Edit Modal -->
<div class="modal-overlay" id="profile-modal">
    <div class="modal" style="max-width:620px">
        <h2 id="profile-modal-title">新增存储实例</h2>
        <form id="profile-form">
            <input type="hidden" name="id" id="profile-id" value="">
            <div class="form-group">
                <label>实例名称 <small class="text-muted">（如「主 COS 桶」「备用 OSS」「测试本地」）</small></label>
                <input type="text" name="name" id="profile-name" class="form-control" required maxlength="100" placeholder="例：主 COS 桶">
            </div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label>存储类型</label>
                    <select name="driver" id="profile-driver" class="form-control">
                        <option value="local">本地存储</option>
                        <option value="s3">对象存储</option>
                    </select>
                </div>
                <div class="form-group" id="profile-provider-group">
                    <label>服务商</label>
                    <select name="provider" id="profile-provider" class="form-control">
                        <?php foreach ($providerList as $pid => $pname): ?>
                        <option value="<?= h($pid) ?>"><?= h($pname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Local fields -->
            <div id="profile-local-fields">
                <div class="form-group">
                    <label>本地存储路径 <small class="text-muted">（相对项目根目录，可空默认 public/uploads）</small></label>
                    <input type="text" name="cfg_path" id="profile-cfg-path" class="form-control" placeholder="public/uploads">
                </div>
                <!-- v1.2.1 迭代: local CDN override (LocalDriver already supports it) -->
                <div class="form-group">
                    <label>CDN 加速域名（可选） <small class="text-muted">（本地图片经 CDN 域名提供，留空用本站地址）</small></label>
                    <input type="text" name="cfg_cdn_local" id="profile-cfg-cdn-local" class="form-control" placeholder="https://cdn.example.com">
                </div>
                <div class="form-group">
                    <label>签名链接有效期（秒） <small class="text-muted">（本地文件经 /files 签名端点访问）</small></label>
                    <input type="text" name="cfg_signed_ttl_local" id="profile-cfg-signed-ttl-local" class="form-control" placeholder="300（默认 5 分钟）">
                </div>
            </div>

            <!-- Object storage fields -->
            <div id="profile-s3-fields" class="hidden">
                <div class="grid grid-2">
                    <div class="form-group pv-field" data-field="key">
                        <label class="pv-label">Access Key</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_key" id="profile-cfg-key" class="form-control" placeholder="" autocomplete="off">
                    </div>
                    <div class="form-group pv-field" data-field="secret">
                        <label class="pv-label">Secret Key</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="password" name="cfg_secret" id="profile-cfg-secret" class="form-control" placeholder="" autocomplete="new-password">
                    </div>
                    <div class="form-group pv-field" data-field="region">
                        <label class="pv-label">Region</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_region" id="profile-cfg-region" class="form-control" placeholder="">
                    </div>
                    <div class="form-group pv-field" data-field="bucket">
                        <label class="pv-label">Bucket</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_bucket" id="profile-cfg-bucket" class="form-control" placeholder="">
                    </div>
                    <div class="form-group pv-field" data-field="endpoint">
                        <label class="pv-label">Endpoint</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_endpoint" id="profile-cfg-endpoint" class="form-control" placeholder="">
                    </div>
                    <div class="form-group pv-field" data-field="cdn">
                        <label class="pv-label">CDN 加速域名（可选）</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_cdn" id="profile-cfg-cdn" class="form-control" placeholder="https://cdn.example.com">
                    </div>
                    <div class="form-group pv-field" data-field="source_domain">
                        <label class="pv-label">自定义源站域名（可选）</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_source_domain" id="profile-cfg-source-domain" class="form-control" placeholder="img.example.com">
                    </div>
                    <div class="form-group pv-field" data-field="signed_ttl">
                        <label class="pv-label">签名链接有效期（秒）</label><span class="pv-req" style="color:var(--danger)"> *</span>
                        <input type="text" name="cfg_signed_ttl" id="profile-cfg-signed-ttl" class="form-control" placeholder="300（默认 5 分钟）">
                    </div>
                </div>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_default" id="profile-is-default" value="1" style="width:16px;height:16px">
                <label for="profile-is-default" style="margin-bottom:0;cursor:pointer">设为默认上传方案</label>
            </div>
        </form>
        <div class="btn-group">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('profile-modal').classList.remove('active')">取消</button>
            <button type="button" class="btn btn-primary" id="profile-submit">保存</button>
        </div>
    </div>
</div><?php admin_footer(); ?>
