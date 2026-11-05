#!/usr/bin/env python3
"""HP デザイン案「サンプル案」の全ページを生成する（Task 11-39）。

使い方（リポジトリ直下で）:
    python3 tools/hp-designs/build_sample.py

- 出力先: hp-designs/サンプル案/*.html（共通 CSS は hp-designs/サンプル案/assets/site.css）
- 文章・写真は 2026-11-05 時点の ark-conditioning.com（オーナー許可のもと閲覧のみ）から取っている。
  事実は足さない。分からない点は「改善ポイント」に「要確認」として書く。
- ヘッダー・フッター・改善ポイントを全ページで揃えるため、ページは手で直さずこのスクリプトを直して再生成する。
"""
from __future__ import annotations

import html
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "hp-designs" / "サンプル案"

IMG = "https://ark-conditioning.com/wp-content/themes/twentytwenty-child/img/"
LOGO = "https://ark-conditioning.com/wp-content/uploads/2023/04/ark_conditioning_logo.png"
BOOKING = "/booking"  # 見本では ARK お試し環境の予約画面へつなぐ
TEL = "03-6421-3363"
TEL_LINK = "tel:0364213363"
LINE = "https://line.me/R/ti/p/%40506qojgv"
INSTAGRAM = "https://www.instagram.com/ark_conditioning/"
ADDRESS = "東京都目黒区自由が丘1-26-5 稲毛屋ビル3F"
MAP = "https://www.google.com/maps/search/?api=1&query=%E6%9D%B1%E4%BA%AC%E9%83%BD%E7%9B%AE%E9%BB%92%E5%8C%BA%E8%87%AA%E7%94%B1%E3%81%8C%E4%B8%981-26-5"
HOURS_WEEKDAY = "平日 10:00〜21:00（最終受付 20:00）"
HOURS_HOLIDAY = "日・祝 10:00〜19:00（最終受付 18:00）"

NAV = [
    ("visitor.html", "はじめての方"),
    ("menu.html", "メニュー"),
    ("price.html", "料金"),
    ("staff.html", "スタッフ"),
    ("access.html", "アクセス"),
    ("blog.html", "ブログ"),
]

# 改善ポイント（全ページ共通の一覧。番号はページ内のオレンジの印と対応）
NOTES = [
    ("フッターの初期設定の文章を、正しい店舗情報に",
     "ページの一番下に WordPress の初期文章（「ここには自己紹介…」「123 Main Street New York」「月〜金 9:00 AM – 5:00 PM」）が全ページに残っている。",
     "住所・営業時間・電話を全ページ共通のフッターに正しく表示。"),
    ("予約ボタンの行き先を 1 つにし、いつでも押せる位置に",
     "上のメニューの「WEB予約」はキャンペーンページ、下の「WEB予約」は外部の予約ページと、行き先が違う。",
     "すべて同じ予約ページへ。スマホでは画面下に「電話」「WEB予約」を常に表示（見本は ARK のお試し予約画面につないでいる）。"),
    ("色をロゴの青に統一",
     "ロゴは青のグラデーションだが、ボタンや強調はテーマ初期色の赤。",
     "ロゴのネイビー〜ブルーを基調に、背景は施術室の木やタオルに合う生成り色に。"),
    ("最初の画面で「何をしてくれるお店か」を一言で",
     "「つくろう！正しい姿勢と正しい運動習慣」と写真だけで、治療と運動の両方ができることや場所の便利さが伝わりにくい。",
     "「痛みを治す。痛まない体をつくる。」＋ 駅徒歩2分・国家資格者によるカラダチェック・個室・シャワー。"),
    ("お悩み・お客様の声・よくある質問を見つけやすく",
     "これらは「はじめての方」ページの中にあり、トップからは見えない。",
     "トップにも要点を出し、詳しくは「はじめての方」ページへ。"),
    ("料金は「まず基本料金」、詳細は整理して（要確認あり）",
     "表が細かく比べにくい。120分の料金が 90分と同じ 14,300円、回数券も 45分と同じ金額で入力ミスの可能性。",
     "基本料金を先に見せ、コースごとに表を分けた。120分は確認できるまで載せていない。"),
    ("お知らせ・ブログは新しい順に",
     "トップのお知らせの日付の並びが崩れている（2026.08.09 の後に 2026.09.05）。",
     "新しい順に整理し、ブログはカテゴリで絞り込めるように。"),
    ("求人ページのエラー表示を消す（至急）",
     "求人ページに PHP の警告（Undefined variable $cat_id … functions.php on line 134）とサーバー内部のファイルの場所が表示されている。「オープン3年以内」も現在は当てはまらない。",
     "警告をなくし（テーマの修正が必要）、開業年を「2021年9月オープン」と事実で書く。"),
    ("営業時間の表記をそろえる（要確認）",
     "お問い合わせページだけ「土日祝 10:00〜19:00」、ほかは「日・祝 10:00〜19:00」で、土曜の営業時間が分からない。",
     "見本は多くのページの表記（平日／日・祝）に合わせた。土曜の扱いを決めて全ページ同じ表記に。"),
    ("お問い合わせの手段を整理",
     "「現在システムエラーの為メールはご利用できません」と書かれたまま。",
     "LINE・電話・WEB予約の 3 つを並べ、使えないメールフォームは載せない。"),
    ("症状改善コースのページを作る",
     "症状改善コースのページが、記事 2 件と初期文章だけでほぼ空。",
     "トップに書かれている内容（集中的に改善・初回90〜105分）をもとにページを用意。内容は追記が必要。"),
]


def esc(s: str) -> str:
    return html.escape(s, quote=True)


def pin(n: int, style: str = "left:12px;top:24px") -> str:
    return f'<button class="pin" style="{style}" data-note="{n}" aria-label="改善ポイント{n}">{n}</button>'


