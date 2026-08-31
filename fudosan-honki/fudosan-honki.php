<?php
/**
 * Plugin Name: 不動産 訪問査定申込（本気査定）
 * Description: 売却を本気で検討している方向けの査定申込フォーム。お名前・電話番号まで受け取り、受付完了メールを自動返信＋担当者に通知します。査定額の自動表示は行わず、担当者が個別に査定してご連絡する形です。入力項目は1つずつ「必須／任意／非表示」を選べます。ショートコード [fudosan_honki] をページに貼るだけ。
 * Version: 1.12.1
 * Author: (運営者)
 * License: GPLv2 or later
 * Text Domain: fudosan-honki
 *
 * ★法的注意:
 *   - 本プラグインは価格を自動表示しない（受付のみ）。価格を提示しないので、
 *     価格に関する断り書きはフォーム・完了画面・メールのいずれにも置かない。
 *     価格をどう受け取るべきかは、担当者が査定結果を伝える場面で説明すること。
 *   - 氏名・電話番号という個人情報を取得するため、利用目的の明示（個情法21条）と、
 *     提携先へ渡す場合は第三者提供の同意（個情法27条）が必須。設定でON/OFFできる。
 *   公開前に弁護士等の確認を推奨。
 */

if (!defined('ABSPATH')) exit; // 直接アクセス禁止

define('FHS_VER', '1.12.1');
define('FHS_OPT', 'fudosan_honki_options');

/**
 * 自動更新の置き場（update.json の URL）。
 * 新バージョンを置くと WP管理画面に「更新可能」バッジが出てワンクリック更新できる。
 * ※ 空なら自動更新は無効（手動アップロードでの運用は可能）。
 */
define('FHS_UPDATE_URL', 'https://raw.githubusercontent.com/yoshimucom-gif/fudosan-honki-plugin/main/update.json');

/* 更新チェッカー（URL未設定なら無効）
   ★is_admin() で囲まないこと。WordPressの自動更新は WP-Cron（管理画面外）で走るため、
     管理画面限定にすると「自動更新を有効化」をONにしても更新が入らない。 */
if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
    require_once __DIR__ . '/includes/plugin-updater.php';
    new FHS_Honki_Updater(__FILE__, FHS_UPDATE_URL);
}

/* =========================================================================
 * 1. 入力項目のスキーマ（このプラグインの心臓部）
 *
 *    すべての入力項目は「必須(req) / 任意(opt) / 非表示(off)」を設定画面から
 *    1つずつ切り替えられる。'def' はその初期値。
 *    'col' … 保存先のDBカラム名（null なら「詳細」テキストにまとめて保存）
 * ======================================================================= */

/** お客様のご連絡先 */
function fhs_customer_fields() {
    return array(
        array('key'=>'name',         'label'=>'お名前',                   'type'=>'text',   'col'=>'name',          'len'=>100, 'def'=>'req', 'ph'=>'例：山田 太郎'),
        array('key'=>'kana',         'label'=>'フリガナ',                 'type'=>'text',   'col'=>'kana',          'len'=>100, 'def'=>'opt', 'ph'=>'例：ヤマダ タロウ'),
        array('key'=>'tel',          'label'=>'電話番号',                 'type'=>'tel',    'col'=>'tel',           'len'=>50,  'def'=>'req', 'ph'=>'例：090-1234-5678'),
        array('key'=>'contact_time', 'label'=>'ご連絡しやすい時間帯',      'type'=>'select', 'col'=>'contact_time',  'len'=>50,  'def'=>'opt', 'opts'=>'contact_time'),
        array('key'=>'owner_address','label'=>'ご住所（物件と異なる場合）', 'type'=>'text',   'col'=>'owner_address', 'len'=>255, 'def'=>'off', 'ph'=>'例：東京都新宿区〇〇1-2-3'),
    );
}

/** 売却のご状況（リードの質を測る＝担当者が優先順位を付けるための情報） */
function fhs_situation_fields() {
    return array(
        array('key'=>'survey',      'label'=>'ご希望の査定方法',     'type'=>'select', 'col'=>'survey',  'len'=>50, 'def'=>'req', 'opts'=>'survey'),
        array('key'=>'purpose',     'label'=>'ご事情・売却の理由',   'type'=>'select', 'col'=>'purpose', 'len'=>50, 'def'=>'opt', 'opts'=>'purpose'),
        array('key'=>'timing',      'label'=>'売却をご希望の時期',   'type'=>'select', 'col'=>'timing',  'len'=>50, 'def'=>'opt', 'opts'=>'timing'),
        array('key'=>'relation',    'label'=>'物件との関係',         'type'=>'select', 'col'=>null,      'len'=>50, 'def'=>'off', 'opts'=>'relation'),
        array('key'=>'loan',        'label'=>'住宅ローンの残債',     'type'=>'select', 'col'=>null,      'len'=>50, 'def'=>'off', 'opts'=>'loan'),
        array('key'=>'other_agent', 'label'=>'他社へのご相談状況',   'type'=>'select', 'col'=>null,      'len'=>50, 'def'=>'off', 'opts'=>'other_agent'),
    );
}

/** 物件種別ごとの入力項目（マンション/戸建て/土地で異なる） */
function fhs_property_fields() {
    return array(
        'mansion' => array(
            array('key'=>'mansion_name',  'label'=>'マンション名',      'type'=>'text',    'def'=>'req', 'ph'=>'例：〇〇マンション'),
            array('key'=>'floor',         'label'=>'階数',              'type'=>'number',  'def'=>'opt', 'ph'=>'例：5'),
            array('key'=>'room_no',       'label'=>'部屋番号',          'type'=>'text',    'def'=>'opt', 'ph'=>'例：503'),
            array('key'=>'exclusive_area','label'=>'専有面積（㎡）',     'type'=>'number',  'def'=>'req', 'ph'=>'例：70'),
            array('key'=>'direction',     'label'=>'方角',              'type'=>'select',  'def'=>'opt', 'opts'=>'direction'),
            array('key'=>'corner',        'label'=>'角部屋',            'type'=>'check',   'def'=>'opt', 'chk'=>'角部屋である'),
            array('key'=>'build_year',    'label'=>'築年（西暦）',       'type'=>'number',  'def'=>'req', 'ph'=>'例：2015'),
            array('key'=>'layout',        'label'=>'間取り',            'type'=>'select',  'def'=>'opt', 'opts'=>'layout'),
            array('key'=>'reform',        'label'=>'リフォーム履歴',     'type'=>'textarea','def'=>'opt', 'ph'=>'例：2020年に水回りをリフォーム'),
        ),
        'house' => array(
            array('key'=>'floors',        'label'=>'階建',              'type'=>'number',  'def'=>'opt', 'ph'=>'例：2'),
            array('key'=>'build_year',    'label'=>'築年（西暦）',       'type'=>'number',  'def'=>'req', 'ph'=>'例：2010'),
            array('key'=>'land_right',    'label'=>'土地権利',          'type'=>'select',  'def'=>'req', 'opts'=>'land_right'),
            array('key'=>'land_area',     'label'=>'土地面積（㎡）',     'type'=>'number',  'def'=>'req', 'ph'=>'例：120'),
            array('key'=>'building_area', 'label'=>'建物面積（㎡）',     'type'=>'number',  'def'=>'req', 'ph'=>'例：95'),
            array('key'=>'structure',     'label'=>'建築構造',          'type'=>'select',  'def'=>'opt', 'opts'=>'structure'),
            array('key'=>'road_contact',  'label'=>'接道状況',          'type'=>'select',  'def'=>'opt', 'opts'=>'road_contact'),
            array('key'=>'road_width',    'label'=>'前面道路幅員（m）',  'type'=>'number',  'def'=>'opt', 'ph'=>'例：4'),
            array('key'=>'road_direction','label'=>'接道方向',          'type'=>'select',  'def'=>'opt', 'opts'=>'direction'),
            array('key'=>'road_type',     'label'=>'道路種別',          'type'=>'select',  'def'=>'opt', 'opts'=>'road_type'),
            array('key'=>'road_frontage', 'label'=>'接道間口（m）',      'type'=>'number',  'def'=>'opt', 'ph'=>'例：5'),
            array('key'=>'layout',        'label'=>'間取り',            'type'=>'select',  'def'=>'opt', 'opts'=>'layout'),
            array('key'=>'reform',        'label'=>'リフォーム履歴',     'type'=>'textarea','def'=>'opt', 'ph'=>'例：2020年に外壁塗装'),
        ),
        'land' => array(
            array('key'=>'land_area',     'label'=>'土地面積（㎡）',     'type'=>'number',  'def'=>'req', 'ph'=>'例：150'),
            array('key'=>'land_right',    'label'=>'土地権利',          'type'=>'select',  'def'=>'req', 'opts'=>'land_right'),
            array('key'=>'road_contact',  'label'=>'接道状況',          'type'=>'select',  'def'=>'opt', 'opts'=>'road_contact'),
            array('key'=>'road_width',    'label'=>'前面道路幅員（m）',  'type'=>'number',  'def'=>'opt', 'ph'=>'例：4'),
            array('key'=>'road_direction','label'=>'接道方向',          'type'=>'select',  'def'=>'opt', 'opts'=>'direction'),
            array('key'=>'road_type',     'label'=>'道路種別',          'type'=>'select',  'def'=>'opt', 'opts'=>'road_type'),
            array('key'=>'road_frontage', 'label'=>'接道間口（m）',      'type'=>'number',  'def'=>'opt', 'ph'=>'例：8'),
            array('key'=>'current_use',   'label'=>'現況',              'type'=>'select',  'def'=>'opt', 'opts'=>'current_use'),
        ),
    );
}

/**
 * ティザー（記事内などに置く短い入口フォーム）に出せる項目。
 *
 * ★お名前・フリガナ・電話番号・メールは意図的に含めない。
 *   ティザーは同意チェックの前段であり、個人情報を受け取る画面ではないため。
 *   （個人情報を受け取るのは、利用目的の明示と同意チェックがある本フォームだけにする）
 */
function fhs_teaser_fields() {
    return array(
        'ptype'   => array('label' => '物件種別',          'type' => 'ptype'),
        'address' => array('label' => '物件の住所',        'type' => 'text',   'ph' => ''),   // 入力例は fhs_address_placeholder()
        'survey'  => array('label' => 'ご希望の査定方法',   'type' => 'select', 'opts' => 'survey'),
        'purpose' => array('label' => 'ご事情・売却の理由', 'type' => 'select', 'opts' => 'purpose'),
        'timing'  => array('label' => '売却をご希望の時期', 'type' => 'select', 'opts' => 'timing'),
    );
}

/**
 * 住所欄の入力例（プレースホルダ）。
 *
 * ★都道府県は入れない。市区町村単位で扱うサービスなので、県から書かせる必要がない。
 * ※会社の所在地から自動で作ることも試したが、扱うエリアと所在地は必ずしも一致しないため、
 *   汎用の例を既定にして、変えたい場合だけ設定で上書きしてもらう形にした。
 */
function fhs_address_placeholder() {
    $custom = trim((string) fhs_opt('address_example', ''));
    return $custom !== '' ? $custom : '例：〇〇市△△町1-2-3';
}

/** 「無料, 地場優良企業対応, 1社査定」のような文字列をタグの配列に。
 *  半角/全角のカンマ、読点、縦棒のどれでも区切れるようにする（書き方で迷わせない）。 */
function fhs_split_tags($raw) {
    $parts = preg_split('/[,，、|｜]+/u', (string)$raw);   // 半角/全角カンマ・読点・縦棒
    if (!is_array($parts)) return array();
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/** fields="ptype,address" を検証済みの順序付きリストに（既定は物件種別＋住所） */
function fhs_parse_teaser_fields($raw) {
    $known = fhs_teaser_fields();
    $out = array();
    foreach (explode(',', (string)$raw) as $k) {
        $k = trim($k);
        if ($k !== '' && isset($known[$k]) && !in_array($k, $out, true)) $out[] = $k;
    }
    return $out ? $out : array('ptype', 'address');
}

/**
 * 属性の間の半角スペースが抜けていても読み取れるようにする。
 *
 * [fudosan_honki design="teaser" url="/○○○/satei/"width="640"]
 *                                          ↑ ここにスペースが無い
 * WordPressは属性を空白区切りで読むため、この塊を属性として認識できず、
 * 「値のない項目」として番号付きで渡してくる。結果 url が空になり、
 * 「urlを指定してください」と出るのに書いてある、という分かりにくい状態になる。
 * よくある書き間違いなので、ここで分解して拾い、管理者にだけ直し方を知らせる。
 */
function fhs_unglue_atts($atts) {
    if (!is_array($atts)) return $atts;
    $out = array(); $fixed = false;
    foreach ($atts as $k => $v) {
        if (is_int($k) && is_string($v)
            && preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"/', $v, $m, PREG_SET_ORDER)
            && count($m) >= 2) {
            foreach ($m as $one) $out[strtolower($one[1])] = $one[2];
            $fixed = true;
            continue;
        }
        $out[$k] = $v;
    }
    if ($fixed) $out['fhs_glued'] = '1';
    return $out;
}

/** ティザーの項目キー → 本フォームでの入力欄名（引き継ぎで同じ欄に流し込むため） */
function fhs_teaser_form_name($key) {
    return in_array($key, array('ptype', 'address'), true) ? $key : 'situation_' . $key;
}

/** 設定・検証で使うグループ一覧（キーはモード保存とPOSTキーの接頭辞） */
function fhs_all_groups() {
    $g = array(
        'customer'  => fhs_customer_fields(),
        'situation' => fhs_situation_fields(),
    );
    foreach (fhs_property_fields() as $pt => $flds) $g['prop_' . $pt] = $flds;
    return $g;
}

/** セレクトの選択肢 */
function fhs_opt_list($key) {
    switch ($key) {
        case 'direction':    return array('北','北東','東','南東','南','南西','西','北西');
        case 'land_right':   return array('所有権','借地権');
        case 'structure':    return array('木造','軽量鉄骨造','重量鉄骨造','鉄筋コンクリート造(RC)','鉄骨鉄筋コンクリート造(SRC)','その他');
        case 'road_contact': return array('一方','角地','二方','三方','四方');
        case 'road_type':    return array('公道','私道');
        case 'layout':       return array('1R','1K','1DK','1LDK','2K','2DK','2LDK','3K','3DK','3LDK','4LDK以上');
        case 'current_use':  return array('更地','古家あり','駐車場','農地','その他');
        case 'contact_time': return array('指定なし','午前（9〜12時）','午後（12〜17時）','夕方以降（17〜20時）','平日のみ希望','土日祝のみ希望','メールで連絡してほしい');
        case 'survey':       return array('訪問査定（実際に見てもらいたい）','机上査定（訪問なし・データのみ）','どちらでもよい');
        case 'purpose':      return array('売却を検討している','相続した・相続する予定','離婚による財産分与','住み替えを検討している','転勤・転居のため','資産価値を把握したい','その他');
        case 'timing':       return array('すぐにでも（3ヶ月以内）','半年以内','1年以内','1年より先','時期は未定');
        case 'relation':     return array('自分（名義人本人）','家族が名義人','共有名義','相続予定・相続中','その他');
        case 'loan':         return array('残債あり','残債なし（完済済み）','わからない');
        case 'other_agent':  return array('まだ相談していない','他社にも相談中','他社に依頼済み');
    }
    return array();
}

/* =========================================================================
 * 2. 有効化: リード保存テーブル作成
 * ======================================================================= */
register_activation_hook(__FILE__, 'fhs_activate');
function fhs_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_honki_leads';
    $charset = $wpdb->get_charset_collate();
    // dbDeltaは「1カラム1行」でないと既存テーブルへのカラム追加を取りこぼす
    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        name VARCHAR(100) NULL,
        kana VARCHAR(100) NULL,
        tel VARCHAR(50) NULL,
        email VARCHAR(191) NOT NULL,
        contact_time VARCHAR(50) NULL,
        owner_address VARCHAR(255) NULL,
        ptype VARCHAR(20) NULL,
        address VARCHAR(255) NULL,
        details LONGTEXT NULL,
        survey VARCHAR(50) NULL,
        purpose VARCHAR(50) NULL,
        timing VARCHAR(50) NULL,
        marketing_opt_in TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    fhs_ensure_columns();
    fhs_ensure_satei_page();
}

/** 保存先カラムの一覧（スキーマから自動生成）: col => 最大長 */
function fhs_lead_columns() {
    $cols = array();
    foreach (array(fhs_customer_fields(), fhs_situation_fields()) as $flds) {
        foreach ($flds as $fd) {
            if (!empty($fd['col'])) $cols[$fd['col']] = isset($fd['len']) ? (int)$fd['len'] : 191;
        }
    }
    return $cols;
}

/* dbDeltaの取りこぼし対策: 不足カラムを明示的にALTERで追加（確実）。
   ここを怠ると insert がそのキーで丸ごと失敗し、リードが1件も溜まらない事故になる。 */
function fhs_ensure_columns() {
    global $wpdb;
    $t = $wpdb->prefix . 'fudosan_honki_leads';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t) return;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `$t`", 0);
    if (!is_array($cols)) return;
    $need = array(
        'address' => 'VARCHAR(255) NULL',
        'details' => 'LONGTEXT NULL',
        'ptype'   => 'VARCHAR(20) NULL',
    );
    foreach (fhs_lead_columns() as $c => $len) {
        $need[$c] = 'VARCHAR(' . $len . ') NULL';
    }
    foreach ($need as $c => $def) {
        if (!in_array($c, $cols, true)) {
            $wpdb->query("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
    }
}

/**
 * 査定ページ（ティザーの遷移先）を用意する。
 *
 * ・スラッグ satei の固定ページを「下書き」で作る。いきなり公開すると、
 *   運営者情報も入れないうちにページが世に出てしまうため。
 * ・すでに同じスラッグのページがあれば、それを査定ページとして使う（勝手に増やさない）。
 * ・★一度作ったら二度と自動作成しない。利用者が意図的に消したのに、
 *   更新のたびに復活すると迷惑なため。
 */
function fhs_ensure_satei_page() {
    if (!function_exists('wp_insert_post')) return;

    // すでに設定済みで、そのページが生きているなら何もしない
    $id = (int) fhs_opt('satei_page_id', 0);
    if ($id > 0 && get_post_status($id) !== false) return;

    if (get_option('fhs_page_created')) return;   // 作成済み（消された場合も再作成しない）

    $o = get_option(FHS_OPT, array());
    if (!is_array($o)) $o = array();

    // 同じスラッグのページが既にあるならそれを採用
    $existing = get_page_by_path('satei', OBJECT, 'page');
    if ($existing) {
        $o['satei_page_id'] = (int) $existing->ID;
        update_option(FHS_OPT, $o);
        update_option('fhs_page_created', '1');
        return;
    }

    $new_id = wp_insert_post(array(
        'post_title'   => '無料査定のお申し込み',
        'post_name'    => 'satei',
        'post_status'  => 'draft',
        'post_type'    => 'page',
        // Gutenbergのショートコードブロックで入れる（クラシックの塊にしない）
        'post_content' => "<!-- wp:shortcode -->\n[fudosan_honki]\n<!-- /wp:shortcode -->",
    ));
    update_option('fhs_page_created', '1');
    if ($new_id && !is_wp_error($new_id)) {
        $o['satei_page_id'] = (int) $new_id;
        update_option(FHS_OPT, $o);
        update_option('fhs_page_notice', '1');   // 管理画面で1回だけ知らせる
    }
}

/** 査定ページのURL（未設定なら空） */
function fhs_satei_url() {
    $id = (int) fhs_opt('satei_page_id', 0);
    if ($id <= 0 || get_post_status($id) === false) return '';
    $url = get_permalink($id);
    return $url ? $url : '';
}

/* 自動更新でバージョンが上がったらテーブル定義を追従（新カラム追加等） */
add_action('plugins_loaded', 'fhs_maybe_upgrade');
function fhs_maybe_upgrade() {
    if (get_option('fhs_db_ver') !== FHS_VER) {
        fhs_activate();
        update_option('fhs_db_ver', FHS_VER);
    }
}

/* =========================================================================
 * 3. 設定
 * ======================================================================= */
function fhs_opt($key, $default = '') {
    $o = get_option(FHS_OPT, array());
    return isset($o[$key]) && $o[$key] !== '' ? $o[$key] : $default;
}

/** チェックボックス型の設定（空文字＝OFFを正しく区別する。fhs_opt では OFF にできない） */
function fhs_flag($key, $default = false) {
    $o = get_option(FHS_OPT, array());
    if (!is_array($o) || !array_key_exists($key, $o)) return $default;
    return $o[$key] === '1';
}

/** 項目のモード: 'req'（必須） / 'opt'（任意） / 'off'（非表示） */
function fhs_mode($group, $key, $def = 'opt') {
    $o = get_option(FHS_OPT, array());
    $k = 'mode_' . $group . '_' . $key;
    if (!is_array($o) || !array_key_exists($k, $o)) return $def;
    return in_array($o[$k], array('req', 'opt', 'off'), true) ? $o[$k] : $def;
}

/** そのグループの表示対象だけを返す（非表示を除外） */
function fhs_visible_fields($group, $flds, $req_only = false) {
    $out = array();
    foreach ($flds as $fd) {
        $m = fhs_mode($group, $fd['key'], $fd['def']);
        if ($m === 'off') continue;
        if ($req_only && $m !== 'req') continue;
        $fd['mode'] = $m;
        $out[] = $fd;
    }
    return $out;
}

/* 査定ページを自動作成したことを1回だけ知らせる（下書きなので公開操作が要る） */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (!get_option('fhs_page_notice')) return;
    $id = (int) fhs_opt('satei_page_id', 0);
    if ($id <= 0) { delete_option('fhs_page_notice'); return; }
    delete_option('fhs_page_notice');
    echo '<div class="notice notice-info is-dismissible"><p><strong>【訪問査定申込】査定ページを下書きで作成しました。</strong><br>'
       . '「無料査定のお申し込み」（スラッグ <code>satei</code>）という固定ページに <code>[fudosan_honki]</code> を入れてあります。'
       . '内容を確認して公開してください。<br>'
       . '<a class="button button-primary" href="' . esc_url(get_edit_post_link($id)) . '">ページを編集する</a> '
       . '<a class="button" href="' . esc_url(admin_url('admin.php?page=fudosan-honki')) . '">設定を開く</a></p></div>';
});

