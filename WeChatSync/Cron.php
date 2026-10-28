<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 公众号发布状态定时回查
 *
 * 路由入口（activate 时注册）：
 *   /wechat-sync/cron/{key}/
 * 其中 key 为站点级密钥（见 key()），建议系统 crontab 每分钟请求一次。
 *
 * 也可由后台管理面板在登录态下直接调用 checkAll()。
 *
 * @package WeChatSync
 */
class WeChatSync_Cron extends Typecho_Widget
{
    /** 发布中最多回查次数（每分钟一次，约 40 分钟后置为超时） */
    const MAX_POLLS = 40;

    /**
     * 前端路由组件入口（无需初始化归档查询）
     */
    public function execute()
    {
    }

    /**
     * 路由动作：校验密钥并执行回查
     */
    public function run()
    {
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        header('Content-Type: text/plain; charset=utf-8');

        $key = $this->request->get('key');
        if ($key !== self::key()) {
            header('HTTP/1.1 403 Forbidden');
            echo "invalid key\n";
            return;
        }

        $report = self::checkAll();
        echo date('Y-m-d H:i:s') . ' checked=' . $report['checked']
            . ' success=' . $report['success']
            . ' pending=' . $report['pending']
            . ' failed=' . $report['failed'] . "\n";
        exit;
    }

