// サンプル案 共通スクリプト（tools/hp-designs/build_sample.py の生成ページで使う）
(function () {
  // スマホ用メニュー
  var menuBtn = document.querySelector('.hdr__menu');
  var spnav = document.getElementById('spnav');
  if (menuBtn && spnav) {
    menuBtn.addEventListener('click', function () {
      var open = spnav.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // 改善ポイントのパネル（見本確認用）
  var panel = document.getElementById('notes');
  var toggle = document.querySelector('.notes-toggle');
  if (panel && toggle) {
    var open = function (n) {
      panel.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      if (n) {
        var item = panel.querySelectorAll('ol li')[n - 1];
        if (item) {
          item.scrollIntoView({ block: 'center' });
          item.style.background = '#fff4ef';
          setTimeout(function () { item.style.background = ''; }, 1600);
        }
      }
    };
    var close = function () { panel.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); };
    toggle.addEventListener('click', function () { panel.classList.contains('open') ? close() : open(); });
    panel.querySelector('.notes__close').addEventListener('click', close);
    document.querySelectorAll('.pin').forEach(function (p) { p.addEventListener('click', function () { open(Number(p.dataset.note)); }); });
    document.getElementById('pinSwitch').addEventListener('change', function (e) { document.body.classList.toggle('hide-pins', !e.target.checked); });
  }

  // ブログのカテゴリ絞り込み
  document.querySelectorAll('.chips .chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var cat = chip.dataset.cat;
      document.querySelectorAll('.chips .chip').forEach(function (c) { c.classList.toggle('is-on', c === chip); });
      document.querySelectorAll('.bloglist li').forEach(function (li) { li.hidden = cat !== 'すべて' && li.dataset.cat !== cat; });
    });
  });
})();
