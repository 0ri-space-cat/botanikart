import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../src/index.js';

test('Payment amount, signed status and production receipt guard', async () => {
 const original=globalThis.fetch;
 const env={SITE_ORIGINS:'http://localhost:8765',CATALOG_URL:'https://catalog.example/catalog.json',TBANK_API_URL:'https://securepay.tinkoff.ru/v2/Init',SITE_SUCCESS_URL:'https://botanikart.ru/order-success.html',SITE_FAILURE_URL:'https://botanikart.ru/order-error.html',TBANK_TERMINAL_KEY:'TESTDEMO',TBANK_PASSWORD:'test-fixture-only'};
 let signedURL='',failURL='',paymentHost='securepayments.tinkoff.ru',bankAmount=0,statusAmount=12300,bankCalls=0;
 globalThis.fetch=async(url,options)=>{
  if(String(url)===env.CATALOG_URL)return Response.json({products:[{id:'flowwow-12345678901234567890123456',title:'🌿'.repeat(70),price:123,available:true}]});
  bankCalls++;
  const payload=JSON.parse(options.body);
  assert.equal(typeof payload.Token,'string');
  if(String(url).endsWith('/Init')){
   bankAmount=payload.Amount;signedURL=payload.SuccessURL;failURL=payload.FailURL;
   return Response.json({Success:true,PaymentURL:`https://${paymentHost}/test`,PaymentId:'123'});
  }
  return Response.json({Success:true,OrderId:payload.OrderId,Payments:[{Status:'CONFIRMED',Amount:statusAmount}]});
 };
 const request=(route,body,origin='http://localhost:8765')=>new Request('https://api.example'+route,{method:'POST',headers:{Origin:origin,'Content-Type':'application/json'},body:JSON.stringify(body)});
 try{
  const productId='flowwow-12345678901234567890123456';
  let init=await worker.fetch(request('/api/create-payment',{productId,amount:1,expectedAmount:100,email:'test@example.com',consent:true}),env);
  assert.equal(init.status,409,'stale displayed price is rejected');assert.equal(bankCalls,0);
  init=await worker.fetch(request('/api/create-payment',{productId,amount:1,expectedAmount:12300,email:'test@example.com',consent:true}),env);
  assert.equal(init.status,200);assert.equal(bankAmount,12300,'caller cannot override the catalogue price');
  assert.ok(signedURL.length<=250,`SuccessURL must fit bank limit, got ${signedURL.length}`);
  assert.ok(failURL.length<=250,`FailURL must fit bank limit, got ${failURL.length}`);
  const reference=new URL(signedURL).searchParams.get('reference');assert.ok(reference);
  let response=await worker.fetch(request('/api/payment-status',{reference:reference+'x'}),env);
  assert.equal(response.status,400,'tampered references rejected');
  statusAmount=999;
  response=await worker.fetch(request('/api/payment-status',{reference}),env);
  assert.equal((await response.json()).paid,false,'CONFIRMED with a wrong amount is not paid');
  statusAmount=12300;
  response=await worker.fetch(request('/api/payment-status',{reference}),env);
  const paid=await response.json();assert.equal(paid.paid,true);assert.equal(paid.testMode,true);assert.equal(paid.title,'🌿'.repeat(70));
  const count=bankCalls;
  response=await worker.fetch(request('/api/create-payment',{productId,expectedAmount:12300,email:'test@example.com',consent:true}),{...env,TBANK_TERMINAL_KEY:'LIVE'});
  assert.equal(response.status,503);assert.equal(bankCalls,count,'live payments require confirmed fiscalization');
  response=await worker.fetch(request('/api/create-payment',{productId,expectedAmount:12300,email:'test@example.com',consent:true},'https://untrusted.example'),env);
  assert.equal(response.status,403);
  paymentHost='payments.attacker.example';
  response=await worker.fetch(request('/api/create-payment',{productId,expectedAmount:12300,email:'test@example.com',consent:true}),env);
  assert.equal(response.status,502,'untrusted payment host is rejected');
 }finally{globalThis.fetch=original}
});
