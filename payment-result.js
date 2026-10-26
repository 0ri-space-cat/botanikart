const title=document.querySelector('[data-result-title]');
const copy=document.querySelector('[data-result-copy]');
const symbol=document.querySelector('[data-result-symbol]');
const delivery=document.querySelector('#delivery-telegram');
const retry=document.querySelector('#check-payment');
const params=new URLSearchParams(location.search);
let reference=params.get('reference')||sessionStorage.getItem('botanikart-payment-'+params.get('order'))||'';
let attempts=0;
async function checkPayment(){
 retry.hidden=true;
 if(!reference){title.textContent='Статус оплаты';copy.textContent='Данные заказа не найдены. Если вы уже оплатили покупку, свяжитесь со студией и назовите номер заказа из формы банка.';return}
 try{
  const response=await fetch(window.BOTANIKART_PAYMENT_STATUS_ENDPOINT,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({reference})});
  if(!response.ok)throw new Error('unavailable');
  const order=await response.json();
  sessionStorage.setItem('botanikart-payment-'+order.orderId,reference);
  history.replaceState(null,'',location.pathname+'?order='+encodeURIComponent(order.orderId));
  if(order.paid){
   symbol.textContent='✓';
   title.textContent=order.testMode?'Тестовый платёж прошёл.':'Спасибо за покупку!';
   copy.textContent=order.testMode?'Банк подтвердил тестовую оплату. Настоящие деньги не списывались.':'Заказ '+order.orderId+' оплачен. Самовывоз бесплатный. Доставку можно оформить в Telegram.';
   if(!order.testMode){delivery.href='https://t.me/Ekaterina_Freed?text='+encodeURIComponent('Здравствуйте! Я оплатил заказ '+order.orderId+'. Товар: '+order.title+'. Хочу оформить доставку.');delivery.hidden=false}
   return;
  }
  if(['REJECTED','CANCELED','DEADLINE_EXPIRED','REFUNDED','REVERSED'].includes(order.status)){
   symbol.textContent='—';title.textContent='Оплата не завершена.';copy.textContent='Вы можете вернуться в каталог и попробовать снова. Если банк сообщил о списании, свяжитесь со студией и назовите номер заказа '+order.orderId+'.';return;
  }
  title.textContent='Проверяем оплату…';copy.textContent='Ожидаем подтверждение от банка.';
  if(++attempts<5){setTimeout(checkPayment,2000);return}
 }catch{title.textContent='Уточняем статус оплаты';copy.textContent='Банк пока не подтвердил результат. Попробуйте проверить статус чуть позже. Если деньги списаны, повторно оплачивать заказ не нужно.'}
 retry.hidden=false;
}
retry.addEventListener('click',()=>{attempts=0;checkPayment()});
checkPayment();
