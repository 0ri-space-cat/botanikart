import worker from '../payments-worker/src/index.js';
const routes={'create-payment':'/api/create-payment','payment-status':'/api/payment-status',health:'/health'};
const defaults={
 SITE_ORIGINS:'https://party-time-putilkovo.github.io,http://127.0.0.1:8765,http://localhost:8765',
 CATALOG_URL:'https://party-time-putilkovo.github.io/botanikart/data/catalog.json',
 SITE_SUCCESS_URL:'https://party-time-putilkovo.github.io/botanikart/order-success.html',
 SITE_FAILURE_URL:'https://party-time-putilkovo.github.io/botanikart/order-error.html',
 TBANK_API_URL:'https://securepay.tinkoff.ru/v2/Init',
 TBANK_TAXATION:'usn_income',TBANK_RECEIPTS_READY:'false'
};
export async function handler(event){
 try{
  const action=event.queryStringParameters?.action||'health';
  if(!routes[action])return {statusCode:404,body:'Not found'};
  const method=event.httpMethod||'GET';
  const body=event.isBase64Encoded?Buffer.from(event.body||'','base64').toString('utf8'):event.body||'';
  const request=new Request('https://botanikart.internal'+routes[action],{method,headers:event.headers||{},...(!['GET','HEAD'].includes(method)?{body}:{})});
  const response=await worker.fetch(request,{...defaults,...process.env});
  return {statusCode:response.status,headers:Object.fromEntries(response.headers),body:await response.text(),isBase64Encoded:false};
 }catch(error){
  console.error('Payment function failed',{name:error.name});
  return {statusCode:500,headers:{'content-type':'application/json'},body:JSON.stringify({error:'payment_service_unavailable'})};
 }
}
