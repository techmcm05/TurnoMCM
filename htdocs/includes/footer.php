</main></div>
<footer class="app-footer">VIVA TURNOS · Gestión de atención al cliente</footer>
<script>
(function(){
  const SESSION_URL='<?=app_url('api/session.php')?>';
  const LOGIN_URL='<?=app_url('index.php')?>';
  const IS_MONITOR=<?=user()['rol']==='MONITOR'?'true':'false'?>;
  const IDLE_LIMIT=3*60*60*1000;
  let lastInteraction=Date.now(), lastSent=0;
  function goLogin(reason){ window.location.href=LOGIN_URL+'?'+reason+'=1'; }
  function heartbeat(force=false){
    if(!force && !IS_MONITOR && Date.now()-lastSent<10000) return;
    lastSent=Date.now();
    fetch(SESSION_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'touch',csrf:'<?=e(csrf_token())?>'}),credentials:'same-origin',cache:'no-store'})
      .then(async r=>{if(r.status===401){const d=await r.json().catch(()=>({}));if(d.replaced) goLogin('replaced'); else if(d.timeout) goLogin('timeout');}}).catch(()=>{});
  }
  function markActivity(){lastInteraction=Date.now();heartbeat(false);}
  ['click','keydown','mousemove','scroll','touchstart'].forEach(ev=>window.addEventListener(ev,markActivity,{passive:true}));
  setInterval(()=>{
    if(!IS_MONITOR && Date.now()-lastInteraction>=IDLE_LIMIT){goLogin('timeout');return;}
    heartbeat(IS_MONITOR);
  },10000);
})();
</script>
<script src="<?=app_url('assets/js/app.js')?>"></script>
</body></html>