def layout(filename: str, title: str, body: str, *, description: str = "") -> str:
    nav = "\n".join(
        f'      <a href="{href}"{" aria-current=\"page\"" if href == filename else ""}>{label}</a>' for href, label in NAV
    )
    notes = "\n".join(
        f"    <li><b>{esc(t)}</b>\n      <span class=\"before\">今：{esc(b)}</span><br>\n      <span class=\"after\">案：{esc(a)}</span></li>"
        for t, b, a in NOTES
    )
    page_title = "ARK Conditioning Laboratory 自由が丘" if filename == "index.html" else f"{title}｜ARK Conditioning Laboratory 自由が丘"
    return f"""<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{esc(page_title)}（サンプル案）</title>
<meta name="description" content="{esc(description)}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;900&family=Inter:wght@500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/site.css">
</head>
<body>

<!-- このファイルは tools/hp-designs/build_sample.py で生成。直接編集しない。 -->
<header class="hdr pin-host">
  {pin(2, "left:8px;top:18px")}
  <div class="wrap hdr__in">
    <a class="hdr__logo" href="index.html"><img src="{LOGO}" alt="ARK Conditioning Laboratory 自由が丘治療院"></a>
    <nav class="hdr__nav" aria-label="メインメニュー">
{nav}
    </nav>
    <a class="hdr__tel" href="{TEL_LINK}">{TEL}</a>
    <a class="btn btn--primary btn--sm" href="{BOOKING}">WEB予約</a>
    <button class="hdr__menu" type="button" aria-controls="spnav" aria-expanded="false">メニュー ▾</button>
  </div>
  <nav class="spnav" id="spnav" aria-label="スマホ用メニュー">
{nav}
      <a href="recruit.html">求人</a>
      <a href="contact.html">お問い合わせ</a>
  </nav>
</header>

<main id="top">
{body}
</main>

<footer class="ftr pin-host">
  {pin(1, "left:12px;top:12px")}
  <div class="wrap">
    <div class="ftr__grid">
      <div>
        <h3>ARK Conditioning Laboratory 自由が丘</h3>
        <p>パーソナルトレーニング × 整体・鍼灸・マッサージ<br>{ADDRESS}<br>自由が丘駅 徒歩2分</p>
      </div>
      <div>
        <h3>営業時間</h3>
        <p>{HOURS_WEEKDAY}<br>{HOURS_HOLIDAY}<br><a href="{TEL_LINK}">TEL {TEL}</a></p>
      </div>
      <div>
        <h3>ページ</h3>
        <ul class="ftr__links">
          <li><a href="visitor.html">はじめての方</a></li><li><a href="menu.html">メニュー</a></li>
          <li><a href="price.html">料金</a></li><li><a href="staff.html">スタッフ</a></li>
          <li><a href="access.html">アクセス</a></li><li><a href="blog.html">ブログ</a></li>
          <li><a href="recruit.html">求人</a></li><li><a href="contact.html">お問い合わせ</a></li>
          <li><a href="{INSTAGRAM}" target="_blank" rel="noopener">Instagram</a></li>
        </ul>
      </div>
    </div>
    <p class="ftr__copy">© ARK Conditioning Laboratory</p>
  </div>
</footer>

<div class="dock" aria-label="予約">
  <a class="btn btn--ghost" href="{TEL_LINK}">☎ 電話</a>
  <a class="btn btn--primary" href="{BOOKING}">WEB予約（空き確認）</a>
</div>

<!-- 改善ポイント（見本確認用。本番には入れない） -->
<button class="notes-toggle" type="button" aria-controls="notes" aria-expanded="false">改善ポイント（{len(NOTES)}）</button>
<aside class="notes" id="notes" aria-label="改善ポイント">
  <button class="notes__close" type="button">閉じる</button>
  <h2>サンプル案：今の HP からの改善ポイント</h2>
  <p>2026-11-05 に今の HP（ark-conditioning.com）を見て作成。文章・写真は今の HP のものを使い、事実は足していません。オレンジの番号はページ内の該当箇所です。</p>
  <ol>
{notes}
  </ol>
  <label class="notes__switch"><input type="checkbox" id="pinSwitch" checked> ページ内のオレンジの番号を表示する</label>
</aside>

<script src="assets/site.js"></script>
</body>
</html>
"""


def page_hero(en: str, title: str, lead: str, img: str | None = None, extra: str = "") -> str:
    photo = f'<div class="phero__photo"><img src="{img}" alt=""></div>' if img else ""
    return f"""<div class="phero{' phero--photo' if img else ''}">
  <div class="wrap phero__grid">
    <div>
      <nav class="crumb" aria-label="パンくず"><a href="index.html">HOME</a><span>›</span>{esc(title)}</nav>
      <span class="en phero__en">{en}</span>
      <h1>{esc(title)}</h1>
      <p class="phero__lead">{lead}</p>
      {extra}
    </div>
    {photo}
  </div>
</div>"""


def sec_head(en: str, title: str, lead: str = "") -> str:
    p = f"<p>{lead}</p>" if lead else ""
    return f'<div class="sec-head"><span class="en">{en}</span><h2>{title}</h2>{p}</div>'


def cta_band() -> str:
    return f"""<div class="cta-band pin-host">
  {pin(2, "left:12px;top:12px")}
  <div class="wrap">
    <h2>体のこと、まずは気軽にご相談ください</h2>
    <p>体の不調やトレーニングに関することなど、お気軽にお問い合わせください。</p>
    <a class="btn btn--ghost" href="{BOOKING}">空き状況を見て WEB予約</a>
    <a class="cta-band__tel" href="{TEL_LINK}">☎ {TEL}</a>
    <span class="cta-band__hours">平日 10:00〜21:00 ／ 日・祝 10:00〜19:00</span>
  </div>
</div>"""


def worry_list(items: list[str]) -> str:
    return '<ul class="checklist">' + "".join(f"<li>{esc(i)}</li>" for i in items) + "</ul>"


