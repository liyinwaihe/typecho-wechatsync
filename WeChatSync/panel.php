<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台管理面板：微信公众号同步
 * 由 admin/extending.php 载入，common.php 已初始化 $options / $user / $security / $request
 */

/** 面板文件可被直接请求，必须在此强制鉴权 */
$user->pass('administrator');

require_once __DIR__ . '/Cron.php';

$db       = Typecho_Db::get();
$options  = Typecho_Widget::widget('Widget_Options');
$security = Typecho_Widget::widget('Widget_Security');

try {
    $cfg = $options->plugin('WeChatSync');
} catch (Exception $e) {
    $cfg = new Typecho_Config(array());
}

// 面板自身地址（两版通用，避免 adminUrl 在 1.1 直接 echo 的问题）
$panelBase = Typecho_Common::url(
    'extending.php?panel=' . urlencode('WeChatSync/panel.php'),
    $options->adminUrl
);

// 定时回查地址（走 Typecho 路由，兼容是否开启伪静态）
$cronKey = WeChatSync_Cron::key();
try {
    $cronUrl = Typecho_Router::url('wechat_sync_cron', array('key' => $cronKey), $options->index);
} catch (Exception $e) {
    $cronUrl = Typecho_Common::url('/wechat-sync/cron/' . $cronKey . '/', $options->rootUrl);
}

