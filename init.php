<?php
// 初始化脚本
// 用于在没有 composer 自动加载的情况下，手动初始化环境

echo "Initializing Carina (E-Hentai Middleware)...\n";

// 1. 创建目录
$dirs = [
    __DIR__ . '/data',
    __DIR__ . '/logs'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Creating directory: $dir\n";
        if (!mkdir($dir, 0777, true)) {
            die("Failed to create directory $dir\n");
        }
    } else {
        echo "Directory exists: $dir\n";
    }
}

// 2. 初始化数据库
require_once __DIR__ . '/src/Database.php';
$config = require __DIR__ . '/config.php';

echo "Initializing Database at {$config['db']['path']}...\n";

try {
    $db = new \EHAPI\Database($config['db']);
    $db->initSchema();
    echo "Database schema initialized successfully.\n";
} catch (\Exception $e) {
    die("Database initialization failed: " . $e->getMessage() . "\n");
}

echo "Initialization complete!\n";
echo "Please ensure you have run 'composer install' to install dependencies.\n";
