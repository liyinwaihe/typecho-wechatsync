<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 携带微信错误码的异常
 */
class WeChatSync_Exception extends Exception
{
    protected $wxCode;

    public function __construct($message, $wxCode = 0, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->wxCode = $wxCode;
    }

    public function getWxCode()
    {
        return $this->wxCode;
    }
}

/**
 * 微信公众平台「发布能力（草稿箱 / 发布）」接口封装
 *
 * 仅依赖 PHP cURL 扩展，兼容 Typecho 1.1（PHP 5.5+）与 1.2.x（PHP 7.2+）。
 * access_token 以文件形式缓存于插件 cache 目录。
 *
 * @package WeChatSync
 * @author  WorkBuddy
 */
class WeChatSync_Api
{
    const BASE = 'https://api.weixin.qq.com/cgi-bin/';

    protected $appid;
    protected $secret;
    protected $cacheDir;

    public function __construct($appid, $secret, $cacheDir)
    {
        $this->appid    = trim((string) $appid);
        $this->secret   = trim((string) $secret);
        $this->cacheDir = rtrim($cacheDir, '/\\');
    }

    /**
     * 获取（必要时刷新）access_token
     *
     * @return string
     * @throws Exception
     */
    public function getToken()
    {
        if ($this->appid === '' || $this->secret === '') {
            throw new Exception('AppID / AppSecret 未配置');
        }

        $file = $this->cacheDir . '/token.json';
        $now  = time();

        if (is_readable($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && !empty($data['access_token'])
                && !empty($data['expires_at']) && $data['expires_at'] - 300 > $now) {
                return $data['access_token'];
            }
        }

        $url = self::BASE . 'token?grant_type=client_credential&appid='
            . urlencode($this->appid) . '&secret=' . urlencode($this->secret);

        $res = $this->httpGetJson($url);
        if (empty($res['access_token'])) {
            throw new Exception($this->explain($res, '获取 access_token 失败'));
        }

        $payload = array(
            'access_token' => $res['access_token'],
            'expires_at'   => $now + intval($res['expires_in'])
        );
        $this->writableCacheDir();
        @file_put_contents($file, json_encode($payload));

        return $res['access_token'];
    }

    /**
     * 强制刷新 access_token（token 失效重试时使用）
     *
     * @return string
     * @throws Exception
     */
    public function refreshToken()
    {
        @unlink($this->cacheDir . '/token.json');
        return $this->getToken();
    }

    /**
     * 新建草稿，返回 media_id
     * 文档：/cgi-bin/draft/add
     *
     * @param array $article 单篇图文内容
     * @return string media_id
     * @throws Exception
     */
    public function addDraft(array $article)
    {
        $body = json_encode(array('articles' => array($article)), JSON_UNESCAPED_UNICODE);

        $res = $this->callWithRetry('draft/add', $body);
        if (empty($res['media_id'])) {
            throw new Exception($this->explain($res, '新建草稿失败'));
        }
        return $res['media_id'];
    }

    /**
     * 发布草稿（提交群发发布），返回 publish_id
     * 文档：/cgi-bin/freepublish/submit
     *
     * @param string $mediaId
     * @return string publish_id
     * @throws Exception
     */
    public function submit($mediaId)
    {
        $body = json_encode(array('media_id' => $mediaId), JSON_UNESCAPED_UNICODE);
        $res  = $this->callWithRetry('freepublish/submit', $body);

        if (empty($res['publish_id'])) {
            $code = isset($res['errcode']) ? intval($res['errcode']) : 0;
            throw new WeChatSync_Exception($this->explain($res, '提交发布失败'), $code);
        }
        return strval($res['publish_id']);
    }

    /**
     * 只读探测当前账号是否具备 freepublish（发布）接口权限
     *
     * 2025-07 起个人主体、未认证企业等账号被回收该权限（返回 48001）。
     * 用只读的 batchget 接口探测，不产生任何发布副作用。
     *
     * @return bool true=具备发布权限
     * @throws Exception 当遇到“无权限”以外的错误时抛出，便于排查
     */
    public function canPublish()
    {
        $body = json_encode(array('offset' => 0, 'count' => 1, 'no_content' => 1), JSON_UNESCAPED_UNICODE);
        $res  = $this->callWithRetry('freepublish/batchget', $body);

        $code = isset($res['errcode']) ? intval($res['errcode']) : 0;
        if ($code === 0) {
            return true;
        }
        if ($code === 48001) {
            return false;
        }
        // 其他错误（如频率限制）不当作“无权限”，交由上层提示
        throw new WeChatSync_Exception($this->explain($res, '发布权限检测失败'), $code);
    }

