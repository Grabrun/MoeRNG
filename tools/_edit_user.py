p = 'src/views/admin/users.php'
s = open(p, encoding='utf-8').read()

old = '''                            <button class="btn btn-outline btn-sm" onclick="editUser(<?= $user->id ?>, '<?= h($user->username) ?>', '<?= h($user->email) ?>', '<?= h($user->role) ?>')">编辑</button>'''
new = '''                            <button class="btn btn-outline btn-sm" data-edit-user='<?= h(json_encode([
                                'id' => (int)$user->id,
                                'username' => (string)$user->username,
                                'email' => (string)$user->email,
                                'role' => (string)$user->role,
                            ], JSON_UNESCAPED_UNICODE)) ?>'>编辑</button>'''
if old in s:
    s = s.replace(old, new, 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('OK users edit btn')
else:
    print('MISS')
    import re
    m = re.search(r'<button class="btn btn-outline btn-sm" onclick="editUser[^>]+>编辑</button>', s)
    print('  actual:', m.group(0) if m else 'NONE')
