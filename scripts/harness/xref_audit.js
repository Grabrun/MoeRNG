// 全站跨文件引用对账（deep audit）。
//
// 背景：本项目近期多次生产故障都属于「跨文件引用断裂」——重命名/改写后另一侧没跟上：
//   · 路由指向不存在的控制器方法（点了 500）
//   · render('x') 指向不存在的视图
//   · icon('y') 用了未定义的图标名（渲染空白）
//   · 前端 getElementById('z') 在视图里没有对应元素（绑定静默失效）
//   · class 用了 CSS 未定义的类（.btn-warning 曾整类缺失 → 裸按钮）
// 语法检查对以上全部无效。本脚本逐项对账。
//
// 用法：node scripts/harness/xref_audit.js（仓库根下运行；脚本内路径相对仓库根）
const fs = require('fs');
const path = require('path');

const read = f => fs.readFileSync(f, 'utf8');
const exists = f => fs.existsSync(f);

function walk(dir, filter, out = []) {
  if (!exists(dir)) return out;
  for (const f of fs.readdirSync(dir)) {
    const fp = path.join(dir, f);
    if (f === 'sdk' || f === 'vendor' || f === 'node_modules') continue;
    const st = fs.statSync(fp);
    if (st.isDirectory()) walk(fp, filter, out);
    else if (filter(f)) out.push(fp);
  }
  return out;
}

let errors = 0, warns = 0;
const section = t => console.log('\n── ' + t + ' ──');
const ok = m => console.log('  ✓ ' + m);
const bad = m => { errors++; console.log('  ✗ ' + m); };
const warn = m => { warns++; console.log('  ⚠ ' + m); };

// 类名 → 文件路径（App\ → src/app/）
const classToFile = fqcn => {
  const rel = fqcn.replace(/^\\+/, '').replace(/^App\\/, '');
  return 'src/app/' + rel.replace(/\\/g, '/') + '.php';
};

// ───────────────────────── 1. 路由 → 控制器方法 ─────────────────────────
section('1. 路由 → 控制器方法');
const routeFiles = ['src/admin.php', 'src/api.php', 'src/index.php', 'src/install.php'];
let routeCount = 0;
const routePaths = { get: new Set(), post: new Set() };
let middlewareRefs = [];

// 求 group('prefix', function(){...}) 的范围与前缀（按花括号配对）
function groupRanges(src) {
  const out = [];
  for (const m of src.matchAll(/->group\(\s*'([^']+)'/g)) {
    const braceStart = src.indexOf('{', m.index);
    if (braceStart < 0) continue;
    let depth = 0, end = braceStart;
    for (let i = braceStart; i < src.length; i++) {
      if (src[i] === '{') depth++;
      else if (src[i] === '}') { depth--; if (depth === 0) { end = i; break; } }
    }
    out.push({ start: m.index, end, prefix: m[1] });
  }
  return out;
}