# ---------------------------------------------------------------------------
# メニュー詳細（各ページの文章は今の HP の各メニューページから）
# ---------------------------------------------------------------------------
MENUS = [
    {
        "file": "menu-check.html", "tag": "まずはここから", "name": "カラダチェック", "en": "BODY CHECK",
        "catch": "まずは、カラダチェックで不調の原因を見つけます",
        "lead": "姿勢や動作をチェックして、お身体の使い方の癖を見つけて不調の原因を探します。",
        "img": IMG + "home/DSC02804.jpg", "img2": IMG + "home/DSC02548.jpeg",
        "worries": ["猫背だと言われたことがある", "足の長さが違うと言われたことがある", "いつも身体の右側だけ（左側だけ）凝る",
                    "靴底の減り方が均等ではない", "マスクを付けると、マスクの高さが均等ではない",
                    "自分でトレーニングした時に左右同じ様にトレーニングできない", "服の襟の開き方が均等ではない"],
        "why": "身体の不調の改善には、何が原因かを明確にする身体チェックが必要です",
        "effects": ["今の自分の身体を理解することで、癖が分かります", "原因を明確にすることで治し方がわかります",
                    "身体の癖を理解することで、自分の身体をケアできるようになります"],
        "features": ["解剖学に基づいて身体をチェックします", "お客さま自身が自分の身体を理解できるように説明します", "動作分析を用いてチェックします"],
        "summary": "解剖学・姿勢・動作から身体の使い方の癖をチェックし、日常生活で気をつけることをお伝えします。",
    },
    {
        "file": "menu-massage.html", "tag": "整える", "name": "整体マッサージ", "en": "MASSAGE",
        "catch": "「揉む」よりも「ほぐす」",
        "lead": "当院の整体マッサージは「揉む」より「ほぐす」に特化し、コリや痛みにオーダーメイドの「揉みほぐし」をご提供しています。",
        "img": IMG + "home/DSC02927.jpeg", "img2": IMG + "home/DSC02615.jpg",
        "worries": ["仕事で疲れてくると、足がむくむ", "通勤中歩いていると足が重い", "背中の張りが気になる",
                    "トレーニング終了後の張りをどうにかしたい", "湯船に浸かっても、肩まわりの疲れがとれない"],
        "why": "身体の疲労や痛みの改善には、原因となる筋肉を正しい押し方と適度な強さでマッサージする必要があります",
        "effects": ["血流が悪くなっている場所にマッサージを行ない、疲れを取ることができる", "筋肉をほぐすことで免疫力の向上や自律神経を整えます",
                    "全身をマッサージすることで、血液やリンパ液の循環を良くして新陳代謝を促します"],
        "features": ["リラクゼーションだけでなく、寝違え、ぎっくり腰などのマッサージも行ないます", "四十肩、五十肩といった慢性症状も対応いたします",
                     "お客さまにあわせた効果のあるオーダーメイドマッサージを行ないます"],
        "summary": "「揉む」より「ほぐす」に特化。正しい押し方と適度な強さで、疲れや痛みを改善します。",
    },
    {
        "file": "menu-acupuncture.html", "tag": "整える", "name": "鍼灸", "en": "ACUPUNCTURE",
        "catch": "自律神経のバランスを整えることで不定愁訴を改善します",
        "lead": "鍼やお灸の刺激によって自己免疫力、自然治癒力を高めて不調を改善します。",
        "img": IMG + "home/DSC02688.jpg", "img2": IMG + "home/DSC02615.jpg",
        "worries": ["ぐっすりと眠れない", "天気によって体調が左右される", "お腹の調子がいつも良くない", "目が疲れやすい",
                    "慢性的なコリがある", "冷え性", "生理痛が辛い"],
        "why": "慢性的な症状・不定愁訴の改善には、自律神経のバランスを整える鍼灸が必要です",
        "effects": ["頭に鍼を刺すことで、睡眠の改善や頭痛が緩和します", "ツボを使うことで複数の症状へアプローチします",
                    "マッサージでは届きづらい深部の筋肉を緩めることでコリを改善します"],
        "features": ["鍼を置いているあいだに他の場所をマッサージします", "鍼とマッサージの組み合わせによりオーダーメイドの対応ができます"],
        "summary": "深部の凝りを自己治癒力でゆるめ、自律神経も整えます。鍼を置いている間にマッサージも行います。",
    },
    {
        "file": "menu-training.html", "tag": "鍛える", "name": "パーソナルトレーニング", "en": "PERSONAL TRAINING",
        "catch": "正しいフォームで、肩こり・姿勢を改善します",
        "lead": "日常生活の何気ない動きで辛さを感じる場合は、正しく身体をつかえていないことが多いため、正しい使い方をトレーニングで覚え、痛みや辛さを改善します。",
        "img": IMG + "home/DSC03641.jpg", "img2": IMG + "home/DSC03361.jpeg",
        "worries": ["デスクワークで長時間座っていると、肩周りが辛い", "家事をしている時に腰に辛さを感じる", "通勤中歩いているとすぐ脚が疲れる",
                    "電車の吊り革を掴むと腕がしびれる", "ランニングをしているとすぐ辛くなる"],
        "why": "日常生活の辛さを改善するには、身体を正しく使えるようにするトレーニングが必要です",
        "effects": ["正しく使われていない筋肉を正しくトレーニングすることで疲れにくい身体にします", "正しく身体を使えるようにトレーニングすることで痩せやすい身体になります",
                    "普段使えていない筋肉をトレーニングすることで姿勢の改善につながります"],
        "features": ["ボディバランスを見ながら、ひとりひとりにあったトレーニングメニューを提案します", "正しいトレーニングフォームを指摘し、トレーニング効果をアップさせます",
                     "健康を重視し、重い重量をあげずに日常生活で使えるトレーニングをします"],
        "summary": "重いものを持ち上げるのではなく、日常生活を痛みなく過ごせる体の使い方をトレーニングします。",
    },
    {
        "file": "menu-senior.html", "tag": "鍛える", "name": "シニアパーソナルトレーニング", "en": "SENIOR TRAINING",
        "catch": "足や腕が上がりやすくなり、怪我のリスクを減らします",
        "lead": "日常生活で動かしにくくなっている動作を、正しくストレッチやトレーニングをすることで改善していきます。",
        "img": IMG + "home/DSC02804.jpg", "img2": IMG + "menu_main2.jpg",
        "worries": ["よくつまづくようになった", "朝起き上がるのに時間がかかる", "膝の痛みがあって、歩くのが大変", "腰の痛みで運動する気持ちになれない",
                    "腕があがりにくく、洗濯を干すのが辛い", "旅行に行きたいが、体力がなくなっていて不安に感じる"],
        "why": "日常生活を快適に過ごし、怪我をしない体を作っていくためには、正しい体の使い方を覚えていく必要があります",
        "effects": ["かたまってしまっている筋肉や関節をご自身の力で伸ばしていきます", "不安定な状態でトレーニングすることで、転倒しにくい体にしていきます",
                    "正しい動かし方でトレーニングすることで、疲れにくい体にしていきます"],
        "features": ["検査で可動域や筋力を確認して、ひとりひとりに合ったメニューを提案します", "けがの可能性が高い動作を、トレーニングで習得していきます",
                     "不調がある場所に対しても、改善するためのエクササイズを行い改善します"],
        "summary": "ストレッチ・バランス・適度な筋力トレーニングで、怪我をしない体と日常生活の質を高めます（60歳以上）。",
    },
    {
        "file": "menu-care.html", "tag": "集中して治す", "name": "症状改善コース", "en": "INTENSIVE CARE",
        "catch": "一定の期間、集中的に症状改善を目指すコースです",
        "lead": "マッサージや鍼灸とトレーニングを組み合わせて、一定の期間、集中的に症状改善を目指します。マッサージや鍼灸は症状を改善するだけでなく、動ける状態を作るためにも行います。",
        "img": IMG + "home/DSC02548.jpeg", "img2": IMG + "home/DSC02688.jpg",
        "worries": [],
        "why": "国家資格者の医科学的な知見に基づき、痛みや不調の原因を特定し改善します",
        "effects": [],
        "features": ["初回 90分〜105分、2回目以降 75〜90分", "マッサージ・鍼灸とトレーニングを組み合わせます"],
        "summary": "マッサージや鍼灸とトレーニングを組み合わせ、一定期間で集中的に症状改善を目指します。",
        "note": 11,
    },
]


