<?php

namespace app\models;

use Yii;
use app\components\Command;
use app\components\LogHelper;

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
    /**
     * 从命令输出中解析最后一次 shell 退出码
     *
     * @param string $output
     * @return int|null
     */
    public static function extractShellExitCode($output) {
        if ($output === '' || $output === null) {
            return null;
        }
        if (!preg_match_all('/\[exit code:\s*(-?\d+)\]/', $output, $matches)) {
            return null;
        }

        return (int)end($matches[1]);
    }

    public static function saveRecord(Command $commandObj, $task_id, $action, $duration) {
        $command = $commandObj->getExeCommand();
        $output = $commandObj->getExeLog();
        $logFile = static::writeDeployLogFile($task_id, $command, $output);
        $status = (int)$commandObj->getExeStatus();
        $shellExit = static::extractShellExitCode($output);
        if ($shellExit !== null) {
            $expectedStatus = ($shellExit === 0) ? 1 : 0;
            if ($status !== $expectedStatus) {
                LogHelper::deployDecision('save_record_status_mismatch', [
                    'reason' => 'exe_status does not match shell exit code in log',
                    'task_id' => $task_id,
                    'action' => $action,
                    'exe_status' => $status,
                    'shell_exit' => $shellExit,
                    'corrected_status' => $expectedStatus,
                    'command' => $command,
                    'log_tail' => mb_substr($output, -4000, null, 'UTF-8'),
                ]);
                $status = $expectedStatus;
            }
        }

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
            'status'     => $status,
            'action'     => $action,
            'created_at' => time(),
            'command'    => $command,
            'memo'       => $memo,
            'duration'   => $duration,
        ];
        if ($status === 0) {
            LogHelper::deployDecision('save_record_failed', [
                'reason' => 'saving record with status=0',
                'task_id' => $task_id,
                'action' => $action,
                'shell_exit' => $shellExit,
                'exe_status' => (int)$commandObj->getExeStatus(),
                'command' => $command,
                'log_tail' => mb_substr($output, -4000, null, 'UTF-8'),
            ]);
        }
        return $record->save();
    }
}
