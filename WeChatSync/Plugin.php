<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Typecho 文章自动同步到微信公众号
 *
 * 发布文章时自动将图文推送到微信公众号草稿箱（可配置为自动发布）。
 * 兼容 Typecho 1.1（17.10.x）与 1.2.x。
 *
 * @package WeChatSync
 * @author  WorkBuddy
 * @version 1.1.1
 * @link    https://www.workbuddy.cn
 * @since   1.1.0
 * @dependence 1.1.0-*
 */
class WeChatSync_Plugin implements Typecho_Plugin_Interface
{
    /** 插件名 */
    const NAME = 'WeChatSync';

    /**
     * 激活
     */
    public static function activate()
    {
        $dir = __DIR__ . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 文章发布完成钩子：自动同步（1.1 与 1.2 句柄归一化后一致）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('WeChatSync_Plugin', 'onFinishPublish');

        // 发文页「选项」面板注入“同步到公众号”复选框
        Typecho_Plugin::factory('admin/write-post.php')->option = array('WeChatSync_Plugin', 'renderWriteOption');

        // 免登录的发布状态定时回查路由（密钥保护）
        try {
            Helper::addRoute(
                'wechat_sync_cron',
                '/wechat-sync/cron/[key]/',
                'WeChatSync_Cron',
                'run'
            );
        } catch (Exception $e) {
            // 路由已存在时忽略
        }

        // 前端请求开始时预载 Cron 类，确保独立路由在各版本都能被实例化
        Typecho_Plugin::factory('index.php')->begin = array('WeChatSync_Plugin', 'onFrontBegin');

        // 后台管理面板（挂在「管理」菜单组：控制台1 / 撰写2 / 管理3 / 设置4）
        if (class_exists('Helper')) {
            try {
                Helper::addPanel(
                    3,
                    self::NAME . '/panel.php',
                    '公众号同步',
                    '微信公众号同步状态',
                    'administrator'
                );
            } catch (Exception $e) {
                // 菜单已存在等情况忽略
            }
        }

        return _t('插件已启用，请填写 AppID / AppSecret 并确认服务器 IP 已加入公众号白名单');
    }

    /**
     * 禁用
     */
    public static function deactivate()
    {
        try {
            Helper::removePanel(3, self::NAME . '/panel.php');
        } catch (Exception $e) {
        }
        try {
            Helper::removeRoute('wechat_sync_cron');
        } catch (Exception $e) {
        }
    }

