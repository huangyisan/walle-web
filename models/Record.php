<?php

namespace app\models;

use Yii;
use app\components\Command;

/**
 * This is the model class for table "record".
 *
 * @property integer $id
 * @property integer $user_id
 * @property integer $task_id
 * @property integer $status
 * @property integer $action
 * @property integer $at
 * @property integer $duration
 * @property string $memo
 * @property string $command
 */
class Record extends \yii\db\ActiveRecord
{

    /**
     * 服务器权限检查
     */
    const ACTION_PERMSSION = 24;

    /**
     * 部署前置触发任务
     */
    const ACTION_PRE_DEPLOY = 40;

    /**
     * 本地代码更新
     */
    const ACTION_CLONE = 53;

    /**
     * 部署后置触发任务
     */
    const ACTION_POST_DEPLOY = 64;

    /**
     * 同步代码到服务器
     */
    const ACTION_SYNC  = 78;

    /**
     * 更新完所有目标机器时触发任务，最后一个得是100
     */
    const ACTION_UPDATE_REMOTE = 100;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'record';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'task_id', 'status'], 'required'],
            [['user_id', 'task_id', 'status', 'created_at', 'duration', 'action'], 'integer'],
            [['memo', 'command'], 'string'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'user_id' => 'user_id',
            'task_id' => 'task_id',
            'status' => 'Status',
            'created_at' => 'created_at',
            'action' => 'action',
            'duration' => 'duration',
            'memo' => 'memo',
        ];
    }

    /**
     * 进度条步骤序号（对应 deploy 页 step-N）
     *
     * @param int $action
     * @return int
     */
    public static function actionToStep($action) {
        $map = [
            self::ACTION_PERMSSION => 1,
            self::ACTION_PRE_DEPLOY => 2,
            self::ACTION_CLONE => 3,
            self::ACTION_POST_DEPLOY => 4,
            self::ACTION_SYNC => 5,
            self::ACTION_UPDATE_REMOTE => 6,
        ];
        return isset($map[$action]) ? $map[$action] : 0;
    }

    /**
     * 失败阶段文案
     *
     * @param int $action
     * @return string
     */
    public static function getActionLabel($action) {
        $labels = [
            self::ACTION_PERMSSION => Yii::t('walle', 'process_detect'),
            self::ACTION_PRE_DEPLOY => Yii::t('walle', 'process_pre-deploy'),
            self::ACTION_CLONE => Yii::t('walle', 'process_checkout'),
            self::ACTION_POST_DEPLOY => Yii::t('walle', 'process_post-deploy'),
            self::ACTION_SYNC => Yii::t('walle', 'process_rsync'),
            self::ACTION_UPDATE_REMOTE => Yii::t('walle', 'process_update'),
        ];
        return isset($labels[$action]) ? $labels[$action] : Yii::t('walle', 'deploy step unknown');
    }

    /**
     * 兼容旧数据：memo 曾用 var_export 存储
     *
     * @param string $memo
     * @return string
     */
    public static function normalizeMemo($memo) {
        if ($memo === '' || $memo === null) {
            return '';
        }
        if (strlen($memo) >= 2 && $memo[0] === "'" && substr($memo, -1) === "'") {
            return stripcslashes(substr($memo, 1, -1));
        }
        return $memo;
    }

    /**
     * 写入完整部署日志文件
     *
     * @param int    $taskId
     * @param string $command
     * @param string $output
     * @return string|null 日志路径
     */
    public static function writeDeployLogFile($taskId, $command, $output) {
        if (empty(Yii::$app->params['log.dir'])) {
            return null;
        }
        $logDir = Yii::$app->params['log.dir'];
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) {
            return null;
        }
        $baseDir = realpath($logDir) ?: $logDir;
        $path = rtrim($baseDir, '/') . '/deploy-task-' . $taskId . '-' . date('YmdHis') . '.log';
        $body = "=== Walle deploy log ===\n"
            . 'time: ' . date('Y-m-d H:i:s') . "\n"
            . "task_id: {$taskId}\n\n"
            . "=== command ===\n"
            . $command . "\n\n"
            . "=== output ===\n"
            . $output;
        if (@file_put_contents($path, $body) === false) {
            return null;
        }
        return $path;
    }

    /**
     * 保存记录
     *
     * @param Command $commandObj
     * @param $task_id
     * @param $action
     * @param $duration
     * @return mixed
     */
    public static function saveRecord(Command $commandObj, $task_id, $action, $duration) {
        $command = $commandObj->getExeCommand();
        $output = $commandObj->getExeLog();
        $logFile = static::writeDeployLogFile($task_id, $command, $output);

        $memo = $output;
        if ($logFile) {
            $memo .= "\n\n---\n" . Yii::t('walle', 'full log file', ['path' => $logFile]);
        }
        if (strlen($memo) > 65530) {
            $memo = substr($memo, -65530);
            $memo = Yii::t('walle', 'log truncated') . "\n\n" . $memo;
        }

        $record = new static();
        $record->attributes = [
            'user_id'    => \Yii::$app->user->id,
            'task_id'    => $task_id,
            'status'     => (int)$commandObj->getExeStatus(),
            'action'     => $action,
            'created_at' => time(),
            'command'    => $command,
            'memo'       => $memo,
            'duration'   => $duration,
        ];
        return $record->save();
    }
}
