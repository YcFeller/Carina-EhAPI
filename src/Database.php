<?php
namespace EHAPI;

use PDO;
use PDOException;

class Database {
    private ?PDO $pdo = null;
    private bool $useFileFallback = false;
    private string $fileCacheDir;

    public function __construct(array $config) {
        $dbPath = $config['path'];
        $this->fileCacheDir = dirname($dbPath) . '/file_cache';

        // 尝试连接 SQLite
        try {
            if (!extension_loaded('pdo_sqlite')) {
                throw new \Exception("pdo_sqlite extension not loaded");
            }
            
            $dsn = 'sqlite:' . $dbPath;
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // 回退到文件模式
            $this->useFileFallback = true;
            if (!is_dir($this->fileCacheDir)) {
                mkdir($this->fileCacheDir, 0777, true);
            }
            // 仅在调试时输出，或者记录到日志（此处无 logger）
            // error_log("Database fallback to file mode: " . $e->getMessage());
        }
    }

    /**
     * 初始化数据库表结构
     */
    public function initSchema(): void {
        if ($this->useFileFallback) {
            return; // 文件模式无需 Schema
        }
        
        $sql = "
            CREATE TABLE IF NOT EXISTS system_cache (
                key TEXT PRIMARY KEY,
                value TEXT,
                expires_at TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_expires_at ON system_cache(expires_at);
        ";
        $this->pdo->exec($sql);
    }

    /**
     * 获取缓存
     * @param string $key 缓存键
     * @return mixed|null 缓存值(自动json_decode) 或 null
     */
    public function getCache(string $key) {
        if ($this->useFileFallback) {
            return $this->getFileCache($key);
        }

        try {
            $stmt = $this->pdo->prepare('SELECT value, expires_at FROM system_cache WHERE key = :k');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch();
            if (!$row) return null;
            
            if (strtotime($row['expires_at']) < time()) {
                $this->deleteCache($key);
                return null;
            }
            
            return json_decode($row['value'], true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 设置缓存
     * @param string $key 缓存键
     * @param mixed $value 缓存值(自动json_encode)
     * @param int $ttl 生存时间(秒)
     */
    public function setCache(string $key, $value, int $ttl = 3600): void {
        if ($this->useFileFallback) {
            $this->setFileCache($key, $value, $ttl);
            return;
        }

        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            
            $sql = 'INSERT OR REPLACE INTO system_cache (key, value, expires_at) VALUES (:k, :v, :e)';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':k' => $key, ':v' => $json, ':e' => $expiresAt]);
        } catch (\Throwable $e) {
            // Ignore write errors
        }
    }

    /**
     * 删除缓存
     */
    public function deleteCache(string $key): void {
        if ($this->useFileFallback) {
            $file = $this->getFileCachePath($key);
            if (file_exists($file)) {
                @unlink($file);
            }
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM system_cache WHERE key = :k');
        $stmt->execute([':k' => $key]);
    }
    
    // --- File Cache Fallback Implementation ---

    private function getFileCachePath(string $key): string {
        return $this->fileCacheDir . '/' . md5($key) . '.json';
    }

    private function getFileCache(string $key) {
        $file = $this->getFileCachePath($key);
        if (!file_exists($file)) return null;

        $content = @file_get_contents($file);
        if (!$content) return null;

        $data = json_decode($content, true);
        if (!$data || !isset($data['expires_at']) || !isset($data['value'])) return null;

        if ($data['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    private function setFileCache(string $key, $value, int $ttl): void {
        $data = [
            'expires_at' => time() + $ttl,
            'value' => $value
        ];
        file_put_contents($this->getFileCachePath($key), json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    
    public function cleanupExpired(): int {
        if ($this->useFileFallback) {
            // 简单遍历清理
            $files = glob($this->fileCacheDir . '/*.json');
            $count = 0;
            $now = time();
            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if ($content) {
                    $data = json_decode($content, true);
                    if ($data && isset($data['expires_at']) && $data['expires_at'] < $now) {
                        @unlink($file);
                        $count++;
                    }
                }
            }
            return $count;
        }
        
        $stmt = $this->pdo->prepare("DELETE FROM system_cache WHERE expires_at < datetime('now', 'localtime')");
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    public function isFallbackMode(): bool {
        return $this->useFileFallback;
    }
}
