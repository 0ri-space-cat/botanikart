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
let avitoSchema={};const schemaReady=fetch('avito-schema.json?v=7').then(r=>{if(!r.ok)throw Error('schema');return r.json()}).then(s=>{avitoSchema=s}).catch(()=>{});
let channelTouched={avito:false,flowwow:false};
let flowwowEditorSequence=0,flowwowEditorData=null;
let connections={},reminderId='',reminderOpen=false,authenticated=false;
let csrf='',products=[],source='all',editing=null,images=[],dirty=false,uploading=false;
const errors={active_payment_exists:'По этому товару уже оформляется другая покупка.',sale_confirmation_required:'Сначала подтвердите продажу.',invalid_channels:'Проверьте настройки площадок и цены.',external_channel:'Существующее объявление пока управляется на своей площадке.',invalid_date:'Укажите корректную дату продления.',duplicate_source:'Товар с этой ссылкой Авито уже есть в каталоге. Найдите и отредактируйте его.',setup_required:'Вход ещё не настроен.',invalid_credentials:'Проверьте логин и пароль.',too_many_attempts:'Слишком много попыток. Попробуйте через 15 минут.',service_unavailable:'Не удалось связаться с каталогом. Попробуйте ещё раз.',invalid_product:'Проверьте название, цену и фотографии.',edit_conflict:'Товар уже изменился. Закройте карточку, обновите список и откройте её снова.',upload_failed:'Не удалось загрузить фото. Выберите JPEG, PNG или WebP.',invalid_image:'Этот формат фотографии не поддерживается. Сохраните её как JPEG или PNG.',image_too_large:'Фото слишком большое. Выберите изображение поменьше.',request_too_large:'Слишком много данных. Уменьшите описание.',https_required:'Откройте каталог по защищённому адресу https://.'};
function message(element,text='',error=false){element.textContent=text;element.classList.toggle('error',error)}
async function api(action,body,params={}){
 const options={credentials:'same-origin',cache:'no-store'};
 if(body!==undefined){options.method='POST';options.headers={'X-CSRF-Token':csrf};if(body instanceof FormData)options.body=body;else{options.headers['Content-Type']='application/json';options.body=JSON.stringify(body)}}
 let response;try{response=await fetch('api.php?action='+encodeURIComponent(action)+'&'+new URLSearchParams(params),options)}catch{throw Error('Нет соединения. Проверьте интернет и повторите.')}
 let result;try{result=await response.json()}catch{throw Error('Каталог временно недоступен. Попробуйте позже.')}
 if(!response.ok){if(result.error==='authentication_required'||result.error==='session_expired'){showLogin();throw Error('Войдите снова, чтобы продолжить.')}throw Error(result.message||errors[result.error]||'Не удалось выполнить действие. Попробуйте ещё раз.')}
 if(result.csrf)csrf=result.csrf;return result;
}
function showLogin(){authenticated=false;$('#catalog-view').hidden=true;$('#orders-view').hidden=true;$('#login-view').hidden=false;$('#logout').hidden=true;$('#editor').close();products=[]}
async function load(){await schemaReady;const result=await api('products');products=result.products;connections=result.connections||{};renderConnections();render()}
async function showCatalog(){authenticated=true;window.scrollTo(0,0);$('#login-view').hidden=true;$('#catalog-view').hidden=false;$('#logout').hidden=false;await load();checkConnections();loadOrders().catch(()=>{});if(location.hash.startsWith('#orders'))openOrders()}
function render(){
 const query=$('#search').value.toLocaleLowerCase('ru-RU').replace(/ё/g,'е').trim();const visibility=$('#visibility').value;const category=$('#category').value;
 const items=products.filter(p=>(visibility==='archive'?p.deleted:!p.deleted)&&(category==='all'||productGroup(p)===category)&&matchesChannel(p,source)&&(!query||(p.title+' '+p.description).toLocaleLowerCase('ru-RU').replace(/ё/g,'е').includes(query))&&(visibility==='all'||visibility==='archive'||visibility==='hidden'&&(p.hidden||p.sourceMissing)||visibility==='visible'&&!p.hidden&&!p.sourceMissing||visibility==='ready'&&p.available&&!p.soldOnSite||visibility==='sold'&&(p.status==='sold'||p.soldOnSite)));
 $('#total').textContent=products.length;$('#empty').hidden=items.length!==0;
 $('#products').innerHTML=items.map(p=>`<button class="product ${p.source==='avito'?'source-avito':''} ${p.hidden||p.sourceMissing||p.deleted?'is-hidden':''}" data-id="${escape(p.id)}"><img src="${escape(imageURL(p.images?.[0]||'assets/logo-mark-roots.png'))}" alt="" loading="lazy">${p.deleted?'<span class="hidden-badge">В архиве</span>':(p.hidden||p.sourceMissing?'<span class="hidden-badge">Скрыт</span>':'')}<div class="product-body"><span class="publication-badges">${productBadges(p)}</span><h2>${escape(p.title)}</h2><span class="product-category">${escape(categories[productGroup(p)]||'Растение')}</span><span class="product-price">${money(p.price)}</span><span class="product-status">${escape(p.soldOnSite?'Раскупили':(statuses[p.status]||(p.available?'В наличии':'Под заказ')))}</span></div></button>`).join('');
}
function renderPhotos(){
 $('#photo-count').textContent=images.length+' / 20';
 $('#photos').innerHTML=images.map((path,i)=>`<div class="photo"><img src="${escape(imageURL(path))}" alt="Фото ${i+1}"><div class="photo-actions"><button type="button" data-cover="${i}" class="${i===0?'is-cover':''}" title="${i===0?'Обложка':'Сделать обложкой'}" aria-label="${i===0?'Обложка':`Сделать фото ${i+1} обложкой`}" aria-pressed="${i===0}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z"/></svg></button><button type="button" data-remove="${i}" aria-label="Убрать фото ${i+1}" title="Убрать фото"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17"/></svg></button></div></div>`).join('');
}
function openEditor(id){
 editing=products.find(p=>p.id===id)||null;const p=editing||{title:'',price:'',description:'',source:'manual',catalogGroup:$('#category').value==='all'?'plant':$('#category').value,status:'ready',url:'',images:[],hidden:false};
 channelTouched={avito:false,flowwow:false};const form=$('#edit-form');form.reset();for(const key of ['title','price','description','catalogGroup','status','url'])form.elements[key].value=key==='status'&&p.soldOnSite?'sold':(p[key]??'');
 form.elements.catalogGroup.value=productGroup(p);$('#editor-category').textContent=categories[productGroup(p)]||'Растение';form.elements.purchase_mode.value=p.purchaseMode==='inquiry'?'inquiry':'direct';form.elements.visible.checked=!p.hidden;$('#product-fields').disabled=Boolean(p.deleted);
 $('#archive-product').hidden=!editing||Boolean(p.deleted);$('#restore-product').hidden=!p.deleted;$('#save').hidden=Boolean(p.deleted);
 $('#editor-heading').textContent=editing?'Карточка товара':'Новый товар';$('#editor-source').innerHTML=productBadges(p);
 updatePurchaseChoice();updateVisibility();images=[...(p.images||[])];renderPhotos();message($('#edit-message'));dirty=false;
 renderPublicationSettings(p);renderAvitoFields(channelData(p).avito);$('#publication-preview').hidden=true;form.elements.url.closest('label').hidden=p.source!=='avito';
 $('#source-url-label').textContent=p.source==='flowwow'?'Ссылка на Flowwow':'Ссылка на объявление Авито';$('#editor').showModal();fitTitle();$('#editor').scrollTop=0;document.body.style.overflow='hidden';
}
function closeEditor(){if(uploading)return;if(dirty&&!confirm('Закрыть карточку без сохранения?'))return;$('#editor').close()}
function toast(text){$('#toast').textContent=text;$('#toast').hidden=false;setTimeout(()=>{$('#toast').hidden=true},3000)}
$('#login-form').addEventListener('submit',async e=>{e.preventDefault();const button=e.target.querySelector('[type=submit]');button.disabled=true;message($('#login-message'));try{await api('login',{username:e.target.elements.username.value,password:e.target.elements.password.value});e.target.elements.password.value='';await showCatalog()}catch(error){message($('#login-message'),error.message,true)}finally{button.disabled=false}});
$('#reveal').addEventListener('click',()=>{const input=$('#login-form').elements.password;const reveal=input.type==='password';input.type=reveal?'text':'password';$('#reveal').textContent=reveal?'Скрыть':'Показать';$('#reveal').setAttribute('aria-label',reveal?'Скрыть пароль':'Показать пароль')});
$('#logout').addEventListener('click',async()=>{try{await api('logout',{});showLogin();await initialize()}catch(error){toast(error.message)}});
$('#add').addEventListener('click',()=>openEditor());$('#close-editor').addEventListener('click',closeEditor);
$('#editor').addEventListener('cancel',e=>{e.preventDefault();closeEditor()});$('#editor').addEventListener('close',()=>{document.body.style.overflow=''});
$('#edit-form').addEventListener('input',e=>{dirty=true;const key=e.target.closest('[data-channel-card]')?.dataset.channelCard;if(key)channelTouched[key]=true});
$('#search').addEventListener('input',render);$('#category').addEventListener('change',render);
$('#edit-form').elements.catalogGroup.addEventListener('change',e=>{$('#editor-category').textContent=categories[e.target.value]});$('#visibility').addEventListener('change',render);
document.querySelectorAll('[data-source]').forEach(button=>button.addEventListener('click',()=>{source=button.dataset.source;document.querySelectorAll('[data-source]').forEach(b=>{b.classList.toggle('active',b===button);b.setAttribute('aria-pressed',String(b===button))});render()}));
$('#products').addEventListener('click',e=>{const item=e.target.closest('[data-id]');if(item)openEditor(item.dataset.id)});
$('#photos').addEventListener('click',e=>{const remove=e.target.closest('[data-remove]'),cover=e.target.closest('[data-cover]');if(remove){images.splice(Number(remove.dataset.remove),1);dirty=true;renderPhotos()}if(cover){images.unshift(...images.splice(Number(cover.dataset.cover),1));dirty=true;renderPhotos()}});
$('#reload').addEventListener('click',async()=>{try{message($('#list-message'));await load();toast('Каталог обновлён')}catch(error){message($('#list-message'),error.message,true)}});
async function preparePhoto(file){
 let bitmap;try{bitmap=await createImageBitmap(file,{imageOrientation:'from-image'})}catch{throw Error(errors.invalid_image)}
 let scale=Math.min(1,2400/Math.max(bitmap.width,bitmap.height));if(Math.min(bitmap.width,bitmap.height)>=1200)scale=Math.min(1,Math.max(scale,1200/Math.min(bitmap.width,bitmap.height)));if(bitmap.width*bitmap.height*scale*scale>12000000)scale=Math.min(1,2400/Math.max(bitmap.width,bitmap.height));const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();
 const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.94));if(!blob)throw Error(errors.invalid_image);return blob;
}
$('#photo-input').addEventListener('change',async e=>{
 const files=[...e.target.files];if(images.length+files.length>20){message($('#edit-message'),'В карточке может быть до 20 фотографий.',true);e.target.value='';return}
 uploading=true;$('#save').disabled=true;$('#photo-input').disabled=true;
 try{let padded=false;for(let i=0;i<files.length;i++){message($('#edit-message'),`Загружаем фото ${i+1} из ${files.length}…`);const form=new FormData();form.append('image',await preparePhoto(files[i]),'photo.jpg');const result=await api('upload',form);images.push(result.path);padded=padded||result.padded;dirty=true;renderPhotos()}message($('#edit-message'),padded?'Фото подготовлены для Flowwow: добавлены светлые поля без обрезки.':'')}catch(error){message($('#edit-message'),error.message,true)}finally{uploading=false;$('#save').disabled=false;$('#photo-input').disabled=false;e.target.value=''}
});
$('#edit-form').addEventListener('submit',async e=>{
 e.preventDefault();if(uploading)return;const form=e.target;const payload={id:editing?.id||'',version:editing?.version||0,hidden:!form.elements.visible.checked};
 if(true){for(const key of ['title','description','catalogGroup','status','url'])payload[key]=form.elements[key].value;payload.source=editing?.source||'manual';payload.purchaseMode=form.elements.purchase_mode.value;payload.price=Number(form.elements.price.value);payload.images=images;if(!images.length){message($('#edit-message'),'Добавьте хотя бы одну фотографию.',true);return}}
 payload.channels={};for(const key of ['avito','flowwow'])payload.channels[key]=form.elements['publish_'+key].checked?{configure:channelTouched[key],enabled:true,price:form.elements['price_'+key].value===''?null:Number(form.elements['price_'+key].value),title:form.elements['title_'+key].value,description:form.elements['description_'+key].value}:{configure:channelTouched[key],enabled:false,price:channelData(editing||{})[key].price??null,title:channelData(editing||{})[key].title||'',description:channelData(editing||{})[key].description||''};payload.channels.avito.category=payload.channels.avito.enabled?form.elements.avito_category.value:(channelData(editing||{}).avito.category||'');payload.channels.avito.attributes=payload.channels.avito.enabled?readAvitoAttributes():(channelData(editing||{}).avito.attributes||{});payload.channels.flowwow.category=$('#flowwow-category')?.value||channelData(editing||{}).flowwow.category||'';payload.channels.flowwow.properties=payload.channels.flowwow.enabled?readFlowwowProperties():(channelData(editing||{}).flowwow.properties||{});
 $('#save').disabled=true;message($('#edit-message'),'Сохраняем…');
 try{const saved=await api('save',payload);dirty=false;$('#editor').close();await load();toast('Карточка сохранена. Проверяем площадки…');const sync=await api('sync-product',{id:saved.id});await load();toast(sync.busy?'Карточка сохранена. Отправка уже идёт, статус обновится позже.':sync.results?.some(r=>r.state==='error')?'Карточка сохранена. Проверьте сообщение площадки.':'Готово. Статусы площадок обновлены')}catch(error){if($('#editor').open)message($('#edit-message'),error.message,true);else toast('Карточка сохранена. Не удалось проверить площадки — откройте её позже.')}finally{$('#save').disabled=false}
});
window.addEventListener('beforeunload',e=>{if(dirty&&$('#editor').open){e.preventDefault();e.returnValue=''}});
async function initialize(){try{const session=await api('session');message($('#login-message'));$('#login-form [type=submit]').disabled=false;if(session.authenticated)await showCatalog()}catch(error){message($('#login-message'),error.message,true)}}
function channelData(p){return p.channels||Object.fromEntries(['avito','flowwow'].map(k=>[k,{enabled:p.source===k,management:p.source===k?'external':'site',price:null,title:'',description:'',state:p.source===k?'external':'off'}]))}
function selectedChannels(p){return ['avito','flowwow'].filter(k=>channelData(p)[k]?.enabled)}
function matchesChannel(p,key){const selected=selectedChannels(p);return key==='all'||(key==='site'?!selected.length&&!p.hidden&&!p.sourceMissing:selected.includes(key))}
function productBadges(p){const keys=selectedChannels(p);if(!keys.length)return !p.hidden&&!p.sourceMissing?`<span class="source-badge manual">${sourceLabel('manual')}</span>`:'<span class="unpublished-badge">Не опубликован</span>';return keys.map(k=>`<span class="source-badge ${k}" title="${escape(channelData(p)[k]?.message||'Выбранная площадка')}">${sourceLabel(k)}${channelData(p)[k]?.state==='error'?'<span class="pending-mark error" aria-label="Ошибка площадки">!</span>':(['queued','awaiting_report','waiting_connection'].includes(channelData(p)[k]?.state)?'<span class="pending-mark" aria-label="Ожидает обработки">…</span>':'')}</span>`).join('')}
function renderPublicationSettings(p){
 const form=$('#edit-form');for(const key of ['avito','flowwow']){const c=channelData(p)[key];form.elements['publish_'+key].checked=Boolean(c.enabled);form.elements['publish_'+key].disabled=Boolean(p.deleted);for(const f of ['price','title','description']){form.elements[f+'_'+key].value=c[f]??'';form.elements[f+'_'+key].disabled=Boolean(p.deleted)}$('#state-'+key).textContent=c.error?c.message||c.error:(['queued','awaiting_report'].includes(c.state)?'Отправляется…':'');document.querySelector(`[data-channel-body="${key}"]`).hidden=!c.error;document.querySelector(`[data-channel-expand="${key}"]`).setAttribute('aria-expanded',String(Boolean(c.error)));document.querySelector(`[data-channel-card="${key}"]`).classList.toggle('is-enabled',Boolean(c.enabled));document.querySelector(`[data-preview="${key}"]`).disabled=!editing;}
 const av=channelData(p).avito;$('#avito-shared').hidden=!(av.sharedCount>1);$('#avito-shared').textContent=`На Авито это одно общее объявление для ${av.sharedCount||1} товаров. Изменения Авито затронут все товары этого общего объявления.`;
 if(av.listingTitle){form.elements.title_avito.placeholder=av.listingTitle;form.elements.description_avito.placeholder=av.listingDescription;form.elements.price_avito.placeholder=money(av.listingPrice);}
 flowwowEditorSequence++;flowwowEditorData=null;if(channelData(p).flowwow.error)loadFlowwowEditor();for(const k of ['avito','flowwow'])setChannelControls(k);
}
function renderConnections(){
 const avito=connections.avito||{},health=avito.health||{};const denied=health.state==='access_denied';const network=['unreachable','rate_limited'].includes(health.state);const alert=$('#connection-alert');alert.hidden=!denied&&!network;alert.classList.toggle('warning',network);
 alert.textContent=denied?'Нет доступа к отчётам Авито. Проверьте, активен ли тариф.':(health.state==='rate_limited'?'Авито временно ограничило частоту запросов. Повторим проверку позже.':'Не удаётся связаться с Авито. Это может быть временный сбой соединения.');
 const reminder=avito.reminder;$('#renewal-banner').hidden=!reminder;$('#renewal-banner').textContent=reminder?.text||'';
 $('#connections-summary').innerHTML=['avito','flowwow'].map(k=>`<div class="connection-summary"><span class="source-badge ${k}">${sourceLabel(k)}</span><p>${escape(connections[k]?.message||'Ожидает подключения')}</p></div>`).join('');
 if(reminder&&!reminder.seen&&!reminderOpen&&!$('#editor').open&&!$('#connections-dialog').open){reminderId=reminder.id;reminderOpen=true;$('#renewal-message').textContent=reminder.text;$('#renewal-dialog').showModal();}
}
$('#connections-open').addEventListener('click',()=>{$('#connections-form').elements.renewalDate.value=connections.avito?.renewalDate||'';message($('#connections-message'));$('#connections-dialog').showModal()});
$('#connections-close').addEventListener('click',()=>{$('#connections-dialog').close();renderConnections()});
$('#connections-form').addEventListener('submit',async e=>{e.preventDefault();const b=e.target.querySelector('[type=submit]');b.disabled=true;try{const result=await api('renewal-date',{date:e.target.elements.renewalDate.value});connections=result.connections;$('#connections-dialog').close();reminderOpen=false;renderConnections();toast('Дата продления сохранена')}catch(error){message($('#connections-message'),error.message,true)}finally{b.disabled=false}});
async function checkConnections(){if(!authenticated)return;try{const result=await api('check-connections',{});connections=result.connections;renderConnections();if(!$('#editor').open)await load()}catch(error){toast(error.message)}}
$('#check-connections').addEventListener('click',async()=>{const b=$('#check-connections');b.disabled=true;try{await checkConnections()}finally{b.disabled=false}});
async function dismissReminder(){try{await api('reminder-seen',{id:reminderId});if(connections.avito?.reminder)connections.avito.reminder.seen=true;$('#renewal-dialog').close();reminderOpen=false}catch(error){toast(error.message)}}
$('#renewal-dismiss').addEventListener('click',dismissReminder);$('#renewal-dialog').addEventListener('cancel',e=>{e.preventDefault();dismissReminder()});
for(const key of ['avito','flowwow'])$('#edit-form').elements['publish_'+key].addEventListener('change',()=>{
 channelTouched[key]=true;const form=$('#edit-form'),on=form.elements['publish_'+key].checked;
 document.querySelector(`[data-channel-body="${key}"]`).hidden=!on;document.querySelector(`[data-channel-expand="${key}"]`).setAttribute('aria-expanded',String(on));document.querySelector(`[data-channel-card="${key}"]`).classList.toggle('is-enabled',on);
 setChannelControls(key);if(key==='avito')$('#avito-fields').hidden=!on;
 if(key==='flowwow'&&on&&!flowwowEditorData)loadFlowwowEditor();
 $('#state-'+key).textContent=on?'':(key==='avito'?'Снимите объявление на Авито вручную.':'Будет скрыто на Flowwow.');
 const p={...(editing||{}),hidden:!form.elements.visible.checked,channels:structuredClone(channelData(editing||{}))};for(const k of ['avito','flowwow'])p.channels[k].enabled=form.elements['publish_'+k].checked;$('#editor-source').innerHTML=productBadges(p);
});
document.querySelectorAll('[data-preview]').forEach(b=>b.addEventListener('click',async()=>{if(!editing)return;const output=$('#publication-preview');output.hidden=false;if(dirty){output.textContent='Сначала сохраните изменения, затем проверьте данные в карточке.';return}b.disabled=true;try{const result=await api('publication-preview',undefined,{id:editing.id,channel:b.dataset.preview});output.innerHTML=`<strong>${escape(result.product.title)} · ${money(result.product.price)}</strong><p>Фотографий: ${result.product.images.length}</p><ul>${result.warnings.map(w=>`<li>${escape(w)}</li>`).join('')}</ul>`}catch(error){output.textContent=error.message}finally{b.disabled=false}}));
setInterval(()=>{if(authenticated&&!document.hidden)checkConnections()},300000);

