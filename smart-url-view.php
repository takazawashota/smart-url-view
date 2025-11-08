<?php
/**
 * Plugin Name: Smart URL View
 * Plugin URI: https://example.com/smart-url-view
 * Description: 投稿・固定ページ・カスタム投稿タイプ内の外部URLを自動的にブログカードに変換するプラグイン
 * Version: 1.0.0
 * Author: Shota Takazawa
 * Author URI: https://example.com
 * License: GPL2
 * Text Domain: smart-url-view
 */

// 直接アクセスを防止
if (!defined('ABSPATH')) {
    exit;
}

// OpenGraphクラスを読み込み
require_once plugin_dir_path(__FILE__) . 'OpenGraph.php';

class SmartUrlView {
    
    private static $instance = null;
    
    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * コンストラクタ
     */
    private function __construct() {
        // コンテンツフィルターを追加
        // wpautop (優先度10) の後に実行するため、優先度を20に設定
        add_filter('the_content', array($this, 'convert_urls_to_cards'), 20);
        
        // スタイルシートを読み込み
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        
        // 管理画面用のスタイル
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        
        // 管理バーにキャッシュ削除ボタンを追加
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        
        // キャッシュ削除処理
        add_action('admin_init', array($this, 'handle_cache_clear'));
        
        // 成功メッセージを表示
        add_action('admin_notices', array($this, 'show_cache_cleared_notice'));
    }
    
