const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync('payment-result.js','utf8');
async function scenario(order){
 const nodes=Object.fromEntries(['[data-result-title]','[data-result-copy]','[data-result-symbol]','#delivery-telegram','#check-payment'].map(k=>[k,{hidden:true,textContent:'',addEventListener(){}}]));
 const timers=[],redirects=[];
 const ctx={document:{querySelector:k=>nodes[k]},URLSearchParams,sessionStorage:{getItem(){},setItem(){}},history:{replaceState(){}},location:{search:'?reference=signed-secret',pathname:'/order-success.html',assign:url=>redirects.push(url)},window:{BOTANIKART_PAYMENT_STATUS_ENDPOINT:'/api/payment-status.php'},setTimeout:(fn,ms)=>{timers.push([fn,ms]);return timers.length},clearTimeout(){},fetch:async()=>({ok:true,json:async()=>order})};
 vm.runInNewContext(code,ctx);await new Promise(resolve=>setImmediate(resolve));
 return {nodes,timers,redirects};
}
(async()=>{
 const base={orderId:'ba-fixture',title:'Маранта',status:'CONFIRMED',paid:true,testMode:false};
 let result=await scenario(base);assert(result.nodes['[data-result-title]'].textContent.includes('Спасибо'));assert(result.timers.some(t=>t[1]===3500));assert(!result.nodes['#delivery-telegram'].href.includes('signed-secret'));
 result.timers.find(t=>t[1]===3500)[0]();assert(result.redirects[0].startsWith('https://t.me/Ekaterina_Freed?text='));
 result=await scenario({...base,testMode:true});assert(!result.timers.length);assert(result.nodes['#delivery-telegram'].hidden);
 result=await scenario({...base,paid:false,status:'REJECTED'});assert(!decodeURIComponent(result.nodes['#delivery-telegram'].href).includes('Я оплатил'));assert(result.timers.some(t=>t[1]===3500));
 result=await scenario({...base,paid:true,status:'PARTIAL_REFUNDED'});assert(result.nodes['[data-result-title]'].textContent.includes('возвращена'));assert(!decodeURIComponent(result.nodes['#delivery-telegram'].href).includes('Я оплатил'));
 console.log('Payment return checks passed: success, failure, refund, demo isolation and secret-free Telegram draft.');
})().catch(e=>{console.error(e);process.exit(1)});