    /**
     * 查询发布状态
     * 文档：/cgi-bin/freepublish/get
     *
     * @param string $publishId
     * @return array
     * @throws Exception
     */
    public function getPublishStatus($publishId)
    {
        $body = json_encode(array('publish_id' => $publishId), JSON_UNESCAPED_UNICODE);
        return $this->callWithRetry('freepublish/get', $body);
    }

    /**
     * 上传图文内容中的图片，返回可用于正文的微信域名 URL
     * 文档：/cgi-bin/media/uploadimg
     *
     * @param string $filePath 本地文件绝对路径
     * @return string
     * @throws Exception
     */
    public function uploadImage($filePath)
    {
        $token = $this->getToken();
        $url   = self::BASE . 'media/uploadimg?access_token=' . urlencode($token);
        $res   = $this->httpPostMultipart($url, $filePath, 'media', $token, 'media/uploadimg');

        if (empty($res['url'])) {
            throw new Exception($this->explain($res, '正文图片转传失败'));
        }
        return $res['url'];
    }

    /**
     * 新增永久图片素材（用作图文封面 thumb_media_id），返回 media_id 与 url
     * 文档：/cgi-bin/material/add_material?type=image
     *
     * @param string $filePath
     * @return array {media_id, url}
     * @throws Exception
     */
    public function addMaterialImage($filePath)
    {
        $token = $this->getToken();
        $url   = self::BASE . 'material/add_material?access_token='
            . urlencode($token) . '&type=image';
        $res   = $this->httpPostMultipart($url, $filePath, 'media', $token,
            'material/add_material', 'image');

        if (empty($res['media_id'])) {
            throw new Exception($this->explain($res, '永久封面素材上传失败'));
        }
        return $res;
    }

    /**
     * 发送客服文本消息（仅认证服务号 / 认证订阅号，且用户 48 小时内有互动）
     * 文档：/cgi-bin/message/custom/send
     *
     * @param string $openId  接收人 OpenID
     * @param string $content 文本内容（URL 会被微信自动识别为可点链接）
     * @throws WeChatSync_Exception
     */
    public function sendCustomText($openId, $content)
    {
        $body = json_encode(array(
            'touser'  => $openId,
            'msgtype' => 'text',
            'text'    => array('content' => $content),
        ), JSON_UNESCAPED_UNICODE);

        $res = $this->callWithRetry('message/custom/send', $body);
        $code = isset($res['errcode']) ? intval($res['errcode']) : 0;
        if ($code !== 0) {
            // 45015: 用户48小时内未互动；48001: 无客服消息权限（未认证/个人号）
            throw new WeChatSync_Exception($this->explain($res, '客服消息发送失败'), $code);
        }
        return true;
    }

    /**
     * 调用 JSON POST 接口；遇到 40001/42001/40014 自动刷新 token 重试一次
     *
     * @param string $endpoint
     * @param string $jsonBody
     * @return array
     * @throws Exception
     */
    protected function callWithRetry($endpoint, $jsonBody)
    {
        $token = $this->getToken();
        $url   = self::BASE . $endpoint . '?access_token=' . urlencode($token);
        $res   = $this->httpPostJson($url, $jsonBody);

        if (isset($res['errcode']) && in_array(intval($res['errcode']), array(40001, 42001, 40014), true)) {
            $token = $this->refreshToken();
            $url   = self::BASE . $endpoint . '?access_token=' . urlencode($token);
            $res   = $this->httpPostJson($url, $jsonBody);
        }
        return $res;
    }

    /* -----------------------------------------------------------------
     * HTTP 基础方法
     * --------------------------------------------------------------- */

