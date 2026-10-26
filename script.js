const data=window.BOTANIK_CATALOG;
const checkedDate=new Intl.DateTimeFormat('ru-RU',{day:'numeric',month:'long',year:'numeric',timeZone:'Europe/Moscow'}).format(new Date(data.checkedAt+'T12:00:00+03:00')).replace(' г.','');
document.querySelectorAll('time[datetime]').forEach(t=>{t.dateTime=data.checkedAt;t.textContent=checkedDate});
const menuButton=document.querySelector('.menu-toggle');
const nav=document.querySelector('.header nav');
function closeMenu(){menuButton.setAttribute('aria-expanded','false');menuButton.setAttribute('aria-label','Открыть меню');nav.classList.remove('is-open')}
menuButton.addEventListener('click',()=>{const open=menuButton.getAttribute('aria-expanded')!=='true';menuButton.setAttribute('aria-expanded',String(open));menuButton.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');nav.classList.toggle('is-open',open)});
nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMenu));
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeMenu()});
const escapeHTML=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>new Intl.NumberFormat('ru-RU').format(n)+' ₽';
const categoryNames={florarium:'Флорариум',plant:'Комнатное растение',composition:'Мини-сад',succulent:'Суккуленты и кактусы',ficus:'Фикус'};
const shortTitle=p=>p.title.replace(/^Флорариум с крышкой /,'').replace(/^Флорариум-экосистема /,'').replace(/^Флорариум закрытый /,'').replace(/^Флорариум /,'').replace(/^Композиция с автополивом /,'').replace(/^Композиция /,'').replace(' (фиттонии 4 вида, папоротник)','').replace(/ в (?:белом|зелёном|зеленом|чёрном|черном|прозрачном|нежно-розовом|нежно-фиолетовом|пепельно-сером|тёмно-синем|керамическом|светло-бежевом|белом керамическом|зеленом керамическом).*(?:кашпо|горшке)$/,'');
function productCard(p){return `<button class="product-card ${p.available?'':'is-unavailable'}" data-product="${escapeHTML(p.id)}" aria-label="Открыть ${escapeHTML(p.title)}"><span class="product-image"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}" loading="lazy"><span class="product-badge ${p.available?'':'muted'}">${p.available?'В наличии':'Под заказ'}</span></span><span class="product-card-meta">${escapeHTML(categoryNames[p.category])} <span>${p.width&&p.height?`${p.width} × ${p.height} см`:'Размеры уточним'}</span></span><span class="product-name">${escapeHTML(shortTitle(p))}</span><span class="product-price">${money(p.price)}${p.available?'':'<small>последняя цена</small>'}</span></button>`}
const featured=document.querySelector('[data-featured]');
if(featured)featured.innerHTML=data.featured.map(id=>data.products.find(p=>p.id===id)).filter(p=>p?.available).slice(0,4).map(productCard).join('');
const dialog=document.querySelector('.product-dialog');
const lightbox=document.querySelector('.lightbox');
let previousFocus=null;
function openProduct(id){const p=data.products.find(p=>p.id===id);if(!p)return;previousFocus=document.activeElement;
 dialog.innerHTML=`<button class="close-product" aria-label="Закрыть карточку">✕</button><div class="product-detail"><div class="product-detail-visual"><button class="detail-main-image" aria-label="Увеличить фотографию"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}"></button><div class="product-thumbs">${p.images.map((src,i)=>`<button class="${i===0?'selected':''}" data-photo="${i}" aria-label="Фотография ${i+1}" aria-pressed="${i===0}"><img src="${escapeHTML(src)}" alt=""></button>`).join('')}<span>Нажмите на фото, чтобы рассмотреть ближе</span></div></div><div class="product-detail-copy"><div class="section-label">${categoryNames[p.category]} · БОТАНИКАРТ</div><h2>${escapeHTML(shortTitle(p))}</h2><div class="detail-price">${money(p.price)}</div><span class="detail-status ${p.available?'':'unavailable'}">${p.available?'В наличии на дату проверки':'Под заказ · сроки и состав уточним'}</span><p class="product-description">${escapeHTML(p.description)}</p>${p.width&&p.height?`<dl class="product-specs"><div><dt>Ширина</dt><dd>${p.width} см</dd></div><div><dt>Высота</dt><dd>${p.height} см</dd></div></dl>`:''}${p.fullDescription&&p.fullDescription!==p.description?`<details class="full-description"><summary>Подробнее</summary><p>${escapeHTML(p.fullDescription)}</p></details>`:''}${p.available?`<button type="button" class="button primary buy-button" data-buy="${escapeHTML(p.id)}">${p.category==='florarium'?'Купить флорариум':p.category==='composition'?'Купить композицию':'Купить растение'}</button>`:'<a class="button primary" href="https://t.me/Ekaterina_Freed" target="_blank" rel="noopener">Обсудить заказ</a>'}${p.available?'<a class="text-link detail-question" href="https://t.me/Ekaterina_Freed" target="_blank" rel="noopener">Задать вопрос</a>':''}<p class="detail-note">${p.available?'Цена и наличие проверены '+checkedDate+'.<br>Наличие и условия покупки подтвердим перед оплатой.':'Указана стоимость предыдущей композиции.<br>Стоимость повторения и срок изготовления уточним лично.'}</p></div></div>`;
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
if(grid){let category='all';const search=document.querySelector('.catalog-search input');const sort=document.querySelector('.catalog-controls select');const stock=document.querySelector('[data-stock]');const filters=[...document.querySelectorAll('[data-category]')];
 function render(){const query=search.value.toLocaleLowerCase('ru-RU').replace(/ё/g,'е').trim();let items=data.products.filter(p=>(category==='all'||p.category===category)&&(!stock.checked||p.available)&&(!query||(p.title+' '+p.description).toLocaleLowerCase('ru-RU').replace(/ё/g,'е').includes(query)));items.sort(sort.value==='price-asc'?(a,b)=>a.price-b.price:sort.value==='price-desc'?(a,b)=>b.price-a.price:(a,b)=>Number(b.available)-Number(a.available));grid.innerHTML=items.map(productCard).join('');document.querySelector('[data-results]').textContent=`Показано ${items.length} из ${data.products.length}`;document.querySelector('.empty-catalog').hidden=items.length!==0;grid.hidden=items.length===0;filters.forEach(b=>{b.classList.toggle('active',b.dataset.category===category);b.setAttribute('aria-pressed',String(b.dataset.category===category))})}
 filters.forEach(b=>b.addEventListener('click',()=>{category=b.dataset.category;render()}));search.addEventListener('input',render);sort.addEventListener('change',render);stock.addEventListener('change',render);document.querySelector('[data-reset]').addEventListener('click',()=>{category='all';search.value='';stock.checked=false;sort.value='default';render()});render();
}

const purchaseDialog=document.querySelector('.purchase-dialog');
function openPurchase(id){
 const p=data.products.find(item=>item.id===id);if(!p)return;
 const title=p.category==='florarium'?'Купить флорариум':p.category==='composition'?'Купить композицию':'Купить растение';
 // Replace this disabled button with acquiring checkout once the terminal is configured.
 purchaseDialog.innerHTML=`<button class="close-purchase" aria-label="Закрыть оформление">✕</button><div class="section-label">БОТАНИКАРТ · ВАША ПОКУПКА</div><h2>${title}</h2><div class="purchase-summary"><img src="${escapeHTML(p.images[0])}" alt="${escapeHTML(p.title)}"><div><h3>${escapeHTML(shortTitle(p))}</h3><p>${money(p.price)}</p></div></div><button type="button" class="button primary" data-checkout="${escapeHTML(p.id)}" disabled>Купить</button>`;
 purchaseDialog.showModal();document.body.classList.add('modal-open');
 purchaseDialog.querySelector('.close-purchase').addEventListener('click',()=>purchaseDialog.close());
}
document.addEventListener('click',event=>{const button=event.target.closest('[data-buy]');if(button)openPurchase(button.dataset.buy)});
purchaseDialog.addEventListener('click',e=>{if(e.target===purchaseDialog){const r=purchaseDialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)purchaseDialog.close()}});
purchaseDialog.addEventListener('close',()=>{if(!dialog.open)document.body.classList.remove('modal-open')});
