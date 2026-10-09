<?php
/*****************************************
  *----------------------------------
  |  ThisScriptVersion: 1.0.0     |
  |  © 2026 By ISAMI ABE          |
  |  LastUpdate: 2026-10-07       |
  |  License: MIT License         |
  |  pusyuu_registry_client (台帳を読む係) |
----------------------------------*

  プシューサービスで「絶対」とみなす住所は、台帳API
  (https://21emon.wjg.jp/pusyuu_registry/)ただ1つです。各プロダクトは、自分以外の
  住所・サーバ間で繋ぐ先・一覧に出すプロダクトを、このファイルを通して台帳から受け取ります。
  ドメインやホスト名を、プロダクトの中に define / const で書かないでください。

  【ゼロトラストの約束】台帳APIには繋がらないことがある、という前提で作っています。
    1. 手元の控え(キャッシュ)で答えます。台帳APIへは「取りに行く」だけです。
    2. 取りに行くのは、前回から一定時間が経ったときだけ(REFRESH_SEC /
       失敗の後は RETRY_SEC)。1回の要求の中では多くても1度です。
    3. 待つのは TIMEOUT 秒まで。遅い台帳APIに全ページを巻き込ませません。
    4. 繋がって、正しい中身が返ってきたときだけ控えを更新します。
    5. 失敗は「届かない / 壊れている / 項目が無い」に分けて error_log に残します。
    6. 控えが一度も無いまま読めないときは null を返します。呼ぶ側は、それを必要とする
       機能だけを止めてください(プロダクト全体を止めないこと)。

  【使い方】素の相対パスで存在を確かめて読み込み、すぐ初期化します。このファイルが無い
  環境では、台帳を使う機能だけが止まります(呼ぶ側は class_exists で確かめてから呼びます)。

      $directry = "../../pusyuusystem/scripts/php_scripts/pusyuu_registry_client.php";
      if (file_exists($directry)) {
        require_once($directry);
        PusyuuRegistryClient::init(<入口が確かめた共有の非公開フォルダ>);
      }

  【このファイルはほかのファイルを読みに行きません】要るもの(控えの置き場所)は初期化で
  受け取ります。

  【自分へのリンクには使わないこと】自分のページへのリンク・フォームの送信先・
  リダイレクトは相対URLで書きます。台帳が止まっても、プロダクト自身の基本機能が
  動き続けるようにするためです。

  【プロダクト固有の事情を入れないこと】全プロダクトで同じファイルを使います。
  ここに特定のプロダクトの都合を1つでも入れると、他のプロダクトがそれを引きずります。

  仕様は pusyuu-service/pusyuu_registry/REGISTRY_SPEC.md を参照してください。
******************************************/

// ---------------------------------------------------------------------
// 【控えの置き場所は、入口が確かめて初期化で渡した共有の非公開フォルダ(CODING_STYLE 5-3)の
// 中だけです】渡されていなければ、台帳APIへも取りに行かず null を返し、台帳を使う機能
// (プロダクト一覧・他プロダクトへのリンク・繋ぐ先)を止めます。
//
// 【一時ディレクトリや、別の置き場所の指定を足さないこと】このファイルが受け取る
// 置き場所は「利用者が指定した非公開フォルダ」の1つだけ、と決めています。置き場所の候補が
// 増えるほど、どこに何があるかを利用者が把握できなくなるからです。控えの中身は公開の窓口
// (?api=registry)の写しだけで機密ではありませんが、それは逃げ道を作ってよい理由にはしません。
// 非公開フォルダを持たないページで台帳の機能が止まって困るときは、ここに逃げ道を作るのではなく、
// そのページの入口に非公開フォルダの指定と確かめ役を足してください。
//
// 【控えに書くのは公開の窓口の応答だけ】認証つきの呼び出し(?api=secrets など)の結果は、
// 控えにも、ほかのどのファイルにも書かないこと。機密は毎回窓口から受け取ります。
//
// 【読み込む前の define で設定を変える仕組みはやめました】住所・待ち時間・間隔は下の
// クラス定数です。どこで何が変えられているのかを追えなくする暗黙の設定だったためです。
// ---------------------------------------------------------------------
final class PusyuuRegistryClient {
  // 【ここだけは住所を固定します】台帳APIは「唯一の絶対の住所」と決めた場所です。
  // サーバを移しても変えない約束なので、推測や HTTP_HOST から組み立てないでください。
  public const URL = 'https://21emon.wjg.jp/pusyuu_registry/';
  // 台帳APIを待つ秒数。ページを開くたびにこの時間まで待たされうるので、長くしないこと。
  private const TIMEOUT = 1;
  // 控えが正しく取れた後、次に取りに行くまでの秒数。
  private const REFRESH_SEC = 300;
  // 取りに行って失敗した後、次に試すまでの秒数。台帳APIが落ちている間、
  // 全ての要求がタイムアウトまで待たされるのを防ぎます。
  private const RETRY_SEC = 60;

