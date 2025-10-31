<?php
/**
 * BingIndexNow 插件（日志写入插件目录文件）
 * 
 * @package BingIndexNow
 * @author OneMuggle
 * @version 1.2.0
 * @link https://github.com/ID521101/BingIndexNow
 */

class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')
            ->finishPublish = ['BingIndexNow_Plugin', 'submitToBingIndex'];

        return _t('Bing IndexNow 已启用，请在插件设置中填写 API Key 和 Host。');
    }

    public static function deactivate()
    {
        return _t('Bing IndexNow 已停用。');
    }

    /* -------------------- 插件配置面板 -------------------- */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey', null, '',
            _t('IndexNow Key（必填）'),
            '在 https://www.bing.com/indexnow/getstarted 获取的 32 位以上密钥(例:c1665249f7874a529f4b16053f5c1665)。'
        );
        $apiKey->addRule('required', _t('请填写 API Key'));
        $form->addInput($apiKey);

        $host = new Typecho_Widget_Helper_Form_Element_Text(
            'host', null, '',
            _t('站点主机名（不含协议：https:// 或 http://）'),
            '例如：www.onemuggle.com'
        );
        $host->addRule('required', _t('请填写站点主机名'));
        $form->addInput($host);

        $keyLocation = new Typecho_Widget_Helper_Form_Element_Text(
            'keyLocation', null, '',
            _t('Key URL（需要在站点根目录上传txt文本）'),
            '1.在站点根目录上传txt文本。 2.在浏览器上通过 (域名 + key + .txt) 能够访问到该文本。例如：https://ww.onemuggle.com/c1665249f7874a529f4b16053f5c1665.txt'
        );
        $form->addInput($keyLocation);

        echo '<p>日志文件将自动写入：<code>BingIndexNow/indexnow_log.txt</code></p>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /* -------------------- 提交核心逻辑 -------------------- */
    public static function submitToBingIndex($contents, $widget)
    {
        $visibility = $contents['visibility'] ?? $contents->visibility ?? null;
        if ($visibility !== 'publish') {
            return;
        }

        $options     = Typecho_Widget::widget('Widget_Options');
        $pluginOpts  = $options->plugin('BingIndexNow');
        $apiKey      = $pluginOpts->apiKey;
        $host        = $pluginOpts->host;
        $keyLocation = $pluginOpts->keyLocation;

        $user        = Typecho_Widget::widget('Widget_User');
        $ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $postId = self::extractPostId($contents, $widget);
        $url    = $contents['permalink'] ?? $contents->permalink ?? $widget->permalink ?? '';

        if (empty($apiKey) || empty($host)) {
            self::logToFile($postId, 0, '缺少 API Key 或 Host', $user->uid, $ip);
            return;
        }

        if (empty($url)) {
            self::logToFile($postId, 0, '无法获取文章永久链接', $user->uid, $ip);
            return;
        }

        $payload = [
            'host'    => $host,
            'key'     => $apiKey,
            'urlList' => [$url],
        ];
        if (!empty($keyLocation)) {
            $payload['keyLocation'] = $keyLocation;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::logToFile($postId, 0, 'JSON 编码错误：' . json_last_error_msg(), $user->uid, $ip);
            return;
        }

        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'User-Agent: Typecho-BingIndexNow/1.2 (+https://github.com/fluffyox/Typecho_BingIndexNow)'
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $msg = "cURL 错误：$curlErr";
        } elseif ($httpCode === 200) {
            $msg = "提交成功：$response";
        } else {
            $msg = "HTTP $httpCode – $response";
        }

        self::logToFile($postId, $httpCode, $msg, $user->uid, $ip);
    }

    private static function extractPostId($contents, $widget)
    {
        if (is_array($contents) && isset($contents['cid'])) {
            return (int)$contents['cid'];
        }
        if (is_object($contents)) {
            if (isset($contents->cid)) return (int)$contents->cid;
            if (isset($contents->id))  return (int)$contents->id;
        }
        if (is_object($widget)) {
            if (isset($widget->cid)) return (int)$widget->cid;
            if (isset($widget->id))  return (int)$widget->id;
        }
        return 0;
    }

    /* -------------------- 文件日志功能 -------------------- */
    private static function logToFile($postId, $code, $message, $userId, $userIp)
    {
        $dir  = dirname(__FILE__);
        $file = $dir . '/indexnow_log.txt';

        $time = date('Y-m-d H:i:s');
        $log  = "[$time] PostID: {$postId}, User: {$userId}, IP: {$userIp}, HTTP: {$code}\n";
        $log .= "Message: {$message}\n";
        $log .= str_repeat('-', 80) . "\n";

        file_put_contents($file, $log, FILE_APPEND | LOCK_EX);
    }
}
