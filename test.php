<?php
// 禁用缓存
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Hentai API (Standalone) 测试控制台</title>
    <style>
        :root { --primary: #28a745; --bg: #f8f9fa; --card-bg: #fff; --border: #dee2e6; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: #333; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1, h2, h3 { color: #2c3e50; }
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        input[type="text"], input[type="number"], textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box; }
        button { background: var(--primary); color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { opacity: 0.9; }
        button.secondary { background: #6c757d; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; }
        .gallery-item { border: 1px solid var(--border); border-radius: 4px; overflow: hidden; cursor: pointer; transition: transform 0.2s; background: #fff; }
        .gallery-item:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .gallery-thumb { width: 100%; height: 250px; object-fit: cover; background: #eee; }
        .gallery-info { padding: 10px; font-size: 12px; }
        .gallery-title { font-weight: bold; margin-bottom: 5px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        
        .reader { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .reader img { max-width: 100%; height: auto; border: 1px solid #ddd; }
        
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 400px; }
        .status-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-success { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="container">
    <h1>E-Hentai API (Standalone) 测试控制台</h1>
    
    <!-- 配置区 -->
    <div class="card">
        <h2>🔧 配置</h2>
        <div class="form-group">
            <label>API 根路径</label>
            <input type="text" id="apiRoot" value="/eh_api_standalone/index.php">
        </div>
        <div class="form-group">
            <label>X-EH-Cookie (可选，用于访问 ExHentai/MPV)</label>
            <input type="text" id="ehCookie" placeholder="ipb_member_id=...; ipb_pass_hash=...; igneous=...;">
            <small style="color: #666;">提示：要获取“所有图片列表”，必须提供有效的 Cookie。</small>
        </div>
    </div>

    <!-- 搜索测试 -->
    <div class="card">
        <h2>🔍 1. 搜索测试</h2>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="searchQuery" placeholder="搜索关键词 (例如: language:chinese)" value="language:chinese">
            <button onclick="doSearch()">搜索</button>
        </div>
        <div style="margin-top: 10px;">
            <input type="checkbox" id="searchRefresh"> <label style="display:inline" for="searchRefresh">强制刷新缓存 (refresh=1)</label>
        </div>
        <hr>
        <div id="searchResult" class="grid"></div>
    </div>

    <!-- 详情与阅读测试 -->
    <div class="card">
        <h2>📖 2. 画廊详情与阅读测试</h2>
        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
            <input type="number" id="gid" placeholder="GID">
            <input type="text" id="token" placeholder="Token">
            <button onclick="getGallery()">获取详情</button>
        </div>
        <div style="margin-bottom: 10px;">
             <input type="checkbox" id="galleryRefresh"> <label style="display:inline" for="galleryRefresh">强制刷新缓存</label>
             <input type="checkbox" id="fetchAll" checked> <label style="display:inline" for="fetchAll">获取所有图片 (fetchAllImages)</label>
        </div>
        <div style="margin-bottom: 10px; padding: 10px; background: #eee; border-radius: 4px;">
            <strong>阅读设置:</strong>
            <div style="display:flex; flex-wrap:wrap; gap:15px; margin-top:5px; align-items:center;">
                <label><input type="checkbox" id="showBigImage"> 显示大图 (自动解析原图)</label>
                
                <div style="display:flex; gap:5px; align-items:center;">
                    <label>宽度:</label>
                    <input type="number" id="imageWidth" placeholder="自适应" style="width:70px;">
                </div>
                
                <div style="display:flex; gap:5px; align-items:center;">
                    <label>高度:</label>
                    <input type="number" id="imageHeight" placeholder="自适应" style="width:70px;">
                </div>
                
                <div style="display:flex; gap:5px; align-items:center;">
                    <label>质量 (1-100):</label>
                    <input type="number" id="imageQuality" value="95" min="1" max="100" style="width:60px;">
                </div>
            </div>
            
            <div style="margin-top:10px; border-top:1px solid #ddd; padding-top:10px;">
                <strong>独立测试图片代理:</strong>
                <div style="display:flex; gap:10px; margin-top:5px;">
                    <input type="text" id="directUrl" placeholder="输入 E-Hentai 单页地址 或 图片直链">
                    <button class="secondary" onclick="testDirectProxy()">测试代理</button>
                </div>
            </div>
        </div>
        
        <div id="galleryMeta" style="margin-bottom: 20px;"></div>
        
        <h3>图片列表 / 阅读器</h3>
        <div id="readerContainer" class="reader">
            <p style="color: #888;">暂无图片数据。请先获取详情，且确保 Cookie 有效以触发 MPV 解析。</p>
        </div>
    </div>

    <!-- 调试输出 -->
    <div class="card">
        <h2>💻 调试输出 (JSON)</h2>
        <pre id="debugOutput">// 等待请求...</pre>
    </div>
</div>

<script>
    const log = (data) => {
        document.getElementById('debugOutput').textContent = JSON.stringify(data, null, 2);
    };

    const getHeaders = () => {
        const headers = {};
        const cookie = document.getElementById('ehCookie').value.trim();
        if (cookie) {
            headers['X-EH-Cookie'] = cookie;
        }
        return headers;
    };

    const getApiUrl = (endpoint, params = {}) => {
        const root = document.getElementById('apiRoot').value.replace(/\/$/, '');
        // 修正逻辑：如果 root 已经包含了 index.php，则需要正确拼接参数
        // 我们的 API 路由设计是 /index.php/search 或 /index.php?path=/search (如果重写不支持)
        // 这里的 index.php 实现通过 REQUEST_URI 解析路径
        
        const url = new URL(window.location.origin + root + endpoint);
        Object.keys(params).forEach(key => {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url.searchParams.append(key, params[key]);
            }
        });
        return url.toString();
    };

    async function doSearch() {
        const query = document.getElementById('searchQuery').value;
        const refresh = document.getElementById('searchRefresh').checked ? 1 : 0;
        const container = document.getElementById('searchResult');
        
        container.innerHTML = '<p>搜索中...</p>';
        
        try {
            const url = getApiUrl('/search', { q: query, refresh });
            const res = await fetch(url, { headers: getHeaders() });
            const data = await res.json();
            log(data);

            container.innerHTML = '';
            if (data.success && data.galleries) {
                data.galleries.forEach(g => {
                    const div = document.createElement('div');
                    div.className = 'gallery-item';
                    div.onclick = () => fillGallery(g.gid, g.token);
                    // 修正图片路径：如果是相对路径，需要加上 API 根目录
                    let thumbUrl = g.thumbnail_proxy;
                    if (thumbUrl && !thumbUrl.startsWith('http') && !thumbUrl.startsWith('/')) {
                        const root = document.getElementById('apiRoot').value.replace(/\/index\.php$/, '');
                        thumbUrl = root + '/' + thumbUrl;
                    }

                    div.innerHTML = `
                        <img class="gallery-thumb" src="${thumbUrl}" loading="lazy">
                        <div class="gallery-info">
                            <div class="gallery-title">${g.title}</div>
                            <div>GID: ${g.gid}</div>
                            <div>Category: ${g.category}</div>
                        </div>
                    `;
                    container.appendChild(div);
                });
            } else {
                container.innerHTML = '<p>未找到结果或 API 报错</p>';
            }
        } catch (e) {
            container.innerHTML = '<p style="color:red">请求失败: ' + e.message + '</p>';
            log({ error: e.message });
        }
    }

    function fillGallery(gid, token) {
        document.getElementById('gid').value = gid;
        document.getElementById('token').value = token;
        window.scrollTo({ top: document.querySelector('.card:nth-child(3)').offsetTop - 20, behavior: 'smooth' });
        getGallery();
    }

    function testDirectProxy() {
        const url = document.getElementById('directUrl').value.trim();
        if (!url) { alert('请输入 URL'); return; }
        
        const width = document.getElementById('imageWidth').value;
        const height = document.getElementById('imageHeight').value;
        const quality = document.getElementById('imageQuality').value;
        
        let proxyUrl = getApiUrl('/image/proxy', { url: url });
        if (width) proxyUrl += `&w=${width}`;
        if (height) proxyUrl += `&h=${height}`;
        if (quality && quality != 95) proxyUrl += `&q=${quality}`;
        
        window.open(proxyUrl, '_blank');
    }

    async function getGallery() {
        const gid = document.getElementById('gid').value;
        const token = document.getElementById('token').value;
        const refresh = document.getElementById('galleryRefresh').checked ? 1 : 0;
        const fetchAll = document.getElementById('fetchAll').checked ? 1 : 0; 
        
        if (!gid || !token) {
            alert('请先填写 GID 和 Token');
            return;
        }

        const metaContainer = document.getElementById('galleryMeta');
        const readerContainer = document.getElementById('readerContainer');
        
        metaContainer.innerHTML = '加载中...';
        readerContainer.innerHTML = '';

        try {
            const url = getApiUrl('/gallery', { gid, token, refresh, fetchAllImages: fetchAll });
            const res = await fetch(url, { headers: getHeaders() });
            const data = await res.json();
            log(data);

            if (data.success) {
                // 显示元数据
                let tagsHtml = '';
                if (data.tags) {
                    Object.keys(data.tags).forEach(k => {
                        tagsHtml += `<div><strong>${k}:</strong> ${data.tags[k].join(', ')}</div>`;
                    });
                }
                
                // 缩略图路径修正
                let mainThumb = data.thumbnail_proxy;
                if (mainThumb && !mainThumb.startsWith('http') && !mainThumb.startsWith('/')) {
                     const root = document.getElementById('apiRoot').value.replace(/\/index\.php$/, '');
                     mainThumb = root + '/' + mainThumb;
                }

                metaContainer.innerHTML = `
                    <h3>${data.title}</h3>
                    <p style="color:#666">${data.title_jpn || ''}</p>
                    <div style="display:flex; gap:20px; margin: 10px 0;">
                         <img src="${mainThumb}" style="width:120px; height:auto; border-radius:4px;">
                         <div style="font-size:14px;">${tagsHtml}</div>
                    </div>
                `;

                // 显示阅读器
                if (data.images && data.images.length > 0) {
                    readerContainer.innerHTML = `<p>共 ${data.images.length} 页</p>`;
                    
                    const showBig = document.getElementById('showBigImage').checked;
                    const width = document.getElementById('imageWidth').value;
                    const height = document.getElementById('imageHeight').value;
                    const quality = document.getElementById('imageQuality').value;
                    const root = document.getElementById('apiRoot').value.replace(/\/index\.php$/, '');

                    data.images.forEach(img => {
                        const imgEl = document.createElement('img');
                        
                        if (showBig) {
                            // 构造大图代理链接
                            let proxyUrl = getApiUrl('/image/proxy', { url: img.url });
                            if (width) proxyUrl += `&w=${width}`;
                            if (height) proxyUrl += `&h=${height}`;
                            if (quality && quality != 95) proxyUrl += `&q=${quality}`;
                            
                            imgEl.src = proxyUrl;
                            imgEl.style.maxWidth = '100%'; 
                            imgEl.style.display = 'block';
                            imgEl.style.margin = '10px auto';
                        } else {
                            // 使用缩略图
                            let thumbUrl = img.thumbnail_proxy;
                             if (thumbUrl && !thumbUrl.startsWith('http') && !thumbUrl.startsWith('/')) {
                                thumbUrl = root + '/' + thumbUrl;
                            }
                            imgEl.src = thumbUrl;
                            imgEl.style.maxWidth = '200px'; 
                            imgEl.style.height = 'auto';
                        }
                        
                        imgEl.title = `Page ${img.page}`;
                        readerContainer.appendChild(imgEl);
                    });
                    
                    if (showBig) {
                        readerContainer.style.flexDirection = 'column';
                    } else {
                        readerContainer.style.flexDirection = 'row';
                        readerContainer.style.flexWrap = 'wrap';
                    }
                } else {
                    readerContainer.innerHTML = `
                        <div class="status-badge status-error">未获取到图片列表</div>
                        <p>可能原因：<br>1. Cookie 无效或未填写 (MPV 需要登录)<br>2. 缓存了无权限的结果 (请勾选强制刷新)<br>3. 账号无权访问该画廊</p>
                        ${data.mpv_url ? `<p>检测到 MPV 链接: <a href="${data.mpv_url}" target="_blank">跳转 E-Hentai 查看</a></p>` : ''}
                        ${data.debug_html ? `<div style="margin-top:10px; border:1px solid #ccc; padding:10px;"><strong>Debug Info (HTML Sample):</strong><pre>${data.debug_html}</pre></div>` : ''}
                    `;
                    readerContainer.style.flexDirection = 'column';
                }
            } else {
                metaContainer.innerHTML = `<p style="color:red">${data.message}</p>`;
            }
        } catch (e) {
            metaContainer.innerHTML = '<p style="color:red">请求失败: ' + e.message + '</p>';
            log({ error: e.message });
        }
    }
</script>

</body>
</html>
