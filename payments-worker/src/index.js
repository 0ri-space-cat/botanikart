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
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && value.length <= 100;
}

function validPhone(value) {
  return /^\+?[0-9 ()-]{7,20}$/.test(value);
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
      PaymentMethod: "full_payment",
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
  const phone = cleanText(input.phone, 24);
  if (!productId || !input.consent || (!email && !phone) || (email && !validEmail(email)) || (phone && !validPhone(phone))) {
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
  const orderId = `ba-${Date.now().toString(36)}-${crypto.randomUUID().slice(0, 8)}`;
  const payload = {
    TerminalKey: env.TBANK_TERMINAL_KEY,
    Amount: amount,
    OrderId: orderId,
    Description: cleanText(product.title, 140),
    Language: "ru",
    PayType: "O",
    DATA: { OperationInitiatorType: "0" },
    SuccessURL: `${env.SITE_SUCCESS_URL}?order=${encodeURIComponent(orderId)}&product=${encodeURIComponent(product.id)}`,
    FailURL: `${env.SITE_FAILURE_URL}?order=${encodeURIComponent(orderId)}`
  };

  const receipt = makeReceipt(env, product, amount, email, phone);
  if (receipt) payload.Receipt = receipt;
  payload.Token = await makeToken(payload, env.TBANK_PASSWORD);

  let bankResponse;
  try {
    bankResponse = await fetch(env.TBANK_API_URL, {
      method: "POST",
      headers: { "content-type": "application/json", ...(env.TBANK_API_TOKEN ? { authorization: `Bearer ${env.TBANK_API_TOKEN}` } : {}) },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(12000)
    });
  } catch {
    return response({ error: "payment_service_unavailable" }, 502, origin, env);
  }

  let result;
  try { result = await bankResponse.json(); } catch { return response({ error: "payment_service_unavailable" }, 502, origin, env); }
  if (!bankResponse.ok || !result.Success || !result.PaymentURL) {
    console.error("T-Bank Init failed", { status: bankResponse.status, errorCode: result.ErrorCode, message: result.Message });
    return response({ error: "payment_init_failed" }, 502, origin, env);
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
      return response({ ok: true, paymentConfigured: Boolean(env.TBANK_TERMINAL_KEY && env.TBANK_PASSWORD) }, 200, origin, env);
    }
    if (url.pathname === "/api/create-payment" && request.method === "POST") return createPayment(request, env, origin);
    return response({ error: "not_found" }, 404, origin, env);
  }
};
