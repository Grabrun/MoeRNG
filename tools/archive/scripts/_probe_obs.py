#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""查看 OBS SDK putObject operation 参数与 signature 默认值。"""
import re

base = r'C:\Users\Administrator\OneDrive\Desktop\资料\huaweicloud-sdk-php-obs-3.24.9\Obs'

# 1) signature 属性默认值
src = open(base + r'\ObsClient.php', encoding='utf-8').read()
for line in src.splitlines():
    if 'signature' in line and ('private' in line or 'protected' in line or 'public' in line):
        print('prop:', line.strip())
# 找构造函数里 signature 相关（没传时的默认）
for line in src.splitlines():
    if 'signature' in line and '=' in line and 'config' not in line:
        print('assign:', line.strip())

# 2) putObject operation 完整定义（V2 + OBS 两个资源模型）
for res in ['V2RequestResource.php', 'OBSRequestResource.php']:
    s = open(base + r'\Internal\Resource' + '\\' + res, encoding='utf-8').read()
    idx = s.find("'putObject'")
    if idx >= 0:
        print('\n=== %s putObject ===' % res)
        print(s[idx:idx + 700])
