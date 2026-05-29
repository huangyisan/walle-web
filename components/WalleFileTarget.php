<?php

namespace app\components;

class WalleFileTarget extends \yii\log\FileTarget {

    /** @var string 日志文件名前缀，如 error / warning / app */
    public $channel = 'app';

    public function init() {
        if ($this->logFile === null) {
            $this->logFile = LogHelper::filePath($this->channel);
        }
        if ($this->logVars === null) {
            $this->logVars = ['_GET', '_POST', '_SERVER.REQUEST_URI', '_SERVER.REQUEST_METHOD', '_SERVER.REMOTE_ADDR'];
        }
        parent::init();
    }

}