initialize();

function readAvitoAttributes(){return Object.fromEntries([...document.querySelectorAll('[data-avito-field]')].map(el=>[el.dataset.avitoField,el.multiple?[...el.selectedOptions].map(o=>o.value).filter(Boolean):el.value]))}
function avitoFieldApplies(f,attrs){return !f.conditions?.length||f.conditions.some(c=>c.clause==='value'&&(c.values||[]).includes(attrs[c.source_field_tag]||''))}
function renderAvitoFields(c={}){
 const external=Boolean(editing?.deleted),category=c.category||'',attrs=c.attributes||{};
 $('#avito-fields').hidden=!c.enabled||external;
 $('#avito-fields').innerHTML=`<label>Категория Авито<select name="avito_category" ${external?'disabled':''}><option value="">Выберите категорию</option>${Object.entries(avitoSchema).map(([key,v])=>`<option value="${escape(key)}" ${key===category?'selected':''}>${escape(v.label)}</option>`).join('')}</select></label><div id="avito-attributes"></div>`;
 const render=()=>{const selected=$('#edit-form').elements.avito_category.value;$('#avito-attributes').innerHTML=(avitoSchema[selected]?.fields||[]).filter(f=>f.values.length!==1&&avitoFieldApplies(f,attrs)).map(f=>`<label>${escape(f.label)}${f.values.length?`<select data-avito-field="${escape(f.tag)}" ${f.multiple?'multiple':''} ${external?'disabled':''}><option value="">Выберите</option>${f.values.map(v=>`<option value="${escape(v)}" ${(Array.isArray(attrs[f.tag])?attrs[f.tag].includes(v):attrs[f.tag]===v)?'selected':''}>${escape(v)}</option>`).join('')}</select>`:`<input data-avito-field="${escape(f.tag)}" value="${escape(attrs[f.tag]||'')}" maxlength="250" ${external?'disabled':''}>`}</label>`).join('');};
 render();$('#avito-attributes').onchange=e=>{if(e.target.dataset.avitoField){Object.assign(attrs,readAvitoAttributes());const fields=avitoSchema[$('#edit-form').elements.avito_category.value]?.fields||[];if(fields.some(f=>f.conditions?.some(c=>c.source_field_tag===e.target.dataset.avitoField)))render();}};
 $('#edit-form').elements.avito_category.addEventListener('change',()=>{for(const key of Object.keys(attrs))delete attrs[key];render();});
}

