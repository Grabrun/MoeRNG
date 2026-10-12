// PHP「未定义变量」静态检查（AST 级，基于 php-parser）。
//
// 背景：两次连续的生产故障都是同一类 —— 字符串手术式改代码后，某变量被引用却
// 从未定义。PHP 把未定义变量求值为 null（只发个 Warning），于是错误在下游爆发成
// 更难懂的形态：
//   · ImageController: $mime 未定义 → S3Driver::upload(): Argument #3 must be
//     of type string, null given
// 语法检查（php-parser parse）永远看不出这类问题 —— 语法完全合法。
//
// 做法：逐作用域（function / method / closure）收集「已定义变量」，再收集全部
// 变量使用，报告未被定义的。闭包不继承外层变量（除非 use），箭头函数自动捕获。
//
// 用法：node scripts/harness/php_undef_check.js [paths...]
//   默认扫描 src/app + src/ 入口 php（路径相对仓库根解析，与 cwd 无关）
const fs = require('fs');
const path = require('path');
const parser = require('php-parser');

// 仓库根：本脚本位于 scripts/harness/，向上两级。
const ROOT = path.resolve(__dirname, '..', '..');

const p = new parser({ parser: { extractDoc: false, suppressErrors: true }, ast: { withPositions: true } });

const ALLOW = new Set(['this', 'GLOBALS', '_GET', '_POST', '_SERVER', '_SESSION', '_COOKIE',
  '_FILES', '_ENV', '_REQUEST', 'argv', 'argc', 'http_response_header', 'php_errormsg']);

function walk(node, fn) {
  if (!node || typeof node !== 'object') return;
  if (Array.isArray(node)) { for (const it of node) walk(it, fn); return; }
  if (typeof node.kind === 'string') fn(node);
  for (const k of Object.keys(node)) {
    if (k === 'loc' || k === 'kind') continue;
    const v = node[k];
    if (v && typeof v === 'object') walk(v, fn);
  }
}

const isFn = n => ['function', 'method', 'closure', 'arrowfunc'].includes(n.kind);

// 收集某个节点「自身作用域」内定义的变量；不进入嵌套函数作用域。
function collectDefs(node, defs) {
  const add = v => { if (v && v.kind === 'variable' && typeof v.name === 'string') defs.add(v.name); };
  const addAssigned = left => {
    if (!left) return;
    if (left.kind === 'variable') add(left);
    else if (left.kind === 'list' || left.kind === 'array') (left.items || []).forEach(addAssigned);
    else if (left.kind === 'entry') addAssigned(left.value);        // 解构项：[$a, $b] 里每项是 entry
    else if (left.kind === 'offsetlookup') addAssigned(left.what);  // $a[0] = 1
    // staticlookup / propertylookup 的 offset 是属性名，不是局部变量 —— 跳过
  };

  walk(node, n => {
    if (n === node) return;
    if (isFn(n)) return;                       // 不深入嵌套函数
    switch (n.kind) {
      case 'assign':
        addAssigned(n.left);
        break;
      case 'foreach':
        addAssigned(n.key);
        addAssigned(n.value);
        break;
      case 'catch':
        add(n.variable);   // php-parser: catch 变量在 variable 属性（what 是异常类型数组）
        break;
      case 'global':
        (n.items || []).forEach(add);
        break;
      case 'staticvariable':
        add(n.variable);
        break;
      case 'call': {
        // out-param 函数：其实参变量视为已定义
        const what = n.what;
        if (what && what.kind === 'name' && OUT_PARAM_FNS.has(what.name)) {
          (n.arguments || []).forEach(a => {
            if (a && a.kind === 'variable') add(a);
            else if (a && a.kind === 'offsetlookup') addAssigned(a.what);
          });
        }
        break;
      }
      case 'pre':
      case 'post':
        if (n.type === 'preinc' || n.type === 'predec' || n.type === 'postinc' || n.type === 'postdec') {
          addAssigned(n.what);
        }
        break;
      default:
        break;
    }
  });
}

// 这些函数会把结果写进实参（by-ref / out-param），AST 里看不到赋值 —— 视为已定义
const OUT_PARAM_FNS = new Set(['preg_match', 'preg_match_all', 'preg_replace_callback_array',
  'parse_str', 'settype', 'sscanf', 'fscanf', 'preg_split', 'str_replace', 'similar_text',
  'sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort', 'usort', 'uasort', 'uksort',
  'shuffle', 'array_splice', 'array_shift', 'array_unshift', 'array_pop', 'array_push',
  'end', 'reset', 'next', 'prev', 'each', 'list']);

