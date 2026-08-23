#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import os, zipfile, datetime

# 源码根 = 本脚本(tools/)的上级目录下的 src/。zip 包内容 = src/ 下所有文件，
# 解压后即为站点根（部署结构不变：app/views/public/config/sdk 都在 zip 根）。
BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ROOT = os.path.join(BASE, 'src')
DST  = os.path.join(BASE, 'releases')
os.makedirs(DST, exist_ok=True)

# AWS SDK 白名单：基础设施 + S3/S3Control + data/s3 + data 根级 + JmesPath + 根级 php
# （Configuration/Identity 为 S3Client 构造必需；Kms/SSO/SSOOIDC/Signin 为懒加载可选路径，补齐消除隐患）
AWS_INFRA = {'Api','Arn','Auth','ClientSideMonitoring','Configuration','Credentials','Crypto',
             'DefaultsMode','Endpoint','EndpointDiscovery','EndpointV2','Exception','Handler',
             'Identity','Kms','Multipart','Retry','Signature','SSO','SSOOIDC','Signin','Sts','Token',
             'S3','S3Control'}

def aws_allowed(rel):
    parts = rel.split('/')
    if parts[:2] != ['sdk','aws']:
        return True
    if len(parts) <= 3:
        return True                      # sdk/aws{,/autoload.php,LICENSE}
    if parts[2] == 'JmesPath':
        return True
    if parts[2] != 'Aws':
        return False
    # Aws/ 根级 php 文件（functions.php、Sdk.php、AwsClient.php ...）
    if len(parts) == 4 and parts[3].endswith('.php'):
        return True
    if parts[3] == 'data':
        if len(parts) == 5 and parts[4].endswith('.php'):
            return True                  # data/*.php 根级（partitions 等）
        return len(parts) >= 5 and parts[4] == 's3'  # 只留 s3 服务定义
    return parts[3] in AWS_INFRA         # 目录白名单

SKIP = {'config','releases','.dsh','node_modules','uploads','.git','.vscode','.idea','.github','tests','bin','sample','_stale'}
SKIP_FILE = {'debug_session.php','_package.py','_check_api.py','_check_zip_aws.py','_check_aws_refs.js','_check_zip_aws.js','_fix_memory.py','_check_frontend.py','_note_version.py','_log_today.py','_fix_memory_line.py','_cleanup_root2.py','_log_cleanup.py','_cleanup_root.py'}
stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
zip_path = os.path.join(DST, 'MoeRNG-v1.2.1-{}.zip'.format(stamp))
n = 0
skipped = 0
with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for dp, dns, fns in os.walk(ROOT):
        dns[:] = [d for d in dns if d not in SKIP]
        for fn in fns:
            if fn in SKIP_FILE:
                continue
            full = os.path.join(dp, fn)
            try:
                rel = os.path.relpath(full, ROOT).replace(os.sep, '/')
            except ValueError:
                continue
            if rel.startswith('public/uploads/'):
                continue
            if rel == 'nul':
                continue
            if rel.startswith('.') and rel != '.htaccess' and rel != '.well-known/security.txt':
                continue
            if not aws_allowed(rel):
                skipped += 1
                continue
            z.write(full, rel)
            n += 1

print('PACKAGED:', zip_path)
print('Files:', n, '| AWS-pruned:', skipped, '| Size: %.2f MB' % (os.path.getsize(zip_path)/1048576))

with zipfile.ZipFile(zip_path) as z:
    names = z.namelist()
core = ['app/Storage/AwsSdkDriver.php','app/Storage/S3Driver.php','sdk/aws/autoload.php',
        'sdk/aws/Aws/S3/S3Client.php','sdk/aws/Aws/functions.php','sdk/aws/JmesPath/JmesPath.php',
        'sdk/aws/Aws/data/s3/2006-03-01/api-2.json.php','sdk/aws/Aws/data/partitions.json.php']
for c in core:
    print(('OK  ' if c in names else 'MISS'), c)
leak = [x for x in names if x.startswith('sdk/aws/Aws/') and any(s in x for s in ['/Ec2/','/Lambda/','/Sns/','/Sqs/','/DynamoDb/','/Iam/','/Rds/','/Glacier/','/Sns/'])]
print('unrelated services leaked:', leak if leak else 'none (clean)')
print('test:', zipfile.ZipFile(zip_path).testzip() or 'intact')
