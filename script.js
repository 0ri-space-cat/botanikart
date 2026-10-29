const data=window.BOTANIK_CATALOG;
const menuButton=document.querySelector('.menu-toggle');
const nav=document.querySelector('.header nav');
function closeMenu(){menuButton.setAttribute('aria-expanded','false');menuButton.setAttribute('aria-label','Открыть меню');nav.classList.remove('is-open')}
menuButton.addEventListener('click',()=>{const open=menuButton.getAttribute('aria-expanded')!=='true';menuButton.setAttribute('aria-expanded',String(open));menuButton.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');nav.classList.toggle('is-open',open)});
nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMenu));
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});
const escapeHTML=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>new Intl.NumberFormat('ru-RU').format(n)+' ₽';
const categoryNames={florarium:'Флорариум',composition:'Живая композиция',potted:'Комнатное растение',plant:'Растение для флорариума',material:'Материалы и декор'};
const productGroup=p=>p.catalogGroup||({succulent:'plant',ficus:'plant'}[p.category]||p.category);
const groupOrder={florarium:0,composition:1,potted:2,plant:3,material:4};
const shortTitle=p=>p.title.replace(/^Флорариум с крышкой /,'').replace(/^Флорариум-экосистема /,'').replace(/^Флорариум закрытый /,'').replace(/^Флорариум /,'').replace(/^Композиция с автополивом /,'').replace(/^Композиция /,'').replace(' (фиттонии 4 вида, папоротник)','').replace(/ в (?:белом|зелёном|зеленом|чёрном|черном|прозрачном|нежно-розовом|нежно-фиолетовом|пепельно-сером|тёмно-синем|керамическом|светло-бежевом|белом керамическом|зеленом керамическом).*(?:кашпо|горшке)$/,'');
function descriptionHTML(raw){
 const lines=String(raw||'').replace(/\r\n?/g,'\n').split('\n');
 const blocks=[];let paragraph=[],items=[];
 const flushParagraph=()=>{if(paragraph.length){blocks.push(`<p>${paragraph.map(escapeHTML).join('<br>')}</p>`);paragraph=[]}};
 const flushList=()=>{if(items.length){blocks.push(`<ul>${items.map(item=>`<li>${escapeHTML(item)}</li>`).join('')}</ul>`);items=[]}};
 for(const rawLine of lines){
  const line=rawLine.trim();
  if(!line){flushParagraph();flushList();continue}
  const bullet=line.match(/^(?:—|•|–)\s+(.+)/);
  if(bullet){flushParagraph();items.push(bullet[1]);continue}
  flushList();
  if(line.endsWith(':')&&line.length<100){flushParagraph();blocks.push(`<h3>${escapeHTML(line)}</h3>`)}
  else paragraph.push(line);
 }
 flushParagraph();flushList();return blocks.join('');
}
const availabilityLabel=p=>p.canOrder?'Раскупили':p.available?'В наличии':p.status==='sold'?'Нет в наличии':'Под заказ';
function productCard(p){
 const size=p.width&&p.height?`<span>${p.width} × ${p.height} см</span>`:'';
 return `<button class="product-card ${p.source==='avito'?'source-avito':''} ${p.available?'':'is-unavailable'}" data-product="${escapeHTML(p.id)}" aria-label="Открыть ${escapeHTML(p.title)}"><span class="product-image"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}" loading="lazy"><span class="product-badge ${p.available?'':'muted'}" ${p.canOrder?'title="Можем сделать похожий на заказ"':''}>${availabilityLabel(p)}</span></span><span class="product-name">${escapeHTML(shortTitle(p))}</span><span class="product-card-meta">${escapeHTML(categoryNames[productGroup(p)]||'Растение')}${size}</span><span class="product-price">${money(p.price)}</span></button>`;
}
const featured=document.querySelector('[data-featured]');
if(featured)featured.innerHTML=data.featured.map(id=>data.products.find(p=>p.id===id)).filter(p=>p?.available).slice(0,4).map(productCard).join('');
const dialog=document.querySelector('.product-dialog');
const lightbox=document.querySelector('.lightbox');
let previousFocus=null;
function productAction(p){
 if(p.available&&p.purchaseMode==='inquiry'){
  const text='Здравствуйте! Хочу выбрать '+p.title+'. Цена за один экземпляр — '+money(p.price)+'. Подскажите, какие варианты есть в наличии, чтобы согласовать выбор перед оплатой.';
  return `<a class="button primary" href="https://t.me/Ekaterina_Freed?text=${encodeURIComponent(text)}" target="_blank" rel="noopener">Согласовать выбор</a>`;
 }
 if(p.canOrder)return `<a class="button primary" href="https://t.me/Ekaterina_Freed?text=${encodeURIComponent('Здравствуйте! Хочу заказать композицию, похожую на '+p.title+'.')}" target="_blank" rel="noopener">Сделать на заказ</a>`;
 if(p.available)return `<button type="button" class="button primary buy-button" data-buy="${escapeHTML(p.id)}">Купить</button>`;
 return '<a class="button primary" href="https://t.me/Ekaterina_Freed" target="_blank" rel="noopener">Обсудить заказ</a>';
}
function openProduct(id){const p=data.products.find(p=>p.id===id);if(!p)return;previousFocus=document.activeElement;
 dialog.innerHTML=`<button class="close-product" aria-label="Закрыть карточку">✕</button><div class="product-detail"><div class="product-detail-visual"><button class="detail-main-image" aria-label="Увеличить фотографию"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}"></button><div class="product-thumbs">${p.images.map((src,i)=>`<button class="${i===0?'selected':''}" data-photo="${i}" aria-label="Фотография ${i+1}" aria-pressed="${i===0}"><img src="${escapeHTML(src)}" alt=""></button>`).join('')}</div></div><div class="product-detail-copy"><h2>${escapeHTML(shortTitle(p))}</h2><p class="product-category">${escapeHTML(categoryNames[productGroup(p)]||'Растение')}${p.available?'':' · '+availabilityLabel(p)}</p><div class="detail-price">${money(p.price)}</div>${p.canOrder?'<p class="sold-order-note">Раскупили. Можем сделать похожий на заказ.</p>':''}<div class="product-description">${descriptionHTML(p.description)}</div>${p.width&&p.height?`<dl class="product-specs"><div><dt>Ширина</dt><dd>${p.width} см</dd></div><div><dt>Высота</dt><dd>${p.height} см</dd></div></dl>`:''}${p.fullDescription&&p.fullDescription!==p.description?`<details class="full-description"><summary>Подробнее</summary><div class="product-description">${descriptionHTML(p.fullDescription)}</div></details>`:''}<div class="product-actions">${productAction(p)}</div></div></div><a class="text-link detail-question" href="https://t.me/Ekaterina_Freed" target="_blank" rel="noopener">Задать вопрос</a>`;
 dialog.showModal();document.body.classList.add('modal-open');
 dialog.querySelector('.close-product').addEventListener('click',()=>dialog.close());
 dialog.querySelectorAll('[data-photo]').forEach(button=>button.addEventListener('click',()=>{dialog.querySelector('.detail-main-image img').src=p.images[Number(button.dataset.photo)];dialog.querySelectorAll('[data-photo]').forEach(b=>{const selected=b===button;b.classList.toggle('selected',selected);b.setAttribute('aria-pressed',String(selected))})}));
 dialog.querySelector('.detail-main-image').addEventListener('click',()=>{lightbox.querySelector('img').src=dialog.querySelector('.detail-main-image img').src;lightbox.querySelector('img').alt=p.title;lightbox.querySelector('p').textContent=p.title;lightbox.showModal()});
}
document.addEventListener('click',e=>{const card=e.target.closest('[data-product]');if(card)openProduct(card.dataset.product)});
for(const modal of [dialog,lightbox])modal.addEventListener('click',e=>{if(e.target===modal){const r=modal.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)modal.close()}});
dialog.addEventListener('close',()=>{document.body.classList.remove('modal-open');previousFocus?.focus()});
lightbox.querySelector('.close-lightbox').addEventListener('click',()=>lightbox.close());
const grid=document.querySelector('[data-catalog]');
if(grid){
 let section='all',plantCategory='all';
 const sections={florarium:['florarium','composition'],plants:['potted','plant'],material:['material']};
 const search=document.querySelector('.catalog-search input'),sort=document.querySelector('.catalog-controls select'),stock=document.querySelector('[data-stock]');
 const sectionButtons=[...document.querySelectorAll('[data-section]')],plantButtons=[...document.querySelectorAll('[data-plant-category]')];
 const url=new URL(location.href),initial=url.searchParams.get('section'),initialCategory=url.searchParams.get('category');
 if(Object.hasOwn(sections,initial))section=initial;
 if(['potted','plant'].includes(initialCategory)){section='plants';plantCategory=initialCategory;}
 function render(updateURL=false){
  const query=search.value.toLocaleLowerCase('ru-RU').replace(/ё/g,'е').trim();
  let items=data.products.filter(p=>(section==='all'||sections[section].includes(productGroup(p)))&&(section!=='plants'||plantCategory==='all'||productGroup(p)===plantCategory)&&(!stock.checked||p.available)&&(!query||(p.title+' '+p.description).toLocaleLowerCase('ru-RU').replace(/ё/g,'е').includes(query)));
  items.sort(sort.value==='price-asc'?(a,b)=>a.price-b.price:sort.value==='price-desc'?(a,b)=>b.price-a.price:(a,b)=>(groupOrder[productGroup(a)]??9)-(groupOrder[productGroup(b)]??9)||Number(b.available)-Number(a.available)||a.title.localeCompare(b.title,'ru'));
  grid.innerHTML=items.map(productCard).join('');document.querySelector('[data-results]').textContent=`Показано ${items.length} из ${data.products.length}`;document.querySelector('.empty-catalog').hidden=items.length!==0;grid.hidden=items.length===0;
  sectionButtons.forEach(button=>{const active=button.dataset.section===section;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active))});
  document.querySelector('.plant-subcategories').hidden=section!=='plants';
  plantButtons.forEach(button=>{const active=button.dataset.plantCategory===plantCategory;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active))});
  for(const [key,groups] of Object.entries(sections))document.querySelector(`[data-section-count="${key}"]`).textContent=data.products.filter(p=>groups.includes(productGroup(p))).length;
  if(updateURL){const next=new URL(location.href);next.searchParams.delete('section');next.searchParams.delete('category');if(section!=='all')next.searchParams.set('section',section);if(section==='plants'&&plantCategory!=='all')next.searchParams.set('category',plantCategory);history.replaceState(null,'',next.pathname+next.search+next.hash)}
 }
 sectionButtons.forEach(button=>button.addEventListener('click',()=>{section=button.dataset.section;plantCategory='all';render(true)}));
 plantButtons.forEach(button=>button.addEventListener('click',()=>{plantCategory=button.dataset.plantCategory;render(true)}));
 search.addEventListener('input',()=>render());sort.addEventListener('change',()=>render());stock.addEventListener('change',()=>render());
 document.querySelector('[data-reset]').addEventListener('click',()=>{section='all';plantCategory='all';search.value='';stock.checked=false;sort.value='default';render(true)});render();
}