  /** 初期化で渡された共有の非公開フォルダ(控えの置き場)。 */
  private static ?string $commonDir = null;

  /**
   * 初期化。入口で、このファイルを読み込んだ直後に1回だけ呼びます。
   * $commonDir は入口が PusyuuHiddenDir::verify() で確かめた共有の非公開フォルダ
   * (確かめられなければ null。そのときは台帳を使う機能が止まります)。
   */
  public static function init(?string $commonDir): void {
    self::$commonDir = ($commonDir === '') ? null : $commonDir;
  }
  /** 失敗の種類(ログの見出し)。 */
  public const PROBLEM_UNREACHABLE = '届かない';
  public const PROBLEM_BROKEN      = '壊れている';
  public const PROBLEM_MISSING     = '項目が無い';

  // ----------------------------------------------------------------
  // 台帳そのもの
  // ----------------------------------------------------------------

  /**
   * 台帳({products, terms, updated_at})。控えも無く読めもしないときは null。
   * 1回の要求の中では、最初の呼び出しで決まった答えを使い回します。
   */
  public static function data(): ?array {
    static $resolved = false;
    static $data = null;

    // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
    if ($resolved === false) {
      $resolved = true;
      $file = self::cacheFile();

      if ($file === null) {
        // 控えを置けないまま取りに行くと、要求のたびに台帳APIを待たせることになるので、
        // 取りに行かずに止めます(上の設定欄を参照)。
        error_log('[PusyuuRegistryClient] 共有の非公開フォルダが初期化(PusyuuRegistryClient::init)で渡されていないため、台帳を使う機能を止めます。');
      } else {
        $cache = self::readCache($file);
        // 控えが無い・前回失敗した、のどちらかなら短い間隔(RETRY)で試し直します。
        // 失敗は checked_at だけを進めるので、控えがあっても「前回失敗」は区別して数えます。
        if ($cache['data'] === null || $cache['failed']) {
          $wait = self::RETRY_SEC;
        } else {
          $wait = self::REFRESH_SEC;
        }

        if ($cache['checked_at'] + $wait <= time()) {
          $cache = self::refresh($cache);
          self::writeCache($file, $cache);
        }

        $data = $cache['data'];
      }
    }

    return $data;
  }

  /** 台帳の1件。台帳が読めない・その id が無いときは null(項目が無い、として記録します)。 */
  public static function product(string $id): ?array {
    $data = self::data();
    $found = null;

    if ($data !== null) {
      foreach ((array)($data['products'] ?? []) as $product) {
        if (($product['id'] ?? '') === $id) {
          $found = $product;
          break;
        }
      }
      if ($found === null) {
        self::note(self::PROBLEM_MISSING, "台帳にプロダクト「{$id}」がありません。");
      }
    }

    return $found;
  }

  // ----------------------------------------------------------------
  // ブラウザへ渡す住所
  // ----------------------------------------------------------------

