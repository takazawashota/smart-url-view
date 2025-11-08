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

// ハンドラークラスを読み込み
require_once plugin_dir_path(__FILE__) . 'includes/class-external-url-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-internal-url-handler.php';

class SmartUrlView {
    
    private static $instance = null;
    private $external_handler;
    private $internal_handler;
    
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
        // ハンドラーを初期化
        $this->external_handler = new Smart_URL_View_External_Handler();
        $this->internal_handler = new Smart_URL_View_Internal_Handler();
        
        // WordPressの自動embed機能を無効化（内部リンクのみ）
        add_filter('embed_oembed_discover', array($this, 'disable_internal_embeds'), 10, 2);
        
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
     * 内部リンクの自動embed機能を無効化
     */
    public function disable_internal_embeds($enable, $url) {
        $site_url = get_site_url();
        
        // 内部URLの場合はembedを無効化
        if (strpos($url, $site_url) === 0) {
            return false;
        }
        
        return $enable;
    }
    
    /**
     * コンテンツ内のURLをブログカードに変換
     */
    public function convert_urls_to_cards($content) {
        // 空のコンテンツは処理しない
        if (empty($content)) {
            return $content;
        }
        
        $site_url = get_site_url();
        
        // WordPressの内部embed（blockquote + iframe）からURLを抽出してブログカードに変換
        $pattern_wp_embed = '/<p>\s*<blockquote class="wp-embedded-content"[^>]*>\s*<a href=["\']([^"\']+)["\'][^>]*>.*?<\/a>\s*<\/blockquote>\s*<iframe class="wp-embedded-content"[^>]*>.*?<\/iframe>\s*<\/p>/is';
        
        if (preg_match_all($pattern_wp_embed, $content, $matches_wp_embed)) {
            foreach ($matches_wp_embed[1] as $i => $url) {
                // 内部URLかチェック
                $is_internal = (strpos($url, $site_url) === 0);
                
                // URLをブログカードに変換
                if ($is_internal) {
                    $card = $this->internal_handler->create_blog_card($url);
                } else {
                    $card = $this->external_handler->create_blog_card($url);
                }
                
                // 元のembedをカードで置換
                $content = str_replace($matches_wp_embed[0][$i], $card, $content);
            }
        }
        
        // 内部URLを処理（Gutenberg embed block）
        $pattern_internal_embed = '/<figure class="wp-block-embed[^"]*">\s*<div class="wp-block-embed__wrapper">\s*(' . preg_quote($site_url, '/') . '[^\s<>"]*?)\s*<\/div>\s*<\/figure>/is';
        $content = preg_replace_callback($pattern_internal_embed, array($this, 'handle_internal_url'), $content);
        
        // 外部URLを処理（Gutenberg embed block）
        $pattern_external_embed = '/<figure class="wp-block-embed[^"]*">\s*<div class="wp-block-embed__wrapper">\s*(https?:\/\/(?!' . preg_quote(parse_url($site_url, PHP_URL_HOST), '/') . ')[^\s<>"]+?)\s*<\/div>\s*<\/figure>/is';
        $content = preg_replace_callback($pattern_external_embed, array($this, 'handle_external_url'), $content);
        
        // パターン1: <p>タグで囲まれた独立したURL
        $pattern1 = '/<p>\s*(<a[^>]+>)?(https?:\/\/[^\s<>"]+?)(<\/a>)?\s*<\/p>/i';
        $content = preg_replace_callback($pattern1, array($this, 'handle_url_in_p_tag'), $content);
        
        // パターン2: <p>タグ内のリンクタグで囲まれたURL
        $pattern2 = '/<p>\s*<a[^>]+href=["\']([^"\']+)["\'][^>]*>\1<\/a>\s*<\/p>/i';
        $content = preg_replace_callback($pattern2, array($this, 'handle_link_in_p_tag'), $content);
        
        // パターン3: 独立した行のURL
        $pattern3 = '/^[ \t]*(https?:\/\/[^\s<>"]+?)[ \t]*$/m';
        $content = preg_replace_callback($pattern3, array($this, 'create_blog_card'), $content);
        
        return $content;
    }
    
    /**
     * 内部URLを処理
     */
    public function handle_internal_url($matches) {
        $url = $matches[1];
        return $this->internal_handler->create_blog_card($url);
    }
    
    /**
     * 外部URLを処理
     */
    public function handle_external_url($matches) {
        $url = $matches[1];
        return $this->external_handler->create_blog_card($url);
    }
    
    /**
     * Gutenbergのembed blockを処理（汎用）
     */
    public function handle_gutenberg_embed($matches) {
        $url = $matches[1];
        return $this->create_blog_card(array(0 => $matches[0], 1 => $url));
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
        
        // 自サイトのURLかどうかを判定
        $site_url = get_site_url();
        $is_internal = (strpos($url, $site_url) === 0);
        
        if ($is_internal) {
            // 内部URLの場合
            return $this->internal_handler->create_blog_card($url);
        } else {
            // 外部URLの場合
            return $this->external_handler->create_blog_card($url);
        }
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