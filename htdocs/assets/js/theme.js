(function(){
  const KEY='viva-theme';
  function getTheme(){
    try { return localStorage.getItem(KEY)==='dark' ? 'dark' : 'light'; } catch(e){ return 'light'; }
  }
  function applyTheme(theme){
    const t=theme==='dark'?'dark':'light';
    document.documentElement.setAttribute('data-theme',t);
    try{localStorage.setItem(KEY,t);}catch(e){}
    const btn=document.getElementById('themeToggle');
    if(btn){
      btn.setAttribute('aria-label', t==='dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
      btn.title=t==='dark' ? 'Modo claro' : 'Modo oscuro';
      btn.innerHTML=t==='dark'
        ? '<i class="bi bi-sun-fill"></i><span>Claro</span>'
        : '<i class="bi bi-moon-stars-fill"></i><span>Oscuro</span>';
    }
  }
  window.toggleVivaTheme=function(){ applyTheme(getTheme()==='dark'?'light':'dark'); };
  window.applyVivaTheme=applyTheme;
  // Apply immediately to avoid a flash of the light theme.
  applyTheme(getTheme());
  // The toggle button is rendered later in the body. Refresh its label once the DOM exists.
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>applyTheme(getTheme()),{once:true});
  else applyTheme(getTheme());
})();
