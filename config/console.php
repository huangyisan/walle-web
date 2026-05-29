<?php
// Merged web + local configuration is available in $web
$params = [
    'version'   => 'v1.2.0',
    'buildTime' => '2016-05-16',
];
return [
    'id'                  => 'console',
    'basePath'            => dirname(__DIR__),
    'bootstrap'           => ['log'],
    'controllerNamespace' => 'app\console',
    'extensions'          => $web['extensions'],
    'components'          => [
        'db'      => $web['components']['db'],
        'log'     => [
            'targets' => [
                [
                    'class'   => \app\components\WalleFileTarget::class,
                    'channel' => 'error',
                    'levels'  => ['error'],
                ],
                [
                    'class'   => \app\components\WalleFileTarget::class,
                    'channel' => 'warning',
                    'levels'  => ['warning'],
                ],
            ],
        ],
    ],
    'params'              => array_merge($web['params'], $params),
];