for (const rf of routeFiles) {
  if (!exists(rf)) continue;
  const src = read(rf);
  const groups = groupRanges(src);
  const prefixFor = (idx) => {
    // 取覆盖该位置的最内层 group（后出现的优先），支持嵌套拼接
    const hit = groups.filter(g => idx > g.start && idx < g.end).sort((a, b) => a.start - b.start);
    return hit.map(g => g.prefix).join('');
  };

  // 变量 → 类名
  const varClass = {};
  for (const m of src.matchAll(/\$([A-Za-z_]\w*)\s*=\s*(\\?[A-Za-z_\\][\w\\]*)::class\s*;/g)) {
    varClass[m[1]] = m[2];
  }
  // 中间件/其它 ::class 引用
  for (const m of src.matchAll(/(\\App\\[A-Za-z_\\]+)::class/g)) middlewareRefs.push([rf, m[1]]);

  // 路由
  for (const m of src.matchAll(/\$router->(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*\[\s*([^,\]]+?)\s*,\s*'([^']+)'\s*\]/g)) {
    const [, verb, rawPath, clsExpr, method] = m;
    routeCount++;
    const routePath = prefixFor(m.index) + (rawPath === '/' ? '' : rawPath);
    routePaths[verb] = routePaths[verb] || new Set();
    routePaths[verb].add(routePath === '' ? '/' : routePath);

    let fqcn = clsExpr.trim();
    if (fqcn.startsWith('$')) fqcn = varClass[fqcn.slice(1)] || '';
    fqcn = fqcn.replace(/^\\+/, '').replace(/::class$/, '');   // 直接写 \App\X::class 的情况
    if (!fqcn) { bad(`${rf}: ${verb} ${routePath} → 无法解析控制器类（${clsExpr.trim()}）`); continue; }

    const file = classToFile(fqcn);
    if (!exists(file)) { bad(`${rf}: ${verb} ${routePath} → 控制器文件缺失 ${file}`); continue; }

    const body = read(file);
    if (!new RegExp('public (?:static )?function ' + method + '\\s*\\(').test(body)) {
      // 可能继承自基类
      const ext = body.match(/class\s+\w+\s+extends\s+(\w+)/);
      const baseFile = 'src/app/Core/' + (ext ? ext[1] : 'Controller') + '.php';
      if (exists(baseFile) && new RegExp('public (?:static )?function ' + method + '\\s*\\(').test(read(baseFile))) {
        ok(`${verb} ${routePath} → ${fqcn}::${method}()（继承自 ${path.basename(baseFile)}）`);
      } else {
        bad(`${rf}: ${verb} ${routePath} → ${fqcn}::${method}() 方法不存在`);
      }
    }
  }
}
ok(`解析 ${routeCount} 条路由，全部指向存在的 public 方法`);

// 中间件/其它 ::class 引用
const uniqMw = [...new Set(middlewareRefs.map(x => x[1]))];
const missingMw = uniqMw.filter(fq => !exists(classToFile(fq)));
if (missingMw.length) missingMw.forEach(fq => bad(`::class 引用指向缺失文件: ${fq} → ${classToFile(fq)}`));
else ok(`${uniqMw.length} 个 ::class 引用（控制器/中间件/Captcha）均有对应文件`);

// ───────────────────────── 2. 控制器 → 视图 ─────────────────────────
section('2. 控制器 render() → 视图文件');
const controllerFiles = walk('src/app/Controllers', f => f.endsWith('.php'));
let viewRefs = 0;
const allViews = walk('src/views', f => f.endsWith('.php')).map(f => f.replace(/\\/g, '/'));
for (const cf of controllerFiles) {
  for (const m of read(cf).matchAll(/->render\(\s*'([^']+)'/g)) {
    viewRefs++;
    const vf = 'src/views/' + m[1] + '.php';
    if (!exists(vf)) bad(`${cf}: render('${m[1]}') → 视图缺失 ${vf}`);
  }
}
ok(`${viewRefs} 处 render() 全部指向存在的视图`);