    protected function httpGetJson($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));
        return $this->execJson($ch);
    }

    protected function httpPostJson($url, $json)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json; charset=utf-8'),
        ));
        return $this->execJson($ch);
    }

    /**
     * 推断上传文件的 MIME 类型（微信 multipart 上传需与真实内容一致）
     *
     * @param string $filePath
     * @return string
     */
    protected function mimeOf($filePath)
    {
        static $map = array(
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
        );

        // 1) 文件头嗅探（最可靠）
        $fh = @fopen($filePath, 'rb');
        if ($fh) {
            $head = (string) fread($fh, 12);
            fclose($fh);
            if (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
                return 'image/png';
            }
            if (substr($head, 0, 3) === "\xff\xd8\xff") {
                return 'image/jpeg';
            }
            if (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') {
                return 'image/gif';
            }
        }

        // 2) finfo
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = @finfo_file($fi, $filePath);
                finfo_close($fi);
                if (is_string($m) && strpos($m, 'image/') === 0) {
                    return $m;
                }
            }
        }

        // 3) 扩展名兜底
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    /**
     * multipart/form-data 上传；token 失效自动刷新重试一次
     *
     * @param string $url
     * @param string $filePath
     * @param string $field
     * @param string $token
     * @param string $endpoint 用于重试拼装
     * @param string $type     永久素材类型（uploadimg 时传空）
     * @return array
     * @throws Exception
     */
    protected function httpPostMultipart($url, $filePath, $field, $token, $endpoint, $type = '')
    {
        if (!is_readable($filePath)) {
            throw new Exception('待上传文件不可读：' . $filePath);
        }

        $mime = $this->mimeOf($filePath);

        $do = function ($requestUrl) use ($filePath, $field, $mime) {
            $ch = curl_init($requestUrl);
            $name = basename($filePath);
            if (class_exists('CURLFile')) {
                $postFields = array($field => new CURLFile($filePath, $mime, $name));
            } else {
                $postFields = array($field => '@' . $filePath . ';type=' . $mime);
            }
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
            ));
            return $this->execJson($ch);
        };

        $res = $do($url);
        if (isset($res['errcode']) && in_array(intval($res['errcode']), array(40001, 42001, 40014), true)) {
            $token = $this->refreshToken();
            if ($type !== '') {
                $url = self::BASE . $endpoint . '?access_token=' . urlencode($token) . '&type=' . urlencode($type);
            } else {
                $url = self::BASE . $endpoint . '?access_token=' . urlencode($token);
            }
            $res = $do($url);
        }
        return $res;
    }

    protected function execJson($ch)
    {
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('网络请求失败：' . $err);
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new Exception('微信接口返回异常（HTTP ' . $http . '）：' . substr($raw, 0, 200));
        }
        return $data;
    }

    protected function explain(array $res, $prefix)
    {
        $code = isset($res['errcode']) ? $res['errcode'] : '-';
        $msg  = isset($res['errmsg']) ? $res['errmsg'] : '未知错误';
        $hint = $this->errorHint(isset($res['errcode']) ? intval($res['errcode']) : 0);
        return $prefix . ' [' . $code . '] ' . $msg . ($hint !== '' ? '（' . $hint . '）' : '');
    }

    /**
     * 常见错误码的中文解释
     *
     * @param int $code
     * @return string
     */
    protected function errorHint($code)
    {
        $map = array(
            40001 => 'AppSecret 错误或 access_token 失效',
            40164 => '调用服务器 IP 不在公众号「IP 白名单」内，请到公众号后台配置',
            48001 => '该公众号未获得「发布」接口权限（需已认证订阅号/服务号）',
            45009 => '接口调用超过频率限制，请稍后再试',
            40007 => '图文封面 thumb_media_id 无效',
            40004 => '媒体文件类型错误',
            40005 => '媒体文件类型不支持，封面仅支持 png/jpeg/jpg/gif',
            40006 => '媒体文件大小超过限制（正文图片 1MB，封面图片 10MB）',
            53400 => '文章内容为空或图文数组为空',
            53401 => '永久材料 media_id 不存在',
        );
        return isset($map[$code]) ? $map[$code] : '';
    }

    protected function writableCacheDir()
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            throw new Exception('缓存目录不可写：' . $this->cacheDir);
        }
    }
}
