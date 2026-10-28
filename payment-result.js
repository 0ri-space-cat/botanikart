const title=document.querySelector('[data-result-title]');
const copy=document.querySelector('[data-result-copy]');
const symbol=document.querySelector('[data-result-symbol]');
const delivery=document.querySelector('#delivery-telegram');
const retry=document.querySelector('#check-payment');
const params=new URLSearchParams(location.search);
let reference=params.get('reference')||sessionStorage.getItem('botanikart-payment-'+params.get('order'))||'';
let attempts=0,telegramTimer;
function contactStudio(order, outcome){
 if(order?.testMode)return;
 const identity=order?'Заказ '+order.orderId+'. Товар: '+order.title+'. ':'';
 const text=outcome==='paid'?'Здравствуйте! Я оплатил покупку. '+identity+'Подскажите, пожалуйста, как получить заказ.':outcome==='refunded'?'Здравствуйте! По моему заказу указан возврат оплаты. '+identity+'Хочу уточнить детали.':'Здравствуйте! Хочу уточнить результат оплаты. '+identity+'Помогите, пожалуйста, проверить заказ.';
 delivery.href='https://t.me/Ekaterina_Freed?text='+encodeURIComponent(text);delivery.hidden=false;delivery.textContent='Написать студии в Telegram';
 clearTimeout(telegramTimer);
 // A draft opens only: Telegram still requires the buyer to press Send.
 telegramTimer=setTimeout(()=>location.assign(delivery.href),3500);
}

async function checkPayment(){
 clearTimeout(telegramTimer);retry.hidden=true;
 if(!reference){contactStudio(null,'unknown');title.textContent='Статус оплаты';copy.textContent='Данные заказа не найдены. Если вы уже оплатили покупку, свяжитесь со студией и назовите номер заказа из формы банка.';return}
 try{
  const response=await fetch(window.BOTANIKART_PAYMENT_STATUS_ENDPOINT,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({reference})});
  if(!response.ok)throw new Error('unavailable');
  const order=await response.json();
  sessionStorage.setItem('botanikart-payment-'+order.orderId,reference);
  history.replaceState(null,'',location.pathname+'?order='+encodeURIComponent(order.orderId));
  if(['REFUNDED','PARTIAL_REFUNDED'].includes(order.status)){
   symbol.textContent='—';title.textContent=order.status==='REFUNDED'?'Оплата возвращена.':'Часть оплаты возвращена.';copy.textContent='Уточните детали заказа у студии.';contactStudio(order,'refunded');return;
  }
  if(order.paid){
   symbol.textContent='✓';
   title.textContent=order.testMode?'Тестовый платёж прошёл.':'Спасибо за покупку!';
   copy.textContent=order.testMode?'Банк подтвердил тестовую оплату. Настоящие деньги не списывались.':'Заказ '+order.orderId+' оплачен. Сейчас откроется Telegram — отправьте сообщение студии, чтобы согласовать получение.';
   if(!order.testMode)contactStudio(order,'paid');return;
  }
  if(['REJECTED','CANCELED','DEADLINE_EXPIRED','REVERSED'].includes(order.status)){
   symbol.textContent='—';title.textContent='Оплата не завершена.';copy.textContent='Сейчас откроется Telegram. Вы можете уточнить детали у студии или вернуться в каталог.';contactStudio(order,'unpaid');return;
  }
  title.textContent='Проверяем оплату…';copy.textContent='Ожидаем подтверждение от банка.';
  if(++attempts<5){setTimeout(checkPayment,2000);return}contactStudio(order,'unknown');
 }catch{title.textContent='Уточняем статус оплаты';copy.textContent='Банк пока не подтвердил результат. Попробуйте проверить статус чуть позже. Если деньги списаны, повторно оплачивать заказ не нужно.'}
 if(delivery.hidden)contactStudio(null,'unknown');retry.hidden=false;
}
retry.addEventListener('click',()=>{attempts=0;checkPayment()});
checkPayment();