  /**
   * 今の要求が、どの入口から来たか。台帳の全ての顔と照らし合わせます。
   * 戻り値: ['face' => 顔の名前(合わなければ 'public'), 'product' => その顔を持つプロダクトのID(合わなければ null),
   *          'scheme' => 今の要求のスキーム]
   *
   * 【HTTP_HOST を使ってよい理由】ここで決めた顔は、この要求を送ってきた本人へ返す
   * リンクと、本人のセッションの部屋分けにだけ使います。Host を偽った人は自分をだますことしか
   * できません(部屋を偽っても、入れるのは自分のセッションの別の部屋だけです)。
   * 【保存するもの・他人に届くものには使わないこと】RSS・通知・メールの本文などは、
   * 要求と無関係に誰かへ届くので、publicUrl() を使ってください。
   */
  private static function resolveEntrance(): array {
    static $resolved = null;

    if ($resolved === null) {
      // 【スキームまで合わせること】同じホストに https の顔と http の顔(古い端末向け)が
      // 並ぶことがあります。ホストだけで選ぶと、http で来た古い端末へ https のリンクを
      // 返してしまい、その端末では開けません。X-Forwarded-Proto は偽れますが、ここで
      // 決めた顔は本人へ返すリンクにしか使わないので、偽っても自分が困るだけです。
      $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
      $requestScheme = $https ? 'https' : 'http';
      $host = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''), $requestScheme);
      $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
      $best = 'public';
      $bestProduct = null;
      $bestLength = -1;

      foreach ((array)(self::data()['products'] ?? []) as $product) {
        foreach ((array)($product['faces'] ?? []) as $name => $url) {
          $parts = parse_url((string)$url);
          $faceScheme = strtolower((string)($parts['scheme'] ?? 'https'));
          $faceHost = self::normalizeHost((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), $faceScheme);
          $facePath = rtrim((string)($parts['path'] ?? ''), '/');
          $pathMatches = ($facePath === '' || $path === $facePath || strncmp($path, $facePath . '/', strlen($facePath) + 1) === 0);

          // 同じホストに複数の顔があるとき(パスで分けた構成)は、一番長く合ったものを選びます。
          if ($host !== '' && $faceScheme === $requestScheme && $faceHost === $host && $pathMatches && strlen($facePath) > $bestLength) {
            $best = (string)$name;
            $bestProduct = (string)($product['id'] ?? '');
            $bestLength = strlen($facePath);
          }
        }
      }

