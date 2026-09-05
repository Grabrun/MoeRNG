#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MoeRNG 一键发版脚本（v1.1.1-beta.3 起）：bump 版本 → 打包 zip → git commit →
push + tag → GitHub Release（正式版和测试版都会创建，附 zip 资产）。

用法：
  python tools/release.py 1.1.1-beta.3                  # 测试版（默认）
  python tools/release.py 1.1.1-beta.3 --no-push        # 只 bump + 打包 + commit
  python tools/release.py 1.1.1 --stable                # 正式版（必须显式 --stable）
  python tools/release.py 1.1.1-beta.3 --note "修复 XX" # 自定义 Release notes

⚠️ 发布门禁（用户规则 2026-08-10）：
- 只有用户明确表示「发正式版」才能发布正式版（去 beta 后缀）。
- 其余一律测试版（X.Y.Z-beta.N）。
- 正式版格式（无 -beta 后缀）必须显式传 --stable，否则脚本拒绝执行；
  agent 也只在用户明确要求时才会传该标志。不确定时先问用户。

📦 Release 规范（用户规则 2026-08-10）：
- **测试版和正式版都要发布 GitHub Release**（tag + release 页面 + zip 资产）。
- 测试版 Release 标记 prerelease: true；正式版 prerelease: false。
- Release notes 缺省为通用模板，可用 --note 覆盖。

说明：
- bump 单一来源：只同步 src/bootstrap.php 的 APP_VERSION 常量。视图（home 等）
  一律读取该常量（无硬编码兜底字面量），打包脚本 _package.py 用 _read_app_version()
  自动读取，故 zip 前缀、资源 ?v= 缓存戳、页面展示的版本号全部跟随 bootstrap.php。
- push/release 凭据：优先读取项目级 token 文件 .dsh/moerng.token（gitignore 隔离，
  仅本项目生效，不污染系统），环境变量 GITHUB_TOKEN 作为兜底。push 走内嵌 URL，
  GitHub Release 用 Bearer token。无 token 时 push 走 `git push origin` 且跳过 Release。
- 发版后必须推送——项目规范：releases/ zip 与 GitHub 代码同步。

