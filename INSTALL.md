# Smart URL View - インストールガイド

## 前提条件

- WordPress 5.0以上
- PHP 7.0以上
- cURL拡張モジュール

## インストール手順

### 1. ファイルの配置

プラグインのファイル構成は以下の通りです：

```
smart-url-view/
├── smart-url-view.php    # メインプラグインファイル
├── OpenGraph.php         # Open Graphパーサー
├── assets/
│   └── css/
│       └── style.css     # スタイルシート
├── README.md
└── INSTALL.md
```

### 2. WordPressへのアップロード

#### 方法A: FTP/SFTPでアップロード

1. `smart-url-view`フォルダ全体をWordPressサーバーの`/wp-content/plugins/`ディレクトリにアップロードします

2. ファイルのパーミッションを確認します：
   - ディレクトリ: 755
   - PHPファイル: 644
   - CSSファイル: 644

3. WordPressの管理画面にログインします

4. 「プラグイン」メニューを開きます

5. 「Smart URL View」を見つけて「有効化」ボタンをクリックします

#### 方法B: 管理画面からアップロード

1. プラグインフォルダをZIP形式で圧縮します：
   ```bash
   cd /path/to/plugins/
   zip -r smart-url-view.zip smart-url-view/
   ```

2. WordPressの管理画面から「プラグイン」→「新規追加」を選択

3. 「プラグインのアップロード」ボタンをクリック

4. 作成したZIPファイルを選択してアップロード

5. 「今すぐインストール」をクリック

6. インストール完了後、「プラグインを有効化」をクリック

### 3. 動作確認

1. 新しい投稿または固定ページを作成します

2. エディタに以下のようにURLを独立した行に記入します：
   ```
   これはテストです。

   https://github.com

   上記のURLがブログカードとして表示されます。
   ```

3. 投稿を公開またはプレビューします

4. URLがブログカードとして表示されることを確認します

### 4. トラブルシューティング

#### ブログカードが表示されない場合

**問題1: URLが変換されない**
- URLが独立した行に記載されているか確認
- 前後に空行があるか確認
- 自サイトのURLでないか確認

**問題2: 画像が表示されない**
- 対象サイトにOpen Graphデータがあるか確認
- 外部サイトへのHTTPリクエストが許可されているか確認
- PHPのcURL拡張が有効か確認：
  ```bash
  php -m | grep curl
  ```

**問題3: スタイルが適用されない**
- ブラウザのキャッシュをクリア
- WordPressのキャッシュプラグインをクリア
- CSSファイルのパスが正しいか確認

#### デバッグモードを有効にする

`wp-config.php`に以下を追加してエラーログを確認：

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

エラーログは`/wp-content/debug.log`に出力されます。

#### キャッシュのクリア

```php
// wp-config.phpまたはfunctions.phpに一時的に追加
delete_option('_transient_smart_url_view_%');
delete_option('_transient_timeout_smart_url_view_%');
```

または、プラグインを無効化→再有効化することでキャッシュがクリアされます。

## アンインストール

1. WordPressの管理画面から「プラグイン」メニューを開きます

2. 「Smart URL View」を見つけて「無効化」をクリックします

3. 「削除」をクリックします

4. キャッシュデータは無効化時に自動的にクリアされます

## カスタマイズ

### テーマでスタイルを上書きする

テーマの`style.css`または子テーマのCSSファイルに以下を追加：

```css
/* ブログカードの背景色を変更 */
.smart-url-view-card {
    background-color: #f0f0f0;
    border-color: #d0d0d0;
}

/* タイトルの色を変更 */
.smart-url-view-title {
    color: #0066cc;
}

/* ホバー時の影を調整 */
.smart-url-view-card:hover {
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}
```

### キャッシュ時間を変更する

`smart-url-view.php`の以下の行を変更：

```php
// 24時間 → 48時間に変更
set_transient($cache_key, $card, 48 * HOUR_IN_SECONDS);
```

### URLパターンをカスタマイズする

`smart-url-view.php`の`convert_urls_to_cards`メソッド内の正規表現を変更：

```php
// 現在のパターン（独立した行のみ）
$pattern = '/^\s*(https?:\/\/[^\s<>"]+?)\s*$/m';

// 例: 文中のURLも変換する場合（推奨しません）
$pattern = '/(https?:\/\/[^\s<>"]+)/';
```

## サポート

問題が解決しない場合は、以下の情報を含めてお問い合わせください：

- WordPressのバージョン
- PHPのバージョン
- 使用しているテーマ
- 他のアクティブなプラグイン
- エラーメッセージ（ある場合）

## 次のステップ

- デモページ（`demo.html`）をブラウザで開いてデザインを確認
- 様々な外部サイトのURLでテスト
- 必要に応じてCSSをカスタマイズ
