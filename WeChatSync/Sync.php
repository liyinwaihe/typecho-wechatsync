<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 同步服务：把一篇 Typecho 文章转换并推送到微信公众号草稿箱 / 发布
 *
 * @package WeChatSync
 */
class WeChatSync_Sync
{
    protected $db;
    protected $options;
    protected $cfg;
    protected $api;
    protected $notifier;
    protected $logFile;

    public function __construct()
    {
        require_once __DIR__ . '/Notifier.php';

        $this->db      = (class_exists('Typecho_Db')) ? Typecho_Db::get() : \Typecho\Db::get();
        $this->options = (class_exists('Typecho_Widget'))
            ? Typecho_Widget::widget('Widget_Options')
            : \Typecho\Widget::widget(\Widget\Options::class);

        try {
            $this->cfg = $this->options->plugin('WeChatSync');
        } catch (Exception $e) {
            // 插件尚未保存配置时使用空配置（后续由字段默认值兜底）
            $configClass = class_exists('Typecho_Config') ? 'Typecho_Config' : '\Typecho\Config';
            $this->cfg = new $configClass(array());
        }

        $cacheDir = __DIR__ . '/cache';
        $this->api = new WeChatSync_Api($this->cfg->appid, $this->cfg->secret, $cacheDir);
        $this->notifier = new WeChatSync_Notifier($this->cfg, $this->api);
        $this->logFile = $cacheDir . '/sync.log';
    }

    /**
     * 执行同步
     *
     * @param object $post 已 push 的文章组件（含 cid/title/permalink/content）
     * @param bool   $force 强制重新同步（忽略已同步标记）
     * @return array 成功结果
     * @throws Exception
     */
    public function run($post, $force = false)
    {
        $cid = intval($post->cid);

        if (!$force && $this->getSynced($cid)) {
            throw new Exception('该文章此前已同步，为避免重复群发，已自动跳过（可在管理面板强制同步）');
        }

        $title = trim($post->title);
        if ($title === '') {
            throw new Exception('文章标题为空，无法同步');
        }

        // 正文 HTML：$post->content 已完成 Markdown 解析
        $contentHtml = $post->content;
        $contentHtml = $this->processImages($contentHtml);

        // 封面
        $thumbMediaId = '';
        $thumbResult = $this->resolveCover($post, $contentHtml);
        if ($thumbResult !== null) {
            list($thumbMediaId) = $thumbResult;
        }

        // 作者 / 原文链接 / 摘要
        $author = trim($this->cfg->author);
        if ($author === '') {
            $author = isset($post->author->screenName) ? $post->author->screenName : '';
        }

        $sourceUrl = '';
        if (intval($this->cfg->sourceUrl) === 1) {
            $sourceUrl = $post->permalink;
        }

        $digest = $this->buildDigest($post);

        $article = array(
            'title'              => $this->truncate($title, 64, ''),
            'author'             => $this->truncate($author, 8, ''),
            'digest'             => $digest,
            'content'            => $contentHtml,
            'content_source_url' => $sourceUrl,
            'need_open_comment'  => intval($this->cfg->openComment) === 1 ? 1 : 0,
            'only_fans_can_comment' => 0,
        );
        if ($thumbMediaId !== '') {
            $article['thumb_media_id'] = $thumbMediaId;
        }

        $mediaId = $this->api->addDraft($article);

        // 草稿已在公众号侧创建成功，先落库，避免后续发布失败导致草稿“失联”
        $result = array(
            'media_id'    => $mediaId,
            'publish_id'  => '',
            'published'   => 0,
            'state'       => 'draft',
            'fail_msg'    => '',
        );
        $this->setSynced($cid, $result);

        // 草稿模式：草稿生成即通知（链接指向公众号后台，便于手动群发）
        if (intval($this->cfg->mode) !== 1) {
            $this->notifyDraftReady($post, '文章已同步到公众号草稿箱，请登录公众平台核对后群发');
        }

        // 0=仅存草稿箱；1=自动发布
        if (intval($this->cfg->mode) === 1) {
            try {
                $publishId = $this->api->submit($mediaId);
                $result['publish_id'] = $publishId;
                $result['published']  = 1;
                $result['state']      = 'publishing';
                $this->setSynced($cid, $result);
                $this->log('PUBLISH_SUBMIT cid=' . $cid . ' publish_id=' . $publishId);
                $this->notifyDraftReady($post, '文章已提交公众号群发，正在发布中');
            } catch (WeChatSync_Exception $e) {
                // 典型为 48001：账号无发布权限（未认证/个人号）。草稿保留，转人工群发
                $hint = $e->getWxCode() == 48001
                    ? '当前公众号无 API 群发权限（未认证/个人主体，2025-07 起被回收），草稿已生成，请在公众号后台手动群发'
                    : '草稿已生成，但自动发布失败：' . $e->getMessage() . '（可在公众号后台手动群发）';
                $result['state']    = 'draft_ready';
                $result['fail_msg'] = $hint;
                $this->setSynced($cid, $result);
                $this->log('PUBLISH_SKIP cid=' . $cid . ' code=' . $e->getWxCode() . ' ' . $e->getMessage());
                $this->notifyDraftReady($post, $hint);
                // 不向上抛致命错误：草稿同步本身已成功
                return $result;
            }
        }

        $this->log('SUCCESS cid=' . $cid . ' title=' . $title
            . ' media=' . $mediaId . ' published=' . $result['published']);

        return $result;
    }

