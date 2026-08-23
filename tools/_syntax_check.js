// 视图 PHP 语法检查（php-parser，开发机无 PHP CLI 的替代方案）
const parser = require('php-parser');
const p = new parser({ parser: { extractDoc: false }, ast: { withPositions: false } });
const fs = require('fs');
const path = require('path');

function walk(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['sdk', 'node_modules', '_stale'].includes(e.name)) continue;
    const fp = path.join(dir, e.name);
    if (e.isDirectory()) walk(fp, acc);
    else if (e.name.endsWith('.php')) acc.push(fp);
  }
  return acc;
}

const files = walk('src/views');
let bad = 0;
for (const f of files) {
  try {
    p.parseCode(fs.readFileSync(f, 'utf8'));
  } catch (e) {
    console.log('FAIL ' + f.replace(/\\/g, '/') + ' ' + e.message.split('\n')[0]);
    bad++;
  }
}
console.log(bad === 0 ? 'PHP-OK all ' + files.length + ' views' : 'PHP-FAIL ' + bad);
process.exit(bad === 0 ? 0 : 1);
