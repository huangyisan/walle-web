<?php
/**
 * @var yii\web\View $this
 */
$this->title = yii::t('walle', 'deploying');
use \app\models\Task;
use yii\helpers\Url;
?>
<style>
    .status > span {
        float: left;
        font-size: 12px;
        width: 14%;
        text-align: right;
    }
    .btn-deploy {
        margin-left: 30px;
    }
    .btn-return {
        /*float: right;*/
        margin-left: 30px;
    }
    .deploy-error-log {
        max-height: 420px;
        overflow: auto;
        text-align: left;
        white-space: pre-wrap;
        word-break: break-word;
        background: #f9f2f4;
        border: 1px solid #eed3d7;
        padding: 10px;
        margin-top: 8px;
        font-size: 12px;
    }
</style>
<div class="box" style="height: 100%">
    <h4 class="box-title header smaller red">
            <i class="icon-map-marker"></i><?= \Yii::t('w', 'conf_level_' . $task->project['level']) ?>
            -
            <?= $task->project->name ?>
            ：
            <?= $task->title ?>
            （<?= $task->project->repo_mode . ':' . $task->branch ?> <?= yii::t('walle', 'version') ?><?= $task->commit_id ?>）
            <?php if (in_array($task->status, [Task::STATUS_PASS, Task::STATUS_FAILED])) { ?>
                <button type="submit" class="btn btn-primary btn-deploy" data-id="<?= $task->id ?>"><?= yii::t('walle', 'deploy') ?></button>
            <?php } ?>
            <a class="btn btn-success btn-return" href="<?= Url::to('@web/task/index') ?>"><?= yii::t('walle', 'return') ?></a>
    </h4>
    <div class="status">
        <span><i class="fa fa-circle-o text-yellow step-1"></i><?= yii::t('walle', 'process_detect') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-2"></i><?= yii::t('walle', 'process_pre-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-3"></i><?= yii::t('walle', 'process_checkout') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-4"></i><?= yii::t('walle', 'process_post-deploy') ?></span>
        <span><i class="fa fa-circle-o text-yellow step-5"></i><?= yii::t('walle', 'process_rsync') ?></span>
        <span style="width: 28%"><i class="fa fa-circle-o text-yellow step-6"></i><?= yii::t('walle', 'process_update') ?></span>
    </div>
    <div style="clear:both"></div>
    <div class="progress progress-small progress-striped active">
        <div class="progress-bar progress-status progress-bar-success" style="width: <?= $task->status == Task::STATUS_DONE ? 100 : 0 ?>%;"></div>
    </div>

    <div class="alert alert-block alert-success result-success" style="<?= $task->status != Task::STATUS_DONE ? 'display: none' : '' ?>">
        <h4><i class="icon-thumbs-up"></i><?= yii::t('walle', 'done') ?></h4>
        <p><?= yii::t('walle', 'done praise') ?></p>

    </div>

    <div class="alert alert-block alert-danger result-failed" style="display: none">
        <h4><i class="icon-bell-alt"></i><?= yii::t('walle', 'error title') ?></h4>
        <span class="error-msg">
        </span>
        <br><br>
        <i class="icon-bullhorn"></i><span><?= yii::t('walle', 'error todo') ?></span>
    </div>

</div>

