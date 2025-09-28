<?php
// PHP PROXY SECTION
if (isset($_GET['proxy_url'])) {
    set_time_limit(120);
    $url = $_GET['proxy_url'];

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo "Invalid URL provided.";
        exit();
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $content = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo "Server-Side cURL Error: " . $error_msg;
        exit();
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code >= 400) {
        http_response_code($http_code);
        echo "Error fetching remote content. Status: " . $http_code;
        exit();
    }

    // --- START: NEW PLAYLIST REWRITING LOGIC ---
    // Check if the content is a playlist (M3U8 file)
    if (strpos($content_type, 'mpegurl') !== false || strpos($content_type, 'x-mpegURL') !== false) {
        // This is a playlist file, we need to rewrite its internal URLs
        $base_url = substr($url, 0, strrpos($url, '/') + 1);
        $lines = explode("\n", $content);
        $rewritten_content = "";

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                // Keep comments and empty lines as they are
                $rewritten_content .= $line . "\n";
                continue;
            }

            if (substr($line, 0, 4) !== 'http') {
                // This is a relative path, so we make it absolute
                $absolute_url = $base_url . $line;
                // Now we wrap it in our own proxy URL
                $proxied_line = '?proxy_url=' . urlencode($absolute_url);
                $rewritten_content .= $proxied_line . "\n";
            } else {
                // This is already an absolute path, just wrap it in our proxy
                $proxied_line = '?proxy_url=' . urlencode($line);
                $rewritten_content .= $proxied_line . "\n";
            }
        }
        $content = $rewritten_content;
    }
    // If it's not a playlist (e.g., a .ts video chunk), we pass it through directly.
    // --- END: NEW PLAYLIST REWRITING LOGIC ---

    header('Content-Type: ' . $content_type);
    header('Access-Control-Allow-Origin: *');
    echo $content;

    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern IPTV Player</title>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root { --background-primary: #0f0f0f; --background-secondary: #212121; --background-tertiary: #383838; --text-primary: #ffffff; --text-secondary: #aaaaaa; --accent-color: #3ea6ff; --border-color: #303030; } * { box-sizing: border-box; } body { font-family: 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background-color: var(--background-primary); color: var(--text-primary); margin: 0; overflow: hidden; } .app-container { display: grid; grid-template-columns: 350px 1fr; grid-template-rows: 60px 1fr; height: 100vh; grid-template-areas: "header header" "sidebar main"; } .header { grid-area: header; background-color: var(--background-secondary); display: flex; align-items: center; padding: 0 24px; border-bottom: 1px solid var(--border-color); } .logo { font-size: 1.5rem; font-weight: bold; } .logo .fa-play-circle { color: var(--accent-color); margin-right: 10px; } .sidebar { grid-area: sidebar; background-color: var(--background-secondary); display: flex; flex-direction: column; overflow: hidden; border-right: 1px solid var(--border-color); } .main-content { grid-area: main; background-color: #000; display: flex; justify-content: center; align-items: center; } video { width: 100%; height: 100%; } .playlist-controls { padding: 16px; border-bottom: 1px solid var(--border-color); } #m3u-url { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid var(--border-color); background-color: var(--background-primary); color: var(--text-primary); margin-bottom: 10px; } #load-btn { width: 100%; padding: 10px 15px; border: none; background-color: var(--accent-color); color: var(--background-primary); border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 1rem; } .search-container { padding: 16px; position: relative; } #search-channel { width: 100%; padding: 10px 10px 10px 40px; background-color: var(--background-primary); border: 1px solid var(--border-color); border-radius: 4px; color: var(--text-primary); } .search-container .fa-search { position: absolute; top: 50%; left: 30px; transform: translateY(-50%); color: var(--text-secondary); } .channel-list { list-style: none; padding: 0; margin: 0; overflow-y: auto; flex-grow: 1; } .channel-list li { padding: 14px 24px; cursor: pointer; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: background-color 0.2s; border-bottom: 1px solid var(--border-color); } .channel-list li:hover { background-color: var(--background-tertiary); } .channel-list li.active { background-color: var(--accent-color); color: var(--background-primary); font-weight: bold; } .error-message { margin: 16px; color: #ff4d4d; padding: 10px; background-color: rgba(255, 77, 77, 0.1); border-radius: 4px; display: none; }
    </style>
</head>
<body>

    <div class="app-container">
        <header class="header">
            <div class="logo"><i class="fas fa-play-circle"></i><span>Modern Player</span></div>
        </header>
        <aside class="sidebar">
            <div class="playlist-controls">
                <input type="text" id="m3u-url" value="https://iptv-org.github.io/iptv/index.m3u">
                <button id="load-btn">Load Playlist</button>
            </div>
            <div class="search-container">
                <i class="fas fa-search"></i>
                <input type="text" id="search-channel" placeholder="Search channels...">
            </div>
            <div id="error-message" class="error-message"></div>
            <ul id="channel-list" class="channel-list"></ul>
        </aside>
        <main class="main-content">
            <video id="iptv-player" controls autoplay></video>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const video = document.getElementById('iptv-player');
            const m3uUrlInput = document.getElementById('m3u-url');
            const loadBtn = document.getElementById('load-btn');
            const channelList = document.getElementById('channel-list');
            const searchInput = document.getElementById('search-channel');
            const errorMessage = document.getElementById('error-message');
            let hls = new Hls();
            let channels = [];

            loadBtn.addEventListener('click', loadM3U);
            searchInput.addEventListener('input', filterChannels);

            function showError(message) {
                errorMessage.textContent = message;
                errorMessage.style.display = 'block';
            }
            
            function playChannel(streamUrl) {
                const proxiedStreamUrl = `?proxy_url=${encodeURIComponent(streamUrl)}`;
                
                if (Hls.isSupported()) {
                    hls.destroy();
                    hls = new Hls();
                    hls.loadSource(proxiedStreamUrl);
                    hls.attachMedia(video);
                    hls.on(Hls.Events.MANIFEST_PARSED, () => video.play());
                    hls.on(Hls.Events.ERROR, (event, data) => {
                        if (data.fatal) {
                            console.error('HLS Fatal Error:', data);
                            showError(`HLS Error: ${data.type} - ${data.details}`);
                        }
                    });
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    video.src = proxiedStreamUrl;
                    video.play();
                }
            }

            function loadM3U() {
                const m3uUrl = m3uUrlInput.value.trim();
                if (!m3uUrl) { showError('Please enter an M3U URL.'); return; }
                const proxyUrl = `?proxy_url=${encodeURIComponent(m3uUrl)}`;
                fetch(proxyUrl)
                    .then(response => { if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`); return response.text(); })
                    .then(data => parseM3U(data))
                    .catch(error => { console.error('Error fetching M3U:', error); showError(`Failed to load playlist. Error: ${error.message}`); });
            }

            function parseM3U(data) {
                channels = []; errorMessage.style.display = 'none'; const lines = data.split('\n'); let currentChannel = {};
                lines.forEach(line => {
                    if (line.startsWith('#EXTINF:')) { const info = line.split(/,(.+)/)[1]; currentChannel.name = info ? info.trim() : 'Unnamed Channel'; } 
                    else if (line.trim() && !line.startsWith('#')) { currentChannel.url = line.trim(); if (currentChannel.name && currentChannel.url) { channels.push({ ...currentChannel }); } currentChannel = {}; }
                });
                renderChannelList(channels);
            }

            function renderChannelList(list) {
                channelList.innerHTML = '';
                list.forEach(channel => {
                    const listItem = document.createElement('li'); listItem.textContent = channel.name; listItem.dataset.url = channel.url;
                    listItem.addEventListener('click', () => { playChannel(channel.url); document.querySelectorAll('#channel-list li').forEach(li => li.classList.remove('active')); listItem.classList.add('active'); });
                    channelList.appendChild(listItem);
                });
            }

            function filterChannels() {
                const query = searchInput.value.toLowerCase(); const filteredChannels = channels.filter(channel => channel.name.toLowerCase().includes(query)); renderChannelList(filteredChannels);
            }
            
            loadM3U();
        });
    </script>
</body>
</html>