    /**
     * 计算 cron 密钥（站点 secret + 插件名派生，不可枚举）
     *
     * @return string
     */
    public static function key()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        return md5($options->secret . '|WeChatSync|cron');
    }

    /**
     * 回查所有「发布中」的文章
     *
     * @return array {checked, success, pending, failed}
     */
    public static function checkAll()
    {
        require_once __DIR__ . '/Api.php';
        require_once __DIR__ . '/Notifier.php';

        $options = Typecho_Widget::widget('Widget_Options');
        $db = Typecho_Db::get();

        try {
            $cfg = $options->plugin('WeChatSync');
        } catch (Exception $e) {
            return array('checked' => 0, 'success' => 0, 'pending' => 0, 'failed' => 0);
        }

        $api = new WeChatSync_Api($cfg->appid, $cfg->secret, __DIR__ . '/cache');
        $notifier = new WeChatSync_Notifier($cfg, $api);

        // 取所有已记录 publish_id 的文章
        $rows = $db->fetchAll($db->select('cid', 'str_value')
            ->from('table.fields')
            ->where('name = ?', 'wechat_publish_id')
            ->where("str_value IS NOT NULL AND str_value <> ''"));

        $checked = $success = $pending = $failed = 0;

        foreach ($rows as $row) {
            $cid = intval($row['cid']);
            $publishId = $row['str_value'];
            $state = self::getField($db, $cid, 'wechat_publish_state');

            // 仅处理「发布中」
            if ($state !== '' && $state !== 'publishing') {
                continue;
            }

            $count = intval(self::getField($db, $cid, 'wechat_poll_count'));
            if ($count >= self::MAX_POLLS) {
                self::setField($db, $cid, 'wechat_publish_state', 'timeout', 'str');
                self::setField($db, $cid, 'wechat_fail_msg', '回查超时，超过最大次数', 'str');
                $failed++;
                $checked++;
                self::log('POLL_TIMEOUT cid=' . $cid . ' publish_id=' . $publishId);
                continue;
            }

            $checked++;
            $count++;
            self::setField($db, $cid, 'wechat_poll_count', $count, 'int');
            self::setField($db, $cid, 'wechat_poll_updated', time(), 'int');

            try {
                $res = $api->getPublishStatus($publishId);
            } catch (Exception $e) {
                // 网络/接口错误，本轮跳过，下轮重试
                self::log('POLL_ERROR cid=' . $cid . ' ' . $e->getMessage());
                $pending++;
                continue;
            }

            // 接口级错误
            if (!empty($res['errcode']) && intval($res['errcode']) !== 0) {
                self::log('POLL_WECHAT_ERR cid=' . $cid . ' [' . $res['errcode'] . '] ' . $res['errmsg']);
                $pending++;
                continue;
            }

            $status = isset($res['publish_status']) ? intval($res['publish_status']) : null;

            switch ($status) {
                case 0:
                    // 成功：article_id + article_detail.item[].article_url
                    $articleUrl = '';
                    if (isset($res['article_detail']['item'][0]['article_url'])) {
                        $articleUrl = $res['article_detail']['item'][0]['article_url'];
                    }
                    $articleId = isset($res['article_id']) ? strval($res['article_id']) : '';

                    self::setField($db, $cid, 'wechat_publish_state', 'success', 'str');
                    self::setField($db, $cid, 'wechat_article_id', $articleId, 'str');
                    self::setField($db, $cid, 'wechat_article_url', $articleUrl, 'str');
                    self::setField($db, $cid, 'wechat_fail_msg', '', 'str');
                    $success++;
                    self::log('POLL_SUCCESS cid=' . $cid . ' article_id=' . $articleId
                        . ($articleUrl !== '' ? ' url=' . $articleUrl : ''));
                    self::notifyTerminal($notifier, $db, $cid, '公众号文章已群发成功', $articleUrl);
                    break;

                case 1:
                    // 仍在发布中
                    $pending++;
                    self::log('POLL_PENDING cid=' . $cid . ' (' . $count . ')');
                    break;

                case 2:
                case 3:
                case 4:
                case 5:
                case 6:
                    $reasons = array(
                        2 => '原创校验失败',
                        3 => '常规发布失败',
                        4 => '平台审核不通过',
                        5 => '发布成功后文章被删除',
                        6 => '发布成功后文章被系统封禁',
                    );
                    $reason = isset($reasons[$status]) ? $reasons[$status] : ('发布失败(' . $status . ')');
                    if (!empty($res['fail_idx'])) {
                        $reason .= '；失败图文位：' . (is_array($res['fail_idx']) ? implode(',', $res['fail_idx']) : $res['fail_idx']);
                    }
                    self::setField($db, $cid, 'wechat_publish_state', 'fail', 'str');
                    self::setField($db, $cid, 'wechat_fail_msg', $reason, 'str');
                    $failed++;
                    self::log('POLL_FAIL cid=' . $cid . ' status=' . $status . ' ' . $reason);
                    self::notifyTerminal($notifier, $db, $cid, '公众号群发失败：' . $reason, '');
                    break;

                default:
                    // 未知状态，继续等待
                    $pending++;
                    self::log('POLL_UNKNOWN cid=' . $cid . ' status=' . var_export($status, true));
            }
        }

        return array(
            'checked' => $checked,
            'success' => $success,
            'pending' => $pending,
            'failed'  => $failed,
        );
    }

    /* -----------------------------------------------------------------
     * 终态通知（成功/失败各只发一次，因为只处理 publishing）
     * --------------------------------------------------------------- */

    protected static function notifyTerminal($notifier, $db, $cid, $reason, $link)
    {
        try {
            $row = $db->fetchRow($db->select('title')->from('table.contents')
                ->where('cid = ?', $cid)->limit(1));
            $title = !empty($row['title']) ? $row['title'] : ('cid=' . $cid);
            $notifier->notify(
                '【公众号发布结果】' . mb_substr($title, 0, 50, 'UTF-8'),
                $reason . "\n文章：" . $title,
                $link
            );
        } catch (Exception $e) {
            self::log('NOTIFY_TERMINAL_FAIL cid=' . $cid . ' ' . $e->getMessage());
        }
    }

    /* -----------------------------------------------------------------
     * 字段读写（与 Sync 保持同一结构，独立实现以避免依赖顺序）
     * --------------------------------------------------------------- */

    protected static function getField($db, $cid, $name)
    {
        $row = $db->fetchRow($db->select('str_value', 'int_value')
            ->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, $name));
        if (empty($row)) {
            return '';
        }
        return $row['str_value'] !== null ? $row['str_value'] : strval($row['int_value']);
    }

    protected static function setField($db, $cid, $name, $value, $type)
    {
        $exist = $db->fetchRow($db->select('cid')->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, $name));

        $strVal = ($type === 'str') ? $value : null;
        $intVal = ($type === 'int') ? intval($value) : 0;

        if (empty($exist)) {
            $db->query($db->insert('table.fields')->rows(array(
                'cid'         => $cid,
                'name'        => $name,
                'type'        => $type === 'str' ? 'str' : 'int',
                'str_value'   => $strVal,
                'int_value'   => $intVal,
                'float_value' => 0,
            )));
        } else {
            $db->query($db->update('table.fields')
                ->rows(array(
                    'type'      => $type === 'str' ? 'str' : 'int',
                    'str_value' => $strVal,
                    'int_value' => $intVal,
                ))
                ->where('cid = ? AND name = ?', $cid, $name));
        }
    }

    protected static function log($message)
    {
        $file = __DIR__ . '/cache/sync.log';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        @file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
            FILE_APPEND
        );
    }
}
