<?php

namespace App\Services;

class LogService
{
    private string $logDir;

    public function __construct(?string $logDir = null)
    {
        $this->logDir = $logDir ?: dirname(__DIR__, 2) . '/storage/logs';
    }

    public function latestFiles(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $files = glob($this->logDir . '/app-*.log') ?: [];
        rsort($files, SORT_STRING);
        return array_values($files);
    }

    public function tail(int $lines = 200, ?string $contains = null): array
    {
        $files = $this->latestFiles();
        if (!$files) {
            return [];
        }

        $buffer = [];
        foreach ($files as $file) {
            $fileLines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $fileLines = array_reverse($fileLines);
            foreach ($fileLines as $line) {
                if ($contains !== null && $contains !== '' && stripos($line, $contains) === false) {
                    continue;
                }
                $buffer[] = $line;
                if (count($buffer) >= $lines) {
                    return array_reverse($buffer);
                }
            }
        }

        return array_reverse($buffer);
    }
}
