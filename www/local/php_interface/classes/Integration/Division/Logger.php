<?php

declare(strict_types=1);

namespace Project\Integration\Division;

use Throwable;

final class Logger
{
    private array $buffer = [];
    private string $logDir;
    private string $channel;

    public function __construct(string $channel = 'import')
    {
        $this->channel = $channel;
        $baseDir = dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/../../../../../'))) . '/logs/division_sync';
        $this->logDir = $baseDir . '/' . date('Y-m-d');
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $extra = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $this->buffer[] = sprintf('[%s] [%s] %s%s', date('Y-m-d H:i:s'), $level, $message, $extra);
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0750, true);
        }

        $file = sprintf('%s/%s_%s.log', $this->logDir, $this->channel, date('H'));
        file_put_contents($file, implode(PHP_EOL, $this->buffer) . PHP_EOL, FILE_APPEND | LOCK_EX);
        $this->buffer = [];
    }

    public static function rotateLogs(string $baseDir, int $days = 30): void
    {
        if (!is_dir($baseDir)) {
            return;
        }

        $threshold = strtotime("-{$days} days");
        foreach (scandir($baseDir) ?: [] as $dir) {
            $path = $baseDir . '/' . $dir;
            if ($dir !== '.' && $dir !== '..' && is_dir($path) && strtotime($dir) < $threshold) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            }
        }
    }

    public function __destruct()
    {
        try {
            $this->flush();
        } catch (Throwable) {
        }
    }
}
