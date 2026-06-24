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

    /**
     * 单条命令最长允许执行时间（秒），超时直接判失败，不重试
     */
    const MAX_EXEC_SECONDS = 600;

    /** @var bool|null 宿主机是否有 coreutils timeout 命令，缓存检测结果 */
    protected static $hasTimeoutBin = null;

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

                $deadline = microtime(true) + self::MAX_EXEC_SECONDS;
                $timedOut = false;

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
                    if (microtime(true) >= $deadline) {
                        // 超时直接杀进程组，不重试，避免被后台残留子进程的管道挂死
                        $timedOut = true;
                        @proc_terminate($process, 9);
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
                if ($timedOut) {
                    $status = 124;
                    $stderr .= PHP_EOL . sprintf(
                        '[timeout] command exceeded %d s and was killed, no retry',
                        self::MAX_EXEC_SECONDS
                    );
                }
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
        $wrapped = $this->wrapWithTimeout($command);
        exec($wrapped . ' 2>&1', $output, $status);
        $log = implode(PHP_EOL, $output);

        // timeout 命令在超时时杀掉子进程后返回 124，不重试，直接按失败处理
        if ($status === 124) {
            $log = rtrim($log . PHP_EOL) . PHP_EOL
                . sprintf('[timeout] command exceeded %d s and was killed, no retry', self::MAX_EXEC_SECONDS);
        }

        return $this->finalizeCommandResult($command, $status, $log, '');
    }

    /**
     * 用 coreutils timeout 包一层，超时后 SIGTERM，5s 后 SIGKILL，杜绝命令卡死整个请求
     * 没有 timeout 命令的环境直接跳过包装（极少见，宿主机为非 Linux 环境时）
     *
     * @param string $command
     * @return string
     */
    protected function wrapWithTimeout($command) {
        if (self::$hasTimeoutBin === null) {
            exec('command -v timeout 2>/dev/null', $out, $code);
            self::$hasTimeoutBin = ($code === 0 && !empty($out));
        }
        if (!self::$hasTimeoutBin) {
            return $command;
        }
        return sprintf('timeout -k 5 %d %s', self::MAX_EXEC_SECONDS, $command);
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

            // ConnectTimeout 限制建连阶段；ServerAlive* 保证连上后对端假死/网络静默丢包时也能在数十秒内探测断开
            // 不依赖这两个参数兜底超时：最终仍由 wrapWithTimeout 的 MAX_EXEC_SECONDS 做整体硬限制
            $localCommand = sprintf('ssh %s -p %d -q -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o CheckHostIP=false -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=3 %s@%s %s',
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
        self::appendLogLine(
            'walle-' . date('Ymd') . '.log',
            date('Y-m-d H:i:s') . ' -- ' . $message . PHP_EOL
        );
    }

    /**
     * @return string|null
     */
    public static function getLogDir() {
        if (!class_exists('\Yii') || !\Yii::$app || empty(\Yii::$app->params['log.dir'])) {
            $fromEnv = getenv('WALLE_LOG_PATH');
            if ($fromEnv !== false && $fromEnv !== '') {
                $logDir = rtrim($fromEnv, '/');
            } else {
                return null;
            }
        } else {
            $logDir = rtrim(\Yii::$app->params['log.dir'], '/');
        }
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        if (!is_dir($logDir)) {
            return null;
        }
        $real = realpath($logDir);
        return $real ?: $logDir;
    }

    /**
     * @param string $basename
     * @param string $line
     * @return bool
     */
    public static function appendLogLine($basename, $line) {
        $logDir = self::getLogDir();
        if (!$logDir) {
            return false;
        }
        return @file_put_contents($logDir . '/' . $basename, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * 部署判定诊断：写入 walle-*.log 与 deploy-decision-*.log（与命令日志同目录）
     *
     * @param string $stage
     * @param array  $payload
     */
    public static function deployDecision($stage, array $payload = []) {
        $payload['stage'] = $stage;
        $line = date('Y-m-d H:i:s') . ' -- [deploy-decision] '
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $date = date('Ymd');
        $ok1 = self::appendLogLine('walle-' . $date . '.log', $line);
        $ok2 = self::appendLogLine('deploy-decision-' . $date . '.log', $line);
        if (!$ok1 && !$ok2) {
            error_log('walle deploy-decision: ' . trim($line));
        }
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