    /**
     * 插件配置面板
     *
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // ------- 凭据 -------
        $appid = new Typecho_Widget_Helper_Form_Element_Text(
            'appid',
            null,
            '',
            _t('AppID'),
            _t('公众号开发者 ID（开发 → 基本配置）。')
        );
        $appid->input->setAttribute('class', 'w-60');
        $form->addInput($appid);

        $secret = new Typecho_Widget_Helper_Form_Element_Text(
            'secret',
            null,
            '',
            _t('AppSecret'),
            _t('公众号开发者密码。仅用于服务器端换取 access_token，不会公开。')
        );
        $secret->input->setAttribute('class', 'w-60');
        $form->addInput($secret);

        // ------- 同步方式 -------
        $mode = new Typecho_Widget_Helper_Form_Element_Radio(
            'mode',
            array(
                0 => _t('仅保存到公众号草稿箱（推荐，人工在公众号后台确认后群发）'),
                1 => _t('自动调用发布接口直接群发'),
            ),
            0,
            _t('同步方式')
        );
        $form->addInput($mode);

        // ------- 发文页勾选默认值 -------
        $defaultChecked = new Typecho_Widget_Helper_Form_Element_Radio(
            'defaultChecked',
            array(1 => _t('默认勾选（发文页可逐篇取消）'), 0 => _t('默认不勾选（发文页可逐篇勾选）')),
            1,
            _t('发文页「同步到公众号」默认值'),
            _t('在撰写文章右侧「选项」中显示一个同步勾选框；非发文页途径（如接口发布）则按此默认值执行。')
        );
        $form->addInput($defaultChecked);

        // ------- 已发布文章更新时是否重新同步 -------
        $syncOnUpdate = new Typecho_Widget_Helper_Form_Element_Radio(
            'syncOnUpdate',
            array(0 => _t('不同步（仅首次发布时同步，推荐）'), 1 => _t('每次更新已发布文章都重新同步')),
            0,
            _t('更新已发布文章'),
            _t('勾选后，在发文页修改并重新发布一篇已同步的文章，也会再次推送到公众号。')
        );
        $form->addInput($syncOnUpdate);


        // ------- 正文图片转传 -------
        $uploadImages = new Typecho_Widget_Helper_Form_Element_Radio(
            'uploadImages',
            array(1 => _t('开启'), 0 => _t('关闭')),
            1,
            _t('正文图片转传微信'),
            _t('公众号图文正文仅支持微信域名图片。开启后会把正文外链图片转传到微信并替换链接（每张限 1MB，支持 png/jpeg/jpg/gif）。')
        );
        $form->addInput($uploadImages);

        // ------- 封面 -------
        $coverFromContent = new Typecho_Widget_Helper_Form_Element_Radio(
            'coverFromContent',
            array(1 => _t('开启'), 0 => _t('关闭')),
            1,
            _t('无封面时取正文首图'),
            _t('封面优先级：文章自定义字段 <code>wechat_cover</code>（图片URL） &gt; 正文第一张图。自动发布必须提供封面。')
        );
        $form->addInput($coverFromContent);

        // ------- 原文链接 -------
        $sourceUrl = new Typecho_Widget_Helper_Form_Element_Radio(
            'sourceUrl',
            array(1 => _t('开启'), 0 => _t('关闭')),
            1,
            _t('附加原文链接'),
            _t('在图文底部“阅读原文”处指向博客文章永久链接。')
        );
        $form->addInput($sourceUrl);

        // ------- 自动摘要 -------
        $autoDigest = new Typecho_Widget_Helper_Form_Element_Radio(
            'autoDigest',
            array(1 => _t('开启'), 0 => _t('关闭')),
            1,
            _t('自动生成摘要'),
            _t('无自定义字段 <code>wechat_digest</code> 时，从正文提取最多 120 字作为摘要。')
        );
        $form->addInput($autoDigest);

        // ------- 留言 / 作者 -------
        $openComment = new Typecho_Widget_Helper_Form_Element_Radio(
            'openComment',
            array(0 => _t('关闭'), 1 => _t('开启')),
            0,
            _t('开启图文留言'),
            _t('需公众号已开通图文留言/评论能力，否则接口会忽略该参数。')
        );
        $form->addInput($openComment);

        $author = new Typecho_Widget_Helper_Form_Element_Text(
            'author',
            null,
            '',
            _t('图文作者名'),
            _t('留空则使用文章作者的昵称。最多 8 个字符。')
        );
        $form->addInput($author);

        // ------- 草稿提醒通知 -------
        $notifyChannels = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'notifyChannels',
            array(
                'serverchan' => _t('Server 酱（微信，个人/未认证号推荐）'),
                'wecom'      => _t('企业微信群机器人'),
                'bark'       => _t('Bark（iOS）'),
                'webhook'    => _t('自定义 Webhook'),
                'wechat'     => _t('公众号客服消息（仅认证号，且接收人 48h 内有互动）'),
            ),
            array(),
            _t('草稿生成后的通知渠道'),
            _t('勾选后，文章同步为草稿（或自动发布失败转人工）时推送提醒，可多选。未认证/个人订阅号无法用客服消息，请选 Server 酱等前四项。')
        );
        $form->addInput($notifyChannels);

        $serverchanKey = new Typecho_Widget_Helper_Form_Element_Text(
            'serverchanKey',
            null,
            '',
            _t('Server 酱 SendKey'),
            _t('在 sct.ftqq.com 登录获取；以 SCT 开头自动走 Turbo 版，否则走旧版。')
        );
        $form->addInput($serverchanKey);

        $wecomWebhook = new Typecho_Widget_Helper_Form_Element_Text(
            'wecomWebhook',
            null,
            '',
            _t('企业微信群机器人 Webhook'),
            _t('形如 https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx。')
        );
        $form->addInput($wecomWebhook);

        $barkUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'barkUrl',
            null,
            '',
            _t('Bark 地址（含 Key）'),
            _t('形如 https://api.day.app/<你的Key>，将自动补 /push。')
        );
        $form->addInput($barkUrl);

        $webhookUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'webhookUrl',
            null,
            '',
            _t('自定义 Webhook URL'),
            _t('将以 JSON POST 发送 {title, content, link, source}，可对接自建服务/n8n/钉钉等。')
        );
        $form->addInput($webhookUrl);

        $notifyOpenid = new Typecho_Widget_Helper_Form_Element_Text(
            'notifyOpenid',
            null,
            '',
            _t('客服消息接收人 OpenID'),
            _t('仅在勾选「公众号客服消息」时需要；接收人须在近 48 小时内与公众号互动过，否则报 45015。')
        );
        $form->addInput($notifyOpenid);
    }

    /**
     * 个人配置（不使用）
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 前端请求开始（index.php begin 钩子）：预载 Cron 独立类
     */
    public static function onFrontBegin()
    {
        require_once __DIR__ . '/Cron.php';
    }

