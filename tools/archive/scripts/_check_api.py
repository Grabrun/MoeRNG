#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# 核对三个驱动的 API 调用 vs 官方原版 SDK 方法签名
import re, os

DESK = r'C:\Users\Administrator\OneDrive\Desktop'
PROJ = r'D:\projects\2026-08-05-10-13-25'

def grep_sig(files, pattern, max_hits=6):
    """在文件中找方法定义签名"""
    out = []
    for f in files:
        if not os.path.isfile(f):
            out.append('MISSING FILE ' + f)
            continue
        src = open(f, encoding='utf-8', errors='ignore').read()
        for m in re.finditer(pattern, src):
            out.append('%s: %s' % (os.path.basename(f), m.group(0)[:140]))
            if len(out) >= max_hits:
                return out
    return out

print('========== 1. COS SDK (官方) 关键方法签名 ==========')
cos_files = [os.path.join(DESK, 'tencent-php', 'src', 'Client.php')]
for pat, label in [
    (r'public function upload\([^)]*\)', 'upload'),
    (r'public function doesBucketExist\([^)]*\)', 'doesBucketExist'),
    (r'public function getPresignedUrl\([^)]*\)', 'getPresignedUrl'),
    (r'public function deleteObject\([^)]*\)', 'deleteObject'),
    (r'public function headObject\([^)]*\)', 'headObject'),
]:
    r = grep_sig(cos_files, pat)
    print('  %-18s %s' % (label, r[0] if r else 'NOT FOUND'))

print()
print('========== 2. OSS SDK (官方) 关键方法/类 ==========')
oss_src = os.path.join(DESK, 'alibabacloud-oss-php-sdk-v2-0.4.0', 'src')
oss_files = [os.path.join(oss_src, 'Client.php'), os.path.join(oss_src, 'ClientExtensionTrait.php'), os.path.join(oss_src, 'Config.php')]
for pat, label in [
    (r'public static function loadDefault\([^)]*\)', 'Config::loadDefault'),
    (r'public function setCredentialsProvider\([^)]*\)', 'setCredentialsProvider'),
    (r'public function setRegion\([^)]*\)', 'setRegion'),
    (r'public function putObjectFromFile\([^)]*\)', 'putObjectFromFile'),
    (r'public function presign\([^)]*\)', 'presign'),
    (r'public function listObjects\([^)]*\)', 'listObjects'),
    (r'public function deleteObject\([^)]*\)', 'deleteObject'),
    (r'public function headObject\([^)]*\)', 'headObject'),
]:
    r = grep_sig(oss_files, pat)
    print('  %-22s %s' % (label, r[0] if r else 'NOT FOUND'))
# 各 Request 模型构造
req_files = {
    'PutObjectRequest': os.path.join(oss_src, 'Models', 'PutObjectRequest.php'),
    'DeleteObjectRequest': os.path.join(oss_src, 'Models', 'DeleteObjectRequest.php'),
    'HeadObjectRequest': os.path.join(oss_src, 'Models', 'HeadObjectRequest.php'),
    'GetObjectRequest': os.path.join(oss_src, 'Models', 'GetObjectRequest.php'),
    'ListObjectsRequest': os.path.join(oss_src, 'Models', 'ListObjectsRequest.php'),
}
for name, f in req_files.items():
    r = grep_sig([f], r'public function __construct\([^)]*\)')
    print('  %-20s %s' % (name, r[0] if r else 'NOT FOUND'))
# PutObjectRequest 的 contentType 属性
f = req_files['PutObjectRequest']
src = open(f, encoding='utf-8').read()
print('  contentType 属性:', 'contentType' in src and 'OK' or 'MISSING')

print()
print('========== 3. AWS SDK (官方) 关键方法 ==========')
aws_s3 = os.path.join(DESK, 'aws', 'Aws', 'S3', 'S3Client.php')
for pat, label in [
    (r'function createPresignedRequest\([^)]*\)', 'createPresignedRequest'),
    (r'function putObject\([^)]*\)', 'putObject'),
    (r'function deleteObject\([^)]*\)', 'deleteObject'),
    (r'function headObject\([^)]*\)', 'headObject'),
    (r'function headBucket\([^)]*\)', 'headBucket'),
]:
    r = grep_sig([aws_s3], pat)
    print('  %-22s %s' % (label, r[0] if r else 'NOT FOUND'))
# getCommand 在 AwsClient
aws_client = os.path.join(DESK, 'aws', 'Aws', 'AwsClient.php')
r = grep_sig([aws_client], r'function getCommand\([^)]*\)')
print('  %-22s %s' % ('getCommand', r[0] if r else 'NOT FOUND'))

print()
print('========== 4. 依赖版本（COS vendor） ==========')
import json
lock = json.load(open(os.path.join(PROJ, 'sdk', 'cos', 'composer.lock'), encoding='utf-8'))
for p in lock['packages']:
    if p['name'] in ('guzzlehttp/guzzle', 'guzzlehttp/psr7', 'guzzlehttp/promises', 'psr/http-message', 'psr/http-client', 'guzzlehttp/guzzle-services'):
        print('  %-30s %s' % (p['name'], p['version']))
