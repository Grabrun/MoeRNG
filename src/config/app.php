<?php

return [
    'installed' => false,
    'base_url' => '',

    // 是否允许 SMTP 指向内网/回环地址（自托管内网邮件服务器场景，如局域网
    // Postfix、MailHog）。默认 false：mail_host 必须解析为公网地址。
    // 注意：开启前请确认服务器所在网络环境——该开关同时放行 127.0.0.1、
    // 10/8、172.16/12、192.168/16 等私网段（云元数据 169.254.169.254 无论
    // 开关状态一律拒绝）。
    // 'allow_private_smtp' => false,
];