    /**
     * 管理バーにメニューを追加
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        // 管理者のみ表示
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $args = array(
            'id'    => 'smart-url-view-cache',
            'title' => '<span class="ab-icon dashicons dashicons-update"></span> Smart URL View キャッシュ削除',
            'href'  => wp_nonce_url(admin_url('?smart_url_view_clear_cache=1'), 'smart_url_view_clear_cache'),
            'meta'  => array(
                'title' => 'Smart URL Viewのキャッシュを削除'
            )
        );
        $wp_admin_bar->add_node($args);
    }
    
    /**
     * キャッシュ削除処理
     */
    public function handle_cache_clear() {
        // 管理者のみ実行可能
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // ノンスチェック
        if (!isset($_GET['smart_url_view_clear_cache']) || 
            !isset($_GET['_wpnonce']) || 
            !wp_verify_nonce($_GET['_wpnonce'], 'smart_url_view_clear_cache')) {
            return;
        }
        
        // キャッシュ削除実行
        $this->clear_all_cache();
        
        // リファラーを取得（元のページURL）
        $redirect_url = wp_get_referer();
        
        // リファラーがない場合は現在のURLから推測
        if (!$redirect_url) {
            $redirect_url = remove_query_arg(array('smart_url_view_clear_cache', '_wpnonce'));
        } else {
            // リファラーURLからキャッシュ削除パラメータを削除
            $redirect_url = remove_query_arg(array('smart_url_view_clear_cache', '_wpnonce'), $redirect_url);
        }
        
        // 成功メッセージをクエリパラメータで追加
        $redirect_url = add_query_arg('smart_url_view_cache_cleared', '1', $redirect_url);
        
        // リダイレクト
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * 全キャッシュを削除
     */
    private function clear_all_cache() {
        global $wpdb;
        
        // トランジェントキャッシュを削除
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_url_view_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smart_url_view_%'");
        
        // 画像キャッシュディレクトリを削除
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/smart-url-view/';
        
        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }
    
    /**
     * キャッシュ削除後の通知を表示
     */
    public function show_cache_cleared_notice() {
        if (isset($_GET['smart_url_view_cache_cleared']) && $_GET['smart_url_view_cache_cleared'] == '1') {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Smart URL View:</strong> キャッシュを削除しました。</p>';
            echo '</div>';
        }
    }
    
    /**
     * スタイルシートを読み込み
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'smart-url-view',
            plugin_dir_url(__FILE__) . 'assets/css/style.css',
            array(),
            '1.0.0'
        );
    }
    
    /**
     * コンテンツ内のURLをブログカードに変換
     */
    public function convert_urls_to_cards($content) {
        // 空のコンテンツは処理しない
        if (empty($content)) {
            return $content;
        }
        
        // パターン1: <p>タグで囲まれた独立したURL（最も一般的）
        $pattern1 = '/<p>\s*(<a[^>]+>)?(https?:\/\/[^\s<>"]+?)(<\/a>)?\s*<\/p>/i';
        $content = preg_replace_callback($pattern1, array($this, 'handle_url_in_p_tag'), $content);
        
        // パターン2: <p>タグ内のリンクタグで囲まれたURL（Gutenbergが自動生成）
        $pattern2 = '/<p>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>\1<\/a>\s*<\/p>/i';
        $content = preg_replace_callback($pattern2, array($this, 'handle_link_in_p_tag'), $content);
        
        // パターン3: 独立した行のURL（念のため）
        $pattern3 = '/^[ \t]*(https?:\/\/[^\s<>"]+?)[ \t]*$/m';
        $content = preg_replace_callback($pattern3, array($this, 'create_blog_card'), $content);
        
        return $content;
    }
    
    /**
     * <p>タグ内のURLを処理
     */
    public function handle_url_in_p_tag($matches) {
        $url = $matches[2];
        return $this->create_blog_card(array(0 => $matches[0], 1 => $url));
    }
    
    /**
     * <p>タグ内のリンクタグを処理（Gutenberg対応）
     */
    public function handle_link_in_p_tag($matches) {
        $url = $matches[1];
        return $this->create_blog_card(array(0 => $matches[0], 1 => $url));
    }
    
    /**
     * ブログカードを作成
     */
    public function create_blog_card($matches) {
        $url = $matches[1];
        
        // 自サイトのURLは除外
        $site_url = get_site_url();
        if (strpos($url, $site_url) === 0) {
            return $matches[0];
        }
        
        // キャッシュキーを生成
        $cache_key = 'smart_url_view_' . md5($url);
        
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
            
            // キャッシュに保存（24時間）
            set_transient($cache_key, $card, 24 * HOUR_IN_SECONDS);
            
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
        // より大きなサイズで保存することで、ボケを防ぐ
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
        // 空の場合はそのまま返す
        if (empty($image_url)) {
            return '';
        }
        
        // 画像が相対パスまたは無効な場合
        // 1. //が含まれていない（相対パス）
        // 2. HTTPSサイトでHTTP画像を使用している
        if (strpos($image_url, '//') === false || 
            (is_ssl() && strpos($image_url, 'https:') === false && strpos($image_url, '//') === 0)) {
            return '';
        }
        
        // プロトコル相対URLの場合、HTTPSに変換
        if (strpos($image_url, '//') === 0) {
            $image_url = 'https:' . $image_url;
        }
        // HTTPで始まる場合、HTTPSに変換（セキュリティ対策）
        elseif (strpos($image_url, 'http://') === 0) {
            $image_url = str_replace('http://', 'https://', $image_url);
        }
        // 相対パスの場合、絶対URLに変換
        elseif (strpos($image_url, '/') === 0) {
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
        
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="smart-url-view-link">';
        
        // サムネイル画像がある場合（有効な画像URLの場合のみ）
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
        
        $card_html = '<div class="smart-url-view-card smart-url-view-simple">';
        $card_html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="smart-url-view-link">';
        $card_html .= '<div class="smart-url-view-content">';
        $card_html .= '<div class="smart-url-view-title">' . esc_html($url) . '</div>';
        $card_html .= '<div class="smart-url-view-site">' . esc_html($host) . '</div>';
        $card_html .= '</div>';
        $card_html .= '</a>';
        $card_html .= '</div>';
        
        // シンプルカードもキャッシュ（エラーを繰り返さないため）
        $cache_key = 'smart_url_view_' . md5($url);
        set_transient($cache_key, $card_html, 24 * HOUR_IN_SECONDS);
        
        return $card_html;
    }
}

// プラグインを初期化
function smart_url_view_init() {
    SmartUrlView::get_instance();
}
add_action('init', 'smart_url_view_init');

/**
 * プラグイン有効化時の処理
 */
function smart_url_view_activate() {
    // 特に処理なし
}
register_activation_hook(__FILE__, 'smart_url_view_activate');

/**
 * プラグイン無効化時の処理
 */
function smart_url_view_deactivate() {
    // キャッシュをクリア
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_url_view_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smart_url_view_%'");
    
    // 画像キャッシュディレクトリを削除
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/smart-url-view/';
    
    if (file_exists($cache_dir)) {
        // ディレクトリ内のファイルを削除
        $files = glob($cache_dir . '*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        // ディレクトリを削除
        @rmdir($cache_dir);
    }
}
register_deactivation_hook(__FILE__, 'smart_url_view_deactivate');
