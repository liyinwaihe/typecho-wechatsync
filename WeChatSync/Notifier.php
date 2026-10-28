<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 草稿 / 发布结果通知
 *
 * 多渠道，任一渠道失败仅记录日志、不影响同步主流程：
 * - 公众号客服消息 message/custom/send（需认证号 + 接收人 48h 内互动 + OpenID）
 * - Server 酱（sctapi / sc.ftqq，个人订阅号也能收到微信提醒，推荐未认证号使用）
 * - 企业微信群机器人 webhook
 * - Bark（iOS 推送）
 * - 自定义 JSON Webhook
 *
 * @package WeChatSync
 */
class WeChatSync_Notifier
{
    protected $cfg;
    protected $api;
    protected $logFile;

    /**
     * @param object               $cfg 插件配置
     * @param WeChatSync_Api|null  $api
     */
    public function __construct($cfg, $api = null)
    {
        $this->cfg = $cfg;
        $this->api = $api;
        $this->logFile = __DIR__ . '/cache/sync.log';
    }

    /**
     * 发送通知
     *
     * @param string $title   标题（单行、简短）
     * @param string $content 正文（可含链接）
     * @param string $link    相关链接（可空）
     * @return int 成功渠道数
     */
    public function notify($title, $content, $link = '')
    {
        $channels = $this->enabledChannels();
        if (empty($channels)) {
            return 0;
        }

        // body 保持纯正文；链接由各渠道按自身能力渲染（避免重复）
        $body = $content;

        $ok = 0;
        foreach ($channels as $channel) {
            try {
                $success = $this->dispatch($channel, $title, $body, $link);
                if ($success) {
                    $ok++;
                }
            } catch (Exception $e) {
                $this->log('NOTIFY_FAIL channel=' . $channel . ' ' . $e->getMessage());
            }
        }
        if ($ok > 0) {
            $this->log('NOTIFY_SENT channels=' . $ok . ' title=' . $title);
        }
        return $ok;
    }

    /**
     * 解析已启用渠道（兼容数组、逗号/空格分隔字符串、JSON 字符串等存储形态）
     *
     * @return array
     */
    protected function enabledChannels()
    {
        $valid = array('wechat', 'serverchan', 'wecom', 'bark', 'webhook');
        $raw = isset($this->cfg->notifyChannels) ? $this->cfg->notifyChannels : '';

        if (is_array($raw)) {
            $list = $raw;
        } else {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return array();
            }
            // 兼容以 JSON 数组形式存储（如 ["wecom","webhook"]）
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        $picked = array();
        foreach ((array) $list as $item) {
            $item = trim((string) $item);
            if (in_array($item, $valid, true) && !in_array($item, $picked, true)) {
                $picked[] = $item;
            }
        }
        return $picked;
    }

    /**
     * 分发到具体渠道
     *
     * @param string $channel
     * @param string $title
     * @param string $body
     * @param string $link
     * @return bool
     * @throws Exception
     */
    protected function dispatch($channel, $title, $body, $link)
    {
        switch ($channel) {
            case 'wechat':
                $openId = trim((string) $this->cfg->notifyOpenid);
                if ($openId === '' || !$this->api) {
                    throw new Exception('客服消息缺少 OpenID 或 API 客户端');
                }
                // 客服文本不保证标题样式，标题前置一行
                $this->api->sendCustomText($openId, $title . "\n" . $body);
                return true;

            case 'serverchan':
                $key = trim((string) $this->cfg->serverchanKey);
                if ($key === '') {
                    throw new Exception('Server 酱 SendKey 未配置');
                }
                // 兼容 Turbo 版 sctapi.ftqq.com 与旧版 sc.ftqq.com（以 SCT 开头用新版）
                $host = (strpos($key, 'SCT') === 0) ? 'sctapi.ftqq.com' : 'sc.ftqq.com';
                $payload = array('title' => $title, 'desp' => $body);
                $this->httpJson('https://' . $host . '/' . rawurlencode($key) . '.send', $payload, 'serverchan');
                return true;

            case 'wecom':
                $url = trim((string) $this->cfg->wecomWebhook);
                if ($url === '') {
                    throw new Exception('企业微信机器人 Webhook 未配置');
                }
                // markdown 消息可点击 URL，长度上限约 4096
                $md = $body;
                if ($link !== '') {
                    $md .= "\n[点此前往](" . $link . ")";
                }
                $this->httpJson($url, array(
                    'msgtype'  => 'markdown',
                    'markdown' => array('content' => "**" . $title . "**\n" . $md),
                ), 'wecom');
                return true;

            case 'bark':
                $url = trim((string) $this->cfg->barkUrl);
                if ($url === '') {
                    throw new Exception('Bark 地址未配置');
                }
                // 形如 https://api.day.app/<key>；自动补 /push
                if (substr($url, -5) !== '/push') {
                    $url = rtrim($url, '/') . '/push';
                }
                $this->httpJson($url, array(
                    'title' => $title,
                    'body'  => $body,
                    'url'   => $link,
                ), 'bark');
                return true;

            case 'webhook':
                $url = trim((string) $this->cfg->webhookUrl);
                if ($url === '') {
                    throw new Exception('自定义 Webhook 未配置');
                }
                $payload = $this->buildWebhookPayload($url, $title, $body, $link);
                $kind = $payload['_kind'];
                unset($payload['_kind']);
                $this->httpJson($url, $payload, $kind);
                return true;
        }
        return false;
    }

