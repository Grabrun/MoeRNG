const fs = require('fs');
const path = require('path');
const engine = require('php-parser');

// 仓库根：本脚本位于 scripts/harness/，向上两级。
const ROOT = path.resolve(__dirname, '..', '..');
const SKIP = new Set(['.workbuddy', 'releases', '.git', 'node_modules', 'reference']);

const parser = new engine({
  parser: { extractDoc: false, suppressErrors: false, version: 804 },
  ast: { withPositions: true },
});

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(e.name)) continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(p);
  }
  return out;
}

const files = walk(ROOT).sort();
let ok = 0;
const errors = [];

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  try {
    parser.parseCode(src, f);
    ok++;
  } catch (err) {
    errors.push({ file: path.relative(ROOT, f), msg: err.message.split('\n')[0] });
  }
}

console.log(`PHP syntax check: ${ok}/${files.length} files parsed OK`);
if (errors.length) {
  console.log('');
  for (const e of errors) console.log(`  SYNTAX ERROR  ${e.file}\n                ${e.msg}`);
  process.exitCode = 1;
} else {
  console.log('No syntax errors.');
}
