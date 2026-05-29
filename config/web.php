<?php
$config = [
    'id' => 'basic',
    'timeZone'   => 'Asia/Shanghai',
    'basePath'   => dirname(__DIR__),
    'extensions' => require(__DIR__ . '/../vendor/yiisoft/extensions.php'),
    'controllerNamespace' => 'app\controllers',
    'defaultRoute'        => 'task/index',
    'components' => [
        'db' => [
            'class'     => 'yii\db\Connection',
            'charset'   => 'utf8',
        ],
        'session' => [
            'class'        => 'yii\web\DbSession',
            'db'           => 'db',
            'sessionTable' => 'session',
        ],
        'errorHandler' => [
            'class'         => \app\components\WalleErrorHandler::class,
            'errorAction'   => 'site/error',
        ],
        'mail' => [
            'class'            => \yii\symfonymailer\Mailer::class,
            'useFileTransport' => true,
            'messageConfig'    => [
                'charset' => 'UTF-8',
            ],
        ],
        'log'  => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets'    => [
                [
                    'class'   => \app\components\WalleFileTarget::class,
                    'channel' => 'error',
                    'levels'  => ['error'],
                    'except'  => [
                        'yii\web\HttpException:404',
                    ],
                ],
                [
                    'class'   => \app\components\WalleFileTarget::class,
                    'channel' => 'warning',
                    'levels'  => ['warning'],
                ],
            ],
        ],
        'user' => [
            'identityClass'   => 'app\models\User',
            'enableAutoLogin' => true,
        ],
        'i18n' => [
            'translations' => [
                '*' => [
                    'class'    => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@app/messages',
                ],
            ],
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName'  => false,
            'rules'           => [
                '<controller:\w+>/<action:\w+>' => '<controller>/<action>',
            ],
        ],
    ],
    'bootstrap'  => [
        'app\components\EventBootstrap',
        'app\components\RequestLogBootstrap',
        'log',
    ],
    'params'     => require(__DIR__ . '/params.php'),
];

if (YII_ENV_DEV) {
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class'      => 'yii\debug\Module',
        'allowedIPs' => ['*'],
    ];
    $config['modules']['gii'] = [
        'class'      => 'yii\gii\Module',
    ];
}

return $config;
