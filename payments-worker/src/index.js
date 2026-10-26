const JSON_HEADERS = { "content-type": "application/json; charset=utf-8", "cache-control": "no-store" };

function allowedOrigins(env) {
  return new Set((env.SITE_ORIGINS || "").split(",").map(value => value.trim()).filter(Boolean));
}

function response(body, status, origin = "", env = {}) {
  const headers = new Headers(JSON_HEADERS);
  const allowed = allowedOrigins(env);
  if (origin && allowed.has(origin)) {
    headers.set("access-control-allow-origin", origin);
    headers.set("vary", "Origin");
    headers.set("access-control-allow-credentials", "false");
  }
  headers.set("access-control-allow-methods", "POST, GET, OPTIONS");
  headers.set("access-control-allow-headers", "content-type");
  return new Response(JSON.stringify(body), { status, headers });
}

function cleanText(value, maxLength) {
  return String(value ?? "").replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, maxLength);
}

function validEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && value.length <= 64;
}

function validPhone(value) {
  return /^\+[1-9][0-9]{6,14}$/.test(value);
}

async function makeToken(payload, password) {
  const entries = Object.entries(payload)
    .filter(([key, value]) => key !== "Token" && (typeof value === "string" || typeof value === "number" || typeof value === "boolean"))
    .map(([key, value]) => [key, String(value)]);
  entries.push(["Password", password]);
  entries.sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0);
  const source = entries.map(([, value]) => value).join("");
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(source));
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, "0")).join("");
}

function testMode(env){return /DEMO$/i.test(env.TBANK_TERMINAL_KEY||'')}

async function referenceKey(env){
 return crypto.subtle.importKey('raw',new TextEncoder().encode('botanikart-order:'+env.TBANK_PASSWORD),{name:'HMAC',hash:'SHA-256'},false,['sign','verify']);
}
function base64url(bytes){return btoa(String.fromCharCode(...bytes)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'')}
function fromBase64url(value){return Uint8Array.from(atob(value.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0))}
async function orderReference(order,env){
 const body=base64url(new TextEncoder().encode(`${order.orderId}|${order.productId}|${order.amount}|${order.expires}`));
 const signature=await crypto.subtle.sign('HMAC',await referenceKey(env),new TextEncoder().encode(body));
 return body+'.'+base64url(new Uint8Array(signature));
}
async function readReference(value,env){
 try{
  if(typeof value!=='string'||value.length>4096)return null;
  const [body,signature,...extra]=value.split('.');
  if(extra.length||!body||!signature)return null;
  if(!await crypto.subtle.verify('HMAC',await referenceKey(env),fromBase64url(signature),new TextEncoder().encode(body)))return null;
  const [orderId,productId,amountText,expiresText,...extraFields]=new TextDecoder().decode(fromBase64url(body)).split('|');
  const order={orderId,productId,amount:Number(amountText),expires:Number(expiresText)};
  if(extraFields.length||!order.orderId||!order.productId||!Number.isSafeInteger(order.amount)||order.amount<=0||!Number.isSafeInteger(order.expires)||order.expires<Date.now())return null;
  return order;
 }catch{return null}
}
async function bankRequest(method,payload,env){
 payload.Token=await makeToken(payload,env.TBANK_PASSWORD);
 const bankFetch=env.BANK_FETCH||fetch;
 const result=await bankFetch(new URL(method,env.TBANK_API_URL).href,{
  method:'POST',headers:{'content-type':'application/json',...(env.TBANK_API_TOKEN?{authorization:`Bearer ${env.TBANK_API_TOKEN}`}:{})},
  body:JSON.stringify(payload),signal:AbortSignal.timeout(12000)
 });
 if(result.status===526)throw new Error('bank_tls_unavailable');
 let data;try{data=await result.json()}catch{throw new Error('payment_service_unavailable')}
 if(!result.ok||!data.Success){
  console.error('T-Bank request rejected',{method,status:result.status,errorCode:data.ErrorCode});
  throw new Error('payment_init_failed');
 }
 return data;
}
async function paymentStatus(request,env,origin){
 if(origin&&!allowedOrigins(env).has(origin))return response({error:'origin_not_allowed'},403,origin,env);
 if(!env.TBANK_PASSWORD||!env.TBANK_TERMINAL_KEY)return response({error:'payment_not_configured'},503,origin,env);
 let input;try{const raw=await request.text();if(raw.length>5000)throw new Error();input=JSON.parse(raw)}catch{return response({error:'invalid_order'},400,origin,env)}
 const order=await readReference(input.reference,env);
 if(!order)return response({error:'invalid_order'},400,origin,env);
 try{
  const result=await bankRequest('CheckOrder',{TerminalKey:env.TBANK_TERMINAL_KEY,OrderId:order.orderId},env);
  if(result.OrderId!==order.orderId)return response({error:'order_mismatch'},502,origin,env);
  const payments=Array.isArray(result.Payments)?result.Payments:[];
  const paid=payments.some(payment=>payment.Status==='CONFIRMED'&&Number(payment.Amount)===order.amount);
  const latest=payments[payments.length-1];
  let title=order.productId;
  try{
   const catalogResponse=await fetch(env.CATALOG_URL,{headers:{accept:'application/json'},cf:{cacheTtl:0}});
   if(catalogResponse.ok){const catalog=await catalogResponse.json();title=catalog.products?.find(item=>item.id===order.productId)?.title||title}
  }catch{}
  return response({orderId:order.orderId,title,productId:order.productId,amount:order.amount/100,paid,testMode:testMode(env),status:paid?'CONFIRMED':latest?.Status||'NEW'},200,origin,env);
 }catch(error){console.error('T-Bank status failed',{message:error.message});return response({error:'payment_service_unavailable'},502,origin,env)}
}

