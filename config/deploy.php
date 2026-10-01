<?php

return [
    /*
     * Master switch tombol/route Kirim ke Hosting. Default true HANYA di lokal
     * agar di hosting halaman deploy 404 (tak akan pernah kirim dari server).
     * Di test, override via config(['deploy.allow_remote' => true]).
     */
    'allow_remote' => env('DEPLOY_ALLOW', env('APP_ENV', 'production') === 'local'),

    // SSH hosting (ambil dari hPanel -> SSH Access; tanpa password di sini — pakai key).
    'ssh_host' => env('DEPLOY_SSH_HOST', '46.202.138.28'),
    'ssh_port' => (int) env('DEPLOY_SSH_PORT', 65002),
    'ssh_user' => env('DEPLOY_SSH_USER', 'u549935065'),
    'ssh_key' => env('DEPLOY_SSH_KEY'), // null = pakai ssh-agent / ~/.ssh/config

    // Path proyek di server. null = auto-deteksi dari DEPLOY_URL.
    'path' => env('DEPLOY_PATH'),
    'docroot' => env('DEPLOY_DOCROOT'), // null = <domain>/public_html
    'php' => env('DEPLOY_PHP', '/opt/alt/php84/usr/bin/php'),
    'url' => env('DEPLOY_URL', 'https://reconpos.misnu.sch.id'),

    'log' => storage_path('logs/kirim-hosting.log'),
    'running' => storage_path('logs/kirim-hosting.running'),

    // Timeout detik per tahap remote (composer & migrate bisa lama di shared hosting).
    'timeout' => (int) env('DEPLOY_TIMEOUT', 1800),
];
