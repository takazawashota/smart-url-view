<?php
/**
 * Plugin Name: Smart URL View
 * Plugin URI: https://example.com/smart-url-view
 * Description: 投稿・固定ページ・カスタム投稿タイプ内の外部URLを自動的にブログカードに変換するプラグイン
 * Version: 1.0.0
 * Author: Your Name
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
            
            $card = $this->render_card($url, $title, $description, $image, $site_name);
            
            // キャッシュに保存（24時間）
            set_transient($cache_key, $card, 24 * HOUR_IN_SECONDS);
            
            return $card;
            
        } catch (Exception $e) {
            return $this->create_simple_card($url);
        }
    }
    
    /**
     * ブログカードをレンダリング
     */
    private function render_card($url, $title, $description, $image, $site_name) {
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="smart-url-view-link">';
        
        // サムネイル画像がある場合
        if (!empty($image)) {
            $card_html .= '<div class="smart-url-view-thumbnail">';
            $card_html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" loading="lazy">';
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
}
register_deactivation_hook(__FILE__, 'smart_url_view_deactivate');
