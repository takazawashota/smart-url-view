<?php
/**
 * 外部URLをブログカードに変換するクラス
 */

// 直接アクセスを防止
if (!defined('ABSPATH')) {
    exit;
}

class Smart_URL_View_External_Handler {
    
    /**
     * ブログカードを作成
     */
    public function create_blog_card($url) {
        // target="_blank"の設定を取得
        $external_blank = get_option('smart_url_view_external_blank', '1');
        
        // キャッシュキーを生成（設定値を含める）
        $cache_key = 'smart_url_view_external_' . md5($url . '_blank_' . $external_blank);
        
        // キャッシュをチェック（24時間）
        $cached_card = get_transient($cache_key);
        if ($cached_card !== false) {
            return $cached_card;
        }
        
        // Open Graphデータを取得
        try {
            $graph = OpenGraph::fetch($url);
            
            if (!$graph) {
                return $this->create_simple_card($url);
            }
            
            $title = $graph->title ?: $url;
            $description = $graph->description ?: '';
            $image = $graph->image ?: '';
            $site_name = $graph->site_name ?: parse_url($url, PHP_URL_HOST);
            
            // 画像URLを検証・修正
            $image = $this->validate_image_url($image, $url);
            
            // 画像をキャッシュに保存
            if (!empty($image)) {
                $cached_image = $this->fetch_and_cache_image($image, $url);
                if ($cached_image) {
                    $image = $cached_image;
                }
            }
            
            $card = $this->render_card($url, $title, $description, $image, $site_name);
            
            // キャッシュに保存
            $cache_duration = get_option('smart_url_view_cache_duration', 24);
            set_transient($cache_key, $card, $cache_duration * HOUR_IN_SECONDS);
            
            return $card;
            
        } catch (Exception $e) {
            return $this->create_simple_card($url);
        }
    }
    
