<?php
/**
 * Carina: Lightweight E-Hentai API Proxy & Middleware
 * 
 * @author    YcFeller <https://github.com/YcFeller>
 * @copyright Copyright (c) 2024 YcFeller
 * @license   MIT
 * @link      https://github.com/OrPudding/vela-py-eh-api-server/ (Sibling project: Vela)
 * @version   1.0.0
 * 
 * Carina provides high-performance gallery parsing and intelligent image processing (WebP->JPEG, Sprite Cropping).
 */

namespace EHAPI;

// 1. 尝试加载 Composer 依赖
// 优先使用当前目录下的 vendor (如果已执行 composer install)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} 
// 其次尝试使用上级目录的 vendor (开发环境便利)
elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'Dependencies not found. Please run "composer install".']));
}

// 2. 加载配置和核心类
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/EHService.php';
require_once __DIR__ . '/src/Logger.php';

// 3. 初始化组件
$logger = new Logger(__DIR__ . '/logs');
$startTime = microtime(true);

// 防止大画廊抓取超时
set_time_limit(600);

try {
    // 确保数据目录存在
    $dbDir = dirname($config['db']['path']);
    if (!is_dir($dbDir)) {
        if (!mkdir($dbDir, 0777, true)) {
            throw new \Exception("Failed to create data directory: $dbDir");
        }
    }
    
    // 初始化数据库
    $db = new Database($config['db']);
    
    // 如果数据库文件是新建的（表不存在），自动初始化结构
    // 简单判断：尝试查询一次
    try {
        $db->getCache('test_init');
    } catch (\Exception $e) {
        $db->initSchema();
    }
    
} catch (\Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'System Init Failed: ' . $e->getMessage()]));
}

// 4. CORS 和 基础 Header
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-EH-Cookie, Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 5. 路由逻辑
try {
    // 尝试获取路径信息
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME']; // /eh_api_standalone/index.php
    $basePath = dirname($scriptName); // /eh_api_standalone
    
    // 移除 base path
    if (strpos($uri, $scriptName) === 0) {
        $path = substr($uri, strlen($scriptName));
    } elseif (strpos($uri, $basePath) === 0) {
        $path = substr($uri, strlen($basePath));
    } else {
        $path = $uri;
    }
    
    // 归一化路径
    $path = '/' . ltrim($path, '/');

    // 获取 Cookie (Header 优先)
    $cookie = $_SERVER['HTTP_X_EH_COOKIE'] ?? '';
    
    $service = new EHService($db, $cookie);

    // 路由分发
    $response = null;
    $statusCode = 200;

    if ($path === '/' || $path === '/search' || $path === '/index.php') {
        // 首页 / 搜索
        $q = $_GET['q'] ?? '';
        $next = $_GET['next'] ?? null;
        $refresh = isset($_GET['refresh']) && $_GET['refresh'] == 1;
        $result = $service->search($q, $next, $refresh);
        $response = $result;
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } elseif ($path === '/gallery') {
        // 画廊详情
        $gid = $_GET['gid'] ?? 0;
        $token = $_GET['token'] ?? '';
        $refresh = isset($_GET['refresh']) && $_GET['refresh'] == 1;
        
        if (!$gid || !$token) {
            throw new \Exception('Missing gid or token parameter');
        }
        
        $result = $service->getGallery((int)$gid, $token, true, $refresh);
        $response = $result;
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } elseif ($path === '/image/proxy') {
        // 图片代理
        $url = $_GET['url'] ?? '';
        if (!$url) {
            throw new \Exception('Missing url parameter');
        }
        
        // 收集处理参数
        $options = [];
        $params = ['w', 'h', 'q', 'sprite_w', 'sprite_h', 'sprite_x', 'sprite_y'];
        foreach ($params as $p) {
            if (isset($_GET[$p])) {
                $options[$p] = $_GET[$p];
            }
        }

        $data = $service->getImageProxyData($url, $options);
        
        if ($data['success']) {
            // 清除之前的 Content-Type
            header_remove('Content-Type');
            header('Content-Type: ' . $data['content_type']);
            // 设置缓存头
            header('Cache-Control: public, max-age=31536000');
            
            // 响应日志数据 (避免 stream 被读取)
            $responseSize = 0;
            
            if ($data['body'] instanceof \Psr\Http\Message\StreamInterface) {
                // 流式输出
                $stream = $data['body'];
                $responseSize = $stream->getSize(); // 可能为 null
                
                // 确保从头开始
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                
                while (!$stream->eof()) {
                    echo $stream->read(8192); // 8KB chunks
                    flush(); // 强制刷新输出缓冲区
                }
            } else {
                // 字符串输出
                echo $data['body'];
                $responseSize = strlen($data['body']);
            }
            
            // 代理成功后退出
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $logger->info("Request processed", [
                'path' => $path,
                'method' => $_SERVER['REQUEST_METHOD'],
                'query' => $_GET,
                'status' => 200,
                'duration_ms' => $duration,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'response_summary' => ['success' => true, 'proxy_url' => $url, 'size' => $responseSize]
            ]);
            exit;
        } else {
            http_response_code(502);
            $statusCode = 502;
            $response = $data;
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        http_response_code(404);
        $statusCode = 404;
        $response = [
            'success' => false, 
            'message' => 'Endpoint not found', 
            'path' => $path,
            'debug_uri' => $uri
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    // 记录正常响应日志
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $logger->info("Request processed", [
        'path' => $path,
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $_GET,
        'status' => $statusCode,
        'duration_ms' => $duration,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'response_summary' => $response // 可能需要截断过长的响应
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    $errorResp = [
        'success' => false, 
        'message' => $e->getMessage()
    ];
    echo json_encode($errorResp, JSON_UNESCAPED_UNICODE);
    
    // 记录错误日志
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $logger->error("Request failed", [
        'path' => $path ?? 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'query' => $_GET,
        'status' => 500,
        'duration_ms' => $duration,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
