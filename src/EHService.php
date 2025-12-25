<?php
/**
 * EHService - Core Logic for E-Hentai API
 * 
 * @author    YcFeller
 * @copyright Copyright (c) 2024 YcFeller
 * @license   MIT
 * @note      Based on the logic from vela-py-eh-api-server by OrPudding
 */

namespace EHAPI;

require_once __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;

class EHService {
    private Client $client;
    private string $baseUrl = 'https://e-hentai.org';
    private $cache;

    /**
     * @param \EHAPI\Database $cache 数据库实例 (需实现 getCache/setCache)
     * @param string $cookie
     */
    public function __construct($cache, string $cookie = '') {
        $this->cache = $cache;
        // 模拟更真实的浏览器请求
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
            'Cache-Control' => 'max-age=0',
            'Sec-Ch-Ua' => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];
        
        if ($cookie) {
            $headers['Cookie'] = $cookie;
        }

        $options = [
            'base_uri' => $this->baseUrl,
            'headers' => $headers,
            'timeout' => 30,
            'verify' => false,
            'http_errors' => false,
            'cookies' => true
        ];

        // 尝试自动检测代理 (优先使用 XR_EH_PROXY，其次 HTTP_PROXY，最后尝试本地常见端口)
        $proxy = getenv('XR_EH_PROXY') ?: getenv('HTTP_PROXY');
        if (!$proxy) {
             // 尝试探测本地常见代理端口 (Clash/V2Ray)
             $commonProxies = ['http://127.0.0.1:7890', 'http://127.0.0.1:10809'];
             foreach ($commonProxies as $p) {
                 if ($this->checkProxy($p)) {
                     $proxy = $p;
                     break;
                 }
             }
        }
        
        if ($proxy) {
            $options['proxy'] = $proxy;
        }