const purchaseDialog=document.querySelector('.purchase-dialog');
const paymentSettings=fetch(window.BOTANIKART_PAYMENT_CONFIG_ENDPOINT,{cache:'no-store'})
 .then(response=>response.ok?response.json():{enabled:false,testMode:false})
 .catch(()=>({enabled:false,testMode:false}));
async function openPurchase(id){
 const p=data.products.find(item=>item.id===id);if(!p||!p.available||p.purchaseMode==='inquiry')return;
 const title='Оформление покупки';
 const settings=await paymentSettings;
 const ready=settings.enabled===true&&Boolean(window.BOTANIKART_PAYMENT_ENDPOINT);
 purchaseDialog.innerHTML=`<button class="close-purchase" aria-label="Закрыть оформление">✕</button><div class="section-label">БОТАНИКАРТ · ВАША ПОКУПКА</div><h2>${title}</h2><div class="purchase-summary"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}"><div><h3>${escapeHTML(shortTitle(p))}</h3><p>${money(p.price)}</p></div></div><form class="purchase-form"><label>Электронная почта<input name="email" type="email" autocomplete="email" maxlength="64" placeholder="Для электронного чека"></label><label>Телефон<input name="phone" type="tel" autocomplete="tel" maxlength="24" placeholder="+7 999 123-45-67"></label><p class="purchase-pickup">Самовывоз бесплатный. Доставку курьером, Яндексом или СДЭКом можно оформить после оплаты. Для этого свяжитесь с нами в <a href="https://t.me/Ekaterina_Freed" target="_blank" rel="noopener">Telegram</a>.</p><label class="purchase-consent"><input name="consent" type="checkbox" required><span>Согласен с <a href="offer.html" target="_blank" rel="noopener">условиями продажи</a> и <a href="privacy.html#consent" target="_blank" rel="noopener">обработкой персональных данных</a>.</span></label><p class="checkout-message" role="status"></p><button type="submit" class="button primary" ${ready?'':'disabled'}>Купить</button></form>`;
 const checkoutMessage=purchaseDialog.querySelector('.checkout-message');
 checkoutMessage.textContent=ready?(settings.testMode?'Тестовая оплата — используйте только тестовую карту.':''):'Оплата временно недоступна. Попробуйте позже.';
 if(!purchaseDialog.open)purchaseDialog.showModal();document.body.classList.add('modal-open');
 purchaseDialog.querySelector('.close-purchase').addEventListener('click',()=>purchaseDialog.close());
 const form=purchaseDialog.querySelector('.purchase-form');
 form.dataset.requestId=crypto.randomUUID();
 form.addEventListener('submit',event=>startCheckout(event,p.id));
}
async function startCheckout(event,productId){
 event.preventDefault();
 const form=event.currentTarget,button=form.querySelector('button[type="submit"]'),message=form.querySelector('.checkout-message');
 const email=form.elements.email.value.trim(),phone=form.elements.phone.value.trim();
 if(!email&&!phone){message.textContent='Укажите телефон или почту для чека.';return}
 if(!form.elements.consent.checked){message.textContent='Чтобы продолжить, подтвердите согласие с условиями и обработкой данных.';return}
 button.disabled=true;button.textContent='Открываем оплату…';message.textContent='';
 try{
  const response=await fetch(window.BOTANIKART_PAYMENT_ENDPOINT,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({productId,requestId:form.dataset.requestId,expectedAmount:Math.round(Number(data.products.find(item=>item.id===productId)?.price)*100),email,phone,consent:true,telegramContext:productId===telegramCheckoutProduct?telegramCheckoutContext:''})});
  const result=await response.json();
  if(!response.ok||!result.paymentUrl)throw new Error(result.error||'payment_init_failed');
  const paymentUrl=new URL(result.paymentUrl);
  if(paymentUrl.protocol!=='https:'||paymentUrl.username||paymentUrl.password||(paymentUrl.port&&paymentUrl.port!=='443')||!['securepay.tinkoff.ru','securepayments.tinkoff.ru','pay.tbank-online.com'].includes(paymentUrl.hostname.toLowerCase()))throw new Error('invalid_payment_url');
  if(result.reference)sessionStorage.setItem('botanikart-payment-'+result.orderId,result.reference);
  window.location.assign(paymentUrl.href);
 }catch(error){
  const messages={invalid_telegram_checkout:'Ссылка покупки из Telegram устарела. Откройте товар в боте ещё раз.',product_unavailable:'Этот товар уже недоступен. Обновите каталог и выберите другой.',price_changed:'Цена изменилась. Обновите страницу.',payment_not_configured:'Оплата временно недоступна. Попробуйте позже.',receipt_not_configured:'Оплата временно недоступна. Попробуйте позже.',catalog_unavailable:'Не удалось проверить наличие. Повторите попытку чуть позже.',invalid_order_details:'Проверьте адрес электронной почты и номер телефона.',too_many_requests:'Слишком много попыток. Попробуйте через несколько минут.',payment_service_unavailable:'Банк временно не ответил. Повторите попытку позже.',payment_init_failed:'Не удалось открыть оплату. Повторите попытку позже.'};
  message.textContent=messages[error.message]||'Не удалось начать оплату. Проверьте соединение и попробуйте ещё раз.';
  button.disabled=false;button.textContent='Купить';
 }
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-buy]');if(button)openPurchase(button.dataset.buy)});
purchaseDialog.addEventListener('click',e=>{if(e.target===purchaseDialog){const r=purchaseDialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)purchaseDialog.close()}});
purchaseDialog.addEventListener('close',()=>{if(!dialog.open)document.body.classList.remove('modal-open')});

// Bot checkout links share the website's existing product and payment flow.
const telegramCheckoutParams=new URLSearchParams(location.search);
const telegramCheckoutProduct=telegramCheckoutParams.get('product')||'';
const telegramCheckoutContext=telegramCheckoutParams.get('tg')||'';
if(telegramCheckoutContext){const clean=new URL(location.href);clean.searchParams.delete('tg');history.replaceState(null,'',clean.pathname+clean.search+clean.hash)}
if(telegramCheckoutProduct&&data.products.some(p=>p.id===telegramCheckoutProduct)){
 openProduct(telegramCheckoutProduct);
 if(telegramCheckoutParams.get('checkout')==='1')openPurchase(telegramCheckoutProduct);
}
