// hide.js - ID / トリップの非表示 (ブラウザの localStorage だけで動く。サーバーは何もしない)
(() => {
  const K = 'bbs_hide', TTL = 3 * 864e5, now = Date.now();
  let H = {};
  try { H = JSON.parse(localStorage.getItem(K) || '{}'); } catch { H = {}; }
  for (const k in H) if (k.startsWith('id:') && now - H[k] > TTL) delete H[k];   // ID は日替わりなので3日で捨てる
  const save = () => { try { localStorage.setItem(K, JSON.stringify(H)); } catch {} };

  const metas = [...document.querySelectorAll('.meta[data-id]')];
  for (const m of metas) {
    const s = document.createElement('span');
    s.className = 'hidebtns';
    for (const [label, key] of [['ID', 'id:' + m.dataset.id], ['トリップ', m.dataset.trip && 'trip:' + m.dataset.trip]]) {
      if (!key) continue;
      const a = document.createElement('a');
      a.href = '#'; a.dataset.hide = key; a.dataset.label = label;
      s.append(' ', a);
    }
    m.after(s);
  }

  function apply() {
    for (const m of metas) {
      const dl = m.closest('dl');
      if (dl) dl.classList.toggle('abone', !!(H['id:' + m.dataset.id] || (m.dataset.trip && H['trip:' + m.dataset.trip])));
    }
    document.querySelectorAll('a[data-hide]').forEach(a => {
      a.textContent = '[' + a.dataset.label + (H[a.dataset.hide] ? '解除' : '非表示') + ']';
    });
  }

  document.addEventListener('click', e => {
    const a = e.target.closest('a[data-hide]');
    if (!a) return;
    e.preventDefault();
    const k = a.dataset.hide;
    if (H[k]) delete H[k]; else H[k] = Date.now();
    save(); apply();
  });
  apply();
})();