/**
 * サイトが https でなければ強く警告する。
 * このフォームは物件の住所・お名前・電話番号を送信する。http のままだと
 * その内容が暗号化されずに流れ、公衆Wi-Fi等では第三者に読み取られる。
 * ※管理画面だけ https という構成もあるので、判定は home_url()（お客様が見る側）で行う。
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (fhs_site_is_https()) return;
    echo '<div class="notice notice-error"><p><strong>【訪問査定申込】このサイトは https ではありません。</strong><br>'
       . 'お客様が入力した<strong>お名前・電話番号・物件の住所が、暗号化されずに送信されます</strong>。'
       . '公衆Wi-Fiなどでは第三者に読み取られます。'
       . 'サーバーでSSL証明書を有効にし、「設定 → 一般」のサイトアドレスを <code>https://</code> に変更してください。</p></div>';
});

function fhs_site_is_https() {
    return strpos(strtolower((string) home_url()), 'https://') === 0;
}

/* 公開前チェック。お客様に見える信頼性の材料が抜けたまま公開されるのを防ぐ */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    // 「査定担当会社」タブの項目。ここが空だと、フォームにも受付完了メールにも
    // その行が出ない＝お客様は連絡先の分からない相手に自宅と電話番号を渡すことになる
    $co = array();
    if (fhs_opt('operator_name', '')    === '') $co[] = '会社名';
    if (fhs_opt('operator_address', '') === '') $co[] = '所在地';
    if (fhs_opt('operator_contact', '') === '') $co[] = '電話番号';
    if (fhs_opt('operator_url', '')     === '') $co[] = '会社サイトURL';
    $miss = array();
    if (fhs_opt('privacy_url', '') === '') $miss[] = 'プライバシーポリシーURL';
    if (!$co && !$miss) return;
    $parts = array();
    if ($co)    $parts[] = '「査定担当会社」タブ：' . implode(' / ', $co);
    if ($miss)  $parts[] = '「基本設定」タブ：' . implode(' / ', $miss);
    echo '<div class="notice notice-warning"><p><strong>【訪問査定申込】公開前に未設定の項目があります</strong><br>'
       . esc_html(implode('　', $parts)) . '<br>'
       . 'このフォームは<strong>お名前・電話番号・物件の住所</strong>を受け取ります。'
       . 'お客様が「どこの会社に自宅と連絡先を渡すのか」を確かめられるよう、'
       . '<strong>会社名・所在地・電話番号・サイト</strong>は埋めてください'
       . '（空欄の項目は、フォームにも受付完了メールにも表示されません）。'
       . '<a href="' . esc_url(admin_url('admin.php?page=fudosan-honki')) . '">設定画面</a>から設定できます。</p></div>';
});

add_action('admin_menu', function () {
    add_menu_page('訪問査定申込', '訪問査定申込', 'manage_options', 'fudosan-honki', 'fhs_settings_page', 'dashicons-admin-home', 58);
    add_submenu_page('fudosan-honki', '設定', '設定', 'manage_options', 'fudosan-honki', 'fhs_settings_page');
    add_submenu_page('fudosan-honki', '申込一覧', '申込一覧', 'manage_options', 'fudosan-honki-leads', 'fhs_leads_page');
});

add_action('admin_init', function () {
    register_setting('fhs_group', FHS_OPT, 'fhs_sanitize_options');
});

/* 設定画面で「メディアから選ぶ」を使えるようにする（画像選択ダイアログ） */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'fudosan-honki') !== false) wp_enqueue_media();
});

/**
 * 複数のメールアドレスを受け取り、正しいものだけを「a@x, b@y」の形に整える。
 * 区切りはカンマ・全角カンマ・読点・改行・スペースのいずれでもよい
 * （コピー&ペーストでどれが混ざっても通るようにする）。
 * 重複は落とす。1件も残らなければ空文字を返す。
 */
function fhs_sanitize_email_list($v) {
    $parts = preg_split('/[,\x{FF0C}\x{3001}\s]+/u', (string) $v);
    if (!is_array($parts)) return '';
    $out = array();
    foreach ($parts as $p) {
        $p = sanitize_email(trim($p));
        if ($p !== '' && is_email($p) && !in_array($p, $out, true)) $out[] = $p;
    }
    return implode(', ', $out);
}

/** 保存済みの通知先を、wp_mail に渡せる配列にする */
function fhs_notify_recipients($raw) {
    $list = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');
    return array_values($list);
}

function fhs_sanitize_options($in) {
    if (!is_array($in)) $in = array();
    $out = array(
        'site_name'        => sanitize_text_field($in['site_name'] ?? '不動産査定'),
        'operator_name'    => sanitize_text_field($in['operator_name'] ?? ''),
        'operator_contact' => sanitize_text_field($in['operator_contact'] ?? ''),
        'operator_address' => sanitize_text_field($in['operator_address'] ?? ''),
        'operator_email'   => sanitize_email($in['operator_email'] ?? ''),
        'operator_url'     => esc_url_raw($in['operator_url'] ?? ''),
        'from_email'       => sanitize_email($in['from_email'] ?? ''),
        'notify_email'     => fhs_sanitize_email_list($in['notify_email'] ?? ''),
        'privacy_url'      => esc_url_raw($in['privacy_url'] ?? ''),
        'terms_url'        => esc_url_raw($in['terms_url'] ?? ''),
        // チェックボックス（未送信＝OFF。'' ではなく明示的に '0' を入れて区別する）
        'notify_on'        => !empty($in['notify_on'])      ? '1' : '0',
        // フォーム上に問い合わせ先（電話番号）を出すか。既定はOFF
        'show_contact'     => !empty($in['show_contact'])    ? '1' : '0',
        'show_marketing'   => !empty($in['show_marketing']) ? '1' : '0',
        'show_note'        => !empty($in['show_note'])      ? '1' : '0',
        'step_form'        => !empty($in['step_form'])      ? '1' : '0',
        // スパム対策
        'spam_block_link'  => !empty($in['spam_block_link'])  ? '1' : '0',
        'spam_require_ja'  => !empty($in['spam_require_ja'])  ? '1' : '0',
        'spam_words'       => sanitize_textarea_field($in['spam_words'] ?? ''),
        'third_party'      => !empty($in['third_party'])    ? '1' : '0',
        'third_party_name' => sanitize_text_field($in['third_party_name'] ?? ''),
        'third_party_url'  => esc_url_raw($in['third_party_url'] ?? ''),
        'logo_url'         => esc_url_raw($in['logo_url'] ?? ''),
        'company_image'    => esc_url_raw($in['company_image'] ?? ''),
        // ティザーの見出しまわり（空欄なら表示しない）
        'teaser_badge'     => sanitize_text_field($in['teaser_badge'] ?? ''),
        'teaser_tags'      => sanitize_text_field($in['teaser_tags'] ?? ''),
        'satei_page_id'    => (int) ($in['satei_page_id'] ?? 0),
        'address_example'  => sanitize_text_field($in['address_example'] ?? ''),
        // 自動返信メール
        'mail_subject'     => sanitize_text_field($in['mail_subject'] ?? ''),
        'mail_body'        => sanitize_textarea_field($in['mail_body'] ?? ''),
        // 見出し・ボタン
        'lead_text'        => sanitize_textarea_field($in['lead_text'] ?? ''),
        // 装飾（色）
        'color_brand'      => sanitize_hex_color($in['color_brand'] ?? '')    ?: '#1f6feb',
        // 空欄ならブランドカラーを使う（ボタンだけ目立つ色にしたい場合に指定）
        'color_btn_bg'     => sanitize_hex_color($in['color_btn_bg'] ?? '')   ?: '',
        'color_btn_text'   => sanitize_hex_color($in['color_btn_text'] ?? '') ?: '#ffffff',
        'color_title'      => sanitize_hex_color($in['color_title'] ?? '')    ?: '#1f6feb',
        'color_badge'      => sanitize_hex_color($in['color_badge'] ?? '')    ?: '#ff5a36',
    );
    // 各項目のモード（必須／任意／非表示）。スキーマを回して必ず明示値を保存する
    foreach (fhs_all_groups() as $g => $flds) {
        foreach ($flds as $fd) {
            $k = 'mode_' . $g . '_' . $fd['key'];
            $v = isset($in[$k]) ? $in[$k] : $fd['def'];
            $out[$k] = in_array($v, array('req', 'opt', 'off'), true) ? $v : $fd['def'];
        }
    }
    return $out;
}

/* =========================================================================
 * 4. 物件種別の対応表
 * ======================================================================= */
$GLOBALS['FHS_PTYPE_LABEL'] = array('mansion' => '中古マンション', 'house' => '中古一戸建て（土地＋建物）', 'land' => '土地');

/* =========================================================================
 * 5. 入力値の正規化・検証
 * ======================================================================= */

/** 全角数字・全角ハイフンを半角へ。type="number" だと全角入力が空になって送信できないため、
 *  数値項目は type="text" + inputmode で受け、ここで直す。 */
function fhs_to_hankaku($s) {
    $s = (string)$s;
    if ($s === '') return $s;
    if (function_exists('mb_convert_kana')) $s = mb_convert_kana($s, 'a', 'UTF-8');
    return strtr($s, array(
        '０'=>'0','１'=>'1','２'=>'2','３'=>'3','４'=>'4','５'=>'5','６'=>'6','７'=>'7','８'=>'8','９'=>'9',
        'ー'=>'-','－'=>'-','−'=>'-','．'=>'.','　'=>' ',
    ));
}

/** 数値項目の妥当性。空文字は「未入力」として呼び出し側で判定する */
function fhs_num_error($fd, $val) {
    $label = $fd['label'];
    if ($val === '') return '';
    if (!is_numeric($val)) return '「' . $label . '」は数字でご入力ください。';
    if ((float)$val < 0)   return '「' . $label . '」は0以上でご入力ください。';
    if ($fd['key'] === 'build_year') {
        $y = (int)$val; $now = (int)date('Y');
        if ($y < 1900 || $y > $now + 1) return '「' . $label . '」は西暦（例：2015）でご入力ください。';
    }
    return '';
}

/** 電話番号の形式。数字9〜11桁（ハイフン・括弧・空白は許容）＋国際表記の先頭+も許容 */
function fhs_tel_valid($tel) {
    $d = preg_replace('/[^0-9]/', '', fhs_to_hankaku($tel));
    return ($d !== null && strlen($d) >= 9 && strlen($d) <= 11);
}

/**
 * 文字数でDBカラム長に収める（超過するとinsertが失敗してリードが消える）。
 * ★mbstring が無いサーバーで substr を使うと、UTF-8の文字の途中で切れて壊れた
 *   バイト列になり、DBが受け付けず「保存したはずのお申し込みが消える」事故になる。
 *   さらに VARCHAR(n) の n は文字数なので、バイト数で切ると日本語は1/3しか入らない。
 *   よって mbstring が無い場合は preg の /u で文字単位に分解して切る。
 */
function fhs_trim_len($s, $max) {
    $s = (string)$s;
    if ($s === '') return $s;
    if (function_exists('mb_substr')) return mb_substr($s, 0, $max);
    if (preg_match_all('/./us', $s, $m) && isset($m[0])) {
        return implode('', array_slice($m[0], 0, $max));
    }
    return substr($s, 0, $max);
}

/* #rrggbb → "r,g,b"（ブランド色を rgba() で薄く使うため） */
function fhs_hex_to_rgb($hex) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '31,111,235';
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

/* =========================================================================
 * 6. 濫用対策
 *    公開フォームは誰でも任意アドレス宛にメールを送れる＝爆撃の踏み台にされ、
 *    送信ドメインのレピュテーションが死ぬ。
 *    ※ nonce は未ログインだと全訪問者で同一値・最大24時間有効のためボット対策にならない。
 * ======================================================================= */

/** 送信元IP。CDN配下で全員が同一IP扱いになるのを避けるため標準ヘッダを優先する。
 *  偽装可能だが、本命の防御はメールアドレス単位の制限（爆撃したい宛先は固定のため）。 */
function fhs_client_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR') as $h) {
        if (!empty($_SERVER[$h])) {
            $parts = explode(',', $_SERVER[$h]);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

function fhs_rate_ok($bucket, $id, $limit, $window) {
    if ($id === '') return true;
    $k = 'fhs_rl_' . $bucket . '_' . md5(strtolower($id));
    $n = (int) get_transient($k);
    if ($n >= $limit) return false;
    set_transient($k, $n + 1, $window);
    return true;
}

function fhs_rl_limits() {
    return array(
        'ip_max'       => (int) apply_filters('fhs_rl_ip_max', 5),                 // 同一IP: 1時間に5件
        'ip_window'    => (int) apply_filters('fhs_rl_ip_window', HOUR_IN_SECONDS),
        'email_max'    => (int) apply_filters('fhs_rl_email_max', 3),              // 同一メール: 24時間に3件
        'email_window' => (int) apply_filters('fhs_rl_email_window', DAY_IN_SECONDS),
    );
}

/** ハニーポットと経過時間でボットを弾く。JSでfetch送信するため fhs_elapsed は必ず入る。 */
function fhs_bot_errors() {
    if (!empty($_POST['fhs_website'])) return array('送信を受け付けられませんでした。');
    $elapsed = isset($_POST['fhs_elapsed']) ? intval($_POST['fhs_elapsed']) : 0;
    if ($elapsed < 3000) return array('入力が早すぎます。もう一度お試しください。');
    return array();
}

/**
 * スパム判定。日本語の不動産フォームには出てこない特徴を見る。
 *
 * ★引っかかった理由は返さない。どの条件で弾かれたかをボットに学習させないため、
 *   ハニーポットと同じ「送信を受け付けられませんでした。」だけを返す。
 * ★お客様を取りこぼす損失の方が大きいので、誤判定の起きにくいものだけを既定で有効にする。
 */
function fhs_spam_hit($values) {
    $block_link = fhs_flag('spam_block_link', true);
    $words = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) fhs_opt('spam_words', ''))), 'strlen'));

    foreach ($values as $v) {
        $v = (string) $v;
        if (trim($v) === '') continue;

        // 1) リンクの埋め込み。査定の申し込みでURLを書く理由がない
        if ($block_link && preg_match('#https?://|www\.[a-z0-9-]+\.|\[url|\[link|</?a\s#iu', $v)) return true;

        // 2) 日本の不動産査定の申し込みには出てこない文字種（キリル・アラビア・タイ）
        if (preg_match('/[\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}]/u', $v)) return true;

        // 3) 管理画面で登録したNGワード
        foreach ($words as $w) {
            $hit = function_exists('mb_stripos') ? mb_stripos($v, $w) : stripos($v, $w);
            if ($hit !== false) return true;
        }
    }
    return false;
}

/**
 * お名前に日本語が1文字も含まれないか。
 * 海外のボットは名前をローマ字で入れることが多いので効き目は大きいが、
 * ローマ字で書く方や外国籍の方まで弾いてしまうため、既定はオフ。
 */
function fhs_name_not_japanese($name) {
    if (!fhs_flag('spam_require_ja', false)) return false;
    $name = preg_replace('/\s+/u', '', (string) $name);
    if ($name === '') return false;
    return !preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{3005}-\x{3007}\x{FF66}-\x{FF9F}]/u', $name);
}

/**
 * CSVインジェクション対策。= + - @ 等で始まるセルは Excel が数式として実行してしまうため、
 * 先頭に ' を付けて無害な文字列にする（お客様の自由入力がそのままCSVに入るため必須）。
 * 数値（-5 等）はそのまま通す。
 */
function fhs_csv_safe($s) {
    $s = (string)$s;
    if ($s === '' || is_numeric($s)) return $s;
    return (strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
}

/* =========================================================================
 * 7. メール
 * ======================================================================= */

/* 受付完了メールの初期本文（お客様へ・差し込みタグ付き） */
function fhs_default_mail_body() {
    return "【{site_name}】査定のお申し込みを受け付けました\n\n"
        . "{customer_name}様\n\n"
        . "この度はお申し込みいただきありがとうございます。\n"
        . "以下の内容で査定のお申し込みを受け付けました。\n\n"
        . "{customer_details}\n\n"
        . "{property_details}\n\n"
        . "担当者が内容を確認のうえ、ご入力いただいたご連絡先へご連絡いたします。\n"
        . "いましばらくお待ちください。";
}

/**
 * メールの末尾に付ける「査定担当会社の連絡先」。
 *
 * ★ここは申し込みが済んだ後なので、断り書きではなく連絡先を出す。
 *   この段階では価格を一切提示していないため、価格に関する断り書きは対象が無い。
 *   価格をどう受け取るべきかは、担当者が査定結果を伝える場面で説明すること。
 *   フォーム上では電話番号を伏せている（電話で済ませてしまい申し込みが減るため）が、
 *   このメールには必ず載せる。
 * ★「当社は宅地建物取引業者ではない」とは書かない。査定を行うのは宅建業者であり、
 *   このメールの差出人もその会社名なので、事実と食い違って受け取った方が混乱する。
 */
function fhs_mail_footer() {
    $name  = fhs_opt('operator_name', '');
    $addr  = fhs_opt('operator_address', '');
    $tel   = fhs_opt('operator_contact', '');
    $mail  = fhs_opt('operator_email', '');
    $url   = fhs_opt('operator_url', '');

    $line = "───────────────────────────────\n";
    $out  = $line . "査定に関するお問い合わせは下記までお願いいたします。\n\n";
    if ($name !== '') $out .= $name . "\n";
    if ($addr !== '') $out .= "所在地 : " . $addr . "\n";
    if ($tel  !== '') $out .= "電話   : " . $tel . "\n";
    if ($mail !== '') $out .= "メール : " . $mail . "\n";
    if ($url  !== '') $out .= "サイト : " . $url . "\n";
    return $out . rtrim($line);
}

/**
 * 連絡先は必ず付ける。本文テンプレートは管理画面で自由に書き換えられるため、
 * テンプレートの中に置くと編集した瞬間に消えてしまう。よってテンプレートの外で連結する。
 */
function fhs_with_footer($body) {
    // mbstring が無いサーバーでも動くよう strpos を使う（UTF-8同士の検索は strpos で正しく判定できる）
    // 利用者が本文に自分で連絡先を書いている場合は二重にしない
    if (strpos($body, '査定に関するお問い合わせは下記まで') === false) {
        $body .= "\n\n" . fhs_mail_footer();
    }
    return $body . "\n";
}

function fhs_mail_body($ctx) {
    $tmpl = fhs_opt('mail_body', '');
    if (trim($tmpl) === '') $tmpl = fhs_default_mail_body();

    $repl = array(
        '{site_name}'         => fhs_opt('site_name', '不動産査定'),
        '{customer_name}'     => isset($ctx['name']) ? $ctx['name'] : '',
        '{customer_details}'  => isset($ctx['customer_details']) ? $ctx['customer_details'] : '',
        '{property_details}'  => isset($ctx['property_details']) ? $ctx['property_details'] : '',
        '{ptype}'             => isset($ctx['ptype_label']) ? $ctx['ptype_label'] : '',
        '{address}'           => isset($ctx['address']) ? $ctx['address'] : '',
        '{survey}'            => isset($ctx['survey']) ? $ctx['survey'] : '',
        '{email}'             => isset($ctx['email']) ? $ctx['email'] : '',
        '{tel}'               => isset($ctx['tel']) ? $ctx['tel'] : '',
        '{operator_name}'     => fhs_opt('operator_name', ''),
        '{operator_contact}'  => fhs_opt('operator_contact', ''),
    );
    // 未設定の項目で「お問い合わせ: 」「 様」のようにラベルだけが残らないよう、その行ごと落とす
    // ★会社の連絡先はメール末尾（fhs_mail_footer）に必ず入る。本文側にも署名を書くと
    //   会社名と電話が2回出るため、署名タグを含む行はここで落とす。
    //   以前の初期文面には署名2行が入っており、それを保存済みの環境が多いので、
    //   「空のときだけ消す」ではなく常に消す。
    $tmpl = preg_replace('/^.*\{operator_contact\}.*\R?/m', '', $tmpl);
    $tmpl = preg_replace('/^.*\{operator_name\}.*\R?/m', '', $tmpl);
    if (trim($repl['{customer_name}'])    === '') $tmpl = preg_replace('/^\h*\{customer_name\}\h*様\h*\R?/m', '', $tmpl);
    $body = strtr($tmpl, $repl);
    // 行ごと削除した箇所に空行が二重に残るため、3行以上の連続改行は2行に畳む
    $body = preg_replace("/(\R){3,}/", "\n\n", $body);
    return fhs_with_footer(rtrim($body));
}

/* 件名テンプレ */
function fhs_mail_subject() {
    $s = fhs_opt('mail_subject', '');
    if (trim($s) === '') $s = '【{site_name}】査定のお申し込みを受け付けました';
    return strtr($s, array('{site_name}' => fhs_opt('site_name', '不動産査定')));
}

/**
 * 管理者通知メールの本文（担当者へ）。
 * ★営業連絡の可否（オプトイン有無）を必ず明記する。
 *   担当者が特定電子メール法に違反する営業メールを送ってしまう事故を防ぐため。
 */
function fhs_admin_notify_body($ctx) {
    $b  = "査定のお申し込みが届きました。\n\n";
    $b .= "───── お客様情報 ─────\n";
    $b .= "■ メール : " . (isset($ctx['email']) ? $ctx['email'] : '') . "\n";
    if (!empty($ctx['customer_details'])) $b .= $ctx['customer_details'] . "\n";
    $b .= "\n───── 物件・ご状況 ─────\n";
    $b .= (isset($ctx['property_details']) ? $ctx['property_details'] : '') . "\n";
    $b .= "\n───── 営業連絡について ─────\n";
    $b .= !empty($ctx['marketing'])
        ? "○ 営業案内メールの受け取りに同意いただいています。\n"
        : "× 営業案内メールの受け取りには同意されていません。\n  今回のお申し込みへのご対応以外の営業メールは送らないでください（特定電子メール法）。\n";
    $b .= "\n管理画面「訪問査定申込 → 申込一覧」からも確認できます。";
    return $b;
}

/**
 * 「更新を確認」ボタン。
 * WordPressの自動チェックは最大12時間おきで、押さないと新版に気づけない。
 * こちらのキャッシュとWP側の更新情報を捨てて、その場で確認し直す。
 */
add_action('admin_post_fhs_check_update', 'fhs_check_update');
function fhs_check_update() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fhs_check_update');
    delete_transient('fhs_honki_updater_' . md5(plugin_basename(__FILE__)));
    delete_site_transient('update_plugins');
    if (function_exists('wp_update_plugins')) wp_update_plugins();
    wp_safe_redirect(admin_url('admin.php?page=fudosan-honki&checked=1'));
    exit;
}