function flowwowValue(field){return flowwowEditorData?.properties?.[field.field]??channelData(editing||{}).flowwow.properties?.[field.field]}
function renderFlowwowProperties(fields,properties){
 const container=$('#flowwow-properties');
 const skip=['name','price','description','images','offerId','productType','subCategoryId'];
 container.innerHTML=fields.filter(f=>!skip.includes(f.field)).map(f=>{
  const v=properties[f.field],id='fw-'+f.field,label=escape(f.name)+(f.required?' <span class="platform-required">*</span>':'');
  let control='';const options=(f.values||[]).map(o=>`<option value="${escape(o.id)}" ${(Array.isArray(v)?v.some(x=>String(x)===String(o.id)):String(v??'')===String(o.id))?'selected':''}>${escape(o.name)}</option>`).join('');
  if(f.type==='select'||f.type==='multiselect')control=`<select id="${id}" data-fw="${escape(f.field)}" ${f.type==='multiselect'?'multiple':''}><option value="">Не выбрано</option>${options}</select>`;
  else if(f.type==='bool')control=`<input id="${id}" data-fw="${escape(f.field)}" type="checkbox" ${v?'checked':''}>`;
  else if(f.type==='measure')control=`<div class="measure-input"><input id="${id}" data-fw="${escape(f.field)}" type="number" step="any" value="${escape(v?.val??'')}"><select data-fw-measure="${escape(f.field)}"><option value="">Единица</option>${(f.measure||[]).map(m=>`<option value="${escape(m.id)}" ${String(v?.measure??'')===String(m.id)?'selected':''}>${escape(m.name)}</option>`).join('')}</select></div>`;
  else if(f.type==='consist')control=`<div class="consist" data-fw-consist="${escape(f.field)}">${(v||[]).map(row=>consistRow(f,row)).join('')}</div><button type="button" class="subtle" data-add-consist="${escape(f.field)}">＋ Добавить составляющую</button>`;
  else if(f.type==='multitext')control=`<textarea id="${id}" data-fw="${escape(f.field)}" rows="3" placeholder="Каждый пункт с новой строки">${escape((v||[]).join('\n'))}</textarea>`;
  else control=`<input id="${id}" data-fw="${escape(f.field)}" type="${f.type==='decimal'?'number':'text'}" ${f.type==='decimal'?'step="any"':''} value="${escape(v??'')}">`;
  const hint=f.dependsOn?.length?'<small class="field-hint">Заполните это поле или альтернативное — оба одновременно не нужны.</small>':'';
  return `<div class="flowwow-property"><label for="${id}">${label}</label>${control}${hint}</div>`;
 }).join('');
 container.onclick=e=>{const b=e.target.closest('[data-remove-consist]');if(b){b.closest('.consist-row').remove();dirty=true;channelTouched.flowwow=true}};
 container.querySelectorAll('[data-add-consist]').forEach(b=>b.addEventListener('click',()=>{const f=fields.find(f=>f.field===b.dataset.addConsist);container.querySelector(`[data-fw-consist="${f.field}"]`).insertAdjacentHTML('beforeend',consistRow(f,{}));dirty=true;channelTouched.flowwow=true}));
 setChannelControls('flowwow');
}
function consistRow(f,row){return `<div class="consist-row"><input data-consist-name placeholder="Название" value="${escape(row.name||'')}"><input data-consist-count type="number" min="0.01" step="any" placeholder="Количество" value="${escape(row.quantity??'')}"><select data-consist-measure><option value="">Единица</option>${(f.measure||[]).map(m=>`<option value="${escape(m.id)}" ${String(m.id)===String(row.measure)?'selected':''}>${escape(m.name)}</option>`).join('')}</select><button type="button" data-remove-consist aria-label="Убрать составляющую">×</button></div>`}
async function loadFlowwowEditor(category){
 const sequence=++flowwowEditorSequence,id=editing?.id||'';const box=$('#flowwow-fields');box.innerHTML='<p class="field-hint">Загружаем настройки Flowwow…</p>';
 try{
  const data=await api('flowwow-editor',undefined,{id,...(category?{category}:{})});
  if(sequence!==flowwowEditorSequence||(editing?.id||'')!==id)return;
  flowwowEditorData=data;
  const saved=channelData(editing||{}).flowwow;const selected=category||saved.category||data.category||'';
  box.innerHTML=`${data.unsupported?'<p class="source-note">Этот специальный тип товара Flowwow не разрешает изменять через API. Данные сайта можно редактировать отдельно.</p>':''}<label>Категория Flowwow<select id="flowwow-category" ${data.immutableCategory?'data-fixed="true"':''} ${data.immutableCategory||editing?.deleted?'disabled':''}><option value="">Выберите категорию</option>${data.categories.map(c=>`<option value="${escape(c.id)}" ${String(c.id)===String(selected)?'selected':''}>${escape(c.name)}</option>`).join('')}</select></label><div id="flowwow-properties"></div><p class="field-hint">Звёздочкой отмечены поля, которые нужны только для Flowwow.</p>`;
  const properties={...data.properties,...(saved.properties||{})};flowwowEditorData.properties=properties;renderFlowwowProperties(data.fields,properties);
  $('#flowwow-category').addEventListener('change',e=>loadFlowwowEditor(e.target.value));
 }catch(error){if(sequence===flowwowEditorSequence)box.innerHTML=`<p class="form-message error">${escape(error.message)}</p><button type="button" id="retry-flowwow-fields" class="subtle">Повторить загрузку</button>`;$('#retry-flowwow-fields')?.addEventListener('click',()=>loadFlowwowEditor(category));}
}
function readFlowwowProperties(){
 if(!flowwowEditorData||!$('#flowwow-properties'))return channelData(editing||{}).flowwow.properties||{};
 const result={};
 for(const f of flowwowEditorData.fields){
  const el=document.querySelector(`[data-fw="${f.field}"]`);
  if(f.type==='consist'){
   const rows=[...document.querySelectorAll(`[data-fw-consist="${f.field}"] .consist-row`)];
   result[f.field]=rows.map(r=>({name:r.querySelector('[data-consist-name]').value,quantity:Number(r.querySelector('[data-consist-count]').value),measure:Number(r.querySelector('[data-consist-measure]').value)}));
   continue;
  }
  if(!el)continue;
  if(f.type==='bool'){result[f.field]=el.checked;continue}
  if(f.type==='multiselect'){result[f.field]=[...el.selectedOptions].filter(o=>o.value!=='').map(o=>f.values.find(v=>String(v.id)===o.value)?.id);continue}
  if(f.type==='multitext'){result[f.field]=el.value.split('\n').map(s=>s.trim()).filter(Boolean);continue}
  if(el.value===''){result[f.field]=null;continue;}
  if(f.type==='select')result[f.field]=f.values.find(v=>String(v.id)===el.value)?.id;
  else if(f.type==='measure')result[f.field]={val:Number(el.value),measure:Number(document.querySelector(`[data-fw-measure="${f.field}"]`).value)};
  else if(f.type==='decimal')result[f.field]=Number(el.value);
  else result[f.field]=el.value;
 }
 return result;
}
$('#archive-product').addEventListener('click',()=>{
 if(!editing)return;
 $('#delete-avito-warning').hidden=!(channelData(editing).avito.enabled||editing.avitoItemId||channelData(editing).avito.externalId);
 if(channelData(editing).avito.sharedCount>1)$('#delete-avito-warning').textContent='Это общее объявление Авито для нескольких товаров. Удалится только эта карточка сайта. Пока другие товары группы включены на Авито, объявление продолжит обновляться. Чтобы снять его целиком, выключите плашку Авито у группы и снимите объявление вручную на Авито.';else $('#delete-avito-warning').textContent='Внимание: чтобы удалить товар на Авито, необходимо снять объявление вручную на Авито. Отправка этой карточки из нашего каталога будет выключена.';
 $('#delete-flowwow-option').hidden=!channelData(editing).flowwow.enabled;$('#delete-hide-flowwow').checked=false;message($('#delete-message'));$('#delete-dialog').showModal();
});
$('#delete-cancel').addEventListener('click',()=>$('#delete-dialog').close());
$('#delete-confirm').addEventListener('click',async()=>{
 if(!editing)return;$('#delete-confirm').disabled=true;
 try{const id=editing.id;await api('archive',{id,version:editing.version,hideFlowwow:$('#delete-hide-flowwow').checked});dirty=false;$('#delete-dialog').close();$('#editor').close();await load();toast('Товар удалён с сайта. Он доступен в архиве.');await api('sync-product',{id});await load()}catch(error){message($('#delete-message'),error.message,true)}finally{$('#delete-confirm').disabled=false}
});
$('#restore-product').addEventListener('click',async()=>{
 if(!editing)return;
 try{await api('archive',{id:editing.id,version:editing.version,restore:true});dirty=false;$('#editor').close();await load();toast('Товар восстановлен.')}catch(error){message($('#edit-message'),error.message,true)}
});