    /**
     * 发送“草稿/发布”提醒（受总开关与渠道配置控制，失败不影响主流程）
     *
     * @param object $post
     * @param string $reason
     */
    protected function notifyDraftReady($post, $reason)
    {
        // 是否通知由“是否勾选通知渠道”决定；无渠道时 Notifier 直接返回
        $title = '【公众号草稿】' . $this->truncate($post->title, 50, '');
        $lines = array(
            $reason,
            '文章：' . $post->title,
        );
        if (intval($this->cfg->sourceUrl) === 1) {
            $lines[] = '原文：' . $post->permalink;
        }
        // 草稿箱无法深链，直接给公众平台首页（进去即可看到待群发草稿）
        $this->notifier->notify($title, implode("\n", $lines), 'https://mp.weixin.qq.com/');
    }
    /* -----------------------------------------------------------------
     * 封面处理
     * --------------------------------------------------------------- */

    /**
     * 返回 [thumbMediaId, coverUrl] 或 null（无封面）
     *
     * @param object $post
     * @param string $contentHtml
     * @return array|null
     * @throws Exception
     */
    protected function resolveCover($post, $contentHtml)
    {
        $coverUrl = '';
        $tmp = null;

        // 1) 自定义字段 wechat_cover
        $fieldCover = $this->getCustomField(intval($post->cid), 'wechat_cover');
        if ($fieldCover !== '') {
            $coverUrl = $fieldCover;
        }

        // 2) 正文第一张图
        if ($coverUrl === '' && intval($this->cfg->coverFromContent) === 1
            && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
            $coverUrl = $m[1];
        }

        if ($coverUrl === '') {
            // 无封面：仅草稿模式允许（发布接口强制要求封面）
            if (intval($this->cfg->mode) === 1) {
                throw new Exception('自动发布需要封面图：请设置文章自定义字段 wechat_cover，或开启「取正文首图作封面」');
            }
            return null;
        }

        // 下载为本地文件后作为永久图片素材上传
        $tmp = $this->downloadToTemp($coverUrl);
        try {
            $mat = $this->api->addMaterialImage($tmp);
        } catch (Exception $e) {
            @unlink($tmp);
            throw $e;
        }
        @unlink($tmp);

        return array($mat['media_id'], isset($mat['url']) ? $mat['url'] : $coverUrl);
    }

    /* -----------------------------------------------------------------
     * 正文图片：转传到微信域名并替换 src
     * --------------------------------------------------------------- */

    protected function processImages($html)
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        $uploadImages = intval($this->cfg->uploadImages) === 1;