/** 配信されている最新バージョン（分からなければ空） */
function fhs_latest_version() {
    $t = get_site_transient('update_plugins');
    $me = plugin_basename(__FILE__);
    if (is_object($t)) {
        if (!empty($t->response[$me]->new_version))  return $t->response[$me]->new_version;
        if (!empty($t->no_update[$me]->new_version)) return $t->no_update[$me]->new_version;
    }
    return '';
}

/* テストメール送信（迷惑メール判定・文面の確認用） */
add_action('admin_post_fhs_test_mail', 'fhs_test_mail');
function fhs_test_mail() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fhs_test_mail');
    $to = wp_get_current_user()->user_email;
    // サンプルの住所も、設定に合わせた入力例から作る（東京固定だと不自然なため）
    $sample_addr = trim(str_replace('例：', '', fhs_address_placeholder()));
    $ctx = array(
        'name' => '山田 太郎', 'email' => $to, 'tel' => '090-1234-5678',
        'ptype_label' => '中古マンション', 'address' => $sample_addr,
        'survey' => '訪問査定（実際に見てもらいたい）',
        'customer_details' => "■ お名前 : 山田 太郎\n■ 電話番号 : 090-1234-5678\n■ ご連絡しやすい時間帯 : 午後（12〜17時）",
        'property_details' => "■ 物件種別 : 中古マンション\n■ 物件住所 : 東京都渋谷区〇〇1-2-3\n■ ご希望の査定方法 : 訪問査定（実際に見てもらいたい）\n■ マンション名 : 〇〇マンション\n■ 専有面積（㎡） : 70\n■ 築年（西暦） : 2015",
    );
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    $from = fhs_opt('from_email'); $site = fhs_opt('site_name', '不動産査定');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $ok = wp_mail($to, '[テスト] ' . fhs_mail_subject(), fhs_mail_body($ctx), $headers);
    wp_safe_redirect(admin_url('admin.php?page=fudosan-honki&testmail=' . ($ok ? '1' : '0') . '&to=' . rawurlencode($to)));
    exit;
}

/* =========================================================================
 * 8. 管理画面：設定
 * ======================================================================= */
/**
 * 色の入力欄。カラーピッカーだけだと「いま何番の色なのか」が分からず、
 * ブランドカラーの指定（#1f6feb など）を貼り付けることもできないため、
 * ★HEXのテキスト入力を主にして、ピッカーは横に並べる。両者は双方向に同期する。
 */
function fhs_color_field($key, $default) {
    $v = fhs_opt($key, $default);
    ob_start(); ?>
    <span class="fhs-colorfield">
      <input type="color" class="fhs-color-pick" value="<?php echo esc_attr($v); ?>" aria-label="カラーピッカーで選ぶ">
      <input type="text" class="fhs-color-hex code" name="<?php echo FHS_OPT; ?>[<?php echo esc_attr($key); ?>]"
             value="<?php echo esc_attr($v); ?>" maxlength="7" size="9" spellcheck="false" autocomplete="off"
             placeholder="<?php echo esc_attr($default); ?>">
      <button type="button" class="button button-small fhs-color-reset" data-default="<?php echo esc_attr($default); ?>">初期値</button>
    </span>
<?php return ob_get_clean();
}

/**
 * 画像を選ぶ欄（プレビュー＋メディアライブラリ＋消す）。
 * 複数置けるようにIDは使わずクラスで組む。
 * $round を true にすると、プレビューを丸で表示する（実際の表示に合わせるため）。
 */
function fhs_image_field($key, $round = false) {
    $url = fhs_opt($key);
    ob_start(); ?>
    <div class="fhs-logofield">
      <span class="fhs-logo-preview<?php echo $url ? '' : ' is-empty'; ?><?php echo $round ? ' is-round' : ''; ?>">
        <?php if ($url): ?><img src="<?php echo esc_url($url); ?>" alt=""><?php else: ?>未設定<?php endif; ?>
      </span>
      <input type="url" class="fhs-logo-url" name="<?php echo FHS_OPT; ?>[<?php echo esc_attr($key); ?>]"
             value="<?php echo esc_attr($url); ?>" size="52" placeholder="https://example.com/logo.png">
      <button type="button" class="button fhs-logo-pick">メディアから選ぶ</button>
      <button type="button" class="button fhs-logo-clear">消す</button>
    </div>
<?php return ob_get_clean();
}

