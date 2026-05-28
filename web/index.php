<?php
// Include local configuration first so we can set the YII_* constants there
$localPath = __DIR__ . '/../config/local.php';
if (!is_file($localPath)) {
    $localPath = __DIR__ . '/../config/local.php.dist';
}
$local = require($localPath);

require(__DIR__ . '/../vendor/autoload.php');
require(__DIR__ . '/../vendor/yiisoft/yii2/Yii.php');

$config = require(__DIR__.'/../config/web.php');
$config = yii\helpers\ArrayHelper::merge($config, $local);

(new yii\web\Application($config))->run();