def menu_detail(m: dict) -> str:
    worries = ""
    if m["worries"]:
        worries = f"""<section class="bg-white">
  <div class="wrap narrow">
    {sec_head("CONCERNS", "こんなお悩みありませんか？")}
    {worry_list(m["worries"])}
  </div>
</section>"""
    effects = ""
    if m["effects"]:
        effects = "<ul class=\"points\">" + "".join(f"<li><b>{i + 1:02d}</b><span>{esc(e)}</span></li>" for i, e in enumerate(m["effects"])) + "</ul>"
    pinhtml = pin(m["note"]) if m.get("note") else ""
    features = "".join(f"<li>{esc(f)}</li>" for f in m["features"])
    return page_hero(m["en"], m["name"], esc(m["catch"]), m["img"]) + f"""
<section class="pin-host">
  {pinhtml}
  <div class="wrap split">
    <div>
      <span class="tag">{esc(m["tag"])}</span>
      <h2 class="h2-left">{esc(m["catch"])}</h2>
      <p class="lead-left">{esc(m["lead"])}</p>
    </div>
    <img class="split__img" src="{m["img2"]}" alt="">
  </div>
</section>
{worries}
<section>
  <div class="wrap narrow">
    {sec_head("WHY", esc(m["why"]))}
    {effects}
    <div class="feature-box">
      <h3>当院の{esc(m["name"])}の特徴</h3>
      <ul class="checklist checklist--plain">{features}</ul>
    </div>
    <p class="center-note">料金は <a href="price.html">料金ページ</a> でご確認ください。</p>
  </div>
</section>
{other_menus(m["file"])}
{cta_band()}"""


def other_menus(current: str) -> str:
    cards = "".join(
        f'<a class="mini" href="{m["file"]}"><img src="{m["img"]}" alt=""><span>{esc(m["name"])}</span></a>'
        for m in MENUS if m["file"] != current
    )
    return f"""<section class="bg-white">
  <div class="wrap">
    {sec_head("OTHER MENU", "ほかのメニュー")}
    <div class="minis">{cards}</div>
  </div>
</section>"""


