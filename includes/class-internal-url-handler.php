<?php
/**
 * 内部URLをブログカードに変換するクラス
 */

// 直接アクセスを防止
if (!defined('ABSPATH')) {
    exit;
}

class Smart_URL_View_Internal_Handler {
    
    /**
     * ブログカードを作成
     */
    public function create_blog_card($url) {
        // URLから投稿IDを取得
        $post_id = url_to_postid($url);
        
        if (!$post_id) {
            // 投稿が見つからない場合はシンプルカードを返す
            return $this->create_simple_card($url);
        }
        
        // 投稿オブジェクトを取得
        $post = get_post($post_id);
        
        if (!$post || $post->post_status !== 'publish') {
            return $this->create_simple_card($url);
        }
        
        // キャッシュキーを生成
        $cache_key = 'smart_url_view_internal_' . $post_id . '_' . $post->post_modified;
        
        // キャッシュをチェック
        $cached_card = get_transient($cache_key);
        if ($cached_card !== false) {
            return $cached_card;
        }
        
        // 投稿情報を取得
        $title = get_the_title($post_id);
        $description = $this->get_post_excerpt($post);
        $image = $this->get_post_thumbnail($post_id);
        $site_name = get_bloginfo('name');
        $post_type = get_post_type_object($post->post_type);
        $post_type_label = $post_type ? $post_type->labels->singular_name : '投稿';
        
        $card = $this->render_card($url, $title, $description, $image, $site_name, $post_type_label);
        
        // キャッシュに保存（24時間）
        set_transient($cache_key, $card, 24 * HOUR_IN_SECONDS);
        
        return $card;
    }
    
    /**
     * 投稿の抜粋を取得
     */
    private function get_post_excerpt($post) {
        // 抜粋が設定されている場合はそれを使用
        if (!empty($post->post_excerpt)) {
            return $post->post_excerpt;
        }
        
        // コンテンツから抜粋を作成
        $content = strip_tags($post->post_content);
        $content = preg_replace('/\s+/', ' ', $content);
        $excerpt = mb_substr($content, 0, 150);
        
        if (mb_strlen($content) > 150) {
            $excerpt .= '...';
        }
        
        return $excerpt;
    }
    
    /**
     * 投稿のサムネイル画像を取得
     */
    private function get_post_thumbnail($post_id) {
        // アイキャッチ画像が設定されている場合
        if (has_post_thumbnail($post_id)) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $image_array = wp_get_attachment_image_src($thumbnail_id, 'medium');
            
            if ($image_array) {
                return $image_array[0];
            }
        }
        
        // アイキャッチがない場合、コンテンツから最初の画像を取得
        $post = get_post($post_id);
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches)) {
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * ブログカードをレンダリング
     */
    private function render_card($url, $title, $description, $image, $site_name, $post_type_label) {
        $has_valid_image = !empty($image);
        
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '" class="smart-url-view-link">';
        
        // サムネイル画像がある場合のみ表示
        if ($has_valid_image) {
            $card_html .= '<div class="smart-url-view-thumbnail">';
            $card_html .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($title) . '" loading="lazy">';
            $card_html .= '</div>';
        }
        
        // テキストコンテンツ
        $card_html .= '<div class="smart-url-view-content">';
        $card_html .= '<div class="smart-url-view-title">' . esc_html($title) . '</div>';
        
        if (!empty($description)) {
            $card_html .= '<div class="smart-url-view-description">' . esc_html($description) . '</div>';
        }
        
        $card_html .= '<div class="smart-url-view-site">' . esc_html($site_name) . '</div>';
        $card_html .= '</div>'; // .smart-url-view-content
        
        $card_html .= '</a>';
        $card_html .= '</div>'; // .smart-url-view-card
        
        return $card_html;
    }
    
    /**
     * シンプルなカードを作成
     */
    private function create_simple_card($url) {
        $card_html = '<div class="smart-url-view-card">';
        $card_html .= '<a href="' . esc_url($url) . '" class="smart-url-view-link">';
        $card_html .= '<div class="smart-url-view-content">';
        $card_html .= '<div class="smart-url-view-title">' . esc_html($url) . '</div>';
        $card_html .= '<div class="smart-url-view-site">' . esc_html(get_bloginfo('name')) . '</div>';
        $card_html .= '</div>';
        $card_html .= '</a>';
        $card_html .= '</div>';
        
        return $card_html;
    }
}

