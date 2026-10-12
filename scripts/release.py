#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MoeRNG 一键发版脚本（VCS 内版本，MR-07 起以此为准）：
bump 版本 → 调用 scripts/_package.py 打包 zip → 计算 sha256 → git commit/push/tag
→ GitHub Release（正式版和测试版都会创建，附 zip + .sha256 资产）。

用法（先在环境变量注入 token，再执行）：
  set MOERNG_TOKEN=ghp_xxx            (Windows CMD)
  $env:MOERNG_TOKEN="ghp_xxx"         (PowerShell)
  export MOERNG_TOKEN=ghp_xxx         (bash)
  python scripts/release.py 1.1.1-beta.3                  # 测试版（默认）
  python scripts/release.py 1.1.1-beta.3 --no-push       # 只 bump + 打包 + commit
  python scripts/release.py 1.1.1 --stable                # 正式版（必须显式 --stable）
  python scripts/release.py 1.1.1-beta.3 --note "修复 XX" # 自定义 Release notes

与历史 tools/release.py 的差异（MR-07 修复要点）：
1. 凭据只从环境变量读取（MOERNG_TOKEN，GITHUB_TOKEN 作为同档别名）。
   绝不读取任何 token 文件；未设置时 push/release 直接报错退出，不做静默回退。
2. 路径全部由 __file__ 相对推导，无任何硬编码绝对路径。
3. zip 选取不再「按 mtime 猜最新」：_package.py 会打印 `PACKAGED: <绝对路径>`，
   本脚本直接解析该精确路径，并校验文件名前缀恰为 MoeRNG-v{version}-。
4. 新增 sha256 产出：对刚打出的 zip 计算 sha256，写出
   releases/<zip名>.sha256（内容为「<hash>  <文件名>」单行，GNU sha256sum 格式），
   并作为第二资产一并上传到 Release。

⚠️ 发布门禁（用户规则 2026-08-10）：
- 只有用户明确表示「发正式版」才能发布正式版（去 beta 后缀）。
- 其余一律测试版（X.Y.Z-beta.N）。
- 正式版格式（无 -beta 后缀）必须显式传 --stable，否则脚本拒绝执行；
  agent 也只在用户明确要求时才会传该标志。不确定时先问用户。

📦 Release 规范（用户规则 2026-08-10）：
- 测试版和正式版都要发布 GitHub Release（tag + release 页面 + zip 资产）。
- 测试版 Release 标记 prerelease: true；正式版 prerelease: false。
- Release notes 缺省为通用模板，可用 --note / --note-file 覆盖。

说明：
- bump 单一来源：只同步 src/bootstrap.php 的 APP_VERSION 常量。视图（home 等）
  一律读取该常量（无硬编码兜底字面量），_package.py 自动读取，故 zip 前缀、
  资源 ?v= 缓存戳、页面展示的版本号全部跟随 bootstrap.php。
- 分工：本脚本只做编排（bump / git / GitHub API / sha256）；
  实际打 zip 的逻辑在 scripts/_package.py（AWS SDK 白名单裁剪等），二者同目录。
- 发版后必须推送——项目规范：releases/ zip 与 GitHub 代码同步。

