<?php

namespace app\components;

use Yii;
use yii\web\HttpException;

class WalleErrorHandler extends \yii\web\ErrorHandler {

    public function logException($exception) {
        parent::logException($exception);

        if ($exception instanceof HttpException && $exception->statusCode === 404) {
            return;
        }

        $request = Yii::$app->has('request') ? Yii::$app->request : null;
        $method = $request ? $request->method : '-';
        $url = $request ? $request->url : '-';
        $userId = (Yii::$app->has('user') && !Yii::$app->user->isGuest)
            ? Yii::$app->user->id
            : '-';

        $message = sprintf(
            "%s (#%s): %s\nFile: %s:%d\nUser: %s\nRequest: %s %s\nTrace:\n%s",
            get_class($exception),
            $exception->getCode(),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $userId,
            $method,
            $url,
            $exception->getTraceAsString()
        );

        try {
            LogHelper::write('error', $message);
        } catch (\Throwable $e) {
            // ignore
        }
    }

}