def menu_index() -> str:
    cards = "".join(
        f"""<a class="menu" href="{m["file"]}">
        <img src="{m["img"]}" alt="{esc(m["name"])}">
        <div><span class="tag">{esc(m["tag"])}</span><h3>{esc(m["name"])}</h3><p>{esc(m["summary"])}</p><span class="more">詳しく見る ›</span></div>
      </a>""" for m in MENUS
    )
    return page_hero("MENU", "メニュー", "その日の身体の状態とご要望にあわせて、治療（整体マッサージ、鍼灸）、トレーニング、リカバリーのメニューをご提案します。", IMG + "menu_main2.jpg") + f"""
<section>
  <div class="wrap">
    {sec_head("ALL MENU", "メニュー一覧", "単品でも、組み合わせても。オーダーメイドでご提案します。")}
    <div class="menus">{cards}</div>
    <div class="courses" aria-label="目的別コース"><span>月額コース</span><span>ジュニア（18歳以下）</span><span>ダイエットコース</span><span>ペアトレーニング</span></div>
    <p class="center-note">目的別のコースは <a href="price.html">料金ページ</a> にまとめています。</p>
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# 料金
# ---------------------------------------------------------------------------
def table(head: list[str], rows: list[list[str]]) -> str:
    th = "".join(f"<th>{esc(h)}</th>" for h in head)
    tr = "".join("<tr>" + "".join(f"<td{' class=\"num\"' if i else ''}>{c}</td>" for i, c in enumerate(r)) + "</tr>" for r in rows)
    return f'<div class="tbl"><table><thead><tr>{th}</tr></thead><tbody>{tr}</tbody></table></div>'


def off(price: str, discount: str) -> str:
    return f"{price}<small>{discount}OFF</small>"


def price_page() -> str:
    basic = table(["時間", "1回", "4回券", "8回券", "12回券"], [
        ["45分", "7,700円", off("28,600円", "2,200円"), off("56,100円", "5,500円"), off("81,400円", "11,000円")],
        ["60分", "9,900円", off("37,400円", "2,200円"), off("73,700円", "5,500円"), off("107,800円", "11,000円")],
        ["75分", "12,100円", off("46,200円", "2,200円"), off("91,300円", "5,500円"), off("134,200円", "11,000円")],
        ["90分", "14,300円", off("55,000円", "2,200円"), off("108,900円", "5,500円"), off("160,600円", "11,000円")],
    ])
    monthly = table(["時間・回数", "1回あたり", "合計（月額）"], [
        ["45分 × 4回まで", "6,050円", off("24,200円", "6,600円")],
        ["45分 × 8回まで", "5,500円", off("44,000円", "17,600円")],
        ["60分 × 4回まで", "8,250円", off("33,000円", "6,600円")],
        ["60分 × 8回まで", "7,700円", off("61,600円", "17,600円")],
        ["75分 × 4回まで", "10,450円", off("41,800円", "6,600円")],
        ["75分 × 8回まで", "9,900円", off("79,200円", "17,600円")],
        ["90分 × 4回まで", "12,650円", off("50,600円", "6,600円")],
        ["90分 × 8回まで", "12,100円", off("96,800円", "17,600円")],
    ])
    junior = table(["時間", "1回", "4回券"], [
        ["お試しコース", "4,400円", "—"],
        ["30分", "5,500円", off("19,800円", "2,200円")],
        ["45分", "7,700円", off("28,600円", "2,200円")],
    ])
    diet = table(["内容", "料金"], [["60分 × 12回 / 3ヶ月", "132,000円"], ["60分 × 16回 / 2ヶ月", "176,000円"]])
    pair = table(["時間", "1回", "5回券"], [["60分", "17,600円", "85,800円"], ["90分", "26,400円", "129,800円"]])
    return page_hero("PRICE", "料金", "すべて税込み価格です。お支払いはクレジットカード・電子マネー・PayPay がご利用いただけます。") + f"""
