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
        
        // 管理画面メニューを追加
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // 管理画面での処理
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }
    
    /**
     * 管理画面メニューを追加
     */
    public function add_admin_menu() {
        add_options_page(
            'Smart URL View 設定',
            'Smart URL View',
            'manage_options',
            'smart-url-view',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * 管理画面での処理
     */
    public function handle_admin_actions() {
        // 管理者のみ実行可能
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // HTMLキャッシュ削除
        if (isset($_POST['clear_html_cache']) && check_admin_referer('smart_url_view_clear_html_cache')) {
            $this->clear_html_cache();
            add_settings_error(
                'smart_url_view_messages',
                'smart_url_view_message',
                'HTMLキャッシュを削除しました。',
                'updated'
            );
        }
        
        // 画像キャッシュ削除
        if (isset($_POST['clear_image_cache']) && check_admin_referer('smart_url_view_clear_image_cache')) {
            $this->clear_image_cache();
            add_settings_error(
                'smart_url_view_messages',
                'smart_url_view_message',
                '画像キャッシュを削除しました。',
                'updated'
            );
        }
        
        // 全キャッシュ削除
        if (isset($_POST['clear_all_cache']) && check_admin_referer('smart_url_view_clear_all_cache')) {
            $this->clear_html_cache();
            $this->clear_image_cache();
            add_settings_error(
                'smart_url_view_messages',
                'smart_url_view_message',
                'すべてのキャッシュを削除しました。',
                'updated'
            );
        }
    }
    
    /**
     * 管理画面ページをレンダリング
     */
    public function render_admin_page() {
        // 権限チェック
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // キャッシュ情報を取得
        $cache_info = $this->get_cache_info();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors('smart_url_view_messages'); ?>
            
            <style>
                .smart-url-view-admin-card {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                    margin: 20px 0;
                    padding: 20px;
                }
                .smart-url-view-admin-card h2 {
                    margin-top: 0;
                    padding-top: 0;
                }
            </style>
            
            <div class="smart-url-view-admin-card">
                <h2>キャッシュ管理</h2>
                <p>Smart URL Viewは外部URLの情報と画像をキャッシュして、ページの表示速度を向上させます。</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">HTMLキャッシュ</th>
                        <td>
                            <p class="description">
                                ブログカードのHTML（タイトル、説明文、サイト名など）をキャッシュします。<br>
                                <strong>件数:</strong> <?php echo esc_html($cache_info['html_count']); ?> 件
                            </p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('smart_url_view_clear_html_cache'); ?>
                                <button type="submit" name="clear_html_cache" class="button">HTMLキャッシュを削除</button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">画像キャッシュ</th>
                        <td>
                            <p class="description">
                                外部サイトからダウンロードした画像をキャッシュします。<br>
                                <strong>件数:</strong> <?php echo esc_html($cache_info['image_count']); ?> 件<br>
                                <strong>サイズ:</strong> <?php echo esc_html($cache_info['image_size']); ?>
                            </p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('smart_url_view_clear_image_cache'); ?>
                                <button type="submit" name="clear_image_cache" class="button">画像キャッシュを削除</button>
                            </form>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">すべてのキャッシュ</th>
                        <td>
                            <p class="description">HTMLキャッシュと画像キャッシュの両方を削除します。</p>
                            <form method="post" style="margin-top: 10px;">
                                <?php wp_nonce_field('smart_url_view_clear_all_cache'); ?>
                                <button type="submit" name="clear_all_cache" class="button button-primary" onclick="return confirm('すべてのキャッシュを削除してもよろしいですか？');">すべてのキャッシュを削除</button>
                            </form>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="smart-url-view-admin-card">
                <h2>プラグインについて</h2>
                <p>Smart URL Viewは、投稿・固定ページ・カスタム投稿タイプ内の外部URLを自動的にブログカードに変換します。</p>
                <ul>
                    <li>Open Graphプロトコルを使用して、リンク先の情報を自動取得</li>
                    <li>キャッシュ機能により、高速表示を実現</li>
                    <li>レスポンシブデザイン対応</li>
                    <li>ダークモード対応</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * キャッシュ情報を取得
     */
    private function get_cache_info() {
        global $wpdb;
        
        // HTMLキャッシュの件数を取得
        $html_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_url_view_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        // 画像キャッシュの情報を取得
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/smart-url-view/';
        
        $image_count = 0;
        $image_size = 0;
        
        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '*');
            if ($files) {
                $image_count = count($files);
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $image_size += filesize($file);
                    }
                }
            }
        }
        
        // サイズを人間が読みやすい形式に変換
        $units = array('B', 'KB', 'MB', 'GB');
        $image_size_readable = $image_size;
        $unit_index = 0;
        
        while ($image_size_readable >= 1024 && $unit_index < count($units) - 1) {
            $image_size_readable /= 1024;
            $unit_index++;
        }
        
        $image_size_readable = round($image_size_readable, 2) . ' ' . $units[$unit_index];
        
        return array(
            'html_count' => $html_count,
            'image_count' => $image_count,
            'image_size' => $image_size_readable
        );
    }
    
    /**
     * HTMLキャッシュを削除
     */
    private function clear_html_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_url_view_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smart_url_view_%'");
    }
    
    /**
     * 画像キャッシュを削除
     */
    private function clear_image_cache() {
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
    // HTMLキャッシュのみクリア（画像キャッシュは残す）
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_url_view_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smart_url_view_%'");
    
    // 注意: 画像キャッシュは削除しません
    // 画像キャッシュを削除したい場合は、管理画面から手動で削除してください
}
register_deactivation_hook(__FILE__, 'smart_url_view_deactivate');