function fhs_settings_page() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    ?>
    <style>
      .fhs-colorfield{display:inline-flex;align-items:center;gap:8px}
      .fhs-colorfield input[type=color]{width:46px;height:34px;padding:2px;border:1px solid #8c8f94;border-radius:4px;background:#fff;cursor:pointer;flex:0 0 auto}
      .fhs-colorfield input[type=text]{width:104px;font-family:monospace;text-transform:lowercase}
      .fhs-colorfield input[type=text].fhs-bad{border-color:#d63638;box-shadow:0 0 0 1px #d63638}
      .fhs-logofield{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
      .fhs-logo-preview{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border:1px solid #dcdcde;border-radius:6px;background:#fff;overflow:hidden;flex:0 0 auto}
      .fhs-logo-preview img{max-width:100%;max-height:100%;width:auto;height:auto;display:block}
      .fhs-logo-preview.is-empty{color:#8c8f94;font-size:11px;background:#f6f7f7}
      .fhs-logo-preview.is-round{border-radius:50%}
      .fhs-logo-preview.is-round img{width:100%;height:100%;object-fit:cover}
      .fhs-recipes td{vertical-align:middle}
      .fhs-recipes .fhs-copy-src{display:block;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 10px;font-size:12.5px;line-height:1.6;word-break:break-all;user-select:all}
      .fhs-recipes .fhs-copy{white-space:nowrap}
    </style>
    <div class="wrap">
        <h1>訪問査定申込（本気査定） 設定</h1>
        <?php if (isset($_GET['testmail'])) {
            $tm_ok = ($_GET['testmail'] === '1');
            $tm_to = isset($_GET['to']) ? sanitize_email(wp_unslash($_GET['to'])) : '';
            echo '<div class="notice notice-' . ($tm_ok ? 'success' : 'error') . '"><p>' .
                ($tm_ok
                    ? 'テストメールを <strong>' . esc_html($tm_to) . '</strong> に送信しました。届かない場合は<strong>迷惑メールフォルダ</strong>も確認してください（届かない＝SPF/DKIM未設定の可能性大）。'
                    : 'テストメールの送信に失敗しました。WP Mail SMTP などの送信設定を確認してください。') .
                '</p></div>';
        } ?>
        <?php
        $fhs_latest = fhs_latest_version();
        $fhs_has_new = ($fhs_latest !== '' && version_compare(FHS_VER, $fhs_latest, '<'));
        if (isset($_GET['checked'])) {
            echo '<div class="notice notice-' . ($fhs_has_new ? 'warning' : 'success') . ' is-dismissible"><p>'
               . ($fhs_has_new
                   ? '新しいバージョン <strong>' . esc_html($fhs_latest) . '</strong> があります。'
                     . '<a href="' . esc_url(admin_url('plugins.php')) . '">プラグイン画面</a>から更新してください。'
                   : 'このプラグインは最新です。')
               . '</p></div>';
        }
        ?>
        <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span>ページに <code>[fudosan_honki]</code> を貼ると申込フォームが表示されます。詳しい書き方は「<strong>使い方</strong>」タブへ。</span>
        </p>
        <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:-6px 0 14px">
            <span class="description">バージョン <strong><?php echo esc_html(FHS_VER); ?></strong><?php
                if ($fhs_has_new) echo '　<span style="color:#b32d2e">最新は ' . esc_html($fhs_latest) . ' です</span>';
                elseif ($fhs_latest !== '') echo '　（最新です）';
            ?></span>
            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fhs_check_update'), 'fhs_check_update')); ?>">更新を確認</a>
            <?php if ($fhs_has_new): ?>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">プラグイン画面で更新する</a>
            <?php endif; ?>
        </p>
        <h2 class="nav-tab-wrapper" id="fhs-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="basic">基本設定</a>
            <a href="#" class="nav-tab" data-tab="company">査定担当会社</a>
            <a href="#" class="nav-tab" data-tab="fields">入力項目</a>
            <a href="#" class="nav-tab" data-tab="privacy">個人情報</a>
            <a href="#" class="nav-tab" data-tab="mail">自動返信メール</a>
            <a href="#" class="nav-tab" data-tab="style">デザイン</a>
            <a href="#" class="nav-tab" data-tab="usage">使い方</a>
        </h2>
        <form method="post" action="options.php">
            <?php settings_fields('fhs_group'); ?>

            <div class="fhs-tabpanel" data-tab="basic">
            <table class="form-table">
                <tr><th>サイト名</th><td><input type="text" name="<?php echo FHS_OPT; ?>[site_name]" value="<?php echo esc_attr(fhs_opt('site_name', '不動産査定')); ?>" size="40">
                    <p class="description">メールの件名や差し込みに使われます。</p></td></tr>
                <tr><th>物件住所の入力例</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[address_example]" value="<?php echo esc_attr(fhs_opt('address_example')); ?>" size="50" placeholder="<?php echo esc_attr(fhs_address_placeholder()); ?>">
                    <p class="description">
                        物件住所の欄に薄く表示される入力例です。空欄なら「<?php echo esc_html(fhs_address_placeholder()); ?>」と表示されます。<br>
                        エリアを絞ったサイトなら <code>例：岡山市北区〇〇1-2-3</code> のように具体的に書くと、お客様が書き方に迷いません。<br>
                        <strong>都道府県は入れていません。</strong>市区町村単位で扱うサービスのため、県から書かせる必要がないためです。
                    </p>
                </td></tr>
                <tr><th>送信元メール</th><td><input type="email" name="<?php echo FHS_OPT; ?>[from_email]" value="<?php echo esc_attr(fhs_opt('from_email')); ?>" size="40" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    <p class="description">お客様への受付完了メールの差出人。空欄ならWordPressの既定の差出人になります。到達率のため WP Mail SMTP 等で SPF/DKIM を設定してください。</p></td></tr>
                <tr><th>通知先メール（担当者）</th><td>
                    <input type="email" multiple name="<?php echo FHS_OPT; ?>[notify_email]" value="<?php echo esc_attr(fhs_opt('notify_email')); ?>" size="60" placeholder="tanto@example.com, info@example.com"><br>
                    <label style="display:inline-block;margin-top:8px"><input type="checkbox" name="<?php echo FHS_OPT; ?>[notify_on]" value="1" <?php checked(fhs_flag('notify_on', true)); ?>> 申し込みが届いたら通知する</label>
                    <p class="description">
                        <strong>カンマ区切りで複数指定できます</strong>（担当者と管理者の両方に届かせたい場合など）。改行区切りでも構いません。<br>
                        保存すると「a@example.com, b@example.com」の形に整えられ、<strong>形式の正しくないものは取り除かれます</strong>（保存後の欄でご確認ください）。<br>
                        空欄なら送信元メール（無ければ管理者アドレス）に通知します。<strong>本気度の高いお客様なので、通知は必ずONを推奨します。</strong>
                    </p></td></tr>
                <tr><th>フォーム冒頭の案内文</th><td>
                    <textarea name="<?php echo FHS_OPT; ?>[lead_text]" rows="3" style="width:100%;max-width:760px"><?php echo esc_textarea(fhs_opt('lead_text')); ?></textarea>
                    <p class="description">フォームの一番上に表示される案内文（任意）。例：「担当者が実際に物件を確認し、根拠のある価格をご提示します。まずはお気軽にお申し込みください。」</p></td></tr>
                <tr><th>査定ページ</th><td>
                    <?php
                    $sid = (int) fhs_opt('satei_page_id', 0);
                    if (function_exists('wp_dropdown_pages')) {
                        wp_dropdown_pages(array(
                            'name'              => FHS_OPT . '[satei_page_id]',
                            'selected'          => $sid,
                            'show_option_none'  => '― 選択してください ―',
                            'option_none_value' => 0,
                        ));
                    }
                    $surl = fhs_satei_url();
                    if ($sid > 0 && $surl) {
                        $st = get_post_status($sid);
                        echo ' <a class="button" href="' . esc_url(get_edit_post_link($sid)) . '">編集</a> ';
                        echo '<a class="button" href="' . esc_url($surl) . '" target="_blank" rel="noopener">表示</a>';
                        if ($st !== 'publish') {
                            echo '<p class="description" style="color:#b32d2e"><strong>このページはまだ下書きです。</strong>内容を確認して公開してください（公開するまでお客様には表示されません）。</p>';
                        }
                    }
                    ?>
                    <p class="description">
                        <strong><code>[fudosan_honki]</code> を貼った査定ページ</strong>を選びます。ティザーの「送信」で移動する先です。<br>
                        ここを設定しておけば、ティザーのショートコードに <code>url="…"</code> を書かなくて済みます。<br>
                        <span class="description">※ プラグインを有効化したとき、スラッグ <code>satei</code> の固定ページを<strong>下書きで自動作成</strong>しています（同じスラッグのページが既にある場合はそれを使います）。</span>
                    </p>
                </td></tr>
                <tr><th>プライバシーポリシーURL</th><td><input type="url" name="<?php echo FHS_OPT; ?>[privacy_url]" value="<?php echo esc_attr(fhs_opt('privacy_url')); ?>" size="50"></td></tr>
                <tr><th>利用規約・免責URL</th><td><input type="url" name="<?php echo FHS_OPT; ?>[terms_url]" value="<?php echo esc_attr(fhs_opt('terms_url')); ?>" size="50"></td></tr>
            </table>
            </div>
<div class="fhs-tabpanel" data-tab="company" style="display:none">
            <h3>査定担当会社</h3>
            <p class="description" style="max-width:900px">
                ここに入れた内容が、<strong>フォーム下部の「査定担当会社」の欄</strong>と
                <strong>受付完了メールの末尾</strong>に表示されます。<br>
                お客様が「どこの会社に自宅と連絡先を渡すのか」を判断する材料になります。
                提携先の不動産会社が査定を行う場合は、<strong>その会社の情報</strong>を入れてください。
            </p>
            <table class="form-table">
                <tr><th>会社名</th><td><input type="text" name="<?php echo FHS_OPT; ?>[operator_name]" value="<?php echo esc_attr(fhs_opt('operator_name')); ?>" size="40" placeholder="例：ミカタ株式会社">
                    <p class="description">フォームとメールに表示されます。<strong>お客様が「どこの会社に自宅と連絡先を渡すのか」を判断する材料</strong>なので、必ずご記入ください。</p></td></tr>
                <tr><th>所在地</th><td><input type="text" name="<?php echo FHS_OPT; ?>[operator_address]" value="<?php echo esc_attr(fhs_opt('operator_address')); ?>" size="50" placeholder="例：岡山県岡山市北区○○1-2-3"></td></tr>
                <tr><th>会社サイトURL</th><td>
                    <input type="url" name="<?php echo FHS_OPT; ?>[operator_url]" value="<?php echo esc_attr(fhs_opt('operator_url')); ?>" size="50" placeholder="https://example.com/">
                    <p class="description">
                        フォームとメールに表示されます。お客様が会社を調べられるようにしておくと、
                        <strong>連絡先を預ける不安が減り、申し込みが増えます</strong>。
                    </p>
                </td></tr>
                <tr><th>電話番号</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[operator_contact]" value="<?php echo esc_attr(fhs_opt('operator_contact')); ?>" size="40" placeholder="例：086-000-0000 / info@example.com"><br>
                    <label style="display:inline-block;margin-top:8px"><input type="checkbox" name="<?php echo FHS_OPT; ?>[show_contact]" value="1" <?php checked(fhs_flag('show_contact', false)); ?>> この連絡先を<strong>フォームにも表示する</strong></label>
                    <p class="description">
                        <strong>ふだんはオフのままを推奨します。</strong>申し込みフォームに電話番号があると、
                        フォームを送らずに電話で済ませる方が出て、<strong>申し込み数が減ります</strong>（どこから来たお客様かも分からなくなります）。<br>
                        オフでも、<strong>受付完了メールには必ず記載されます</strong>ので、お客様が連絡できなくなることはありません。
                    </p>
                </td></tr>
                <tr><th>問い合わせメール</th><td>
                    <input type="email" name="<?php echo FHS_OPT; ?>[operator_email]" value="<?php echo esc_attr(fhs_opt('operator_email')); ?>" size="40" placeholder="info@example.com">
                    <p class="description">
                        受付完了メールの末尾に載る、お客様からの問い合わせ先です。<br>
                        <span class="description">※申し込みの通知を受け取る「通知先メール（担当者）」とは別に指定できます。</span>
                    </p>
                </td></tr>
                <tr><th>会社の画像</th><td>
                    <?php echo fhs_image_field('company_image', true); ?>
                    <p class="description">
                        フォーム下部の「査定担当会社」の欄で、<strong>会社名・所在地の左に丸く表示されます</strong>。<br>
                        会社ロゴ、店舗の外観、担当者の顔写真など。<strong>正方形の画像</strong>を用意してください
                        （正方形でなくても中央を正方形に切り出して丸くします）。空欄なら表示しません。
                    </p>
                </td></tr>
            </table>
            </div>


            <div class="fhs-tabpanel" data-tab="fields" style="display:none">
            <h3>入力項目の設定</h3>
            <p class="description" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;max-width:860px">
                項目ごとに <strong>必須／任意／非表示</strong> を選べます。<br>
                <strong>項目を増やすほど申込数は減り、リードの質は上がります。</strong>まずは必須を絞り、
                足りない情報は担当者がお電話で聞く運用をおすすめします。<br>
                ※ <strong>メールアドレス・物件種別・物件住所・同意チェック</strong>は常に必須です（連絡と査定に不可欠なため、切り替えできません）。
            </p>
            <?php
            $groups = array(
                'customer'  => array('お客様のご連絡先', 'お名前・電話番号は「本気の申し込み」を受けるための中心項目です。'),
                'situation' => array('売却のご状況', '担当者が優先順位を付け、初回の連絡で的確に話すための情報です。'),
            );
            foreach (fhs_property_fields() as $pt => $flds) {
                $groups['prop_' . $pt] = array('物件情報：' . $GLOBALS['FHS_PTYPE_LABEL'][$pt], '「' . $GLOBALS['FHS_PTYPE_LABEL'][$pt] . '」が選ばれたときだけ表示される項目です。');
            }
            $all = fhs_all_groups();
            foreach ($groups as $g => $meta):
                $flds = $all[$g];
            ?>
            <h4 style="margin:26px 0 6px"><?php echo esc_html($meta[0]); ?></h4>
            <p class="description" style="margin:0 0 8px"><?php echo esc_html($meta[1]); ?></p>
            <table class="widefat striped" style="max-width:640px">
                <thead><tr><th style="width:46%">項目</th><th style="width:18%">必須</th><th style="width:18%">任意</th><th style="width:18%">非表示</th></tr></thead>
                <tbody>
                <?php foreach ($flds as $fd):
                    $k = 'mode_' . $g . '_' . $fd['key'];
                    $cur = fhs_mode($g, $fd['key'], $fd['def']);
                ?>
                    <tr>
                        <td><?php echo esc_html($fd['label']); ?></td>
                        <?php foreach (array('req','opt','off') as $m): ?>
                        <td><label style="display:block"><input type="radio" name="<?php echo FHS_OPT . '[' . $k . ']'; ?>" value="<?php echo $m; ?>" <?php checked($cur, $m); ?>></label></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>

            <h4 style="margin:26px 0 6px">スパム対策</h4>
            <p class="description" style="max-width:860px">
                すでに<strong>ハニーポット・送信までの経過時間・同一IP/同一メールの回数制限</strong>が常時働いています。
                ここでは、それを抜けてきた場合の追加の網を設定します。<br>
                <strong>引っかかった送信には理由を伝えません</strong>（どこで弾かれたかをボットに学習させないため）。
            </p>
            <table class="form-table">
                <tr><th>リンクを含む申し込み</th><td>
                    <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[spam_block_link]" value="1" <?php checked(fhs_flag('spam_block_link', true)); ?>> URL（http://… など）が入力されていたら受け付けない</label>
                    <p class="description">
                        査定の申し込みでURLを書く理由はまずないので、<strong>オンのままを推奨します。</strong>
                        宣伝リンクを貼る典型的なスパムを止められます。<br>
                        ※あわせて、キリル文字・アラビア文字・タイ文字が含まれる申し込みは常に受け付けません（設定不要）。
                    </p>
                </td></tr>
                <tr><th>お名前の日本語チェック</th><td>
                    <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[spam_require_ja]" value="1" <?php checked(fhs_flag('spam_require_ja', false)); ?>> お名前に日本語が1文字も含まれない場合は受け付けない</label>
                    <p class="description">
                        海外からのスパムによく効きますが、<strong>お名前をローマ字で書く方や外国籍の方も弾いてしまいます。</strong><br>
                        <strong>既定はオフ</strong>です。実際にスパムが増えてきたらオンにしてください。
                    </p>
                </td></tr>
                <tr><th>NGワード</th><td>
                    <textarea name="<?php echo FHS_OPT; ?>[spam_words]" rows="4" style="width:100%;max-width:520px" placeholder="1行に1語ずつ&#10;例：SEO対策&#10;例：ビットコイン"><?php echo esc_textarea(fhs_opt('spam_words')); ?></textarea>
                    <p class="description">
                        1行に1語。入力欄のどこかにこの語が含まれていたら受け付けません（大文字小文字は区別しません）。<br>
                        実際に届いたスパムの特徴的な単語を足していく使い方を想定しています。<strong>短すぎる語は誤爆します</strong>のでご注意ください。
                    </p>
                </td></tr>
            </table>

            <h4 style="margin:26px 0 6px">見せ方</h4>
            <table class="form-table"><tr><th>ステップ表示</th><td>
                <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[step_form]" value="1" <?php checked(fhs_flag('step_form', true)); ?>> 査定ページのフォームを「物件 → ご状況 → ご連絡先」の3ステップに分けて表示する</label>
                <p class="description">
                    一画面に全部並べるより<strong>途中離脱が減ります</strong>（一括査定サイトはほぼこの形です）。<br>
                    進み具合のバーが出て、「次へ進む」を押すたびにその画面の必須項目だけを確認します。
                    <strong>お名前・電話番号は必ず最後のステップ</strong>で聞きます。<br>
                    ティザーで入力済みのステップは自動で飛ばします。オフにすると従来どおり1画面に全項目を表示します。
                </p>
            </td></tr></table>

            <h4 style="margin:26px 0 6px">そのほかの欄</h4>
            <table class="form-table"><tr><th>表示する項目</th><td>
                <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[show_note]" value="1" <?php checked(fhs_flag('show_note', true)); ?>> 「備考・ご要望」の自由入力欄</label><br>
                <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[show_marketing]" value="1" <?php checked(fhs_flag('show_marketing', true)); ?>> 「営業案内メールを希望」チェック欄</label>
                <p class="description">営業案内メールのチェックは<strong>同意の証拠</strong>になります（特定電子メール法）。オフにすると、今回の申し込み以外の営業メールは送れません。</p>
            </td></tr></table>
            </div>

            <div class="fhs-tabpanel" data-tab="privacy" style="display:none">
            <h3>個人情報の取り扱い</h3>
            <p class="description" style="background:#fcf0f1;border-left:4px solid #b32d2e;padding:10px 12px;max-width:860px">
                このフォームは<strong>お名前・電話番号</strong>を受け取ります。匿名査定と違い、
                <strong>個人情報保護法の義務が重くなります</strong>。とくに、集めた情報を
                <strong>他社（提携する不動産会社など）に渡す場合は、お客様の同意が必要です（個情法27条）。</strong>
            </p>
            <table class="form-table">
                <tr><th>他社へ情報を渡しますか</th><td>
                    <label><input type="checkbox" name="<?php echo FHS_OPT; ?>[third_party]" value="1" <?php checked(fhs_flag('third_party', false)); ?>> 提携する不動産会社などに、お客様の情報を提供する</label>
                    <p class="description">
                        <strong>ONにすると</strong>、フォームの利用目的と同意文に「提携先へ提供すること」が明記され、
                        お客様の同意チェックがその同意を兼ねる形になります。<br>
                        <strong>OFFのまま他社に渡すのは違法です。</strong>自社内だけで対応する場合はOFFのままにしてください。
                    </p>
                </td></tr>
                <tr><th>提供先の説明</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[third_party_name]" value="<?php echo esc_attr(fhs_opt('third_party_name')); ?>" size="60" placeholder="例：当社が提携する不動産会社（お住まいの地域を担当する1〜3社）">
                    <p class="description">上をONにしたときにフォームへ表示されます。<strong>具体的に書くほど信頼されます。</strong>空欄なら「当社が提携する不動産会社」と表示します。</p>
                </td></tr>
                <tr><th>提供先を説明したページ</th><td>
                    <input type="url" name="<?php echo FHS_OPT; ?>[third_party_url]" value="<?php echo esc_attr(fhs_opt('third_party_url')); ?>" size="60" placeholder="https://example.com/partners/">
                    <p class="description">
                        提携会社の一覧ページや、提携会社での個人情報の取り扱いを説明したページのURL。<br>
                        指定すると、上の「提供先の説明」がフォーム上で<strong>リンクになります</strong>。<br>
                        <strong>提携先が1社だけなら、ここは空欄で構いません。</strong>上の「提供先の説明」に会社名が書いてあれば、
                        お客様は「どこに渡るか」を判断できます。<br>
                        一覧ページが要るのは<strong>提携先が複数あって、渡る先がその場で特定できない場合</strong>です
                        （一括査定サイトが提携会社一覧を置いているのはこのためです）。<br>
                        なお、<strong>提携先のプライバシーポリシーを自社サイトに載せる義務はありません</strong>。それは提携先自身が公表すべきものです。
                    </p>
                </td></tr>
            </table>
            <h4>フォームに自動表示される内容</h4>
            <p class="description">下記はコードで固定されており、消せません（同意の取得に必要なため）。</p>
            <ul style="list-style:disc;margin-left:20px;max-width:860px">
                <li>個人情報の利用目的（査定とご連絡のため）</li>
                <li>同意チェック（プライバシーポリシー・免責事項へのリンク付き）</li>
                <li>査定担当会社（会社名・所在地・サイト）※「査定担当会社」タブに入れた項目のみ</li>
            </ul>
            </div>

            <div class="fhs-tabpanel" data-tab="mail" style="display:none">
            <table class="form-table">
                <tr><th>件名</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[mail_subject]" value="<?php echo esc_attr(fhs_opt('mail_subject')); ?>" size="60" placeholder="【{site_name}】査定のお申し込みを受け付けました">
                    <p class="description">空欄で初期件名。</p>
                </td></tr>
                <tr><th>本文</th><td>
                    <textarea name="<?php echo FHS_OPT; ?>[mail_body]" rows="20" style="width:100%;max-width:760px;font-family:monospace;font-size:13px"><?php echo esc_textarea(fhs_opt('mail_body') ?: fhs_default_mail_body()); ?></textarea>
                    <p class="description">
                        空欄にして保存すると初期文面に戻ります。使える差し込みタグ：<br>
                        <code>{site_name}</code> <code>{customer_name}</code> <code>{customer_details}</code>（お客様情報のまとまり） <code>{property_details}</code>（物件情報のまとまり） <code>{ptype}</code> <code>{address}</code> <code>{survey}</code> <code>{email}</code> <code>{tel}</code><br>
                        <span class="description">※会社名・電話などの署名は<strong>本文に書く必要はありません</strong>。「査定担当会社」タブの内容がメール末尾に自動で入ります（本文に書くと二重になるため、書かれていても取り除きます）。</span>
                    </p>
                    <p class="description" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;margin-top:10px">
                        <strong>メールの末尾には「査定担当会社の連絡先」が自動で付きます。</strong><br>
                        内容は<a href="#" class="fhs-gotab" data-tab="company">査定担当会社</a>タブのものです。
                        本文には<strong>ご案内したい内容だけ</strong>をお書きください。
                    </p>
                </td></tr>
                <tr><th>到達確認</th><td>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fhs_test_mail'), 'fhs_test_mail')); ?>" class="button">テストメールを自分宛に送信</a>
                    <p class="description">
                        現在の件名・本文テンプレートでサンプルを送ります（保存してから押してください）。<br>
                        <strong>迷惑メールに入る場合</strong>は「WP Mail SMTP」等でSMTP送信にし、送信ドメインの <code>SPF</code> / <code>DKIM</code> / <code>DMARC</code> を設定してください。
                    </p>
                </td></tr>
            </table>
            </div>

            <div class="fhs-tabpanel" data-tab="style" style="display:none">
            <h3>ティザーの見出し</h3>
            <p class="description">記事に置く入口フォーム（ティザー）の見出しまわりの設定です。<strong>空欄にした項目は表示されません。</strong></p>
            <table class="form-table">
                <tr><th>バッジ</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[teaser_badge]" value="<?php echo esc_attr(fhs_opt('teaser_badge')); ?>" size="30" placeholder="例：無料・秘密厳守">
                    <p class="description">見出しの左（縦のときは上）に出る小さなバッジ。空欄なら表示しません。</p>
                </td></tr>
                <tr><th>メリットのタグ</th><td>
                    <input type="text" name="<?php echo FHS_OPT; ?>[teaser_tags]" value="<?php echo esc_attr(fhs_opt('teaser_tags')); ?>" size="60" placeholder="例：無料, 地場優良企業対応, 1社査定">
                    <p class="description">
                        見出しの右（縦のときは下）に並ぶタグ。<strong>カンマ（,）で区切ります</strong>（、や | でも構いません）。<br>
                        <strong>3つくらいまでが読みやすい</strong>です。空欄なら表示しません。<br>
                        例：<code>無料, 地場優良企業対応, 1社査定</code> ／ <code>しつこい営業なし, 最短即日, 相談だけOK</code>
                    </p>
                </td></tr>
                <tr><th>アイコン画像</th><td>
                    <?php echo fhs_image_field('logo_url'); ?>
                    <p class="description">
                        会社ロゴやファビコンなど。<strong>見出しの左に小さく表示されます</strong>（高さは見出しに合わせて自動調整）。<br>
                        正方形に近い画像がきれいに収まります。空欄なら表示しません。
                    </p>
                </td></tr>
            </table>
            <p class="description">いずれも、ショートコードで <code>badge="…"</code> <code>tags="…"</code> <code>logo="…"</code> を指定すると、そのフォームだけ差し替えられます。</p>

            <h3>フォームの色</h3>
            <p class="description">カラーコード（<code>#1f6feb</code> のような6桁）を直接入力できます。左の四角を押すとカラーピッカーからも選べます。</p>
            <table class="form-table">
                <tr><th>ブランドカラー</th><td>
                    <?php echo fhs_color_field('color_brand', '#1f6feb'); ?>
                    <p class="description">入力済みチェック（✓）、次の入力欄のハイライト、物件種別で選んだタイル、メリットのタグに使われます。</p>
                </td></tr>
                <tr><th>ボタンの背景色</th><td>
                    <?php echo fhs_color_field('color_btn_bg', '#e65100'); ?>
                    <p class="description">
                        送信ボタン・「次へ進む」ボタンの背景色。<strong>空欄なら <code>#e65100</code>（オレンジ）</strong>です。<br>
                        不動産の一括査定サイトは軒並み<strong>暖色（オレンジ〜赤）</strong>を使っています。
                        色そのものより「<strong>まわりから浮いているか</strong>」が効くので、紺や白が基調のページでは暖色が有利です。<br>
                        ブランドカラーに合わせたい場合は、上のブランドカラーと同じ値を入れてください。
                    </p>
                </td></tr>
                <tr><th>ボタンの文字色</th><td><?php echo fhs_color_field('color_btn_text', '#ffffff'); ?></td></tr>
                <tr><th>見出しの色</th><td>
                    <?php echo fhs_color_field('color_title', '#1f6feb'); ?>
                    <p class="description">ティザーの見出し（例：60秒でかんたん入力）の文字色。</p>
                </td></tr>
                <tr><th>「必須」バッジの色</th><td>
                    <?php echo fhs_color_field('color_badge', '#ff5a36'); ?>
                    <p class="description">未入力の項目に付くバッジと、ティザーの「無料・秘密厳守」バッジ。入力すると「ブランドカラーの ✓」に変わります。</p>
                </td></tr>
            </table>
            <p class="description">初期値：ブランド <code>#1f6feb</code> ／ ボタン文字 <code>#ffffff</code> ／ 見出し <code>#1f6feb</code> ／ バッジ <code>#ff5a36</code><br>
                空欄のまま保存すると初期値に戻ります。</p>
            </div>

            <div class="fhs-tabpanel" data-tab="usage" style="display:none">
            <h3>ショートコードの貼り方</h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:170px">用途</th><th>ショートコード</th></tr></thead>
                <tbody>
                <tr><td><strong>標準</strong></td>
                    <td><code>[fudosan_honki]</code><br><span class="description">全項目・幅100%・枠なし。</span></td></tr>
                <tr><td><strong>コンパクト</strong><br><span class="description">サイドバー等</span></td>
                    <td><code>[fudosan_honki design="compact"]</code><br><span class="description">必須項目のみ・幅440pxのカード。</span></td></tr>
                <tr><td><strong>カード</strong></td>
                    <td><code>[fudosan_honki design="card"]</code><br><span class="description">全項目を枠＋影のカードで表示。</span></td></tr>
                <tr style="background:#fffbe6"><td><strong>ティザー（横長）</strong><br><span class="description">記事の途中・記事末</span></td>
                    <td><code>[fudosan_honki design="teaser"]</code><br>
                        <span class="description"><strong>入力欄が横一列に並ぶ</strong>横長タイプ。2〜3項目だけ入力してもらい、ボタンで<strong>査定ページ</strong>へ。入力値は自動で引き継がれます。物件種別は<strong>タイルを1タップ</strong>で選べます。<br>
                        幅は既定で本文いっぱい。<strong>狭い場所やスマホでは自動的に縦積みに切り替わります。</strong></span></td></tr>
                <tr style="background:#fffbe6"><td><strong>ティザー（縦）</strong><br><span class="description">サイドバー・記事末</span></td>
                    <td><code>[fudosan_honki design="teaser-v"]</code><br>
                        <span class="description">同じ内容を<strong>常に縦積み</strong>・幅440pxのカードで。サイドバーなど幅の狭い場所向け。</span></td></tr>
                </tbody>
            </table>

            <h3>ティザー（入口フォーム）の使い方</h3>
            <p class="description" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;max-width:900px">
                いきなり全項目のフォームを見せると身構えられてしまいます。<strong>記事の中には「2〜3項目だけのティザー」を置き、
                続きは査定ページで書いてもらう</strong>のが定石です。<br>
                ティザーで入力した内容は査定ページに<strong>自動で引き継がれ</strong>、「↓ 続きはこちらから」と案内して
                <strong>次に書く欄まで自動でスクロール</strong>します。あと何項目で終わるかも表示されるので、離脱が減ります。
            </p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:110px">属性</th><th>意味</th></tr></thead>
                <tbody>
                <tr><td><code>url</code></td><td>ボタンの遷移先。<strong>ふだんは書かなくて構いません</strong>（設定→基本設定→「査定ページ」で選んだページへ送ります）。<br>
                    そのフォームだけ別のページへ送りたいときに指定します。<br>
                    <strong>例：</strong><code>url="https://fudosan-uru.jp/○○○/satei/"</code>（<code>○○○</code> はエリアのスラッグなど）<br>
                    <span class="description">同じサイト内なら <code>url="/○○○/satei/"</code> のように <code>/</code> で始まる書き方でも構いません。</span></td></tr>
                <tr><td><code>fields</code></td><td>聞く項目と順番。<code>ptype</code>（物件種別）/ <code>address</code>（物件の住所）/ <code>survey</code>（査定方法）/ <code>purpose</code>（ご事情）/ <code>timing</code>（希望時期）から選ぶ。省略時は <code>ptype,address</code><br>
                    <span class="description">※ <strong>お名前・電話番号・メールはティザーには置けません。</strong>個人情報は、利用目的の明示と同意チェックがある査定ページで受け取る決まりにしているためです。</span></td></tr>
                <tr><td><code>width</code></td><td>横幅。<strong>省略時は横長＝本文の幅いっぱい、縦＝440px</strong>（どちらも中央寄せ）。<code>width="820"</code> のように数字だけ書けばpx、<code>width="100%"</code> も指定できます。<br>
                    <span class="description">狭くすると入力欄は自動的に縦積みへ切り替わります（おおむね560px以下から）。</span></td></tr>
                <tr><td><code>title</code></td><td>見出し（省略時：60秒でかんたん入力）</td></tr>
                <tr><td><code>subtitle</code></td><td>小見出し（省略時は表示なし）</td></tr>
                <tr><td><code>badge</code></td><td>見出しの左（縦のときは上）のバッジ。<strong>ふだんは「デザイン」タブで設定</strong>し、ここではそのフォームだけ変えたいときに使います。<code>badge=""</code> でそのフォームだけ非表示</td></tr>
                <tr><td><code>tags</code></td><td>見出しの右（縦のときは下）に並ぶメリットのタグ。カンマ区切り。例：<code>tags="無料,地場優良企業対応,1社査定"</code><br>
                    <span class="description">こちらも「デザイン」タブで設定すれば全ティザーに反映されます。</span></td></tr>
                <tr><td><code>steps</code></td><td><code>steps="0"</code> で「STEP 1」「STEP 2」の表記を消す</td></tr>
                <tr><td><code>logo</code></td><td>見出しの左に出すアイコン画像のURL。<strong>ふだんは「デザイン」タブで設定すれば全部のティザーに出ます</strong>ので、ここで指定するのは<strong>そのフォームだけ画像を変えたいとき</strong>です</td></tr>
                <tr><td><code>note</code></td><td>フォーム下の小さな注記。<strong><code>|</code>（縦棒）で改行</strong>できます<br>
                    <span class="description">例：<code>note="しつこい営業はいたしません|査定は無料です"</code>　省略時は「入力内容は次のページに引き継がれます。／この時点ではまだ送信されません。」の2行</span></td></tr>
                <tr><td><code>button</code></td><td>ボタンの文言（省略時：無料で査定を依頼する）</td></tr>
                </tbody>
            </table>
            <h3>そのままコピーして使えます</h3>
            <p class="description">
                <strong>そのまま貼るだけで使えます。</strong>遷移先は
                <a href="<?php echo esc_url(admin_url('admin.php?page=fudosan-honki')); ?>">設定 → 基本設定 → 査定ページ</a>
                で選んだページになるので、ショートコードにURLを書く必要はありません。<br>
                <span class="description">別のページへ送りたいときだけ <code>url="…"</code> を足してください（下の属性表を参照）。</span>
            </p>
            <table class="widefat striped fhs-recipes" style="max-width:980px">
                <thead><tr><th style="width:190px">やりたいこと</th><th>ショートコード</th><th style="width:90px"></th></tr></thead>
                <tbody>
                <tr>
                    <td><strong>記事の途中に置く</strong><br><span class="description">いちばん基本。入力欄が横一列</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki design="teaser"]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                <tr>
                    <td><strong>幅を抑える</strong><br><span class="description">本文が広いときに</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki design="teaser" width="820"]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                <tr>
                    <td><strong>サイドバーに置く</strong><br><span class="description">縦積み・幅440px</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki design="teaser-v"]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                <tr>
                    <td><strong>見出しを地域に合わせる</strong><br><span class="description">エリア記事向け</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki design="teaser" title="岡山市の売却価格を調べる" subtitle="ご入力は60秒。しつこい営業はいたしません"]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                <tr>
                    <td><strong>売却時期も聞く</strong><br><span class="description">横一列に3項目</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki design="teaser" fields="ptype,address,timing"]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                <tr>
                    <td><strong>査定ページ本体</strong><br><span class="description">遷移先のページに貼る</span></td>
                    <td><code class="fhs-copy-src">[fudosan_honki]</code></td>
                    <td><button type="button" class="button fhs-copy">コピー</button></td>
                </tr>
                </tbody>
            </table>
            <p class="description" style="background:#fff8e6;border-left:4px solid #dba617;padding:10px 12px;max-width:980px;margin-top:12px">
                <strong>書き方の注意：属性と属性の間には必ず半角スペースを入れてください。</strong><br>
                × <code>url="/○○○/satei/"width="640"</code>　→　○ <code>url="/○○○/satei/" width="640"</code><br>
                <span class="description">※ スペースが抜けていても動くようにしてありますが、その場合はフォームの上に
                （管理者にだけ見える）お知らせが出ます。</span>
            </p>
            <p class="description">
                <strong>横長と縦の違い：</strong>横長（<code>teaser</code>）は<strong>入力欄が横一列に並びます</strong>。縦（<code>teaser-v</code>）は常に縦積みです。<br>
                横長は幅が足りなくなると自動で縦積みに切り替わるので、スマホでもそのまま使えます。
            </p>
            <p class="description">属性 <code>button</code> はどのデザインでも使えます。例：<code>[fudosan_honki button="無料で査定を依頼する"]</code></p>

            <h3>申し込み後の動き</h3>
            <ol>
                <li>お客様に<strong>受付完了メール</strong>を自動返信（内容は「自動返信メール」タブで編集可）</li>
                <li><strong>通知先メール（担当者）</strong>に申込内容を通知（営業連絡の可否つき）</li>
                <li>担当者がお客様へ連絡し、査定を実施</li>
            </ol>

            <h3>匿名査定・査定書作成受付との違い</h3>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>プラグイン</th><th>取得する情報</th><th>価格の表示</th></tr></thead>
                <tbody>
                <tr><td>かんたん不動産AI査定（匿名）</td><td>メールのみ</td><td>統計から参考価格レンジを即時表示</td></tr>
                <tr><td>査定書作成受付</td><td>メールのみ</td><td>表示しない（後日、査定書を送付）</td></tr>
                <tr><td><strong>訪問査定申込（本プラグイン）</strong></td><td><strong>お名前・電話番号まで</strong></td><td>表示しない（担当者が個別に対応）</td></tr>
                </tbody>
            </table>
            <p class="description">3つとも同じサイトで併用できます（メニュー・データは別々）。匿名 → 本気、と段階を分けて導線を作るのが定石です。</p>

            <h3 style="color:#b32d2e">法的な注意</h3>
            <p class="description" style="max-width:900px">
                
                フォーム・メールの免責文でその旨を明示しています。<strong>この注記は受付完了メールと完了画面に自動で入ります（消せません）。</strong><br>
                また、集めたお名前・電話番号を<strong>他社に渡す場合は「個人情報」タブの設定を必ずONにしてください</strong>（同意なしの第三者提供は違法です）。
                公開前に弁護士等の確認を推奨します。
            </p>
            </div>

            <div id="fhs-save"><?php submit_button(); ?></div>
        </form>
    </div>
    <script>
    (function(){
        /* 色欄：HEXテキストとカラーピッカーを双方向に同期する */
        function expand(v){   // #abc → #aabbcc（input[type=color] は6桁しか受け付けない）
            return v.length === 4 ? '#' + v[1]+v[1] + v[2]+v[2] + v[3]+v[3] : v;
        }
        function norm(v){
            v = String(v == null ? '' : v).trim().toLowerCase();
            if (v !== '' && v.charAt(0) !== '#') v = '#' + v;
            return /^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v) ? v : null;
        }
        document.querySelectorAll('.fhs-colorfield').forEach(function(f){
            var pick = f.querySelector('.fhs-color-pick'),
                hex = f.querySelector('.fhs-color-hex'),
                reset = f.querySelector('.fhs-color-reset');
            pick.addEventListener('input', function(){ hex.value = pick.value; hex.classList.remove('fhs-bad'); });
            hex.addEventListener('input', function(){
                var v = norm(hex.value);
                if (v) { pick.value = expand(v); hex.classList.remove('fhs-bad'); }
                else hex.classList.toggle('fhs-bad', hex.value.trim() !== '');   // 空欄は初期値に戻る指定として許す
            });
            hex.addEventListener('blur', function(){
                var v = norm(hex.value);
                if (v) hex.value = v;                       // #ABC → #abc に整える
                else if (hex.value.trim() !== '') { hex.value = pick.value; hex.classList.remove('fhs-bad'); }
            });
            reset.addEventListener('click', function(){
                hex.value = reset.getAttribute('data-default');
                pick.value = reset.getAttribute('data-default');
                hex.classList.remove('fhs-bad');
            });
        });

        /* 画像を選ぶ欄。ページ内にいくつあっても動くようクラスで走査する。
           wp.media が使えない環境でも、URLを直接貼れば動く。 */
        document.querySelectorAll('.fhs-logofield').forEach(function(field){
            var input = field.querySelector('.fhs-logo-url');
            var preview = field.querySelector('.fhs-logo-preview');
            var pick = field.querySelector('.fhs-logo-pick');
            var clear = field.querySelector('.fhs-logo-clear');
            if (!input) return;
            var frame = null;
            function render(){
                var url = input.value.trim();
                if (url) { preview.innerHTML = '<img alt="">'; preview.querySelector('img').src = url; preview.classList.remove('is-empty'); }
                else { preview.textContent = '未設定'; preview.classList.add('is-empty'); }
            }
            input.addEventListener('input', render);
            if (clear) clear.addEventListener('click', function(){ input.value = ''; render(); });
            if (pick) pick.addEventListener('click', function(){
                if (!window.wp || !window.wp.media) { input.focus(); return; }   // 使えなければ手入力に任せる
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: '画像を選ぶ', button: { text: 'この画像を使う' },
                                   library: { type: 'image' }, multiple: false });
                frame.on('select', function(){
                    input.value = frame.state().get('selection').first().toJSON().url;
                    render();
                });
                frame.open();
            });
        });

        /* ショートコードのコピーボタン。
           管理画面が https でないと navigator.clipboard が使えないため、
           その場合は選択＋execCommand に落とす（社内の http 環境でも動くように）。 */
        document.querySelectorAll('.fhs-copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var src = btn.closest('tr').querySelector('.fhs-copy-src');
                var text = src.textContent.trim();
                function done(ok){
                    var label = btn.textContent;
                    btn.textContent = ok ? 'コピーしました' : '選択しました';
                    setTimeout(function(){ btn.textContent = label; }, 1600);
                }
                function fallback(){
                    var r = document.createRange();
                    r.selectNodeContents(src);
                    var sel = window.getSelection();
                    sel.removeAllRanges(); sel.addRange(r);
                    var ok = false;
                    try { ok = document.execCommand('copy'); } catch (e) {}
                    done(ok);
                }
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(function(){ done(true); }, fallback);
                } else fallback();
            });
        });

        var tabs = document.querySelectorAll('#fhs-tabs .nav-tab');
        var panels = document.querySelectorAll('.fhs-tabpanel');
        var save = document.getElementById('fhs-save');
        function showTab(name){
            tabs.forEach(function(x){ x.classList.toggle('nav-tab-active', x.getAttribute('data-tab') === name); });
            panels.forEach(function(p){ p.style.display = (p.getAttribute('data-tab') === name) ? '' : 'none'; });
            if (save) save.style.display = (name === 'usage') ? 'none' : ''; // 使い方タブでは保存ボタンを隠す
        }
        tabs.forEach(function(t){
            t.addEventListener('click', function(e){
                e.preventDefault();
                showTab(t.getAttribute('data-tab'));
            });
        });
        // 説明文の中から別タブへ飛ぶリンク
        document.querySelectorAll('.fhs-gotab').forEach(function(a){
            a.addEventListener('click', function(e){
                e.preventDefault();
                showTab(a.getAttribute('data-tab'));
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    })();
    </script>
<?php }

/**
 * 直近の保存エラーを控える。
 * MySQLのエラー文には値が混ざることがある（例: Duplicate entry '…' for key）ため、
 * 全ページ読み込みで展開される autoload には載せず、長さも切り詰める。
 */
function fhs_record_db_error($msg) {
    $msg = fhs_trim_len((string) $msg, 300) . ' @ ' . current_time('mysql');
    if (get_option('fhs_last_db_error') === false) {
        add_option('fhs_last_db_error', $msg, '', 'no');
    } else {
        update_option('fhs_last_db_error', $msg, 'no');
    }
}

/* =========================================================================
 * 9. 管理画面：申込一覧
 * ======================================================================= */
function fhs_leads_page() {
    // ★メニュー経由でなくても個人情報を出さない。add_submenu_page の権限指定だけに頼らない
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    global $wpdb;
    $table = $wpdb->prefix . 'fudosan_honki_leads';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 200");
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    $export = wp_nonce_url(admin_url('admin-post.php?action=fhs_export_leads'), 'fhs_export_leads');
    echo '<div class="wrap"><h1>訪問査定申込 一覧</h1>';
    if (isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>削除しました。</p></div>';
    $dberr = get_option('fhs_last_db_error');
    if ($dberr) echo '<div class="notice notice-error"><p><strong>直近に保存エラーが発生しました：</strong> ' . esc_html($dberr) . '<br>最新版に更新すると自動修復を試みます。解消されない場合は、この赤いメッセージの文面を共有してください。</p></div>';
    echo '<p>申込件数：' . $total . ' 件（表示は最新200件）　<a class="button button-primary" href="' . esc_url($export) . '">CSVエクスポート（Excel）</a></p>';
    echo '<p class="description">個人情報を含みます。CSVの取り扱いにご注意ください。「営業可」が空欄のお客様には、今回の申し込みへの対応以外の営業メールを送らないでください。</p>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>申込日時</th><th>お名前</th><th>電話</th><th>メール</th><th>種別</th><th>物件住所</th><th>査定方法</th><th>時期</th><th>詳細</th><th>営業可</th><th>操作</th></tr></thead><tbody>';
    if ($rows) foreach ($rows as $r) {
        $plabel = isset($GLOBALS['FHS_PTYPE_LABEL'][$r->ptype]) ? $GLOBALS['FHS_PTYPE_LABEL'][$r->ptype] : $r->ptype;
        $det = isset($r->details) ? (string)$r->details : '';
        $del = wp_nonce_url(admin_url('admin-post.php?action=fhs_delete_lead&id=' . $r->id), 'fhs_delete_lead_' . $r->id);
        $g = function ($v) { return ($v !== null && $v !== '') ? $v : '-'; };
        printf('<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
             . '<td style="white-space:pre-line;font-size:12px;line-height:1.5">%s</td><td>%s</td>'
             . '<td><a href="%s" onclick="return confirm(\'この申し込みを削除しますか？\')" style="color:#b32d2e">削除</a></td></tr>',
            esc_html($r->created_at),
            esc_html($g(isset($r->name) ? $r->name : '')),
            esc_html($g(isset($r->tel) ? $r->tel : '')),
            esc_html($r->email),
            esc_html($plabel),
            esc_html($g(isset($r->address) ? $r->address : '')),
            esc_html($g(isset($r->survey) ? $r->survey : '')),
            esc_html($g(isset($r->timing) ? $r->timing : '')),
            esc_html($det !== '' ? $det : '-'),
            $r->marketing_opt_in ? '○' : '', esc_url($del));
    } else echo '<tr><td colspan="11">まだありません</td></tr>';
    echo '</tbody></table></div>';
}

/* CSVエクスポート（Excel向けShift_JIS） */
add_action('admin_post_fhs_export_leads', 'fhs_export_leads');
function fhs_export_leads() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('fhs_export_leads');
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}fudosan_honki_leads ORDER BY id DESC", ARRAY_A);
    nocache_headers();
    // Excel は Shift_JIS が最も安全。ただし mbstring が無いサーバーでは変換できないため、
    // その場合は UTF-8 + BOM で出す（BOMがないとExcelが文字化けする）
    $can_sjis = function_exists('mb_convert_encoding');
    header('Content-Type: text/csv; charset=' . ($can_sjis ? 'Shift_JIS' : 'UTF-8'));
    header('Content-Disposition: attachment; filename="honki_satei_moushikomi.csv"');
    $out = fopen('php://output', 'w');
    if (!$can_sjis) fwrite($out, "\xEF\xBB\xBF");
    $head = array('ID','申込日時','お名前','フリガナ','電話番号','メール','連絡希望時間帯','ご住所','種別','物件住所','査定方法','売却理由','希望時期','詳細','営業同意');
    $cols = array('id','created_at','name','kana','tel','email','contact_time','owner_address','ptype','address','survey','purpose','timing','details','marketing_opt_in');
    $sjis = function ($s) use ($can_sjis) {
        return $can_sjis ? mb_convert_encoding((string)$s, 'SJIS-win', 'UTF-8') : (string)$s;
    };
    fputcsv($out, array_map($sjis, $head));
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) {
            $v = isset($r[$c]) ? $r[$c] : '';
            if ($c === 'ptype' && isset($GLOBALS['FHS_PTYPE_LABEL'][$v])) $v = $GLOBALS['FHS_PTYPE_LABEL'][$v];
            if ($c === 'marketing_opt_in') $v = $v ? '同意あり' : '同意なし';
            $line[] = $sjis(fhs_csv_safe($v));
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* リード削除（個人情報の削除依頼対応） */
add_action('admin_post_fhs_delete_lead', 'fhs_delete_lead');
function fhs_delete_lead() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id = intval($_GET['id'] ?? 0);
    check_admin_referer('fhs_delete_lead_' . $id);
    if ($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'fudosan_honki_leads', array('id' => $id));
    }
    wp_safe_redirect(admin_url('admin.php?page=fudosan-honki-leads&deleted=1'));
    exit;
}

