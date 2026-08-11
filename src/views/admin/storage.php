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
</div>

<script>
(function() {
    const table = document.getElementById('profile-table');
    const form = document.getElementById('profile-form');
    const modal = document.getElementById('profile-modal');
    const submitBtn = document.getElementById('profile-submit');
    const title = document.getElementById('profile-modal-title');

    // v1.2.0 迭代: per-provider field defs — the S3 form is a single shared
    // form; fields, labels, placeholders and required marks adapt to the
    // selected provider. null = field hidden for that provider.
    const PV_FIELDS = {
        cos:   { key:{l:'SecretId', p:'AKID 开头', r:true}, secret:{l:'SecretKey', p:'', r:true},
                 region:{l:'Region', p:'ap-guangzhou / ap-shanghai…', r:true},
                 bucket:{l:'Bucket', p:'mybucket-1250000000（需带 APPID）', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false} },
        oss:   { key:{l:'AccessKeyId', p:'LTAI 开头', r:true}, secret:{l:'AccessKeySecret', p:'', r:true},
                 region:{l:'Region', p:'cn-hangzhou / oss-cn-…', r:true},
                 bucket:{l:'Bucket', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false} },
        aws:   { key:{l:'Access Key ID', p:'AKIA 开头', r:true}, secret:{l:'Secret Access Key', p:'', r:true},
                 region:{l:'Region', p:'us-east-1 / ap-southeast-1…', r:true},
                 bucket:{l:'Bucket', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false} },
        obs:   { key:{l:'Access Key', p:'', r:true}, secret:{l:'Secret Key', p:'', r:true}, region:null,
                 bucket:{l:'Bucket', p:'mybucket', r:true},
                 endpoint:{l:'Endpoint', p:'https://obs.cn-north-4.myhuaweicloud.com', r:true},
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false} },
        upyun: { bucket:{l:'服务名（Service = Bucket）', p:'mybucket', r:true},
                 key:{l:'操作员名（Operator）', p:'', r:true}, secret:{l:'操作员密码（Password）', p:'', r:true},
                 region:null, endpoint:null,
                 cdn:{l:'CDN 加速域名（可选）', p:'https://cdn.example.com', r:false} },
        qiniu: { key:{l:'Access Key', p:'', r:true}, secret:{l:'Secret Key', p:'', r:true},
                 region:{l:'区域（Region）', p:'z0(华东) / z1(华北) / z2(华南) / z3(华东2) / as0(新加坡) / na0(北美)', r:true},
                 bucket:{l:'空间名（Bucket）', p:'mybucket', r:true}, endpoint:null,
                 cdn:{l:'CDN 下载域名（可选）', p:'https://cdn.example.com', r:false} },
    };

    function applyProvider(pv) {
        const def = PV_FIELDS[pv] || PV_FIELDS.cos;
        document.querySelectorAll('.pv-field').forEach(function(el) {
            const f = el.dataset.field;
            const d = def[f];
            if (!d) { el.classList.add('hidden'); return; }
            el.classList.remove('hidden');
            const label = el.querySelector('.pv-label');
            const req = el.querySelector('.pv-req');
            const input = el.querySelector('input');
            if (label) label.textContent = d.l;
            if (req) req.style.display = d.r ? 'inline' : 'none';
            if (input && !input.value) input.placeholder = d.p || '';
        });
    }
    document.getElementById('profile-provider').addEventListener('change', function() {
        applyProvider(this.value);
    });

    function toggleFields() {
        const isS3 = document.getElementById('profile-driver').value === 's3';
        document.getElementById('profile-local-fields').classList.toggle('hidden', isS3);
        document.getElementById('profile-s3-fields').classList.toggle('hidden', !isS3);
        document.getElementById('profile-provider-group').classList.toggle('hidden', !isS3);
        if (isS3) applyProvider(document.getElementById('profile-provider').value);
    }
    document.getElementById('profile-driver').addEventListener('change', toggleFields);

    function resetForm(mode, row) {
        form.reset();
        document.getElementById('profile-id').value = row ? row.dataset.id : '';
        document.getElementById('profile-name').value = row ? row.dataset.name : '';
        document.getElementById('profile-driver').value = row ? (row.dataset.driver || 'local') : 'local';
        document.getElementById('profile-provider').value = row ? (row.dataset.provider || 'cos') : 'cos';

        let cfg = {};
        try { cfg = row ? JSON.parse(row.dataset.config || '{}') : {}; } catch (e) { cfg = {}; }
        document.getElementById('profile-cfg-path').value = cfg.path || '';
        document.getElementById('profile-cfg-key').value = cfg.key || '';
        document.getElementById('profile-cfg-secret').value = cfg.secret || '';
        document.getElementById('profile-cfg-region').value = cfg.region || '';
        document.getElementById('profile-cfg-bucket').value = cfg.bucket || '';
        document.getElementById('profile-cfg-endpoint').value = cfg.endpoint || '';
        document.getElementById('profile-cfg-cdn').value = cfg.cdn || '';
        document.getElementById('profile-is-default').checked = row ? row.dataset.default === '1' : false;

        toggleFields();
        title.textContent = mode === 'update' ? '编辑存储实例' : '新增存储实例';
        submitBtn.textContent = mode === 'update' ? '保存修改' : '创建实例';
    }

    document.getElementById('profile-new').addEventListener('click', function() {
        resetForm('create', null);
        modal.classList.add('active');
    });

    submitBtn.addEventListener('click', async function() {
        const id = document.getElementById('profile-id').value;
        const mode = id ? 'update' : 'create';
        const payload = {
            id: id,
            name: document.getElementById('profile-name').value.trim(),
            driver: document.getElementById('profile-driver').value,
            provider: document.getElementById('profile-provider').value,
            cfg_key: document.getElementById('profile-cfg-key').value.trim(),
            cfg_secret: document.getElementById('profile-cfg-secret').value,
            cfg_region: document.getElementById('profile-cfg-region').value.trim(),
            cfg_bucket: document.getElementById('profile-cfg-bucket').value.trim(),
            cfg_endpoint: document.getElementById('profile-cfg-endpoint').value.trim(),
            cfg_cdn: document.getElementById('profile-cfg-cdn').value.trim(),
            cfg_path: document.getElementById('profile-cfg-path').value.trim(),
            is_default: document.getElementById('profile-is-default').checked ? '1' : '0'
        };
        if (!payload.name) { showToast('请填写实例名称', 'error'); return; }
        if (payload.driver === 's3' && !(payload.cfg_key && payload.cfg_secret && payload.cfg_region && payload.cfg_bucket)) {
            showToast('对象存储实例需填写完整的 AccessKey / SecretKey / Region / Bucket', 'error'); return;
        }

        submitBtn.disabled = true;
        try {
            const result = await adminPost('/admin/storage/profiles/' + mode, payload);
            modal.classList.remove('active');
            showToast(result.message, 'success');
            // Full reload keeps the table + current-effective card in sync.
            setTimeout(() => window.location.reload(), 500);
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            submitBtn.disabled = false;
        }
    });

    table.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-profile-action]');
        if (!btn) return;
        e.preventDefault();
        const row = btn.closest('tr');
        const id = row?.dataset.id;
        const action = btn.dataset.profileAction;

        if (action === 'edit') { resetForm('update', row); modal.classList.add('active'); return; }

        if (action === 'delete') {
            if (!confirm('确定删除存储实例「' + (row?.dataset.name || '') + '」？此操作不可撤销。')) return;
        }

        btn.disabled = true;
        try {
            const url = '/admin/storage/profiles/' + action;
            const result = await adminPost(url, { id: id });
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 400);
        } catch (err) {
            showToast(err.message, 'error');
            btn.disabled = false;
        }
    });
})();
</script>

<?php admin_footer(); ?>
