const panel = document.getElementById('quick-panel');
let previousFocus;
function openQuickPanel(){previousFocus=document.activeElement;panel.inert=false;panel.classList.add('open');panel.setAttribute('aria-hidden','false');document.body.classList.add('panel-active');panel.querySelector('button').focus();}
function closeQuickPanel(){panel.classList.remove('open');panel.setAttribute('aria-hidden','true');panel.inert=true;document.body.classList.remove('panel-active');previousFocus?.focus();}
function openPortal(type){if(type==='familia'){location.hash='agenda';return;}location.href='admin.php';}
function openPortalFromPanel(type){closeQuickPanel();openPortal(type);}
function closePortal(){document.getElementById('modal').classList.remove('show');document.getElementById('modal').setAttribute('aria-hidden','true');}
function showToast(message){const toast=document.getElementById('toast');toast.textContent=message;toast.classList.add('show');clearTimeout(window.toastTimer);window.toastTimer=setTimeout(()=>toast.classList.remove('show'),5000);}
function openGallery(){const box=document.getElementById('modal');box.classList.add('show');box.setAttribute('aria-hidden','false');box.querySelector('.close').focus();}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeQuickPanel();closePortal();}if(e.key==='Tab' && panel.classList.contains('open')){const els=[...panel.querySelectorAll('a,button')];if(e.shiftKey&&document.activeElement===els[0]){e.preventDefault();els.at(-1).focus();}else if(!e.shiftKey&&document.activeElement===els.at(-1)){e.preventDefault();els[0].focus();}}});
document.getElementById('modal').addEventListener('click',e=>{if(e.target.id==='modal')closePortal();});
panel.inert=true;
const el=(tag,text,className)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(className)n.className=className;return n;};
const dateKey=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
const today=new Date();const calendarYear=today.getFullYear();let calendarMonth=today.getMonth(),selectedDate=dateKey(today);
let publicData={classes:[],events:[],reminders:[],contact:null},loadState='loading';
function itemsForDay(key){const day=new Date(key+'T12:00:00').getDay();return [
  ...publicData.classes.filter(c=>Number(c.weekday)===day&&key>=c.start_date&&key<=c.end_date).map(c=>({...c,type:'Aula'})),
  ...publicData.events.filter(e=>e.starts.slice(0,10)===key||e.second_date===key).map(e=>({...e,time:e.second_date===key?(e.second_time||''):e.starts.slice(11,16),type:'Evento'})),
  ...publicData.reminders.filter(r=>r.date===key).map(r=>({...r,time:'',type:'Lembrete'}))
].sort((a,b)=>(a.time||'').localeCompare(b.time||''));}
function renderCalendar(){
  const date=new Date(calendarYear,calendarMonth,1);
  document.getElementById('calendar-month').textContent=date.toLocaleDateString('pt-BR',{month:'long',year:'numeric'});
  document.getElementById('calendar-prev').disabled=calendarMonth===0;document.getElementById('calendar-next').disabled=calendarMonth===11;
  const grid=document.getElementById('calendar-grid');grid.replaceChildren();
  ['DOM','SEG','TER','QUA','QUI','SEX','SÁB'].forEach(d=>grid.append(el('span',d,'calendar-weekday')));
  for(let i=0;i<date.getDay();i++)grid.append(el('span'));
  for(let day=1;day<=new Date(calendarYear,calendarMonth+1,0).getDate();day++){
    const current=new Date(calendarYear,calendarMonth,day),key=dateKey(current),items=itemsForDay(key);
    const button=el('button',String(day),'calendar-day'+(key===selectedDate?' selected':'')+(key===dateKey(today)?' today':''));
    button.type='button';button.setAttribute('aria-pressed',String(key===selectedDate));button.setAttribute('aria-label',current.toLocaleDateString('pt-BR',{day:'numeric',month:'long',year:'numeric'})+(items.length?`, ${items.length} atividades`:''));
    if(items.length)button.append(el('span','•','calendar-dot'));
    button.addEventListener('click',()=>{selectedDate=key;renderCalendar();});grid.append(button);
  }
  const list=document.getElementById('calendar-items');list.replaceChildren();
  list.append(el('h3',new Date(selectedDate+'T12:00:00').toLocaleDateString('pt-BR',{weekday:'long',day:'numeric',month:'long'})));
  const items=itemsForDay(selectedDate);
  if(!items.length)list.append(el('p',loadState==='loading'?'Carregando agenda…':loadState==='error'?'Não foi possível carregar a agenda. Atualize a página para tentar novamente.':'Nenhuma atividade publicada para este dia.','empty-copy'));
  items.forEach(item=>{const article=el('article');article.append(el('time',item.time||'—'));const body=el('div');body.append(el('p',item.title),el('small',item.location||item.description||''));article.append(body,el('span',item.type,'class-tag'));list.append(article);});
}
function changeMonth(delta){const next=calendarMonth+delta;if(next<0||next>11)return;calendarMonth=next;selectedDate=calendarMonth===today.getMonth()?dateKey(today):dateKey(new Date(calendarYear,calendarMonth,1));renderCalendar();}
document.getElementById('calendar-prev').addEventListener('click',()=>changeMonth(-1));
document.getElementById('calendar-next').addEventListener('click',()=>changeMonth(1));
document.getElementById('calendar-today').addEventListener('click',()=>{calendarMonth=today.getMonth();selectedDate=dateKey(today);renderCalendar();});
function renderReminders(){const list=document.getElementById('reminder-list');list.replaceChildren();const items=publicData.reminders.filter(r=>!r.date||r.date>=dateKey(today));if(!items.length)list.append(el('p','Os próximos lembretes do estúdio aparecerão aqui.','empty-copy'));items.forEach(r=>{const article=el('article'),date=el('span',r.date?r.date.slice(8,10):'✦','date');if(r.date){date.append(document.createElement('br'),el('small',new Date(r.date+'T12:00:00').toLocaleDateString('pt-BR',{month:'short'})));}const body=el('div');body.append(el('p',r.title),el('small',r.description));article.append(date,body);list.append(article);});}
function safeLink(url){try{return new URL(url).protocol==='https:';}catch{return false;}}
function renderTickets(){const list=document.getElementById('event-list');list.replaceChildren();const events=publicData.events.filter(e=>!e.starts||(e.second_date||e.starts.slice(0,10))>=dateKey(today));if(!events.length){list.append(el('p',loadState==='error'?'Não foi possível carregar os eventos. Tente novamente mais tarde.':'A programação dos próximos espetáculos será divulgada aqui.','empty-copy'));return;}events.forEach(e=>{const card=el('article',undefined,'public-event');card.append(el('h3',e.title));if(e.description)card.append(el('p',e.description));const info=e.starts?new Date(e.starts.includes('T')?e.starts:e.starts+'T12:00:00').toLocaleDateString('pt-BR',{day:'numeric',month:'long',year:'numeric'})+(e.starts.includes('T')?' · '+e.starts.slice(11,16):''):'Data a definir';const second=e.second_date?' e '+new Date(e.second_date+'T12:00:00').toLocaleDateString('pt-BR',{day:'numeric',month:'long',year:'numeric'})+(e.second_time?' · '+e.second_time:''):'';card.append(el('p',info+second+(e.location?' · '+e.location:''),'event-details'));card.append(el('p','Cada ingresso vale somente para o dia escolhido. Para os dois dias, faça uma compra para cada dia.','ticket-status'));if(e.price!=='')card.append(el('p',Number(e.price).toLocaleString('pt-BR',{style:'currency',currency:'BRL'}),'event-price'));if(e.ticket_state==='pix'&&e.price!==''){const link=el('a','Escolher dia e quantidade →','button primary');link.href='admin.php?ticket='+encodeURIComponent(e.id);card.append(link);}else if(e.ticket_state==='link'&&e.price!==''&&safeLink(e.ticket_url)){const link=el('a','Comprar ingressos ↗','button primary');link.href=e.ticket_url;link.rel='noopener noreferrer';link.target='_blank';card.append(link);}else card.append(el('span','Ingressos ainda não disponíveis','ticket-status'));list.append(card);});}
function renderContact(){const list=document.getElementById('contact-details');list.replaceChildren();const c=publicData.contact;if(!c){list.append(el('p','Consulte a recepção do estúdio para informações.'));return;}if(c.phone){const digits=c.phone.replace(/\D/g,'');if(digits.length>=10){const link=el('a','WhatsApp · '+c.phone,'button primary');link.href='https://wa.me/'+(digits.length<=11?'55':'')+digits;link.target='_blank';link.rel='noopener noreferrer';list.append(link);}}if(c.email){const link=el('a',c.email);link.href='mailto:'+c.email;list.append(link);}if(c.instagram&&safeLink(c.instagram)){const link=el('a','Instagram ↗');link.href=c.instagram;link.target='_blank';link.rel='noopener noreferrer';list.append(link);}}
let galleryPhotos=[],galleryIndex=0,galleryTrigger=null,galleryOverflow='';
const photoDialog=el('dialog',undefined,'photo-viewer');photoDialog.setAttribute('aria-label','Galeria de fotos');
const photoTop=el('div',undefined,'photo-viewer-top'),photoClose=el('button','← Voltar à galeria','photo-close'),photoCount=el('span');
photoClose.type='button';photoCount.setAttribute('role','status');photoTop.append(photoClose,photoCount);
const photoStage=el('div',undefined,'photo-stage'),photoImage=el('img'),photoError=el('p');
photoError.setAttribute('role','status');photoImage.draggable=false;photoStage.append(photoImage,photoError);
const photoCaption=el('p',undefined,'photo-caption'),photoNav=el('div',undefined,'photo-nav');
const photoPrev=el('button','← Anterior'),photoNext=el('button','Próxima →');photoPrev.type=photoNext.type='button';photoNav.append(photoPrev,photoNext);
photoDialog.append(photoTop,photoStage,photoCaption,photoNav);document.body.append(photoDialog);
function showUploadedPhoto(index){
  if(!galleryPhotos.length)return;galleryIndex=(index+galleryPhotos.length)%galleryPhotos.length;
  const item=galleryPhotos[galleryIndex];photoError.textContent='';photoImage.hidden=false;
  photoImage.src='admin.php?photo='+item.id;photoImage.alt=item.caption||'Foto do estúdio';
  photoCaption.textContent=item.caption||'';photoCount.textContent=`Foto ${galleryIndex+1} de ${galleryPhotos.length}`;
  photoPrev.disabled=photoNext.disabled=galleryPhotos.length<2;
}
function openUploadedPhoto(index,trigger){
  galleryTrigger=trigger;showUploadedPhoto(index);galleryOverflow=document.body.style.overflow;
  photoDialog.showModal();document.body.style.overflow='hidden';photoClose.focus();
}
photoImage.addEventListener('error',()=>{photoImage.hidden=true;photoError.textContent='Não foi possível carregar esta foto. Tente a próxima ou volte à galeria.';});
photoClose.addEventListener('click',()=>photoDialog.close());
photoDialog.addEventListener('close',()=>{document.body.style.overflow=galleryOverflow;galleryTrigger?.focus({preventScroll:true});});
photoPrev.addEventListener('click',()=>showUploadedPhoto(galleryIndex-1));photoNext.addEventListener('click',()=>showUploadedPhoto(galleryIndex+1));
photoDialog.addEventListener('keydown',e=>{
  if(e.key==='Escape')e.stopPropagation();
  if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();showUploadedPhoto(galleryIndex+(e.key==='ArrowLeft'?-1:1));}
});
let photoTouch=null;
photoStage.addEventListener('touchstart',e=>{photoTouch=e.touches.length===1?{x:e.touches[0].clientX,y:e.touches[0].clientY}:null;},{passive:true});
photoStage.addEventListener('touchcancel',()=>{photoTouch=null;});
photoStage.addEventListener('touchend',e=>{
  if(!photoTouch||e.touches.length||!e.changedTouches.length)return;
  const dx=e.changedTouches[0].clientX-photoTouch.x,dy=e.changedTouches[0].clientY-photoTouch.y;photoTouch=null;
  if(Math.abs(dx)>55&&Math.abs(dx)>Math.abs(dy)*1.5)showUploadedPhoto(galleryIndex+(dx<0?1:-1));
},{passive:true});
function renderUploadedGallery(){
  const list=document.getElementById('uploaded-gallery');if(!list)return;list.replaceChildren();
  galleryPhotos=(publicData.gallery||[]).filter(item=>Number.isInteger(item.id)&&item.id>0);
  galleryPhotos.forEach((item,index)=>{
    const figure=el('figure'),button=el('button',undefined,'gallery-thumbnail'),img=el('img');
    button.type='button';button.setAttribute('aria-label','Abrir foto '+(index+1)+': '+(item.caption||'Foto do estúdio'));
    img.src='admin.php?photo='+item.id;img.alt=item.caption||'Foto do estúdio';img.loading='lazy';button.append(img);
    button.addEventListener('click',()=>openUploadedPhoto(index,button));figure.append(button,el('figcaption',item.caption||''));list.append(figure);
  });
}
renderCalendar();
// Portfolio preview: fictional data only; never queries the production server.
const demoYear=today.getFullYear();
const demoDay=dateKey(new Date(demoYear,today.getMonth(),Math.min(today.getDate()+3,28)));
publicData={
  classes:[{title:'Ballet iniciante — turma demonstrativa',weekday:1,time:'18:00',start_date:`${demoYear}-01-01`,end_date:`${demoYear}-12-31`}],
  events:[{id:1,title:'Movimento — apresentação demonstrativa',starts:`${demoYear}-12-20T18:30`,second_date:`${demoYear}-12-21`,second_time:'18:30',location:'Teatro fictício',price:'36.00',ticket_state:'closed',description:'Exemplo de evento com escolha de data. Não há venda nesta demonstração.'}],
  reminders:[{title:'Ensaio aberto — exemplo',date:demoDay,description:'Recado fictício para demonstrar a comunicação com as famílias.'}],
  gallery:[],contact:{email:'contato@example.invalid'}
};
loadState='ready';renderCalendar();renderReminders();renderTickets();renderContact();renderUploadedGallery();