/* =========================================================================
 * 10. AJAX（admin-ajax 経由。REST無効化環境でも動く）
 * ======================================================================= */
/**
 * 新しいnonceを配る。
 * ページキャッシュが効いていると、HTMLに焼き込まれたnonceが古いまま配られ続け、
 * 24時間で失効した後は全員の送信が失敗する。JS側がこれを呼んで取り直せるようにする。
 */
add_action('wp_ajax_fudosan_honki_nonce', 'fhs_ajax_nonce');
add_action('wp_ajax_nopriv_fudosan_honki_nonce', 'fhs_ajax_nonce');
function fhs_ajax_nonce() {
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    wp_send_json(array('nonce' => wp_create_nonce('fudosan_honki')));
}

add_action('wp_ajax_fudosan_honki', 'fhs_ajax');
add_action('wp_ajax_nopriv_fudosan_honki', 'fhs_ajax');
function fhs_ajax() {
    check_ajax_referer('fudosan_honki', 'nonce');

    // ボット・自動送信を先に弾く（DB・メールに一切触らせない）
    $bot = fhs_bot_errors();
    if ($bot) wp_send_json(array('ok' => false, 'errors' => $bot));

    $lim     = fhs_rl_limits();
    $compact = !empty($_POST['compact']);

    $ptype   = sanitize_text_field($_POST['ptype'] ?? '');
    // 住所は必須の自由入力。カラム長(255)で丸めておく（後段だけで丸めると表示と保存値が食い違う）
    $address = fhs_trim_len(sanitize_text_field($_POST['address'] ?? ''), 255);
    $email   = sanitize_email($_POST['email'] ?? '');
    $note    = fhs_flag('show_note', true) ? sanitize_textarea_field($_POST['note_text'] ?? '') : '';
    $agree   = !empty($_POST['agree']);
    $mkt     = fhs_flag('show_marketing', true) && !empty($_POST['marketing']);

    $errors = array();
    if (!$agree) $errors[] = '個人情報の取扱いへの同意が必要です。';
    if (!is_email($email)) $errors[] = 'メールアドレスの形式が正しくありません。';
    if ($address === '') $errors[] = '物件の住所を入力してください。';
    if (!isset($GLOBALS['FHS_PTYPE_LABEL'][$ptype])) $errors[] = '物件種別を選択してください。';

    /**
     * スキーマ駆動で1グループぶんの入力を取得・検証する。
     * $prefix … POSTキーの接頭辞（customer_ / situation_ / mansion__ など）
     * 戻り値 … array(値の連想配列, 表示用の行配列)
     */
    $collect = function ($group, $flds, $prefix) use (&$errors, $compact) {
        $vals = array(); $lines = array();
        foreach (fhs_visible_fields($group, $flds, $compact) as $fd) {
            $pk = $prefix . $fd['key'];
            if ($fd['type'] === 'check') {
                $val = !empty($_POST[$pk]) ? 'はい' : '';
            } elseif ($fd['type'] === 'textarea') {
                $val = sanitize_textarea_field($_POST[$pk] ?? '');
            } else {
                $val = sanitize_text_field($_POST[$pk] ?? '');
            }
            // 形式エラーを出した項目は、続けて「入力してください」まで出すと
            // 1つの間違いで2行のエラーが並び、何を直せばいいのか分からなくなる
            $bad = false;
            if ($fd['type'] === 'number') {
                $val = trim(fhs_to_hankaku($val));
                $ne = fhs_num_error($fd, $val);
                if ($ne !== '') { $errors[] = $ne; $val = ''; $bad = true; }
            }
            // 選択肢は選択肢外の値を無視（フォーム改ざん対策）
            if ($fd['type'] === 'select' && $val !== '' && !in_array($val, fhs_opt_list($fd['opts']), true)) $val = '';
            if ($fd['type'] === 'tel' && $val !== '') {
                // 全角で入力されることが多い。担当者がそのまま発信できるよう半角に揃えて保存する
                $val = trim(fhs_to_hankaku($val));
                if (!fhs_tel_valid($val)) {
                    $errors[] = '「' . $fd['label'] . '」の形式が正しくありません（例：090-1234-5678）。';
                    $val = ''; $bad = true;
                }
            }
            if ($fd['mode'] === 'req' && $val === '' && !$bad) {
                $errors[] = '「' . $fd['label'] . '」を入力してください。';
            }
            // 保存先カラムの長さに、この時点で丸めておく。
            // 後段だけで丸めると、メール・完了画面には長いままの値が出て保存値と食い違う。
            if (!empty($fd['col']) && !empty($fd['len'])) $val = fhs_trim_len($val, $fd['len']);
            $vals[$fd['key']] = array('fd' => $fd, 'val' => $val);
            if ($val !== '') $lines[] = '■ ' . $fd['label'] . ' : ' . $val;
        }
        return array($vals, $lines);
    };

    list($cust, $cust_lines) = $collect('customer',  fhs_customer_fields(),  'customer_');
    list($situ, $situ_lines) = $collect('situation', fhs_situation_fields(), 'situation_');

    // 物件の種別別項目
    $prop_lines = array(); $prop_vals = array();
    $schema = fhs_property_fields();
    if (isset($schema[$ptype])) {
        list($prop_vals, $prop_lines) = $collect('prop_' . $ptype, $schema[$ptype], $ptype . '__');
    }

    if ($errors) wp_send_json(array('ok' => false, 'errors' => $errors));

    /* スパム判定。自由入力の欄をまとめて見る。
       ★理由は伝えない（どこで弾かれたかをボットに教えないため）。 */
    $free_text = array($address, $note);
    foreach (array($cust, $situ, $prop_vals) as $set) {
        foreach ($set as $item) $free_text[] = $item['val'];
    }
    $cust_name = isset($cust['name']) ? $cust['name']['val'] : '';
    if (fhs_spam_hit($free_text) || fhs_name_not_japanese($cust_name)) {
        wp_send_json(array('ok' => false, 'errors' => array('送信を受け付けられませんでした。')));
    }

    // ★入力内容が正しいときだけ回数を数える。
    //   入力ミスでも数えると、間違えた正規のお客様が先にブロックされてしまう。
    if (!fhs_rate_ok('ip', fhs_client_ip(), $lim['ip_max'], $lim['ip_window'])) {
        wp_send_json(array('ok' => false, 'errors' => array(
            '送信が集中しています。しばらく時間をおいてからお試しください。')));
    }
    // ★本命の防御：同一アドレス宛の連続送信を止める（第三者のアドレスを入れての爆撃対策）
    if (!fhs_rate_ok('email', $email, $lim['email_max'], $lim['email_window'])) {
        wp_send_json(array('ok' => false, 'errors' => array(
            'このメールアドレスでのお申し込みが続いています。24時間ほどおいてからお試しください。')));
    }

    $label = $GLOBALS['FHS_PTYPE_LABEL'][$ptype];

    // メール・画面用のまとまり
    $customer_details = implode("\n", $cust_lines);
    $prop_head = array('■ 物件種別 : ' . $label, '■ 物件住所 : ' . $address);
    $property_details = implode("\n", array_merge($prop_head, $situ_lines, $prop_lines));
    if ($note !== '') $property_details .= "\n■ 備考・ご要望 : " . $note;

    // 「詳細」列に入れるもの＝個別カラムを持たない項目 ＋ 物件の種別別項目 ＋ 備考
    $detail_lines = array();
    foreach (array($cust, $situ) as $set) {
        foreach ($set as $item) {
            if (empty($item['fd']['col']) && $item['val'] !== '') {
                $detail_lines[] = '■ ' . $item['fd']['label'] . ' : ' . $item['val'];
            }
        }
    }
    $detail_lines = array_merge($detail_lines, $prop_lines);
    if ($note !== '') $detail_lines[] = '■ 備考・ご要望 : ' . $note;

    // 完了画面の「ご入力内容」用。お名前・電話・種別・住所は表で別に出すため、ここには入れない
    $confirm_lines = array_merge($situ_lines, $prop_lines);
    if ($note !== '') $confirm_lines[] = '■ 備考・ご要望 : ' . $note;

    // リード保存
    global $wpdb;
    $row = array(
        'created_at' => current_time('mysql'),
        'email'      => $email,
        'ptype'      => $ptype,
        'address'    => fhs_trim_len($address, 255),
        'details'    => implode("\n", $detail_lines),
        'marketing_opt_in' => $mkt ? 1 : 0,
    );
    // 個別カラムを持つ項目（お名前・電話番号など）を、カラム長に丸めて格納
    $lens = fhs_lead_columns();
    foreach (array($cust, $situ) as $set) {
        foreach ($set as $item) {
            $col = isset($item['fd']['col']) ? $item['fd']['col'] : null;
            if ($col) $row[$col] = fhs_trim_len($item['val'], isset($lens[$col]) ? $lens[$col] : 191);
        }
    }

    $ins = $wpdb->insert($wpdb->prefix . 'fudosan_honki_leads', $row);
    if ($ins === false) {
        // 1回目失敗：不足カラムを補ってからもう一度だけ試す
        fhs_record_db_error($wpdb->last_error);
        fhs_ensure_columns();
        $ins = $wpdb->insert($wpdb->prefix . 'fudosan_honki_leads', $row);
    }
    if ($ins === false) {
        // リトライも失敗：受付できていないので「完了」と偽らず、メールも送らずにエラーを返す
        fhs_record_db_error($wpdb->last_error);
        wp_send_json(array('ok' => false, 'errors' => array(
            '申し訳ありません。ただいま受付処理でエラーが発生しました。お手数ですが時間をおいて再度お試しください。',
        )));
    }
    delete_option('fhs_last_db_error');   // 初回・リトライを問わず、保存に成功したらエラー記録を消す

    $ctx = array(
        'name'  => isset($cust['name']) ? $cust['name']['val'] : '',
        'tel'   => isset($cust['tel'])  ? $cust['tel']['val']  : '',
        'email' => $email,
        'ptype_label' => $label,
        'address' => $address,
        'survey'  => isset($situ['survey']) ? $situ['survey']['val'] : '',
        'customer_details' => $customer_details,
        'property_details' => $property_details,
        'marketing' => $mkt,
    );

    // 受付完了メール（お客様へ）
    $site = fhs_opt('site_name', '不動産査定');
    $from = fhs_opt('from_email');
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if ($from) $headers[] = 'From: ' . $site . ' <' . $from . '>';
    $mail_ok = wp_mail($email, fhs_mail_subject(), fhs_mail_body($ctx), $headers);

    // 担当者通知
    if (fhs_flag('notify_on', true)) {
        $notify = fhs_notify_recipients(fhs_opt('notify_email', $from ?: get_option('admin_email')));
        if ($notify) {
            $subj = '【査定申込】' . ($ctx['name'] !== '' ? $ctx['name'] . '様 / ' : '') . $label . ' / ' . $address;
            // 宛先が複数でも、お互いのアドレスが見えないよう1通ずつ送る
            foreach ($notify as $to) {
                wp_mail($to, $subj, fhs_admin_notify_body($ctx), $headers);
            }
        }
    }

    wp_send_json(array(
        'ok' => true, 'mail_ok' => (bool)$mail_ok,
        'email' => $email, 'name' => $ctx['name'], 'tel' => $ctx['tel'],
        'ptype_label' => $label, 'address' => $address,
        'confirm_text' => implode("\n", $confirm_lines),
    ));
}