function checkScope(fnNode, file, out, parentDefs) {
  const defs = new Set();
  // 参数
  (fnNode.arguments || []).forEach(a => {
    if (a && a.kind === 'parameter' && a.name && typeof a.name.name === 'string') defs.add(a.name.name);
  });
  // 闭包 use
  if (fnNode.kind === 'closure' && Array.isArray(fnNode.uses)) {
    fnNode.uses.forEach(u => { if (u && typeof u.name === 'string') defs.add(u.name); });
  }
  // 箭头函数自动捕获外层
  if (fnNode.kind === 'arrowfunc' && parentDefs) parentDefs.forEach(v => defs.add(v));

  collectDefs(fnNode.body || fnNode, defs);

  // 收集使用（不进入嵌套函数）
  const used = new Map();
  const scan = node => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) { node.forEach(scan); return; }
    if (isFn(node) && node !== fnNode) return;
    // self::$x / $obj->$x 的 offset 是属性名，不是局部变量 —— 只扫 what，跳过 offset
    if (node.kind === 'staticlookup' || node.kind === 'propertylookup') {
      scan(node.what);
      return;
    }
    if (node.kind === 'variable' && typeof node.name === 'string') {
      if (!used.has(node.name)) used.set(node.name, node.loc ? node.loc.start.line : '?');
    }
    if (node.kind === 'parameter' && node.name && typeof node.name.name === 'string') {
      if (!used.has(node.name.name)) used.set(node.name.name, node.loc ? node.loc.start.line : '?');
    }
    for (const k of Object.keys(node)) {
      if (k === 'loc' || k === 'kind') continue;
      const v = node[k];
      if (v && typeof v === 'object') scan(v);
    }
  };
  scan(fnNode.body || fnNode);

  for (const [name, line] of used) {
    if (ALLOW.has(name)) continue;
    if (defs.has(name)) continue;
    out.push({ file, line, name, scope: fnNode.name && fnNode.name.name ? fnNode.name.name : '(closure)' });
  }

  // 递归检查嵌套函数
  walk(fnNode.body || fnNode, n => {
    if (isFn(n) && n !== fnNode) checkScope(n, file, out, defs);
  });
}

function analyze(file) {
  const code = fs.readFileSync(file, 'utf8');
  const display = path.relative(ROOT, file).replace(/\\/g, '/');
  let ast;
  try { ast = p.parseCode(code); } catch (e) { return [{ file: display, line: 0, name: 'PARSE FAIL: ' + e.message.split('\n')[0] }]; }
  const out = [];
  // 文件顶层作用域
  checkScope({ kind: 'function', name: { name: '(top-level)' }, body: ast, arguments: [] }, display, out, null);
  return out;
}

function collectFiles(target) {
  const st = fs.statSync(target);
  if (st.isFile()) return target.endsWith('.php') ? [target] : [];
  let out = [];
  for (const f of fs.readdirSync(target)) {
    const fp = path.join(target, f);
    if (f === 'sdk' || f === 'vendor' || f === 'node_modules') continue;
    const s = fs.statSync(fp);
    if (s.isDirectory()) out = out.concat(collectFiles(fp));
    else if (f.endsWith('.php')) out.push(fp);
  }
  return out;
}

// 注意：**不扫描 src/views/** —— 视图是模板，其变量由控制器通过 render() 注入，
// AST 里看不到"定义"，全部是误报。本检查面向程序化代码（app/ + 入口文件）。
// 路径相对仓库根（ROOT）解析，可从任意 cwd 运行。
const argTargets = process.argv.slice(2);
const defaultTargets = ['src/app', 'src/doctor.php', 'src/admin.php', 'src/api.php', 'src/index.php', 'src/bootstrap.php'];
const targets = (argTargets.length ? argTargets : defaultTargets)
  .map(t => (path.isAbsolute(t) ? t : path.join(ROOT, t)));

let files = [];
for (const t of targets) { try { files = files.concat(collectFiles(t)); } catch (e) { /* 忽略不存在的路径 */ } }
files = [...new Set(files)];

let issues = [];
for (const f of files) issues = issues.concat(analyze(f));

console.log('扫描 ' + files.length + ' 个 PHP 文件（AST 级未定义变量检查）');
if (issues.length === 0) {
  console.log('✓ 未发现「使用但未定义」的变量');
  process.exit(0);
}
console.log('✗ 发现 ' + issues.length + ' 处：');
for (const i of issues) console.log('  ' + i.file + ':' + i.line + '  $' + i.name + '  (' + (i.scope || '') + ')');
process.exit(1);