SemVer 约定（用户规则）：
- 测试期修 bug → X.Y.Z-beta.N+1；新增兼容功能 → MINOR+1 + beta.1；不兼容 → MAJOR+1 + beta.1
"""
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


def package() -> str:
    r = subprocess.run([sys.executable, 'tools/_package.py'], cwd=ROOT, capture_output=True, text=True)
    print(r.stdout.strip())
    if r.returncode != 0:
        print('[FAIL] package:', r.stderr[-500:])
        sys.exit(1)
    import glob
    # Newest by mtime — filename sort breaks on beta.10 vs beta.9 ('1'<'9').
    zips = sorted(glob.glob(os.path.join(ROOT, 'releases', 'MoeRNG-v*.zip')), key=os.path.getmtime)
    return zips[-1]


def get_token() -> str:
    """优先读取项目级 token 文件（.dsh/moerng.token，已被 gitignore 隔离），
    环境变量 GITHUB_TOKEN 作为兜底。token 只对本项目生效，不污染系统环境。"""
    # 1) 项目级 token 文件（首选）
    token_file = os.path.join(ROOT, '.dsh', 'moerng.token')
    try:
        if os.path.isfile(token_file):
            t = open(token_file, encoding='utf-8').read().strip()
            if t:
                return t
    except OSError:
        pass
    # 2) 环境变量兜底
    return os.environ.get('GITHUB_TOKEN', '')


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
    token = get_token()
    if token:
        url = f'https://Grabrun:{token}@github.com/{REPO}.git'
        r = subprocess.run(['git', 'push', url, 'main'], cwd=ROOT, capture_output=True, text=True)
        tag = f'v{version}'
        r2 = subprocess.run(['git', 'tag', tag], cwd=ROOT, capture_output=True, text=True)
        if r2.returncode != 0:
            print(f'[WARN] tag {tag} may already exist: {r2.stderr.strip()}')
        r3 = subprocess.run(['git', 'push', url, tag], cwd=ROOT, capture_output=True, text=True)
        print(r3.stdout.strip() or r3.stderr.strip())
    else:
        r = subprocess.run(['git', 'push', 'origin', 'main'], cwd=ROOT, capture_output=True, text=True)
        print(f'[WARN] no GITHUB_TOKEN — tag v{version} not pushed, GitHub Release skipped')
    print(r.stdout.strip() or r.stderr.strip())
    if r.returncode != 0:
        print('[FAIL] push — check GITHUB_TOKEN or local git credentials')
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
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = resp.read().decode('utf-8')
            return json.loads(body) if body else {}
    except urllib.error.HTTPError as e:
        msg = e.read().decode('utf-8', errors='replace')[:300]
        print(f'[FAIL] GitHub API {method} {url}: HTTP {e.code} {msg}')
        sys.exit(1)


def github_release(version: str, zip_path: str, note: str = '') -> None:
    token = get_token()
    if not token:
        print('[SKIP] GitHub Release（无 GITHUB_TOKEN）')
        return
    tag = f'v{version}'
    prerelease = 'beta' in version
    if note:
        body = note
    else:
        # Release Notes 模板（用户规则 2026-08-10）：
        # - 只输出有内容的章节；无内容的章节整章删除（包括「破坏性变更：无」「本版无修复」等占位）
        # - 必有：版本概述 + 升级指南 + 完整变更日志链接（CHANGELOG.md）
        # - 可选（按实际内容）：🚨 破坏性变更 / 🚀 新功能 / ⬆️ 增强 / 🐛 修复 / 📚 文档 / 贡献者致谢
        cl_url = 'https://github.com/Grabrun/MoeRNG/blob/main/CHANGELOG.md'
        kind = '测试版' if prerelease else '正式版'
        body = (
            f'## v{version}\n\n'
            f'**版本概述**：{kind}版本，用于验证新功能与修复。\n\n'
            '### 升级指南（Upgrade Guide）\n'
            '1. 下载下方 zip 资产覆盖部署（参考 README 快速开始）。\n'
            '2. 重启 PHP-FPM 触发数据库自动迁移。\n'
            '3. 运行 doctor.php 验证部署健康后删除。\n\n'
            f'完整变更日志请查看 [CHANGELOG.md]({cl_url})。'
        )

    # Create the release (idempotent-ish: fail fast if tag exists without release).
    release = _api('POST', f'{API}/repos/{REPO}/releases', token, {
        'tag_name': tag,
        'name': f'MoeRNG v{version}' + ('（测试版）' if prerelease else ''),
        'body': body,
        'draft': False,
        'prerelease': prerelease,
    })
    rid = release['id']
    print(f'[OK  ] GitHub Release created: {release["html_url"]} (prerelease={prerelease})')

    # Upload the zip asset.
    fname = os.path.basename(zip_path)
    url = f'{UPLOADS}/repos/{REPO}/releases/{rid}/assets?name={urllib.parse.quote(fname)}'
    with open(zip_path, 'rb') as f:
        asset = _api('POST', url, token, raw=f.read(), content_type='application/zip')
    print(f'[OK  ] Asset uploaded: {asset.get("browser_download_url", fname)}')


def main() -> None:
    if len(sys.argv) < 2:
        print('usage: python tools/release.py <version> [--no-push] [--stable] [--note "..."] [--note-file path]')
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
                # v1.3.0-beta.2 修复: note 草稿读入内存后立即删除，否则
                # git() 的 `git add -A` 会把发布说明草稿一起提交进仓库
                # （已两次发生在 tools/_relnote_*.md，需事后手工清理）。
                # 仅清理仓库工作树内的草稿（releases/ 已被 gitignore，
                # 放那里的草稿无需删除）；工作树外的路径不动。
                try:
                    rel = os.path.relpath(os.path.abspath(path), ROOT)
                    inside_tree = not rel.startswith('..')
                    if inside_tree and not rel.replace('\\', '/').startswith('releases'):
                        os.remove(path)
                        print(f'[OK  ] temp note removed: {path}')
                except (OSError, ValueError):
                    # ValueError: note 在其它盘符（relpath 跨盘符不支持）→ 保守不删
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
    z = package()
    print(f'[OK  ] zip: {os.path.basename(z)}')
    git(version, push)
    if push:
        github_release(version, z, note)
    print(f'=== released v{version} ({"pushed + release" if push else "commit-only"}) ===')


if __name__ == '__main__':
    main()
