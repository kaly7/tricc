<?php
/**
 * Reset helpers for Payslip app.
 * - Deletes processing data from DB (keeps divisions, users, employees).
 * - Deletes files under storage/uploads, storage/output, storage/tmp.
 */
class ResetTool {

    public static function resetDatabase(\PDO $pdo): array {
        $pdo->beginTransaction();
        try {
            // Disable FK checks (in case uploads/page_jobs have FK constraints)
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

            $tables = [
                'page_jobs',
                'uploads',
                'audit_log',
            ];

            $done = [];
            foreach ($tables as $t) {
                // Use TRUNCATE for speed + reset AUTO_INCREMENT
                $pdo->exec("TRUNCATE TABLE `$t`");
                $done[] = $t;
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $pdo->commit();
            return ['ok'=>true, 'tables'=>$done];
        } catch (\Throwable $e) {
            try { $pdo->rollBack(); } catch (\Throwable $ignored) {}
            try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (\Throwable $ignored) {}
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }

    public static function deleteTreeContents(string $dir, array $keepNames = ['.gitkeep']): array {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) return ['ok'=>true, 'deleted'=>0, 'note'=>'dir missing'];

        $deleted = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $path => $info) {
            $base = $info->getBasename();
            if (in_array($base, $keepNames, true)) continue;

            if ($info->isFile() || $info->isLink()) {
                @unlink($path);
                $deleted++;
            } elseif ($info->isDir()) {
                // Only remove dir if empty after deletions
                @rmdir($path);
            }
        }

        return ['ok'=>true, 'deleted'=>$deleted];
    }

    public static function resetFiles(array $dirs): array {
        $results = [];
        foreach ($dirs as $k => $d) {
            $results[$k] = self::deleteTreeContents($d);
        }
        return ['ok'=>true, 'results'=>$results];
    }
}
