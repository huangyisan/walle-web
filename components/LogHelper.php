<?php

namespace app\components;

use Yii;

class LogHelper {

    /**
     * @return string 日志目录（末尾带 /）
     */
    public static function dir() {
        $dir = '/var/log/walle/';
        if (Yii::$app && !empty(Yii::$app->params['log.dir'])) {
            $dir = Yii::$app->params['log.dir'];
        }
        $dir = rtrim($dir, '/') . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return (realpath($dir) ?: $dir);
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
        $line = date('Y-m-d H:i:s') . ' -- ' . $message . PHP_EOL;
        @file_put_contents(self::filePath($channel), $line, FILE_APPEND | LOCK_EX);
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
