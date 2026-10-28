'use strict';
const $=s=>document.querySelector(s);
const names={flowwow:'Flowwow',avito:'Авито',manual:'Сайт'};
const sourceLabel=key=>{const k=Object.hasOwn(names,key)?key:'manual';return `<img class="source-icon" src="icons/${k}.${k==='manual'?'svg':'png'}" alt="" aria-hidden="true">${names[k]}`};
const categories={florarium:'Флорариум',composition:'Живая композиция',potted:'Комнатное растение',plant:'Растение для флорариума',material:'Материалы и декор'};
const productGroup=p=>p.catalogGroup||({succulent:'plant',ficus:'plant'}[p.category]||p.category)||'plant';
const statuses={ready:'В наличии',preorder:'Под заказ',sold:'Нет в наличии'};
const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB',minimumFractionDigits:0,maximumFractionDigits:2}).format(n);
const imageURL=path=>String(path).startsWith('https://')?path:'/'+String(path).replace(/^\/+/, '');
let connections={},reminderId='',reminderOpen=false,authenticated=false;
let csrf='',products=[],source='all',editing=null,images=[],dirty=false,uploading=false;
const errors={invalid_channels:'Проверьте настройки площадок и цены.',external_channel:'Существующее объявление пока управляется на своей площадке.',invalid_date:'Укажите корректную дату продления.',duplicate_source:'Товар с этой ссылкой Авито уже есть в каталоге. Найдите и отредактируйте его.',setup_required:'Вход ещё не настроен.',invalid_credentials:'Проверьте логин и пароль.',too_many_attempts:'Слишком много попыток. Попробуйте через 15 минут.',service_unavailable:'Не удалось связаться с каталогом. Попробуйте ещё раз.',invalid_product:'Проверьте название, цену и фотографии.',edit_conflict:'Товар уже изменился. Закройте карточку, обновите список и откройте её снова.',upload_failed:'Не удалось загрузить фото. Выберите JPEG, PNG или WebP.',invalid_image:'Этот формат фотографии не поддерживается. Сохраните её как JPEG или PNG.',image_too_large:'Фото слишком большое. Выберите изображение поменьше.',request_too_large:'Слишком много данных. Уменьшите описание.',https_required:'Откройте каталог по защищённому адресу https://.'};
function message(element,text='',error=false){element.textContent=text;element.classList.toggle('error',error)}
async function api(action,body,params={}){
 const options={credentials:'same-origin',cache:'no-store'};
 if(body!==undefined){options.method='POST';options.headers={'X-CSRF-Token':csrf};if(body instanceof FormData)options.body=body;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(body)}}
 let response;try{response=await fetch('api.php?action='+encodeURIComponent(action)+'&'+new URLSearchParams(params),options)}catch{throw Error('Нет соединения. Проверьте интернет и повторите.')}
 let result;try{result=await response.json()}catch{throw Error('Каталог временно недоступен. Попробуйте позже.')}
 if(!response.ok){if(result.error==='authentication_required'||result.error==='session_expired'){showLogin();throw Error('Войдите снова, чтобы продолжить.')}throw Error(errors[result.error]||'Не удалось выполнить действие. Попробуйте ещё раз.')}
 if(result.csrf)csrf=result.csrf;return result;
}
function showLogin(){authenticated=false;$('#catalog-view').hidden=true;$('#login-view').hidden=false;$('#logout').hidden=true;$('#editor').close();products=[]}
async function load(){const result=await api('products');products=result.products;connections=result.connections||{};renderConnections();render()}
async function showCatalog(){authenticated=true;window.scrollTo(0,0);$('#login-view').hidden=true;$('#catalog-view').hidden=false;$('#logout').hidden=false;await load();checkConnections()}
function render(){
 const query=$('#search').value.toLocaleLowerCase('ru-RU').replace(/ё/g,'е').trim();const visibility=$('#visibility').value;const category=$('#category').value;
 const items=products.filter(p=>(category==='all'||productGroup(p)===category)&&matchesChannel(p,source)&&(!query||(p.title+' '+p.description).toLocaleLowerCase('ru-RU').replace(/ё/g,'е').includes(query))&&(visibility==='all'||visibility==='hidden'&&(p.hidden||p.sourceMissing)||visibility==='visible'&&!p.hidden&&!p.sourceMissing||visibility==='ready'&&p.available||visibility==='sold'&&p.status==='sold'));
 $('#total').textContent=products.length;$('#empty').hidden=items.length!==0;
 $('#products').innerHTML=items.map(p=>`<button class="product ${p.source==='avito'?'source-avito':''} ${p.hidden||p.sourceMissing?'is-hidden':''}" data-id="${escape(p.id)}"><img src="${escape(imageURL(p.images?.[0]||'assets/logo-mark-roots.png'))}" alt="" loading="lazy">${p.hidden||p.sourceMissing?'<span class="hidden-badge">Скрыт</span>':''}<div class="product-body"><span class="publication-badges">${productBadges(p)}</span><h2>${escape(p.title)}</h2><span class="product-category">${escape(categories[productGroup(p)]||'Растение')}</span><span class="product-price">${money(p.price)}</span><span class="product-status">${escape(statuses[p.status]||(p.available?'В наличии':'Под заказ'))}</span></div></button>`).join('');
}
function renderPhotos(){
 $('#photo-count').textContent=images.length+' / 20';
 $('#photos').innerHTML=images.map((path,i)=>`<div class="photo"><img src="${escape(imageURL(path))}" alt="Фото ${i+1}">${editing?.source==='flowwow'?'':`<div class="photo-actions"><button type="button" data-cover="${i}" aria-label="Сделать фото ${i+1} обложкой" ${i===0?'disabled':''}>Обложка</button><button type="button" data-remove="${i}" aria-label="Убрать фото ${i+1}">×</button></div>`}</div>`).join('');
}
function openEditor(id){
 editing=products.find(p=>p.id===id)||null;const p=editing||{title:'',price:'',description:'',source:'manual',catalogGroup:$('#category').value==='all'?'plant':$('#category').value,status:'ready',url:'',images:[],hidden:false};
 const form=$('#edit-form');form.reset();for(const key of ['title','price','description','catalogGroup','status','url'])form.elements[key].value=p[key]??'';
 form.elements.catalogGroup.value=productGroup(p);$('#editor-category').textContent=categories[productGroup(p)]||'Растение';form.elements.inquiry.checked=p.purchaseMode==='inquiry';form.elements.visible.checked=!p.hidden;$('#product-fields').disabled=p.source==='flowwow';
 $('#editor-heading').textContent=editing?'Карточка товара':'Новый товар';$('#editor-source').innerHTML=productBadges(p);
 $('#flowwow-note').hidden=p.source!=='flowwow';$('#missing-note').hidden=!p.sourceMissing;images=[...(p.images||[])];renderPhotos();message($('#edit-message'));dirty=false;
 renderPublicationSettings(p);$('#publication-preview').hidden=true;form.elements.url.closest('label').hidden=p.source!=='avito';
 $('#source-url-label').textContent=p.source==='flowwow'?'Ссылка на Flowwow':'Ссылка на объявление Авито';$('#editor').showModal();$('#editor').scrollTop=0;document.body.style.overflow='hidden';
}
function closeEditor(){if(uploading)return;if(dirty&&!confirm('Закрыть карточку без сохранения?'))return;$('#editor').close()}
function toast(text){$('#toast').textContent=text;$('#toast').hidden=false;setTimeout(()=>{$('#toast').hidden=true},3000)}
$('#login-form').addEventListener('submit',async e=>{e.preventDefault();const button=e.target.querySelector('[type=submit]');button.disabled=true;message($('#login-message'));try{await api('login',{username:e.target.elements.username.value,password:e.target.elements.password.value});e.target.elements.password.value='';await showCatalog()}catch(error){message($('#login-message'),error.message,true)}finally{button.disabled=false}});
$('#reveal').addEventListener('click',()=>{const input=$('#login-form').elements.password;const reveal=input.type==='password';input.type=reveal?'text':'password';$('#reveal').textContent=reveal?'Скрыть':'Показать';$('#reveal').setAttribute('aria-label',reveal?'Скрыть пароль':'Показать пароль')});
$('#logout').addEventListener('click',async()=>{try{await api('logout',{});showLogin();await initialize()}catch(error){toast(error.message)}});
$('#add').addEventListener('click',()=>openEditor());$('#close-editor').addEventListener('click',closeEditor);
$('#editor').addEventListener('cancel',e=>{e.preventDefault();closeEditor()});$('#editor').addEventListener('close',()=>{document.body.style.overflow=''});
$('#edit-form').addEventListener('input',()=>{dirty=true});
$('#search').addEventListener('input',render);$('#category').addEventListener('change',render);
$('#edit-form').elements.catalogGroup.addEventListener('change',e=>{$('#editor-category').textContent=categories[e.target.value]});$('#visibility').addEventListener('change',render);
document.querySelectorAll('[data-source]').forEach(button=>button.addEventListener('click',()=>{source=button.dataset.source;document.querySelectorAll('[data-source]').forEach(b=>{b.classList.toggle('active',b===button);b.setAttribute('aria-pressed',String(b===button))});render()}));
$('#products').addEventListener('click',e=>{const item=e.target.closest('[data-id]');if(item)openEditor(item.dataset.id)});
$('#photos').addEventListener('click',e=>{const remove=e.target.closest('[data-remove]'),cover=e.target.closest('[data-cover]');if(remove){images.splice(Number(remove.dataset.remove),1);dirty=true;renderPhotos()}if(cover){images.unshift(...images.splice(Number(cover.dataset.cover),1));dirty=true;renderPhotos()}});
$('#reload').addEventListener('click',async()=>{try{message($('#list-message'));await load();toast('Каталог обновлён')}catch(error){message($('#list-message'),error.message,true)}});
async function preparePhoto(file){
 let bitmap;try{bitmap=await createImageBitmap(file,{imageOrientation:'from-image'})}catch{throw Error(errors.invalid_image)}
 const scale=Math.min(1,1800/Math.max(bitmap.width,bitmap.height));const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();
 const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.9));if(!blob)throw Error(errors.invalid_image);return blob;
}
$('#photo-input').addEventListener('change',async e=>{
 const files=[...e.target.files];if(images.length+files.length>20){message($('#edit-message'),'В карточке может быть до 20 фотографий.',true);e.target.value='';return}
 uploading=true;$('#save').disabled=true;$('#photo-input').disabled=true;
 try{for(let i=0;i<files.length;i++){message($('#edit-message'),`Загружаем фото ${i+1} из ${files.length}…`);const form=new FormData();form.append('image',await preparePhoto(files[i]),'photo.jpg');const result=await api('upload',form);images.push(result.path);dirty=true;renderPhotos()}message($('#edit-message'))}catch(error){message($('#edit-message'),error.message,true)}finally{uploading=false;$('#save').disabled=false;$('#photo-input').disabled=false;e.target.value=''}
});
$('#edit-form').addEventListener('submit',async e=>{
 e.preventDefault();if(uploading)return;const form=e.target;const payload={id:editing?.id||'',version:editing?.version||0,hidden:!form.elements.visible.checked};
 if(editing?.source!=='flowwow'){for(const key of ['title','description','catalogGroup','status','url'])payload[key]=form.elements[key].value;payload.source=editing?.source||'manual';payload.purchaseMode=form.elements.inquiry.checked?'inquiry':'direct';payload.price=Number(form.elements.price.value);payload.images=images;if(!images.length){message($('#edit-message'),'Добавьте хотя бы одну фотографию.',true);return}}
 payload.channels={};for(const key of ['avito','flowwow'])payload.channels[key]={enabled:form.elements['publish_'+key].checked,price:form.elements['price_'+key].value===''?null:Number(form.elements['price_'+key].value),title:form.elements['title_'+key].value,description:form.elements['description_'+key].value};
 $('#save').disabled=true;message($('#edit-message'),'Сохраняем…');
 try{await api('save',payload);dirty=false;$('#editor').close();await load();toast('Карточка и настройки сохранены')}catch(error){message($('#edit-message'),error.message,true)}finally{$('#save').disabled=false}
});
window.addEventListener('beforeunload',e=>{if(dirty&&$('#editor').open){e.preventDefault();e.returnValue=''}});
async function initialize(){try{const session=await api('session');message($('#login-message'));$('#login-form [type=submit]').disabled=false;if(session.authenticated)await showCatalog()}catch(error){message($('#login-message'),error.message,true)}}
function channelData(p){return p.channels||Object.fromEntries(['avito','flowwow'].map(k=>[k,{enabled:p.source===k,management:p.source===k?'external':'site',price:null,title:'',description:'',state:p.source===k?'external':'off'}]))}
function selectedChannels(p){return ['avito','flowwow'].filter(k=>channelData(p)[k]?.enabled)}
function matchesChannel(p,key){const selected=selectedChannels(p);return key==='all'||(key==='site'?!selected.length&&!p.hidden&&!p.sourceMissing:selected.includes(key))}
function productBadges(p){const keys=selectedChannels(p);if(!keys.length)return !p.hidden&&!p.sourceMissing?`<span class="source-badge manual">${sourceLabel('manual')}</span>`:'<span class="unpublished-badge">Не опубликован</span>';return keys.map(k=>`<span class="source-badge ${k}" title="${escape(channelData(p)[k]?.message||'Выбранная площадка')}">${sourceLabel(k)}${channelData(p)[k]?.management==='site'?'<span class="pending-mark" aria-label="Ожидает подключения">·</span>':''}</span>`).join('')}
function renderPublicationSettings(p){
 const form=$('#edit-form');for(const key of ['avito','flowwow']){const c=channelData(p)[key];const external=c.management==='external';form.elements['publish_'+key].checked=Boolean(c.enabled);form.elements['publish_'+key].disabled=external;for(const f of ['price','title','description']){form.elements[f+'_'+key].value=c[f]??'';form.elements[f+'_'+key].disabled=external}$('#state-'+key).textContent=external?'Уже на площадке. Пока управляется там.':(c.enabled?'Выбрана. Отправка ожидает подключения.':'Можно выбрать для отправки.');document.querySelector(`[data-preview="${key}"]`).disabled=!editing;document.querySelector(`[data-preview="${key}"]`).textContent=editing?'Проверить данные':'Проверка после сохранения';}
}
function renderConnections(){
 const avito=connections.avito||{},health=avito.health||{};const denied=health.state==='access_denied';const network=['unreachable','rate_limited'].includes(health.state);const alert=$('#connection-alert');alert.hidden=!denied&&!network;alert.classList.toggle('warning',network);
 alert.textContent=denied?'Нет доступа к автозагрузке Авито. Не забыли оплатить подписку? Проверьте также ключи и права приложения.':(health.state==='rate_limited'?'Авито временно ограничило частоту запросов. Повторим проверку позже.':'Не удаётся связаться с Авито. Это может быть временный сбой соединения.');
 const reminder=avito.reminder;$('#renewal-banner').hidden=!reminder;$('#renewal-banner').textContent=reminder?.text||'';
 $('#connections-summary').innerHTML=['avito','flowwow'].map(k=>`<div class="connection-summary"><span class="source-badge ${k}">${sourceLabel(k)}</span><p>${escape(connections[k]?.message||'Ожидает подключения')}</p></div>`).join('');
 if(reminder&&!reminder.seen&&!reminderOpen&&!$('#editor').open&&!$('#connections-dialog').open){reminderId=reminder.id;reminderOpen=true;$('#renewal-message').textContent=reminder.text;$('#renewal-dialog').showModal();}
}
$('#connections-open').addEventListener('click',()=>{$('#connections-form').elements.renewalDate.value=connections.avito?.renewalDate||'';message($('#connections-message'));$('#connections-dialog').showModal()});
$('#connections-close').addEventListener('click',()=>{$('#connections-dialog').close();renderConnections()});
$('#connections-form').addEventListener('submit',async e=>{e.preventDefault();const b=e.target.querySelector('[type=submit]');b.disabled=true;try{const result=await api('renewal-date',{date:e.target.elements.renewalDate.value});connections=result.connections;$('#connections-dialog').close();reminderOpen=false;renderConnections();toast('Дата продления сохранена')}catch(error){message($('#connections-message'),error.message,true)}finally{b.disabled=false}});
async function checkConnections(){if(!authenticated)return;try{const result=await api('check-connections',{});connections=result.connections;renderConnections()}catch(error){toast(error.message)}}
$('#check-connections').addEventListener('click',async()=>{const b=$('#check-connections');b.disabled=true;try{await checkConnections()}finally{b.disabled=false}});
async function dismissReminder(){try{await api('reminder-seen',{id:reminderId});if(connections.avito?.reminder)connections.avito.reminder.seen=true;$('#renewal-dialog').close();reminderOpen=false}catch(error){toast(error.message)}}
$('#renewal-dismiss').addEventListener('click',dismissReminder);$('#renewal-dialog').addEventListener('cancel',e=>{e.preventDefault();dismissReminder()});
for(const key of ['avito','flowwow'])$('#edit-form').elements['publish_'+key].addEventListener('change',()=>{const current=editing||{source:'manual',hidden:false};const p={...current,hidden:!$('#edit-form').elements.visible.checked,channels:structuredClone(channelData(current))};for(const k of ['avito','flowwow'])p.channels[k].enabled=$('#edit-form').elements['publish_'+k].checked;$('#editor-source').innerHTML=productBadges(p);$('#state-'+key).textContent=$('#edit-form').elements['publish_'+key].checked?'Выбрана. Отправка ожидает подключения.':'Не выбрана. Товар останется на сайте, если он не скрыт.';});
document.querySelectorAll('[data-preview]').forEach(b=>b.addEventListener('click',async()=>{if(!editing)return;const output=$('#publication-preview');output.hidden=false;if(dirty){output.textContent='Сначала сохраните изменения, затем проверьте данные в карточке.';return}b.disabled=true;try{const result=await api('publication-preview',undefined,{id:editing.id,channel:b.dataset.preview});output.innerHTML=`<strong>${escape(result.product.title)} · ${money(result.product.price)}</strong><p>Фотографий: ${result.product.images.length}</p><ul>${result.warnings.map(w=>`<li>${escape(w)}</li>`).join('')}</ul>`}catch(error){output.textContent=error.message}finally{b.disabled=false}}));
setInterval(()=>{if(authenticated&&!document.hidden)checkConnections()},300000);

initialize();