document.querySelectorAll('[data-channel-expand]').forEach(b=>b.addEventListener('click',()=>{
 const key=b.dataset.channelExpand,el=$('#edit-form').elements['publish_'+key],body=document.querySelector(`[data-channel-body="${key}"]`);
 if(editing?.deleted)return;
 if(!el.checked){el.checked=true;dirty=true;el.dispatchEvent(new Event('change',{bubbles:true}));return}
 body.hidden=!body.hidden;b.setAttribute('aria-expanded',String(!body.hidden));
 if(key==='flowwow'&&!body.hidden&&!flowwowEditorData)loadFlowwowEditor();
}));

function setChannelControls(key){
 const on=$('#edit-form').elements['publish_'+key].checked;
 document.querySelector(`[data-channel-body="${key}"]`)?.querySelectorAll('input,select,textarea,button').forEach(el=>el.disabled=Boolean(editing?.deleted)||!on||el.dataset.fixed==='true'||el.classList.contains('preview-publication')&&!editing);
}
$('#edit-form').addEventListener('invalid',e=>{const body=e.target.closest('[data-channel-body]');if(body){body.hidden=false;document.querySelector(`[data-channel-expand="${body.dataset.channelBody}"]`)?.setAttribute('aria-expanded','true');}},true);

function updatePurchaseChoice(){const inquiry=$('#edit-form').elements.purchase_mode.value==='inquiry';$('#purchase-hint').textContent=inquiry?'Покупатель сначала пишет вам в Telegram, чтобы согласовать выбранный товар.':'Покупатель может сразу оплатить товар на сайте.'}
function updateVisibility(){const visible=$('#edit-form').elements.visible.checked;$('#visibility-caption').textContent=visible?'На сайте':'Скрыт с сайта';$('.eye-control').title=visible?'Скрыть с сайта':'Показать на сайте'}
$('#edit-form').elements.visible.addEventListener('change',updateVisibility);
document.querySelectorAll('[name=purchase_mode]').forEach(el=>el.addEventListener('change',updatePurchaseChoice));
let orders=[],orderFilter='open',orderLoaded=false;
async function loadOrders(announce=false){
 if(!authenticated)return;
 const previous=new Set(orders.map(o=>o.orderId));
 const result=await api('orders');orders=result.orders||[];
 const count=orders.filter(o=>o.fulfillment!=='completed'&&o.status!=='REFUNDED').length;
 $('#orders-count').textContent=count;$('#orders-count').hidden=!count;
 if(announce&&orderLoaded&&orders.some(o=>!previous.has(o.orderId)))toast('Новый оплаченный заказ');
 orderLoaded=true;renderOrders();
}
function renderOrders(){
 const items=orders.filter(o=>orderFilter==='all'||(orderFilter==='completed'?o.fulfillment==='completed':o.fulfillment!=='completed'&&o.status!=='REFUNDED'));
 $('#orders-list').innerHTML=items.map(o=>`<article class="order-card"><div class="order-head"><div><h2>${escape(o.title)}</h2><small>${escape(o.orderId)} · ${new Intl.DateTimeFormat('ru-RU',{dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Moscow'}).format(new Date((o.paidAt||o.createdAt)*1000))}</small></div><strong class="order-amount">${money(o.amount)}</strong></div>${o.status!=='CONFIRMED'?`<p class="order-state">${o.status==='REFUNDED'?'Возврат оплаты':'Частичный возврат'}</p>`:''}<p>${[o.phone?`<a href="tel:${escape(o.phone)}">${escape(o.phone)}</a>`:'',o.email?`<a href="mailto:${escape(o.email)}">${escape(o.email)}</a>`:''].filter(Boolean).join(' · ')}</p><div class="order-actions">${o.status==='CONFIRMED'&&!o.saleConfirmedAt?`<button type="button" class="primary" data-confirm-sale="${escape(o.orderId)}">Подтвердить продажу</button>`:''}${o.saleConfirmedAt?`<span class="sale-confirmed">Продажа подтверждена</span><button type="button" class="${o.fulfillment==='completed'?'secondary':'primary'}" data-complete-order="${escape(o.orderId)}" data-completed="${o.fulfillment!=='completed'}">${o.fulfillment==='completed'?'Вернуть в новые':'Заказ выполнен'}</button>`:''}${o.saleConfirmedAt&&o.saleKind==='single'&&Object.keys(o.salePlatforms||{}).length?`<button type="button" class="secondary" data-sale-platforms="${escape(o.orderId)}">Площадки</button>`:''}${o.fulfillment==='completed'?'<small>Выполнен</small>':''}${o.emailState==='pending'?'<small>Письмо ожидает отправки</small>':''}</div></article>`).join('');
 $('#orders-message').textContent=items.length?'':orderFilter==='completed'?'Выполненных заказов пока нет.':'Оплаченных заказов пока нет.';
}
async function openOrders(){
 const target=new URLSearchParams(location.hash.split('?')[1]||'').get('sale');$('#catalog-view').hidden=true;$('#orders-view').hidden=false;location.hash='orders';
 try{await loadOrders();if(target){const item=orders.find(o=>o.orderId===target);if(item&&!item.saleConfirmedAt){const b=document.querySelector(`[data-confirm-sale="${CSS.escape(target)}"]`);b?.focus();b?.scrollIntoView({block:'center'});}}}catch(error){message($('#orders-message'),error.message,true)}
}
$('#orders-open').addEventListener('click',openOrders);
$('#orders-back').addEventListener('click',()=>{$('#orders-view').hidden=true;$('#catalog-view').hidden=false;history.replaceState(null,'',location.pathname)});
$('#orders-refresh').addEventListener('click',()=>loadOrders().catch(e=>message($('#orders-message'),e.message,true)));
document.querySelectorAll('[data-orders]').forEach(button=>button.addEventListener('click',()=>{orderFilter=button.dataset.orders;document.querySelectorAll('[data-orders]').forEach(b=>b.classList.toggle('active',b===button));renderOrders()}));
let saleOrderId='';
function showSalePlatforms(order){
 const targets=order.salePlatforms||{};if(!Object.keys(targets).length)return;
 saleOrderId=order.orderId;$('#sale-platform-title').textContent=order.title;$('#sale-platform-options').innerHTML=['flowwow','avito'].filter(k=>targets[k]).map(k=>`<label class="sale-target"><input type="checkbox" name="sale_channel" value="${k}" ${targets[k].enabled?'checked':''}><span>${sourceLabel(k)}${k==='avito'?`<small>Объявление нужно снять вручную.${targets[k].url?` <a href="${escape(targets[k].url)}" target="_blank" rel="noopener">Открыть Авито</a>`:''}</small>`:''}</span></label>`).join('');message($('#sale-platform-message'));$('#sale-platform-submit').hidden=false;$('#sale-platform-cancel').textContent='Оставить на площадках';$('#sale-platform-dialog').showModal();
}
$('#sale-platform-cancel').addEventListener('click',()=>$('#sale-platform-dialog').close());
$('#sale-platform-form').addEventListener('submit',async e=>{
 e.preventDefault();const b=$('#sale-platform-submit');b.disabled=true;
 try{const channels=[...document.querySelectorAll('[name=sale_channel]:checked')].map(x=>x.value);const r=await api('withdraw-sale',{id:saleOrderId,channels});await loadOrders();
 const states=r.publication?.results||[];const error=states.some(s=>s.state==='error');const notes=[];if(channels.includes('flowwow'))notes.push(states.find(s=>s.channel==='flowwow')?.state==='hidden'?'Flowwow: товар скрыт.':'Flowwow: отключена публикация.');if(channels.includes('avito'))notes.push('Авито: снимите объявление вручную.');message($('#sale-platform-message'),error?'Не удалось обновить площадку. Откройте настройки товара.':notes.join(' ')||'Площадки оставлены без изменений.',error);b.hidden=!error;$('#sale-platform-cancel').textContent='Готово';
 }catch(error){message($('#sale-platform-message'),error.message,true)}finally{b.disabled=false}
});
$('#orders-list').addEventListener('click',async e=>{
 const sale=e.target.closest('[data-confirm-sale]'),platforms=e.target.closest('[data-sale-platforms]'),button=e.target.closest('[data-complete-order]');
 if(platforms){showSalePlatforms(orders.find(o=>o.orderId===platforms.dataset.salePlatforms));return;}
 if(sale){sale.disabled=true;try{const r=await api('confirm-sale',{id:sale.dataset.confirmSale});await loadOrders();await load();toast('Продажа подтверждена');if(r.sale.kind==='single')showSalePlatforms(orders.find(o=>o.orderId===sale.dataset.confirmSale));}catch(error){toast(error.message)}finally{sale.disabled=false}return;}
 if(!button)return;button.disabled=true;try{await api('complete-order',{id:button.dataset.completeOrder,completed:button.dataset.completed==='true'});await loadOrders();toast(button.dataset.completed==='true'?'Заказ выполнен':'Заказ возвращён в новые')}catch(error){toast(error.message)}finally{button.disabled=false}
});
setInterval(()=>{if(authenticated&&document.visibilityState==='visible')loadOrders(true).catch(()=>{})},60000);

function fitTitle(){const field=$('#edit-form').elements.title;field.style.height='auto';const height=field.scrollHeight+2;field.style.height=Math.min(height,120)+'px';field.style.overflowY=height>120?'auto':'hidden'}
$('#edit-form').elements.title.addEventListener('input',fitTitle);
window.addEventListener('resize',()=>{if($('#editor').open)fitTitle()});