<section class="pin-host">
  {pin(6)}
  <div class="wrap narrow">
    {sec_head("BASIC", "基本料金（ARKコンディショニング）", "整体マッサージ・鍼灸・パーソナルトレーニングを、その日の状態に合わせて組み合わせます。")}
    {basic}
    <p class="price-note">回数券はまとめてご利用いただくほどお得になります。120分の料金は確認中のため掲載していません。</p>

    {sec_head("MONTHLY", "月額コース", "ARKコンディショニング（整体マッサージ × 鍼灸 × パーソナルトレーニング）が月額でお得になるプランです。")}
    {monthly}
    <ul class="rules">
      <li>利用可能時間：月曜〜金曜 12:00〜18:00（最終受付 17:00）</li>
      <li>スタッフ指名はできません。1日につき最大1回までご利用いただけます。</li>
      <li>利用には事前の予約が必要です。ご予約が取れるのは次回分のみです。</li>
      <li>ご予約の変更・キャンセルは前日までにお願いいたします。無断キャンセルは1回分消費となります。</li>
    </ul>

    <div class="price-cols">
      <div>
        {sec_head("JUNIOR / SENIOR", "ジュニア・シニアコース", "医療系国家資格を持ったトレーナーが、的確に必要な運動を提供します。ジュニア：18歳以下／シニア：60歳以上。")}
        {junior}
      </div>
      <div>
        {sec_head("PAIR", "ペアトレーニングコース", "ご夫婦・パートナー・お友達と一緒にトレーニングできます。")}
        {pair}
      </div>
    </div>

    {sec_head("DIET", "ダイエットコース", "体重減少の原理原則に基づき、正しいダイエットを提供します。SNSに掲載可能な方に限り、お得なプランもございます（お問い合わせください）。")}
    {diet}
    <p class="price-note">無断キャンセルは1回分の消費となります。すべて税込み価格です。</p>
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# はじめての方
# ---------------------------------------------------------------------------
def visitor_page() -> str:
    worries = ["姿勢が悪いと言われる", "歩いているとすぐ足が疲れる", "頭痛がよくおきる", "ぐっすりと眠れない",
               "長時間同じ姿勢をしていると肩周りが辛い", "背中の張りが気になる", "足がむくみやすい", "産後、身体が戻らない"]
    faq = [
        ("着替えはありますか？", "はい、ございます。ご希望の際はご用意いたしますので、スタッフにお伝えください。"),
        ("クレジットカードは使えますか？", "はい、ご利用できます。クレジットカードの他、電子マネー、PayPayもご利用できます。"),
        ("駐車場はありますか？", "ご用意がありません。近隣のコインパーキングをご利用ください。"),
        ("スタッフの指名はできますか？", "はい、可能です。ご希望があればご予約の際にお伝えください。"),
        ("予約をしなくても利用できますか？", "ご予約優先となりますが、空いていればご利用いただけます。"),
    ]
    faq_html = "".join(f"<details><summary>{esc(q)}</summary><p>{esc(a)}</p></details>" for q, a in faq)
    return page_hero("FIRST VISIT", "はじめての方へ",
                     "日常生活での痛みや不調の原因には、お身体の使い方の癖と心の状態が大きく関与します。ご自身で頭・身体・心を上手くコントロールできるようになることをサポートしています。",
                     IMG + "home/DSC02411.png") + f"""
<section class="bg-white pin-host">
  {pin(5)}
  <div class="wrap narrow">
    {sec_head("CONCERNS", "こんなことでお悩みではありませんか？")}
    {worry_list(worries)}
  </div>
</section>
<section>
  <div class="wrap">
    {sec_head("3 POINTS", "元気な身体づくりのための3つのポイント", "身体の痛みや不調の改善には、カラダチェック・整える・トレーニングの3つが不可欠です。")}
    <div class="steps">
      <article class="step"><img src="{IMG}home/DSC02548.jpeg" alt=""><div class="step__body"><div class="step__no">POINT 1</div><h3>カラダチェック</h3><p>カラダチェックで、身体の癖や不調の原因を特定します。</p></div></article>
      <article class="step"><img src="{IMG}home/DSC02615.jpg" alt=""><div class="step__body"><div class="step__no">POINT 2</div><h3>身体を整える</h3><p>不調の原因にあわせてマッサージ、骨盤・骨格調整・鍼灸を選ぶことで素早く不調を改善します。</p></div></article>
      <article class="step"><img src="{IMG}home/DSC03361.jpeg" alt=""><div class="step__body"><div class="step__no">POINT 3</div><h3>パーソナルトレーニング</h3><p>正しい身体の使い方を覚えることで、不調のない体作りをします。</p></div></article>
    </div>
  </div>
</section>
<section class="bg-white">
  <div class="wrap">
    {sec_head("FLOW", "当日の流れ")}
    <ol class="flow">
      <li><b>1</b><h3>ご予約</h3><p>WEB予約またはお電話で。スタッフの指名もできます。</p></li>
      <li><b>2</b><h3>問診・カラダチェック</h3><p>お悩みを伺い、姿勢や動作から原因を特定します。</p></li>
      <li><b>3</b><h3>施術・トレーニング</h3><p>その日の状態に合わせて、整体・鍼灸・トレーニングを組み合わせます。</p></li>
      <li><b>4</b><h3>セルフケアのご提案</h3><p>今の身体の状態にあったメニュー・プランをご提案します。</p></li>
    </ol>
    <p class="flow-time">着替えのご用意があります ／ 更衣室・シャワールーム完備</p>
  </div>
</section>
<section>
  <div class="wrap">
    {sec_head("VOICES", "お客様の声", "「楽になった」「身体のクセを知ることができた」等の声が寄せられています。")}
    <div class="voices">
      <article class="voice"><h3>腰が楽になりました！</h3><p>腰が痛くて行きました。自分のクセなんかも教えていただいたので、これから気をつけてみたいと思います。お店の内装はとても綺麗で、なおかつ落ち着く雰囲気で素敵なお店です。</p></article>
      <article class="voice"><h3>ダイエット目的でトレーニングと慢性的な腰痛に鍼灸をお試しで受けました！</h3><p>トレーニングの時に姿勢のクセなどを教えて頂きストレッチとトレーニングをしてもらい、その後、鍼をしてもらいました。鍼は気になっていたところにピンポイントで入ってきてかなり効きました。お試しのつもりでしたが、はまってしまいそうです。</p></article>
      <article class="voice"><h3>カラダチェックで自分の身体の癖を知ることができました！</h3><p>お店は通りに面しており、部屋は綺麗で清潔感があります。はじめに立ったり座ったりしながら自分の身体の状態を説明して頂き、身体の使い方の癖を教えてもらいました。</p></article>
    </div>
  </div>
</section>
<section class="bg-white">
  <div class="wrap">
    {sec_head("FAQ", "よくあるご質問")}
    <div class="faq">{faq_html}</div>
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# スタッフ（詳細が今の HP で明示されている内容だけ載せる）
# ---------------------------------------------------------------------------
def staff_page() -> str:
    return page_hero("STAFF", "スタッフ", "施術とトレーニングの両面から、みなさまの健康をサポートします。スタッフの指名もできます。") + f"""
<section>
  <div class="wrap">
    {sec_head("MAIN STAFF", "メインスタッフ")}
    <article class="profile">
      <img src="{IMG}staff/shigeki.jpg" alt="本間 慈基">
      <div>
        <span class="en">SHIGEKI HOMMA</span>
        <h3>本間 慈基</h3>
        <dl class="kv"><dt>担当メニュー</dt><dd>パーソナルトレーニング・マッサージ</dd><dt>趣味・好きな事</dt><dd>食べること（ダントツで好き）・トレーニング・コーヒー・ビール</dd></dl>
        <p>大学時代に野球部に所属していました。部活とは別に、接骨院で野球専門のトレーニング指導をしているところに通っていて、その先生の影響を大きく受けてこの業界に入りました。接骨院で働いているうちに、施術だけでは根本的な改善になっていないことに気付き、トレーニングの重要性を感じました。</p>
        <p>施術、トレーニングを通して、身体の不調でお困りの方を全力でサポート致します！</p>
      </div>
    </article>
    <div class="staff">
      <article class="person"><img src="{IMG}staff/syoki.jpg" alt="本間 奨基"><h3>本間 奨基</h3><span class="en">SHOKI HOMMA</span><p>みなさまの健康を第一に、結果にこだわってサポートさせていただきます！</p></article>
      <article class="person"><img src="{IMG}staff/miyuki.jpg" alt="齋藤 美由紀"><h3>齋藤 美由紀</h3><span class="en">MIYUKI SAITO</span><p>みなさまの人生の一部をサポートできるよう、精一杯頑張ります。よろしくお願いいたします。</p></article>
    </div>
  </div>