<script type="text/javascript">
    $(function() {
        function escapeHtml(text) {
            if (text === null || text === undefined) {
                return '';
            }
            return $('<div/>').text(String(text)).html();
        }

        function showDeployError(o, data) {
            data = data || {};
            var step = data.step || 0;
            if (step > 0) {
                $('.step-' + step).removeClass('text-yellow').addClass('text-red');
            }
            $('.progress-status').removeClass('progress-bar-success').addClass('progress-bar-danger');
            var title = o.msg || '<?= yii::t('walle', 'deploy failed') ?>';
            var cmd = data.command || '';
            var memo = data.memo || o.msg || '';
            var html = '<p><strong>' + escapeHtml(title) + '</strong></p>';
            if (cmd) {
                html += '<p><code style="white-space:pre-wrap;">' + escapeHtml(cmd) + '</code></p>';
            }
            if (memo) {
                html += '<pre class="deploy-error-log">' + escapeHtml(memo) + '</pre>';
            }
            $('.error-msg').html(html);
            $('.result-failed').show();
        }

        $('.btn-deploy').click(function() {
            $this = $(this);
            $this.addClass('disabled');
            var task_id = $(this).data('id');
            var timer;
            var deployFinished = false;
            // 前端兜底：最长等待 10 分钟，不重试，超时直接判失败提示用户去看服务器/日志
            var DEPLOY_MAX_WAIT_MS = 10 * 60 * 1000;
            var deployStartedAt = Date.now();
            // 传给 get-process 用于过滤掉"重新发起部署"时竞态读到的上一次失败记录；
            // 减 10s 兜底客户端/服务端时钟误差，不影响真实失败的判定（真实失败的 record 必然晚于此时间）
            var sinceTs = Math.floor(deployStartedAt / 1000) - 10;
            function markDeploySuccess() {
                deployFinished = true;
                $('.progress-status').removeClass('progress-bar-danger').removeClass('progress-bar-striped').addClass('progress-bar-success');
                $('.progress-status').parent().removeClass('progress-striped');
                $('.progress-status').attr('aria-valuenow', 100).width('100%');
                $('.result-success').show();
                $('.result-failed').hide();
                $this.removeClass('disabled');
                clearInterval(timer);
            }
            function markDeployTimeout() {
                if (deployFinished) {
                    return;
                }
                deployFinished = true;
                clearInterval(timer);
                $('.progress-status').removeClass('progress-bar-success').addClass('progress-bar-danger');
                showDeployError({msg: '<?= yii::t('walle', 'deploy timeout') ?>'}, {});
                $this.removeClass('disabled');
            }
            function applyProcessUi(data) {
                data = data || {};
                if (0 != data.percent) {
                    $('.progress-status').attr('aria-valuenow', data.percent).width(data.percent + '%');
                }
                for (var i = 1; i <= 6; i++) {
                    $('.step-' + i).removeClass('text-green text-red progress-bar-striped');
                    if (i <= data.step) {
                        $('.step-' + i).removeClass('text-yellow').addClass('text-green');
                    } else {
                        $('.step-' + i).addClass('text-yellow');
                    }
                }
            }
            // 与后端 actionStartDeploy 一致：pre_deploy → checkout → post_deploy → 同步 → pre_release/软链/post_release
            var TASK_STATUS_PASS = 1;
            var TASK_STATUS_DONE = 3;
            var TASK_STATUS_FAILED = 4;
            function isDeployDone(data) {
                if (!data) {
                    return false;
                }
                if (data.task_status === TASK_STATUS_DONE) {
                    return true;
                }
                return 1 == data.status && (100 == data.percent || 6 == data.step);
            }
            function confirmDeployResult(o) {
                if (deployFinished) {
                    return;
                }
                $.get("<?= Url::to('@web/walle/get-process?taskId=') ?>" + task_id + "&since=" + sinceTs, function (process) {
                    var data = process.data || {};
                    applyProcessUi(data);
                    if (isDeployDone(data)) {
                        markDeploySuccess();
                        return;
                    }
                    // 二次确认仍为进行中：不要用轮询快照误报失败，继续等 start-deploy 结束
                    if (1 == data.status && data.task_status !== TASK_STATUS_FAILED) {
                        return;
                    }
                    clearInterval(timer);
                    showDeployError(o || process, data);
                    $this.removeClass('disabled');
                }).fail(function() {
                    clearInterval(timer);
                    showDeployError(o || {msg: '<?= yii::t('walle', 'deploy failed') ?>'}, {});
                    $this.removeClass('disabled');
                });
            }
            function handleDeployRequestError(o) {
                confirmDeployResult(o);
            }
            $.post("<?= Url::to('@web/walle/start-deploy') ?>", {taskId: task_id}, function(o) {
                if (deployFinished) {
                    return;
                }
                if (o.code != 0) {
                    handleDeployRequestError(o);
                    return;
                }
                if (o.data && o.data.warning) {
                    $('.result-success p').append(' (' + escapeHtml(o.data.warning) + ')');
                }
                confirmDeployResult({msg: ''});
            }).fail(function(xhr) {
                var o = {msg: '<?= yii::t('walle', 'deploy failed') ?>'};
                try {
                    o = JSON.parse(xhr.responseText);
                } catch (e) {}
                handleDeployRequestError(o);
            });
            $('.progress-status').attr('aria-valuenow', 10).width('10%');
            $('.result-failed').hide();
            function getProcess() {
                if (deployFinished) {
                    return;
                }
                if (Date.now() - deployStartedAt >= DEPLOY_MAX_WAIT_MS) {
                    markDeployTimeout();
                    return;
                }
                $.get("<?= Url::to('@web/walle/get-process?taskId=') ?>" + task_id + "&since=" + sinceTs, function (o) {
                    var data = o.data || {};
                    applyProcessUi(data);
                    if (isDeployDone(data)) {
                        markDeploySuccess();
                        return;
                    }
                    // task 仍为 PASS 时后端不会返回终态失败；仅 task 已 FAILED 才二次确认
                    if (0 == data.status && data.percent > 0 && data.task_status === TASK_STATUS_FAILED) {
                        confirmDeployResult(o);
                        return;
                    }
                    $('.progress-status')
                        .removeClass('progress-bar-danger')
                        .addClass('progress-bar-success progress-bar-striped');
                });
            }
            timer = setInterval(getProcess, 600);
        })

        var _hmt = _hmt || [];
        (function() {
            var hm = document.createElement("script");
            hm.src = "//hm.baidu.com/hm.js?5fc7354aff3dd67a6435818b8ef02b52";
            var s = document.getElementsByTagName("script")[0];
            s.parentNode.insertBefore(hm, s);
        })();
    })

</script>