/* =========================================================================
 * 11. ショートコード [fudosan_honki]
 * ======================================================================= */
add_shortcode('fudosan_honki', 'fhs_shortcode');
/**
 * デザインパターン:
 *   [fudosan_honki]                  標準（全項目・幅100%・枠なし）
 *   [fudosan_honki design="compact"] コンパクト（必須のみ・カード・幅440px）
 *   [fudosan_honki design="card"]    全項目をカード（枠＋影）で表示
 *   [fudosan_honki design="teaser"]   入口フォーム・横長（記事の途中に置く）
 *   [fudosan_honki design="teaser-v"] 入口フォーム・縦（サイドバー）
 *   ※遷移先は設定の「査定ページ」。url 属性で個別に上書きもできる
 *
 * ティザーは2〜3項目だけ聞いて url のページへ送る。入力値は sessionStorage で引き継ぐ
 * （URLのクエリには載せない＝物件住所が履歴やリファラに残らないようにするため）。
 */
function fhs_shortcode($atts = array()) {
    $atts  = fhs_unglue_atts($atts);
    $glued = !empty($atts['fhs_glued']);   // 属性の間のスペースが抜けていた（拾って動かしている）
    unset($atts['fhs_glued']);
    $a = shortcode_atts(array(
        'design' => 'default', 'button' => '',
        // ティザー用
        'url' => '', 'title' => '', 'subtitle' => '', 'note' => '', 'fields' => '',
        'logo' => '', 'badge' => '', 'steps' => '1', 'width' => '', 'tags' => '',
    ), $atts, 'fudosan_honki');
    $design  = in_array($a['design'], array('default', 'compact', 'card', 'teaser', 'teaser-v'), true) ? $a['design'] : 'default';
    $compact = ($design === 'compact');
    $teaser  = ($design === 'teaser' || $design === 'teaser-v');   // 入口フォーム（本フォームへ引き継ぐ）
    $btn     = $a['button'] !== '' ? sanitize_text_field($a['button'])
                                   : ($teaser ? '無料で査定を依頼する' : '査定を申し込む');

    /* ティザーの遷移先。url を書かなければ、設定で指定した査定ページへ送る
       （ショートコードにURLを毎回書かなくて済むように）。 */
    $t_target = '';
    if ($teaser) {
        $t_target = $a['url'] !== '' ? esc_url_raw($a['url']) : fhs_satei_url();
    }
    $t_title  = $a['title']    !== '' ? sanitize_text_field($a['title'])    : '60秒でかんたん入力';
    $t_sub    = $a['subtitle'] !== '' ? sanitize_text_field($a['subtitle']) : '';
    $t_note   = $a['note']     !== '' ? sanitize_text_field($a['note'])     : '';
    /* 注記は行ごとに分けて出す（幅によって変な位置で折り返さないように）。
       note属性では「|」で行を区切れる。 */
    $t_note_lines = $t_note !== ''
        ? array_values(array_filter(array_map('trim', explode('|', $t_note)), 'strlen'))
        : array('入力内容は次のページに引き継がれます。', 'この時点ではまだ送信されません。');
    // アイコンは設定のものを使い、ショートコードで指定があればそちらを優先する
    $t_logo   = $a['logo']     !== '' ? esc_url_raw($a['logo'])             : fhs_opt('logo_url', '');
    /* バッジとタグは「設定画面で決めて全ティザーに反映」が基本。
       ショートコードで指定があればそのフォームだけ上書き。
       どちらも空なら表示しない（既定の文言は持たせない）。 */
    $t_badge  = isset($atts['badge']) ? sanitize_text_field($a['badge']) : fhs_opt('teaser_badge', '');
    $t_tags   = fhs_split_tags(isset($atts['tags']) ? $a['tags'] : fhs_opt('teaser_tags', ''));
    $t_steps  = ($a['steps'] !== '0' && $a['steps'] !== '');
    $t_fields = $teaser ? fhs_parse_teaser_fields($a['fields']) : array();

    /* ティザーの横幅。数字だけなら px として扱う（width="500"）。
       width="100%" で本文の幅いっぱいにもできる。既定は横長500px / 縦440px。 */
    $t_width = '';
    if ($teaser) {
        $w = trim((string)$a['width']);
        if ($w !== '' && preg_match('/^\d+$/', $w)) $w .= 'px';
        // 横長は入力欄を横に並べるため、既定は本文の幅いっぱい。縦は440px
        if (!preg_match('/^\d+(px|%|em|rem|vw)$/', $w)) $w = ($design === 'teaser-v') ? '440px' : '100%';
        $t_width = $w;
    }

    $c_brand    = fhs_opt('color_brand', '#1f6feb');
    $c_btn_text = fhs_opt('color_btn_text', '#ffffff');
    /* 申し込みボタンの色。未指定なら暖色（オレンジ）。
       一括査定サイトはどこも暖色（イエウールのCTAは #b02d2a の赤）で、
       紺・白が基調のフォームでは暖色が最も浮く。
       #e65100 は白文字とのコントラストが 3.79:1 あり、大きな太字なら読みやすさの基準を満たす
       （#ff9900 のような明るいオレンジは 2.14:1 しかなく、白文字が沈む）。 */
    $c_btn_bg   = fhs_opt('color_btn_bg', '') ?: '#e65100';
    $c_title    = fhs_opt('color_title', '#1f6feb');
    $c_badge    = fhs_opt('color_badge', '#ff5a36');
    $c_brand_rgb = fhs_hex_to_rgb($c_brand);

    $nonce   = wp_create_nonce('fudosan_honki');
    $ajax    = admin_url('admin-ajax.php');
    $privacy = fhs_opt('privacy_url');
    $terms   = fhs_opt('terms_url');
    /* 1ページに複数置かれる前提。uniqid() は同一リクエスト内で同じ値を返すことがあるため、
       連番を足して確実に一意にする（idが重なると label が別のフォームの入力欄を指してしまう）。
       CSSとJSは何個あっても最初の1回だけ出す。 */
    static $seq = 0, $assets_done = false;
    $uid     = 'fhs-' . uniqid() . '-' . (++$seq);
    $need_assets = !$assets_done;
    $assets_done = true;

    // compact では必須項目だけに絞る（メインビジュアル横などに収めるため）
    $cust_fields = fhs_visible_fields('customer',  fhs_customer_fields(),  $compact);
    $situ_fields = fhs_visible_fields('situation', fhs_situation_fields(), $compact);
    $show_note   = fhs_flag('show_note', true) && !$compact;
    $show_mkt    = fhs_flag('show_marketing', true) && !$compact;

    /* ステップ表示。一画面に20項目並ぶと身構えられるので、
       「物件 → ご状況 → ご連絡先」の順に小分けにする（個人情報は必ず最後）。
       compact とティザーは元々短いので分けない。 */
    $step2       = ($situ_fields || $show_note);            // 中身が無ければ2ステップ目は作らない
    $stepped     = !$teaser && !$compact && fhs_flag('step_form', true);
    $step_titles = $step2 ? array('物件の情報', '売却のご状況', 'ご連絡先')
                          : array('物件の情報', 'ご連絡先');

    // 第三者提供の有無で、利用目的と同意文の書き方を変える（個情法27条）
    $tp      = fhs_flag('third_party', false);
    $tp_name = fhs_opt('third_party_name', '当社が提携する不動産会社');
    $tp_url  = fhs_opt('third_party_url', '');
    // 提供先の説明。ページがあればリンクにして、渡す相手を確認できるようにする
    $tp_label = $tp_url
        ? '<a href="' . esc_url($tp_url) . '" target="_blank" rel="noopener">' . esc_html($tp_name) . '</a>'
        : esc_html($tp_name);
    $op_name = fhs_opt('operator_name', '当社');

    $ptype_options = '<option value="">選択してください</option>';
    foreach ($GLOBALS['FHS_PTYPE_LABEL'] as $k => $v) {
        $ptype_options .= '<option value="' . esc_attr($k) . '">' . esc_html($v) . '</option>';
    }

    $agree_label = 'プライバシーポリシーおよび免責事項に同意します（必須）';
    if ($privacy || $terms) {
        $p = $privacy ? '<a href="' . esc_url($privacy) . '" target="_blank" rel="noopener">プライバシーポリシー</a>' : 'プライバシーポリシー';
        $t = $terms ? '<a href="' . esc_url($terms) . '" target="_blank" rel="noopener">免責事項</a>' : '免責事項';
        $agree_label = $p . 'および' . $t . 'に同意します（必須）';
    }
    if ($tp) {
        $agree_label = '上記の個人情報の取り扱い（' . $tp_label . 'への提供を含む）に同意し、' . $agree_label;
    }

    /** 物件種別のタイル選択（1タップで選べるようにする。セレクトより離脱が少ない） */
    $render_ptype_tiles = function ($uid, $name = 'ptype') {
        ob_start(); ?>
        <div class="fhs-tiles" role="group">
<?php foreach ($GLOBALS['FHS_PTYPE_LABEL'] as $k => $v):
        $short = ($k === 'house') ? '一戸建て' : (($k === 'mansion') ? 'マンション' : '土地');
        $tid = $uid . '-tile-' . $k; ?>
          <input type="radio" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($tid); ?>" value="<?php echo esc_attr($k); ?>" class="fhs-tile-input">
          <label class="fhs-tile" for="<?php echo esc_attr($tid); ?>"><?php echo esc_html($short); ?></label>
<?php endforeach; ?>
        </div>
<?php return ob_get_clean();
    };

    /** ティザー1項目ぶんのHTML（STEP表記つき） */
    $render_teaser_field = function ($key, $fd, $i, $uid) use ($render_ptype_tiles, $t_steps) {
        $nm = fhs_teaser_form_name($key);
        $id = $uid . '-t-' . $key;
        ob_start(); ?>
        <div class="fhs-tfield fhs-tfield-<?php echo esc_attr($key); ?>">
          <label<?php echo $fd['type'] === 'ptype' ? '' : ' for="' . esc_attr($id) . '"'; ?>>
<?php if ($t_steps): ?><span class="fhs-step">STEP <?php echo (int)$i; ?></span><?php endif; ?>
            <?php echo esc_html($fd['label']); ?>
          </label>
<?php if ($fd['type'] === 'ptype'): ?>
          <?php echo $render_ptype_tiles($uid, 'ptype'); ?>
<?php elseif ($fd['type'] === 'select'): ?>
          <select name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" class="fhs-typed">
            <option value="">選択してください</option>
<?php foreach (fhs_opt_list($fd['opts']) as $o): ?>
            <option value="<?php echo esc_attr($o); ?>"><?php echo esc_html($o); ?></option>
<?php endforeach; ?>
          </select>
<?php else: ?>
          <input type="text" name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" class="fhs-typed" placeholder="<?php echo esc_attr($key === 'address' ? fhs_address_placeholder() : (isset($fd['ph']) ? $fd['ph'] : '')); ?>">
<?php endif; ?>
        </div>
<?php return ob_get_clean();
    };

    /** 1項目ぶんのHTML（ラベル＋入力欄）。$prefix はPOSTキーの接頭辞 */
    $render_field = function ($fd, $prefix, $uid) {
        $nm   = $prefix . $fd['key'];
        $req  = ($fd['mode'] === 'req');
        $full = ($fd['type'] === 'textarea');
        $id   = $uid . '-' . $nm;
        ob_start(); ?>
        <div class="fhs-field<?php echo $full ? ' fhs-full' : ''; ?>">
<?php if ($fd['type'] === 'check'): ?>
          <div class="fhs-check">
            <input type="checkbox" name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" value="1">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($fd['chk']); ?></label>
          </div>
<?php else: ?>
          <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($fd['label']); ?><?php echo $req ? '<span class="fhs-req">必須</span>' : '<span class="fhs-opt">任意</span>'; ?></label>
<?php if ($fd['type'] === 'select'): ?>
          <select name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" class="fhs-typed" data-req="<?php echo $req ? '1' : ''; ?>">
            <option value="">選択してください</option>
<?php foreach (fhs_opt_list($fd['opts']) as $o): ?>
            <option value="<?php echo esc_attr($o); ?>"><?php echo esc_html($o); ?></option>
<?php endforeach; ?>
          </select>
<?php elseif ($fd['type'] === 'textarea'): ?>
          <textarea name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" class="fhs-typed" data-req="<?php echo $req ? '1' : ''; ?>" rows="2" placeholder="<?php echo esc_attr(isset($fd['ph']) ? $fd['ph'] : ''); ?>"></textarea>
<?php else:
        /* 数値は type="number" にしない。全角数字を打つとブラウザが値を空にしてしまい、
           「入力したのに必須エラーが出る」状態になるため。inputmode でキーパッドだけ出す。 */
        $type = 'text'; $extra = '';
        if ($fd['type'] === 'number') { $extra = ' inputmode="decimal"'; }
        elseif ($fd['type'] === 'tel') { $type = 'tel'; $extra = ' inputmode="tel" autocomplete="tel"'; }
        elseif ($fd['key'] === 'name') { $extra = ' autocomplete="name"'; }
?>
          <input type="<?php echo $type; ?>"<?php echo $extra; ?> name="<?php echo esc_attr($nm); ?>" id="<?php echo esc_attr($id); ?>" class="fhs-typed" data-req="<?php echo $req ? '1' : ''; ?>" placeholder="<?php echo esc_attr(isset($fd['ph']) ? $fd['ph'] : ''); ?>">
<?php endif; ?>
<?php endif; ?>
        </div>
<?php return ob_get_clean();
    };

    ob_start(); ?>
<div class="fhs-wrap fhs-design-<?php echo esc_attr($design); ?>" id="<?php echo esc_attr($uid); ?>"
  data-fhs-teaser="<?php echo $teaser ? '1' : ''; ?>" data-fhs-target="<?php echo esc_attr($t_target); ?>"<?php
  echo $t_width ? ' style="max-width:' . esc_attr($t_width) . '"' : ''; ?>>
<?php if ($need_assets): ?>
  <style>
    .fhs-wrap{--fhs-brand:<?php echo esc_attr($c_brand); ?>;--fhs-brand-rgb:<?php echo esc_attr($c_brand_rgb); ?>;--fhs-btn-text:<?php echo esc_attr($c_btn_text); ?>;--fhs-btn-bg:<?php echo esc_attr($c_btn_bg); ?>;--fhs-title:<?php echo esc_attr($c_title); ?>;--fhs-badge-bg:<?php echo esc_attr($c_badge); ?>;--fhs-ink:#1a1f36;--fhs-muted:#6b7280;--fhs-line:#e5e7eb;width:100%;max-width:none;margin:0;color:var(--fhs-ink);font-family:inherit;line-height:1.75;font-size:17px}
    /* テーマ側が box-sizing を当てているかどうかで、余白ぶん高さ・幅がずれる。
       このフォームの中だけは border-box に固定して、どのテーマでも同じ見た目にする。 */
    .fhs-wrap,.fhs-wrap *{box-sizing:border-box}
    .fhs-card{background:transparent;border:0;border-radius:0;padding:0 0 28px}
    .fhs-wrap{overflow-wrap:anywhere}   /* 長いメールアドレスや住所で横スクロールさせない */
    .fhs-card > :last-child{margin-bottom:0}
    .fhs-wrap label{display:block;font-weight:700;margin:18px 0 7px;font-size:17px;color:#374151;letter-spacing:.01em}
    .fhs-req,.fhs-opt{font-size:11px;font-weight:700;border-radius:4px;padding:4px 7px;line-height:1;margin-left:8px;display:inline-flex;align-items:center;vertical-align:middle;letter-spacing:.02em;white-space:nowrap;flex:0 0 auto}
    .fhs-req{background:var(--fhs-badge-bg);color:#fff}
    .fhs-opt{background:#eef1f5;color:#6b7280}
    .fhs-req.fhs-done{background:var(--fhs-brand);color:#fff;border-radius:50%;width:20px;height:20px;padding:0;font-size:12px;justify-content:center}
    .fhs-lead{background:#f6f8fa;border:1px solid var(--fhs-line);border-radius:10px;padding:14px 16px;font-size:16px;color:#374151;margin-bottom:20px;white-space:pre-line}
    .fhs-section{display:flex;align-items:center;font-weight:800;font-size:21px;color:var(--fhs-ink);margin:34px 0 10px;padding-left:12px;border-left:5px solid var(--fhs-brand);line-height:1.45;letter-spacing:.01em}
    .fhs-form > .fhs-section:first-child{margin-top:0}
    /* ★チェックボックス・ラジオは対象外にする。padding や角丸が乗ると、
       テーマが自前で描いている環境で丸く潰れるなど表示が壊れる。 */
    .fhs-wrap input:not([type=checkbox]):not([type=radio]),.fhs-wrap select,.fhs-wrap textarea{width:100%;padding:14px 15px;border:1px solid #cbd5e1;border-radius:9px;font-size:18px;background:#fff;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
    .fhs-wrap input:not([type=checkbox]):not([type=radio]):focus,.fhs-wrap select:focus,.fhs-wrap textarea:focus{outline:none;border-color:var(--fhs-brand);box-shadow:0 0 0 3px rgba(var(--fhs-brand-rgb),.15)}
    /* テーマ側の appearance:none などを打ち消して、ブラウザ標準の四角いチェックに戻す */
    .fhs-wrap input[type=checkbox]{-webkit-appearance:checkbox;appearance:auto;width:auto;min-width:0;height:auto;padding:0;margin:0;border:0;border-radius:0;background:none;box-shadow:none}
    .fhs-wrap input[type=radio]{-webkit-appearance:radio;appearance:auto;width:auto;min-width:0;height:auto;padding:0;margin:0;border:0;border-radius:0;background:none;box-shadow:none}
    /* 項目は2カラムでコンパクトに（textarea・チェックは全幅） */
    .fhs-group{display:grid;grid-template-columns:1fr 1fr;gap:0 18px;align-items:start}
    .fhs-field{min-width:0}
    .fhs-field.fhs-full{grid-column:1/-1}
    .fhs-field > label:first-child{margin-top:16px}
    @media(max-width:560px){.fhs-group{grid-template-columns:1fr}}
    .fhs-hint{color:var(--fhs-muted);font-size:14px;margin-top:5px;line-height:1.7}
    .fhs-check{display:flex;gap:9px;align-items:flex-start;margin-top:14px}
    .fhs-wrap .fhs-check input[type=checkbox]{margin-top:5px;transform:scale(1.25);flex:0 0 auto}
    .fhs-check label{margin:0;font-weight:400;font-size:16px}
    .fhs-wrap button{margin-top:24px;width:100%;background:var(--fhs-btn-bg);color:var(--fhs-btn-text);border:0;border-radius:10px;padding:18px;font-size:20px;font-weight:700;cursor:pointer}
    /* ステップ表示。★.fhs-step はティザーの「STEP 1」バッジが使っているので別名にすること */
    .fhs-wrap .fhs-formstep{display:block}
    .fhs-steps{margin-bottom:22px}
    .fhs-stepbar{display:flex;gap:6px}
    .fhs-stepdot{flex:1;height:6px;border-radius:3px;background:#e5e7eb;transition:background .25s}
    .fhs-stepdot.is-on{background:var(--fhs-brand)}
    .fhs-stepnow{margin-top:9px;font-size:14px;font-weight:700;color:var(--fhs-muted)}
    .fhs-stepnow b{color:var(--fhs-brand)}
    .fhs-nav{display:flex;gap:12px;align-items:stretch}
    .fhs-nav button{margin-top:24px}
    .fhs-wrap .fhs-back{flex:0 0 34%;background:#fff;color:var(--fhs-muted);border:1px solid #cbd5e1;font-size:17px}
    .fhs-wrap .fhs-back:hover{filter:none;background:#f6f8fa}
    @media(max-width:480px){.fhs-wrap .fhs-back{flex:0 0 38%;font-size:15px;padding:14px 8px}}
    .fhs-wrap button:hover{filter:brightness(.93)}
    .fhs-wrap button:disabled{opacity:.6;cursor:wait;filter:none}
    /* ハニーポット：display:none だと一部のボットに読まれるため画面外へ逃がす */
    .fhs-hp{position:absolute!important;left:-9999px!important;top:auto;width:1px;height:1px;overflow:hidden}
    .fhs-privacy-note{background:#f6f8fa;border:1px solid var(--fhs-line);border-radius:9px;padding:13px 15px;font-size:14px;color:#4b5563;line-height:1.75;margin-top:16px}
    .fhs-operator{margin-top:18px;padding-top:16px;border-top:1px solid var(--fhs-line);font-size:14px;color:#4b5563;line-height:1.9}
    .fhs-operator-t{font-weight:700;color:var(--fhs-ink);margin-bottom:4px;font-size:15px}
    .fhs-operator span{display:inline-block;min-width:6.5em;padding-right:10px;color:var(--fhs-muted)}
    .fhs-operator a{color:var(--fhs-brand)}
    .fhs-operator-name{font-weight:700;color:var(--fhs-ink);font-size:15px;margin-bottom:2px}
    /* 会社の画像は正方形に切り出して丸く見せる（縦横比が違っても中央でトリミング） */
    .fhs-operator-body{display:flex;align-items:center;gap:16px}
    .fhs-wrap .fhs-opimg{width:72px;height:72px;flex:0 0 72px;border-radius:50%;object-fit:cover;object-position:center;background:#f6f8fa;border:1px solid var(--fhs-line);padding:0}
    .fhs-operator-info{min-width:0}
    @media(max-width:480px){.fhs-wrap .fhs-opimg{width:56px;height:56px;flex:0 0 56px}.fhs-operator-body{gap:12px}}
    .fhs-err{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;padding:10px 12px;border-radius:9px;margin-bottom:10px;font-size:16px}
    .fhs-spec{width:100%;border-collapse:collapse;margin:16px 0;font-size:17px}
    .fhs-spec th,.fhs-spec td{border-bottom:1px solid var(--fhs-line);padding:12px 10px;text-align:left}
    .fhs-spec th{color:var(--fhs-muted);font-weight:600;width:38%}
    .fhs-ok{color:#0a7d33;font-weight:600;font-size:16px;margin-top:16px}
    .fhs-next-note{background:#eef6ff;border:1px solid #cfe3ff;border-radius:10px;padding:14px 16px;font-size:15px;color:#1c3d5a;margin-top:16px;line-height:1.8}

    /* デザイン: compact */
    .fhs-design-compact{max-width:440px}
    .fhs-design-compact .fhs-card{background:#fff;border:1px solid var(--fhs-line);border-radius:14px;padding:20px 18px;box-shadow:0 8px 28px rgba(16,24,40,.10)}
    .fhs-design-compact label{font-size:16px;margin:12px 0 5px}
    .fhs-design-compact input,.fhs-design-compact select,.fhs-design-compact textarea{padding:11px 12px;font-size:16px}
    .fhs-design-compact button{margin-top:16px;padding:14px;font-size:17px}
    .fhs-design-compact .fhs-form .fhs-hint{display:none}
    .fhs-design-compact .fhs-group{grid-template-columns:1fr} /* 幅が狭いので1カラム */
    .fhs-design-compact .fhs-section{display:none}
    .fhs-design-compact .fhs-check label{font-size:14px}
    .fhs-design-compact .fhs-lead{font-size:14px;padding:10px 12px}
    .fhs-design-compact .fhs-spec{font-size:15px}
    .fhs-design-compact .fhs-spec th,.fhs-design-compact .fhs-spec td{padding:9px 8px}

    /* 次に入力すべき欄をハイライト */
    .fhs-wrap select.fhs-next,.fhs-wrap input.fhs-next,.fhs-wrap textarea.fhs-next{border-color:rgba(var(--fhs-brand-rgb),.55);animation:fhsPulse 1.5s ease-in-out infinite}
    @keyframes fhsPulse{
      0%,100%{box-shadow:0 0 0 3px rgba(var(--fhs-brand-rgb),.16)}
      50%{box-shadow:0 0 0 7px rgba(var(--fhs-brand-rgb),.28)}
    }
    @media (prefers-reduced-motion:reduce){
      .fhs-wrap select.fhs-next,.fhs-wrap input.fhs-next,.fhs-wrap textarea.fhs-next{animation:none;box-shadow:0 0 0 3px rgba(var(--fhs-brand-rgb),.20)}
    }

    /* デザイン: card */
    .fhs-design-card .fhs-card{background:#fff;border:1px solid var(--fhs-line);border-radius:14px;padding:24px 22px;box-shadow:0 4px 18px rgba(16,24,40,.06)}
<?php // ここから下はティザー用。以降のスタイルも含めて、このブロックはページに1回だけ出力される ?>

    /* ============ ティザー（記事内などに置く入口フォーム） ============ */
    .fhs-design-teaser .fhs-card,.fhs-design-teaser-v .fhs-card{background:#fff;border:1px solid var(--fhs-line);border-radius:14px;padding:22px 22px 24px;box-shadow:0 8px 28px rgba(16,24,40,.10)}
    /* 記事の中に置くので中央寄せ。★幅(max-width)はラッパのインラインstyleで個別に指定する。
       ここに書くと、同じページに横長と縦を両方置いたとき、後から出力されたCSSが
       両方に効いてしまい、片方の幅が意図せず変わる。 */
    .fhs-design-teaser,.fhs-design-teaser-v{margin-left:auto;margin-right:auto}
    .fhs-design-teaser .fhs-hint,.fhs-design-teaser-v .fhs-hint{display:none}
    /* 見出しまわりは横長・縦で同じ組み方にする。
       1行目＝メリットのタグ、2行目＝バッジ＋アイコン＋見出し。
       HTMLの順（タグ→バッジ→見出し→小見出し）がそのまま表示順になるので order は使わない。 */
    .fhs-design-teaser .fhs-ttexts,.fhs-design-teaser-v .fhs-ttexts{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 12px}
    .fhs-design-teaser .fhs-ttags,.fhs-design-teaser-v .fhs-ttags{flex:1 1 100%;margin-top:0;margin-bottom:2px}
    .fhs-design-teaser .fhs-tbadge-row,.fhs-design-teaser-v .fhs-tbadge-row{margin-bottom:0}
    .fhs-design-teaser .fhs-tsub,.fhs-design-teaser-v .fhs-tsub{flex:1 1 100%;margin-top:0}
    /* 横長は横幅が余るので、見出しを左・タグを右に振り分けて1行に収める。
       （HTMLの順はタグが先なので order で位置を入れ替える） */
    .fhs-design-teaser .fhs-thead{text-align:left}
    .fhs-design-teaser .fhs-ttexts{justify-content:flex-start}
    .fhs-design-teaser .fhs-tbadge-row{order:1}
    .fhs-design-teaser .fhs-ttitle{order:2}
    .fhs-design-teaser .fhs-ttags{order:3;flex:0 1 auto;margin-left:auto;margin-bottom:0}
    .fhs-design-teaser .fhs-tsub{order:4}
    .fhs-thead{text-align:center;padding-bottom:16px;margin-bottom:4px;border-bottom:1px solid var(--fhs-line)}
    .fhs-ttitle{font-size:22px;font-weight:800;color:var(--fhs-title);line-height:1.4;letter-spacing:.01em}
    .fhs-tsub{font-size:14px;color:var(--fhs-muted);margin-top:5px;line-height:1.6}
    /* 見出しの左に置くアイコン（会社ロゴ・ファビコンなど）。高さは見出しの文字に合わせる */
    /* 正方形なら高さ基準で収まり、横長ロゴでも読める程度の幅を許す（object-fitで縦横比は保つ） */
    .fhs-wrap .fhs-ticon{height:1.45em;width:auto;max-width:4.5em;vertical-align:-.22em;margin-right:.4em;display:inline-block;object-fit:contain}
    /* STEPバッジ */
    .fhs-step{display:inline-block;background:#3a4a5e;color:#fff;font-size:11px;font-weight:700;letter-spacing:.04em;border-radius:4px;padding:4px 8px;margin-right:9px;vertical-align:middle;line-height:1}
    .fhs-wrap .fhs-tfield > label{display:block;font-weight:700;font-size:16px;color:#374151;margin:16px 0 8px}
    /* 物件種別のタイル選択（1タップ） */
    /* ★セレクタは .fhs-wrap 付きで書くこと。上の .fhs-wrap input / .fhs-wrap label より
       詳細度が低いと width:100% や display:block に負け、隠したはずのラジオが
       画面幅いっぱいに広がって横スクロールが出る。 */
    /* タイルは3つ横並びが基本。入らなくなったら自動で2つ・1つに落ちる */
    .fhs-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(92px,1fr));gap:10px}
    .fhs-wrap .fhs-tile-input,.fhs-wrap input[type=radio].fhs-tile-input{position:absolute;opacity:0;width:1px;height:1px;padding:0;border:0;pointer-events:none;appearance:none;-webkit-appearance:none}
    .fhs-wrap .fhs-tile{display:flex;align-items:center;justify-content:center;text-align:center;background:#fff;border:2px solid #cbd5e1;border-radius:10px;padding:15px 8px;font-weight:700;font-size:16px;color:#374151;cursor:pointer;transition:border-color .15s,background .15s,color .15s;margin:0;line-height:1.3;min-height:56px}
    .fhs-wrap .fhs-tile:hover{border-color:rgba(var(--fhs-brand-rgb),.6)}
    .fhs-wrap .fhs-tile-input:checked + .fhs-tile{border-color:var(--fhs-brand);background:rgba(var(--fhs-brand-rgb),.08);color:var(--fhs-brand)}
    .fhs-wrap .fhs-tile-input:focus-visible + .fhs-tile{box-shadow:0 0 0 3px rgba(var(--fhs-brand-rgb),.25)}
    /* ===== 横長：入力欄を横一列に並べる =====
       ★ビューポート幅のメディアクエリではなく flex-wrap で折り返す。
         幅はショートコードの width で決まるので、画面が広くてもカードが狭いことがある。
         各項目に「これ以上は縮まない幅」を持たせ、入らなくなったら自動で下に落とす。 */
    .fhs-design-teaser .fhs-trow{display:flex;flex-wrap:wrap;gap:14px 22px;align-items:flex-start}
    .fhs-design-teaser .fhs-tfield{flex:1 1 240px;min-width:0}
    .fhs-design-teaser .fhs-tfield-ptype{flex:1.35 1 330px}   /* タイル3つぶん確保する */
    .fhs-design-teaser .fhs-tfield > label{margin-top:0}
    .fhs-design-teaser .fhs-tcta{flex:1 1 100%;display:flex;flex-direction:column;align-items:center}
    .fhs-design-teaser .fhs-tcta button{max-width:520px;margin-top:6px}
    /* 横長は見出しも1行にまとめる（バッジ＋見出しを横並び。狭ければ折り返す） */
    .fhs-design-teaser .fhs-ttexts{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px 14px}
    /* 見出しの上に置くバッジ（無料・秘密厳守など） */
    .fhs-tbadge-row{margin-bottom:9px;line-height:1}
    /* 見出しの横に並べるメリットのタグ */
    .fhs-ttags{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:9px}
    /* タグは四角・線なしの塗りだけ。バッジ（丸い塗り）と形を変えて、並んでもくどくならないようにする */
    .fhs-ttag{font-size:12px;font-weight:700;color:var(--fhs-brand);background:rgba(var(--fhs-brand-rgb),.10);border:0;border-radius:5px;padding:6px 11px;line-height:1.3;white-space:nowrap}
    .fhs-tbadge{display:inline-block;background:var(--fhs-badge-bg);border:1px solid var(--fhs-badge-bg);color:#fff;font-size:12px;font-weight:800;border-radius:999px;padding:5px 14px;line-height:1}
    .fhs-tnote{color:var(--fhs-muted);font-size:12px;margin-top:12px;line-height:1.8;text-align:center;text-wrap:pretty}
    .fhs-tnote span{display:block}
    .fhs-admin-warn{background:#fdecea;border:1px solid #f5c6cb;color:#c0392b;padding:12px 14px;border-radius:9px;font-size:14px;margin-bottom:12px;line-height:1.8}
    /* 自動で拾えている場合は「エラー」ではないので、色を落とす */
    .fhs-admin-warn.fhs-admin-note{background:#fff8e6;border-color:#f0e0a8;color:#6b5a12}
    .fhs-admin-warn code{background:rgba(0,0,0,.06);padding:2px 6px;border-radius:4px;font-size:13px}
    @media(max-width:560px){
      /* 幅が無いときは横に振り分けず、中央に積む */
      .fhs-design-teaser .fhs-thead{text-align:center}
      .fhs-design-teaser .fhs-ttexts{justify-content:center}
      .fhs-design-teaser .fhs-ttags{order:0;flex:1 1 100%;margin-left:0;margin-bottom:2px;justify-content:center}
      .fhs-tiles{grid-template-columns:1fr;gap:8px}
      .fhs-wrap .fhs-tile{min-height:0;padding:13px 8px}
      .fhs-ttitle{font-size:19px}
      .fhs-design-teaser .fhs-card,.fhs-design-teaser-v .fhs-card{padding:18px 16px 20px}
      .fhs-wrap .fhs-ticon{max-width:3.4em}
    }

    /* 引き継ぎ後の「続きはこちらから」バナー */
    .fhs-resume{display:flex;align-items:baseline;flex-wrap:wrap;gap:4px 10px;background:rgba(var(--fhs-brand-rgb),.07);border:1px solid rgba(var(--fhs-brand-rgb),.22);border-left:4px solid var(--fhs-brand);border-radius:8px;padding:12px 14px;margin:26px 0 6px;font-size:15px}
    .fhs-resume b{color:var(--fhs-brand);font-weight:800}
    .fhs-resume span{color:var(--fhs-muted);font-size:14px}
  </style>
<?php endif; /* $need_assets */ ?>

  <div class="fhs-card fhs-form-card">
<?php if ($glued && current_user_can('manage_options')): ?>
    <div class="fhs-admin-warn fhs-admin-note"><strong>【この行は管理者にだけ見えています】ショートコードの属性の間に半角スペースが足りません。</strong><br>
      いまは自動で読み取って表示していますが、<code>"</code> と次の属性の間に<strong>半角スペース</strong>を入れてください。<br>
      × <code>url="/○○○/satei/"width="640"</code>　→　○ <code>url="/○○○/satei/" width="640"</code></div>
<?php endif; ?>
<?php if ($teaser): /* ===== 入口フォーム（ティザー）===== */ ?>
<?php if (!$t_target && current_user_can('manage_options')): ?>
    <div class="fhs-admin-warn"><strong>【この行は管理者にだけ見えています】</strong><br>
      ティザーの遷移先が決まっていません。次のどちらかで設定してください。<br>
      ① <a href="<?php echo esc_url(admin_url('admin.php?page=fudosan-honki')); ?>">設定 → 基本設定 → 査定ページ</a> で、<code>[fudosan_honki]</code> を貼ったページを選ぶ（<strong>おすすめ</strong>。以後どのティザーにも効きます）<br>
      ② このショートコードに <code>url="https://…/○○○/satei/"</code> を追加する</div>
<?php endif; ?>
    <div class="fhs-errors"></div>
    <form class="fhs-form">
      <div class="fhs-thead">
        <div class="fhs-ttexts">
<?php /* タグを先に置く。縦ではこの順（タグ→バッジ→見出し）がそのまま見た目の順になり、
         読み上げの順序とも一致する。横長は1行に並べるので、CSSのorderで位置だけ入れ替える。 */ ?>
<?php if ($t_tags): ?>
          <div class="fhs-ttags">
<?php foreach ($t_tags as $tag): ?>
            <span class="fhs-ttag"><?php echo esc_html($tag); ?></span>
<?php endforeach; ?>
          </div>
<?php endif; ?>
<?php if ($t_badge !== ''): ?>
          <div class="fhs-tbadge-row"><span class="fhs-tbadge"><?php echo esc_html($t_badge); ?></span></div>
<?php endif; ?>
          <div class="fhs-ttitle"><?php if ($t_logo): ?><img class="fhs-ticon" src="<?php echo esc_url($t_logo); ?>" alt="<?php echo esc_attr(fhs_opt('operator_name', '')); ?>"><?php endif; ?><?php echo esc_html($t_title); ?></div>
<?php if ($t_sub !== ''): ?>
          <div class="fhs-tsub"><?php echo esc_html($t_sub); ?></div>
<?php endif; ?>
        </div>
      </div>
      <div class="fhs-trow">
<?php $t_reg = fhs_teaser_fields(); $ti = 1;
      foreach ($t_fields as $tk) { echo $render_teaser_field($tk, $t_reg[$tk], $ti++, $uid); } ?>
        <div class="fhs-tcta">
          <button class="fhs-submit" type="submit"><?php echo esc_html($btn); ?></button>
        </div>
      </div>
      <?php /* 注記は文ごとに行を分ける。1つの段落にすると幅によって
               「…送信されませ／ん。」のように中途半端な位置で折り返してしまう。
               note属性では | で行を区切れる。 */ ?>
      <div class="fhs-tnote">
<?php foreach ($t_note_lines as $ln): ?>
        <span><?php echo esc_html($ln); ?></span>
<?php endforeach; ?>
      </div>
      <?php /* ティザーには免責を置かない。ここでは価格を一切示さず、申し込みも受け付けない
               （次のページへ移るだけ）ため。免責が要るのは価格を示す場面と申し込みを受け付ける場面で、
               それは遷移先の本フォーム・完了画面・自動返信メールに必ず表示される。 */ ?>
    </form>
<?php else: /* ===== 通常のフォーム ===== */ ?>
<?php $lead = fhs_opt('lead_text'); if ($lead !== ''): ?>
    <div class="fhs-lead"><?php echo esc_html($lead); ?></div>
<?php endif; ?>
    <div class="fhs-errors"></div>
    <form class="fhs-form">
      <?php /* ボット対策。人には見えず、自動入力ツールだけが埋める欄 */ ?>
      <div class="fhs-hp" aria-hidden="true">
        <label for="<?php echo esc_attr($uid . '-website'); ?>">ウェブサイト（入力しないでください）</label>
        <input type="text" name="fhs_website" id="<?php echo esc_attr($uid . '-website'); ?>" tabindex="-1" autocomplete="off">
      </div>

<?php if ($stepped): /* 進み具合。ゴールが見えると最後まで書いてもらいやすい */ ?>
      <div class="fhs-steps">
        <div class="fhs-stepbar">
<?php for ($i = 1; $i <= count($step_titles); $i++): ?>
          <span class="fhs-stepdot" data-step="<?php echo $i; ?>"></span>
<?php endfor; ?>
        </div>
        <div class="fhs-stepnow"></div>
      </div>
<?php endif; ?>

<?php if ($stepped): ?><div class="fhs-formstep" data-step="1"><?php endif; ?>
      <div class="fhs-section">物件の情報</div>
      <label for="<?php echo esc_attr($uid . '-ptype'); ?>">物件種別<span class="fhs-req">必須</span></label>
      <select name="ptype" id="<?php echo esc_attr($uid . '-ptype'); ?>" required><?php echo $ptype_options; ?></select>
      <div class="fhs-hint">選ぶと、その種別の入力項目が表示されます</div>

      <label for="<?php echo esc_attr($uid . '-address'); ?>">物件の住所<span class="fhs-req">必須</span></label>
      <input type="text" name="address" id="<?php echo esc_attr($uid . '-address'); ?>" placeholder="<?php echo esc_attr(fhs_address_placeholder()); ?>" required>
      <div class="fhs-hint">丁目・番地までご記入ください（建物名・部屋番号は下の欄で結構です）</div>

<?php foreach (fhs_property_fields() as $pt => $flds):
        $vis = fhs_visible_fields('prop_' . $pt, $flds, $compact);
        if (!$vis) continue; ?>
      <div class="fhs-group" data-ptype="<?php echo esc_attr($pt); ?>" style="display:none">
<?php   foreach ($vis as $fd) { echo $render_field($fd, $pt . '__', $uid); } ?>
      </div>
<?php endforeach; ?>
<?php if ($stepped): ?></div><?php endif; /* step 1 ここまで */ ?>

<?php if ($step2): ?>
<?php if ($stepped): ?><div class="fhs-formstep" data-step="2" style="display:none"><?php endif; ?>
<?php if ($situ_fields): ?>
      <div class="fhs-section">売却のご状況</div>
      <div class="fhs-group">
<?php   foreach ($situ_fields as $fd) { echo $render_field($fd, 'situation_', $uid); } ?>
      </div>
<?php endif; ?>

<?php if ($show_note): ?>
      <label for="<?php echo esc_attr($uid . '-note'); ?>">備考・ご要望<span class="fhs-opt">任意</span></label>
      <textarea name="note_text" id="<?php echo esc_attr($uid . '-note'); ?>" rows="2" placeholder="ご不明な点、ご希望などがあればご記入ください"></textarea>
<?php endif; ?>
<?php if ($stepped): ?></div><?php endif; /* step 2 ここまで */ ?>
<?php endif; ?>

<?php if ($stepped): ?><div class="fhs-formstep" data-step="<?php echo $step2 ? 3 : 2; ?>" style="display:none"><?php endif; ?>
      <div class="fhs-section">ご連絡先</div>
<?php if ($cust_fields): ?>
      <div class="fhs-group">
<?php   foreach ($cust_fields as $fd) { echo $render_field($fd, 'customer_', $uid); } ?>
      </div>
<?php endif; ?>
      <label for="<?php echo esc_attr($uid . '-email'); ?>">メールアドレス<span class="fhs-req">必須</span></label>
      <input type="email" name="email" id="<?php echo esc_attr($uid . '-email'); ?>" placeholder="you@example.com" autocomplete="email" required>

      <?php /* 個人情報の利用目的の明示（個情法21条）。同意を求める直前に必ず出す。
               プライバシーポリシーURLが未設定でも、最低限ここで目的が伝わるようにしておく。
               第三者提供がONのときは、提供先と提供する旨を必ず書く（個情法27条）。 */ ?>
      <div class="fhs-privacy-note">
        <strong>個人情報の取り扱いについて</strong><br>
        ご入力いただいた内容は、<?php echo esc_html($op_name); ?>が<strong>査定の実施とその結果のご連絡、およびそれに関するご案内</strong>のために利用します。<br>
<?php if ($tp): ?>
        また、査定・ご提案のため、<strong><?php echo $tp_label; ?></strong>にご入力内容（お名前・ご連絡先・物件情報）を提供します。
        提供先での取り扱いは、提供先の定めによります。ご同意いただけない場合は、送信をお控えください。<br>
<?php else: ?>
        ご本人の同意なく第三者に提供することはありません。<br>
<?php endif; ?>
        削除をご希望の場合は、<?php echo (fhs_opt('operator_contact', '') !== '' && fhs_flag('show_contact', false))
            ? '下記の連絡先'
            : ($privacy ? '<a href="' . esc_url($privacy) . '" target="_blank" rel="noopener">プライバシーポリシー</a>に記載の窓口'
                        : '当社の窓口'); ?>までお申し付けください。
      </div>

      <div class="fhs-check">
        <input type="checkbox" name="agree" id="<?php echo esc_attr($uid . '-agree'); ?>" value="1" required>
        <label for="<?php echo esc_attr($uid . '-agree'); ?>"><?php echo $agree_label; ?></label>
      </div>
<?php if ($show_mkt): ?>
      <div class="fhs-check">
        <input type="checkbox" name="marketing" id="<?php echo esc_attr($uid . '-mkt'); ?>" value="1">
        <label for="<?php echo esc_attr($uid . '-mkt'); ?>">売却に関するご提案・お役立ち情報のメール受け取りを希望します（任意）</label>
      </div>
<?php endif; ?>
<?php if ($compact): ?>
      <input type="hidden" name="compact" value="1">
<?php endif; ?>
<?php if ($stepped): ?></div><?php endif; /* 最終ステップ ここまで */ ?>

      <div class="fhs-nav">
<?php if ($stepped): ?>
        <button type="button" class="fhs-back" style="display:none">← 戻る</button>
        <button type="button" class="fhs-nextstep">次へ進む</button>
<?php endif; ?>
        <button class="fhs-submit" type="submit"<?php echo $stepped ? ' style="display:none"' : ''; ?>><?php echo esc_html($btn); ?></button>
      </div>
    </form>
<?php endif; /* ===== 分岐ここまで ===== */ ?>

<?php if (!$teaser): ?>
<?php /* 申し込みフォームには免責を置かない。ここでは価格を一切示しておらず、
         申し込みをためらわせるだけになるため。
         免責は「価格の話が始まる場面」＝受付完了メールと完了画面に出す。 */ ?>
<?php
    /* 査定を担当する会社の明示。お客様が「どこの誰に自宅と連絡先を渡すのか」を
       判断する材料になる。設定済みの項目だけを出す。 */
    $op_disp = fhs_opt('operator_name', '');
    $op_addr = fhs_opt('operator_address', ''); $op_tel = fhs_opt('operator_contact', '');
    $op_img  = fhs_opt('company_image', '');
    $op_url  = fhs_opt('operator_url', '');
    $op_mail = fhs_opt('operator_email', '');
    $op_tel_shown = ($op_tel !== '' && fhs_flag('show_contact', false));
    if ($op_disp || $op_addr || $op_url || $op_tel_shown):
?>
    <div class="fhs-operator">
      <div class="fhs-operator-t">査定担当会社</div>
      <div class="fhs-operator-body">
<?php if ($op_img): ?>
        <img class="fhs-opimg" src="<?php echo esc_url($op_img); ?>" alt="<?php echo esc_attr($op_disp); ?>" loading="lazy">
<?php endif; ?>
        <div class="fhs-operator-info">
<?php if ($op_disp): ?>          <div class="fhs-operator-name"><?php echo esc_html($op_disp); ?></div>
<?php endif; if ($op_addr): ?>          <div><span>所在地</span><?php echo esc_html($op_addr); ?></div>
<?php endif; if ($op_url !== ''): ?>          <div><span>サイト</span><a href="<?php echo esc_url($op_url); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', rtrim($op_url, '/'))); ?></a></div>
<?php endif; if ($op_tel_shown): ?>          <div><span>お問い合わせ</span><?php echo esc_html($op_tel); ?></div>
<?php endif; if ($op_tel_shown && $op_mail !== ''): ?>          <div><span>メール</span><?php echo esc_html($op_mail); ?></div>
<?php endif; ?>
        </div>
      </div>
    </div>
<?php endif; ?>
<?php endif; /* !$teaser ここまで */ ?>
  </div>

  <div class="fhs-card fhs-result" style="display:none"></div>
</div>

<?php if ($need_assets): ?>
<script>
(function(){
  /* このスクリプトはページに1回だけ出力し、ページ内のフォームを全部まとめて初期化する。
     LPのようにティザーを何個も置いても、重いJSが人数分ぶら下がらないようにするため。 */
  if (window.fhsFormsReady) return;
  window.fhsFormsReady = true;

  var AJAX = <?php echo wp_json_encode($ajax); ?>;
  var NONCE = <?php echo wp_json_encode($nonce); ?>;
  var LOADED_AT = Date.now();   // ページキャッシュがあってもJS側で計測すれば正しく効く
  var HANDOFF_KEY = 'fhs_handoff';

  /* ★ティザーからの引き継ぎは sessionStorage で行う（URLのクエリには載せない）。
     物件の住所という個人に結びつく情報を URL に載せると、ブラウザの履歴や
     外部サイトへのリファラに残ってしまうため。
     サーバー側でHTMLに焼き込む方式も採らない（ページキャッシュが効くと
     「最初に開いた人の入力値」が他の訪問者にも配られてしまう）。 */
  function readHandoff(){
    try {
      var raw = sessionStorage.getItem(HANDOFF_KEY);
      if (!raw) return null;
      var o = JSON.parse(raw);
      return (o && typeof o === 'object') ? o : null;
    } catch (e) { return null; }   // 使えない環境では引き継ぎ無しとして扱う
  }

  function init(wrap){
  if (!wrap || wrap.getAttribute('data-fhs-init')) return;
  wrap.setAttribute('data-fhs-init', '1');
  var TEASER = wrap.getAttribute('data-fhs-teaser') === '1';
  var TARGET = wrap.getAttribute('data-fhs-target') || '';
  var form = wrap.querySelector('.fhs-form'), errBox = wrap.querySelector('.fhs-errors');
  if (!form) return;
  var formCard = wrap.querySelector('.fhs-form-card'), resultCard = wrap.querySelector('.fhs-result');
  var btn = wrap.querySelector('.fhs-submit');
  var SUBMIT_LABEL = btn ? btn.textContent : '送信';
  var SENDING = false;   // 送信中フラグ（二重送信の防止。btn.disabled だけではEnter連打を防げない）
  var ptypeSel = wrap.querySelector('select[name="ptype"]');
  var groups = wrap.querySelectorAll('.fhs-group[data-ptype]');

  /* その欄が「ちゃんと埋まっているか」。
     ★空でないだけで✓を出すと、「090」だけでもチェックが付いてしまい、
       送信して初めて形式エラーが出る。見た目と結果が食い違うので形式まで見る。
     戻り値: null=OK / 'empty'=未入力 / 'format'=形式が違う */
  function fieldProblem(el){
    var v = String(el.value == null ? '' : el.value).trim();
    if (v === '') return 'empty';
    var type = (el.getAttribute('type') || '').toLowerCase();
    var mode = (el.getAttribute('inputmode') || '').toLowerCase();
    var han = v.replace(/[０-９]/g, function(c){ return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
               .replace(/[－ーｰ−—]/g, '-').replace(/[．]/g, '.');
    if (type === 'tel') {
      var digits = han.replace(/[^0-9]/g, '');
      return (digits.length >= 9 && digits.length <= 11) ? null : 'format';   // サーバー側と同じ基準
    }
    if (type === 'email') {
      return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? null : 'format';
    }
    if (mode === 'decimal') {                       // 面積・築年などの数値欄
      return /^[0-9]+(\.[0-9]+)?$/.test(han) ? null : 'format';
    }
    return null;
  }
  function fieldOk(el){ return fieldProblem(el) === null; }

  // 直前の <label> 内の「必須」バッジ
  function badgeFor(el){
    var lbl = el.previousElementSibling;
    return (lbl && lbl.tagName === 'LABEL') ? lbl.querySelector('.fhs-req') : null;
  }

  // 画面の並び順に：種別 → 住所 → 選択中の種別の必須項目 → その他の必須項目 → メール
  function currentRequired(){
    var req = [];
    if (form.elements['ptype'])   req.push(form.elements['ptype']);
    if (form.elements['address']) req.push(form.elements['address']);
    var pt = ptypeSel ? ptypeSel.value : '';
    if (pt) {
      var g = wrap.querySelector('.fhs-group[data-ptype="' + pt + '"]');
      if (g) Array.prototype.forEach.call(g.querySelectorAll('[data-req="1"]'), function(el){ req.push(el); });
    }
    Array.prototype.forEach.call(form.querySelectorAll('.fhs-group:not([data-ptype]) [data-req="1"]'), function(el){ req.push(el); });
    if (form.elements['email'])   req.push(form.elements['email']);
    return req;
  }

  var resumeBox = null;

  function updateFormState(){
    if (TEASER) return;   // ティザーは引き継ぐだけなので、必須ガイドは動かさない
    Array.prototype.forEach.call(form.querySelectorAll('.fhs-next'), function(e){ e.classList.remove('fhs-next'); });
    var req = currentRequired(), firstEmpty = null, remaining = 0;
    req.forEach(function(el){
      var b = badgeFor(el);
      var filled = fieldOk(el);   // 形式まで正しいときだけ ✓ にする
      if (b) {
        if (filled) { b.classList.add('fhs-done'); b.textContent = '✓'; }
        else { b.classList.remove('fhs-done'); b.textContent = '必須'; }
      }
      if (!filled) { remaining++; if (!firstEmpty) firstEmpty = el; }
    });
    if (firstEmpty) firstEmpty.classList.add('fhs-next');
    if (resumeBox) {
      if (remaining === 0) resumeBox.style.display = 'none';
      else { resumeBox.style.display = ''; resumeBox.querySelector('span').textContent = 'あと' + remaining + '項目で完了です'; }
    }
  }

  // 種別を選ぶと、その種別の入力欄だけ表示
  function switchType(){
    if (TEASER) return;
    var pt = ptypeSel ? ptypeSel.value : '';
    Array.prototype.forEach.call(groups, function(g){
      g.style.display = (g.getAttribute('data-ptype') === pt) ? '' : 'none';
    });
    updateFormState();
  }

  /* 引き継ぎで来たときだけ出す「↓ 続きはこちらから」。
     長いフォームの途中に飛ばされると、どこから書けばいいのか分からなくなるため。 */
  function insertResumeBanner(){
    var el = wrap.querySelector('.fhs-next');
    if (!el) return;
    var anchor = el;
    while (anchor && anchor.parentNode !== form) anchor = anchor.parentNode;
    if (!anchor) return;
    var prev = anchor.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') anchor = prev;
    resumeBox = document.createElement('div');
    resumeBox.className = 'fhs-resume';
    resumeBox.innerHTML = '<b>↓ 続きはこちらから</b><span></span>';
    form.insertBefore(resumeBox, anchor);
    updateFormState();
  }

  /* ===== ステップ表示 =====
     一画面に全部出すより離脱が減る。個人情報は必ず最後のステップに置く。
     ページ遷移はしない（読み込み待ちで離脱するため、表示の切り替えだけで済ませる）。 */
  var steps = wrap.querySelectorAll('.fhs-formstep');
  var STEPPED = steps.length > 1;
  var stepNow = 0;
  var stepDots = wrap.querySelectorAll('.fhs-stepdot');
  var stepLabel = wrap.querySelector('.fhs-stepnow');
  var backBtn = wrap.querySelector('.fhs-back');
  var nextBtn = wrap.querySelector('.fhs-nextstep');
  var STEP_TITLES = <?php echo wp_json_encode($stepped ? $step_titles : array()); ?>;

  /** そのステップの中で、まだ埋まっていない必須項目を返す */
  function missingIn(i){
    if (!STEPPED) return [];
    var box = steps[i], out = [];
    var els = box.querySelectorAll('select[name="ptype"], input[name="address"], input[name="email"], [data-req="1"]');
    Array.prototype.forEach.call(els, function(el){
      if (!el.offsetParent && el.type !== 'hidden') return;      // 表示されていない種別の欄は対象外
      if (el.closest('.fhs-group[data-ptype]') && el.closest('.fhs-group[data-ptype]').style.display === 'none') return;
      if (!fieldOk(el)) out.push(el);
    });
    var agree = box.querySelector('input[name="agree"]');
    if (agree && !agree.checked) out.push(agree);
    return out;
  }

  function labelTextOf(el){
    if (el.name === 'agree') return '個人情報の取扱いへの同意';
    var lbl = el.previousElementSibling;
    if (!lbl || lbl.tagName !== 'LABEL') {
      var byId = el.id ? wrap.querySelector('label[for="' + el.id + '"]') : null;
      lbl = byId || lbl;
    }
    if (!lbl) return 'この項目';
    return lbl.textContent.replace(/必須|任意|✓/g, '').trim();
  }

  function showStep(i){
    if (!STEPPED) return;
    stepNow = Math.max(0, Math.min(steps.length - 1, i));
    Array.prototype.forEach.call(steps, function(s, n){ s.style.display = (n === stepNow) ? '' : 'none'; });
    Array.prototype.forEach.call(stepDots, function(d, n){ d.classList.toggle('is-on', n <= stepNow); });
    if (stepLabel) stepLabel.innerHTML = 'STEP <b>' + (stepNow + 1) + '</b> / ' + steps.length + '　' + esc(STEP_TITLES[stepNow] || '');
    var last = (stepNow === steps.length - 1);
    if (nextBtn) nextBtn.style.display = last ? 'none' : '';
    if (btn)     btn.style.display     = last ? '' : 'none';
    if (backBtn) backBtn.style.display = (stepNow === 0) ? 'none' : '';
    errBox.innerHTML = '';
    updateFormState();
  }

  if (STEPPED) {
    if (nextBtn) nextBtn.addEventListener('click', function(){
      var miss = missingIn(stepNow);
      if (miss.length) {
        errBox.innerHTML = miss.map(function(el){
          var why = fieldProblem(el);
          var name = esc(labelTextOf(el));
          var msg = (why === 'format')
            ? '「' + name + '」の形式をご確認ください。'
            : '「' + name + '」を入力してください。';
          return '<div class="fhs-err">' + msg + '</div>';
        }).join('');
        errBox.scrollIntoView({ behavior:'smooth', block:'center' });
        if (miss[0].focus) miss[0].focus();
        return;
      }
      showStep(stepNow + 1);
      wrap.scrollIntoView({ behavior:'smooth', block:'start' });
    });
    if (backBtn) backBtn.addEventListener('click', function(){
      showStep(stepNow - 1);
      wrap.scrollIntoView({ behavior:'smooth', block:'start' });
    });
    showStep(0);
  }

  function scrollToFirstEmpty(){
    var target = resumeBox || wrap.querySelector('.fhs-typed.fhs-next, input.fhs-next, select.fhs-next') || btn;
    if (!target) return;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    setTimeout(function(){
      target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    }, 120);
  }

  if (ptypeSel) ptypeSel.addEventListener('change', switchType);
  Array.prototype.forEach.call(form.querySelectorAll('.fhs-typed, input[name="address"], input[name="email"]'), function(el){
    el.addEventListener('change', updateFormState);
    el.addEventListener('input', updateFormState);
  });

  switchType(); // 初期表示
  /* ブラウザバック等でブラウザが種別を復元しても change は発火しないため、
     「中古マンションと表示されているのに入力欄が出ない」状態になる。読み込み時と
     bfcache 復帰時に自前で反映し直す。 */
  setTimeout(switchType, 0);
  setTimeout(switchType, 250);
  window.addEventListener('pageshow', function(e){ if (e.persisted) switchType(); });

  /* ティザーから引き継いだ入力値を復元する */
  if (!TEASER) {
    var HANDOFF = readHandoff();
    if (HANDOFF && Object.keys(HANDOFF).length) {
      var applyHandoff = function(){
        Object.keys(HANDOFF).forEach(function(n){
          var el = form.elements[n];
          if (el && HANDOFF[n]) el.value = HANDOFF[n];
        });
        switchType();
      };
      applyHandoff();
      /* ブラウザの自動入力は、こちらの復元より後に走って値を上書きすることがある。
         戻る操作（bfcache）でも以前の入力値が復元される。引き継いだ値が正しいので取り戻す。 */
      setTimeout(applyHandoff, 0);
      setTimeout(applyHandoff, 250);
      window.addEventListener('pageshow', function(e){ if (e.persisted) applyHandoff(); });

      if (STEPPED) {
        /* 引き継ぎで埋まったステップは飛ばして、最初に書くところから始める */
        var start = steps.length - 1;
        for (var si = 0; si < steps.length; si++) {
          if (missingIn(si).length) { start = si; break; }
        }
        showStep(start);
        if (start > 0) scrollToFirstEmpty();
      } else {
        insertResumeBanner();
        scrollToFirstEmpty();
      }
    }
  }

  function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

  form.addEventListener('submit', function(e){
    e.preventDefault();

    /* ティザー: ここでは送信しない。入力値を持って本フォームのページへ移るだけ。 */
    if (TEASER) {
      var data = {};
      Array.prototype.forEach.call(form.querySelectorAll('.fhs-typed, .fhs-tile-input'), function(el){
        if (el.type === 'radio') { if (el.checked && el.value) data[el.name] = el.value; }
        else if (el.value) data[el.name] = el.value;
      });
      try { sessionStorage.setItem(HANDOFF_KEY, JSON.stringify(data)); } catch (err) { /* 引き継げないだけ */ }
      if (!TARGET) {
        errBox.innerHTML = '<div class="fhs-err">遷移先のページが設定されていません。サイト管理者にお知らせください。</div>';
        return;
      }
      window.location.href = TARGET;
      return;
    }

    if (SENDING) return;   // 送信中の再送信（連打・Enter連打）を止める
    SENDING = true;
    errBox.innerHTML = '';
    btn.disabled = true; btn.textContent = '送信中…';

    var fd = new FormData(form);
    fd.append('action', 'fudosan_honki');
    fd.append('nonce', NONCE);
    fd.append('fhs_elapsed', String(Date.now() - LOADED_AT));   // 表示から送信までの経過ms（ボット判定）

    /* ページキャッシュで古いnonceが配られていると 403 になる。
       その場合だけ新しいnonceを取り直して1回だけ送り直す。 */
    function send(retried){
      fd.set('nonce', NONCE);
      return fetch(AJAX, { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(r){
          if (r.status === 403 && !retried) {
            return fetch(AJAX + '?action=fudosan_honki_nonce', { credentials:'same-origin' })
              .then(function(x){ return x.json(); })
              .then(function(n){
                if (!n || !n.nonce) throw new Error('nonce');
                NONCE = n.nonce;
                return send(true);
              });
          }
          return r.json();
        });
    }

    send(false)
      .then(function(d){
        SENDING = false;
        btn.disabled = false; btn.textContent = SUBMIT_LABEL;
        /* ★応答が正しい形か必ず確かめる。
           admin-ajax は失敗時に -1 や 0 という「JSONとして解釈できてしまう値」を返す。
           素通しすると、1件も保存されていないのに「受け付けました」と表示してしまう。 */
        if (!d || typeof d !== 'object' || (d.ok !== true && !d.errors)) throw new Error('bad-response');
        if (d.errors) {
          errBox.innerHTML = d.errors.map(function(x){return '<div class="fhs-err">'+esc(x)+'</div>';}).join('');
          errBox.scrollIntoView({ behavior:'smooth', block:'center' });   // 画面外だと「無反応」に見えて連打される
          return;
        }
        renderResult(d);
      })
      .catch(function(){
        SENDING = false;
        btn.disabled = false; btn.textContent = SUBMIT_LABEL;
        errBox.innerHTML = '<div class="fhs-err">通信エラーが発生しました。時間をおいて再度お試しください。</div>';
      });
  });

  function renderResult(d){
    // 申し込みが完了したら引き継ぎデータは用済み。残すと次の訪問で古い値が復元されてしまう
    try { sessionStorage.removeItem(HANDOFF_KEY); } catch (e) {}
    var rows = (d.name ? '<tr><th>お名前</th><td>'+esc(d.name)+' 様</td></tr>' : '')
      + (d.tel ? '<tr><th>電話番号</th><td>'+esc(d.tel)+'</td></tr>' : '')
      + '<tr><th>メール</th><td>'+esc(d.email)+'</td></tr>'
      + '<tr><th>物件種別</th><td>'+esc(d.ptype_label)+'</td></tr>'
      + (d.address ? '<tr><th>物件住所</th><td>'+esc(d.address)+'</td></tr>' : '');
    var det = d.confirm_text
      ? '<div class="fhs-hint" style="white-space:pre-line;margin-top:10px">'+esc(d.confirm_text)+'</div>' : '';
    var mailLine = d.mail_ok
      ? '<p class="fhs-ok">✓ '+esc(d.email)+' 宛に受付完了メールをお送りしました。</p>'
      : '<p class="fhs-hint">お申し込みは完了しています（確認メールの送信に失敗した可能性があります。担当より別途ご連絡します）。</p>';
    var html = '<h3 style="margin-top:0">査定のお申し込みを受け付けました</h3>'
      + '<div class="fhs-next-note"><strong>このあとの流れ</strong><br>担当者が内容を確認し、ご入力いただいたご連絡先へご連絡いたします。'
      + '営業時間の都合により、お時間をいただく場合があります。</div>'
      + '<table class="fhs-spec">'+rows+'</table>'
      + det
      + mailLine;
    resultCard.innerHTML = html;
    formCard.style.display = 'none';
    resultCard.style.display = 'block';
    resultCard.scrollIntoView({ behavior:'smooth', block:'start' });
  }
  }

  function initAll(){
    Array.prototype.forEach.call(document.querySelectorAll('.fhs-wrap'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
  else initAll();
  // 戻る操作からの復帰や、後から差し込まれたフォームにも効かせる
  window.addEventListener('pageshow', initAll);
})();
</script>
<?php endif; /* $need_assets */ ?>
<?php
    return ob_get_clean();
}
