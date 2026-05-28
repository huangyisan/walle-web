<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 五  7/31 22:21:23 2015
 *
 * @File Name: command/Sync.php
 * @Description:
 * *****************************************************************/
namespace app\components;

use app\models\Project;

class Task extends Ansible {

    /**
     * pre-deploy部署代码前置触发任务
     * 在部署代码之前的准备工作，如git的一些前置检查、vendor的安装（更新）
     *
     * @return bool
     */
    public function preDeploy($version) {
        $tasks = GlobalHelper::str2arr($this->getConfig()->pre_deploy);

        return $this->runWorkspaceTasks($tasks, $version);
    }

    /**
     * post-deploy部署代码后置触发任务
     * git代码检出之后，可能做一些调整处理，如vendor拷贝，配置环境适配（mv config-test.php config.php）
     *
     * @return bool
     */
    public function postDeploy($version) {
        $tasks = GlobalHelper::str2arr($this->getConfig()->post_deploy);

        return $this->runWorkspaceTasks($tasks, $version);
    }

    /**
     * 在工作区内逐步执行配置命令，失败时保留完整输出
     *
     * @param array  $tasks
     * @param string $version
     * @return bool
     */
    protected function runWorkspaceTasks(array $tasks, $version) {
        if (empty($tasks)) {
            return true;
        }

        $workspace = rtrim(Project::getDeployWorkspace($version), '/');
        $pattern = ['#{WORKSPACE}#'];
        $replace = [$workspace];
        $logs = [];
        $commandsRun = [];
        $step = 0;

        foreach ($tasks as $taskLine) {
            $taskLine = trim(preg_replace($pattern, $replace, $taskLine));
            if ($taskLine === '') {
                continue;
            }
            $step++;
            $command = '. /etc/profile && cd ' . escapeshellarg($workspace) . ' && ' . $taskLine;
            $commandsRun[] = $taskLine;
            if (!$this->runLocalCommand($command)) {
                $logs[] = sprintf("[步骤 %d 失败] $ %s\n%s", $step, $taskLine, $this->getExeLog());
                $this->setExecutionResult(implode("\n", $commandsRun), implode("\n\n", $logs));
                return false;
            }
            $logs[] = sprintf("[步骤 %d 成功] $ %s\n%s", $step, $taskLine, $this->getExeLog());
        }

        $this->setExecutionResult(implode("\n", $commandsRun), implode("\n\n", $logs));
        return true;
    }

    /**
     * 设置了版本保留数量，超出了设定值，则删除老版本
     */
    public function cleanUpReleasesVersion() {

        $ansibleStatus = Project::getAnsibleStatus();

        $cmd[] = sprintf('cd %s', Project::getReleaseVersionDir());
        $cmd[] = sprintf('rm -f %s/*.tar.gz', rtrim(Project::getReleaseVersionDir(), '/'));
        $cmd[] = sprintf('ls -1 | sort -r | awk \'FNR > %d  {printf("rm -rf %%s\n", $0);}\' | bash', $this->config->keep_version_num);

        $command = join(' && ', $cmd);

        if ($ansibleStatus) {
            // ansible 并发执行远程命令
            return $this->runRemoteCommandByAnsibleShell($command);
        } else {
            return $this->runRemoteCommand($command);
        }
    }

    /**
     * 获取远程服务器要操作的任务命令
     *
     * @param $task    string
     * @param $version string
     * @return string string
     */
    public static function getRemoteTaskCommand($task, $version) {
        $tasks = GlobalHelper::str2arr($task);
        if (empty($tasks)) return '';

        // 可能要做一些依赖环境变量的命令操作
        $cmd = ['. /etc/profile'];
        $workspace = Project::getTargetWorkspace();
        $version   = Project::getReleaseVersionDir($version);
        $pattern = [
            '#{WORKSPACE}#',
            '#{VERSION}#',
        ];
        $replace = [
            $workspace,
            $version,
        ];

        // 简化用户切换目录，直接切换到当前的版本目录：{release_library}/{project}/{version}
        $cmd[] = "cd {$version}";
        foreach ($tasks as $task) {
            $cmd[] = preg_replace($pattern, $replace, $task);
        }
        return join(' && ', $cmd);
    }

    /**
     * 执行远程服务器任务集合
     * 对于目标机器更多的时候是一台机器完成一组命令，而不是每条命令逐台机器执行
     *
     * @param array   $tasks
     * @param integer $delay 每台机器延迟执行post_release任务间隔, 不推荐使用, 仅当业务无法平滑重启时使用
     * @return mixed
     */
    public function runRemoteTaskCommandPackage($tasks, $delay = 0) {

        $task = join(' && ', $tasks);

        if (Project::getAnsibleStatus() && !$delay) {
            // ansible 并发执行远程命令
            return $this->runRemoteCommandByAnsibleShell($task);
        } else {
            return $this->runRemoteCommand($task, $delay);
        }

    }

}
