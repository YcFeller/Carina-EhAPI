<?php
namespace EHAPI;

class Logger {
    private string $logDir;

    public function __construct(string $logDir) {
        $this->logDir = $logDir;
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }

    /**
     * 写入日志
     * @param string $level (INFO, ERROR, WARN)
     * @param string $message
     * @param array $context
     */
    public function log(string $level, string $message, array $context = []): void {
        $date = date('Y-m-d');
        $time = date('H:i:s');
        $file = $this->logDir . "/eh_api_{$date}.log";
        
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$date} {$time}] [{$level}] {$message} {$contextStr}" . PHP_EOL;
        
        file_put_contents($file, $line, FILE_APPEND);
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
}