    /**
     * 发文页「选项」面板：注入“同步到公众号”勾选框
     * 该钩子位于主表单内，复选框会随 do=publish 一起提交。
     *
     * @param object $post 文章编辑组件（Widget_Contents_Post_Edit）
     */
    public static function renderWriteOption($post)
    {
        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg = $options->plugin(self::NAME);
        } catch (Exception $e) {
            return;
        }

        // 编辑已发布文章时，若其已同步，则依据“更新重新同步”配置决定初始勾选
        $alreadySynced = false;
        if ($post && $post->have()) {
            $db = Typecho_Db::get();
            $mark = $db->fetchRow($db->select('int_value')
                ->from('table.fields')
                ->where('cid = ? AND name = ?', intval($post->cid), 'wechat_synced'));
            $alreadySynced = !empty($mark);
        }

        $checked = intval($cfg->defaultChecked) === 1;
        if ($alreadySynced && intval($cfg->syncOnUpdate) !== 1) {
            $checked = false;
        }

        echo '<section class="typecho-post-option">';
        echo '<label class="typecho-label">' . htmlspecialchars(_t('微信公众号')) . '</label>';
        echo '<ul><li>';
        // 标记本次发布来自发文页（区别于接口/XML-RPC 等途径）
        echo '<input type="hidden" name="wechat_sync_form" value="1">';
        echo '<input type="checkbox" id="wechat_sync" name="wechat_sync" value="1"'
            . ($checked ? ' checked="checked"' : '') . '>';
        echo ' <label for="wechat_sync">' . htmlspecialchars(_t('发布时同步到公众号')) . '</label>';
        echo '</li></ul>';
        echo '</section>';
    }

    /**
     * finishPublish 钩子回调
     *
     * @param array   $contents 发布时的内容数组
     * @param object  $post     文章组件（已 push，含 cid/title/permalink/content）
     */
    public static function onFinishPublish($contents, $post)
    {
        require_once __DIR__ . '/Api.php';
        require_once __DIR__ . '/Sync.php';

        // 私密 / 隐藏 / 待审核文章不自动同步
        $status = isset($post->status) ? $post->status : (isset($contents['status']) ? $contents['status'] : 'publish');
        if ($status !== 'publish') {
            return;
        }

        $cid = intval($post->cid);
        if ($cid <= 0) {
            return;
        }

        try {
            $options = Typecho_Widget::widget('Widget_Options');
            $cfg = $options->plugin(self::NAME);
        } catch (Exception $e) {
            return;
        }

        // 判定本次是否需要同步（Edit 组件的 request 已包含表单字段）
        $request = isset($post->request) ? $post->request : null;
        $fromWritePage = $request && $request->get('wechat_sync_form') === '1';
        $checkbox = $request ? $request->get('wechat_sync') : null;

        // 该文章此前是否已同步过
        $db = Typecho_Db::get();
        $markRow = $db->fetchRow($db->select('int_value')
            ->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, 'wechat_synced'));
        $alreadySynced = !empty($markRow);

        if ($fromWritePage) {
            // 发文页：完全尊重复选框（默认值由 syncOnUpdate / defaultChecked 在渲染时决定）
            $wantSync = ($checkbox === '1');
        } else {
            // 接口 / XML-RPC 等非发文页途径：按全局默认值；已同步且关闭“更新重同步”则跳过
            $wantSync = (intval($cfg->defaultChecked) === 1);
            if ($alreadySynced && intval($cfg->syncOnUpdate) !== 1) {
                $wantSync = false;
            }
        }

        if (!$wantSync) {
            return;
        }

        try {
            $sync = new WeChatSync_Sync();
            // 已同步过仍要再推（如发文页重新勾选），需强制覆盖“仅同步一次”保护
            $sync->run($post, $alreadySynced);
        } catch (Exception $e) {
            // 自动同步失败不阻断文章发布流程，仅记录日志并在后台提示
            self::logError($cid, $e->getMessage());
            try {
                if (class_exists('Widget_Notice')) {
                    Typecho_Widget::widget('Widget_Notice')->set(
                        _t('公众号同步失败：%s（详见「管理 → 公众号同步」）', $e->getMessage()),
                        'notice'
                    );
                }
            } catch (Exception $ne) {
            }
        }
    }

    /**
     * 记录自动同步错误到日志文件
     *
     * @param int    $cid
     * @param string $message
     */
    protected static function logError($cid, $message)
    {
        $file = __DIR__ . '/cache/sync.log';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        @file_put_contents(
            $file,
            '[' . date('Y-m-d H:i:s') . '] AUTO_FAIL cid=' . $cid . ' ' . $message . "\n",
            FILE_APPEND
        );
    }

    /**
     * 供 Action / 面板调用：同步指定 cid
     *
     * @param int  $cid
     * @param bool $force
     * @return array
     * @throws Exception
     */
    public static function syncCid($cid, $force = false)
    {
        require_once __DIR__ . '/Api.php';
        require_once __DIR__ . '/Sync.php';

        $post = Helper::widgetById('Contents', intval($cid));
        if (!$post || !$post->have()) {
            throw new Exception('文章不存在：cid=' . $cid);
        }
        if ($post->type !== 'post' || $post->status !== 'publish') {
            throw new Exception('仅支持对「已发布的文章(post)」执行同步');
        }

        $sync = new WeChatSync_Sync();
        return $sync->run($post, $force);
    }

    /**
     * 测试连接：换取 access_token，并在自动发布模式下探测发布权限
     *
     * @return array {token, can_publish, mode}
     * @throws Exception
     */
    public static function testConnection()
    {
        require_once __DIR__ . '/Api.php';
        $options = Typecho_Widget::widget('Widget_Options');
        $cfg = $options->plugin(self::NAME);
        $api = new WeChatSync_Api($cfg->appid, $cfg->secret, __DIR__ . '/cache');
        $token = $api->refreshToken();

        $mode = intval($cfg->mode) === 1 ? 1 : 0;
        $canPublish = null; // null=草稿模式无需探测

        if ($mode === 1) {
            try {
                $canPublish = $api->canPublish() ? 1 : 0;
            } catch (Exception $e) {
                $canPublish = null; // 检测本身遇到其他错误，不阻断 token 成功结论
            }
        }

        return array('token' => $token, 'can_publish' => $canPublish, 'mode' => $mode);
    }

    /**
     * 发送一条测试通知（按已配置渠道）
     *
     * @return int 成功渠道数
     * @throws Exception
     */
    public static function testNotify()
    {
        require_once __DIR__ . '/Api.php';
        require_once __DIR__ . '/Notifier.php';

        $options = Typecho_Widget::widget('Widget_Options');
        $cfg = $options->plugin(self::NAME);
        $api = new WeChatSync_Api($cfg->appid, $cfg->secret, __DIR__ . '/cache');
        $notifier = new WeChatSync_Notifier($cfg, $api);

        return $notifier->notify(
            '【测试】WeChatSync 通知正常',
            "这是一条来自 Typecho 公众号同步插件的测试通知。\n时间：" . date('Y-m-d H:i:s'),
            'https://mp.weixin.qq.com/'
        );
    }
}