async function getAvailableProduct(env, productId) {
  const catalogResponse = await fetch(env.CATALOG_URL, { headers: { accept: "application/json" }, cf: { cacheTtl: 0 } });
  if (!catalogResponse.ok) throw new Error("catalog_unavailable");
  const catalog = await catalogResponse.json();
  const product = Array.isArray(catalog.products) ? catalog.products.find(item => item.id === productId) : null;
  if (!product || product.available !== true || !Number.isFinite(Number(product.price)) || Number(product.price) <= 0) return null;
  return product;
}

function makeReceipt(env, item, amount, email, phone) {
  if (!env.TBANK_TAXATION || !env.TBANK_TAX) return null;
  const receipt = {
    Taxation: env.TBANK_TAXATION,
    Items: [{
      Name: cleanText(item.title, 128),
      Price: amount,
      Quantity: 1,
      Amount: amount,
      Tax: env.TBANK_TAX,
      PaymentMethod: env.TBANK_PAYMENT_METHOD || "full_prepayment",
      PaymentObject: "commodity"
    }]
  };
  if (email) receipt.Email = email;
  if (phone) receipt.Phone = phone;
  return receipt;
}

async function createPayment(request, env, origin) {
  if (origin && !allowedOrigins(env).has(origin)) return response({ error: "origin_not_allowed" }, 403, origin, env);
  if (!env.TBANK_TERMINAL_KEY || !env.TBANK_PASSWORD) return response({ error: "payment_not_configured" }, 503, origin, env);
  if (!env.CATALOG_URL || !env.TBANK_API_URL) return response({ error: "payment_not_configured" }, 503, origin, env);

  if (!testMode(env) && (env.TBANK_RECEIPTS_READY !== "true" || !env.TBANK_TAXATION || !env.TBANK_TAX)) return response({ error: "receipt_not_configured" }, 503, origin, env);

  let input;
  try {
    const raw = await request.text();
    if (raw.length > 8192) return response({ error: "request_too_large" }, 413, origin, env);
    input = JSON.parse(raw);
  } catch {
    return response({ error: "invalid_json" }, 400, origin, env);
  }

  const productId = cleanText(input.productId, 80);
  const email = cleanText(input.email, 100).toLowerCase();
  const phoneInput = cleanText(input.phone, 24);
  const digits = phoneInput.replace(/[^0-9]/g, "");
  const phone = digits ? "+" + (digits.length === 11 && digits.startsWith("8") ? "7" + digits.slice(1) : digits) : "";
  if (!productId || !Number.isSafeInteger(input.expectedAmount) || input.expectedAmount<=0 || input.consent !== true || (!email && !phone) || (email && !validEmail(email)) || (phone && !validPhone(phone))) {
    return response({ error: "invalid_order_details" }, 400, origin, env);
  }

  let product;
  try {
    product = await getAvailableProduct(env, productId);
  } catch {
    return response({ error: "catalog_unavailable" }, 503, origin, env);
  }
  if (!product) return response({ error: "product_unavailable" }, 409, origin, env);

  const amount = Math.round(Number(product.price) * 100);
  if(input.expectedAmount!==amount)return response({error:'price_changed'},409,origin,env);
  const orderId = `ba-${Date.now().toString(36)}-${crypto.randomUUID().slice(0, 8)}`;
  const reference = await orderReference({orderId,productId:product.id,amount,expires:Date.now()+3*86400000},env);
  const successURL=new URL(env.SITE_SUCCESS_URL);
  successURL.searchParams.set('reference',reference);
  const failURL=new URL(env.SITE_FAILURE_URL);
  failURL.searchParams.set('order',orderId);
  if(successURL.href.length>250||failURL.href.length>250)return response({error:'payment_not_configured'},503,origin,env);
  const payload = {
    TerminalKey: env.TBANK_TERMINAL_KEY,
    Amount: amount,
    OrderId: orderId,
    Description: cleanText(product.title, 140),
    Language: "ru",
    PayType: "O",
    DATA: { OperationInitiatorType: "0" },
    SuccessURL: successURL.href,
    FailURL: failURL.href
  };

  const receipt = makeReceipt(env, product, amount, email, phone);
  if (receipt) payload.Receipt = receipt;
  let result;
  try {
    result = await bankRequest('Init',payload,env);
    if (!result.PaymentURL) throw new Error('payment_init_failed');
    const paymentURL = new URL(result.PaymentURL);
    if (paymentURL.protocol !== 'https:' || !['securepay.tinkoff.ru','securepayments.tinkoff.ru'].includes(paymentURL.hostname.toLowerCase())) throw new Error('payment_init_failed');
  } catch (error) {
    console.error('T-Bank Init failed',{message:error.message});
    return response({error:error.message==='bank_tls_unavailable'?'bank_tls_unavailable':'payment_init_failed'},502,origin,env);
  }

  return response({ paymentUrl: result.PaymentURL, orderId }, 200, origin, env);
}

export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    const url = new URL(request.url);
    if (request.method === "OPTIONS") {
      if (origin && !allowedOrigins(env).has(origin)) return response({ error: "origin_not_allowed" }, 403, origin, env);
      return new Response(null, { status: 204, headers: {
        "access-control-allow-origin": origin,
        "access-control-allow-methods": "POST, GET, OPTIONS",
        "access-control-allow-headers": "content-type",
        "access-control-max-age": "86400",
        "vary": "Origin"
      }});
    }
    if (url.pathname === "/health" && request.method === "GET") {
      return response({ ok: true, paymentConfigured: Boolean(env.TBANK_TERMINAL_KEY && env.TBANK_PASSWORD), testMode: testMode(env), receiptConfigured: env.TBANK_RECEIPTS_READY === "true" && Boolean(env.TBANK_TAXATION && env.TBANK_TAX) }, 200, origin, env);
    }
    if (url.pathname === "/api/payment-status" && request.method === "POST") return paymentStatus(request, env, origin);
    if (url.pathname === "/api/create-payment" && request.method === "POST") return createPayment(request, env, origin);
    return response({ error: "not_found" }, 404, origin, env);
  }
};