SemVer 约定（用户规则）：
- 测试期修 bug → X.Y.Z-beta.N+1；新增兼容功能 → MINOR+1 + beta.1；不兼容 → MAJOR+1 + beta.1
"""
import hashlib
import json
import os
import re
import subprocess
import sys
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = 'Grabrun/MoeRNG'
API = 'https://api.github.com'
UPLOADS = 'https://uploads.github.com'
FILES = [
    # 唯一版本定义源 = src/bootstrap.php 的 APP_VERSION 常量。
    # home.php / _package.py 等全部自动读取该常量（home.php 已去掉硬编码兜底
    # 字面量，_package.py 用 _read_app_version()），所以 bump 只需改这一处。
    ('src/bootstrap.php', re.compile(r"(define\('APP_VERSION', ')[^']+('\))"), lambda v: r"\g<1>" + v + r"\g<2>"),
]


def bump(version: str) -> None:
    for rel, pattern, repl in FILES:
        path = os.path.join(ROOT, rel)
        s = open(path, encoding='utf-8').read()
        s2, n = pattern.subn(repl(version), s)
        if n == 0:
            print(f'[FAIL] pattern not found in {rel}')
            sys.exit(1)
        open(path, 'w', encoding='utf-8').write(s2)
        print(f'[OK  ] {rel} -> {version}')


def package(version: str) -> str:
    """调用 scripts/_package.py 打包，并按「PACKAGED: <path>」精确取回产物路径。

    确定性规则（替代历史 mtime 猜测）：
      1. _package.py 打包完成时 stdout 首条信息行固定为 `PACKAGED: <zip绝对路径>`；
      2. 本行直接取该路径；
      3. 校验文件名前缀恰为 `MoeRNG-v{version}-`（防止打到旧版本残留 zip）；
      4. 若未解析到或校验不过，立即报错退出——绝不按 mtime/glob 猜测。
    """
    packager = os.path.join(ROOT, 'scripts', '_package.py')
    r = subprocess.run([sys.executable, packager], cwd=ROOT, capture_output=True, text=True)
    print(r.stdout.strip())
    if r.returncode != 0:
        print('[FAIL] package:', r.stderr[-500:])
        sys.exit(1)
    zip_path = None
    for line in r.stdout.splitlines():
        if line.startswith('PACKAGED:'):
            zip_path = line.split('PACKAGED:', 1)[1].strip()
            break
    if not zip_path or not os.path.isfile(zip_path):
        print('[FAIL] 未从 _package.py 输出中解析到 PACKAGED 路径:', r.stdout[-300:])
        sys.exit(1)
    expected_prefix = f'MoeRNG-v{version}-'
    if not os.path.basename(zip_path).startswith(expected_prefix):
        print(f'[FAIL] zip 文件名前缀不符：{os.path.basename(zip_path)} != {expected_prefix}*')
        sys.exit(1)
    return zip_path


def write_sha256(zip_path: str) -> str:
    """对 zip 计算 sha256，写出 releases/<zip名>.sha256。

    文件内容：单行 `<hex>  <basename>`（两个空格，GNU sha256sum -c 可直接校验）。
    返回 .sha256 文件路径。
    """
    h = hashlib.sha256()
    with open(zip_path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    digest = h.hexdigest()
    checksum_path = zip_path + '.sha256'
    with open(checksum_path, 'w', encoding='ascii', newline='\n') as f:
        f.write(f'{digest}  {os.path.basename(zip_path)}\n')
    print(f'[OK  ] sha256: {digest}  -> {os.path.basename(checksum_path)}')
    return checksum_path


def get_token() -> str:
    """凭据只从环境变量读取：MOERNG_TOKEN 优先，GITHUB_TOKEN 为同档别名。
    绝不读取任何 token 文件（历史 .dsh/moerng.token 方案已废弃）。"""
    return os.environ.get('MOERNG_TOKEN', '').strip() or os.environ.get('GITHUB_TOKEN', '').strip()


def require_token() -> str:
    t = get_token()
    if not t:
        print('[FAIL] 未设置环境变量 MOERNG_TOKEN（或 GITHUB_TOKEN）。')
        print('       请先注入：PowerShell  ->  $env:MOERNG_TOKEN="ghp_xxx"')
        print('       无 token 时不进行任何文件级兜底读取，直接退出。')
        sys.exit(1)
    return t


def git(version: str, push: bool) -> None:
    subprocess.run(['git', 'add', '-A'], cwd=ROOT, check=True)
    r = subprocess.run(
        ['git', 'commit', '-m', f'release v{version}'],
        cwd=ROOT, capture_output=True, text=True,
    )
    print(r.stdout.strip() or r.stderr.strip())
    if not push:
        print('[SKIP] push (--no-push)')
        return
    token = require_token()
    url = f'https://Grabrun:{token}@github.com/{REPO}.git'
    r = subprocess.run(['git', 'push', url, 'main'], cwd=ROOT, capture_output=True, text=True)
    tag = f'v{version}'
    r2 = subprocess.run(['git', 'tag', tag], cwd=ROOT, capture_output=True, text=True)
    if r2.returncode != 0:
        print(f'[WARN] tag {tag} may already exist: {r2.stderr.strip()}')
    r3 = subprocess.run(['git', 'push', url, tag], cwd=ROOT, capture_output=True, text=True)
    print(r3.stdout.strip() or r3.stderr.strip())
    print(r.stdout.strip() or r.stderr.strip())
    if r.returncode != 0:
        print('[FAIL] push — check MOERNG_TOKEN or local git credentials')
        sys.exit(1)


def _api(method: str, url: str, token: str, payload=None, raw: bytes | None = None, content_type: str | None = None) -> dict:
    data = None
    headers = {
        'Authorization': f'Bearer {token}',
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
    }
    if raw is not None:
        data = raw
        headers['Content-Type'] = content_type or 'application/octet-stream'
    elif payload is not None:
        data = json.dumps(payload).encode('utf-8')
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            body = resp.read().decode('utf-8')
            return json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        msg = e.read().decode('utf-8', errors='replace')[:300]
        print(f'[FAIL] GitHub API {method} {url}: HTTP {e.code} {msg}')
        sys.exit(1)


def _upload_asset(rid: int, token: str, asset_path: str) -> dict:
    fname = os.path.basename(asset_path)
    url = f'{UPLOADS}/repos/{REPO}/releases/{rid}/assets?name={urllib.parse.quote(fname)}'
    with open(asset_path, 'rb') as f:
        asset = _api('POST', url, token, raw=f.read(),
                     content_type='application/octet-stream')
    print(f'[OK  ] Asset uploaded: {asset.get("browser_download_url", fname)}')
    return asset


def github_release(version: str, zip_path: str, sha_path: str, note: str = '') -> None:
    token = require_token()
    tag = f'v{version}'
    prerelease = 'beta' in version
    if note:
        body = note
    else:
        # Release Notes 模板（用户规则 2026-08-10）：
        # - 只输出有内容的章节；无内容的章节整章删除
        # - 必有：版本概述 + 升级指南 + 完整变更日志链接（CHANGELOG.md）
        cl_url = 'https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md'
        kind = '测试版' if prerelease else '正式版'
        body = (
            f'## v{version}\n\n'
            f'**版本概述**：{kind}版本，用于验证新功能与修复。\n\n'
            '### 升级指南（Upgrade Guide）\n'
            '1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。\n'
            '2. 重启 PHP-FPM 触发数据库自动迁移。\n'
            '3. 运行 doctor.php 验证部署健康后删除。\n\n'
            f'完整变更日志请查看 [CHANGELOG.md]({cl_url})。\n\n'
            f'> 校验：下载 zip 后请同目录保留同名 `.sha256` 资产，'
            f'执行 `sha256sum -c {os.path.basename(sha_path)}` 校验完整性。'
        )

    # Create the release (fail fast if tag exists without release).
    release = _api('POST', f'{API}/repos/{REPO}/releases', token, {
        'tag_name': tag,
        'name': f'MoeRNG v{version}' + ('（测试版）' if prerelease else ''),
        'body': body,
        'draft': False,
        'prerelease': prerelease,
    })
    rid = release['id']
    print(f'[OK  ] GitHub Release created: {release["html_url"]} (prerelease={prerelease})')

    # 上传资产：zip + 同名 .sha256
    _upload_asset(rid, token, zip_path)
    _upload_asset(rid, token, sha_path)


def main() -> None:
    if len(sys.argv) < 2:
        print('usage: python scripts/release.py <version> [--no-push] [--stable] [--note "..."] [--note-file path]')
        sys.exit(1)
    version = sys.argv[1]
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?', version):
        print(f'[FAIL] invalid semver: {version}')
        sys.exit(1)
    push = '--no-push' not in sys.argv
    stable = '--stable' in sys.argv
    note = ''
    if '--note' in sys.argv:
        i = sys.argv.index('--note')
        if i + 1 < len(sys.argv):
            note = sys.argv[i + 1]
    if '--note-file' in sys.argv:
        i = sys.argv.index('--note-file')
        if i + 1 < len(sys.argv):
            path = sys.argv[i + 1]
            if os.path.isfile(path):
                with open(path, encoding='utf-8') as f:
                    note = f.read().strip()
                # note 草稿读入内存后立即删除，避免 `git add -A` 把草稿一起提交。
                try:
                    rel = os.path.relpath(os.path.abspath(path), ROOT)
                    inside_tree = not rel.startswith('..')
                    if inside_tree and not rel.replace('\\', '/').startswith('releases'):
                        os.remove(path)
                        print(f'[OK  ] temp note removed: {path}')
                except (OSError, ValueError):
                    pass
            else:
                print(f'[FAIL] --note-file 不存在: {path}')
                sys.exit(1)

    # Release gate: a stable version (no -beta suffix) requires explicit --stable.
    is_stable_format = 'beta' not in version
    if is_stable_format and not stable:
        print('[FAIL] 正式版（无 -beta 后缀）必须显式 --stable；仅用户明确要求发正式版时使用。')
        print('       测试版请使用 X.Y.Z-beta.N 格式。')
        sys.exit(2)
    if not is_stable_format and stable:
        print('[WARN] 测试版版本号带 --stable 冗余（忽略）。')

    print(f'=== MoeRNG release v{version} ({("STABLE" if is_stable_format else "beta")}) ===')
    bump(version)
    z = package(version)
    print(f'[OK  ] zip: {os.path.basename(z)}')
    sha = write_sha256(z)
    git(version, push)
    if push:
        github_release(version, z, sha, note)
    print(f'=== released v{version} ({"pushed + release" if push else "commit-only"}) ===')


if __name__ == '__main__':
    main()