        $this->client = new Client($options);
    }

    private function checkProxy($proxy): bool {
        // 简单检测端口是否开放
        $parts = parse_url($proxy);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? 80;
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * 搜索/获取画廊列表
     */
    public function search(string $query = '', ?string $next = null, bool $refresh = false): array {
        $params = [];
        if ($query) {
            $params['f_search'] = $query;
        }
        if ($next) {
            $params['next'] = $next;
        }
        // 强制列表视图
        // $params['inline_set'] = 'dm_e'; 
        
        $cacheKey = 'search_' . md5(json_encode($params));
        $cached = $this->cache->getCache($cacheKey);
        
        if ($cached && !$refresh) {
            return $cached;
        }

        $response = $this->client->get('/', ['query' => $params]);
        $html = (string) $response->getBody();
        
        $data = $this->parseGalleryList($html);
        $data['keyword'] = $query;
        
        if ($data['success']) {
            if (empty($data['galleries'])) {
                // error_log("EHAPI Warning: Empty search results for query: {$query}");
            }
            $this->cache->setCache($cacheKey, $data, 300); // 缓存 5 分钟
        }
        
        return $data;
    }

    /**
     * 获取画廊详情 (包含所有图片列表)
     */
    public function getGallery(int $gid, string $token, bool $fetchAllImages = true, bool $refresh = false): array {
        $cacheKey = "gallery_{$gid}_{$token}" . ($fetchAllImages ? '_full' : '');
        $cached = $this->cache->getCache($cacheKey);
        
        if ($cached && !$refresh) {
            return $cached;
        }

        $url = "/g/{$gid}/{$token}/?inline_set=ts_l";
        $response = $this->client->get($url);
        if ($response->getStatusCode() !== 200) {
            return ['success' => false, 'message' => 'Gallery not found or error'];
        }
        $html = (string) $response->getBody();

        $data = $this->parseGalleryDetail($html, $gid, $token);

        if ($data['success'] && $fetchAllImages) {
            $images = [];
            // 策略1: 尝试解析 MPV
            if (isset($data['mpv_url'])) {
                $images = $this->fetchMpvImages($data['mpv_url']);
            }
            
            // 策略2 (Fallback): 如果 MPV 失败、未找到，或者解析出的数量少于期望，逐页抓取
            $expectedCount = $data['total_images'] ?? 0;
            if (empty($images) || ($expectedCount > 0 && count($images) < $expectedCount)) {
                $images = $this->scrapeAllGalleryImages($html, $gid, $token, $data['total_pages'] ?? 1);
            }

            if (!empty($images)) {
                $data['images'] = $images;
            }
        }
        
        if ($data['success']) {
            $this->cache->setCache($cacheKey, $data, 3600); // 缓存 1 小时
        }
        
        return $data;
    }

    /**
     * 逐页抓取所有图片
     */
    private function scrapeAllGalleryImages(string $firstPageHtml, int $gid, string $token, int $totalPages): array {
        $allImages = [];
        $currentCount = 0;

        // 解析第一页
        $firstPageImages = $this->scrapeGalleryImages($firstPageHtml, $gid, 1);
        $allImages = array_merge($allImages, $firstPageImages);
        $currentCount += count($firstPageImages);

        // 如果只有一页，直接返回
        if ($totalPages <= 1) {
            return $allImages;
        }

        // 循环获取后续页面（p 从 0 开始，第一页 p=0 已获取）
        for ($p = 1; $p < $totalPages; $p++) {
            // 简单防封策略
            usleep(100000); // 100ms

            $url = "/g/{$gid}/{$token}/?inline_set=ts_l&p={$p}";
            try {
                $response = $this->client->get($url);
                $html = (string)$response->getBody();
                
                // 起始序号累加，保证 page 连续
                $pageImages = $this->scrapeGalleryImages($html, $gid, $currentCount + 1);
                if (empty($pageImages)) {
                    continue;
                }
                $allImages = array_merge($allImages, $pageImages);
                $currentCount += count($pageImages);
            } catch (\Exception $e) {
                // 忽略单页错误
            }
        }

        return $allImages;
    }

    /**
     * 解析当前页面的缩略图列表
     */
    private function scrapeGalleryImages(string $html, int $gid, int $startImageNumber = 1): array {
        $dom = new DOMDocument();
        // Fix encoding: force UTF-8
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $images = [];
        
        // 策略 1: 尝试匹配 gdtm (小图模式 - Normal Thumbs)
        $nodes = $xpath->query('//div[@id="gdt"]//div[contains(@class, "gdtm")]');
        
        // 策略 2: 尝试匹配 gdtl (大图模式 - Large Thumbs)
        if ($nodes->length === 0) {
             $nodes = $xpath->query('//div[@id="gdt"]//div[contains(@class, "gdtl")]');
        }

        // 策略 3: 兜底匹配 (直接找 gdt 下的所有链接)
        if ($nodes->length === 0) {
             // 查找 gdt 下所有包含 href 的 a 标签，且 href 包含 /s/ (单页图片链接)
             $nodes = $xpath->query('//div[@id="gdt"]//a[contains(@href, "/s/")]');
             // 为了统一逻辑，我们构建一个临时数组
             $tempNodes = [];
             foreach ($nodes as $n) { $tempNodes[] = $n; } 
        } else {
             $tempNodes = $nodes; 
        }

        foreach ($tempNodes as $index => $node) {
            $page = $startImageNumber + $index;
            
            // 如果是 div 容器，需要找内部的 a 标签
            if ($node->nodeName === 'div') {
                $link = $xpath->query('.//a', $node)->item(0);
            } else {
                // 如果是策略 3，node 本身就是 a 标签
                $link = $node;
            }
            
            if (!$link) continue;
            
            $pageUrl = $link->getAttribute('href');
            
            // 尝试解析 Key (从 url: /s/KEY/GID-PAGE)
            $key = '';
            if (preg_match('/\/s\/([a-z0-9]+)\//', $pageUrl, $m)) {
                $key = $m[1];
            }
            
            // 解析缩略图和样式
            $thumb = '';
            $style = '';
            
            if ($node->nodeName === 'div') {
                // gt200 / gdtm (background-image style)
                $imgNode = $xpath->query('.//img', $node)->item(0);
                if ($imgNode) {
                    $thumb = $imgNode->getAttribute('src');
                } else {
                    $style = $node->getAttribute('style');
                }
            } else {
                // 策略 3: node 是 a 标签
                $imgNode = $xpath->query('.//img', $node)->item(0);
                if ($imgNode) {
                     $thumb = $imgNode->getAttribute('src');
                } else {
                     $innerDiv = $xpath->query('.//div', $node)->item(0);
                     if ($innerDiv) {
                         $style = $innerDiv->getAttribute('style');
                     }
                }
            }

            // 如果有 style，尝试解析背景图和坐标
            $w = null; $h = null; $x = null; $y = null;
            
            if (!$thumb && $style) {
                // 健壮的 style 解析：拆分属性
                $styleParts = explode(';', $style);
                
                foreach ($styleParts as $part) {
                    $part = trim($part);
                    if (empty($part)) continue;
                    
                    if (strpos($part, 'width:') === 0) {
                        $w = (int)trim(str_replace(['width:', 'px'], '', $part));
                    } elseif (strpos($part, 'height:') === 0) {
                        $h = (int)trim(str_replace(['height:', 'px'], '', $part));
                    } elseif (strpos($part, 'background:') === 0 || strpos($part, 'background-image:') === 0) {
                        if (preg_match('/url\((.*?)\)/', $part, $m)) {
                            $thumb = trim($m[1], '\'" ');
                        }
                        // 在 background 简写属性中寻找坐标 (数字 px 数字 px)
                        $bgClean = preg_replace('/url\(.*?\)/', '', $part);
                        if (preg_match('/(-?\d+)(?:px)?\s+(-?\d+)(?:px)?/', $bgClean, $pm)) {
                            $x = abs((int)$pm[1]);
                            $y = abs((int)$pm[2]);
                        }
                    } elseif (strpos($part, 'background-position:') === 0) {
                        if (preg_match('/(-?\d+)(?:px)?\s+(-?\d+)(?:px)?/', $part, $pm)) {
                            $x = abs((int)$pm[1]);
                            $y = abs((int)$pm[2]);
                        }
                    }
                }
            }

            $proxyQuery = 'url=' . urlencode($thumb);
            if (isset($w, $h, $x, $y)) {
                $proxyQuery .= "&sprite_w={$w}&sprite_h={$h}&sprite_x={$x}&sprite_y={$y}";
            }

            $images[] = [
                'page' => $page,
                'name' => sprintf("%03d.jpg", $page),
                'key' => $key,
                'url' => $pageUrl,
                'thumbnail' => $thumb,
                'thumbnail_proxy' => 'image/proxy?' . $proxyQuery // 修正为相对路径，由前端处理 base
            ];
        }
        
        return $images;
    }

    private function fetchMpvImages(string $mpvUrl): array {
        try {
            $response = $this->client->get($mpvUrl);
            $html = (string) $response->getBody();
            
            // MPV page contains a script with 'var y = [...]' or similar
            // Structure in MPV:
            // var y = [ { "n":"Name", "k":"Key", "t":"thumb_url" }, ... ];
            // Need to parse this JSON-like structure.
            
            if (preg_match('/var\s+y\s*=\s*(\[.*?\])\s*;/s', $html, $matches)) {
                $json = $matches[1];
                // The JSON might be malformed or contain JS objects (keys without quotes)
                // E-Hentai MPV 'y' variable usually is valid JSON or close to it.
                // Let's try to decode it.
                $list = json_decode($json, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // If simple decode fails, try to fix common JS object syntax issues if needed
                    // For now, return empty or try regex extraction if needed.
                    return [];
                }

                $images = [];
                // Structure of items in y:
                // { "n": "001.jpg", "k": "4864c76766", "t": "https://..." }
                // Image Page URL: https://e-hentai.org/s/{key}/{gid}-{page}
                // But wait, MPV 'gid' might be needed.
                // Actually MPV url has gid: /mpv/gid/token/
                
                if (preg_match('/\/mpv\/(\d+)\//', $mpvUrl, $m)) {
                    $gid = $m[1];
                } else {
                    return [];
                }

                foreach ($list as $index => $item) {
                    $page = $index + 1;
                    $key = $item['k'];
                    $imgUrl = "https://e-hentai.org/s/{$key}/{$gid}-{$page}";
                    
                    $images[] = [
                        'page' => $page,
                        'name' => $item['n'],
                        'key' => $key,
                        'url' => $imgUrl, // This is the page URL, not the direct image URL
                        'thumbnail' => $item['t'],
                        'thumbnail_proxy' => 'image/proxy?url=' . urlencode($item['t'])
                    ];
                }
                return $images;
            }
            
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fetchImageByPageUrl(string $pageUrl): ?string {
        try {
            // 请求页面
            $response = $this->client->get($pageUrl);
            $html = (string)$response->getBody();
            
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            
            // 查找图片元素 id="img"
            $img = $xpath->query('//img[@id="img"]')->item(0);
            if ($img) {
                return $img->getAttribute('src');
            }
            
            // 如果没找到，可能需要处理 nl (Network Line) 重试逻辑
            // 暂时忽略，只处理基础情况
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取图片代理数据 (获取真实图片 URL 和 headers)
     */
    public function getImageProxyData(string $url, array $options = []): array {
        // 简单实现：直接请求 URL
        // 注意：E-Hentai 图片通常有防盗链，Referer 必须正确
        
        try {
            // 自动识别是否为 E-Hentai 单页地址 (/s/KEY/GID-PAGE)
            if (preg_match('/e-hentai\.org\/s\//', $url)) {
                $realImageUrl = $this->fetchImageByPageUrl($url);
                if ($realImageUrl) {
                    $url = $realImageUrl;
                } else {
                    throw new \Exception('Failed to parse image URL from page');
                }
            }

            $headers = [];
            if (strpos($url, 'ehgt.org') !== false || strpos($url, 'hath.network') !== false) {
                 $headers['Referer'] = 'https://e-hentai.org/';
            }

            // 优化：如果是流式透传（无缩放需求且非WebP转码需求），直接 Stream
            // 判断是否需要处理
            // 需要处理的情况：
            // 1. 指定了 w 或 h (缩放)
            // 2. 指定了 sprite_* (裁剪)
            // 3. 目标是 WebP 且我们可能想要转码 (根据 Accept 头? 这里暂时假设只根据 options)
            // 其实，如果客户端 Accept 支持 WebP，我们可以直接透传 WebP。
            // 为了简单，我们只在有明确处理参数时才下载整个 body。
            
            $needsProcessing = !empty($options['w']) || !empty($options['h']) || isset($options['sprite_w']);
            
            // 发起请求
            $response = $this->client->get($url, [
                'headers' => $headers,
                'stream' => !$needsProcessing, // 如果不需要处理，则开启 stream
                'timeout' => 60
            ]);

            $contentType = $response->getHeaderLine('Content-Type');
            
            // 二次检查：如果是 WebP 且我们强制要转码 (可选，目前保持原逻辑：只在需要裁剪/缩放时处理)
            // 如果 $needsProcessing 为 false，直接返回 stream
            
            if (!$needsProcessing) {
                 return [
                    'success' => true,
                    'content_type' => $contentType,
                    'body' => $response->getBody() // StreamInterface
                ];
            }

            // 如果需要处理，必须获取完整 body
            $body = (string)$response->getBody();

            // 尝试进行图片处理
            try {
                return $this->processImage($body, $options);
            } catch (\Throwable $e) {
                // 回退逻辑：处理失败则返回原图
                // 记录错误
            }

            return [
                'success' => true,
                'content_type' => $contentType,
                'body' => $body
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 处理图片：WebP转JPEG、缩放、裁剪 (支持 Imagick 和 GD)
     */
    private function processImage(string $imageBlob, array $options): array {
        // 预检：如果图片太大，直接放弃处理，防止内存耗尽
        try {
            $info = getimagesizefromstring($imageBlob);
            if ($info) {
                $w = $info[0];
                $h = $info[1];
                // 限制：总像素超过 2500万 (例如 5000x5000)
                if ($w * $h > 25000000) {
                    throw new \Exception("Image too large for processing ({$w}x{$h})");
                }
            }
        } catch (\Throwable $e) {
            // 获取信息失败，忽略检查，尝试处理
        }

        // 尝试提高内存限制 (仅当前请求)
        @ini_set('memory_limit', '512M');

        // 优先使用 Imagick
        if (extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick();
                $imagick->readImageBlob($imageBlob);

                // 雪碧图裁剪 (Sprite Sheet)
                if (isset($options['sprite_w'], $options['sprite_h'], $options['sprite_x'], $options['sprite_y'])) {
                    $imagick->cropImage(
                        (int)$options['sprite_w'], 
                        (int)$options['sprite_h'], 
                        (int)$options['sprite_x'], 
                        (int)$options['sprite_y']
                    );
                    $imagick->setImagePage(0, 0, 0, 0); 
                }

                // 缩放
                if (isset($options['w']) || isset($options['h'])) {
                    $w = isset($options['w']) ? (int)$options['w'] : 0;
                    $h = isset($options['h']) ? (int)$options['h'] : 0;
                    $imagick->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
                }

                // 格式与质量
                if ($imagick->getImageFormat() !== 'JPEG') {
                    $imagick->setImageFormat('jpeg');
                }
                $q = isset($options['q']) ? (int)$options['q'] : 95;
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality($q);
                $imagick->stripImage();

                return [
                    'success' => true,
                    'content_type' => 'image/jpeg',
                    'body' => $imagick->getImageBlob()
                ];
            } catch (\Throwable $e) {
                // Imagick 失败，尝试 GD
            }
        }

        // GD 库回退实现
        if (extension_loaded('gd')) {
            try {
                $srcImg = @imagecreatefromstring($imageBlob);
                if (!$srcImg) {
                    throw new \Exception('GD failed to load image');
                }

                // 雪碧图裁剪
                if (isset($options['sprite_w'], $options['sprite_h'], $options['sprite_x'], $options['sprite_y'])) {
                    $w = (int)$options['sprite_w'];
                    $h = (int)$options['sprite_h'];
                    $x = (int)$options['sprite_x'];
                    $y = (int)$options['sprite_y'];
                    
                    // 创建新画布
                    $dstImg = imagecreatetruecolor($w, $h);
                    
                    // 复制并裁剪
                    // imagecopy(dst, src, dst_x, dst_y, src_x, src_y, src_w, src_h)
                    imagecopy($dstImg, $srcImg, 0, 0, $x, $y, $w, $h);
                    
                    imagedestroy($srcImg);
                    $srcImg = $dstImg; // 更新当前处理的图片资源
                }

                // 缩放
                if (isset($options['w']) || isset($options['h'])) {
                    $origW = imagesx($srcImg);
                    $origH = imagesy($srcImg);
                    
                    $newW = isset($options['w']) ? (int)$options['w'] : 0;
                    $newH = isset($options['h']) ? (int)$options['h'] : 0;
                    
                    if ($newW == 0 && $newH == 0) {
                        // 无需缩放
                    } else {
                        if ($newW == 0) $newW = (int)($origW * ($newH / $origH));
                        if ($newH == 0) $newH = (int)($origH * ($newW / $origW));
                        
                        $dstImg = imagecreatetruecolor($newW, $newH);
                        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                        
                        imagedestroy($srcImg);
                        $srcImg = $dstImg;
                    }
                }

                // 输出为 JPEG
                ob_start();
                $q = isset($options['q']) ? (int)$options['q'] : 95;
                imagejpeg($srcImg, null, $q);
                $body = ob_get_clean();
                imagedestroy($srcImg);

                return [
                    'success' => true,
                    'content_type' => 'image/jpeg',
                    'body' => $body
                ];

            } catch (\Throwable $e) {
                // GD 也失败，抛出异常以触发最外层的 raw fallback
                throw $e;
            }
        }
        
        throw new \Exception('No image processing library available');
    }

    private function parseGalleryList(string $html): array {
        $dom = new DOMDocument();
        // Fix encoding: force UTF-8
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $galleries = [];
        // 尝试匹配 Extended View (gltc)
        $nodes = $xpath->query('//table[@class="itg gltc"]/tr[position()>1]');
        
        // 如果没找到，尝试匹配 Thumbnail View (gl1t)
        if ($nodes->length === 0) {
             // TODO: Implement other views if needed. 
             // For now, let's assume gltc or try to match gl1t containers
             $nodes = $xpath->query('//div[@class="gl1t"]');
             // 处理 Thumbnail View... (略，优先支持列表)
        }

        foreach ($nodes as $node) {
            $g = [];
            
            // 解析链接和标题
            $linkNode = $xpath->query('.//div[@class="glink"]/..', $node)->item(0);
            if (!$linkNode) {
                 // 可能是 Thumbnail View
                 $linkNode = $xpath->query('.//a', $node)->item(0);
            }
            if (!$linkNode) continue;
            
            $url = $linkNode->getAttribute('href');
            if (preg_match('/\/g\/(\d+)\/([a-f0-9]+)\//', $url, $matches)) {
                $g['gid'] = (int)$matches[1];
                $g['token'] = $matches[2];
            } else {
                continue;
            }
            $g['url'] = $url;
            $g['title'] = $linkNode->textContent;
            
            // 缩略图
            $img = $xpath->query('.//img', $node)->item(0);
            if ($img) {
                $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
                $style = $img->getAttribute('style');
                
                // 解析雪碧图参数 (Extended View)
                // style="height:200px;width:200px;background:transparent url(https://ehgt.org/...) -20px -40px no-repeat"
                $proxyQuery = 'url=' . urlencode($src);
                
                if (strpos($style, 'background') !== false && strpos($style, 'no-repeat') !== false) {
                     // 尝试提取 background-position 和 尺寸
                     // width:100px;height:100px;background:... -100px -200px ...
                     $w = $h = $x = $y = null;
                     
                     if (preg_match('/width:(\d+)px/', $style, $wm)) $w = $wm[1];
                     if (preg_match('/height:(\d+)px/', $style, $hm)) $h = $hm[1];
                     
                     // 提取背景图 URL (如果 src 是空的或者是透明图)
                     // url(https://...)
                     if (preg_match('/url\((.*?)\)/', $style, $um)) {
                         $bgUrl = trim($um[1], '\'" ');
                         if ($bgUrl) {
                             $src = $bgUrl; // 使用背景图作为真实 URL
                             $proxyQuery = 'url=' . urlencode($src);
                         }
                     }

                     // 提取位置 -20px -40px
                     // 注意：位置通常是负数，表示偏移
                     if (preg_match('/\s(-?\d+)px\s(-?\d+)px/', $style, $pm)) {
                         $x = abs((int)$pm[1]);
                         $y = abs((int)$pm[2]);
                     }
                     
                     if ($w !== null && $h !== null && $x !== null && $y !== null) {
                         $proxyQuery .= "&sprite_w={$w}&sprite_h={$h}&sprite_x={$x}&sprite_y={$y}";
                     }
                }

                $g['thumbnail'] = $src;
                // 生成代理链接
                $g['thumbnail_proxy'] = 'image/proxy?' . $proxyQuery;
            }
            
            // 类别
            $catNode = $xpath->query('.//*[@class="cn"]', $node)->item(0); // cn class usually holds category text
            $g['category'] = $catNode ? $catNode->textContent : '';

            // 评分
            $ratingNode = $xpath->query('.//div[contains(@class, "ir")]', $node)->item(0); // ir class for stars
            if ($ratingNode) {
                // style="background-position:-16px -21px" -> calculate rating
                // Or try to find title attribute? Usually not present in list
                // 粗略估算或不做
                $g['rating'] = 0; 
            }

            // 上传者
            $uploaderNode = $xpath->query('.//div/a[contains(@href, "/uploader/")]', $node)->item(0);
            $g['uploader'] = $uploaderNode ? $uploaderNode->textContent : '';

            // 页数
            // 通常在 gl4c 或 文本中找 "X pages"
            $text = $node->textContent;
            if (preg_match('/(\d+) pages/', $text, $pm)) {
                $g['pages'] = (int)$pm[1];
            }

            $galleries[] = $g;
        }
        
        // 翻页
        $nextId = null;
        $nextLink = $xpath->query('//a[@id="dnext"]')->item(0); // dnext is "Next" button ID
        if ($nextLink) {
            $href = $nextLink->getAttribute('href');
            if (preg_match('/next=(\d+)/', $href, $matches)) {
                $nextId = $matches[1];
            }
        }

        return [
            'success' => true,
            'galleries' => $galleries,
            'pagination' => [
                'has_next' => !!$nextId,
                'next_id' => $nextId
            ]
        ];
    }

    private function getDebugHtml(string $html): string {
        $debug = '';
        if (preg_match('/<title>(.*?)<\/title>/', $html, $m)) {
            $debug .= "Title: " . $m[1] . "\n";
        }
        $bodyStart = strpos($html, '<body');
        if ($bodyStart !== false) {
            $debug .= "Body Sample: " . substr($html, $bodyStart, 500);
        } else {
            $debug .= "Head Sample: " . substr($html, 0, 500);
        }
        return htmlspecialchars($debug);
    }

    /**
     * 解析画廊详情页信息
     */
    private function parseGalleryDetail(string $html, int $gid, string $token): array {
        $dom = new DOMDocument();
        // Fix encoding: force UTF-8
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);

        $data = [
            'success' => true,
            'gid' => $gid,
            'token' => $token,
        ];

        // 标题
        $gn = $xpath->query('//*[@id="gn"]')->item(0);
        if (!$gn) {
            // 如果连标题都找不到，说明页面完全不对
            // 返回失败并附带 HTML 调试信息
            return [
                'success' => false,
                'message' => 'Failed to parse gallery title. Page might be invalid.',
                'debug_html' => $this->getDebugHtml($html)
            ];
        }
        $gj = $xpath->query('//*[@id="gj"]')->item(0);
        $data['title'] = $gn ? $gn->textContent : '';
        $data['title_jpn'] = $gj ? $gj->textContent : '';

        // 缩略图
        $cover = $xpath->query('//div[@id="gd1"]//img')->item(0); // Usually gd1 has cover
        if ($cover) {
            // style="background:transparent url(...) ..."
            // or src attribute
            $src = $cover->getAttribute('src');
            if (strpos($src, 'ehgt.org') === false && $cover->getAttribute('style')) {
                 if (preg_match('/url\((.*?)\)/', $cover->getAttribute('style'), $m)) {
                     $src = $m[1];
                 }
            }
            $data['thumbnail'] = $src;
            $data['thumbnail_proxy'] = 'image/proxy?url=' . urlencode($src);
        }

        // 标签
        $tags = [];
        $tagRows = $xpath->query('//div[@id="taglist"]//tr');
        foreach ($tagRows as $row) {
            $namespace = $xpath->query('.//td[1]', $row)->item(0)->textContent;
            $namespace = rtrim($namespace, ':');
            $tagNodes = $xpath->query('.//td[2]//div', $row);
            $t = [];
            foreach ($tagNodes as $tn) {
                $t[] = $tn->textContent;
            }
            $tags[$namespace] = $t;
        }
        $data['tags'] = $tags;

        // 解析详细信息 (gdd) 以获取总图片数量 Length
        $info = [];
        $gddRows = $xpath->query('//*[@id="gdd"]//tr');
        foreach ($gddRows as $row) {
            $labelNode = $xpath->query('.//td[@class="gdt1"]', $row)->item(0);
            $valueNode = $xpath->query('.//td[@class="gdt2"]', $row)->item(0);
            if ($labelNode && $valueNode) {
                $label = trim($labelNode->textContent);
                $value = trim($valueNode->textContent);
                $info[rtrim($label, ':')] = $value;
            }
        }
        if (isset($info['Length'])) {
            if (preg_match('/(\d+)/', $info['Length'], $m)) {
                $data['total_images'] = (int)$m[1];
            }
        }

        // 解析分页导航，获取总页数
        $pttLinks = $xpath->query('//table[@class="ptt"]//td/a');
        $maxPage = 1;
        foreach ($pttLinks as $link) {
            $txt = $link->textContent;
            if (is_numeric($txt)) {
                $p = (int)$txt;
                if ($p > $maxPage) $maxPage = $p;
            }
        }
        $data['total_pages'] = $maxPage;

        // 图片预览 (获取前几页)
        // 获取所有图片链接需要遍历或使用 API
        // 这里为了简单，只解析当前页面的图片链接 (gdt)
        // 实际上 Python 项目说 "一次性返回所有"，可能需要解析 MPV
        
        $mpvLink = $xpath->query('//a[contains(@href, "/mpv/")]')->item(0);
        if ($mpvLink) {
            $data['mpv_url'] = $mpvLink->getAttribute('href');
            // 可以选择进一步请求 MPV URL 获取所有图片
            // 为了性能，这里先返回 mpv_url，客户端或后续逻辑处理
        }
        
        return $data;
    }
}
