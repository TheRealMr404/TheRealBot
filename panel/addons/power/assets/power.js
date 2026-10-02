(function(){
  'use strict';
  var shell=document.querySelector('.power-shell');
  if(!shell)return;

  function faNum(n){return new Intl.NumberFormat('fa-IR',{maximumFractionDigits:0}).format(Number(n)||0)}
  function money(n){return faNum(n)+' ت'}

  document.querySelectorAll('.power-chart').forEach(function(chart){
    var data=[];
    try{data=JSON.parse(chart.getAttribute('data-chart')||'[]')}catch(e){}
    if(!data.length){chart.innerHTML='<div class="power-chart-empty">داده‌ای برای نمودار موجود نیست.</div>';return}
    var max=Math.max.apply(null,data.map(function(x){return Number(x.value)||0}).concat([1]));
    var kind=chart.getAttribute('data-kind');
    data.forEach(function(item){
      var bar=document.createElement('div');
      bar.className='power-chart-bar';
      bar.style.setProperty('--h',Math.max(2,(Number(item.value)||0)/max*100)+'%');
      var tip=document.createElement('i');
      tip.textContent=item.date+' · '+(kind==='money'?money(item.value):faNum(item.value));
      bar.appendChild(tip);chart.appendChild(bar);
    });
  });

  document.querySelectorAll('.power-donut').forEach(function(el){
    var good=Number(el.getAttribute('data-good'))||0,bad=Number(el.getAttribute('data-bad'))||0;
    el.style.setProperty('--good',good+bad?good/(good+bad)*100:0);
  });

  var privacyButton=document.querySelector('[data-privacy-toggle]');
  function setPrivacy(on){shell.setAttribute('data-privacy',on?'1':'0');sessionStorage.setItem('power-privacy',on?'1':'0');if(privacyButton)privacyButton.classList.toggle('btn-primary',on)}
  if(sessionStorage.getItem('power-privacy')!==null)setPrivacy(sessionStorage.getItem('power-privacy')==='1');
  if(privacyButton)privacyButton.addEventListener('click',function(){setPrivacy(shell.getAttribute('data-privacy')!=='1')});

  document.querySelectorAll('form[data-confirm]').forEach(function(form){
    form.addEventListener('submit',function(e){
      if(!window.confirm(form.getAttribute('data-confirm')||'ادامه می‌دهید؟'))e.preventDefault();
    });
  });

  document.addEventListener('keydown',function(e){
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){
      e.preventDefault();window.location.href='power.php?section=search';
    }
    if((e.ctrlKey||e.metaKey)&&e.shiftKey&&e.key.toLowerCase()==='p'){
      e.preventDefault();setPrivacy(shell.getAttribute('data-privacy')!=='1');
    }
  });
})();
