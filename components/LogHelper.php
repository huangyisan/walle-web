<?php

namespace app\components;

use Yii;

class LogHelper {

    /** @var string|null */
    private static $resolvedDir;

    /**
     * @return string 日志目录（末尾带 /），不可写时回退到 runtime/logs
     */
    public static function dir() {
        if (self::$resolvedDir !== null) {
            return self::$resolvedDir;
        }

        $candidates = [];
        if (Yii::$app && !empty(Yii::$app->params['log.dir'])) {
            $candidates[] = Yii::$app->params['log.dir'];
        }
        $candidates[] = '/var/log/walle/';
        $candidates[] = Yii::getAlias('@runtime/logs');

        foreach ($candidates as $dir) {
            $dir = rtrim($dir, '/') . '/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                self::$resolvedDir = (realpath($dir) ?: $dir);
                if (substr(self::$resolvedDir, -1) !== '/') {
                    self::$resolvedDir .= '/';
                }
                return self::$resolvedDir;
            }
        }

        self::$resolvedDir = Yii::getAlias('@runtime/logs/');
        return self::$resolvedDir;
    }

    /**
     * @param string $channel 如 request / error / app
     * @return string
     */
    public static function filePath($channel) {
        return self::dir() . $channel . '-' . date('Ymd') . '.log';
    }

    /**
     * @param string $channel
     * @param string $message
     */
    public static function write($channel, $message) {
        try {
            $line = date('Y-m-d H:i:s') . ' -- ' . $message . PHP_EOL;
            @file_put_contents(self::filePath($channel), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // 日志写入失败不能影响业务请求
        }
    }

    /**
     * 部署失败判定诊断（同时写 deploy-decision-*.log 与 walle-*.log）
     *
     * @param string $stage
     * @param array  $payload
     */
    public static function deployDecision($stage, array $payload = []) {
        Command::deployDecision($stage, $payload);
    }

    /**
     * @param array $params
     * @return array
     */
    public static function sanitizeParams(array $params) {
        foreach ($params as $key => $value) {
            $lower = strtolower((string)$key);
            if (strpos($lower, 'password') !== false
                || strpos($lower, 'passwd') !== false
                || strpos($lower, 'token') !== false
                || strpos($lower, 'secret') !== false
                || strpos($lower, 'cookie') !== false) {
                $params[$key] = '***';
            }
        }
        return $params;
    }

}
