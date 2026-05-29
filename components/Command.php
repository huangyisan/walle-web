<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 五  7/31 22:42:32 2015
 *
 * @File Name: command/Command.php
 * @Description:
 * *****************************************************************/
namespace app\components;

class Command {

    protected static $LOGDIR = '';
    /**
     * Handler to the current Log File.
     * @var mixed
     */
    protected static $logFile = null;

    /**
     * Config
     * @var \walle\config\Config
     */
    protected $config;

    /**
     * 命令运行返回值：0失败，1成功
     * @var int
     */
    protected $status = 1;

    /** @var bool post_deploy 等长任务需 proc_open 分通道捕获输出 */
    protected $captureOutputViaProcOpen = false;

    protected $command = '';

    protected $log = null;

    /**
     * 加载配置
     *
     * @param $config
     * @return $this
     * @throws \Exception
     */
    public function __construct($config) {
        if ($config) {
            $this->config = $config;
        } else {
            throw new \Exception(\yii::t('walle', 'unknown config'));
        }
    }

    /**
     * post_deploy / pre_deploy 等长任务开启 proc_open 分通道捕获
     */
    public function enableProcOpenCapture($enable = true) {
        $this->captureOutputViaProcOpen = (bool)$enable;
        return $this;
    }

    /**
     * 执行本地宿主机命令
     *
     * @param $command
     * @return bool|int true 成功，false 失败
     */
    final public function runLocalCommand($command) {
        $command = trim($command);
        $this->log('---------------------------------');
        $this->log('---- Executing: $ ' . $command);

        if (!$this->captureOutputViaProcOpen) {
            return $this->runLocalCommandViaExec($command);
        }

        $status = 1;
        $stdout = '';
        $stderr = '';

        // post_deploy 等长任务：proc_open 分别捕获 stdout/stderr
        if (function_exists('proc_open')) {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open($command, $descriptorSpec, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                while (true) {
                    $read = [];
                    if (!feof($pipes[1])) {
                        $read[] = $pipes[1];
                    }
                    if (!feof($pipes[2])) {
                        $read[] = $pipes[2];
                    }
                    if (empty($read)) {
                        break;
                    }
                    $write = null;
                    $except = null;
                    $changed = @stream_select($read, $write, $except, 0, 200000);
                    if ($changed === false) {
                        break;
                    }
                    foreach ($read as $stream) {
                        $chunk = stream_get_contents($stream);
                        if ($chunk === false || $chunk === '') {
                            continue;
                        }
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);
            } else {
                return $this->runLocalCommandViaExec($command);
            }
        } else {
            return $this->runLocalCommandViaExec($command);
        }

        return $this->finalizeCommandResult($command, $status, $stdout, $stderr);
    }

    /**
     * ssh/scp/git/ansible 等短命令：exec 获取退出码最可靠（与 8314dd1 前行为一致）
     *
     * @param string $command
     * @return bool
     */
    protected function runLocalCommandViaExec($command) {
        $status = 1;
        $output = [];
        exec($command . ' 2>&1', $output, $status);
        $log = implode(PHP_EOL, $output);

        return $this->finalizeCommandResult($command, $status, $log, '');
    }

    /**
     * @param string $command
     * @param int    $status shell 退出码
     * @param string $stdout
     * @param string $stderr
     * @return bool
     */
    protected function finalizeCommandResult($command, $status, $stdout, $stderr) {
        $this->command = $command;
        $this->status = ((int)$status === 0) ? 1 : 0;

        $parts = [];
        if (trim($stdout) !== '') {
            $parts[] = rtrim($stdout);
        }
        if (trim($stderr) !== '') {
            $parts[] = '[stderr]';
            $parts[] = rtrim($stderr);
        }

        $this->log = trim(implode(PHP_EOL, $parts));
        if ($this->log !== '') {
            $this->log .= PHP_EOL;
        }
        $this->log .= '[exit code: ' . $status . ']';

        $this->log($this->log);
        if ($this->status !== 1) {
            LogHelper::deployDecision('shell_exit_not_zero', [
                'reason' => 'shell exit code is not 0',
                'exit_code' => $status,
                'command' => $command,
                'stdout_tail' => mb_substr((string)$stdout, -2000, null, 'UTF-8'),
                'stderr_tail' => mb_substr((string)$stderr, -2000, null, 'UTF-8'),
                'log_tail' => mb_substr($this->log, -3000, null, 'UTF-8'),
            ]);
        }
        $this->log('---------------------------------');

        return $this->status === 1;
    }

    /**
     * 执行远程目标机器命令
     *
     * @param string  $command
     * @param integer $delay 每台机器延迟执行post_release任务间隔, 不推荐使用, 仅当业务无法平滑重启时使用
     * @return bool
     */
    final public function runRemoteCommand($command, $delay = 0) {
        $this->log = '';
        $needTTY = '-T';

        foreach (GlobalHelper::str2arr($this->getConfig()->hosts) as $remoteHost) {

            $localCommand = sprintf('ssh %s -p %d -q -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o CheckHostIP=false %s@%s %s',
                $needTTY,
                $this->getHostPort($remoteHost),
                escapeshellarg($this->getConfig()->release_user),
                escapeshellarg($this->getHostName($remoteHost)),
                escapeshellarg($command)
            );

            if ($delay > 0) {
                // 每台机器延迟执行post_release任务间隔, 不推荐使用, 仅当业务无法平滑重启时使用
                static::log(sprintf('Sleep: %d s', $delay));
                sleep($delay);
            }

            static::log('Run remote command ' . $command);

            $log = $this->log;
            $this->runLocalCommand($localCommand);

            $this->log = $log . (($log ? PHP_EOL : '') . $remoteHost . ' : ' . $this->log);
            if ($this->status !== 1) {
                return false;
            }

        }

        return true;
    }

    /**
     * 加载配置
     *
     * @param $config
     * @return $this
     * @throws \Exception
     */
    public function setConfig($config) {
        if ($config) {
            $this->config = $config;
        } else {
            throw new \Exception(\yii::t('walle', 'unknown config'));
        }
        return $this;
    }

    /**
     * 获取配置
     * @return \walle\config\Config
     */
    protected function getConfig() {
        return $this->config;
    }

    public static function log($message) {
        if (empty(\Yii::$app->params['log.dir'])) return;

        $logDir = \Yii::$app->params['log.dir'];
        if (!file_exists($logDir)) return;

        $logFile = realpath($logDir) . '/walle-' . date('Ymd') . '.log';
        if (self::$logFile === null) {
            self::$logFile = fopen($logFile, 'a');
        }

        $message = date('Y-m-d H:i:s -- ') . $message;
        fwrite(self::$logFile, $message . PHP_EOL);
    }

    /**
     * 获取执行command
     *
     * @author wushuiyong
     * @return string
     */
    public function getExeCommand() {
        return $this->command;
    }

    /**
     * 获取执行log
     *
     * @author wushuiyong
     * @return string
     */
    public function getExeLog() {
        return $this->log === null ? '' : $this->log;
    }

    /**
     * 合并多步任务输出（pre/post-deploy 逐步执行时使用）
     *
     * @param string $command
     * @param string $log
     */
    public function setExecutionResult($command, $log) {
        $this->command = $command;
        $this->log = $log;
    }

    /**
     * 获取执行log
     *
     * @author wushuiyong
     * @return string
     */
    public function getExeStatus() {
        return (int)$this->status;
    }

    /**
     * 获取耗时毫秒数
     *
     * @return int
     */
    public static function getMs() {
        return intval(microtime(true) * 1000);
    }

    /**
     * 获取目标机器的ip或别名
     *
     * @param $host
     * @return mixed
     */
    protected function getHostName($host) {
        list($hostName,) = explode(':', $host);
        return $hostName;
    }

    /**
     * 获取目标机器的ssh端口
     *
     * @param $host
     * @param int $default
     * @return int
     */
    protected function getHostPort($host, $default = 22) {
        $hostInfo = explode(':', $host);
        return !empty($hostInfo[1]) ? $hostInfo[1] : $default;
    }

}
