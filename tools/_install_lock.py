p = 'src/app/Controllers/InstallController.php'
s = open(p, encoding='utf-8').read()

# 给 step2/step3/step4/complete 全部加 installed 锁（重装攻击面封堵）
guard = '''        // Security: block reinstall on an already-installed site. Without
        // this, anyone could POST through the wizard and overwrite
        // config/database.php + recreate the admin account (full takeover).
        if (\\App\\Core\\Config::get('app.installed', false)) {
            $this->redirect('/');
        }
'''

import re
count = 0
for fn in ['step2', 'step3', 'step4', 'complete']:
    pattern = f'    public function {fn}(Request $request): void\n    {{\n'
    replacement = f'    public function {fn}(Request $request): void\n    {{\n' + guard
    if pattern in s and guard not in s.split(pattern)[1][:200]:
        s = s.replace(pattern, replacement, 1)
        count += 1

open(p, 'w', encoding='utf-8').write(s)
print(f'Added installed-guard to {count} methods')
