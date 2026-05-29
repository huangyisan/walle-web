<?php

namespace app\components;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Event;
use yii\web\Application;

class RequestLogBootstrap implements BootstrapInterface {

    /** @var float */
    private $startTime = 0;

    public function bootstrap($app) {
        if (!$app instanceof Application) {
            return;
        }

        Event::on(Application::class, Application::EVENT_BEFORE_REQUEST, function () {
            $this->startTime = microtime(true);
        });

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, function () {
            $this->logRequest();
        });
    }

    private function logRequest() {
        if (empty(Yii::$app->params['log.dir'])) {
            return;
        }

        $request = Yii::$app->request;
        $response = Yii::$app->response;
        $duration = $this->startTime > 0
            ? (int)round((microtime(true) - $this->startTime) * 1000)
            : 0;
        $userId = !Yii::$app->user->isGuest ? Yii::$app->user->id : '-';

        $query = LogHelper::sanitizeParams($request->get());
        $body = LogHelper::sanitizeParams($request->post());

        $line = sprintf(
            '%s %s | user=%s | ip=%s | status=%s | %dms | query=%s | post=%s',
            $request->method,
            $request->url,
            $userId,
            $request->userIP,
            $response->statusCode,
            $duration,
            json_encode($query, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        LogHelper::write('request', $line);
    }

}