        return preg_replace_callback('/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
            function ($matches) use ($uploadImages) {
                $before = $matches[1];
                $url    = trim($matches[2]);
                $after  = $matches[3];
                $newUrl = $url;

                if ($uploadImages && $this->isTransferable($url)) {
                    try {
                        $tmp = $this->downloadToTemp($url);
                        $newUrl = $this->api->uploadImage($tmp);
                        @unlink($tmp);
                    } catch (Exception $e) {
                        $this->log('IMAGE_FAIL ' . $url . ' => ' . $e->getMessage());
                        $newUrl = $url; // 转传失败则保留原图，避免整篇失败
                    }
                }
                return '<img' . $before . 'src="' . htmlspecialchars($newUrl, ENT_QUOTES) . '"' . $after . '>';
            }, $html);
    }

    /**
     * 是否需要转传：微信域名图片无需处理；data URI / 协议相对地址跳过
     *
     * @param string $url
     * @return bool
     */
    protected function isTransferable($url)
    {
        if ($url === '' || strpos($url, 'data:') === 0 || strpos($url, '//') === 0) {
            return false;
        }
        if (preg_match('#^https?://([^/]+)#i', $url, $m)) {
            $host = strtolower($m[1]);
            if (strpos($host, 'mmbiz.qpic.cn') !== false
                || strpos($host, 'wx.qq.com') !== false) {
                return false;
            }
        }
        return (bool) preg_match('#^https?://#i', $url);
    }

    /* -----------------------------------------------------------------
     * 摘要
     * --------------------------------------------------------------- */

    protected function buildDigest($post)
    {
        $custom = $this->getCustomField(intval($post->cid), 'wechat_digest');
        if ($custom !== '') {
            return $this->truncate(trim(strip_tags($custom)), 120);
        }
        if (intval($this->cfg->autoDigest) === 1) {
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($post->content)));
            return $this->truncate($plain, 120);
        }
        return '';
    }

    /* -----------------------------------------------------------------
     * 下载与临时文件
     * --------------------------------------------------------------- */

    protected function downloadToTemp($url)
    {
        $dir = __DIR__ . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ch = curl_init($url);
        $tmp = tempnam($dir, 'img_');
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, array(
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WeChatSync/1.0)',
        ));
        $ok = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $http >= 400 || filesize($tmp) === 0) {
            @unlink($tmp);
            throw new Exception('图片下载失败（HTTP ' . $http . '）' . ($err !== '' ? '：' . $err : '') . ' ' . $url);
        }
        return $tmp;
    }

    /* -----------------------------------------------------------------
     * 同步状态（自定义字段 table.fields）
     * --------------------------------------------------------------- */

    protected function getSynced($cid)
    {
        $row = $this->db->fetchRow($this->db->select('int_value', 'str_value')
            ->from('table.fields')->where('cid = ? AND name = ?', $cid, 'wechat_media_id'));
        if (empty($row)) {
            return null;
        }
        return !empty($row['str_value']) ? $row['str_value'] : $row['int_value'];
    }

    protected function setSynced($cid, array $result)
    {
        $this->upsertField($cid, 'wechat_media_id', $result['media_id'], 'str');
        $this->upsertField($cid, 'wechat_publish_id', $result['publish_id'], 'str');
        $this->upsertField($cid, 'wechat_published', $result['published'], 'int');
        $this->upsertField($cid, 'wechat_synced', time(), 'int');

        $state = isset($result['state']) ? $result['state']
            : (!empty($result['published']) ? 'publishing' : 'draft');
        $failMsg = isset($result['fail_msg']) ? $result['fail_msg'] : '';

        // 统一维护发布状态字段（草稿/待手动群发/发布中）
        $this->upsertField($cid, 'wechat_publish_state', $state, 'str');
        $this->upsertField($cid, 'wechat_fail_msg', $failMsg, 'str');

        if (!empty($result['published'])) {
            // 自动发布已提交：初始化回查计数与结果字段
            $this->upsertField($cid, 'wechat_poll_count', 0, 'int');
            $this->upsertField($cid, 'wechat_poll_updated', time(), 'int');
            $this->upsertField($cid, 'wechat_article_id', '', 'str');
            $this->upsertField($cid, 'wechat_article_url', '', 'str');
        }
    }

    protected function getCustomField($cid, $name)
    {
        $row = $this->db->fetchRow($this->db->select('str_value', 'int_value')
            ->from('table.fields')->where('cid = ? AND name = ?', $cid, $name));
        if (empty($row)) {
            return '';
        }
        return isset($row['str_value']) && $row['str_value'] !== null
            ? $row['str_value']
            : (isset($row['int_value']) ? strval($row['int_value']) : '');
    }

    protected function upsertField($cid, $name, $value, $type)
    {
        $exist = $this->db->fetchRow($this->db->select('cid')->from('table.fields')
            ->where('cid = ? AND name = ?', $cid, $name));

        $strVal = ($type === 'str') ? $value : null;
        $intVal = ($type === 'int') ? intval($value) : 0;

        if (empty($exist)) {
            $this->db->query($this->db->insert('table.fields')->rows(array(
                'cid'         => $cid,
                'name'        => $name,
                'type'        => $type === 'str' ? 'str' : 'int',
                'str_value'   => $strVal,
                'int_value'   => $intVal,
                'float_value' => 0,
            )));
        } else {
            $this->db->query($this->db->update('table.fields')
                ->rows(array(
                    'type'      => $type === 'str' ? 'str' : 'int',
                    'str_value' => $strVal,
                    'int_value' => $intVal,
                ))
                ->where('cid = ? AND name = ?', $cid, $name));
        }
    }

    /* -----------------------------------------------------------------
     * 工具
     * --------------------------------------------------------------- */

    protected function truncate($text, $length, $suffix = '...')
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        // 微信按字符计；中文环境用 mb_substr
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $length) {
            return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
        }
        if (!function_exists('mb_strlen') && strlen($text) > $length * 3) {
            return substr($text, 0, $length * 3) . $suffix;
        }
        return $text;
    }

    protected function log($message)
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}
