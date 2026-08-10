#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MoeRNG 一键发版脚本（v1.1.0 起）：bump 版本 → 打包 zip → git commit → push GitHub。

用法：
  python tools/release.py 1.1.1-beta.1            # 测试版（默认）
  python tools/release.py 1.1.1-beta.1 --no-push  # 只 bump + 打包 + commit
  python tools/release.py 1.1.1 --stable          # 正式版（必须显式 --stable）

⚠️ 发布门禁（用户规则 2026-08-10）：
- 只有用户明确表示「发正式版」才能发布正式版（去 beta 后缀）。
- 其余一律测试版（X.Y.Z-beta.N）。
- 正式版格式（无 -beta 后缀）必须显式传 --stable，否则脚本拒绝执行；
  agent 也只在用户明确要求时才会传该标志。不确定时先问用户。

说明：
- bump 同步 4 处：src/bootstrap.php / src/app/Controllers/ApiController.php /
  src/views/home.php / tools/_package.py（打包文件名模板）
- push 凭据：优先环境变量 GITHUB_TOKEN（fine-grained PAT，单次内嵌 URL，不落盘）；
  无 token 时走普通 `git push origin`（用你本机的 git 凭据）。
- 发版后必须推送——项目规范：releases/ zip 与 GitHub 代码同步。

SemVer 约定（用户规则）：
- 测试期修 bug → X.Y.Z-beta.N+1；新增兼容功能 → MINOR+1 + beta.1；不兼容 → MAJOR+1 + beta.1
"""
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = 'Grabrun/MoeRNG'
FILES = [
    ('src/bootstrap.php', re.compile(r"(define\('APP_VERSION', ')[^']+('\))"), lambda v: r"\g<1>" + v + r"\g<2>"),
    ('src/app/Controllers/ApiController.php', re.compile(r"('version' => defined\('APP_VERSION'\) \? APP_VERSION : ')[^']+(',)"), lambda v: r"\g<1>" + v + r"\g<2>"),
    ('src/views/home.php', re.compile(r"(\? APP_VERSION : ')[^']+(')"), lambda v: r"\g<1>" + v + r"\g<2>"),
    ('tools/_package.py', re.compile(r"(MoeRNG-v)[0-9A-Za-z.\-]+(-)"), lambda v: r"\g<1>" + v + r"\g<2>"),
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
    token = os.environ.get('GITHUB_TOKEN', '')
    if token:
        url = f'https://Grabrun:{token}@github.com/{REPO}.git'
        r = subprocess.run(['git', 'push', url, 'main'], cwd=ROOT, capture_output=True, text=True)
    else:
        r = subprocess.run(['git', 'push', 'origin', 'main'], cwd=ROOT, capture_output=True, text=True)
    print(r.stdout.strip() or r.stderr.strip())
    if r.returncode != 0:
        print('[FAIL] push — check GITHUB_TOKEN or local git credentials')
        sys.exit(1)


def main() -> None:
    if len(sys.argv) < 2:
        print('usage: python tools/release.py <version> [--no-push] [--stable]')
        sys.exit(1)
    version = sys.argv[1]
    if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(-beta\.[0-9]+)?', version):
        print(f'[FAIL] invalid semver: {version}')
        sys.exit(1)
    push = '--no-push' not in sys.argv
    stable = '--stable' in sys.argv

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
    print(f'=== released v{version} ({"pushed" if push else "commit-only"}) ===')


if __name__ == '__main__':
    main()
