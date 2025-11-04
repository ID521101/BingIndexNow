<?php
/**
 * BingIndexNow 插件（包含从 sitemap 自动/手动推送到 IndexNow）
 *
 * @package BingIndexNow
 * @author OneMuggle
 * @version 1.3.0
 * @link https://github.com/ID521101/BingIndexNow
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('BingIndexNow_Plugin', 'submitToBingIndex');

        // 注册前端 header 钩子用于伪 cron（每日运行 sitemap 提交）
        Typecho_Plugin::factory('Widget_Archive')->header = array('BingIndexNow_Plugin', 'maybeRunSitemapCron');

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

        // 手动提交按钮（在插件设置页触发）
        echo '<h4>站点地图即时推送</h4>';
        echo '<form method="post" style="margin-bottom:10px">';
        echo '<input type="submit" class="btn primary" name="submit_sitemap_now" value="立即提交Sitemap到IndexNow" />';
        echo '&nbsp;<span style="color:#666;">（将读取底部填写的 Sitemap URL 并推送全部链接）</span>';
        echo '</form>';

        if (!empty($_POST['submit_sitemap_now'])) {
            // 处理手动提交（只在管理员访问插件配置页面时执行）
            $options = Typecho_Widget::widget('Widget_Options');
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

    /* -------------------- 提交核心逻辑（保留原功能） -------------------- */
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

        $options     = Typecho_Widget::widget('Widget_Options');
        $pluginOpts  = $options->plugin('BingIndexNow');
        $apiKey      = isset($pluginOpts->apiKey) ? $pluginOpts->apiKey : '';
        $host        = isset($pluginOpts->host) ? $pluginOpts->host : '';
        $keyLocation = isset($pluginOpts->keyLocation) ? $pluginOpts->keyLocation : '';

        $user = Typecho_Widget::widget('Widget_User');
        $ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

        $postId = self::extractPostId($contents, $widget);
        $url    = '';
        if (is_array($contents) && isset($contents['permalink'])) $url = $contents['permalink'];
        elseif (is_object($contents) && isset($contents->permalink)) $url = $contents->permalink;
        elseif (is_object($widget) && isset($widget->permalink)) $url = $widget->permalink;

        if (empty($apiKey) || empty($host) || empty($url)) {
            self::logToFile($postId, 0, '缺少 API Key 或 Host 或 URL', $user->uid, $ip);
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
    private static function logToFile($postId, $code, $message, $userId = 0, $userIp = '')
    {
        $dir  = dirname(__FILE__);
        $file = $dir . '/indexnow_log.txt';

        $time = date('Y-m-d H:i:s');
        $log  = "[$time] PostID: {$postId}, User: {$userId}, IP: {$userIp}, HTTP: {$code}\n";
        $log .= "Message: {$message}\n";
        $log .= str_repeat('-', 80) . "\n";

        file_put_contents($file, $log, FILE_APPEND | LOCK_EX);
    }

    /* -------------------- Sitemap 自动与手动提交功能（独立于原功能） -------------------- */

    // 在前端 header 钩子触发，使用文件方式记录上次运行时间以实现伪 cron（每日一次）
    public static function maybeRunSitemapCron()
    {
        $dir = dirname(__FILE__);
        $lockFile = $dir . '/indexnow_sitemap_last_run.txt';

        // 如果文件不存在或最后运行时间超过 24 小时，则执行
        $needRun = true;
        if (file_exists($lockFile)) {
            $last = (int)trim(file_get_contents($lockFile));
            if ($last + 86400 > time()) {
                $needRun = false;
            }
        }

        if ($needRun) {
            // 读取配置
            $options = Typecho_Widget::widget('Widget_Options');
            $pluginOpts = $options->plugin('BingIndexNow');
            $sitemap = isset($pluginOpts->sitemap_url) ? trim($pluginOpts->sitemap_url) : '';
            if (!empty($sitemap)) {
                self::runSitemapSubmit($sitemap);
                // 更新最后运行时间
                file_put_contents($lockFile, (string)time(), LOCK_EX);
            }
        }
    }

    // 处理手动提交调用（返回字符串结果）
    public static function handleManualSitemapSubmit($sitemap)
    {
        if (empty($sitemap)) {
            return 'Sitemap 地址为空，请在插件设置中填写。';
        }

        return self::runSitemapSubmit($sitemap);
    }

    // 运行 sitemap 提交主流程（返回字符串）
    public static function runSitemapSubmit($sitemap)
    {
        $dir = dirname(__FILE__);
        $logFile = $dir . '/indexnow_sitemap_log.txt';
        $time = date('Y-m-d H:i:s');

        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOpts = $options->plugin('BingIndexNow');
        $apiKey = isset($pluginOpts->apiKey) ? trim($pluginOpts->apiKey) : '';
        $host = isset($pluginOpts->host) ? trim($pluginOpts->host) : '';
        $keyLocation = isset($pluginOpts->keyLocation) ? trim($pluginOpts->keyLocation) : '';

        if (empty($apiKey) || empty($host)) {
            $msg = "[$time] 错误：缺少 API Key 或 Host 配置。" . PHP_EOL . str_repeat('-', 80) . PHP_EOL;
            file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
            return $msg;
        }

        // 获取所有 URL
        $urls = self::fetchUrlsFromSitemap($sitemap);
        if (empty($urls)) {
            $msg = "[$time] 错误：未从 Sitemap 中获取到任何 URL。" . PHP_EOL . str_repeat('-', 80) . PHP_EOL;
            file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
            return $msg;
        }

        // 限制单次提交大小（可根据需要调整或移除）
        // $urls = array_slice($urls, 0, 1000);

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
        file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);

        return $logMsg;
    }

    // 解析 sitemap，支持 sitemap index 和常规 sitemap
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
            // sitemap index 包含多个 sitemap 文件
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

        // 去重并返回
        $urls = array_values(array_unique($urls));
        return $urls;
    }

    // 简单的远程抓取（支持 curl）
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
