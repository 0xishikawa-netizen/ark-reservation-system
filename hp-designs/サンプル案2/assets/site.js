document.documentElement.classList.add('js');
const menuToggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('#site-nav');
function closeMenu() {
  menuToggle?.setAttribute('aria-expanded', 'false');
  nav?.classList.remove('is-open');
}
menuToggle?.addEventListener('click', () => {
  const open = menuToggle.getAttribute('aria-expanded') !== 'true';
  menuToggle.setAttribute('aria-expanded', String(open));
  nav?.classList.toggle('is-open', open);
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && menuToggle?.getAttribute('aria-expanded') === 'true') {
    closeMenu();
    menuToggle.focus();
  }
});
nav?.addEventListener('click', (event) => {
  if (event.target.closest('a')) closeMenu();
});
document.querySelectorAll('a[href="#design-note"]').forEach(link => {
  link.addEventListener('click', () => {
    const note = document.querySelector('#design-note');
    if (note) note.open = true;
  });
});
const filters = document.querySelectorAll('[data-filter]');
const search = document.querySelector('#article-search');
const articles = [...document.querySelectorAll('[data-category]')];
let category = 'すべて';
function filterArticles() {
  const term = (search?.value || '').trim().toLocaleLowerCase('ja');
  let count = 0;
  articles.forEach(article => {
    const matches = (category === 'すべて' || article.dataset.category === category) && article.textContent.toLocaleLowerCase('ja').includes(term);
    article.hidden = !matches;
    if (matches) count++;
  });
  const counter = document.querySelector('#article-count');
  if (counter) counter.textContent = `${count}件の記事`;
  const empty = document.querySelector('#article-empty');
  if (empty) empty.hidden = count !== 0;
}
filters.forEach(button => button.addEventListener('click', () => {
  category = button.dataset.filter;
  filters.forEach(filter => filter.setAttribute('aria-pressed', String(filter === button)));
  filterArticles();
}));
search?.addEventListener('input', filterArticles);
document.querySelector('#reset-search')?.addEventListener('click', () => {
  search.value = '';
  category = 'すべて';
  filters.forEach(filter => filter.setAttribute('aria-pressed', String(filter.dataset.filter === category)));
  filterArticles();
  search.focus();
});
