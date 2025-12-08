<?php
/**
 * BingIndexNow 插件（包含从 sitemap 自动/手动推送到 IndexNow）
 *
 * @package BingIndexNow
 * @author OneMuggle
 * @version 1.3.1
 * @link https://github.com/ID521101/BingIndexNow
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('BingIndexNow_Plugin', 'submitToBingIndex');
        return _t('Bing IndexNow 已启用，请务必在插件设置中填写 Key 并点击保存。');
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
            '在 https://www.bing.com/indexnow/getstarted 获取的 key。'
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
            '例如：https://www.onemuggle.com/yourkey.txt'
        );
        $form->addInput($keyLocation);

        // 日志开关
        $saveLog = new Typecho_Widget_Helper_Form_Element_Radio(
            'saveLog', array('1' => _t('是'), '0' => _t('否')), '1', _t('是否保存日志'), _t('将操作日志写入插件目录下的文件'));
        $form->addInput($saveLog);

        // ====== Sitemap 配置与手动提交按钮 ======
        $sitemapUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'sitemap_url', null, '',
            _t('站点地图地址（Sitemap URL）'),
            _t('填写完整的 Sitemap 地址，例如：https://www.onemuggle.com/sitemap.xml')
        );
        $form->addInput($sitemapUrl);

        // 手动提交按钮
        echo '<h4>站点地图即时推送</h4>';
        echo '<form method="post" style="margin-bottom:10px">';
        echo '<input type="submit" class="btn primary" name="submit_sitemap_now" value="立即提交Sitemap到IndexNow" />';
        echo '&nbsp;<span style="color:#666;">（将读取底部填写的 Sitemap URL 并推送全部链接）</span>';
        echo '</form>';

        if (!empty($_POST['submit_sitemap_now'])) {
            $options = Typecho_Widget::widget('Widget_Options');
            // 注意：文件夹名必须完全匹配 BingIndexNow
            $pluginOpts = $options->plugin('BingIndexNow');
            $sitemap = isset($pluginOpts->sitemap_url) ? trim($pluginOpts->sitemap_url) : '';
            
            echo '<pre style="background:#f8f8f8;border:1px solid #eee;padding:10px;">';
            echo "开始手动提交 Sitemap：" . htmlspecialchars($sitemap) . "\n";
            $result = self::handleManualSitemapSubmit($sitemap);
            echo htmlspecialchars($result);
            echo '</pre>';
        }

        echo '<p>日志文件将自动写入插件目录下：<code>indexnow_log.txt</code> 和 <code>indexnow_sitemap_log.txt</code></p>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /* -------------------- 提交核心逻辑 -------------------- */
    public static function submitToBingIndex($contents, $widget)
    {
        // 仅当发布时触发
        $visibility = null;
        if (is_array($contents) && isset($contents['visibility'])) {
            $visibility = $contents['visibility'];
        } elseif (is_object($contents) && isset($contents->visibility)) {
            $visibility = $contents->visibility;
        }

        if ($visibility !== 'publish') {
            return;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        // 确保获取的是本插件的配置
        $pluginOpts = $options->plugin('BingIndexNow');

        $apiKey      = isset($pluginOpts->apiKey) ? $pluginOpts->apiKey : '';
        $host        = isset($pluginOpts->host) ? $pluginOpts->host : '';
        $keyLocation = isset($pluginOpts->keyLocation) ? $pluginOpts->keyLocation : '';
        
        // 获取日志开关：如果未设置，默认为 0 (不保存)
        // 使用 == 比较，兼容 string '1' 和 int 1
        $shouldLog = (isset($pluginOpts->saveLog) && $pluginOpts->saveLog == '1');

        $user = Typecho_Widget::widget('Widget_User');

        $postId = self::extractPostId($contents, $widget);
        $url    = '';
        if (is_array($contents) && isset($contents['permalink'])) $url = $contents['permalink'];
        elseif (is_object($contents) && isset($contents->permalink)) $url = $contents->permalink;
        elseif (is_object($widget) && isset($widget->permalink)) $url = $widget->permalink;

        // 检查参数
        if (empty($apiKey) || empty($host) || empty($url)) {
            if ($shouldLog) {
                self::logToFile($postId, 0, '错误：缺少 API Key 或 Host 或 URL', $user->uid);
            }
            return;
        }

        $payloadArr = array(
            'host' => $host,
            'key' => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList' => array($url)
        );
        $payload = json_encode($payloadArr);

        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($payload)));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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

        // 只有当开关明确为 1 时才执行
        if ($shouldLog) {
            self::logToFile($postId, $httpCode, $msg, $user->uid);
        }
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
    private static function logToFile($postId, $code, $message, $userId = 0)
    {
        $dir  = dirname(__FILE__);
        $file = $dir . '/indexnow_log.txt';

        // 【修改点】强制使用 Asia/Shanghai 时区
        try {
            $dt = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
            $time = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $time = date('Y-m-d H:i:s'); // 如果环境不支持，回退到默认
        }

        $log  = "[$time] PostID: {$postId}, User: {$userId}, HTTP: {$code}\n";
        $log .= "Message: {$message}\n";
        $log .= str_repeat('-', 80) . "\n";

        file_put_contents($file, $log, FILE_APPEND | LOCK_EX);
    }

    /* -------------------- Sitemap 自动与手动提交功能 -------------------- */

    public static function handleManualSitemapSubmit($sitemap)
    {
        if (empty($sitemap)) {
            return 'Sitemap 地址为空，请在插件设置中填写。';
        }
        return self::runSitemapSubmit($sitemap);
    }

    public static function runSitemapSubmit($sitemap)
    {
        $dir = dirname(__FILE__);
        $logFile = $dir . '/indexnow_sitemap_log.txt';
        
        // 【修改点】强制使用 Asia/Shanghai 时区
        try {
            $dt = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
            $time = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $time = date('Y-m-d H:i:s');
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOpts = $options->plugin('BingIndexNow');
        
        $apiKey = isset($pluginOpts->apiKey) ? trim($pluginOpts->apiKey) : '';
        $host = isset($pluginOpts->host) ? trim($pluginOpts->host) : '';
        $keyLocation = isset($pluginOpts->keyLocation) ? trim($pluginOpts->keyLocation) : '';
        
        // 获取日志开关状态，严格检查
        $shouldLog = (isset($pluginOpts->saveLog) && $pluginOpts->saveLog == '1');

        if (empty($apiKey) || empty($host)) {
            $msg = "[$time] 错误：缺少 API Key 或 Host 配置。" . PHP_EOL . str_repeat('-', 80) . PHP_EOL;
            if ($shouldLog) {
                file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
            }
            return $msg;
        }

        $urls = self::fetchUrlsFromSitemap($sitemap);
        if (empty($urls)) {
            $msg = "[$time] 错误：未从 Sitemap 中获取到任何 URL。" . PHP_EOL . str_repeat('-', 80) . PHP_EOL;
            if ($shouldLog) {
                file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
            }
            return $msg;
        }

        $postDataArr = array(
            'host' => $host,
            'key' => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList' => array_values($urls)
        );
        $postData = json_encode($postDataArr);

        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($postData)));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $logMsg = "[$time] cURL 错误：$curlErr" . PHP_EOL;
        } elseif ($httpCode === 200) {
            $logMsg = "[$time] 提交成功，URLs: " . count($urls) . PHP_EOL . "HTTP Code: $httpCode" . PHP_EOL . "Response: $response" . PHP_EOL;
        } else {
            $logMsg = "[$time] 提交失败，URLs: " . count($urls) . PHP_EOL . "HTTP Code: $httpCode" . PHP_EOL . "Response: $response" . PHP_EOL;
        }

        $logMsg .= str_repeat('-', 80) . PHP_EOL;
        
        // 只有当开关明确为 1 时才写入
        if ($shouldLog) {
            file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
        }

        return $logMsg;
    }

    public static function fetchUrlsFromSitemap($sitemapUrl)
    {
        $urls = array();
        $content = self::fetchRemoteContent($sitemapUrl);
        if (empty($content)) return $urls;

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) return $urls;

        $rootName = strtolower($xml->getName());
        if ($rootName === 'sitemapindex') {
            foreach ($xml->sitemap as $s) {
                $loc = trim((string)$s->loc);
                if (!empty($loc)) {
                    $sub = self::fetchUrlsFromSitemap($loc);
                    if (!empty($sub)) $urls = array_merge($urls, $sub);
                }
            }
        } elseif ($rootName === 'urlset') {
            foreach ($xml->url as $u) {
                $loc = trim((string)$u->loc);
                if (!empty($loc)) $urls[] = $loc;
            }
        }
        $urls = array_values(array_unique($urls));
        return $urls;
    }

    private static function fetchRemoteContent($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return '';
        return $res;
    }
}