/* ---------------- POST 处理（PRG） ---------------- */
if ($request->isPost()) {
    // CSRF 校验，失败会自动返回上一页
    $security->protect();

    $do = $request->get('do');
    try {
        require_once __DIR__ . '/Api.php';
        require_once __DIR__ . '/Sync.php';

        switch ($do) {
            case 'test':
                $t = WeChatSync_Plugin::testConnection();
                $msg = '连接成功，已获取 access_token（' . substr($t['token'], 0, 8) . '…）';
                if ($t['mode'] === 1) {
                    if ($t['can_publish'] === 0) {
                        $msg = '连接成功，但当前公众号【没有 API 群发权限】（未认证/个人主体，2025-07 起被回收，错误码 48001）。草稿箱、传图均可正常使用，建议把「同步方式」改为“仅保存到草稿箱”，发布在公众号后台手动完成。';
                        $ok = 0; // 以警告样式展示
                        break;
                    }
                    if ($t['can_publish'] === 1) {
                        $msg .= '；已确认具备 API 群发权限。';
                    }
                } else {
                    $msg .= '；当前为草稿箱模式（未认证号也可正常使用）。';
                }
                $ok = 1;
                break;

            case 'sync':
                $cid   = intval($request->get('cid'));
                $force = intval($request->get('force')) === 1;
                if ($cid <= 0) {
                    throw new Exception('缺少文章 cid');
                }
                $result = WeChatSync_Plugin::syncCid($cid, $force);
                if (!empty($result['published'])) {
                    $msg = '已推送到草稿箱并提交发布';
                } elseif (isset($result['state']) && $result['state'] === 'draft_ready') {
                    $msg = '草稿已生成；自动发布不可用（账号无群发权限），请到公众号后台手动群发';
                } else {
                    $msg = '已保存到公众号草稿箱，请到公众号后台手动群发';
                }
                $ok = 1;
                break;

            case 'recheck':
                $report = WeChatSync_Cron::checkAll();
                $msg = '回查完成：本次检查 ' . $report['checked']
                    . '，成功 ' . $report['success']
                    . '，发布中 ' . $report['pending']
                    . '，失败 ' . $report['failed'];
                $ok = 1;
                break;

            case 'test-notify':
                require_once __DIR__ . '/Notifier.php';
                $count = WeChatSync_Plugin::testNotify();
                if ($count > 0) {
                    $msg = '测试通知已通过 ' . $count . ' 个渠道发送，请查收';
                    $ok = 1;
                } else {
                    $msg = '未发送：请先在插件配置中勾选至少一个通知渠道并填写对应参数';
                    $ok = 0;
                }
                break;

            default:
                throw new Exception('未知操作');
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $ok = 0;
    }

    $response->redirect($panelBase . '&wxs=' . ($ok ? 'ok' : 'err') . '&wxsmsg=' . urlencode($msg));
}

/* ---------------- GET 数据准备 ---------------- */

$flashOk  = $request->get('wxs') === 'ok';
$flashErr = $request->get('wxs') === 'err';
$flashMsg = $request->get('wxsmsg');

// 最近 20 篇文章
$posts = $db->fetchAll($db->select('cid', 'title', 'created', 'status', 'type')
    ->from('table.contents')
    ->where('type = ?', 'post')
    ->order('created', Typecho_Db::SORT_DESC)
    ->limit(20));

$cids = array();
foreach ($posts as $p) {
    $cids[] = $p['cid'];
}

$syncMap = array();
if (!empty($cids)) {
    $marks = $db->fetchAll($db->select('cid', 'name', 'str_value', 'int_value')
        ->from('table.fields')
        ->where('cid IN ?', $cids)
        ->where('name LIKE ?', 'wechat_%'));
    foreach ($marks as $m) {
        $val = $m['str_value'] !== null ? $m['str_value'] : $m['int_value'];
        $syncMap[$m['cid']][$m['name']] = $val;
    }
}

// 最近日志
$logFile = __DIR__ . '/cache/sync.log';
$logs = array();
if (is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    $logs = array_slice(array_reverse($lines), 0, 30);
}

// CSRF token
$tokenValue = $security->getToken($request->getRequestUrl());

include 'header.php';
include 'menu.php';
?>

<style>
    .wxs-card { background:#fff; border:1px solid #e6e9ef; border-radius:8px; padding:18px 20px; margin-bottom:18px; }
    .wxs-card h3 { margin:0 0 12px; font-size:15px; }
    .wxs-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px; white-space:nowrap; }
    .wxs-ok { background:#e6f7ec; color:#1a8c45; }
    .wxs-draft { background:#fff5e6; color:#b97a13; }
    .wxs-no { background:#f0f1f5; color:#8a8f99; }
    .wxs-doing { background:#e8f1fd; color:#1f6ed4; }
    .wxs-fail { background:#fdecec; color:#c0392b; }
    .wxs-table { width:100%; border-collapse:collapse; font-size:13px; }
    .wxs-table th, .wxs-table td { padding:8px 10px; border-bottom:1px solid #eef0f4; text-align:left; vertical-align:middle; }
    .wxs-table th { color:#8a8f99; font-weight:600; }
    .wxs-form { display:inline; }
    .wxs-log { background:#1f2430; color:#d6dae3; border-radius:6px; padding:12px; font-size:12px;
              line-height:1.7; max-height:260px; overflow:auto; white-space:pre-wrap; word-break:break-all; }
    .wxs-cron { background:#f7f8fa; border:1px dashed #d4d8e0; border-radius:6px; padding:8px 10px;
               font-size:12px; word-break:break-all; margin:8px 0; }
    .wxs-sub { display:block; margin-top:4px; font-size:12px; color:#9aa0ab; }
    .wxs-sub a { color:#1f6ed4; }
</style>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2>微信公众号同步</h2>
        </div>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">

                <?php if ($flashOk): ?>
                    <div class="message success notice"><?php echo htmlspecialchars($flashMsg); ?></div>
                <?php elseif ($flashErr): ?>
                    <div class="message error notice"><?php echo htmlspecialchars($flashMsg); ?></div>
                <?php endif; ?>

                <!-- 接口连接 / 状态回查 -->
                <div class="wxs-card">
                    <h3>接口连接与发布回查</h3>
                    <p style="color:#666;font-size:13px;margin:0 0 12px;">
                        同步方式：<?php echo intval($cfg->mode) === 1 ? '自动群发发布' : '仅存草稿箱（推荐）'; ?>
                        · AppID：<?php echo htmlspecialchars($cfg->appid ? substr($cfg->appid, 0, 6) . '****' : '未配置'); ?>
                    </p>
                    <form class="wxs-form" method="post" action="<?php echo htmlspecialchars($panelBase); ?>">
                        <input type="hidden" name="do" value="test">
                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($tokenValue); ?>">
                        <button type="submit" class="btn primary">测试连接</button>
                    </form>
                    <form class="wxs-form" method="post" action="<?php echo htmlspecialchars($panelBase); ?>">
                        <input type="hidden" name="do" value="recheck">
                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($tokenValue); ?>">
                        <button type="submit" class="btn">立即回查发布状态</button>
                    </form>
                    <form class="wxs-form" method="post" action="<?php echo htmlspecialchars($panelBase); ?>"
                          onsubmit="this.querySelector('button').disabled=true;">
                        <input type="hidden" name="do" value="test-notify">
                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($tokenValue); ?>">
                        <button type="submit" class="btn">发送测试通知</button>
                    </form>
                    <a class="btn" href="<?php echo $options->adminUrl('options-plugin.php?config=WeChatSync'); ?>">修改插件配置</a>

                    <?php if (intval($cfg->mode) === 1): ?>
                    <div style="margin-top:14px;">
                        <strong style="font-size:13px;">定时回查地址（建议系统 cron 每分钟请求一次）</strong>
                        <div class="wxs-cron"><?php echo htmlspecialchars($cronUrl); ?></div>
                        <p style="color:#999;font-size:12px;margin:0;">
                            Linux 示例：<code>* * * * * curl -s "<?php echo htmlspecialchars($cronUrl); ?>" &gt;/dev/null 2&gt;&amp;1</code><br>
                            地址内含站点密钥，请勿公开；自动发布的文章会由它轮询公众号结果并回写「成功 / 失败 / 超时」。
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 最近文章 -->
                <div class="wxs-card">
                    <h3>最近文章</h3>
                    <table class="wxs-table">
                        <thead>
                        <tr>
                            <th>标题</th>
                            <th>发布日期</th>
                            <th>公众号状态</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($posts as $p):
                            $m = isset($syncMap[$p['cid']]) ? $syncMap[$p['cid']] : array();
                            $syncedAt  = isset($m['wechat_synced']) ? date('Y-m-d H:i', intval($m['wechat_synced'])) : '';
                            $published = isset($m['wechat_published']) ? intval($m['wechat_published']) : 0;
                            $state     = isset($m['wechat_publish_state']) ? $m['wechat_publish_state'] : '';
                            $articleUrl = isset($m['wechat_article_url']) ? $m['wechat_article_url'] : '';
                            $failMsg    = isset($m['wechat_fail_msg']) ? $m['wechat_fail_msg'] : '';
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo $options->adminUrl('write-post.php?cid=' . $p['cid']); ?>">
                                        <?php echo htmlspecialchars($p['title']); ?>
                                    </a>
                                </td>
                                <td><?php echo date('Y-m-d', $p['created']); ?></td>
                                <td>
                                    <?php if ($syncedAt === ''): ?>
                                        <span class="wxs-badge wxs-no">未同步</span>
                                    <?php elseif ($published !== 1): ?>
                                        <?php if ($state === 'draft_ready'): ?>
                                            <span class="wxs-badge wxs-draft">草稿已就绪·待手动群发</span>
                                            <?php if ($failMsg !== ''): ?>
                                                <span class="wxs-sub"><?php echo htmlspecialchars($failMsg); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="wxs-badge wxs-draft">草稿箱 · <?php echo $syncedAt; ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php
                                        if ($state === 'success') {
                                            echo '<span class="wxs-badge wxs-ok">已群发成功</span>';
                                            if ($articleUrl !== '') {
                                                echo '<span class="wxs-sub"><a href="' . htmlspecialchars($articleUrl) . '" target="_blank" rel="noopener">查看公众号文章 ↗</a></span>';
                                            }
                                        } elseif ($state === 'fail' || $state === 'timeout') {
                                            echo '<span class="wxs-badge wxs-fail" title="' . htmlspecialchars($failMsg) . '">'
                                                . ($state === 'timeout' ? '回查超时' : '发布失败') . '</span>';
                                            if ($failMsg !== '') {
                                                echo '<span class="wxs-sub">' . htmlspecialchars($failMsg) . '</span>';
                                            }
                                        } else {
                                            echo '<span class="wxs-badge wxs-doing">发布中… ' . $syncedAt . '</span>';
                                        }
                                        ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form class="wxs-form" method="post" action="<?php echo htmlspecialchars($panelBase); ?>"
                                          onsubmit="this.querySelector('button').disabled=true;">
                                        <input type="hidden" name="do" value="sync">
                                        <input type="hidden" name="cid" value="<?php echo $p['cid']; ?>">
                                        <input type="hidden" name="force" value="0">
                                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($tokenValue); ?>">
                                        <button type="submit" class="btn s">同步</button>
                                    </form>
                                    <?php if ($syncedAt !== ''): ?>
                                        <form class="wxs-form" method="post" action="<?php echo htmlspecialchars($panelBase); ?>"
                                              onsubmit="return confirm('确定强制重新同步？将在公众号生成新的草稿/发布。');">
                                            <input type="hidden" name="do" value="sync">
                                            <input type="hidden" name="cid" value="<?php echo $p['cid']; ?>">
                                            <input type="hidden" name="force" value="1">
                                            <input type="hidden" name="_" value="<?php echo htmlspecialchars($tokenValue); ?>">
                                            <button type="submit" class="btn s">强制重同步</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 日志 -->
                <div class="wxs-card">
                    <h3>同步日志（最近 30 条）</h3>
                    <?php if (!empty($logs)): ?>
                        <div class="wxs-log"><?php echo htmlspecialchars(implode("\n", $logs)); ?></div>
                    <?php else: ?>
                        <p style="color:#999;font-size:13px;margin:0;">暂无日志。</p>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<?php
include 'footer.php';
