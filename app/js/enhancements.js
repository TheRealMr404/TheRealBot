(function () {
  "use strict";

  var ENHANCEMENT_VERSION = "1.0.0";
  var APP_VERSION_URL = new URL("./version", document.baseURI).toString();
  var CACHE_PREFIX = "mirza-miniapp-";
  var CONFIG_PATTERN = /\b(?:vmess|vless|trojan|ss|ssr|hysteria2?|tuic|wireguard|wg):\/\/[^\s"'<>]+/gi;
  var mutationTimer = null;
  var currentServiceFilter = "all";
  var telegram = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

  document.documentElement.lang = "fa";
  document.documentElement.dir = "rtl";

  function icon(name) {
    var icons = {
      tools: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8.2 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 3.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H1.8V9.6h.1A1.7 1.7 0 0 0 3.6 8.2a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8 3.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V1.8h4v.1A1.7 1.7 0 0 0 15 3.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8c.16.38.36.72.6 1 .28.3.67.45 1.1.45h.1v4h-.1A1.7 1.7 0 0 0 19.4 15Z"/></svg>',
      up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>'
    };
    return icons[name] || "";
  }

  function haptic(type) {
    try {
      if (!telegram || !telegram.HapticFeedback) return;
      if (type === "success") telegram.HapticFeedback.notificationOccurred("success");
      else if (type === "error") telegram.HapticFeedback.notificationOccurred("error");
      else if (type === "selection") telegram.HapticFeedback.selectionChanged();
      else telegram.HapticFeedback.impactOccurred("light");
    } catch (_) {}
  }

  function toast(message, duration) {
    var old = document.querySelector(".mirza-toast");
    if (old) old.remove();
    var el = document.createElement("div");
    el.className = "mirza-toast";
    el.setAttribute("role", "status");
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(function () {
      if (el.parentNode) el.remove();
    }, duration || 2600);
  }

  function copyText(text) {
    if (!text) return Promise.reject(new Error("empty"));
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var area = document.createElement("textarea");
      area.value = text;
      area.setAttribute("readonly", "");
      area.style.position = "fixed";
      area.style.opacity = "0";
      document.body.appendChild(area);
      area.select();
      try {
        document.execCommand("copy") ? resolve() : reject(new Error("copy failed"));
      } catch (error) {
        reject(error);
      } finally {
        area.remove();
      }
    });
  }

  function downloadText(filename, text) {
    var blob = new Blob([text], { type: "text/plain;charset=utf-8" });
    var link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
  }

  function collectConfigs() {
    var matches = (document.body.innerText || "").match(CONFIG_PATTERN) || [];
    var clean = matches.map(function (value) {
      return value.replace(/[)،,.؛;]+$/, "");
    });
    return Array.from(new Set(clean));
  }

  function setupTelegram() {
    if (!telegram) return;
    try {
      telegram.ready();
      telegram.expand();
      if (telegram.setHeaderColor) telegram.setHeaderColor("secondary_bg_color");
      if (telegram.setBackgroundColor) telegram.setBackgroundColor("bg_color");
    } catch (_) {}

    if (telegram.BackButton) {
      try {
        telegram.BackButton.onClick(function () {
          var state = window.history.state;
          if (state && typeof state.idx === "number" && state.idx > 0) window.history.back();
          else window.location.href = new URL("./services", document.baseURI).toString();
        });
      } catch (_) {}
    }
  }

  function updateTelegramBackButton() {
    if (!telegram || !telegram.BackButton) return;
    var relative = window.location.pathname.replace(/\/+$/, "");
    var isDetail = /\/services\/[^/]+$/.test(relative);
    try {
      if (isDetail) telegram.BackButton.show();
      else telegram.BackButton.hide();
    } catch (_) {}
  }

  function patchHistory() {
    ["pushState", "replaceState"].forEach(function (method) {
      var original = window.history[method];
      if (!original || original.__mirzaPatched) return;
      var patched = function () {
        var result = original.apply(this, arguments);
        window.dispatchEvent(new Event("mirza:routechange"));
        return result;
      };
      patched.__mirzaPatched = true;
      window.history[method] = patched;
    });
    window.addEventListener("popstate", function () {
      window.dispatchEvent(new Event("mirza:routechange"));
    });
    window.addEventListener("mirza:routechange", function () {
      updateTelegramBackButton();
      scheduleEnhance();
      window.setTimeout(function () { window.scrollTo({ top: 0, behavior: "smooth" }); }, 30);
    });
  }

  function setupHaptics() {
    document.addEventListener("click", function (event) {
      var target = event.target.closest && event.target.closest("button, a, [role='button']");
      if (target && !target.disabled) haptic("impact");
    }, true);
    document.addEventListener("change", function () { haptic("selection"); }, true);
  }

  function showNetworkState(online, temporary) {
    var banner = document.querySelector(".mirza-network-banner");
    if (!banner) {
      banner = document.createElement("div");
      banner.className = "mirza-network-banner";
      banner.setAttribute("role", "status");
      document.body.appendChild(banner);
    }
    banner.dataset.state = online ? "online" : "offline";
    banner.textContent = online ? "اتصال اینترنت دوباره برقرار شد" : "اتصال اینترنت قطع است؛ اطلاعات قبلی همچنان قابل مشاهده است";
    banner.hidden = false;
    if (temporary) {
      window.setTimeout(function () { banner.hidden = true; }, 2600);
    }
  }

  function setupNetworkMonitor() {
    if (!navigator.onLine) showNetworkState(false, false);
    window.addEventListener("offline", function () {
      showNetworkState(false, false);
      haptic("error");
    });
    window.addEventListener("online", function () {
      showNetworkState(true, true);
      haptic("success");
    });
  }

  function getDiagnostics() {
    var errors = [];
    try { errors = JSON.parse(localStorage.getItem("mirza:errors") || "[]"); } catch (_) {}
    var version = localStorage.getItem("mirza:current-version") || "نامشخص";
    var tgInfo = telegram ? {
      version: telegram.version || "نامشخص",
      platform: telegram.platform || "نامشخص",
      colorScheme: telegram.colorScheme || "نامشخص"
    } : { status: "خارج از تلگرام" };
    return [
      "گزارش عیب‌یابی مینی‌اپ میرزا",
      "نسخه برنامه: " + version,
      "نسخه افزونه: " + ENHANCEMENT_VERSION,
      "مسیر: " + window.location.pathname,
      "وضعیت اینترنت: " + (navigator.onLine ? "آنلاین" : "آفلاین"),
      "زمان: " + new Date().toISOString(),
      "تلگرام: " + JSON.stringify(tgInfo),
      "مرورگر: " + navigator.userAgent,
      "خطاهای اخیر: " + JSON.stringify(errors.slice(-5))
    ].join("\n");
  }

  function saveError(message, source) {
    try {
      var errors = JSON.parse(localStorage.getItem("mirza:errors") || "[]");
      errors.push({
        message: String(message || "Unknown error").slice(0, 400),
        source: String(source || window.location.pathname).slice(0, 200),
        time: new Date().toISOString()
      });
      localStorage.setItem("mirza:errors", JSON.stringify(errors.slice(-20)));
    } catch (_) {}
  }

  function setupErrorLog() {
    window.addEventListener("error", function (event) {
      saveError(event.message, event.filename || "window.error");
    });
    window.addEventListener("unhandledrejection", function (event) {
      var reason = event.reason && event.reason.message ? event.reason.message : event.reason;
      saveError(reason, "unhandledrejection");
    });
  }

  function closeSheet() {
    var overlay = document.querySelector(".mirza-sheet-overlay");
    if (overlay) overlay.remove();
    document.body.style.overflow = "";
  }

  function openSheet(title, bodyBuilder) {
    closeSheet();
    var overlay = document.createElement("div");
    overlay.className = "mirza-sheet-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    var sheet = document.createElement("section");
    sheet.className = "mirza-sheet";
    sheet.innerHTML = '<div class="mirza-sheet-handle"></div><div class="mirza-sheet-header"><h2 class="mirza-sheet-title"></h2><button class="mirza-sheet-close" type="button" aria-label="بستن">×</button></div>';
    sheet.querySelector(".mirza-sheet-title").textContent = title;
    sheet.querySelector(".mirza-sheet-close").addEventListener("click", closeSheet);
    bodyBuilder(sheet);
    overlay.appendChild(sheet);
    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) closeSheet();
    });
    document.body.appendChild(overlay);
    document.body.style.overflow = "hidden";
    sheet.querySelector("button, [tabindex]") && sheet.querySelector("button, [tabindex]").focus();
  }

  function openHelp() {
    openSheet("راهنمای رفع مشکل اتصال", function (sheet) {
      var content = document.createElement("div");
      content.innerHTML = '<ol class="mirza-help-list"><li>تاریخ و ساعت دستگاه را روی حالت خودکار قرار دهید.</li><li>کانفیگ را دوباره از بخش اطلاعات اتصال کپی یا وارد کنید.</li><li>برنامه اتصال را به آخرین نسخه به‌روزرسانی کنید.</li><li>یک‌بار بین اینترنت همراه و وای‌فای جابه‌جا شوید.</li><li>اگر حجم یا زمان سرویس تمام شده، وضعیت سرویس را در همین مینی‌اپ بررسی کنید.</li><li>در صورت ادامه مشکل، گزارش عیب‌یابی را برای پشتیبانی بفرستید.</li></ol><div class="mirza-help-note">این راهنما فقط تنظیمات امن و عمومی را بررسی می‌کند و هیچ اطلاعات حساب یا کانفیگی را برای جایی ارسال نمی‌کند.</div>';
      sheet.appendChild(content);
    });
  }

  function clearAppCache() {
    var jobs = [];
    if ("caches" in window) {
      jobs.push(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (key) { return key.indexOf(CACHE_PREFIX) === 0; }).map(function (key) { return caches.delete(key); }));
      }));
    }
    jobs.push(Promise.resolve().then(function () {
      localStorage.removeItem("mirza:errors");
    }));
    return Promise.all(jobs);
  }

  function openTools() {
    openSheet("ابزارهای مینی‌اپ", function (sheet) {
      var grid = document.createElement("div");
      grid.className = "mirza-action-grid";
      var actions = [
        ["بروزرسانی صفحه", "دریافت تازه‌ترین اطلاعات", function () { window.location.reload(); }],
        ["راهنمای اتصال", "مراحل معمول رفع قطعی", function () { closeSheet(); openHelp(); }],
        ["کپی گزارش عیب‌یابی", "برای ارسال به پشتیبانی", function () {
          copyText(getDiagnostics()).then(function () { toast("گزارش عیب‌یابی کپی شد"); haptic("success"); }).catch(function () { toast("کپی گزارش انجام نشد"); haptic("error"); });
        }],
        ["پاک‌کردن حافظه موقت", "بدون حذف حساب کاربری", function () {
          clearAppCache().then(function () { toast("حافظه موقت پاک شد"); haptic("success"); window.setTimeout(function () { window.location.reload(); }, 700); });
        }]
      ];
      actions.forEach(function (action) {
        var button = document.createElement("button");
        button.className = "mirza-action-card";
        button.type = "button";
        button.innerHTML = "<strong></strong><span></span>";
        button.querySelector("strong").textContent = action[0];
        button.querySelector("span").textContent = action[1];
        button.addEventListener("click", action[2]);
        grid.appendChild(button);
      });
      sheet.appendChild(grid);
    });
  }

  function setupFloatingTools() {
    if (!document.querySelector(".mirza-tools-button")) {
      var tools = document.createElement("button");
      tools.className = "mirza-tools-button";
      tools.type = "button";
      tools.setAttribute("aria-label", "ابزارها و راهنما");
      tools.innerHTML = icon("tools");
      tools.addEventListener("click", openTools);
      document.body.appendChild(tools);
    }
    if (!document.querySelector(".mirza-scroll-top")) {
      var up = document.createElement("button");
      up.className = "mirza-scroll-top";
      up.type = "button";
      up.setAttribute("aria-label", "بازگشت به بالای صفحه");
      up.innerHTML = icon("up");
      up.addEventListener("click", function () { window.scrollTo({ top: 0, behavior: "smooth" }); });
      document.body.appendChild(up);
      var update = function () { up.dataset.visible = window.scrollY > 500 ? "true" : "false"; };
      window.addEventListener("scroll", update, { passive: true });
      update();
    }
  }

  function identifyServiceState(card) {
    if (card.querySelector(".bg-green-500")) return "active";
    if (card.querySelector(".bg-yellow-500")) return "expired";
    if (card.querySelector(".bg-purple-500")) return "on_hold";
    if (card.querySelector(".bg-red-500")) return "limited";
    if (card.querySelector(".bg-gray-500")) return "disabled";
    return "unknown";
  }

  function getServiceCards() {
    var buttons = Array.from(document.querySelectorAll("button"));
    var cards = [];
    buttons.forEach(function (button) {
      if ((button.textContent || "").trim() !== "جزئیات") return;
      var card = button.closest(".overflow-hidden") || button.parentElement;
      if (!card) return;
      var wrapper = card.parentElement;
      if (wrapper && cards.indexOf(wrapper) === -1) cards.push(wrapper);
    });
    return cards;
  }

  function applyServiceFilter() {
    var cards = getServiceCards();
    var visible = 0;
    var counts = { all: cards.length, active: 0, expired: 0, on_hold: 0, limited: 0, disabled: 0, unknown: 0 };
    cards.forEach(function (wrapper) {
      var state = identifyServiceState(wrapper);
      counts[state] = (counts[state] || 0) + 1;
      var shouldShow = currentServiceFilter === "all" || currentServiceFilter === state;
      wrapper.classList.toggle("mirza-enhance-hidden", !shouldShow);
      if (shouldShow) visible += 1;
    });
    var panel = document.querySelector(".mirza-service-filter");
    if (panel) {
      panel.querySelectorAll(".mirza-filter-chip").forEach(function (chip) {
        var state = chip.dataset.filter;
        chip.dataset.active = state === currentServiceFilter ? "true" : "false";
        var count = counts[state] || 0;
        var label = chip.dataset.label;
        chip.textContent = label + " (" + count + ")";
      });
      var meta = panel.querySelector(".mirza-filter-meta");
      if (meta) meta.textContent = "نمایش " + visible + " مورد از " + cards.length + " سرویس بارگذاری‌شده";
    }
  }

  function enhanceServicesList() {
    if (!/\/services\/?$/.test(window.location.pathname)) return;
    var search = document.querySelector('input[placeholder*="جستجوی سرویس"]');
    if (!search) return;
    if (!document.querySelector(".mirza-service-filter")) {
      var panel = document.createElement("section");
      panel.className = "mirza-service-filter";
      panel.setAttribute("aria-label", "فیلتر سرویس‌ها");
      var row = document.createElement("div");
      row.className = "mirza-filter-row";
      [
        ["all", "همه"],
        ["active", "فعال"],
        ["expired", "منقضی"],
        ["on_hold", "در انتظار"],
        ["limited", "محدود"],
        ["disabled", "غیرفعال"]
      ].forEach(function (item) {
        var chip = document.createElement("button");
        chip.type = "button";
        chip.className = "mirza-filter-chip";
        chip.dataset.filter = item[0];
        chip.dataset.label = item[1];
        chip.addEventListener("click", function () {
          currentServiceFilter = item[0];
          applyServiceFilter();
        });
        row.appendChild(chip);
      });
      var meta = document.createElement("div");
      meta.className = "mirza-filter-meta";
      panel.appendChild(row);
      panel.appendChild(meta);
      var searchBox = search.parentElement;
      searchBox.insertAdjacentElement("afterend", panel);
    }
    applyServiceFilter();
  }

  function markPrivateContents(revealed) {
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT);
    var nodes = [];
    while (walker.nextNode()) {
      var el = walker.currentNode;
      if (el.closest(".mirza-service-tools, .mirza-sheet-overlay")) continue;
      var ownText = Array.from(el.childNodes).filter(function (node) { return node.nodeType === Node.TEXT_NODE; }).map(function (node) { return node.textContent; }).join(" ");
      if (CONFIG_PATTERN.test(ownText)) nodes.push(el);
      CONFIG_PATTERN.lastIndex = 0;
    }
    nodes.forEach(function (el) {
      el.classList.add("mirza-private-content");
      el.dataset.revealed = revealed ? "true" : "false";
    });
  }

  function enhanceServiceDetail() {
    if (!/\/services\/[^/]+\/?$/.test(window.location.pathname)) return;
    var configs = collectConfigs();
    if (!configs.length) return;
    var heading = Array.from(document.querySelectorAll("h1,h2,h3,h4,div")).find(function (el) {
      return el.children.length < 3 && (el.textContent || "").trim() === "اطلاعات اتصال";
    });
    if (!heading) return;
    var existing = document.querySelector(".mirza-service-tools");
    if (!existing) {
      var panel = document.createElement("section");
      panel.className = "mirza-service-tools";
      panel.innerHTML = '<div class="mirza-service-tools-row"></div><div class="mirza-tool-meta"></div>';
      var row = panel.querySelector(".mirza-service-tools-row");
      var revealed = true;
      var actions = [
        ["کپی همه", function () {
          var values = collectConfigs();
          copyText(values.join("\n")).then(function () { toast(values.length + " کانفیگ کپی شد"); haptic("success"); }).catch(function () { toast("کپی انجام نشد"); haptic("error"); });
        }],
        ["دانلود فایل", function () {
          var values = collectConfigs();
          var username = decodeURIComponent(window.location.pathname.split("/").filter(Boolean).pop() || "service");
          downloadText(username + "-configs.txt", values.join("\n"));
          toast("فایل کانفیگ‌ها آماده شد");
        }],
        ["اشتراک‌گذاری", function () {
          var values = collectConfigs();
          var text = values.join("\n");
          if (navigator.share) {
            navigator.share({ title: "اطلاعات اتصال", text: text }).catch(function () {});
          } else {
            copyText(text).then(function () { toast("اشتراک‌گذاری پشتیبانی نشد؛ متن کپی شد"); });
          }
        }],
        ["مخفی‌کردن", function (button) {
          revealed = !revealed;
          markPrivateContents(revealed);
          button.textContent = revealed ? "مخفی‌کردن" : "نمایش اطلاعات";
        }]
      ];
      actions.forEach(function (action) {
        var button = document.createElement("button");
        button.type = "button";
        button.textContent = action[0];
        button.addEventListener("click", function () { action[1](button); });
        row.appendChild(button);
      });
      var card = heading.closest("[class*='rounded']") || heading.parentElement;
      if (card && card.parentElement) card.parentElement.insertBefore(panel, card.nextSibling);
      else heading.insertAdjacentElement("afterend", panel);
      existing = panel;
    }
    var meta = existing.querySelector(".mirza-tool-meta");
    if (meta) meta.textContent = configs.length + " کانفیگ شناسایی شد؛ اطلاعات فقط روی دستگاه شما پردازش می‌شود.";
  }

  function showUpdateBanner(version) {
    if (document.querySelector(".mirza-update-banner")) return;
    var banner = document.createElement("div");
    banner.className = "mirza-update-banner";
    banner.setAttribute("role", "status");
    var text = document.createElement("span");
    text.textContent = "نسخه جدید " + version + " آماده است";
    var button = document.createElement("button");
    button.type = "button";
    button.textContent = "به‌روزرسانی";
    button.addEventListener("click", function () {
      localStorage.setItem("mirza:current-version", version);
      clearAppCache().finally(function () { window.location.reload(); });
    });
    banner.appendChild(text);
    banner.appendChild(button);
    document.body.appendChild(banner);
  }

  function checkVersion() {
    fetch(APP_VERSION_URL + "?t=" + Date.now(), { cache: "no-store" })
      .then(function (response) { return response.ok ? response.text() : ""; })
      .then(function (raw) {
        var version = raw.trim();
        if (!version) return;
        var saved = localStorage.getItem("mirza:current-version");
        if (saved && saved !== version) showUpdateBanner(version);
        else if (!saved) localStorage.setItem("mirza:current-version", version);
      })
      .catch(function () {});
  }

  function registerServiceWorker() {
    if (!("serviceWorker" in navigator) || !window.isSecureContext) return;
    window.addEventListener("load", function () {
      navigator.serviceWorker.register(new URL("./sw.js", document.baseURI).toString(), { scope: new URL("./", document.baseURI).pathname }).catch(function (error) {
        saveError(error && error.message, "service-worker");
      });
    });
  }

  function scheduleEnhance() {
    if (mutationTimer) return;
    mutationTimer = window.setTimeout(function () {
      mutationTimer = null;
      enhanceServicesList();
      enhanceServiceDetail();
    }, 120);
  }

  function setupMutationObserver() {
    var observer = new MutationObserver(scheduleEnhance);
    observer.observe(document.getElementById("root") || document.body, { childList: true, subtree: true });
  }

  function start() {
    setupTelegram();
    patchHistory();
    updateTelegramBackButton();
    setupHaptics();
    setupNetworkMonitor();
    setupErrorLog();
    setupFloatingTools();
    setupMutationObserver();
    checkVersion();
    registerServiceWorker();
    scheduleEnhance();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