// ───────────────────────── 3. 视图 require 子模板 ─────────────────────────
section('3. 视图 require → 子模板文件');
let reqCount = 0;
for (const vf of allViews) {
  const dir = path.dirname(vf);
  for (const m of read(vf).matchAll(/(?:require|include)(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/g)) {
    reqCount++;
    const target = path.join(dir, m[1]).replace(/\\/g, '/');
    if (!exists(target)) bad(`${vf}: require '${m[1]}' → 文件缺失 ${target}`);
  }
}
ok(`${reqCount} 处 require __DIR__ 全部存在`);

// ───────────────────────── 4. icon('name') 图标名 ─────────────────────────
section('4. icon(name) → icons.php 定义');
const iconsSrc = read('src/views/partials/icons.php');
const definedIcons = new Set([...iconsSrc.matchAll(/'([a-z0-9-]+)'\s*=>/g)].map(m => m[1]));
const usedIcons = new Map();
for (const f of [...allViews, ...walk('src/public/js', x => x.endsWith('.js'))]) {
  for (const m of read(f).matchAll(/\bicon\(\s*'([a-z0-9-]+)'/g)) {
    if (!usedIcons.has(m[1])) usedIcons.set(m[1], []);
    usedIcons.get(m[1]).push(f);
  }
}
const missingIcons = [...usedIcons.keys()].filter(n => !definedIcons.has(n));
if (missingIcons.length) missingIcons.forEach(n => bad(`icon('${n}') 未在 icons.php 定义（${usedIcons.get(n)[0]}）`));
else ok(`使用 ${usedIcons.size} 个图标名，全部已定义（icons.php 共 ${definedIcons.size} 个）`);

// ───────────────────────── 5. 视图内部链接 → 路由 ─────────────────────────
section('5. 视图 action/href → 已注册路由');
const known = new Set();
for (const verb of Object.keys(routePaths)) for (const p of routePaths[verb]) known.add(p);
// 精确匹配（前缀已在解析阶段拼好）；容错：允许尾斜杠差异
const resolveLink = (link) => {
  const clean = link.split('?')[0].split('#')[0].replace(/\/+$/, '');
  if (clean === '' ) return known.has('/');
  return known.has(clean);
};
const getRoutes = routePaths.get || new Set();
const postRoutes = routePaths.post || new Set();
let linkCount = 0, methodCount = 0;
const brokenLinks = [], wrongMethod = [];
const SKIP = [/^\/files\//, /^\/admin\/captcha/];

for (const vf of allViews) {
  const src = read(vf);

  // 5a. <a href="/..."> → 必须是 GET 路由（否则点击 404）
  for (const m of src.matchAll(/<a\b[^>]*\shref="(\/(?:admin|api|install)[^"#]*?)"/g)) {
    linkCount++;
    const link = m[1];
    if (SKIP.some(re => re.test(link))) continue;
    methodCount++;
    if (!resolveLink(link)) brokenLinks.push(`${vf}: ${link}`);
    else if (!getRoutes.has(link.split('?')[0].replace(/\/+$/, ''))) wrongMethod.push(`${vf}: <a href="${link}"> 指向非 GET 路由`);
  }

  // 5b. <form ... action="/..." ...> → 按其 method 校验（默认 GET）
  for (const m of src.matchAll(/<form\b[^>]*>/g)) {
    const tag = m[0];
    const act = tag.match(/action="(\/(?:admin|api|install)[^"#]*?)"/);
    if (!act) continue;
    linkCount++;
    const link = act[1];
    if (SKIP.some(re => re.test(link))) continue;
    const method = (/method="post"/i.test(tag) || /method='post'/i.test(tag)) ? 'post' : 'get';
    const set = method === 'post' ? postRoutes : getRoutes;
    const clean = link.split('?')[0].replace(/\/+$/, '');
    methodCount++;
    if (!set.has(clean)) {
      if (resolveLink(link)) wrongMethod.push(`${vf}: <form method="${method.toUpperCase()}" action="${link}"> 无对应 ${method.toUpperCase()} 路由`);
      else brokenLinks.push(`${vf}: form action ${link}`);
    }
  }
}
if (brokenLinks.length) brokenLinks.forEach(b => bad(`视图链接/表单无对应路由: ${b}`));
if (wrongMethod.length) wrongMethod.forEach(b => bad(`HTTP 方法不匹配（点了会 404）: ${b}`));
if (!brokenLinks.length && !wrongMethod.length) ok(`${methodCount} 个 <a href>/<form action> 路径与 HTTP 方法均匹配（共扫描 ${linkCount} 处）`);

// ───────────────────────── 6. JS getElementById → 视图元素 ─────────────────────────
section('6. JS getElementById → 视图中的 id（提示级）');
const viewIds = new Set();
for (const vf of allViews) {
  for (const m of read(vf).matchAll(/id="([A-Za-z0-9_-]+)"/g)) viewIds.add(m[1]);
}
const jsIds = new Map();
for (const jf of walk('src/public/js', x => x.endsWith('.js'))) {
  for (const m of read(jf).matchAll(/getElementById\(\s*'([A-Za-z0-9_-]+)'/g)) {
    if (m[1].endsWith('-')) continue;   // 动态拼接：getElementById('x-' + id)
    if (!jsIds.has(m[1])) jsIds.set(m[1], []);
    jsIds.get(m[1]).push(jf);
  }
}
// JS 运行时动态创建/由数据驱动的 id 白名单
const DYNAMIC_IDS = new Set(['progress-bar', 'lb-image', 'preview-image']);
const missingIds = [...jsIds.keys()].filter(id => !viewIds.has(id) && !DYNAMIC_IDS.has(id));
if (missingIds.length) missingIds.forEach(id => warn(`getElementById('${id}') 在所有视图中未见（${jsIds.get(id)[0]}）`));
else ok(`${jsIds.size} 个 getElementById 目标均能在视图中找到`);

// ───────────────────────── 7. CSS 类 → 样式定义 ─────────────────────────
section('7. 视图/JS 使用的 class → CSS 定义（提示级）');
const cssFiles = walk('src/public/css', f => f.endsWith('.css'));
const css = cssFiles.map(read).join('\n');
const definedClasses = new Set([...css.matchAll(/\.([A-Za-z][\w-]*)/g)].map(m => m[1]));
const usedClasses = new Map();
for (const vf of allViews) {
  // 注意 (?<![\w-]) —— 否则会把 data-toggle-class="x" 也当成 class 属性（曾误报 modal id）
  for (const m of read(vf).matchAll(/(?<![\w-])class="([^"]*)"/g)) {
    for (const c of m[1].split(/\s+/).filter(Boolean)) {
      // 只接受真实 CSS 类名：小写字母开头 + 字母数字/连字符/下划线
      // （排除 PHP 片段：$var、'x'、!==、(expr)、%s 等）
      if (!/^-?[a-z][a-z0-9_-]*$/.test(c)) continue;
      if (!usedClasses.has(c)) usedClasses.set(c, []);
      usedClasses.get(c).push(vf);
    }
  }
}
for (const jf of walk('src/public/js', x => x.endsWith('.js'))) {
  for (const m of read(jf).matchAll(/classList\.(?:add|remove|toggle|contains)\(\s*'([\w-]+)'/g)) {
    if (!usedClasses.has(m[1])) usedClasses.set(m[1], []);
    usedClasses.get(m[1]).push(jf);
  }
}
// 纯行为标记（无样式需求）/ 条件拼接产生的类名
// 纯行为标记 / 由父选择器或工具类覆盖 / 动态拼接 —— 已人工确认无需独立样式
// 白名单已清空（2026-09-13）：此前这里堆了 46 项，其中 36 项其实**已有 CSS 定义**
// （属过期豁免），剩下的是把 .pv-*/.setting-*/.stats-grid 等真缺口"豁免"掉了 ——
// 等于用白名单掩盖了缺口。现在改为：视图/JS 用到的类**必须有定义**，例外需在此
// 显式列出并写明理由（当前为空）。
const CLASS_IGNORE = new Set([]);
const missingClasses = [...usedClasses.keys()].filter(c => !definedClasses.has(c) && !CLASS_IGNORE.has(c));
// 用了却无定义 = 元素会被渲染但完全没有样式（静默失效）→ 按错误处理
if (missingClasses.length) missingClasses.forEach(c => bad(`class "${c}" 无 CSS 定义（${usedClasses.get(c)[0]}）`));
else ok(`${usedClasses.size} 个 class 使用均能在 CSS 中找到定义（白名单豁免 ${CLASS_IGNORE.size} 项）`);

// ───────────────────────── 汇总 ─────────────────────────
console.log('\n' + '='.repeat(60));
console.log(errors === 0
  ? `✓ 跨文件引用审计通过（${warns} 条提示需人工确认）`
  : `✗ ${errors} 处断链 + ${warns} 条提示`);
process.exit(errors === 0 ? 0 : 1);
