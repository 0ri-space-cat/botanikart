import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';

test('Payment amount, signed status and production receipt guard', async () => {
 const original=globalThis.fetch;
 const env={SITE_ORIGINS:'http://localhost:8765',CATALOG_URL:'https://catalog.example/catalog.json',TBANK_API_URL:'https://securepay.tinkoff.ru/v2/Init',SITE_SUCCESS_URL:'https://shop.example/success',SITE_FAILURE_URL:'https://shop.example/error',TBANK_TERMINAL_KEY:'TESTDEMO',TBANK_PASSWORD:'test-fixture-only'};
 let signedURL='',bankAmount=0,statusAmount=12300,bankCalls=0;
 globalThis.fetch=async(url,options)=>{
  if(String(url)===env.CATALOG_URL)return Response.json({products:[{id:'plant',title:'Test plant',price:123,available:true}]});
  bankCalls++;
  const payload=JSON.parse(options.body);
  assert.equal(typeof payload.Token,'string');
  if(String(url).endsWith('/Init')){
   bankAmount=payload.Amount;signedURL=payload.SuccessURL;
   return Response.json({Success:true,PaymentURL:'https://securepay.tinkoff.ru/test',PaymentId:'123'});
  }
  return Response.json({Success:true,OrderId:payload.OrderId,Payments:[{Status:'CONFIRMED',Amount:statusAmount}]});
 };
 const request=(route,body,origin='http://localhost:8765')=>new Request('https://api.example'+route,{method:'POST',headers:{Origin:origin,'Content-Type':'application/json'},body:JSON.stringify(body)});
 try{
  const init=await worker.fetch(request('/api/create-payment',{productId:'plant',amount:1,email:'test@example.com',consent:true}),env);
  assert.equal(init.status,200);assert.equal(bankAmount,12300,'caller cannot override the catalogue price');
  const reference=new URL(signedURL).searchParams.get('reference');assert.ok(reference);
  let response=await worker.fetch(request('/api/payment-status',{reference:reference+'x'}),env);
  assert.equal(response.status,400,'tampered references rejected');
  statusAmount=999;
  response=await worker.fetch(request('/api/payment-status',{reference}),env);
  assert.equal((await response.json()).paid,false,'CONFIRMED with a wrong amount is not paid');
  statusAmount=12300;
  response=await worker.fetch(request('/api/payment-status',{reference}),env);
  const paid=await response.json();assert.equal(paid.paid,true);assert.equal(paid.testMode,true);
  const count=bankCalls;
  response=await worker.fetch(request('/api/create-payment',{productId:'plant',email:'test@example.com',consent:true}),{...env,TBANK_TERMINAL_KEY:'LIVE'});
  assert.equal(response.status,503);assert.equal(bankCalls,count,'live payments require confirmed fiscalization');
  response=await worker.fetch(request('/api/create-payment',{productId:'plant',email:'test@example.com',consent:true},'https://untrusted.example'),env);
  assert.equal(response.status,403);
 }finally{globalThis.fetch=original}
});
