// MoeRNG front.js 运行时等价验证（一次性）：
// 模拟前台页面 DOM（home 随机预览 + tester + gallery 统计/reveal），加载 helpers.js + front.js，
// 触发 DOMContentLoaded 链，断言零异常且关键交互函数可达。
// 用法: node docs/scripts/verify_front_runtime.js
const fs = require('fs'), vm = require('vm');
const noop = () => {};
const errors = [];

function makeEl(id) {
  const listeners = {};
  const el = {
    id, listeners,
    addEventListener: (ev, fn) => { (listeners[ev] = listeners[ev] || []).push(fn); },
    classList: { add: noop, remove: noop, toggle: noop, contains: () => false },
    style: {}, dataset: {}, setAttribute: noop, getAttribute: () => null,
    appendChild: noop, removeChild: noop, remove: noop, closest: () => null,
    querySelector: () => null, querySelectorAll: () => [],
    disabled: false, textContent: '', innerHTML: '', value: '', files: [],
    checked: false, hidden: false, src: '', innerText: '', alt: '', download: '',
    rel: '', href: '', offsetWidth: 0,
  };
  return el;
}

// 前台页面 DOM 元素（home + tester + gallery 交集）
const registry = {};
['api-tester','test-category','test-type','test-size','test-run','test-result','test-url',
 'test-curl','test-status','test-duration','test-meta',
 'rd-run','rd-image','rd-url','rd-category','rd-loading','rd-placeholder','rd-meta',
 'rd-zoom','rd-download','rd-lightbox','rd-lb-img','rd-lb-close',
 'lightbox','lb-image','lb-name','lb-url','lb-count','lb-copy','lb-close','lb-prev','lb-next',
].forEach(id => registry[id] = makeEl(id));

let domReady = null;
const sandbox = {
  console: { log: noop, warn: noop, error: noop, info: noop },
  document: {
    addEventListener: (ev, fn) => { if (ev === 'DOMContentLoaded') domReady = fn; },
    getElementById: (id) => registry[id] || null,
    querySelector: (sel) => (sel === '.random-demo' ? registry['rd-run'] : sel === '.reveal' ? null : null),
    querySelectorAll: (sel) => (sel === '.reveal' ? [] : sel === '.copy-btn' ? [] : sel === '.stat-value[data-count]' ? [] : []),
    createElement: (tag) => makeEl(tag),
    body: { appendChild: noop, removeChild: noop, classList: { add: noop, remove: noop } },
    documentElement: { style: {}, setAttribute: noop },
  },
  window: { addEventListener: noop, location: { reload: noop, search: '', href: '' }, confirm: () => true, setTimeout, clearTimeout, matchMedia: () => ({ matches: true }) },
  setTimeout, clearTimeout,
  fetch: async () => {
    const body = JSON.stringify({ success: true, data: { url: 'https://x/u.webp', category: 'c', thumbs: { md: 'm.webp' } } });
    return { ok: true, status: 200, text: async () => body, json: async () => JSON.parse(body) };
  },
  FormData: class { append(){} },
  URLSearchParams: class { constructor(q){ this.q=q||''; } toString(){ return this.q; } set(){} },
  navigator: { clipboard: { writeText: async () => {} } },
  localStorage: { getItem: () => null, setItem: noop },
  location: { reload: noop, search: '', href: '' },
  confirm: () => true, alert: noop, performance: { now: () => 0 },
  Image: class { set src(v){ if (v) { this.onload && this.onload(); } } },
  IntersectionObserver: class { observe(){} unobserve(){} },
  requestAnimationFrame: (fn) => 0,
  matchMedia: () => ({ matches: true }),
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

try { vm.runInContext(fs.readFileSync('src/public/js/helpers.js', 'utf8'), sandbox, { filename: 'helpers.js' }); }
catch (e) { errors.push('helpers.js: ' + e.message); }
try { vm.runInContext(fs.readFileSync('src/public/js/front.js', 'utf8'), sandbox, { filename: 'front.js' }); }
catch (e) { errors.push('front.js: ' + e.message); }

if (domReady) { try { domReady(); } catch (e) { errors.push('DOMContentLoaded: ' + e.message); } } else { errors.push('DOMContentLoaded 未注册'); }

// 断言关键前台函数可达（顶层声明 → sandbox 上下文内可解析）
const checks = ['initApiTester', 'initCopyButtons', 'parseJsonResponse',
                'initReveal', 'initRandomDemo', 'initStatCount', 'applyDynamicStyles', 'UX_ICON'];
const winChecks = ['showToast', 'copyText'];
checks.forEach(name => {
  const present = vm.runInContext('typeof ' + name, sandbox) !== 'undefined';
  if (!present) errors.push('顶层符号缺失: ' + name);
});
winChecks.forEach(name => {
  const present = vm.runInContext('typeof window.' + name, sandbox) === 'function';
  if (!present) errors.push('window 全局缺失: ' + name);
});

if (errors.length) { errors.forEach(e => console.log('✗ ' + e)); process.exit(1); }
console.log('✓ front.js 前台 DOM 运行时链零异常，10 个关键符号全部可达');