</section>
<section class="bg-white">
  <div class="wrap narrow">
    {sec_head("SUB STAFF", "サブスタッフ")}
    <div class="subs">
      <article><h3>渡邉 洋貴</h3><dl class="kv"><dt>担当メニュー</dt><dd>パーソナルトレーニング・マッサージ・整体（骨盤・骨格調整）</dd><dt>趣味・好きな事</dt><dd>ビール大好き・サッカー観戦</dd></dl></article>
      <article><h3>岡田 勝希</h3><dl class="kv"><dt>担当メニュー</dt><dd>パーソナルトレーニング・マッサージ・鍼灸</dd><dt>趣味・好きな事</dt><dd>筋トレ・映画鑑賞</dd></dl></article>
    </div>
    <p class="center-note">サブスタッフの写真は今の HP に無いため、見本では文字だけにしています。</p>
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# アクセス
# ---------------------------------------------------------------------------
def access_page() -> str:
    return page_hero("ACCESS", "アクセス・営業時間", "東急東横線・大井町線「自由が丘駅」から徒歩2分です。", IMG + "access_main.jpg") + f"""
<section class="pin-host">
  {pin(9)}
  <div class="wrap narrow">
    <dl class="kv kv--big">
      <dt>住所</dt><dd>{ADDRESS}</dd>
      <dt>アクセス</dt><dd>東急東横線 自由が丘駅 徒歩2分<br>東急大井町線 自由が丘駅 徒歩2分</dd>
      <dt>営業時間</dt><dd>{HOURS_WEEKDAY}<br>{HOURS_HOLIDAY}</dd>
      <dt>電話</dt><dd><a href="{TEL_LINK}">{TEL}</a></dd>
      <dt>予約方法</dt><dd>WEB予約 または 電話予約</dd>
      <dt>サービス</dt><dd>パーソナルトレーニング、整体、鍼灸、マッサージ</dd>
      <dt>駐車場</dt><dd>なし（お車でお越しの方は、近隣のコインパーキングをご利用ください）</dd>
      <dt>駐輪場</dt><dd>なし（自転車でお越しの方は、近隣の駐輪場をご利用ください）</dd>
    </dl>
    <p class="center"><a class="btn btn--ghost" href="{MAP}" target="_blank" rel="noopener">Googleマップで開く</a></p>
  </div>
</section>
<section class="bg-white">
  <div class="wrap split">
    <div>
      <span class="en tag-en">GREETING</span>
      <h2 class="h2-left">自由が丘店について</h2>
      <p class="lead-left">ARK Conditioning Laboratory 自由が丘店は、2021年9月2日にオープンしました。</p>
      <p class="lead-left">トレーニングスペースが広く、同時に3〜5名のトレーニングが行えます。マルチラックも2台、更衣室2つとシャワールームも完備していて、施術スペースは個室が2部屋あります。</p>
    </div>
    <img class="split__img" src="{IMG}home/DSC03361.jpeg" alt="トレーニングスペース">
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# ブログ（タイトル・日付・カテゴリは今の HP のブログ一覧から）
# ---------------------------------------------------------------------------
BLOG = [
    ("2026.09.23", "お知らせ", "自律神経について②", "https://ark-conditioning.com/news/3224"),
    ("2026.09.16", "お知らせ", "自律神経について①", "https://ark-conditioning.com/news/3220"),
    ("2026.09.05", "パーソナルトレーニング", "パフォーマンスを上げるための原理原則", "https://ark-conditioning.com/staff/shigeki/3212"),
    ("2026.08.16", "パーソナルトレーニング", "身体を動かし続けることで", "https://ark-conditioning.com/menu/training/3199"),
    ("2026.08.09", "お知らせ", "夏の水分補給、できていますか？", "https://ark-conditioning.com/news/3194"),
    ("2026.07.11", "パーソナルトレーニング", "体を自由に動かす！", "https://ark-conditioning.com/menu/training/3143"),
    ("2026.06.30", "お知らせ", "猫背は猫になって改善する２", "https://ark-conditioning.com/blog"),
    ("2026.06.28", "お知らせ", "猫背は猫(Cat)になって改善する", "https://ark-conditioning.com/blog"),
    ("2026.06.17", "マッサージ", "マッサージの効果", "https://ark-conditioning.com/menu/massage/3131"),
    ("2026.05.02", "お知らせ", "鍼灸って何するの？", "https://ark-conditioning.com/blog"),
    ("2026.03.27", "店舗", "ARKの紹介！", "https://ark-conditioning.com/menu/3117"),
    ("2026.03.26", "お知らせ", "下っ腹が痩せないのは姿勢のせい？", "https://ark-conditioning.com/blog"),
    ("2026.03.24", "お知らせ", "このくらい大丈夫…。その頭痛、我慢していませんか", "https://ark-conditioning.com/blog"),
]


def blog_page() -> str:
    cats = ["すべて"] + sorted({c for _, c, _, _ in BLOG}, key=lambda c: [b[1] for b in BLOG].index(c))
    chips = "".join(f'<button type="button" class="chip{" is-on" if i == 0 else ""}" data-cat="{esc(c)}">{esc(c)}</button>' for i, c in enumerate(cats))
    items = "".join(
        f'<li data-cat="{esc(c)}"><a href="{u}" target="_blank" rel="noopener"><time>{d}</time><span class="cat">{esc(c)}</span><span class="ttl">{esc(t)}</span></a></li>'
        for d, c, t, u in BLOG
    )
    return page_hero("BLOG", "お知らせ・ブログ", "身体のこと、トレーニングのこと、お店のお知らせをお届けします。") + f"""