      $resolved = ['face' => $best, 'product' => ($bestProduct === '') ? null : $bestProduct, 'scheme' => $requestScheme];
    }

    return $resolved;
  }

  /** 今の要求が来た入口の顔の名前。台帳のどの顔にも合わなければ 'public'。 */
  public static function entrance(): string {
    return self::resolveEntrance()['face'];
  }

  /**
   * 今の要求が、台帳のどのプロダクトの顔から来たか(そのID)。合わなければ null。
   * 同じプロダクトに公開ドメイン・LAN・古い端末向けの http など複数の入口があっても同じIDになり、
   * 1つのホストをパスで分けた構成でも別々のIDになります(セッションの部屋分けに使います)。
   */
  public static function currentProduct(): ?string {
    return self::resolveEntrance()['product'];
  }

  /** 今の要求のスキーム('https' か 'http')。 */
  public static function requestScheme(): string {
    return self::resolveEntrance()['scheme'];
  }

  /**
   * プロダクトの住所(ブラウザへ渡す物)。$path は '/' か '?' で始まる続き(空でもよい)。
   * 選び方は次の順です。
   *   1. 今の入口と同じ名前の顔
   *   2. 今の要求と同じスキームの顔(public が合えば public を先に)
   *   3. public
   * 【2番を外さないこと】http で来た古い端末が、別のプロダクトへのリンクで https へ
   * 送られると開けません。名前が揃っていない顔同士でも、スキームは揃えます。
   * 台帳が読めない・その id が無いときは null。
   */
  public static function url(string $id, string $path = ''): ?string {
    $product = self::product($id);
    $faces = ($product === null) ? [] : (array)($product['faces'] ?? []);
    $face = self::entrance();
    $scheme = self::requestScheme();
    $sameScheme = null;
    if (isset($faces['public']) && strtolower((string)parse_url((string)$faces['public'], PHP_URL_SCHEME)) === $scheme) {
      $sameScheme = $faces['public'];
    } else {
      foreach ($faces as $candidate) {
        if ($sameScheme === null && strtolower((string)parse_url((string)$candidate, PHP_URL_SCHEME)) === $scheme) {
          $sameScheme = $candidate;
        }
      }
    }

    if ($product === null) {
      $url = null;
    } else if (isset($faces[$face])) {
      $url = $faces[$face] . $path;
    } else if ($sameScheme !== null) {
      $url = $sameScheme . $path;
    } else if (isset($faces['public'])) {
      $url = $faces['public'] . $path;
    } else {
      $url = null;
    }

    return $url;
  }

  /**
   * プロダクトの設定値(台帳の settings)。HTTPの住所ではない公開の値(STUNサーバーなど)。
   * 台帳が読めない・その名前が無いときは null。
   */
  public static function setting(string $id, string $name): ?string {
    $product = self::product($id);
    $value = $product['settings'][$name] ?? null;
    return is_string($value) ? $value : null;
  }

  /** 公開の顔の住所。要求と無関係に誰かへ届くもの(通知・RSS・メール)にはこちらを使います。 */
  public static function publicUrl(string $id, string $path = ''): ?string {
    $product = self::product($id);
    $base = (string)($product['faces']['public'] ?? '');
    return ($base === '') ? null : $base . $path;
  }

  /**
   * プロダクトの住所の頭(末尾の / 無し)を、HTMLへそのまま埋め込める形で返します。
   * $public が true なら公開の顔(OGPのように、よそが取りに来るもの)、false なら今の入口に合う顔。
   * 台帳が読めない・その id が無いときは空文字です。後ろに '/pusyuusystem/...' のような
   * パスを続けて書く前提なので、空文字のときは「そのページのホストの / から」に倒れます。
   */
  public static function baseHtml(string $id, bool $public = false): string {
    $url = $public ? self::publicUrl($id) : self::url($id);
    return htmlspecialchars((string)$url, ENT_QUOTES, 'UTF-8');
  }

  /** プロダクトの画像の住所(url() と同じ顔の選び方)。画像が無ければ空文字、台帳が読めなければ null。 */
  public static function imageUrl(array $product): string {
    $image = (string)($product['image'] ?? '');

    if ($image !== '' && $image[0] === '/') {
      $url = (string)self::url((string)($product['id'] ?? ''), $image);
    } else {
      $url = $image;
    }

    return $url;
  }

  /**
   * 一覧に出すプロダクト(旧 futures.json の links の代わり)。$selfId の物は外します。
   * 各項目は {id, title, description, url, image}。url と image は今の入口に合わせてあります。
   * 台帳が読めないときは null(「一覧を読み込めませんでした」を出してください)。
   */
  public static function links(string $selfId = ''): ?array {
    $data = self::data();
    $links = [];

    if ($data !== null) {
      foreach ((array)($data['products'] ?? []) as $product) {
        $id = (string)($product['id'] ?? '');
        if ($id !== $selfId && ($product['listed'] ?? true) === true) {
          $links[] = [
            'id'          => $id,
            'title'       => (string)($product['title'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'url'         => (string)self::url($id),
            'image'       => self::imageUrl($product),
          ];
        }
      }
    }

    return ($data === null) ? null : $links;
  }

  /**
   * 台帳に載っている全ての顔のホスト(小文字、既定でない番号付き)。
   * SSO の戻り先の許可など「うちのサービスの住所か」を確かめるのに使います。
   * 台帳が読めないときは空の配列(=どこも許さない側に倒れます)。
   */
  public static function knownHosts(): array {
    $hosts = [];

    foreach ((array)(self::data()['products'] ?? []) as $product) {
      foreach ((array)($product['faces'] ?? []) as $url) {
        $parts = parse_url((string)$url);
        $host = self::normalizeHost((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), (string)($parts['scheme'] ?? 'https'));
        if ($host !== '' && !in_array($host, $hosts, true)) {
          $hosts[] = $host;
        }
      }
    }

    return $hosts;
  }

  /**
   * そのURLが、台帳に載っているどれかの顔(スキーム・ホスト・番号まで一致)を指しているか。
   * 戻り先の許可(オープンリダイレクト対策)に使います。
   * 台帳が読めないときは false(=どこへも戻さない側に倒れます)。
   *
   * 【ホスト名だけで比べないこと】http の顔が無いホストへ http で戻すと、
   * 途中で中身を読まれうる経路へ利用者を送ることになります。スキームまで合わせます。
   */
  public static function isKnownUrl(string $url): bool {
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $target = self::normalizeHost((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), $scheme);
    $known = false;

    if (is_array($parts) && ($scheme === 'https' || $scheme === 'http') && $target !== '') {
      foreach ((array)(self::data()['products'] ?? []) as $product) {
        foreach ((array)($product['faces'] ?? []) as $face) {
          $faceParts = parse_url((string)$face);
          $faceScheme = strtolower((string)($faceParts['scheme'] ?? ''));
          $faceHost = self::normalizeHost((string)($faceParts['host'] ?? '') . (isset($faceParts['port']) ? ':' . $faceParts['port'] : ''), $faceScheme);
          if ($faceScheme === $scheme && $faceHost === $target) {
            $known = true;
          }
        }
      }
    }

    return $known;
  }

  /**
   * そのURLが、セッションライブラリ(pusyuu_session)を使っているプロダクトの顔
   * (スキーム・ホスト・番号まで一致)か。台帳の settings の pusyuu_session が "on" の
   * プロダクトだけを数えます。
   * 戻り値: true / false / null(台帳が読めず分からない。呼ぶ側が別の手掛かりに倒すこと)
   *
   * 【true でも「通してよい」ではありません】これは引き換えコードを「付けてよいか」の目安で、
   * 着いた先では必ず合言葉を確かめます(pusyuu_session の関門)。
   */
  public static function isSessionUrl(string $url): ?bool {
    $data = self::data();
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $target = self::normalizeHost((string)($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''), $scheme);
    $known = false;

    if ($data !== null && is_array($parts) && $target !== '') {
      foreach ((array)($data['products'] ?? []) as $product) {
        if ((string)($product['settings']['pusyuu_session'] ?? '') !== 'on') {
          continue;
        }
        foreach ((array)($product['faces'] ?? []) as $face) {
          $faceParts = parse_url((string)$face);
          $faceScheme = strtolower((string)($faceParts['scheme'] ?? ''));
          $faceHost = self::normalizeHost((string)($faceParts['host'] ?? '') . (isset($faceParts['port']) ? ':' . $faceParts['port'] : ''), $faceScheme);
          if ($faceScheme === $scheme && $faceHost === $target) {
            $known = true;
          }
        }
      }
    }

    return ($data === null) ? null : $known;
  }

  // ----------------------------------------------------------------
  // サーバ間の呼び出し
  // ----------------------------------------------------------------

  /**
   * サーバ間でそのプロダクトへ繋ぐ経路。
   * 戻り値: ['url' => 実際に繋ぐURL, 'host' => 名乗るホスト名(Host・証明書の確認。
   *          internal を使わないときは null)] 。台帳が読めない・id が無いときは null。
   *
   * 台帳に internal(例: https://127.0.0.1)があればそこへ繋ぎ、ホスト名は public の顔の
   * ものを名乗ります。無ければ public の顔へそのまま繋ぎます。
   */
  public static function route(string $id, string $path = ''): ?array {
    $product = self::product($id);
    $public = (string)($product['faces']['public'] ?? '');
    $internal = (string)($product['internal'] ?? '');
    $publicParts = parse_url($public);

    if ($product === null || $public === '') {
      $route = null;
    } else if ($internal === '') {
      $route = ['url' => $public . $path, 'host' => null];
    } else {
      // public の顔がパスを持つ構成(パスで分けた構成)なら、そのパスも内側の経路に付けます。
      $publicPath = rtrim((string)($publicParts['path'] ?? ''), '/');
      $host = (string)($publicParts['host'] ?? '') . (isset($publicParts['port']) ? ':' . $publicParts['port'] : '');
      $route = ['url' => $internal . $publicPath . $path, 'host' => $host];
    }

    return $route;
  }

  /**
   * あるプロダクトの居場所を、ほかのライブラリの初期化へそのまま渡せる形で返します。
   * 戻り値: ['api' => サーバ間で繋ぐURL(route() の url に $path を付けた物),
   *          'host' => 名乗るホスト名(route() の host), 'site' => ブラウザへ渡す根(url())]
   * 台帳が読めない・その id が無いときは null。
   *
   * 例: メイキィのクライアントの初期化 pusyuuAccountInit(…, PusyuuRegistryClient::location('p-meikiee', '/index.php'), …)
   * 【ここに特定のプロダクトの都合を入れないこと】route() と url() を1つにまとめただけの
   * 汎用の取り出し口です。各プロダクトが同じ組み立てを書いて食い違わないために置いています。
   */
  public static function location(string $id, string $path = ''): ?array {
    $route = self::route($id, $path);
    $site = self::url($id);

    return ($route === null || $site === null) ? null : ['api' => $route['url'], 'host' => $route['host'], 'site' => $site];
  }

  /**
   * 今の入口の顔が台帳で分かったときだけ、そのスキーム('https' か 'http')。分からなければ null。
   * ほかのライブラリへ「今の要求のスキーム」を渡すときに使います(分からないなら渡さず、
   * 受け取った側の既定=https に任せます)。
   */
  public static function knownScheme(): ?string {
    return (self::currentProduct() === null) ? null : self::requestScheme();
  }

  /**
   * サーバ間でそのプロダクトへHTTPで問い合わせます(file_get_contents だけで行います)。
   * 戻り値: ['status' => int, 'body' => string|null, 'problem' => '' | 届かない | 項目が無い,
   *          'where' => ログ用の説明]
   * リダイレクトは追いません(POSTがGETに化けて本文が失われるのを防ぐため)。
   *
   * 【curl を使わないこと】apt で入れただけのPHPには curl がありません。
   */
  public static function call(string $id, string $method, string $path, array $headers = [], string $body = '', float $timeout = 5): array {
    $route = self::route($id, $path);

    if ($route === null) {
      $result = ['status' => 0, 'body' => null, 'problem' => self::PROBLEM_MISSING, 'where' => "プロダクト「{$id}」の経路が台帳から分かりません。"];
    } else {
      if ($route['host'] !== null) {
        $headers[] = 'Host: ' . $route['host'];
      }
      $peer = ($route['host'] === null) ? null : (string)strtok($route['host'], ':');
      $context = stream_context_create([
        'http' => [
          'method'          => $method,
          'header'          => implode("\r\n", $headers),
          'content'         => $body,
          'timeout'         => $timeout,
          'ignore_errors'   => true,
          'follow_location' => 0,
        ],
        // 内側の経路(127.0.0.1 など)で https を使うときも、証明書は名乗ったホスト名で確かめます。
        'ssl' => ($peer === null) ? [] : ['peer_name' => $peer, 'verify_peer' => true, 'verify_peer_name' => true],
      ]);
      $where = 'url=' . $route['url'] . (($route['host'] === null) ? '' : ' / Host: ' . $route['host']);
      $raw = @file_get_contents($route['url'], false, $context);
      // 【この行を別の関数へ切り出さないこと】PHP 8.4 より前は $http_response_header が
      // file_get_contents を呼んだ関数の中にしか現れません。8.5 では非推奨なので新しい方を先に使います。
      $status = self::statusOf(function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []));

      if ($raw === false || $status === 0) {
        $error = error_get_last();
        $result = ['status' => 0, 'body' => null, 'problem' => self::PROBLEM_UNREACHABLE, 'where' => $where . ' / ' . (string)($error['message'] ?? '')];
        self::note(self::PROBLEM_UNREACHABLE, "プロダクト「{$id}」へ繋がりません。" . $result['where']);
      } else {
        $result = ['status' => $status, 'body' => $raw, 'problem' => '', 'where' => $where . ' / HTTP ' . $status];
      }
    }

    return $result;
  }

  // ----------------------------------------------------------------
  // 控えと取得(ここから下はこのファイルの中だけで使います)
  // ----------------------------------------------------------------

  /** 台帳APIへ取りに行き、控えを新しくします。失敗しても控えの中身は捨てません。 */
  private static function refresh(array $cache): array {
    $headers = ['Accept: application/json'];
    if ($cache['data'] !== null && $cache['etag'] !== '') {
      $headers[] = 'If-None-Match: ' . $cache['etag'];
    }
    $context = stream_context_create(['http' => [
      'method'        => 'GET',
      'header'        => implode("\r\n", $headers),
      'timeout'       => self::TIMEOUT,
      'ignore_errors' => true,
      'user_agent'    => 'pusyuu-registry-client',
    ]]);
    $raw = @file_get_contents(self::URL . '?api=registry', false, $context);
    // 【この行を別の関数へ切り出さないこと】理由は call() の同じ行を参照。
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = self::statusOf($responseHeaders);
    $decoded = ($raw === false) ? null : json_decode($raw, true);
    $etag = '';
    foreach ($responseHeaders as $line) {
      if (stripos($line, 'ETag:') === 0) {
        $etag = trim(substr($line, 5));
      }
    }

    $cache['checked_at'] = time();

    if ($raw === false || $status === 0) {
      $cache['failed'] = true;
      self::note(self::PROBLEM_UNREACHABLE, '台帳APIに繋がりません(' . self::URL . ')。控えで動きます。');
    } else if ($status === 304 && $cache['data'] !== null) {
      $cache['failed'] = false;
    } else if ($status !== 200 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true || !is_array($decoded['products'] ?? null)) {
      $cache['failed'] = true;
      self::note(self::PROBLEM_BROKEN, '台帳APIの応答を読めません(HTTP ' . $status . ')。控えで動きます。');
    } else {
      $cache['failed'] = false;
      $cache['etag'] = $etag;
      $cache['fetched_at'] = time();
      $cache['data'] = [
        'updated_at' => (string)($decoded['updated_at'] ?? ''),
        'products'   => array_values($decoded['products']),
        'terms'      => is_array($decoded['terms'] ?? null) ? array_values($decoded['terms']) : [],
      ];
    }

    return $cache;
  }

  private static function readCache(string $file): array {
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $decoded = ($raw === false) ? null : json_decode($raw, true);

    return [
      'etag'       => (string)($decoded['etag'] ?? ''),
      'fetched_at' => (int)($decoded['fetched_at'] ?? 0),
      'checked_at' => (int)($decoded['checked_at'] ?? 0),
      'failed'     => (bool)($decoded['failed'] ?? false),
      'data'       => is_array($decoded['data'] ?? null) ? $decoded['data'] : null,
    ];
  }

  /** 別名で書いてから rename します。途中で落ちても、半分だけ書かれた控えを読む人がいません。 */
  private static function writeCache(string $file, array $cache): void {
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

    if ($json === false) {
      error_log('[PusyuuRegistryClient] 控えを書けません(JSONにできない)。');
    } else if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
      @unlink($tmp);
      error_log('[PusyuuRegistryClient] 控えを書けません: ' . $file);
    }
  }

  /** 控えのファイル。共有の非公開フォルダが初期化で渡されていなければ null(置き場所はそこだけです)。 */
  private static function cacheFile(): ?string {
    return (self::$commonDir === null) ? null : self::$commonDir . '/pusyuu_registry_cache.json';
  }

  /** ホスト名を比べられる形に揃えます(小文字・既定の番号は省く)。 */
  private static function normalizeHost(string $host, string $scheme): string {
    $host = strtolower(trim($host));
    $default = (strtolower($scheme) === 'https') ? ':443' : ':80';
    if (substr($host, -strlen($default)) === $default) {
      $host = substr($host, 0, -strlen($default));
    }
    return $host;
  }

  private static function statusOf(array $headers): int {
    return (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($headers[0] ?? ''), $m) === 1) ? (int)$m[1] : 0;
  }

  /** 失敗を1行だけ残します。同じ要求の中で同じ文を何度も書きません。 */
  private static function note(string $problem, string $message): void {
    static $seen = [];
    $line = '[PusyuuRegistryClient] ' . $problem . ': ' . $message;

    if (!isset($seen[$line])) {
      $seen[$line] = true;
      error_log($line);
    }
  }
}
