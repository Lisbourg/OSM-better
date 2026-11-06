/*
 * Better OSM 1.7.9 native page runtime.
 * - Executes as ordinary page JavaScript (not Tampermonkey/Violentmonkey).
 * - Uses the official OSM Leaflet/MapLibre objects.
 * - Registers Better OSM hooks before the captured official map-entry bundle.
 * - Routes cross-origin XHR/fetch through the same-origin PHP/cURL reverse proxy.
 */
(() => {
  "use strict";
  if (window.__betterOsmRuntimeInstalled) return;
  window.__betterOsmRuntimeInstalled = true;

  // The single source of truth is app-base.json. app-base.js.php reads it
  // before this runtime and injects window.__betterOsmBasePath into the page.
  const configuredBasePath = window.__betterOsmBasePath;
  if (typeof configuredBasePath !== "string" ||
      !configuredBasePath.startsWith("/") || !configuredBasePath.endsWith("/")) {
    throw new Error("Missing or invalid app-base.json. Set index_path to the public URL path of index.html.");
  }
  const APP_BASE_PATH = configuredBasePath;
  const APP_BASE_URL = new URL(APP_BASE_PATH, location.origin);
  // Native default base is Standard (code M); never restore a stale vector
  // selection from an old saved OSM session if the URL has no layer choice.
  const initialHash = location.hash.replace(/^#/, "");
  if (/(?:^|&)map=/.test(initialHash) && !/(?:^|&)layers=/.test(initialHash)) {
    try { history.replaceState(history.state, "", location.pathname + location.search + "#" + initialHash + "&layers=M"); } catch (_) {}
  }
  // With no explicit map hash, the native OSM map module chooses its normal
  // initial center and Standard layer. Do not force the page to (0, 0).

  const APP_BASE_REFERRER = APP_BASE_URL.href;
  window.__betterOsmNormalizePath = stripAppBasePath;
  window.__betterOsmSidebarURL = function(value) {
    try {
      const url = new URL(String(value), APP_BASE_URL);
      if (url.origin !== location.origin) return appPath('__proxy') + '?url=' + encodeURIComponent(url.href);
      url.pathname = appPath(stripAppBasePath(url.pathname));
      return url.pathname + url.search + url.hash;
    } catch (_) { return value; }
  };

  function appPath(path) {
    return APP_BASE_PATH + String(path || "").replace(/^\/+/, "");
  }

  function stripAppBasePath(pathname) {
    const baseWithoutSlash = APP_BASE_PATH.slice(0, -1);
    let path = pathname || "/";
    if (APP_BASE_PATH !== "/") {
      if (path === baseWithoutSlash || path === APP_BASE_PATH) return "/";
      if (path.startsWith(APP_BASE_PATH)) path = "/" + path.slice(APP_BASE_PATH.length);
    }
    // OSM's SPA routes are / and /directions, not /index.html and
    // /directions/index.html. Normalize static-file URLs before recognition.
    if (path === "/index.html") return "/";
    if (path.endsWith("/index.html")) path = path.slice(0, -11) || "/";
    if (path.length > 1 && path.endsWith("/")) path = path.slice(0, -1);
    return path;
  }

  function localizeAppURL(value) {
    try {
      const url = new URL(String(value), location.href);
      if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return value;
      const normalizedPath = stripAppBasePath(url.pathname);
      if (normalizedPath !== url.pathname ||
          (APP_BASE_PATH !== "/" && !url.pathname.startsWith(APP_BASE_PATH))) {
        url.pathname = appPath(normalizedPath);
      }
      return url.href;
    } catch (_) {
      return value;
    }
  }

  // OSM's SPA router emits root-relative history URLs. Keep its internal route
  // argument untouched, but prefix the browser address with this app's folder.
  function localizeHistoryURL(value) {
    if (value == null) return value;
    try {
      const url = new URL(String(value), window.location.href);
      if (url.origin === window.location.origin ||
          url.origin === "https://www.openstreetmap.org") {
        const normalizedPath = stripAppBasePath(url.pathname);
        url.pathname = appPath(normalizedPath);
        url.protocol = window.location.protocol;
        url.host = window.location.host;
        return url.pathname + url.search + url.hash;
      }
    } catch (_) {}
    return value;
  }

  const nativePushState = history.pushState.bind(history);
  const nativeReplaceState = history.replaceState.bind(history);
  history.pushState = function(state, title, url) {
    return nativePushState(state, title, localizeHistoryURL(url));
  };
  history.replaceState = function(state, title, url) {
    return nativeReplaceState(state, title, localizeHistoryURL(url));
  };

  const VERSION = "1.7.9";
  const STORAGE_PREFIX = "better-osm-org-static:";
  // The userscript and all pinned dependencies/resources are already compiled
  // into the ordinary local better-osm-merged.js file. No remote build occurs.
  const RESOURCES = {
    OAUTH_HTML: true,
    DARK_THEME_FOR_ID_CSS: true,
    REGIONAL_TAGINFOS: true
  };

  const nativeFetch = window.fetch.bind(window);
  const nativeXHR = window.XMLHttpRequest;
  const resourceText = Object.create(null);
  const resourceURL = Object.create(null);
  const menuCommands = [];

  // Userscript menu commands are registered for the existing Better OSM UI.
  // This merger does not add any buttons, badges, or notification panels.
  function ensureBetterOsmMenu() { /* no new DOM */ }

  // The original GM menu lives in a browser extension. Expose its existing
  // commands through modifier shortcuts without adding anything to OSM's UI.
  document.addEventListener("keydown", event => {
    if (!(event.ctrlKey && event.altKey && event.shiftKey) || event.repeat) return;
    const labels = {
      s: /settings|config|\u8BBE\u7F6E|\u914D\u7F6E/i,
      u: /check.*update|\u66F4\u65B0/i,
      h: /hotkeys|shortcut|\u5FEB\u6377\u952E/i,
      j: /josm/i,
      i: /oauth.*token|token/i,
      n: /☃|snow|\u96EA\u82B1/i
    };
    const matcher = labels[String(event.key || "").toLowerCase()];
    if (!matcher) return;
    const command = menuCommands.find(item => matcher.test(item.label));
    if (!command) return;
    event.preventDefault();
    event.stopPropagation();
    try { command.callback(); }
    catch (error) { console.error("[Better OSM menu]", error); }
  }, true);
  function showRuntimeNotice(message, kind = "warning") {
    if (kind === "error") console.error("[Better OSM]", message);
    else console.warn("[Better OSM]", message);
  }

  window.__betterOsmStatus = "native-runtime-starting";
  window.__betterOsmVersion = VERSION;
  window.__betterOsmNetwork = "php-curl-server-proxy";

  // Remove only authentication/session material captured from the source page.
  // Better OSM preferences remain in their own namespace.
  try {
    for (const key of Object.keys(localStorage)) {
      if (!key.startsWith(STORAGE_PREFIX) &&
          /(?:oauth|access[_-]?token|refresh[_-]?token|csrf|authenticity|session[_-]?token)/i.test(key)) {
        localStorage.removeItem(key);
      }
    }
  } catch (_) {}


  function makeProxyURL(target, referer) {
    const encoded = encodeURIComponent(String(target))
      .replace(/%7B/gi, "{")
      .replace(/%7D/gi, "}");
    let url = appPath("__proxy") + "?url=" + encoded;
    // Custom map layers may declare the Referer their upstream requires. The
    // browser cannot set Referer on fetch/XHR, so it is forwarded as a query
    // parameter for the PHP proxy to apply server-side (validated there).
    if (referer != null && /^(?:https?:\/\/)/i.test(String(referer))) {
      url += "&referer=" + encodeURIComponent(String(referer));
    }
    return url;
  }

  function sendServerRequest(details) {
    const controller = new AbortController();
    const method = String(details.method || "GET").toUpperCase();
    const timeoutMs = Math.max(0, Number(details.timeout || 0));
    let timeoutHandle = null;
    let timedOut = false;
    if (timeoutMs) timeoutHandle = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);
    const promise = (async () => {
      const suppliedHeaders = new Headers(details.headers || {});
      const headers = new Headers();
      let explicitReferer = String(details.referer || "");
      try {
        suppliedHeaders.forEach((value, key) => {
          const lowerKey = key.toLowerCase();
          if (lowerKey === "referer") {
            // Custom-layer Referer contracts are honoured through the proxy
            // query parameter instead of being silently dropped.
            if (!explicitReferer) explicitReferer = String(value);
            return;
          }
          if (!["host","origin","cookie","content-length"].includes(lowerKey) &&
              !lowerKey.startsWith("sec-")) headers.set(key, value);
        });
      } catch (_) {}

      const body = details.data;

      let response = await nativeFetch(makeProxyURL(details.url, explicitReferer), {
        method,
        headers,
        body: ["GET","HEAD"].includes(method) ? undefined : body,
        signal: controller.signal,
        credentials: "same-origin",
        redirect: "follow",
        cache: "no-store"
      });


      const buffer = await response.arrayBuffer();
      const contentType = response.headers.get("content-type") || "";
      const responseHeaders = [...response.headers.entries()].map(([key,value]) => `${key}: ${value}`).join("\r\n");
      const isText = /^(?:text\/|application\/(?:json|xml|javascript|x-www-form-urlencoded|[^;]+\+json|[^;]+\+xml))/.test(contentType);
      const text = isText ? new TextDecoder().decode(buffer) : "";
      const responseType = String(details.responseType || "text").toLowerCase();
      let value = text;
      if (responseType === "blob") value = new Blob([buffer], {type:contentType || "application/octet-stream"});
      else if (responseType === "arraybuffer") value = buffer;
      else if (responseType === "json") {
        try { value = JSON.parse(text); }
        catch (error) { throw new Error("Invalid JSON response: " + error.message); }
      } else if (responseType === "document" || responseType === "xml") {
        value = new DOMParser().parseFromString(text, "text/xml");
      } else if (responseType && responseType !== "text") value = buffer;

      const result = {
        status: response.status,
        statusText: response.statusText,
        finalUrl: response.headers.get("x-proxy-final-url") || details.url,
        response: value,
        responseText: isText ? text : "",
        responseHeaders
      };
      if (typeof details.onload === "function") details.onload(result);
      return result;
    })().catch(error => {
      if (timedOut && typeof details.ontimeout === "function") details.ontimeout(error);
      else if (controller.signal.aborted && typeof details.onabort === "function") details.onabort(error);
      else if (typeof details.onerror === "function") details.onerror(error);
      throw error;
    }).finally(() => {
      if (timeoutHandle) clearTimeout(timeoutHandle);
    });
    promise.abort = () => controller.abort();
    // Legacy GM_xmlhttpRequest callers normally use callbacks and ignore the
    // return value. Mark a rejected promise handled without changing the
    // promise they receive (await still rejects normally).
    promise.catch(() => {});
    return promise;
  }

  function serverFetch(url, init = {}) {
    const headers = {};
    try {
      new Headers(init.headers || {}).forEach((value, key) => { headers[key] = value; });
    } catch (_) {}
    delete headers.referer;
    delete headers.Referer;
    const method = String(init.method || "GET").toUpperCase();
    const request = sendServerRequest({
      url: String(url),
      method,
      headers,
      data: init.body,
      responseType: "arraybuffer",
      timeout: init.timeout || 0
    });
    if (init.signal) {
      if (init.signal.aborted) request.abort();
      else init.signal.addEventListener("abort", () => request.abort(), {once:true});
    }
    return request.then(result => {
      const rawHeaders = new Headers();
      String(result.responseHeaders || "").split(/\r?\n/).forEach(line => {
        const index = line.indexOf(":");
        if (index > 0) {
          try { rawHeaders.append(line.slice(0,index).trim(), line.slice(index+1).trim()); } catch (_) {}
        }
      });
      const status = result.status || 200;
      const body = [204,205,304].includes(status) ? null : result.response;
      return new Response(body, {
        status,
        statusText: result.statusText || "",
        headers: rawHeaders
      });
    });
  }

  function routeOfficial(input) {
    try {
      const raw = input instanceof Request ? input.url : String(input);
      const url = new URL(raw, APP_BASE_URL.href);
      if (url.origin === location.origin) {
        const appPathname = stripAppBasePath(url.pathname);
        const staticPaths = [
          "/", "/assets/", "/app-base.js.php", "/better-osm-runtime.js", "/better-osm-directions.js",
          "/panes/", "/directions", "/directions/", "/search", "/query", "/note/new", "/favicon", "/__proxy", "/__health", "/__deps.php", "/better-osm-union.php", "/better-osm-merged.js", "/better-osm-core.js", "/better-osm-original.user.js"
        ];
        if (staticPaths.some(path => path === "/"
          ? appPathname === "/"
          : path.endsWith("/")
            ? appPathname.startsWith(path)
            : appPathname === path || appPathname === path + "/")) {
          url.pathname = appPath(appPathname);
          return url.href;
        }
        // All other same-origin paths are official OSM Rails routes. Strip the
        // deployment prefix before sending them to the official upstream host.
        url.pathname = appPathname;
        url.protocol = "https:";
        // WHATWG URL keeps an existing explicit port when only host is
        // reassigned; clear it so the upstream URL is the canonical
        // https://www.openstreetmap.org/... endpoint.
        url.host = "www.openstreetmap.org";
        url.port = "";
        return url.href;
      }
      return raw;
    } catch (_) { return input; }
  }

  window.GM = {
    getValue: async (key, fallback) => {
      try {
        const value = localStorage.getItem(STORAGE_PREFIX + key);
        return value === null ? fallback : JSON.parse(value);
      } catch (_) { return fallback; }
    },
    setValue: async (key, value) => localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(value)),
    deleteValue: async key => localStorage.removeItem(STORAGE_PREFIX + key),
    listValues: async () => Object.keys(localStorage).filter(key => key.startsWith(STORAGE_PREFIX)).map(key => key.slice(STORAGE_PREFIX.length)),
    registerMenuCommand: (label, callback, accessKey) => {
      menuCommands.push({label:String(label), callback, accessKey});
      ensureBetterOsmMenu();
      return menuCommands.length;
    },
    openInTab: (url) => window.open(url, "_blank", "noopener"),
    addElement: (...args) => {
      let parent, tag, attrs;
      if (args.length === 3) [parent, tag, attrs] = args;
      else if (args.length === 2) [tag, attrs] = args;
      else return null;
      const element = document.createElement(tag);
      Object.entries(attrs || {}).forEach(([key,value]) => {
        if (key === "style" && value && typeof value === "object") Object.assign(element.style, value);
        else if (key in element && typeof value !== "object") {
          try { element[key] = value; } catch (_) { element.setAttribute(key, String(value)); }
        } else element.setAttribute(key, String(value));
      });
      if (parent) parent.appendChild(element);
      return element;
    },
    xmlHttpRequest: sendServerRequest,
    fetch: (url, options = {}) => serverFetch(url, options),
    getResourceText: name => resourceText[name] || window.__betterOsmResourceText?.[name] || "",
    getResourceUrl: name => window.GM_getResourceURL(name)
  };

  window.GM_info = {
    script: {name:"Better osm.org", version:VERSION},
    scriptHandler:"NativeStatic",
    version:"1.0.0"
  };
  window.GM_getValue = (key, fallback) => window.GM.getValue(key, fallback);
  window.GM_setValue = (key, value) => window.GM.setValue(key, value);
  window.GM_deleteValue = key => window.GM.deleteValue(key);
  window.GM_listValues = () => window.GM.listValues();
  window.GM_registerMenuCommand = window.GM.registerMenuCommand;
  window.GM_openInTab = window.GM.openInTab;
  window.GM_addElement = window.GM.addElement;
  window.GM_getResourceText = name => resourceText[name] || window.__betterOsmResourceText?.[name] || "";
  window.GM_getResourceURL = name => {
    if (resourceURL[name]) return resourceURL[name];
    const contents = window.__betterOsmResourceText?.[name];
    if (typeof contents !== 'string') return '';
    resourceURL[name] = URL.createObjectURL(new Blob([contents], {type:name.endsWith('_CSS')?'text/css':'text/html'}));
    return resourceURL[name];
  };
  window.unsafeWindow = window;
  window.exportFunction = fn => fn;
  window.cloneInto = value => value;

  // Static links in the supplied OSM snapshot can still contain official
  // absolute SPA URLs. Rebase only local map actions when clicked; deliberate
  // links to login, documentation, and third-party websites are left intact.
  document.addEventListener("click", event => {
    const anchor = event.target instanceof Element ? event.target.closest("a[href]") : null;
    if (!anchor) return;
    try {
      const dest = new URL(anchor.href, location.href);
      if (dest.origin !== "https://www.openstreetmap.org") return;
      if (!/^(?:\/(?:search|directions|export|query|history|note\/(?:new|\d+)|changeset\/\d+|(?:node|way|relation)\/\d+|account\/home)|\/user\/[^/]+\/history)(?:\/|$)/.test(dest.pathname)) return;
      anchor.setAttribute("href", appPath(dest.pathname) + dest.search + dest.hash);
    } catch (_) {}
  }, true);

  // Native page fetch adapter: local OSM routes and cross-origin requests use the
  // PHP/cURL endpoint, which sends upstream requests with server-side headers.
  window.fetch = async function(input, init = {}) {
    const isRequest = input instanceof Request;
    const raw = isRequest ? input.url : String(input);
    const url = routeOfficial(raw);
    const crossOrigin = (() => { try { return new URL(url, APP_BASE_URL.href).origin !== location.origin; } catch (_) { return false; } })();
    if (!crossOrigin) {
      const localInput = url !== raw
        ? (isRequest ? new Request(url, input) : url)
        : input;
      return nativeFetch(localInput, init);
    }
    if (isRequest) {
      const request = input.clone();
      const method = String(init.method || request.method).toUpperCase();
      const headers = new Headers(request.headers);
      if (init.headers) new Headers(init.headers).forEach((value,key) => headers.set(key,value));
      const body = init.body !== undefined ? init.body :
        (["GET","HEAD"].includes(method) ? undefined : await request.arrayBuffer());
      return serverFetch(url, {
        ...init, method, headers, body,
        signal:init.signal || request.signal
      });
    }
    return serverFetch(url, init);
  };
  // XMLHttpRequest adapter for OSM backend endpoints. Local static assets stay local;
  // remote OSM/third-party requests are sent by the PHP server.
  const nativeXhrOpen = nativeXHR.prototype.open;
  const nativeXhrSend = nativeXHR.prototype.send;
  const nativeXhrSetHeader = nativeXHR.prototype.setRequestHeader;
  const nativeXhrAbort = nativeXHR.prototype.abort;
  const nativeXhrGetters = Object.fromEntries(
    ["readyState","status","statusText","response","responseText","responseURL"].map(name => [
      name, Object.getOwnPropertyDescriptor(nativeXHR.prototype, name)?.get
    ])
  );
  function NativeBridgeXHR() {
    const xhr = new nativeXHR();
    const state = {
      remote:false, method:"GET", url:"", headers:{}, body:null,
      readyState:0, status:0, statusText:"", response:null,
      responseText:"", responseURL:"", responseHeaders:"", promise:null, request:null, aborted:false, sequence:0
    };
    const define = (name, getter) => {
      try { Object.defineProperty(xhr, name, {configurable:true, enumerable:true, get:getter}); } catch (_) {}
    };
    define("readyState", () => state.remote ? state.readyState : nativeXhrGetters.readyState.call(xhr));
    define("status", () => state.remote ? state.status : nativeXhrGetters.status.call(xhr));
    define("statusText", () => state.remote ? state.statusText : nativeXhrGetters.statusText.call(xhr));
    define("response", () => state.remote ? state.response : nativeXhrGetters.response.call(xhr));
    define("responseText", () => state.remote ? state.responseText : nativeXhrGetters.responseText.call(xhr));
    define("responseURL", () => state.remote ? state.responseURL : nativeXhrGetters.responseURL.call(xhr));
    xhr.open = function(method, url, async = true, user, password) {
      // Native XHR invalidates its active request on a subsequent open().
      // Retain that rule for the PHP-proxy branch; otherwise an old response
      // can be mistaken for the newly opened request.
      state.sequence++;
      if (state.remote) {
        state.request?.abort?.();
      } else {
        // A previous ordinary same-origin XHR can still be in flight.
        // Transitioning to a proxy request must cancel it as native open() does.
        const nativeReady = nativeXhrGetters.readyState?.call(xhr) ?? 0;
        if (nativeReady > 0 && nativeReady < 4) nativeXhrAbort.call(xhr);
      }
      state.request = null;
      state.promise = null;
      const routed = routeOfficial(url);
      let remote = false;
      try { remote = new URL(routed, location.href).origin !== location.origin; } catch (_) {}
      if (!remote) {
        state.remote = false;
        return nativeXhrOpen.call(xhr, method, routed, async, user, password);
      }
      state.remote = true;
      state.method = String(method || "GET").toUpperCase();
      state.url = routed;
      state.headers = {};
      state.body = null;
      state.readyState = 1;
      state.status = 0;
      state.statusText = "";
      state.response = null;
      state.responseText = "";
      state.responseURL = "";
      state.responseHeaders = "";
      state.aborted = false;
      state.request = null;
      state.promise = null;
      xhr.dispatchEvent(new Event("readystatechange"));
    };
    xhr.setRequestHeader = function(name, value) {
      if (!state.remote) return nativeXhrSetHeader.call(xhr, name, value);
      state.headers[name] = value;
    };
    xhr.send = function(body = null) {
      if (!state.remote) return nativeXhrSend.call(xhr, body);
      state.body = body;
      state.aborted = false;
      const sequence = state.sequence;
      state.request = sendServerRequest({
        url:state.url, method:state.method, headers:state.headers, data:body,
        responseType:xhr.responseType || "text", timeout:xhr.timeout || 45000,
        withCredentials:false
      });
      state.promise = state.request.then(result => {
        if (state.aborted || sequence !== state.sequence) return;
        state.status = result.status;
        state.statusText = result.statusText;
        state.responseURL = result.finalUrl;
        state.responseHeaders = result.responseHeaders;
        state.response = result.response;
        state.responseText = typeof result.response === "string" ? result.response : "";
        state.readyState = 2; xhr.dispatchEvent(new Event("readystatechange"));
        state.readyState = 3; xhr.dispatchEvent(new Event("readystatechange"));
        state.readyState = 4; xhr.dispatchEvent(new Event("readystatechange"));
        xhr.dispatchEvent(new Event("load"));
        xhr.dispatchEvent(new Event("loadend"));
      }).catch(error => {
        if (state.aborted || sequence !== state.sequence) return;
        state.readyState = 4;
        xhr.dispatchEvent(new Event(error.name === "AbortError" ? "abort" : "error"));
        xhr.dispatchEvent(new Event("loadend"));
        xhr.dispatchEvent(new Event("readystatechange"));
      });
    };
    xhr.abort = function() {
      if (!state.remote) return nativeXhrAbort.call(xhr);
      if (state.aborted) return;
      state.aborted = true;
      state.sequence++;
      state.request?.abort?.();
      state.request = null;
      state.readyState = 0;
      state.status = 0;
      xhr.dispatchEvent(new Event("abort"));
      xhr.dispatchEvent(new Event("loadend"));
    };
    xhr.getAllResponseHeaders = function() {
      if (!state.remote) return nativeXHR.prototype.getAllResponseHeaders.call(xhr);
      return state.responseHeaders;
    };
    xhr.getResponseHeader = function(name) {
      if (!state.remote) return nativeXHR.prototype.getResponseHeader.call(xhr, name);
      const line = state.responseHeaders.split(/\r?\n/).find(x => x.toLowerCase().startsWith(String(name).toLowerCase()+":"));
      return line ? line.slice(line.indexOf(":")+1).trim() : null;
    };
    return xhr;
  }
  NativeBridgeXHR.prototype = nativeXHR.prototype;
  Object.assign(NativeBridgeXHR, {
    UNSENT:0, OPENED:1, HEADERS_RECEIVED:2, LOADING:3, DONE:4
  });
  window.XMLHttpRequest = NativeBridgeXHR;

  // Older GM APIs used by a few upstream helper modules.
  window.GM_xmlhttpRequest = sendServerRequest;
  window.GM.xmlHttpRequest = sendServerRequest;

  function hexToBase64(hex) {
    const bytes = new Uint8Array(hex.match(/.{2}/g).map(pair => parseInt(pair,16)));
    let binary = "";
    bytes.forEach(byte => binary += String.fromCharCode(byte));
    return btoa(binary);
  }
  async function sha256Matches(text, expected) {
    const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
    const actual = [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2,"0")).join("");
    return actual.toLowerCase() === expected.toLowerCase();
  }

  // The verified dependencies and the uploaded original source are bundled in
  // the static better-osm-merged.js at build time; PHP only proxies live data.

  function proxyResourceURL(value) {
    if (typeof value !== "string" || !value) return value;
    const trimmed = value.trim();
    if (/^(?:data:|blob:|javascript:|mailto:|tel:|#)/i.test(trimmed)) return value;
    try {
      const absolute = new URL(trimmed, APP_BASE_URL.href);
      if (!/^https?:$/.test(absolute.protocol)) return value;
      if (absolute.origin === location.origin) return localizeAppURL(absolute.href);
      return makeProxyURL(absolute.href);
    } catch (_) {
      return value;
    }
  }

  window.__betterOsmProxyResourceURL = proxyResourceURL;

  function proxySrcset(value) {
    if (typeof value !== "string") return value;
    return value.split(",").map(part => {
      const bits = part.trim().split(/\s+/);
      if (!bits[0]) return part;
      bits[0] = proxyResourceURL(bits[0]);
      return bits.join(" ");
    }).join(", ");
  }

  function proxyCssValue(value) {
    if (typeof value !== "string" || !/(?:url\(|@import\s)/i.test(value)) return value;
    return value.replace(/url\(\s*(["']?)([^"')]+)\1\s*\)/gi,
      (_match, quote, rawUrl) => {
        const url = String(rawUrl).trim();
        if (/^\/(?!\/)/.test(url)) return `url("${localizeAppURL(url)}")`;
        if (/^https?:\/\//i.test(url)) return `url("${proxyResourceURL(url)}")`;
        return `url("${url}")`;
      }).replace(/@import\s+(["'])(https?:\/\/[^"']+|\/(?!\/)[^"']+)\1/gi,
        (_match, quote, rawUrl) => {
          const url = rawUrl.startsWith("/") ? localizeAppURL(rawUrl) : proxyResourceURL(rawUrl);
          return `@import url("${url}")`;
        });
  }

  function shouldProxyAttribute(element, name) {
    const tag = element?.tagName?.toLowerCase();
    if (["src","srcset","poster","data"].includes(name)) return true;
    if (name === "href" && tag === "link") {
      return /(?:stylesheet|icon|preload|modulepreload|manifest)/i.test(element.rel || "");
    }
    return false;
  }

  function rewriteResourceHTML(markup) {
    if (typeof markup !== "string") return markup;
    return markup.replace(
      /(\b(?:src|poster|data)\s*=\s*)(["'])(https?:\/\/[^"']+|\/(?!\/)[^"']*)\2/gi,
      (_m, prefix, quote, url) => prefix + quote + proxyResourceURL(url) + quote
    ).replace(
      /(\bsrcset\s*=\s*)(["'])([^"']+)\2/gi,
      (_m, prefix, quote, value) => prefix + quote + proxySrcset(value) + quote
    ).replace(
      /(<link\b[^>]*\brel\s*=\s*["'][^"']*(?:stylesheet|icon|preload|modulepreload|manifest)[^"']*["'][^>]*\bhref\s*=\s*)(["'])(https?:\/\/[^"']+|\/(?!\/)[^"']*)\2/gi,
      (_m, prefix, quote, url) => prefix + quote + proxyResourceURL(url) + quote
    ).replace(
      /(<form\b[^>]*\baction\s*=\s*)(["'])(https?:\/\/[^"']+)\2/gi,
      (_m, prefix, quote, url) => prefix + quote + proxyResourceURL(url) + quote
    ).replace(
      /(<(?:a|area)\b[^>]*\bhref\s*=\s*)(["'])(\/(?!\/)[^"']*)\2/gi,
      (_m, prefix, quote, url) => prefix + quote + localizeAppURL(url) + quote
    ).replace(
      /(\baction\s*=\s*)(["'])(\/(?!\/)[^"']*)\2/gi,
      (_m, prefix, quote, url) => prefix + quote + localizeAppURL(url) + quote
    ).replace(
      /(\bstyle\s*=\s*)(["'])([^"']*)\2/gi,
      (_m, prefix, quote, value) => prefix + quote + proxyCssValue(value) + quote
    );
  }

  function installResourceRequestProxy() {
    const originalSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name, value) {
      const key = String(name).toLowerCase();
      const tag = this?.tagName?.toLowerCase();
      if (key === "href" && (tag === "a" || tag === "area")) {
        value = localizeAppURL(String(value));
      } else if (key === "action" && tag === "form") {
        value = localizeAppURL(String(value));
      } else if (shouldProxyAttribute(this, key)) {
        value = key === "srcset" ? proxySrcset(String(value)) : proxyResourceURL(String(value));
      } else if (key === "style") {
        value = proxyCssValue(String(value));
      }
      return originalSetAttribute.call(this, name, value);
    };

    const propertyTargets = [
      [window.HTMLImageElement, "src"], [window.HTMLScriptElement, "src"],
      [window.HTMLIFrameElement, "src"], [window.HTMLSourceElement, "src"],
      [window.HTMLVideoElement, "src"], [window.HTMLAudioElement, "src"],
      [window.HTMLTrackElement, "src"], [window.HTMLEmbedElement, "src"],
      [window.HTMLObjectElement, "data"]
    ];
    for (const [ctor, property] of propertyTargets) {
      const descriptor = ctor && Object.getOwnPropertyDescriptor(ctor.prototype, property);
      if (!descriptor?.set || !descriptor?.get || descriptor.configurable === false) continue;
      try {
        Object.defineProperty(ctor.prototype, property, {
          configurable:true, enumerable:descriptor.enumerable,
          get:descriptor.get,
          set(value) { return descriptor.set.call(this, proxyResourceURL(String(value))); }
        });
      } catch (_) {}
    }

    const linkDescriptor = window.HTMLLinkElement &&
      Object.getOwnPropertyDescriptor(HTMLLinkElement.prototype, "href");
    if (linkDescriptor?.set && linkDescriptor?.get && linkDescriptor.configurable !== false) {
      try {
        Object.defineProperty(HTMLLinkElement.prototype, "href", {
          configurable:true, enumerable:linkDescriptor.enumerable,
          get:linkDescriptor.get,
          set(value) {
            const shouldProxy = /(?:stylesheet|icon|preload|modulepreload|manifest)/i.test(this.rel || "");
            return linkDescriptor.set.call(this, shouldProxy ? proxyResourceURL(String(value)) : value);
          }
        });
      } catch (_) {}
    }

    const styleSetProperty = CSSStyleDeclaration.prototype.setProperty;
    CSSStyleDeclaration.prototype.setProperty = function(name, value, priority) {
      return styleSetProperty.call(this, name, proxyCssValue(String(value)), priority);
    };

    for (const property of ["backgroundImage","borderImageSource","listStyleImage","maskImage","cursor"]) {
      const descriptor = Object.getOwnPropertyDescriptor(CSSStyleDeclaration.prototype, property);
      if (!descriptor?.set || !descriptor?.get || descriptor.configurable === false) continue;
      try {
        Object.defineProperty(CSSStyleDeclaration.prototype, property, {
          configurable:true, enumerable:descriptor.enumerable,
          get:descriptor.get,
          set(value) { return descriptor.set.call(this, proxyCssValue(String(value))); }
        });
      } catch (_) {}
    }
    const originalInsertRule = CSSStyleSheet.prototype.insertRule;
    CSSStyleSheet.prototype.insertRule = function(rule, index) {
      return originalInsertRule.call(this, proxyCssValue(String(rule)), index);
    };

    const innerHTMLDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, "innerHTML");
    if (innerHTMLDescriptor?.set && innerHTMLDescriptor?.get && innerHTMLDescriptor.configurable !== false) {
      try {
        Object.defineProperty(Element.prototype, "innerHTML", {
          configurable:true, enumerable:innerHTMLDescriptor.enumerable,
          get:innerHTMLDescriptor.get,
          set(value) { return innerHTMLDescriptor.set.call(this, rewriteResourceHTML(String(value))); }
        });
      } catch (_) {}
    }

    const originalInsertAdjacentHTML = Element.prototype.insertAdjacentHTML;
    Element.prototype.insertAdjacentHTML = function(position, text) {
      return originalInsertAdjacentHTML.call(this, position, rewriteResourceHTML(String(text)));
    };

    const observer = new MutationObserver(records => {
      for (const record of records) {
        if (record.type === "attributes" &&
            (shouldProxyAttribute(record.target, record.attributeName) || record.attributeName === "style" ||
             (record.attributeName === "rel" && record.target.tagName?.toLowerCase() === "link") ||
             (record.attributeName === "href" && ["a","area"].includes(record.target.tagName?.toLowerCase())) ||
             (record.attributeName === "action" && record.target.tagName?.toLowerCase() === "form"))) {
          const key = record.attributeName.toLowerCase() === "rel" ? "href" : record.attributeName.toLowerCase();
          const tag = record.target.tagName?.toLowerCase();
          if (!shouldProxyAttribute(record.target, key) && key !== "style" &&
              !(key === "href" && ["a","area"].includes(tag)) && !(key === "action" && tag === "form")) continue;
          const current = record.target.getAttribute(key);
          if (!current) continue;
          const proxied = key === "srcset" ? proxySrcset(current) :
            key === "style" ? proxyCssValue(current) :
            (key === "href" && ["a","area"].includes(tag)) || (key === "action" && tag === "form")
              ? localizeAppURL(current) : proxyResourceURL(current);
          if (proxied !== current) originalSetAttribute.call(record.target, key, proxied);
        }
        for (const node of record.addedNodes || []) {
          if (node.nodeType !== 1) continue;
          const candidates = [node, ...node.querySelectorAll?.("[src],[srcset],[poster],[data],link[rel]") || []];
          for (const el of candidates) {
            for (const attr of ["src","srcset","poster","data","href"]) {
              if (!el.hasAttribute?.(attr) || !shouldProxyAttribute(el, attr)) continue;
              const current = el.getAttribute(attr);
              const proxied = attr === "srcset" ? proxySrcset(current) : proxyResourceURL(current);
              if (proxied !== current) originalSetAttribute.call(el, attr, proxied);
            }
          }
        }
      }
    });
    observer.observe(document.documentElement, {
      subtree:true, childList:true, attributes:true,
      attributeFilter:["src","srcset","poster","data","href","action","style","rel"]
    });
  }

  try {
    const nativeSendBeacon = navigator.sendBeacon?.bind(navigator);
    if (nativeSendBeacon) {
      navigator.sendBeacon = function(url, data) {
        const raw = String(url);
        const routed = routeOfficial(raw);
        let absolute;
        try { absolute = new URL(routed, APP_BASE_URL.href); } catch (_) { return nativeSendBeacon(url, data); }
        if (absolute.origin === location.origin) return nativeSendBeacon(absolute.href, data);
        return nativeSendBeacon(makeProxyURL(absolute.href), data);
      };
    }
  } catch (_) {}

  function proxyTileTemplate(value, referer) {
    if (typeof value !== "string") return value;
    const target = value.startsWith("//") ? location.protocol + value : value;
    if (/^(?:data:|blob:)/i.test(target)) return target;
    if (target.startsWith("/")) return localizeAppURL(target);
    if (!/^https?:\/\//i.test(target)) return value;
    try {
      const comparable = target.replace(/\{[^}]+\}/g, "0");
      const resolved = new URL(comparable, APP_BASE_URL);
      if (resolved.origin === location.origin) return localizeAppURL(target);
      return makeProxyURL(target, referer);
    } catch (_) { return value; }
  }

  function installLeafletProxy() {
    const L = window.L;
    if (L?.Map?.addInitHook && !L.Map.__betterOsmReferenceHook) {
      L.Map.addInitHook(function() {
        if (this._container?.id === "map") {
          window.__osmMap = this;
          window.dispatchEvent(new Event("osm-map-ready"));
        }
      });
      L.Map.__betterOsmReferenceHook = true;
    }
    if (!L?.tileLayer || L.tileLayer.__betterOsmProxyPatched) return;
    // Cover direct new L.TileLayer(...) calls as well as factory-created tiles.
    const tilePrototype = L.TileLayer?.prototype;
    if (tilePrototype?.getTileUrl && !tilePrototype.__betterOsmGetTileUrlProxy) {
      const nativeGetTileUrl = tilePrototype.getTileUrl;
      tilePrototype.getTileUrl = function(coords) {
        return proxyTileTemplate(nativeGetTileUrl.call(this, coords));
      };
      tilePrototype.__betterOsmGetTileUrlProxy = true;
    }
    const original = L.tileLayer;
    const wrapped = function(url, options) {
      return original.call(this, proxyTileTemplate(url), options);
    };
    Object.assign(wrapped, original);
    if (typeof original.wms === "function") {
      wrapped.wms = function(url, params, options) {
        return original.wms(proxyTileTemplate(url), params, options);
      };
    }
    wrapped.__betterOsmProxyPatched = true;
    L.tileLayer = wrapped;

    // MapLibre performs tile/style/glyph requests in workers. Its transformRequest
    // hook runs before those requests leave the MapLibre request manager.
    const glPrototype = (L.OSM?.MaplibreGL || L.MaplibreGL)?.prototype;
    if (glPrototype?.initialize && !glPrototype.__betterOsmProxyPatched) {
      const originalInitialize = glPrototype.initialize;
      glPrototype.initialize = function(options = {}) {
        const priorTransform = options.transformRequest;
        const patchedOptions = {
          ...options,
          transformRequest(url, resourceType) {
            let transformed = priorTransform ? priorTransform(url, resourceType) : {url};
            if (typeof transformed === "string") transformed = {url:transformed};
            transformed = transformed || {url};
            return {...transformed, url:proxyTileTemplate(transformed.url || url)};
          }
        };
        return originalInitialize.call(this, patchedOptions);
      };
      glPrototype.__betterOsmProxyPatched = true;
    }
  }


  // Compiled Better OSM calls this immediately before its own code.
  // The OSM map entry stays after the merged script in the original HTML.
  window.__betterOsmProxyTileURL = (value, referer) => proxyTileTemplate(value, referer);
  window.__betterOsmProxyRequestURL = proxyResourceURL;
  window.__betterOsmPrepareMap = () => {
    installLeafletProxy();
    // The captured OpenMapTiles class was retargeted from a MapTiler style to
    // OpenFreeMap Liberty. The old MapTiler language transformer must not
    // rewrite arbitrary Liberty text-field expressions: doing so can create
    // invalid MapLibre expressions and poison the placement renderer.
    const mapLibre = window.OSM?.MapLibre;
    if (mapLibre && typeof mapLibre.setOMTMapLanguage === "function" &&
        !mapLibre.setOMTMapLanguage.__betterOsmOnlyMapTiler) {
      const originalLanguage = mapLibre.setOMTMapLanguage;
      const guardedLanguage = function(gl) {
        let metadata;
        try {
          const style = gl?.getStyle?.();
          metadata = style?.metadata || gl?.style?.stylesheet?.metadata;
        } catch (_) {
          // The style object may not be available until MapLibre finishes
          // loading; never turn that timing difference into a page exception.
          return;
        }
        if (!metadata || !Object.prototype.hasOwnProperty.call(metadata, "maptiler:language")) return;
        return originalLanguage.call(this, gl);
      };
      guardedLanguage.__betterOsmOnlyMapTiler = true;
      mapLibre.setOMTMapLanguage = guardedLanguage;
    }
    if (window.OSM?.MODULE_PATHS) {
      for (const [name,path] of Object.entries(window.OSM.MODULE_PATHS)) {
        if (typeof path !== 'string') continue;
        const localized = path.startsWith('/assets/') ? appPath(path.slice(1)) : path;
        if (localized.startsWith(APP_BASE_PATH + 'assets/index_modules/')) {
          const separator = localized.includes('?') ? '&' : '?';
          window.OSM.MODULE_PATHS[name] = localized + separator + 'v=configured-base-20261105-b';
        } else if (localized !== path) {
          window.OSM.MODULE_PATHS[name] = localized;
        }
      }
    }
    window.__betterOsmStatus = 'integrated-source-ready';
  };

  // The official bundle writes every cookie with the Secure attribute
  // (OSM.cookies = Cookies.withAttributes({path:"/", samesite:"lax",
  // secure:true})), which is correct on the HTTPS-only production site.
  // On a plain-HTTP deployment the browser silently rejects Secure cookies,
  // which would break the official language dialog (_osm_locale) and the
  // last-position cookie (_osm_location). Relax Secure only when the page
  // itself is not secure; HTTPS deployments keep the official behavior.
  function relaxCookieSecureOnInsecureOrigin() {
    if (location.protocol === 'https:') return;
    const patch = () => {
      try {
        if (window.OSM?.cookies?.withAttributes) {
          window.OSM.cookies = window.OSM.cookies.withAttributes({ secure: false });
        }
      } catch (_) {}
    };
    if (window.OSM?.cookies) patch();
    else document.addEventListener('DOMContentLoaded', patch, { once: true });
  }

  // Official server-rendered strings (nav bar, search forms, dialogs) are
  // baked into the captured index.html in English: Rails renders them per
  // locale on the official site, but this static mirror ships one HTML file.
  // router.php already rewrites <html lang> and the assets/i18n bundle from
  // the _osm_locale cookie / ?locale= parameter, which translates every
  // JS-side string; the server-rendered strings are translated here at
  // DOM-ready. Values follow the official openstreetmap-website locale
  // files (translatewiki exports), e.g. layouts.header / site.search.
  const SERVER_STRING_TRANSLATIONS = {
    "zh-CN": {
      "Where is this?": "\u8fd9\u662f\u54ea\u91cc\uff1f",
      "Describe the current location using the search engine": "\u4f7f\u7528\u641c\u7d22\u5f15\u64ce\u8bf4\u660e\u5f53\u524d\u4f4d\u7f6e",
      "Search": "\u641c\u7d22",
      "Search Results": "\u641c\u7d22\u7ed3\u679c",
      "Go": "\u63d0\u4ea4",
      "Find directions between two points": "\u67e5\u627e\u4e24\u70b9\u4e4b\u95f4\u7684\u65b9\u5411",
      "Car": "\u6c7d\u8f66",
      "Bicycle": "\u81ea\u884c\u8f66",
      "Foot": "\u6b65\u884c",
      "Directions services": "\u5bfc\u822a\u670d\u52a1",
      "From": "\u4ece",
      "To": "\u81f3",
      "Reverse Directions": "\u6362\u5411",
      "Edit": "\u7f16\u8f91",
      "Edit with iD (in-browser editor)": "\u4f7f\u7528 iD (\u6d4f\u89c8\u5668\u5185\u7f16\u8f91\u5668) \u7f16\u8f91",
      "Edit with Remote Control (JOSM, Potlatch, Merkaartor)": "\u4f7f\u7528 \u8fdc\u7a0b\u63a7\u5236 (JOSM\u3001Potlatch\u3001Merkaartor) \u7f16\u8f91",
      "History": "\u5386\u53f2",
      "Export": "\u5bfc\u51fa",
      "GPS Traces": "GPS \u8f68\u8ff9",
      "User Diaries": "\u7528\u6237\u65e5\u8bb0",
      "Communities": "\u793e\u533a",
      "Copyright": "\u8457\u4f5c\u6743",
      "Help": "\u5e2e\u52a9",
      "Donate": "\u6350\u8d60",
      "About": "\u5173\u4e8e",
      "More": "\u66f4\u591a",
      "Select Language": "\u9009\u62e9\u8bed\u8a00",
      "Log in": "\u767b\u5f55",
      "Sign up": "\u6ce8\u518c",
      "Loading...": "\u52a0\u8f7d\u4e2d\u2026\u2026",
      "Close": "\u5173\u95ed",
      "No matching places found.": "\u672a\u627e\u5230\u5339\u914d\u7684\u5730\u70b9\u3002",
      "Query Features": "\u67e5\u8be2\u8981\u7d20",
      "Nearby features": "\u9644\u8fd1\u8981\u7d20",
      "Enclosing features": "\u5c01\u95ed\u8981\u7d20",
      "Click on the map to find nearby features.": "\u70b9\u51fb\u5730\u56fe\u4ee5\u67e5\u627e\u9644\u8fd1\u8981\u7d20\u3002",
      "You are either using a browser that does not support JavaScript, or you have disabled JavaScript.": "\u60a8\u4f7f\u7528\u7684\u6d4f\u89c8\u5668\u4e0d\u652f\u6301 JavaScript\uff0c\u6216\u8005\u60a8\u5df2\u7981\u7528 JavaScript\u3002",
      "OpenStreetMap uses JavaScript for its slippy map.": "OpenStreetMap \u7684\u6ed1\u5757\u5730\u56fe\u9700\u8981 JavaScript \u652f\u6301\u3002"
    }
  };
  const serverStringOriginalText = new WeakMap();
  const serverStringOriginalAttribute = new WeakMap();
  let serverStringApplyQueued = false;

  function applyServerStringTranslations() {
    let locale = "en";
    try {
      locale = (document.documentElement.getAttribute("lang") || "en").trim().replace(/_/g, "-");
    } catch (_) {}
    const dictionary = SERVER_STRING_TRANSLATIONS[locale] || null;
    const body = document.body;
    if (!body) return;
    const exact = (value) => {
      if (!dictionary) return null;
      const trimmed = value.trim();
      if (!Object.prototype.hasOwnProperty.call(dictionary, trimmed)) return null;
      const translated = dictionary[trimmed];
      return trimmed === "" || translated === trimmed ? null : [trimmed, translated];
    };
    // Text nodes: remember each node's original value so re-running after
    // sidebar injections (or switching back to English) stays idempotent.
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, null);
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);
    for (const node of textNodes) {
      const value = node.nodeValue || "";
      if (!value.trim()) continue;
      if (!serverStringOriginalText.has(node)) serverStringOriginalText.set(node, value);
      const original = serverStringOriginalText.get(node) || value;
      const hit = exact(original);
      node.nodeValue = hit ? original.replace(hit[0], hit[1]) : original;
    }
    for (const element of body.querySelectorAll("[placeholder],[title],[aria-label]")) {
      for (const attribute of ["placeholder", "title", "aria-label"]) {
        if (!element.hasAttribute(attribute)) continue;
        const value = element.getAttribute(attribute) || "";
        if (!value.trim()) continue;
        if (!serverStringOriginalAttribute.has(element)) {
          serverStringOriginalAttribute.set(element, {});
        }
        const originals = serverStringOriginalAttribute.get(element);
        if (!Object.prototype.hasOwnProperty.call(originals, attribute)) originals[attribute] = value;
        const original = originals[attribute] || value;
        const hit = exact(original);
        const translated = hit ? original.replace(hit[0], hit[1]) : original;
        if (element.getAttribute(attribute) !== translated) {
          element.setAttribute(attribute, translated);
        }
      }
    }
  }

  function queueServerStringTranslations() {
    if (serverStringApplyQueued) return;
    serverStringApplyQueued = true;
    const run = () => {
      serverStringApplyQueued = false;
      try { applyServerStringTranslations(); } catch (_) {}
    };
    if (typeof requestAnimationFrame === "function") requestAnimationFrame(run);
    else setTimeout(run, 0);
  }

  // Sidebar panes and modal bodies (search results, query results, notes…)
  // are injected later by Turbo/fetch; re-translate whatever arrives.
  const serverStringObserver = new MutationObserver(records => {
    for (const record of records) {
      if (record.addedNodes && record.addedNodes.length) {
        queueServerStringTranslations();
        return;
      }
    }
  });
  const startServerStringTranslations = () => {
    applyServerStringTranslations();
    serverStringObserver.observe(document.body, {childList: true, subtree: true});
    document.addEventListener("turbo:frame-load", queueServerStringTranslations, true);
  };
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startServerStringTranslations, {once: true});
  } else {
    startServerStringTranslations();
  }

  installResourceRequestProxy();
  relaxCookieSecureOnInsecureOrigin();
})();