    /**
     * 根据 webhook 地址特征构造兼容载荷：
     * - 企业微信群机器人（qyapi.weixin.qq.com/.../webhook/send）→ {msgtype:markdown,...}
     * - 钉钉机器人（oapi.dingtalk.com/robot/send）→ {msgtype:markdown,...}
     * - 其他 → 通用 JSON {title,content,link,source}
     *
     * @return array
     */
    protected function buildWebhookPayload($url, $title, $body, $link)
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');

        if (strpos($host, 'qyapi.weixin.qq.com') !== false
            && stripos($url, '/webhook/send') !== false) {
            // 企业微信群机器人
            $md = "**" . $title . "**\n" . $body;
            if ($link !== '') {
                $md .= "\n[点此前往](" . $link . ")";
            }
            return array(
                '_kind'    => 'wecom',
                'msgtype'  => 'markdown',
                'markdown' => array('content' => $md),
            );
        }

        if (strpos($host, 'oapi.dingtalk.com') !== false
            && stripos($url, '/robot/send') !== false) {
            // 钉钉群机器人
            $md = "### " . $title . "\n\n" . $body;
            if ($link !== '') {
                $md .= "\n\n[点此前往](" . $link . ")";
            }
            return array(
                '_kind'    => 'dingtalk',
                'msgtype'  => 'markdown',
                'markdown' => array('title' => $title, 'text' => $md),
            );
        }

        // 通用自定义端点
        return array(
            '_kind'  => 'webhook',
            'title'   => $title,
            'content' => $body,
            'link'    => $link,
            'source'  => 'WeChatSync',
        );
    }

    /**
     * 发送 JSON POST 并按渠道校验响应
     *
     * @param string $url
     * @param array  $payload
     * @param string $channel
     * @throws Exception
     */
    protected function httpJson($url, array $payload, $channel = 'webhook')
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json; charset=utf-8'),
        ));
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('网络错误：' . $err);
        }
        if ($http >= 400) {
            throw new Exception('HTTP ' . $http . '：' . substr($raw, 0, 150));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return; // 无法解析则以 HTTP 状态为准
        }

        $msg = isset($data['message']) ? $data['message']
            : (isset($data['errmsg']) ? $data['errmsg'] : '未知错误');

        switch ($channel) {
            case 'wecom':
            case 'dingtalk':
                // 成功 {"errcode":0,"errmsg":"ok"}（企业微信、钉钉一致）
                if (isset($data['errcode']) && intval($data['errcode']) !== 0) {
                    $label = $channel === 'dingtalk' ? '钉钉' : '企业微信';
                    throw new Exception('[' . $label . ' ' . $data['errcode'] . '] ' . $msg);
                }
                break;
            case 'serverchan':
                // 成功 {"code":0,...}；非 0 即失败
                if (isset($data['code']) && intval($data['code']) !== 0) {
                    throw new Exception('[Server酱 ' . $data['code'] . '] ' . $msg);
                }
                break;
            case 'bark':
                // 成功 {"code":200,"message":"success"}；仅 200 视为成功
                if (isset($data['code']) && intval($data['code']) !== 200) {
                    throw new Exception('[Bark ' . $data['code'] . '] ' . $msg);
                }
                break;
            default:
                // 自定义 webhook：有 errcode 且非 0 才算失败，其余以 HTTP 状态为准
                if (isset($data['errcode']) && intval($data['errcode']) !== 0) {
                    throw new Exception('[' . $data['errcode'] . '] ' . $msg);
                }
        }
    }

    protected function log($message)
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $this->logFile,
            '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
            FILE_APPEND
        );
    }
}
