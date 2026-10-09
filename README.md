# pusyuu_registry_client

プシューサービスの台帳 API (pusyuu_registry) を読む係です。
各プロダクトは、自分以外の住所・サーバー間の接続先・一覧に出すプロダクトを、このファイルを通して台帳から受け取ります。
ドメインやホスト名をプロダクトの中に書かずに済みます。

## 台帳に繋がらないことを前提にしています

1. 手元の控え(キャッシュ)で答え、台帳 API へは一定時間ごとに取りに行くだけです
2. 待つのは数秒まで(既定1秒)なので、台帳が遅くてもページ全体は巻き込まれません
3. 正しい中身が返ってきたときだけ控えを更新します
4. 控えが一度も無いまま読めないときは `null` を返します。呼ぶ側は、それを必要とする機能だけを止めてください

## 使い方

```php
$directry = __DIR__ . '/pusyuu_registry_client.php';
if (file_exists($directry)) {
  require_once($directry);
  PusyuuRegistryClient::init($commonDir);  // 控えの置き場(共有の非公開フォルダ)
}

if (class_exists('PusyuuRegistryClient')) {
  $url = PusyuuRegistryClient::url('p-meikiee', '/path');  // 読めなければ null
}
```

主なメソッド: `data()` / `product()` / `url()` / `setting()` / `links()` / `route()` / `call()`

自分のページへのリンクやフォームの送信先には使わず、相対 URL で書いてください。台帳が止まっても基本機能が動き続けるようにするためです。

読み込んだ直後に `init()` を呼んでください。初期化より前に台帳を読むと、その要求の間ずっと控えの置き場が無いまま扱われます。
このファイルはほかのファイルを読みに行きません。

## ライセンス

[MIT License](LICENSE) © 2026 ISAMI ABE
