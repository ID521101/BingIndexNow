<?php
/**
 * BingIndexNow 插件（通过数据库bingindex_log查看是否成功）
 *
 * @package  BingIndexNow
 * @author   OneMuggle
 * @version  1.0.5
 * @link     https://github.com/ID521101/BingIndexNow
 *
 * 主要改动：
 *   1. 读取文章 ID 与永久链接时同时支持数组访问和对象属性。
 *   2. 仍然保持 “keyLocation” 为可选字段（自定义 key 放置路径）。
 *   3. 移除了所有导致 post_id 为 NULL 的路径，日志表写入一定有合法 ID（如果真的获取不到则写 0，方便排查）。
 */

class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    /* -------------------- 激活 / 停用 -------------------- */
    public static function activate()
    {
        // 文章编辑完毕后调用 submitToBingIndex()
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')
            ->finishPublish = ['BingIndexNow_Plugin', 'submitToBingIndex'];

        // 创建日志表
        self::createDatabaseTable();

        // 注册插件设置页
        Typecho_Plugin::factory('Widget_Plugins_Config')
            ->register = ['BingIndexNow_Plugin', 'config'];

        return _t('Bing IndexNow 已启用，请在插件设置里填写 API Key、Host，必要时可填写 Key URL。');
    }

    public static function deactivate()
    {
        // 停用后保留日志表（若真的要删请手动调用 deleteDatabaseTable()）
        return _t('Bing IndexNow 已停用，日志表已保留。');
    }

    /* -------------------- 插件配置面板 -------------------- */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 必填：API Key
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey',
            null,
            '',
            _t('IndexNow Key（必填）'),
            '在 https://www.bing.com/indexnow/getstarted 申请得到的 32 位以上密钥(例如：c1665249f7874a529f4b16053f5c1665)。'
        );
        $apiKey->addRule('required', _t('请填写 API Key'));
        $form->addInput($apiKey);

        // 必填：Host（不带协议）
        $host = new Typecho_Widget_Helper_Form_Element_Text(
            'host',
            null,
            '',
            _t('站点主机名（不含协议）'),
            '例如 www.onemuggle.com'
        );
        $host->addRule('required', _t('请填写站点主机名'));
        $form->addInput($host);

        // 可选：Key 的公开 URL（如果密钥不放在 .well-known 目录，请自行填写完整地址）
        $keyLocation = new Typecho_Widget_Helper_Form_Element_Text(
            'keyLocation',
            null,
            '',
            _t('Key URL（需要在站点根目录下上传的txt文本）'),
            '例如：https://www.onemuggle.com/c1665249f7874a529f4b16053f5c1665.txt '
        );
        $form->addInput($keyLocation);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /* -------------------- 核心：文章发布后提交 -------------------- */
    /**
     * @param mixed $contents 文章对象（兼容数组访问或对象属性）
     * @param mixed $widget   同一个文章对象的另一种写法（有的版本会把它传进来）
     */
    public static function submitToBingIndex($contents, $widget)
    {
        // -------------------------------------------------
        // 1. 只对公开发布的文章执行
        // -------------------------------------------------
        $visibility = $contents['visibility'] ?? $contents->visibility ?? null;
        if ($visibility !== 'publish') {
            return;
        }

        // -------------------------------------------------
        // 2. 读取插件配置
        // -------------------------------------------------
        $options = Typecho_Widget::widget('Widget_Options');
        $apiKey  = $options->plugin('BingIndexNow')->apiKey;
        $host    = $options->plugin('BingIndexNow')->host;
        $keyLocation = $options->plugin('BingIndexNow')->keyLocation; // 可为空

        if (empty($apiKey) || empty($host)) {
            // 配置不完整直接记录日志并退出
            $postId = self::extractPostId($contents, $widget);
            self::logToDatabase(
                $postId,
                '插件配置不完整（缺少 API Key 或 Host）',
                Typecho_Widget::widget('Widget_User')->uid,
                $_SERVER['REMOTE_ADDR']
            );
            return;
        }

        // -------------------------------------------------
        // 3. 取得文章 ID 与永久链接（兼容多种写法）
        // -------------------------------------------------
        $postId = self::extractPostId($contents, $widget);
        if ($postId == 0) {
            // 仍然取不到 ID，记录供排查后退出
            self::logToDatabase(
                0,
                '获取文章 ID 失败，未写入 IndexNow',
                Typecho_Widget::widget('Widget_User')->uid,
                $_SERVER['REMOTE_ADDR']
            );
            return;
        }

        // 永久链接（permalink）同样兼容数组/对象
        $url = $contents['permalink'] ?? $contents->permalink ?? $widget->permalink ?? '';
        if (empty($url)) {
            self::logToDatabase(
                $postId,
                '获取文章永久链接失败，未写入 IndexNow',
                Typechar_Widget::widget('Widget_User')->uid,
                $_SERVER['REMOTE_ADDR']
            );
            return;
        }
        $urlList = [$url];

        // -------------------------------------------------
        // 4. 组装 IndexNow JSON
        // -------------------------------------------------
        $payload = [
            'host'    => $host,
            'key'     => $apiKey,
            'urlList' => $urlList,
        ];
        if (!empty($keyLocation)) {
            $payload['keyLocation'] = $keyLocation;   // 可选字段
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::logToDatabase(
                $postId,
                'JSON 编码错误：' . json_last_error_msg(),
                Typecho_Widget::widget('Widget_User')->uid,
                $_SERVER['REMOTE_ADDR']
            );
            return;
        }

        // -------------------------------------------------
        // 5. 通过 cURL POST 提交
        // -------------------------------------------------
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'User-Agent: Typecho-BingIndexNow/1.0 (+https://github.com/fluffyox/Typecho_BingIndexNow)'
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // -------------------------------------------------
        // 6. 统一写入日志
        // -------------------------------------------------
        if ($curlErr) {
            $msg = "cURL 错误：$curlErr";
        } elseif ($httpCode !== 200) {
            $msg = "HTTP $httpCode – $response";
        } else {
            $msg = "提交成功：$response";
        }

        self::logToDatabase($postId, $msg,
            Typecho_Widget::widget('Widget_User')->uid,
            $_SERVER['REMOTE_ADDR']);
    }

    /**
     * 统一取出文章 ID（兼容数组、对象、第二个参数）
     *
     * @param mixed $contents
     * @param mixed $widget
     * @return int 文章 ID（找不到则返回 0）
     */
    private static function extractPostId($contents, $widget)
    {
        // 1) $contents 可能是数组
        if (is_array($contents) && isset($contents['cid'])) {
            return (int)$contents['cid'];
        }

        // 2) $contents 可能是对象（ArrayAccess 兼容对象）
        if (is_object($contents)) {
            if (isset($contents->cid)) {
                return (int)$contents->cid;
            }
            // 有的版本里 $contents 实际是 Widget，属性叫 id
            if (isset($contents->id)) {
                return (int)$contents->id;
            }
        }

        // 3) 备用：使用第二个参数 $widget（有时也是同一个对象）
        if (is_object($widget)) {
            if (isset($widget->cid)) {
                return (int)$widget->cid;
            }
            if (isset($widget->id)) {
                return (int)$widget->id;
            }
        }

        return 0; // 没有找到
    }

    /* -------------------- 数据库表操作 -------------------- */
    /** 创建日志表（插件激活时调用） */
    private static function createDatabaseTable()
    {
        $db     = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$prefix}bingindex_log (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id   INT NOT NULL,
    user_id   INT NOT NULL,
    user_ip   VARCHAR(45) NOT NULL,
    response  MEDIUMTEXT,
    timestamp INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_post (post_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        $db->query($sql);
    }

    /** 删除日志表（仅在手动调用时使用） */
    private static function deleteDatabaseTable()
    {
        $db = Typecho_Db::get();
        $db->query('DROP TABLE IF EXISTS `' . $db->getPrefix() . 'bingindex_log`');
    }

    /** 将一次提交的结果写入日志表 */
    private static function logToDatabase($postId, $message, $userId, $userIp)
    {
        // 防止 NULL，强制转整数（0 代表 “未知 ID”，便于后期排查）
        $postId = (int)$postId;

        $db     = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $data = [
            'post_id'   => $postId,
            'user_id'   => $userId,
            'user_ip'   => $userIp,
            'response'  => $message,
            'timestamp' => time(),
        ];

        $db->query($db->insert($prefix . 'bingindex_log')->rows($data));
    }
}