<section class="pin-host">
  {pin(7)}
  <div class="wrap narrow">
    <div class="chips" role="group" aria-label="カテゴリで絞り込む">{chips}</div>
    <ul class="bloglist">{items}</ul>
    <p class="center-note">見本では記事のリンク先を今の HP にしています（新しいタブで開きます）。</p>
  </div>
</section>
{cta_band()}"""


# ---------------------------------------------------------------------------
# 求人
# ---------------------------------------------------------------------------
def recruit_page() -> str:
    return page_hero("RECRUIT", "スタッフ募集", "ジムと整体が融合した施設で働きませんか？ トレーニングと整体が一緒に受けられるのが、アークコンディショニングラボラトリーの魅力です。", IMG + "home/DSC02615.jpg") + f"""
<section class="pin-host">
  {pin(8)}
  <div class="wrap narrow">
    <ul class="checklist checklist--plain"><li>常勤で働ける方を募集しています</li><li>未経験でも大歓迎</li><li>2021年9月オープンの店舗で、整体・パーソナルトレーニングでお客様の健康をサポートします</li></ul>
    {sec_head("REQUIREMENTS", "募集内容")}
    <dl class="kv kv--big">
      <dt>募集職種</dt><dd>柔道整復師・鍼灸師・あん摩マッサージ指圧師・整体師・パーソナルトレーナー</dd>
      <dt>給与</dt><dd>【正職員】月給 230,000円〜（交通費別途支給）<br>※試用期間6か月・条件変更なし</dd>
      <dt>待遇</dt><dd>社会保険完備、交通費支給、雇用保険、労災保険、健康保険、厚生年金、研修制度有り、制服貸与</dd>
      <dt>勤務時間</dt><dd>10:00〜21:00の間でシフト制（実働8時間・休憩60分）</dd>
      <dt>休日</dt><dd>週休2日、シフト制</dd>
      <dt>応募要件</dt><dd>柔道整復師・鍼灸師・あん摩マッサージ指圧師・整体師・パーソナルトレーナー</dd>
    </dl>
    {sec_head("PROCESS", "選考の流れ", "応募から内定までは平均1週間〜1ヶ月ほどです。在職中で今すぐ転職が難しい方も、調整のご相談が可能です。")}
    <ol class="flow flow--5">
      <li><b>1</b><h3>応募</h3><p>応募フォームまたはお電話で</p></li>
      <li><b>2</b><h3>書類選考</h3><p>書類選考を実施します</p></li>
      <li><b>3</b><h3>日程調整</h3><p>採用担当よりご連絡します</p></li>
      <li><b>4</b><h3>面接</h3><p>面接を実施します</p></li>
      <li><b>5</b><h3>採用決定</h3><p>結果をご連絡します</p></li>
    </ol>
    <p class="center"><a class="btn btn--primary" href="{TEL_LINK}">電話で応募する</a> <a class="btn btn--ghost" href="contact.html">お問い合わせ</a></p>
  </div>
</section>"""


# ---------------------------------------------------------------------------
# お問い合わせ
# ---------------------------------------------------------------------------
def contact_page() -> str:
    return page_hero("CONTACT", "お問い合わせ", "ARK Conditioning Laboratory にご関心をお持ちいただき、ありがとうございます。ご意見・ご相談など、お気軽にお問い合わせください。") + f"""
<section class="pin-host">
  {pin(10)}
  <div class="wrap">
    <div class="contact-cards">
      <article><h3>WEB予約</h3><p>空き状況を見て、そのままご予約いただけます。</p><a class="btn btn--primary" href="{BOOKING}">空き状況を見る</a></article>
      <article><h3>LINEでお問い合わせ</h3><p>ARK 公式LINE からお気軽にどうぞ。</p><a class="btn btn--ghost" href="{LINE}" target="_blank" rel="noopener">ARK 公式LINE</a></article>
      <article><h3>お電話でのお問い合わせ</h3><p><a class="big-tel" href="{TEL_LINK}">{TEL}</a><br>{HOURS_WEEKDAY}<br>{HOURS_HOLIDAY}</p></article>
    </div>
  </div>
</section>"""


# ---------------------------------------------------------------------------
# トップ（これまでの index.html の本文をもとに、各ページへの導線を足した版）
# ---------------------------------------------------------------------------
def index_page() -> str:
    body = (Path(__file__).resolve().parent / "sample_index_body.html").read_text(encoding="utf-8")
    return body


PAGES = {
    "index.html": ("トップ", index_page, "自由が丘駅徒歩2分。整体・鍼灸 × パーソナルトレーニング。"),
    "visitor.html": ("はじめての方へ", visitor_page, "はじめての方へ。当日の流れ、お客様の声、よくある質問。"),
    "menu.html": ("メニュー", menu_index, "カラダチェック・整体マッサージ・鍼灸・パーソナルトレーニングほか。"),
    "price.html": ("料金", price_page, "基本料金・回数券・月額コース・ジュニア/シニア・ダイエット・ペア。"),
    "staff.html": ("スタッフ", staff_page, "スタッフ紹介。"),
    "access.html": ("アクセス・営業時間", access_page, "自由が丘駅徒歩2分。営業時間・設備。"),
    "blog.html": ("お知らせ・ブログ", blog_page, "お知らせ・ブログ。"),
    "recruit.html": ("スタッフ募集", recruit_page, "柔道整復師・鍼灸師・あん摩マッサージ指圧師・整体師・パーソナルトレーナー募集。"),
    "contact.html": ("お問い合わせ", contact_page, "WEB予約・LINE・電話でのお問い合わせ。"),
}


def main() -> None:
    for m in MENUS:
        PAGES[m["file"]] = (m["name"], (lambda m=m: menu_detail(m)), m["summary"])
    for filename, (title, fn, desc) in PAGES.items():
        (OUT / filename).write_text(layout(filename, title, fn(), description=desc), encoding="utf-8")
    print(f"generated {len(PAGES)} pages into {OUT}")


if __name__ == "__main__":
    main()
