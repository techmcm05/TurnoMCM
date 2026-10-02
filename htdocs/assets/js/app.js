document.addEventListener('click', e => {
  const btn=e.target.closest('[data-confirm]');
  if(btn && !confirm(btn.dataset.confirm)) e.preventDefault();
});
