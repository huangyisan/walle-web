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
     * 命令运行结果：0 失败，1 成功
     * @var int
     */
    protected $status = 1;

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
     * 执行本地宿主机命令
     *
     * @param $command
     * @return bool|int true 成功，false 失败
     */
    final public function runLocalCommand($command) {
        $command = trim($command);
        $this->log('---------------------------------');
        $this->log('---- Executing: $ ' . $command);

        $exitCode = 1;
        $stdout = '';
        $stderr = '';

        // 使用 proc_open 分别捕获 stdout/stderr，避免复杂命令输出丢失
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
                    $read = [$pipes[1], $pipes[2]];
                    $write = null;
                    $except = null;
                    @stream_select($read, $write, $except, 0, 200000);

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

                    $processStatus = proc_get_status($process);
                    if (!$processStatus['running']) {
                        break;
                    }
                }

                // 进程退出后再读一次，避免 PM2/Prisma 等尾部输出落在 pipe 里未捕获
                $tailOut = stream_get_contents($pipes[1]);
                if ($tailOut !== false && $tailOut !== '') {
                    $stdout .= $tailOut;
                }
                $tailErr = stream_get_contents($pipes[2]);
                if ($tailErr !== false && $tailErr !== '') {
                    $stderr .= $tailErr;
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
            } else {
                // proc_open 不可用时，回退到 exec
                $fallback = [];
                exec($command . ' 2>&1', $fallback, $exitCode);
                $stdout = implode(PHP_EOL, $fallback);
            }
        } else {
            $fallback = [];
            exec($command . ' 2>&1', $fallback, $exitCode);
            $stdout = implode(PHP_EOL, $fallback);
        }

        // 执行过的命令
        $this->command = $command;
        // shell 退出码 0 表示成功
        $this->status = ((int)$exitCode === 0) ? 1 : 0;

        $parts = [];
        if (trim($stdout) !== '') {
            $parts[] = rtrim($stdout);
        }
        if (trim($stderr) !== '') {
            $parts[] = '[stderr]';
            $parts[] = rtrim($stderr);
        }

        // 操作日志（含退出码，便于页面上定位 yarn/npm 等失败）
        $this->log = trim(implode(PHP_EOL, $parts));
        if ($this->log !== '') {
            $this->log .= PHP_EOL;
        }
        $this->log .= '[exit code: ' . $exitCode . ']';

        $this->log($this->log);
        $this->log('---------------------------------');

        return $this->status === 1;
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