    /**
     * 外部画像を取得してキャッシュディレクトリに保存
     */
    private function fetch_and_cache_image($image_url, $page_url) {
        // URLのクエリパラメータを削除
        $image_url_clean = preg_replace('/\?.*$/i', '', $image_url);
        
        // キャッシュディレクトリのパス
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/smart-url-view/';
        $cache_url = $upload_dir['baseurl'] . '/smart-url-view/';
        
        // ディレクトリが存在しない場合は作成
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // 画像をダウンロード
        $response = wp_remote_get($image_url, array(
            'timeout' => 15,
            'sslverify' => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        
        if (empty($image_data)) {
            return null;
        }
        
        // Content-TypeヘッダーからMIMEタイプを取得
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // MIMEタイプから正しい拡張子を決定
        $mime_to_ext = array(
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        );
        
        // Content-TypeからベースのMIMEタイプを抽出（charset等を除去）
        $mime_type = strtok($content_type, ';');
        $mime_type = trim($mime_type);
        
        // MIMEタイプに基づいて拡張子を決定
        if (isset($mime_to_ext[$mime_type])) {
            $ext = $mime_to_ext[$mime_type];
        } else {
            // MIMEタイプが不明な場合、URLから拡張子を取得
            $ext = strtolower(pathinfo($image_url_clean, PATHINFO_EXTENSION));
            $allowed_exts = array('png', 'jpg', 'jpeg', 'gif', 'webp');
            
            if (!in_array($ext, $allowed_exts)) {
                // それでも不明な場合は画像データから判定
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detected_mime = $finfo->buffer($image_data);
                
                if (isset($mime_to_ext[$detected_mime])) {
                    $ext = $mime_to_ext[$detected_mime];
                } else {
                    // 最終手段としてpngを使用
                    $ext = 'png';
                }
            }
        }
        
        // キャッシュファイル名（画像URLのMD5ハッシュ）
        $cache_filename = md5($image_url) . '.' . $ext;
        $cache_filepath = $cache_dir . $cache_filename;
        $cache_fileurl = $cache_url . $cache_filename;
        
        // すでにキャッシュが存在する場合はそのURLを返す
        if (file_exists($cache_filepath)) {
            return $cache_fileurl;
        }
        
        // ファイルシステムに保存
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        // 一時ファイルとして保存
        $temp_file = $cache_filepath . '.tmp';
        if (!$wp_filesystem->put_contents($temp_file, $image_data, FS_CHMOD_FILE)) {
            return null;
        }
        
        // 画像エディタで画像を読み込んでリサイズ
        $image_editor = wp_get_image_editor($temp_file);
        
        if (is_wp_error($image_editor)) {
            @unlink($temp_file);
            return null;
        }
        
        // 現在の画像サイズを取得
        $current_size = $image_editor->get_size();
        $current_width = $current_size['width'];
        $current_height = $current_size['height'];
        
        // 画像をリサイズ（400x400に収まるように、アスペクト比を維持）
        $max_width = 400;
        $max_height = 400;
        
        // 画像が既に小さい場合はリサイズしない
        if ($current_width > $max_width || $current_height > $max_height) {
            $image_editor->resize($max_width, $max_height, false);
        }
        
        // 品質を高く設定して保存（JPEGの場合は90%の品質）
        $save_args = array(
            'quality' => 90
        );
        
        $saved = $image_editor->save($cache_filepath, null, $save_args);
        
        // 一時ファイルを削除
        @unlink($temp_file);
        
        if (is_wp_error($saved)) {
            return null;
        }
        
        return $cache_fileurl;
    }
    
    /**
     * 画像URLを検証・修正
     */
    private function validate_image_url($image_url, $page_url) {
        if (empty($image_url)) {
            return '';
        }
        
        // プロトコル相対URL（//example.com/image.jpg）の場合
        if (strpos($image_url, '//') === 0) {
            $parsed_url = parse_url($page_url);
            $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'https';
            $image_url = $scheme . ':' . $image_url;
        }
        // 絶対パス（/images/image.jpg）の場合
        elseif (strpos($image_url, '/') === 0 && strpos($image_url, '//') !== 0) {
            $parsed_url = parse_url($page_url);
            $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'https';
            $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
            
            if ($host) {
                $image_url = $scheme . '://' . $host . $image_url;
            } else {
                return '';
            }
        }
        // 完全な相対パス（http(s)で始まらない）
        elseif (strpos($image_url, 'http') !== 0) {
            $parsed_url = parse_url($page_url);
            $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] : 'https';
            $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
            $path = isset($parsed_url['path']) ? dirname($parsed_url['path']) : '';
            
            if ($host) {
                $base_url = $scheme . '://' . $host . $path;
                $image_url = rtrim($base_url, '/') . '/' . ltrim($image_url, '/');
            } else {
                return '';
            }
        }
        
        // URLが有効かチェック
        if (filter_var($image_url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        
        // 画像ファイルの拡張子チェック
        $image_url_without_query = preg_replace('/\?.*$/i', '', $image_url);
        $allowed_exts = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');
        $ext = strtolower(pathinfo($image_url_without_query, PATHINFO_EXTENSION));
        
        // 拡張子がない、または許可されていない場合は空を返す
        if (empty($ext) || !in_array($ext, $allowed_exts)) {
            return '';
        }
        
        return $image_url;
    }
    
    /**
     * ブログカードをレンダリング
     */
    private function render_card($url, $title, $description, $image, $site_name) {
        // 画像URLが有効かチェック（空文字列でない、かつ有効なURL）
        $has_valid_image = !empty($image) && filter_var($image, FILTER_VALIDATE_URL) !== false;
        
        // target="_blank"の設定を確認
        $external_blank = get_option('smart_url_view_external_blank', '1');
        $target_attr = ($external_blank === '1') ? ' target="_blank" rel="noopener noreferrer"' : '';
        
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '"' . $target_attr . ' class="smart-url-view-link">';
        
        // サムネイル画像がある場合のみ表示
        if ($has_valid_image) {
            $card_html .= '<div class="smart-url-view-thumbnail">';
            $card_html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" loading="lazy" onerror="this.parentElement.style.display=\'none\'">';
            $card_html .= '</div>';
        }
        
        // テキストコンテンツ
        $card_html .= '<div class="smart-url-view-content">';
        $card_html .= '<div class="smart-url-view-title">' . esc_html($title) . '</div>';
        
        if (!empty($description)) {
            // 説明文を150文字に制限
            $short_description = mb_substr($description, 0, 150);
            if (mb_strlen($description) > 150) {
                $short_description .= '...';
            }
            $card_html .= '<div class="smart-url-view-description">' . esc_html($short_description) . '</div>';
        }
        
        $card_html .= '<div class="smart-url-view-site">' . esc_html($site_name) . '</div>';
        $card_html .= '</div>'; // .smart-url-view-content
        
        $card_html .= '</a>';
        $card_html .= '</div>'; // .smart-url-view-card
        
        return $card_html;
    }
    
    /**
     * シンプルなカードを作成（Open Graphデータが取得できない場合）
     */
    private function create_simple_card($url) {
        $host = parse_url($url, PHP_URL_HOST);
        
        // target="_blank"の設定を確認
        $external_blank = get_option('smart_url_view_external_blank', '1');
        $target_attr = ($external_blank === '1') ? ' target="_blank" rel="noopener noreferrer"' : '';
        
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '"' . $target_attr . ' class="smart-url-view-link">';
        $card_html .= '<div class="smart-url-view-content">';
        $card_html .= '<div class="smart-url-view-title">' . esc_html($url) . '</div>';
        $card_html .= '<div class="smart-url-view-site">' . esc_html($host) . '</div>';
        $card_html .= '</div>';
        $card_html .= '</a>';
        $card_html .= '</div>';
        
        return $card_html;
    }
}
