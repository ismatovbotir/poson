// Poson template — minimal UI behaviour (replace with Alpine/Livewire in Laravel if needed)
document.addEventListener('DOMContentLoaded', () => {
  // Favorite toggle
  document.querySelectorAll('.fav').forEach(b => b.addEventListener('click', e => {
    e.preventDefault(); b.classList.toggle('is-on');
  }));

  // Mobile filters
  const ft = document.querySelector('.filter-toggle'), fl = document.querySelector('.filters');
  if (ft && fl) ft.addEventListener('click', () => fl.classList.toggle('is-open'));

  // Product tabs
  const tabs = document.querySelectorAll('.tab');
  tabs.forEach(t => t.addEventListener('click', e => {
    e.preventDefault();
    tabs.forEach(x => x.classList.remove('is-active'));
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('is-active'));
    t.classList.add('is-active');
    document.querySelector(t.dataset.target).classList.add('is-active');
  }));

  // Gallery thumbs
  document.querySelectorAll('.thumb').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.thumb').forEach(x => x.classList.remove('is-active'));
    t.classList.add('is-active');
    const stage = document.querySelector('.stage svg');
    if (stage) stage.innerHTML = '<use href="#' + t.dataset.img + '"/>';
  }));

  // Quantity
  const qi = document.querySelector('.qty input');
  document.querySelectorAll('.qty button').forEach(b => b.addEventListener('click', () => {
    const v = Math.max(1, (parseInt(qi.value) || 1) + (b.dataset.d === '+' ? 1 : -1));
    qi.value = v; updateTier(v);
  }));
  if (qi) qi.addEventListener('input', () => updateTier(parseInt(qi.value) || 1));
  function updateTier(q) {
    const el = document.querySelector('[data-total]');
    if (!el) return;
    const unit = q >= 50 ? 1090000 : q >= 10 ? 1160000 : 1250000;
    el.textContent = (unit * q).toLocaleString('ru-RU').replace(/,/g, ' ') + ' сум';
  }
});
