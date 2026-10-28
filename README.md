# WeChatSync · Typecho 公众号同步插件

[简体中文](#简体中文) ｜ [English](#english)

将 Typecho 文章在发布时自动同步到微信公众号：自动建草稿、转存正文图片、处理封面与摘要，并支持发文页逐篇勾选、发布状态定时回查、草稿就绪多渠道通知。兼容 **Typecho 1.1 / 1.2.x / 1.3.x**，仅依赖 PHP cURL。

Automatically sync Typecho posts to a WeChat Official Account when publishing: create drafts, re-host body images, handle cover & digest, with a per-post checkbox on the editor, scheduled publish-status polling, and multi-channel notifications. Works with **Typecho 1.1 / 1.2.x / 1.3.x**, requiring only the PHP cURL extension.

---

# 简体中文

## 功能特性

- **发文页逐篇勾选**：撰写文章右侧「选项」中提供「发布时同步到公众号」复选框；默认值与「更新已发布文章是否重发」均可配置。
- **两种同步方式**：
  - 仅保存到公众号**草稿箱**（默认，最稳妥，人工在公众号后台确认后群发）；
  - 自动调用**发布接口直接群发**（需已认证账号）。
- **未认证 / 个人订阅号可用**：草稿箱、图片转存、永久素材接口对未认证号开放；2025 年 7 月起 `freepublish` 群发权限对个人 / 未认证账号回收，此类账号请使用草稿模式。
- **正文图片自动转微信域名**（`media/uploadimg`），单张失败不阻断整篇。
- **封面**：优先取自定义字段 `wechat_cover`，其次正文第一张图（上传为永久素材得到 `thumb_media_id`）。
- **发布状态定时回查**：自动群发后按 `publish_id` 轮询，回写成功（含图文永久链接）/ 原创失败 / 审核不通过 / 被删除 / 被封禁 / 超时。
- **草稿就绪通知（多渠道）**：Server 酱、企业微信群机器人、Bark（iOS）、自定义 Webhook，以及公众号客服消息（仅认证号 + 48 小时互动窗口）。
- 自动摘要（≤120 字）、「阅读原文」指向博客、图文留言开关、自定义作者名。
- 同一篇默认只同步一次以防重复群发；后台「管理 → 公众号同步」可手动 / 强制重同步、测试连接、发送测试通知、查看日志。

## 环境要求

- Typecho 1.1（17.10.x）/ 1.2.x / 1.3.x
- PHP 5.6+（建议 7.4/8.x），启用 **cURL** 扩展
- 一个微信公众号（订阅号或服务号）及其 AppID / AppSecret
- 博客服务器出口公网 IP 已加入公众号「IP 白名单」
- `usr/plugins/WeChatSync/cache/` 目录可写（用于缓存 access_token 与日志）

## 安装

1. 将本仓库的 `WeChatSync/` 目录上传到博客 `usr/plugins/`，结构如下：
   ```
   usr/plugins/WeChatSync/Plugin.php
   usr/plugins/WeChatSync/Api.php
   usr/plugins/WeChatSync/Sync.php
   usr/plugins/WeChatSync/Cron.php
   usr/plugins/WeChatSync/Notifier.php
   usr/plugins/WeChatSync/panel.php
   usr/plugins/WeChatSync/cache/.htaccess
   ```
   也可直接下载 [releases/WeChatSync.zip](releases/WeChatSync.zip)，解压到 `usr/plugins/`。
2. 后台「控制台 → 插件」启用 **WeChatSync**。
3. 进入插件设置，填写 **AppID / AppSecret**，选择同步方式与通知渠道。
4. 公众号后台「设置与开发 → 基本配置」：加入服务器 **IP 白名单**。
5. 后台「管理 → 公众号同步」点 **测试连接**。

> 从旧版升级：覆盖文件后请在后台**停用再启用一次**（刷新后台菜单与 cron 路由注册）。

## 未认证 / 个人订阅号怎么用

自 **2025 年 7 月**起，个人主体、企业未认证及不支持认证的账号被回收 `freepublish` 系列权限（调用返回 48001）。能力对照：

| 能力 | 未认证 / 个人订阅号 |
| --- | --- |
| access_token（需配 IP 白名单） | ✅ |
| 正文图转存 / 永久封面素材 | ✅ |
| 新建图文草稿 `draft/add` | ✅ |
| API 直接群发 `freepublish/submit` | ❌ |
| 客服消息 `message/custom/send` | ❌ |

用法：同步方式保持默认「**仅保存到草稿箱**」；发文后草稿自动出现在公众号后台，核对后手动点「群发」。误开自动发布时，插件也会**先生成并保存草稿，再尝试发布**，遇到 48001 会保留草稿并在后台标记「草稿已就绪·待手动群发」。

## 发文页勾选

右侧「选项」面板出现「微信公众号 → 发布时同步到公众号」：

- 新建文章：按插件设置的默认值预选。
- 编辑已同步文章：默认不勾选（防误重复群发）；手动勾选则强制再推一次。
- 接口 / XML-RPC 等非发文页途径：按全局默认值执行。
- 私密 / 隐藏 / 待审核文章一律不同步。

## 发布状态定时回查（仅自动群发模式）

插件注册了免登录、密钥保护的路由。后台面板会显示完整地址，形如：

```
https://你的域名/wechat-sync/cron/<32位密钥>/
```

用系统计划任务每分钟请求一次：

```cron
* * * * * curl -s "https://你的域名/wechat-sync/cron/<密钥>/" >/dev/null 2>&1
```

未开启伪静态时用 `https://你的域名/index.php/wechat-sync/cron/<密钥>/`。地址含站点密钥，请勿公开。不配置 cron 也可在面板点「立即回查发布状态」。轮询约 40 次（约 40 分钟）未决标记为超时。

## 草稿 / 发布结果通知

在插件设置勾选一个或多个渠道（草稿生成、自动发布转人工、群发终态时推送）：

| 渠道 | 配置项 | 说明 |
| --- | --- | --- |
| Server 酱（推荐） | SendKey | 微信「方糖服务号」推送，个人 / 未认证号首选；`SCT` 开头自动用 Turbo 版 |
| 企业微信群机器人 | Webhook URL | Markdown 可点链接 |
| Bark（iOS） | `https://api.day.app/<key>` | 自动补 `/push`，点通知可跳转 |
| 自定义 Webhook | URL | JSON POST：`{title, content, link, source}` |
| 公众号客服消息 | OpenID | 仅认证号，且接收人近 48 小时与公众号互动过，否则报 45015 |

面板「发送测试通知」可验证。任一渠道失败只写日志，不影响同步。

## 文章自定义字段

| 字段 | 作用 |
| --- | --- |
| `wechat_cover` | 指定封面图 URL（优先于正文首图） |
| `wechat_digest` | 指定摘要（优先于自动摘要） |

## 常见错误码

| 错误码 | 含义与处理 |
| --- | --- |
| 40164 | 服务器 IP 不在白名单，到公众号后台添加出口 IP |
| 40001 | AppSecret 错误或 token 失效（插件会自动刷新一次） |
| 48001 | 无该接口权限（未认证 / 个人号无群发与客服消息权限），改用草稿模式 |
| 45015 | 客服消息：接收人 48 小时内未与公众号互动 |
| 40006 | 图片超限（正文图 ≤1MB，封面 ≤10MB） |
| 40005 | 图片类型不支持，封面仅 png/jpeg/jpg/gif |

## 安全说明

- AppSecret 仅在服务器端换取 access_token；token 缓存于 `cache/`（内含 `.htaccess` 拒绝访问）。建议在 Web 服务器层面也禁止公网访问该目录。
- 自动同步失败**不阻断发文**，错误写入 `cache/sync.log` 并在后台提示。

## 目录文件

| 文件 | 职责 |
| --- | --- |
| `Plugin.php` | 入口、钩子、配置项、发文页勾选 |
| `Api.php` | 微信接口封装（token / 草稿 / 发布 / 图片素材 / 客服消息） |
| `Sync.php` | 内容转换、图片转存、封面、摘要、状态写回、触发通知 |
| `Cron.php` | 发布状态定时回查 |
| `Notifier.php` | 多渠道通知 |
| `panel.php` | 后台管理面板 |

## 更新日志

- **1.1.0** 新增发文页逐篇勾选；发布状态定时回查；多渠道草稿 / 结果通知（含 Server 酱等，适配未认证号）；草稿先落库防止发布失败丢稿；兼容 Typecho 1.3.x。
- **1.0.0** 首发：自动 / 手动同步草稿箱或群发、图片转存、封面与摘要、后台面板与日志。

## 许可证

GPL-2.0-or-later（与 Typecho 一致）。

---

# English

## Features

- **Per-post checkbox on the editor**: a "Sync to WeChat when publishing" checkbox in the editor's Options panel; the default and re-sync-on-update behavior are configurable.
- **Two sync modes**:
  - save to the Official Account **draft box only** (default; you review and send manually),
  - call the **freepublish API to broadcast directly** (verified accounts only).
- **Works with unverified / personal subscription accounts**: draft, image transfer and permanent material APIs are available; since July 2025 `freepublish` is revoked for personal/unverified accounts (error 48001), so use draft mode for those.
- **Re-host body images** to a WeChat domain via `media/uploadimg`; one image failing does not abort the post.
- **Cover**: custom field `wechat_cover` first, otherwise the first body image (uploaded as permanent material for `thumb_media_id`).
- **Scheduled publish-status polling**: after auto-broadcast, poll by `publish_id` and record success (with permanent article URL), originality/audit failures, deletion/ban, or timeout.
- **Multi-channel notifications** when a draft is ready: ServerChan, WeCom group bot, Bark (iOS), custom webhook, and Official Account customer-service message (verified accounts + 48h interaction window only).
- Auto digest (≤120 chars), "Read original" back-link, comment toggle, custom author name.
- A post syncs only once by default to avoid duplicate broadcasts; the admin panel supports manual/forced re-sync, connection test, notification test, and logs.

## Requirements

- Typecho 1.1 (17.10.x) / 1.2.x / 1.3.x
- PHP 5.6+ (7.4/8.x recommended) with the **cURL** extension enabled
- A WeChat Official Account with AppID / AppSecret
- The server's public egress IP added to the account's **IP allow-list**
- Writable `usr/plugins/WeChatSync/cache/` (caches the access token and logs)

## Installation

1. Upload the `WeChatSync/` directory from this repo into your blog's `usr/plugins/`, so that it contains `Plugin.php`, `Api.php`, `Sync.php`, `Cron.php`, `Notifier.php`, `panel.php`, and `cache/.htaccess`. Or download [releases/WeChatSync.zip](releases/WeChatSync.zip) and unzip it into `usr/plugins/`.
2. Enable **WeChatSync** under **Console → Plugins**.
3. Open the plugin settings and fill in **AppID / AppSecret**, choose the sync mode and notification channels.
4. In the Official Account console, under **Settings & Development → Basic configuration**, add the server IP to the **IP allow-list**.
5. In the blog admin, go to **Manage → WeChat Sync** and click **Test connection**.

> Upgrading from an older version: overwrite the files, then **deactivate and reactivate** the plugin once (this refreshes the admin menu and the cron route registration).

## Unverified / personal subscription accounts

Since **July 2025**, personal-entity, unverified-enterprise and non-certifiable accounts lose the `freepublish` family (returns 48001). Capabilities:

| Capability | Unverified / personal |
| --- | --- |
| access_token (IP allow-list still required) | ✅ |
| body image transfer / permanent cover material | ✅ |
| create draft `draft/add` | ✅ |
| direct broadcast `freepublish/submit` | ❌ |
| customer-service message `message/custom/send` | ❌ |

Keep the default **draft-box mode**. After publishing, the draft appears in the Official Account backend; review it and tap broadcast manually. Even if auto-broadcast is switched on by mistake, the plugin **creates and stores the draft first**, then attempts publishing; on 48001 it keeps the draft and marks it "Draft ready · send manually".

## Editor checkbox

The Options panel shows **WeChat → Sync to Official Account when publishing**:

- New posts use the configured default.
- When editing an already-synced post it is unchecked by default (to prevent accidental re-broadcast); checking it forces a new push.
- Non-editor channels (API/XML-RPC) follow the global default.
- Private/hidden/pending posts are never synced.

## Scheduled publish-status polling (auto-broadcast mode only)

The plugin registers an unauthenticated, key-protected route. The full URL is shown in the admin panel:

```
https://your-domain/wechat-sync/cron/<32-char-key>/
```

Call it every minute via cron:

```cron
* * * * * curl -s "https://your-domain/wechat-sync/cron/<key>/" >/dev/null 2>&1
```

Without URL rewriting use `https://your-domain/index.php/wechat-sync/cron/<key>/`. The URL contains a site-specific secret—keep it private. You can also click "Poll publish status now" in the panel. After roughly 40 polls (~40 minutes) an undecided task is marked timed out.

## Draft / result notifications

Enable one or more channels in plugin settings (sent on draft creation, fallback to manual send, and broadcast terminal states):

| Channel | Setting | Notes |
| --- | --- | --- |
| ServerChan (recommended) | SendKey | Pushed via the "Fangtang" WeChat service account; best for personal/unverified accounts; keys starting with `SCT` use the Turbo endpoint automatically |
| WeCom group bot | Webhook URL | Markdown with clickable link |
| Bark (iOS) | `https://api.day.app/<key>` | `/push` appended automatically; tap to open the link |
| Custom webhook | URL | JSON POST body: `{title, content, link, source}` |
| Official Account customer-service message | OpenID | Verified accounts only; recipient must have interacted with the account within 48h (otherwise 45015) |

Use **Send test notification** in the panel. A failing channel is logged but never breaks syncing.

## Post custom fields

| Field | Purpose |
| --- | --- |
| `wechat_cover` | Cover image URL (takes priority over the first body image) |
| `wechat_digest` | Explicit digest (takes priority over auto digest) |

## Common error codes

| Code | Meaning / action |
| --- | --- |
| 40164 | Server IP not in the allow-list; add the egress IP in the account console |
| 40001 | Wrong AppSecret or invalid token (the plugin refreshes it once automatically) |
| 48001 | No permission for this API (unverified/personal accounts lack broadcast and customer-service messaging); use draft mode |
| 45015 | Customer-service message: recipient did not interact with the account in the last 48h |
| 40006 | Image too large (body ≤1MB, cover ≤10MB) |
| 40005 | Unsupported image type; cover accepts png/jpeg/jpg/gif only |

## Security

- The AppSecret is only used server-side to obtain an access token; the token is cached under `cache/` (shipped with a deny-all `.htaccess`). Prefer also blocking that directory at the web-server level.
- A sync failure never blocks publishing; errors go to `cache/sync.log` and surface in the admin panel.

## Files

| File | Responsibility |
| --- | --- |
| `Plugin.php` | Entry, hooks, config, editor checkbox |
| `Api.php` | WeChat API wrapper (token / draft / publish / image material / customer-service message) |
| `Sync.php` | Content conversion, image transfer, cover, digest, state persistence, triggering notifications |
| `Cron.php` | Scheduled publish-status polling |
| `Notifier.php` | Multi-channel notifications |
| `panel.php` | Admin panel |

## Changelog

- **1.1.0** Per-post editor checkbox; scheduled publish-status polling; multi-channel draft/result notifications (incl. ServerChan for unverified accounts); draft is persisted before publishing to avoid losing it on failure; Typecho 1.3.x compatibility.
- **1.0.0** Initial release: auto/manual sync to draft or broadcast, image transfer, cover & digest, admin panel and logs.

## License

GPL-2.0-or-later (matching Typecho).
