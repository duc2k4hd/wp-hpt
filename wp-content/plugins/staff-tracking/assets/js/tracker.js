/**
 * Staff Tracking — frontend tracker
 * No framework. No blocking. Single request on valid session (≥30 s active).
 *
 * Relies on STConfig injected via wp_localize_script:
 *   { endpoint, nonce, threshold, page_path }
 */
(function () {
  'use strict';

  /* ── Guard: only in browser, not wp-admin ───────────────────────────────── */
  if (typeof STConfig === 'undefined') return;

  /* ── Constants ──────────────────────────────────────────────────────────── */
  // Hỗ trợ thêm STConfig.show_messages để bật/tắt các thông báo
  const THRESHOLD = STConfig.threshold || 30;    // seconds
  const SCROLL_THRESHOLD = STConfig.scroll_threshold || 60; // % scroll
  const LS_USER = 'st_user';                    // localStorage key
  const TICK_MS = 1000;                          // 1 s tick

  // Fallback UUID (IE / old Safari / HTTP contexts)
  function uid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  // Cookie helpers for Safari Private Mode
  function setCookie(name, value, days) {
    let expires = "";
    if (days) {
      let date = new Date();
      date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
      expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax";
  }

  function getCookie(name) {
    let nameEQ = name + "=";
    let ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
      let c = ca[i];
      while (c.charAt(0) === ' ') c = c.substring(1, c.length);
      if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
  }

  /* ── State ──────────────────────────────────────────────────────────────── */
  let activeSeconds = 0;
  let sent = false;
  let tabActive = !document.hidden;
  const sessionId = (crypto.randomUUID ? crypto.randomUUID() : uid());
  let startTime = new Date().toISOString();

  let behaviorQueue = [];
  let scrollMilestones = { p25: false, p50: false, p75: false, p100: false };

  function pushBehavior(type, data) {
    if (behaviorQueue.length >= 150) return;
    const offset = Math.round((new Date() - new Date(startTime)) / 1000);
    const now = new Date();
    const tzoffset = now.getTimezoneOffset() * 60000;
    const localISOTime = (new Date(now.getTime() - tzoffset)).toISOString().slice(0, 19).replace('T', ' ');
    behaviorQueue.push({
      type: type,
      data: data,
      time: localISOTime,
      offset: offset
    });
  }

  let loggedNoRef = false;
  let loggedNotScrolled = false;
  let maxScroll = 0;

  let isMissionActive = false;
  let scrollListenerAdded = false;
  let ticker = null;
  let scrollTimeout = null;

  function isGoogleReferrer(ref) {
    if (!ref) return false;
    try {
      const url = new URL(ref);
      return url.hostname.includes('google.');
    } catch (e) {
      return ref.includes('google.');
    }
  }

  /* ── Local user identity ─────────────────────────────────────────────────── */
  function loadUser() {
    let u = null;
    try {
      u = localStorage.getItem(LS_USER);
    } catch { }
    if (!u) {
      try {
        u = sessionStorage.getItem(LS_USER);
      } catch { }
    }
    if (!u) {
      u = getCookie(LS_USER);
    }
    try {
      return JSON.parse(u ? decodeURIComponent(u) : 'null') || {};
    } catch {
      return {};
    }
  }

  function saveUser(data) {
    const val = encodeURIComponent(JSON.stringify(data));
    try {
      localStorage.setItem(LS_USER, JSON.stringify(data));
    } catch { }
    try {
      sessionStorage.setItem(LS_USER, JSON.stringify(data));
    } catch { }
    try {
      setCookie(LS_USER, val, 365);
    } catch { }
  }

  /* ── Scroll Prompt UI ────────────────────────────────────────────────────── */
  let scrollPromptEl = null;

  function initScrollPrompt() {
    if (document.getElementById('st-scroll-prompt')) return;
    
    const style = document.createElement('style');
    style.textContent = `
      #st-scroll-prompt {
        position: fixed;
        top: 50%;
        right: 20px;
        transform: translateY(-50%) translateX(50px);
        opacity: 0;
        visibility: hidden;
        z-index: 999999;
        transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        pointer-events: none;
      }
      #st-scroll-prompt.st-show {
        opacity: 1;
        visibility: visible;
        transform: translateY(-50%) translateX(0);
      }
      .st-scroll-prompt-inner {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-left: 4px solid #10b981;
        padding: 16px 20px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 16px;
        animation: st-bounce-y 2.5s infinite ease-in-out;
      }
      @keyframes st-bounce-y {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(8px); }
      }
      .st-mouse {
        width: 22px;
        height: 34px;
        border: 2px solid #10b981;
        border-radius: 12px;
        position: relative;
        display: flex;
        justify-content: center;
      }
      .st-mouse::before {
        content: '';
        width: 4px;
        height: 6px;
        background: #10b981;
        border-radius: 2px;
        position: absolute;
        top: 6px;
        animation: st-scroll-wheel 1.5s infinite;
      }
      @keyframes st-scroll-wheel {
        0% { transform: translateY(0); opacity: 1; }
        100% { transform: translateY(10px); opacity: 0; }
      }
      .st-scroll-text {
        color: #1f2937;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.5;
        text-align: left;
        text-shadow: 0 1px 2px rgba(0,0,0,0.05);
      }
      .st-scroll-highlight {
        color: #10b981;
        font-weight: 800;
      }
    `;
    document.head.appendChild(style);

    scrollPromptEl = document.createElement('div');
    scrollPromptEl.id = 'st-scroll-prompt';
    const scrollPromptContent = (STConfig.messages && STConfig.messages.scroll_down_prompt) ? STConfig.messages.scroll_down_prompt : 'Hãy cuộn xuống<br/><span class="st-scroll-highlight">để hoàn thành</span>';
    scrollPromptEl.innerHTML = `
      <div class="st-scroll-prompt-inner">
        <div class="st-mouse"></div>
        <div class="st-scroll-text">${scrollPromptContent}</div>
      </div>
    `;
    if (STConfig.show_messages && STConfig.show_messages.scroll_down_prompt === false) {
      scrollPromptEl.style.display = 'none';
    }
    document.body.appendChild(scrollPromptEl);
  }

  function toggleScrollPrompt(scrollY, docH, winH) {
    if (!isMissionActive || !isGoogleReferrer(document.referrer) || sent) {
      if (scrollPromptEl) scrollPromptEl.classList.remove('st-show');
      return;
    }
    
    if (!scrollPromptEl) initScrollPrompt();

    const percentScrollTop = docH > 0 ? (scrollY / docH) * 100 : 0;
    
    // Hiện thông báo nếu đang ở khoảng 10% đầu trang HOẶC chưa cuộn quá nửa màn hình
    // và điều kiện là chưa đạt mốc scroll yêu cầu
    if ((percentScrollTop <= 10 || scrollY < winH * 0.5) && maxScroll < SCROLL_THRESHOLD) {
      scrollPromptEl.classList.add('st-show');
    } else {
      scrollPromptEl.classList.remove('st-show');
    }
  }

  /* ── Track Scroll Depth ──────────────────────────────────────────────────── */
  function updateScroll() {
    const scrollY = window.scrollY || window.pageYOffset || 0;
    const winH = window.innerHeight || 0;
    const docH = Math.max(
      document.body.scrollHeight || 0, document.documentElement.scrollHeight || 0,
      document.body.offsetHeight || 0, document.documentElement.offsetHeight || 0,
      document.body.clientHeight || 0, document.documentElement.clientHeight || 0
    );

    let percent = 100;
    if (docH > winH) {
      percent = Math.floor((scrollY + winH) / docH * 100);
    }
    if (percent > 100) percent = 100;

    if (percent > maxScroll) {
      maxScroll = percent;

      
      // Scroll milestones behavior log
      if (maxScroll >= 25 && !scrollMilestones.p25) {
        scrollMilestones.p25 = true;
        pushBehavior('scroll', { depth: 25 });
      }
      if (maxScroll >= 50 && !scrollMilestones.p50) {
        scrollMilestones.p50 = true;
        pushBehavior('scroll', { depth: 50 });
      }
      if (maxScroll >= 75 && !scrollMilestones.p75) {
        scrollMilestones.p75 = true;
        pushBehavior('scroll', { depth: 75 });
      }
      if (maxScroll >= 100 && !scrollMilestones.p100) {
        scrollMilestones.p100 = true;
        pushBehavior('scroll', { depth: 100 });
      }
    }

    // Toggle scroll prompt visibility
    toggleScrollPrompt(scrollY, docH, winH);
  }

  function onScrollEvent() {
    resetActivity();
    lastScrollTime = Date.now();
    if (!scrollTimeout) {
      scrollTimeout = setTimeout(function () {
        updateScroll();
        scrollTimeout = null;
      }, 150);
    }
  }

  /* ── Confirm Button Logic ────────────────────────────────────────────────── */
  let confirmBtnShown = false;
  function showConfirmButton() {
    if (confirmBtnShown) return;
    confirmBtnShown = true;
    
    const btn = document.createElement('button');
    btn.innerHTML = 'Bấm vào đây để xác nhận';
    
    // Random class name to avoid easy bot selection
    const randClass = 'st-' + Math.random().toString(36).substring(2, 10);
    btn.className = randClass;
    
    // Inline styles to make it prominent but random positioned
    Object.assign(btn.style, {
      position: 'fixed',
      zIndex: '2147483647',
      padding: '15px 30px',
      background: '#d92626',
      color: '#fff',
      fontSize: '18px',
      fontWeight: 'bold',
      border: '2px solid #fff',
      borderRadius: '8px',
      cursor: 'pointer',
      boxShadow: '0 4px 15px rgba(0,0,0,0.4)',
      transition: 'transform 0.2s',
      fontFamily: 'sans-serif'
    });
    
    // Random position: between 10% and 80% to ensure it stays on screen on all devices
    const topPercent = Math.floor(Math.random() * 70) + 10;
    const leftPercent = Math.floor(Math.random() * 60) + 10; // Left max 70% to not cut off text
    
    btn.style.top = topPercent + '%';
    btn.style.left = leftPercent + '%';
    
    // Add pulse animation dynamically
    const style = document.createElement('style');
    style.innerHTML = `
      @keyframes ${randClass}-pulse {
        0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(217, 38, 38, 0.7); }
        70% { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(217, 38, 38, 0); }
        100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(217, 38, 38, 0); }
      }
      .${randClass} {
        animation: ${randClass}-pulse 1.5s infinite;
      }
      .${randClass}:active {
        transform: scale(0.95) !important;
      }
    `;
    document.head.appendChild(style);
    
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      btn.remove();
      style.remove();
      sendSession(false);
    });
    
    document.body.appendChild(btn);
  }

  /* ── Tracking Lifecycle ──────────────────────────────────────────────────── */
  let lastActivityTime = Date.now();
  let lastScrollTime = Date.now();
  let isIdle = false;
  let isIdlePaused = false;
  const IDLE_TIMEOUT = 15000; // 15 seconds

  function resetActivity() {
    lastActivityTime = Date.now();
    isIdle = false;
  }

  function showIdlePrompt() {
    const toast = document.getElementById('st-toast');
    const msg = document.getElementById('st-toast-msg');
    const icon = document.getElementById('st-toast-icon');
    if (!toast || !msg || !icon) return;

    countdownToastVisible = false;

    // Hiển thị thông báo yêu cầu cuộn lên bấm nút thật
    msg.innerHTML = `Vui lòng thêm sản phẩm vào giỏ hàng!`;
    icon.textContent = '⏸️';
    toast.style.backgroundColor = '#f59e0b'; // Màu cam cảnh báo

    if (toastTimeout) {
      clearTimeout(toastTimeout);
    }
    
    toast.classList.add('st-toast-show');
  }

  // Bắt sự kiện click vào nút Thêm vào giỏ hàng thật trên website
  document.addEventListener('click', function(e) {
    const realAddToCartBtn = e.target.closest('.single_add_to_cart_button') || e.target.closest('button[name="add-to-cart"]');
    if (realAddToCartBtn) {
      // Nếu đang bị tạm dừng thì cho chạy tiếp
      if (isIdlePaused) {
        isIdlePaused = false;
        lastScrollTime = Date.now(); // reset thời gian cuộn
        resetActivity();
        const toast = document.getElementById('st-toast');
        if (toast) toast.classList.remove('st-toast-show');
      }

      // Ghi traffic ngay lúc user click nếu đã đủ điều kiện
      if (!sent && activeSeconds >= THRESHOLD && maxScroll >= SCROLL_THRESHOLD) {
         sendSession(false);
      }
    }
  });

  let loggedSuccessMsg = false;

  function startTrackingLifecycle() {
    // Ngăn chặn đăng ký lặp lại nhiều lần
    if (scrollListenerAdded) return;
    scrollListenerAdded = true;

    window.addEventListener('scroll', onScrollEvent, { passive: true });
    
    // Add idle detection listeners
    ['mousemove', 'keydown', 'click', 'touchstart'].forEach(function (evt) {
      window.addEventListener(evt, resetActivity, { passive: true });
    });
    
    updateScroll();

    if (ticker) {
      clearInterval(ticker);
    }

    ticker = setInterval(function () {
      if (Date.now() - lastActivityTime > IDLE_TIMEOUT) {
        isIdle = true;
      }

      // Kiểm tra dừng cuộn 10s
      if (!isIdlePaused && Date.now() - lastScrollTime > 10000) {
        if (isMissionActive && isGoogleReferrer(document.referrer) && !sent) {
           isIdlePaused = true;
           showIdlePrompt();
        }
      }

      if (tabActive && !sent) {
        const user = loadUser();

        // Nếu chưa điền đủ thông tin (khách vãng lai), im lặng hoàn toàn, không đếm giây
        if (!user.name || !user.emp_code || !user.class_code || !user.team) {
          activeSeconds = 0;
          startTime = new Date().toISOString();
          return;
        }

        // Đã điền thông tin nhân viên: kiểm tra Referrer Google
        // Chỉ cần check khi đã xác nhận có nhiệm vụ active
        if (!isMissionActive) {
          activeSeconds = 0;
          return;
        }
        const ref = document.referrer;
        if (!isGoogleReferrer(ref)) {
          if (!loggedNoRef) {
            showToast(STConfig.messages.require_google || 'Bạn phải truy cập bài viết thông qua tìm kiếm Google!', true, 'require_google');
            loggedNoRef = true;
          }
          activeSeconds = 0;
          return;
        }

        activeSeconds++;
        if (activeSeconds % 10 === 0) {
          pushBehavior('heartbeat', { active: activeSeconds });
        }

        // Đoạn Toast đếm ngược: Đã cuộn đủ nhưng chưa onsite đủ thời gian
        if (isIdlePaused) {
          showIdlePrompt();
        } else if (maxScroll >= SCROLL_THRESHOLD && activeSeconds < THRESHOLD) {
          const remaining = THRESHOLD - activeSeconds;
          showCountdownToast(remaining);
        }

        if (activeSeconds >= THRESHOLD) {
          // Đã đủ thời gian, kiểm tra cuộn trang
          if (maxScroll < SCROLL_THRESHOLD) {
            if (!loggedNotScrolled) {
              hideCountdownToast();
              const msg = STConfig.messages.scroll_not_enough ? STConfig.messages.scroll_not_enough.replace('{scroll}', SCROLL_THRESHOLD) : 'Bạn chưa cuộn đủ ' + SCROLL_THRESHOLD + '% chiều dài bài viết!';
              showToast(msg, true, 'scroll_not_enough');
              loggedNotScrolled = true;
            }
            return;
          }

          // Đạt đủ cả 2 điều kiện
          hideCountdownToast();
          
          if (isIdlePaused) {
             // Đã đủ điều kiện thời gian và cuộn, nhưng bị bắt buộc click giỏ hàng
             // -> Khoan chưa submit, đợi họ click thật
             return;
          }

          if (typeof STConfig !== 'undefined' && parseInt(STConfig.require_confirm, 10) === 1) {
            showConfirmButton();
          } else {
            // Tự động ghi nhận ngay khi đủ điều kiện
            sendSession(false);
          }
        }
      }
    }, TICK_MS);
  }

  function checkMissionAndStart() {
    const user = loadUser();
    // Nếu chưa nhập danh tính → không check (khách vãng lai, không cần gọi API)
    if (!user.name || !user.emp_code || !user.class_code || !user.team) {
      return;
    }

    // Không check referrer tại đây mà luôn gọi API kiểm tra nhiệm vụ trước.
    // Nếu không có nhiệm vụ nào active → im lặng tối đa (không gọi tracker, không hiện toast).
    // Nếu có nhiệm vụ mà referrer sai → tracker sẽ tự xử lý trong setInterval.
    // Cache key bao gồm version do admin cập nhật — tự động invalidate khi có nhiệm vụ mới
    const assignVer = (typeof STConfig !== 'undefined' && STConfig.assign_ver) ? STConfig.assign_ver : '0';
    const cacheKey = 'st_mission_v4_' + assignVer + '_' + btoa(encodeURIComponent(
      (window.location.pathname || '/') + '|' + user.emp_code + '|' + user.team
    )).slice(0, 40);

    // Chỉ cache kết quả 'active' (trang khớp) — 'none' và 'mismatch' không cache
    // để đảm bảo khi admin tạo nhiệm vụ mới người dùng thấy người ngay lập tức
    const cached = sessionStorage.getItem(cacheKey);
    if (cached === 'active') {
      isMissionActive = true;
      startTrackingLifecycle();
      return;
    }
    // Không có cache valid — gọi API kiểm tra lại
    // Trì hoãn 1.5s để không tranh tài nguyên với các request quan trọng của trang
    setTimeout(function () {
      const payload = {
        url: window.location.href,
        user_name: user.name,
        emp_code: user.emp_code,
        class_code: user.class_code,
        team: user.team,
        post_type: (typeof STConfig !== 'undefined') ? STConfig.post_type : '',
        post_id: (typeof STConfig !== 'undefined') ? STConfig.post_id : '',
        referrer: document.referrer
      };

      fetch(STConfig.endpoint.replace('/log', '/check-mission'), {
        method: 'POST',
        credentials: 'omit',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data && data.success) {
            // Có nhiệm vụ khớp — cache lại và bắt đầu tracking
            isMissionActive = true;
            sessionStorage.setItem(cacheKey, 'active');
            const btnWrapper = document.getElementById('st-id-btn-wrapper');
            if (btnWrapper) btnWrapper.style.display = '';
            startTrackingLifecycle();
          } else if (data && data.reason === 'invalid_staff_credentials') {
            isMissionActive = false;
            if (scrollListenerAdded) {
              window.removeEventListener('scroll', onScrollEvent);
              scrollListenerAdded = false;
            }
            if (ticker) { clearInterval(ticker); ticker = null; }
            showToast(STConfig.messages.invalid_staff || 'Thông tin nhân sự không tồn tại hoặc không khớp!', true, 'invalid_staff');
            saveUser({ name: '', emp_code: '', class_code: '', team: '' });
          } else if (data && (data.reason === 'no_assignments' || data.reason === 'general_mismatch')) {
            // Không có nhiệm vụ nào, hoặc chỉ có nhiệm vụ chung mà sai trang → im lặng, không cache
            isMissionActive = false;
          } else if (data && data.reason === 'already_done_today') {
            isMissionActive = false;
            if (scrollListenerAdded) {
              window.removeEventListener('scroll', onScrollEvent);
              scrollListenerAdded = false;
            }
            if (ticker) { clearInterval(ticker); ticker = null; }
            showToast(STConfig.messages.already_completed || 'Bạn đã hoàn thành nhiệm vụ ở trang này hôm nay rồi!', true, 'already_completed');
            // Vẫn hiện nút chức năng để họ có thể xem tiền/lịch sử
            const btnWrapper = document.getElementById('st-id-btn-wrapper');
            if (btnWrapper) btnWrapper.style.display = '';
          } else {
            // specific_mismatch: Có nhiệm vụ riêng nhưng sai trang → hiện toast
            isMissionActive = false;
            if (scrollListenerAdded) {
              window.removeEventListener('scroll', onScrollEvent);
              scrollListenerAdded = false;
            }
            if (ticker) { clearInterval(ticker); ticker = null; }
            showToast(STConfig.messages.not_in_mission || 'Sản phẩm này không nằm trong nhiệm vụ phân công của bạn!', true, 'not_in_mission');
            const btnWrapper = document.getElementById('st-id-btn-wrapper');
            if (btnWrapper) btnWrapper.style.display = 'none';
          }
        })
        .catch(function () {
          // Nếu lỗi mạng: vẫn cho phép tracking bình thường (không phạt người dùng)
          startTrackingLifecycle();
        });
    }, 1500);
  }

  /* ── Tab visibility & Behaviors ─────────────────────────────────────────── */
  document.addEventListener('visibilitychange', function () {
    tabActive = !document.hidden;
    pushBehavior('visibility', { state: document.visibilityState });
  });

  document.addEventListener('click', function (e) {
    const originalTarget = e.target;
    if (!originalTarget) return;

    let target = originalTarget;
    const interactiveTags = ['a', 'button', 'input', 'label', 'select', 'textarea', 'summary', 'li'];
    let current = originalTarget;
    let depth = 0;
    while (current && depth < 5) {
      const tagName = (current.tagName || '').toLowerCase();
      if (interactiveTags.indexOf(tagName) !== -1 || current.getAttribute('onclick') || current.id) {
        target = current;
        break;
      }
      current = current.parentNode;
      depth++;
    }

    const tag = target.tagName || '';
    let text = '';
    const lowerTag = tag.toLowerCase();
    if (lowerTag === 'input' || lowerTag === 'textarea' || lowerTag === 'select') {
      text = (target.value || '').trim();
    } else {
      text = (target.textContent || '').trim();
    }
    
    text = text.replace(/\s+/g, ' ').slice(0, 100);

    const id = target.id ? '#' + target.id : '';
    const classes = target.className && typeof target.className === 'string' 
      ? '.' + target.className.split(/\s+/).filter(Boolean).join('.') 
      : '';
    const href = target.getAttribute('href') || '';
    const title = target.getAttribute('title') || originalTarget.getAttribute('title') || '';
    
    const alt = originalTarget.getAttribute('alt') || target.getAttribute('alt') || '';
    const src = originalTarget.getAttribute('src') || target.getAttribute('src') || '';

    pushBehavior('click', {
      tag: tag,
      text: text,
      selector: tag.toLowerCase() + id + classes,
      href: href,
      title: title,
      alt: alt,
      src: src
    });
  });

  document.addEventListener('copy', function (e) {
    let selectedText = '';
    if (window.getSelection) {
      selectedText = window.getSelection().toString();
    } else if (document.selection && document.selection.type !== 'Control') {
      selectedText = document.selection.createRange().text;
    }
    
    let target = e.target;
    let selector = '';
    if (target) {
      const id = target.id ? '#' + target.id : '';
      const classes = target.className && typeof target.className === 'string' ? '.' + target.className.split(/\s+/).filter(Boolean).join('.') : '';
      selector = (target.tagName || '').toLowerCase() + id + classes;
    }

    pushBehavior('copy', { 
      text: selectedText.slice(0, 200),
      selector: selector
    });
  });

  document.addEventListener('paste', function (e) {
    let target = e.target;
    let selector = '';
    if (target) {
      const id = target.id ? '#' + target.id : '';
      const classes = target.className && typeof target.className === 'string' ? '.' + target.className.split(/\s+/).filter(Boolean).join('.') : '';
      selector = (target.tagName || '').toLowerCase() + id + classes;
    }
    let clipLength = 0;
    try {
      clipLength = (e.clipboardData || window.clipboardData).getData('text').length;
    } catch(err) {}

    pushBehavior('paste', {
      selector: selector,
      text_length: clipLength
    });
  });

  /* ── Send on page unload (if not already sent) ──────────────────────────── */
  window.addEventListener('pagehide', onLeave);
  window.addEventListener('beforeunload', onLeave);

  function onLeave() {
    if (sent) return;
    if (activeSeconds >= THRESHOLD) {
      const ref = document.referrer;
      if (!isGoogleReferrer(ref)) {
        return;
      }

      if (maxScroll < SCROLL_THRESHOLD) {
        return;
      }

      const user = loadUser();
      if (!user.name || !user.emp_code || !user.class_code || !user.team) {
        return;
      }

      sendSession(true);
    }
  }

  /* ── Core send ──────────────────────────────────────────────────────────── */
  function sendSession(useBeacon) {
    if (sent) return;
    sent = true;
    if (ticker) {
      clearInterval(ticker);
      ticker = null;
    }

    const user    = loadUser();
    const endTime = new Date().toISOString();

    // Xóa cache nhiệm vụ trên trình duyệt để tránh bị nhận nhầm là chưa làm nếu người dùng tải lại trang
    Object.keys(sessionStorage).forEach(function(key) {
      if (key.indexOf('st_mission_v4_') === 0) {
        sessionStorage.removeItem(key);
      }
    });

    // ── Lớp 6: Thu thập bot fingerprint từ trình duyệt ──────────────────────
    const webdriverFlag   = !!navigator.webdriver;
    const pluginCount     = navigator.plugins ? navigator.plugins.length : -1;
    const hasChromeObject = !!(window.chrome && window.chrome.runtime);

    // ── Lớp 1: Lấy challenge token trước khi gửi log ─────────────────────────
    const challengeEndpoint = STConfig.endpoint.replace('/log', '/challenge') + '?_t=' + new Date().getTime();
    fetch(challengeEndpoint, { method: 'GET', credentials: 'omit', cache: 'no-store' })
      .then(function(r) { return r.json(); })
      .then(function(challengeData) {
        const challengeToken = (challengeData && challengeData.token) ? challengeData.token : '';

        const realElapsed = Math.floor((Date.now() - new Date(startTime).getTime()) / 1000);

        const payload = {
          session_id:       sessionId,
          url:              window.location.href,
          page_path:        STConfig.page_path || window.location.pathname,
          post_type:        (typeof STConfig !== 'undefined') ? STConfig.post_type : '',
          post_id:          (typeof STConfig !== 'undefined') ? STConfig.post_id : '',
          duration_seconds: Math.max(activeSeconds, realElapsed),
          timestamp_start:  startTime,
          timestamp_end:    endTime,
          user_name:        user.name        || '',
          emp_code:         user.emp_code    || '',
          class_code:       user.class_code  || '',
          team:             user.team        || '',
          max_scroll:       maxScroll,
          referrer:         document.referrer || '',
          events:           behaviorQueue,
          // Challenge token
          challenge:        challengeToken,
          // Bot fingerprint signals
          webdriver_flag:   webdriverFlag,
          plugin_count:     pluginCount,
          has_chrome_object: hasChromeObject,
        };

        if (useBeacon && navigator.sendBeacon) {
          const blob = new Blob(
            [JSON.stringify(Object.assign(payload, { _nonce: STConfig.nonce }))],
            { type: 'application/json' }
          );
          navigator.sendBeacon(STConfig.endpoint, blob);
        } else {
          fetch(STConfig.endpoint, {
            method: 'POST',
            credentials: 'omit',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          })
            .then(function(response) {
              if (response.status === 429) {
                showToast(STConfig.messages.rate_limit || 'Bạn đang gửi quá nhanh (Rate Limit)! Vui lòng thử lại sau.', true, 'rate_limit');
                return null;
              }
              if (response.status === 403) {
                // Challenge không hợp lệ — không hiện lỗi để tránh lộ thông tin
                return null;
              }
              return response.json();
            })
            .then(function(data) {
              if (!data) return;
              if (data.success) {
                const msg = STConfig.messages.success ? STConfig.messages.success.replace('{name}', user.name || '') : '🎉 Ghi nhận KPI traffic thành công cho: ' + (user.name || '');
                showToast(msg, false, 'success');
                // Xóa cache dashboard để khi mở lại sẽ lấy dữ liệu mới (tiến độ cập nhật)
                dashboardCache = null;
                // Nếu bảng dashboard đang mở thì tải lại dữ liệu luôn
                const overlay = document.getElementById('st-id-overlay');
                if (overlay && overlay.style.display === 'flex') {
                  selectTab(activeTab);
                }
              } else if (data.reason) {
                if (data.reason === 'missing_requirements' || data.reason === 'not_enough_conditions') {
                  const msg = STConfig.messages.not_enough_conditions ? STConfig.messages.not_enough_conditions.replace('{onsite}', THRESHOLD).replace('{scroll}', SCROLL_THRESHOLD) : 'Chưa đủ điều kiện: Cần onsite >= ' + THRESHOLD + 's và cuộn >= ' + SCROLL_THRESHOLD + '%';
                  showToast(msg, true, 'not_enough_conditions');
                } else if (data.reason === 'already_done_today' || data.reason === 'already_completed') {
                  showToast(STConfig.messages.already_completed || 'Bạn đã hoàn thành nhiệm vụ cho đường dẫn này trong ngày hôm nay rồi!', true, 'already_completed');
                } else if (data.reason === 'invalid_staff_credentials' || data.reason === 'invalid_staff') {
                  showToast(STConfig.messages.invalid_staff || 'Thông tin nhân sự không tồn tại hoặc không khớp!', true, 'invalid_staff');
                  saveUser({ name: '', emp_code: '', class_code: '', team: '' });
                } else if (data.reason === 'invalid_challenge' || data.reason === 'bot') {
                  // Không hiện lỗi cụ thể — tránh lộ cơ chế bảo mật
                } else {
                  showToast(data.message || 'Từ chối log: ' + data.reason, true, 'connection_failed');
                }
              }
            })
            .catch(function() {
              showToast(STConfig.messages.connection_failed || 'Kết nối thất bại, không thể ghi nhận KPI traffic!', true, 'connection_failed');
            });
        }
      })
      .catch(function() {
        // Challenge fetch thất bại — không gửi log
        showToast(STConfig.messages.connection_failed || 'Kết nối thất bại, không thể ghi nhận KPI traffic!', true, 'connection_failed');
      });
  }


  /* ── Toast Notification Helper ──────────────────────────────────────────── */
  let toastTimeout = null;
  /* ── Countdown Toast (đếm ngược thời gian onsite) ──────────────────────── */
  let countdownToastVisible = false;

  function showCountdownToast(remaining) {
    const toast = document.getElementById('st-toast');
    const msg   = document.getElementById('st-toast-msg');
    const icon  = document.getElementById('st-toast-icon');
    if (!toast || !msg || !icon) return;

    // Cập nhật nội dung mỗi giây
    icon.textContent = '✅';
    toast.style.backgroundColor = '#0ea5e9'; // Màu xanh dương — phân biệt với success/error

    if (remaining > 0) {
      if (STConfig.show_messages && STConfig.show_messages.stay_longer === false) {
        hideCountdownToast();
        return;
      }
      msg.innerHTML = STConfig.messages.stay_longer ? STConfig.messages.stay_longer.replace('{remaining}', remaining) : 'Bạn đã cuộn đủ! Hãy ở lại thêm ' + remaining + ' giây nữa để hoàn thành.';
    } else {
      if (STConfig.show_messages && STConfig.show_messages.recording_traffic === false) {
        hideCountdownToast();
        return;
      }
      msg.textContent = STConfig.messages.recording_traffic || 'Đang ghi nhận traffic...';
    }

    // Hủy auto-hide của toast thông thường (nếu có)
    if (toastTimeout) {
      clearTimeout(toastTimeout);
      toastTimeout = null;
    }

    if (!countdownToastVisible) {
      countdownToastVisible = true;
      toast.classList.add('st-toast-show');
    }
  }

  function hideCountdownToast() {
    if (!countdownToastVisible) return;
    const toast = document.getElementById('st-toast');
    if (toast) toast.classList.remove('st-toast-show');
    countdownToastVisible = false;
  }

  function showToast(message, isError, msgKey) {
    if (msgKey && STConfig.show_messages && STConfig.show_messages[msgKey] === false) {
      return;
    }

    const toast = document.getElementById('st-toast');
    const msg = document.getElementById('st-toast-msg');
    const icon = document.getElementById('st-toast-icon');
    if (!toast || !msg || !icon) return;

    // Nếu đang hiển thị countdown toast → reset state
    countdownToastVisible = false;

    msg.textContent = message;
    icon.textContent = isError ? '❌' : '✅';
    toast.style.backgroundColor = isError ? '#ef4444' : '#10b981';

    if (toastTimeout) {
      clearTimeout(toastTimeout);
    }

    toast.classList.add('st-toast-show');

    const duration = ((typeof STConfig !== 'undefined' && STConfig.toast_duration) ? parseInt(STConfig.toast_duration, 10) : 5) * 1000;

    toastTimeout = setTimeout(function () {
      toast.classList.remove('st-toast-show');
    }, duration);
  }

  /* ── Identity popup ─────────────────────────────────────────────────────── */
  function buildUI() {
    const user = loadUser();

    /* Toast Container */
    const toastEl = document.createElement('div');
    toastEl.id = 'st-toast';
    toastEl.innerHTML = '<span id="st-toast-icon">✅</span><span id="st-toast-msg"></span><span id="st-toast-close" style="position: absolute; top: -8px; right: -8px; width: 18px; height: 18px; background: #374151; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; cursor: pointer; user-select: none; box-shadow: 0 2px 5px rgba(0,0,0,0.2); border: 1.5px solid #fff; line-height: 1; z-index: 10;">&times;</span>';
    document.body.appendChild(toastEl);

    document.getElementById('st-toast-close').addEventListener('click', function(e) {
      e.stopPropagation();
      toastEl.classList.remove('st-toast-show');
    });

    /* Floating button */
    const btn = document.createElement('button');
    btn.id = 'st-id-btn';
    btn.setAttribute('aria-label', 'Cài đặt');
    btn.innerHTML = '<span class="st-btn-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96a6.99 6.99 0 0 0-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></span>';


    /* Modal overlay */
    const overlay = document.createElement('div');
    overlay.id = 'st-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'st-modal-title');
    overlay.innerHTML = `
      <div id="st-modal">
        <h3 id="st-modal-title">Xác thực Nhân sự</h3>
        <label for="st-input-empcode">Mã nhân viên</label>
        <input id="st-input-empcode" type="text"
               placeholder="NV001" maxlength="50"
               value="${escAttr(user.emp_code || '')}">
        <label for="st-input-classcode">Mã lớp</label>
        <input id="st-input-classcode" type="text"
               placeholder="K01" maxlength="50"
               value="${escAttr(user.class_code || '')}">
               
        <div id="st-verification-error" style="color: #ef4444; font-size: 13px; font-weight: 500; margin-bottom: 12px; display: none;"></div>
        <div id="st-verification-success" style="color: #10b981; font-size: 13px; font-weight: 500; margin-bottom: 12px; display: none;"></div>

        <div id="st-modal-actions">
          <button id="st-modal-cancel" type="button">Đóng</button>
          <button id="st-modal-save"   type="button">Xác nhận</button>
        </div>
        <p id="st-modal-note">Vui lòng nhập Mã nhân viên và Mã lớp. Họ tên và bộ phận sẽ được tra cứu tự động.</p>
      </div>
    `;
    document.body.appendChild(overlay);

    /* Dashboard overlay (Bảng nhiệm vụ & doanh thu) */
    const dbOverlay = document.createElement('div');
    dbOverlay.id = 'st-dashboard-overlay';
    dbOverlay.setAttribute('role', 'dialog');
    dbOverlay.setAttribute('aria-modal', 'true');
    dbOverlay.innerHTML = `
      <div id="st-dashboard-modal">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
          <h3 style="margin: 0; font-size: 17px; color: #1d2327; width: 60%;">Nhiệm vụ & Doanh thu</h3>
          <span id="st-db-user-info" style="font-size: 13px; color: #4b5563; font-weight: 600;"></span>
        </div>

        <!-- Tab buttons (Nhiệm vụ, Tiền, Lịch sử) -->
        <div id="st-tab-buttons" style="display: flex; justify-content: space-between; gap: 8px; margin-bottom: 15px;">
          <button id="st-tab-btn-missions" class="st-tab-btn" type="button" style="flex: 1; padding: 8px 0; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #2271b1; border-radius: 4px; background: #2271b1; color: #fff; transition: all 0.15s;">Nhiệm vụ</button>
          <button id="st-tab-btn-money" class="st-tab-btn" type="button" style="flex: 1; padding: 8px 0; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #c3c4c7; border-radius: 4px; background: #f0f0f1; color: #3c434a; transition: all 0.15s;">Tiền</button>
          <button id="st-tab-btn-history" class="st-tab-btn" type="button" style="flex: 1; padding: 8px 0; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid #c3c4c7; border-radius: 4px; background: #f0f0f1; color: #3c434a; transition: all 0.15s;">Lịch sử</button>
        </div>

        <!-- Dòng chú thích nhỏ chỉ hiển thị thông tin hôm nay -->
        <div style="font-size: 11px; color: #64748b; font-style: italic; margin-top: -8px; margin-bottom: 10px; text-align: left; display: flex; align-items: center; gap: 4px;">
          <span style="display: inline-block; width: 5px; height: 5px; background: #2271b1; border-radius: 50%;"></span>
          <span>Số liệu và lịch sử chỉ tính trong ngày hôm nay</span>
        </div>

        <!-- Tab content container -->
        <div id="st-tab-content" style="max-height: 260px; overflow-y: auto; font-size: 12.5px; line-height: 1.45; color: #3c434a; margin-bottom: 18px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; background: #fafafa; box-sizing: border-box;">
          <div id="st-tab-loading" style="text-align: center; color: #787c82; padding: 15px 0;">Đang tải dữ liệu...</div>
          <div id="st-tab-inner-content"></div>
        </div>

        <!-- Footer actions -->
        <div style="display: flex; justify-content: space-between; gap: 12px;">
          <button id="st-dashboard-logout" type="button" style="padding: 9px 20px; border: none; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity .15s; background: #ef4444; color: #fff;">Đăng xuất</button>
          <button id="st-dashboard-close" type="button" style="padding: 9px 20px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; background: #f0f0f1; color: #3c434a;">Đóng</button>
        </div>
      </div>
    `;
    document.body.appendChild(dbOverlay);

    /* Password overlay */
    const passOverlay = document.createElement('div');
    passOverlay.id = 'st-pass-overlay';
    passOverlay.style.display = 'none';
    passOverlay.style.position = 'fixed';
    passOverlay.style.top = '0';
    passOverlay.style.left = '0';
    passOverlay.style.width = '100%';
    passOverlay.style.height = '100%';
    passOverlay.style.background = 'rgba(0,0,0,0.6)';
    passOverlay.style.zIndex = '999999';
    passOverlay.style.alignItems = 'center';
    passOverlay.style.justifyContent = 'center';
    passOverlay.style.backdropFilter = 'blur(3px)';
    passOverlay.innerHTML = `
      <div style="background:#fff; border-radius:8px; padding:20px; width:300px; box-shadow:0 10px 25px rgba(0,0,0,0.2); text-align:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;">
        <h4 style="margin:0 0 10px; font-size:15px; color:#1d2327;">Mật khẩu bảo mật</h4>
        <p style="font-size:12px; color:#64748b; margin-bottom:15px;">Vui lòng nhập mật khẩu để tiếp tục truy cập.</p>
        <input id="st-gear-pass-input" type="password" placeholder="••••••••" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:4px; font-size:16px !important; text-align:center; margin-bottom:12px; box-sizing:border-box;">
        <div id="st-gear-pass-error" style="color:#ef4444; font-size:12px; font-weight:500; margin-bottom:12px; display:none;"></div>
        <div style="display:flex; justify-content:space-between; gap:8px;">
          <button id="st-gear-pass-cancel" type="button" style="flex:1; padding:8px 0; font-size:12.5px; border:1px solid #c3c4c7; border-radius:4px; background:#f0f0f1; color:#3c434a; cursor:pointer; font-weight:600;">Đóng</button>
          <button id="st-gear-pass-submit" type="button" style="flex:1; padding:8px 0; font-size:12.5px; border:none; border-radius:4px; background:#2271b1; color:#fff; cursor:pointer; font-weight:600;">Xác nhận</button>
        </div>
      </div>
    `;
    document.body.appendChild(passOverlay);

    // Tạo wrapper phù hợp với cấu trúc HTML của social icons container
    const targetSelector = '.social-icons.share-icons.share-row.relative.icon-social.icon-style-fill';
    let target = document.querySelector(targetSelector);

    function insertIntoSocial(container) {
      // Nếu container là <ul>, dùng <li> để khớp cấu trúc; nếu không thì dùng <span>
      const tagName = container.tagName.toLowerCase();
      const wrapperTag = (tagName === 'ul' || tagName === 'ol') ? 'li' : 'span';
      const wrapper = document.createElement(wrapperTag);
      wrapper.id = 'st-id-btn-wrapper';
      wrapper.appendChild(btn);
      container.appendChild(wrapper);
    }

    if (target) {
      insertIntoSocial(target);
    } else {
      // Fallback: nếu chưa có social icons, đặt tạm thời FAB vào body
      const fallbackWrapper = document.createElement('div');
      fallbackWrapper.id = 'st-id-btn-wrapper';
      fallbackWrapper.appendChild(btn);
      document.body.appendChild(fallbackWrapper);

      // Kiểm tra định kỳ xem social icons đã xuất hiện chưa
      const checkExist = setInterval(function () {
        target = document.querySelector(targetSelector);
        if (target) {
          // Xóa fallback wrapper khỏi body
          if (fallbackWrapper.parentNode === document.body) {
            document.body.removeChild(fallbackWrapper);
          }
          // Tạo lại wrapper đúng loại và chèn vào social icons
          insertIntoSocial(target);
          clearInterval(checkExist);
        }
      }, 300);
    }

    document.body.appendChild(overlay);

    if (user.team) {
      const selectTeam = document.getElementById('st-input-team');
      if (selectTeam) {
        selectTeam.value = user.team;
      }
    }

    // Khởi tạo SlimSelect sẽ được thực hiện khi mở modal để tránh lỗi UI

    /* Events */
    btn.addEventListener('click', function() {
      const todayStr = getLocalDateString();
      let unlockedDate = null;
      try {
        unlockedDate = localStorage.getItem('st_gear_unlocked_date');
      } catch (e) {}

      if (unlockedDate === todayStr) {
        openModal();
      } else {
        showGearPasswordModal();
      }
    });
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeModal();
    });
    dbOverlay.addEventListener('click', function (e) {
      if (e.target === dbOverlay) closeModal();
    });
    const stBtnCancel = document.getElementById('st-gear-pass-cancel');
    if (stBtnCancel) stBtnCancel.addEventListener('click', hideGearPasswordModal);
    
    const stBtnSubmit = document.getElementById('st-gear-pass-submit');
    if (stBtnSubmit) stBtnSubmit.addEventListener('click', submitGearPassword);
    
    const stInputPass = document.getElementById('st-gear-pass-input');
    if (stInputPass) {
      stInputPass.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') submitGearPassword();
      });
    }

    passOverlay.addEventListener('click', function(e) {
      if (e.target === passOverlay) hideGearPasswordModal();
    });

    const modalCancel = document.getElementById('st-modal-cancel');
    if (modalCancel) modalCancel.addEventListener('click', closeModal);

    const modalSave = document.getElementById('st-modal-save');
    if (modalSave) modalSave.addEventListener('click', saveForm);

    const dbClose = document.getElementById('st-dashboard-close');
    if (dbClose) dbClose.addEventListener('click', closeModal);

    const dbLogout = document.getElementById('st-dashboard-logout');
    if (dbLogout) dbLogout.addEventListener('click', handleLogout);

    const btnMissions = document.getElementById('st-tab-btn-missions');
    if (btnMissions) btnMissions.addEventListener('click', function() { selectTab('missions'); });

    const btnMoney = document.getElementById('st-tab-btn-money');
    if (btnMoney) btnMoney.addEventListener('click', function() { selectTab('money'); });

    const btnHistory = document.getElementById('st-tab-btn-history');
    if (btnHistory) btnHistory.addEventListener('click', function() { selectTab('history'); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
        hideGearPasswordModal();
      }
    });

    const tabContent = document.getElementById('st-tab-content');
    if (tabContent) {
      tabContent.addEventListener('click', function(e) {
      if (e.target && e.target.classList.contains('st-copy-btn')) {
        const text = e.target.getAttribute('data-copy');
        if (text) {
          navigator.clipboard.writeText(text).then(function() {
            const originalText = e.target.textContent;
            e.target.textContent = 'Copied!';
            e.target.style.background = '#86efac';
            e.target.style.color = '#14532d';
            e.target.style.borderColor = '#86efac';
            
            // Tô màu xanh nhạt cho hàng khi click copy
            const row = e.target.closest('tr');
            if (row) {
              row.style.background = '#dcfce7';
              row.style.transition = 'background 0.3s';
            }

            setTimeout(function() {
              e.target.textContent = originalText;
              e.target.style.background = '#fff';
              e.target.style.color = '#334155';
              e.target.style.borderColor = '#cbd5e1';
            }, 1000);
          }).catch(function() {});
        }
      } else if (e.target && e.target.classList.contains('st-work-link')) {
        // Tô màu xanh nhạt cho hàng khi click vào link trực tiếp
        const row = e.target.closest('tr');
        if (row) {
          row.style.background = '#dcfce7';
          row.style.transition = 'background 0.3s';
        }
      } else if (e.target && e.target.classList.contains('st-money-period-btn')) {
        activeMoneyPeriod = e.target.getAttribute('data-period');
        if (dashboardCache) {
          renderActiveTab(dashboardCache);
        }
      } else if (e.target && e.target.classList.contains('st-money-subtab-btn')) {
        activeMoneySubtab = e.target.getAttribute('data-subtab');
        if (dashboardCache) {
          renderActiveTab(dashboardCache);
        }
      } else if (e.target && e.target.id === 'st-toggle-bank-form-btn') {
        const form = document.getElementById('st-bank-form-container');
        if (form) {
          const isHidden = form.style.display === 'none';
          form.style.display = isHidden ? 'block' : 'none';
          document.getElementById('st-bank-edit-id').value = '';
          document.getElementById('st-bank-number').value = '';
          document.getElementById('st-bank-name').value = '';
          document.getElementById('st-bank-form-title').textContent = 'Thêm tài khoản ngân hàng';
          document.getElementById('st-bank-submit-btn').textContent = 'Lưu';
        }
      } else if (e.target && e.target.id === 'st-bank-cancel-btn') {
        const form = document.getElementById('st-bank-form-container');
        if (form) form.style.display = 'none';
      } else if (e.target && e.target.id === 'st-bank-submit-btn') {
        saveBankAccount();
      } else if (e.target && e.target.classList.contains('st-bank-edit-btn')) {
        const btn = e.target;
        const form = document.getElementById('st-bank-form-container');
        if (form) {
          form.style.display = 'block';
          document.getElementById('st-bank-edit-id').value = btn.getAttribute('data-id');
          document.getElementById('st-bank-code').value = btn.getAttribute('data-code');
          document.getElementById('st-bank-number').value = btn.getAttribute('data-number');
          document.getElementById('st-bank-name').value = btn.getAttribute('data-account-name');
          document.getElementById('st-bank-form-title').textContent = 'Cập nhật tài khoản ngân hàng';
          document.getElementById('st-bank-submit-btn').textContent = 'Cập nhật';
          form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      } else if (e.target && e.target.classList.contains('st-bank-delete-btn')) {
        const id = e.target.getAttribute('data-id');
        if (confirm('Bạn có chắc chắn muốn xóa tài khoản ngân hàng này?')) {
          deleteBankAccount(id);
        }
      } else if (e.target && e.target.id === 'st-withdraw-submit-btn') {
        submitWithdrawal();
      } else if (e.target && e.target.classList.contains('st-withdraw-cancel-btn')) {
        const id = e.target.getAttribute('data-id');
        if (confirm('Bạn có chắc chắn muốn hủy yêu cầu rút tiền này?')) {
          cancelWithdrawal(id);
        }
      }
    });
    }
  }

  let dashboardCache = null;
  let activeTab = 'missions';
  let activeMoneyPeriod = 'today';
  let historyPage = 1;
  let activeMoneySubtab = 'wallet';
  let walletData = null;
  let bankAccounts = [];
  let withdrawals = [];
  let walletError = false;

  const BANK_LIST = [
    { code: 'VCB', name: 'Vietcombank' },
    { code: 'TCB', name: 'Techcombank' },
    { code: 'MB', name: 'MB Bank' },
    { code: 'CTG', name: 'VietinBank' },
    { code: 'BID', name: 'BIDV' },
    { code: 'ACB', name: 'ACB' },
    { code: 'STB', name: 'Sacombank' },
    { code: 'VPB', name: 'VPBank' },
    { code: 'VBA', name: 'Agribank' },
    { code: 'TPB', name: 'TPBank' },
    { code: 'SHB', name: 'SHB' },
    { code: 'HDB', name: 'HDBank' },
    { code: 'VIB', name: 'VIB' },
    { code: 'MSB', name: 'MSB' }
  ];

  function getLocalDateString() {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function selectTab(tab) {
    activeTab = tab;
    
    // Cập nhật giao diện các nút tab
    const tabs = ['missions', 'money', 'history'];
    tabs.forEach(function(t) {
      const btn = document.getElementById('st-tab-btn-' + t);
      if (btn) {
        if (t === tab) {
          btn.style.background = '#2271b1';
          btn.style.color = '#fff';
          btn.style.borderColor = '#2271b1';
        } else {
          btn.style.background = '#f0f0f1';
          btn.style.color = '#3c434a';
          btn.style.borderColor = '#c3c4c7';
        }
      }
    });

    const innerContent = document.getElementById('st-tab-inner-content');
    const loadingEl = document.getElementById('st-tab-loading');
    if (innerContent) innerContent.innerHTML = '';
    if (loadingEl) loadingEl.style.display = 'block';

    const user = loadUser();
    if (!user.emp_code || !user.class_code) {
      if (loadingEl) loadingEl.style.display = 'none';
      return;
    }

    if (tab === 'history') {
      loadHistoryPage(1);
      return;
    }

    if (tab === 'money') {
      const dashboardUrl = STConfig.endpoint.replace('/log', '/member-dashboard');
      const walletUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/wallet');
      const bankUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/bank-accounts');
      const withdrawUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/withdrawals');

      const queryParams = `?emp_code=${encodeURIComponent(user.emp_code)}&class_code=${encodeURIComponent(user.class_code)}`;

      Promise.all([
        fetch(dashboardUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ emp_code: user.emp_code, class_code: user.class_code })
        }).then(r => r.json()).catch(() => null),

        fetch(walletUrl + queryParams, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': STConfig.nonce }
        }).then(r => r.status === 401 ? { unauthorized: true } : r.json()).catch(() => null),

        fetch(bankUrl + queryParams, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': STConfig.nonce }
        }).then(r => r.json()).catch(() => null),

        fetch(withdrawUrl + queryParams, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': STConfig.nonce }
        }).then(r => r.json()).catch(() => null)
      ]).then(function(results) {
        if (loadingEl) loadingEl.style.display = 'none';

        const dashData = results[0];
        const walData = results[1];
        const bData = results[2];
        const wData = results[3];

        if (dashData && dashData.success) {
          dashboardCache = dashData;
        }

        if (walData && walData.unauthorized) {
          walletError = 'unauthorized';
          walletData = null;
        } else if (walData && walData.success) {
          walletError = false;
          walletData = walData.wallet;
        } else {
          walletError = 'error';
        }

        bankAccounts = (bData && bData.success) ? bData.accounts : [];
        withdrawals = (wData && wData.success) ? wData.withdrawals : [];

        renderActiveTab(dashboardCache || { periods: {} });
      }).catch(function() {
        if (loadingEl) loadingEl.style.display = 'none';
        if (innerContent) innerContent.innerHTML = '<div style="color:#ef4444; text-align:center; padding:10px 0;">Không thể tải dữ liệu tài chính.</div>';
      });

      return;
    }

    if (dashboardCache) {
      renderActiveTab(dashboardCache);
    } else {
      const dashboardUrl = STConfig.endpoint.replace('/log', '/member-dashboard');

      fetch(dashboardUrl, {
        method: 'POST',
        credentials: 'omit',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          emp_code: user.emp_code,
          class_code: user.class_code
        })
      })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (data && data.success) {
            dashboardCache = data;
            renderActiveTab(data);
          } else {
            throw new Error();
          }
        })
        .catch(function() {
          if (loadingEl) loadingEl.style.display = 'none';
          if (innerContent) innerContent.innerHTML = '<div style="color:#ef4444; text-align:center; padding:10px 0;">Không thể tải dữ liệu nhiệm vụ.</div>';
        });
    }
  }

  function loadHistoryPage(page) {
    historyPage = page;
    const innerContent = document.getElementById('st-tab-inner-content');
    const loadingEl = document.getElementById('st-tab-loading');
    if (innerContent) innerContent.innerHTML = '';
    if (loadingEl) loadingEl.style.display = 'block';

    const user = loadUser();
    if (!user.emp_code || !user.class_code) {
      if (loadingEl) loadingEl.style.display = 'none';
      return;
    }

    const historyUrl = STConfig.endpoint.replace('/log', '/member-history');

    fetch(historyUrl, {
      method: 'POST',
      credentials: 'omit',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        emp_code: user.emp_code,
        class_code: user.class_code,
        page: page,
        per_page: 5
      })
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (data && data.success) {
          renderHistoryTab(data);
        } else {
          throw new Error();
        }
      })
      .catch(function() {
        if (loadingEl) loadingEl.style.display = 'none';
        if (innerContent) innerContent.innerHTML = '<div style="color:#ef4444; text-align:center; padding:10px 0;">Không thể tải lịch sử chạy traffic.</div>';
      });
  }

  function renderHistoryTab(data) {
    const innerContent = document.getElementById('st-tab-inner-content');
    if (!innerContent) return;

    const list = data.logs || [];
    if (list.length === 0) {
      innerContent.innerHTML = '<div style="text-align:center; color:#787c82; padding:15px 0;">Hôm nay bạn chưa chạy link nào hoặc chưa được ghi nhận.</div>';
      return;
    }

    let html = '<div style="display:flex; flex-direction:column; gap:8px;">';
    list.forEach(function(log) {
      const timeStr = log.created_at ? log.created_at.split(' ')[1] : '';
      html += `
        <div style="border-bottom:1px solid #f0f0f1; padding-bottom:6px; display:flex; justify-content:space-between; align-items:center; font-size:12px;">
          <div style="max-width:75%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:left;">
            <a href="${escAttr(log.page_path)}" target="_blank" title="${escAttr(log.page_path)}" style="color:#2271b1; text-decoration:none; font-weight:500;">${escHtml(log.page_path)}</a>
          </div>
          <span style="font-size:11px; color:#94a3b8;">${escHtml(timeStr)}</span>
        </div>
      `;
    });
    html += '</div>';

    if (data.total_pages > 1) {
      html += `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; font-size:12px; border-top:1px solid #f0f0f1; padding-top:8px;">
          <button id="st-hist-prev" type="button" ${data.current_page <= 1 ? 'disabled' : ''} style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; background:${data.current_page <= 1 ? '#f1f5f9' : '#fff'}; color:${data.current_page <= 1 ? '#94a3b8' : '#334155'}; cursor:${data.current_page <= 1 ? 'not-allowed' : 'pointer'};">Trang trước</button>
          <span style="color:#64748b;">Trang <strong>${data.current_page}</strong> / ${data.total_pages}</span>
          <button id="st-hist-next" type="button" ${data.current_page >= data.total_pages ? 'disabled' : ''} style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; background:${data.current_page >= data.total_pages ? '#f1f5f9' : '#fff'}; color:${data.current_page >= data.total_pages ? '#94a3b8' : '#334155'}; cursor:${data.current_page >= data.total_pages ? 'not-allowed' : 'pointer'};">Trang sau</button>
        </div>
      `;
    }

    innerContent.innerHTML = html;

    const prevBtn = document.getElementById('st-hist-prev');
    const nextBtn = document.getElementById('st-hist-next');
    if (prevBtn && data.current_page > 1) {
      prevBtn.addEventListener('click', function() {
        loadHistoryPage(data.current_page - 1);
      });
    }
    if (nextBtn && data.current_page < data.total_pages) {
      nextBtn.addEventListener('click', function() {
        loadHistoryPage(data.current_page + 1);
      });
    }
  }

  function renderActiveTab(data) {
    const innerContent = document.getElementById('st-tab-inner-content');
    const loadingEl = document.getElementById('st-tab-loading');
    if (loadingEl) loadingEl.style.display = 'none';
    if (!innerContent) return;

    let html = '';

    if (activeTab === 'missions') {
      const list = data.active_missions || [];
      
      // Flatten all product paths/links of all active missions into a single array of work items
      let allItems = [];
      list.forEach(function(m) {
        const paths = m.paths || [];
        paths.forEach(function(p) {
          allItems.push({
            missionId: m.id,
            title: m.title,
            period_value: m.period_value,
            kpi_achieved: m.kpi_achieved,
            kpi_target: m.kpi_target,
            path: p.path,
            sku: p.sku
          });
        });
      });

      // Filter out links that have already been visited today
      let visitedMap = {};
      (data.today_visited_paths || []).forEach(function(path) {
        visitedMap[rtrimSlash(path).toLowerCase()] = true;
      });

      function rtrimSlash(str) {
        return str.replace(/\/+$/, '');
      }

      let activeItems = allItems.filter(function(item) {
        const normalizedPath = rtrimSlash(item.path).toLowerCase();
        return !visitedMap[normalizedPath];
      });

      // Lấy danh sách định danh các liên kết được chọn từ localStorage
      let selectedKeys = [];
      try {
        const stored = localStorage.getItem('st_selected_work_keys');
        if (stored) {
          selectedKeys = JSON.parse(stored);
        }
      } catch (e) {}

      // Lọc các liên kết còn hoạt động (chưa chạy hôm nay) và nằm trong nhóm 10 đang chọn
      let currentActiveSelected = activeItems.filter(function(item) {
        const key = item.missionId + '_' + item.path;
        return selectedKeys.indexOf(key) !== -1;
      });

      // Bổ sung thêm cho đủ tối đa 10 liên kết nếu danh sách bị thiếu hụt (do vừa chạy xong nhiệm vụ)
      if (currentActiveSelected.length < 10 && activeItems.length > 0) {
        const needed = 10 - currentActiveSelected.length;
        // Tìm những item chưa được chọn trong activeItems
        const unselectedActiveItems = activeItems.filter(function(item) {
          const key = item.missionId + '_' + item.path;
          return selectedKeys.indexOf(key) === -1;
        });

        if (unselectedActiveItems.length > 0) {
          // Trộn ngẫu nhiên các item chưa được chọn để bổ sung
          let additional = unselectedActiveItems.slice();
          for (let i = additional.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const temp = additional[i];
            additional[i] = additional[j];
            additional[j] = temp;
          }
          const picked = additional.slice(0, needed);
          currentActiveSelected = currentActiveSelected.concat(picked);
        }

        // Lưu lại danh sách định danh mới vào localStorage
        const newSelectedKeys = currentActiveSelected.map(function(item) {
          return item.missionId + '_' + item.path;
        });
        try {
          localStorage.setItem('st_selected_work_keys', JSON.stringify(newSelectedKeys));
        } catch (e) {}
      }

      if (currentActiveSelected.length === 0) {
        html = '<div style="text-align:center; color:#787c82; padding:15px 0;">Hôm nay bạn đã chạy tất cả sản phẩm được phân công!</div>';
      } else {
        html += `
          <table style="width:100%; border-collapse:collapse; text-align:left; font-size:12px;">
            <thead>
              <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1;">
                <th style="padding:8px 6px; color:#475569; font-weight:600; width:35%;">Nhiệm vụ</th>
                <th style="padding:8px 6px; color:#475569; font-weight:600; width:45%;">Từ khóa / Link</th>
                <th style="padding:8px 6px; color:#475569; font-weight:600; text-align:center; width:20%;">Tiến độ</th>
              </tr>
            </thead>
            <tbody>
        `;
        currentActiveSelected.forEach(function(item) {
          html += `
            <tr style="border-bottom:1px solid #e2e8f0; vertical-align:top;">
              <td style="padding:8px 6px; text-align:left;">
                <div style="font-weight:600; color:#1d2327; margin-bottom:2px; max-width:110px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escAttr(item.title)}">${escHtml(item.title)}</div>
                <div style="font-size:10px; color:#64748b;">Hạn: ${escHtml(item.period_value)}</div>
              </td>
              <td style="padding:8px 6px; text-align:left;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:4px;">
                  <a class="st-work-link" href="${escAttr(item.path)}" target="_blank" title="${escAttr(item.path)}" style="color:#2271b1; text-decoration:none; font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:90px;">${escHtml(item.sku)}</a>
                  <button type="button" class="st-copy-btn" data-copy="${escAttr(item.sku)}" style="padding:2px 5px; font-size:10px; font-weight:600; border:1px solid #cbd5e1; border-radius:4px; background:#fff; color:#334155; cursor:pointer; line-height:1.2; transition:all 0.1s;">Copy</button>
                </div>
              </td>
              <td style="padding:8px 6px; text-align:center; font-weight:600; color:#16a34a; font-size:13px; line-height:1.8;">
                ${item.kpi_achieved}/${item.kpi_target}
              </td>
            </tr>
          `;
        });
        html += `
            </tbody>
          </table>
        `;
      }
    } else if (activeTab === 'money') {
      html += `
        <!-- Sub-tabs for Money View -->
        <div style="display:flex; justify-content:space-between; gap:6px; margin-bottom:12px;">
          <button type="button" class="st-money-subtab-btn" data-subtab="wallet" style="flex:1; padding:6px 0; font-size:11.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneySubtab === 'wallet' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneySubtab === 'wallet' ? '#2271b1' : '#fff'}; color:${activeMoneySubtab === 'wallet' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Ví & Hoa hồng</button>
          <button type="button" class="st-money-subtab-btn" data-subtab="withdraw" style="flex:1; padding:6px 0; font-size:11.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneySubtab === 'withdraw' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneySubtab === 'withdraw' ? '#2271b1' : '#fff'}; color:${activeMoneySubtab === 'withdraw' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Tài khoản ngân hàng</button>
        </div>
      `;

      if (activeMoneySubtab === 'wallet') {
        const periodData = (data.periods && data.periods[activeMoneyPeriod]) ? data.periods[activeMoneyPeriod] : { total_unpaid: 0, total_paid: 0, missions: [] };
        
        html += `
          <!-- Sub-tabs for Money Period -->
          <div style="display:flex; justify-content:space-between; gap:4px; margin-bottom:12px;">
            <button type="button" class="st-money-period-btn" data-period="today" style="flex:1; padding:6px 0; font-size:10.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneyPeriod === 'today' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneyPeriod === 'today' ? '#2271b1' : '#fff'}; color:${activeMoneyPeriod === 'today' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Hôm nay</button>
            <button type="button" class="st-money-period-btn" data-period="week" style="flex:1; padding:6px 0; font-size:10.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneyPeriod === 'week' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneyPeriod === 'week' ? '#2271b1' : '#fff'}; color:${activeMoneyPeriod === 'week' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Tuần này</button>
            <button type="button" class="st-money-period-btn" data-period="month" style="flex:1; padding:6px 0; font-size:10.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneyPeriod === 'month' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneyPeriod === 'month' ? '#2271b1' : '#fff'}; color:${activeMoneyPeriod === 'month' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Tháng này</button>
            <button type="button" class="st-money-period-btn" data-period="all" style="flex:1; padding:6px 0; font-size:10.5px; font-weight:600; cursor:pointer; border:1px solid ${activeMoneyPeriod === 'all' ? '#2271b1' : '#cbd5e1'}; border-radius:3px; background:${activeMoneyPeriod === 'all' ? '#2271b1' : '#fff'}; color:${activeMoneyPeriod === 'all' ? '#fff' : '#475569'}; transition:all 0.15s; outline:none;">Tất cả</button>
          </div>

          <div style="display:flex; gap:8px; margin-bottom:12px;">
            <div style="flex:1; background:#fffbeb; border:1px solid #fef3c7; border-radius:4px; padding:10px 6px; text-align:center;">
              <div style="font-size:10px; color:#b45309; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Chưa Chuyển</div>
              <div style="font-size:14px; color:#b45309; font-weight:bold; margin-top:2px;">${fmt(periodData.total_unpaid || 0)} đ</div>
            </div>
            <div style="flex:1; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px; padding:10px 6px; text-align:center;">
              <div style="font-size:10px; color:#15803d; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Đã Chuyển</div>
              <div style="font-size:14px; color:#166534; font-weight:bold; margin-top:2px;">${fmt(periodData.total_paid || 0)} đ</div>
            </div>
          </div>
        `;

        const allMissions = periodData.missions || [];

        if (allMissions.length === 0) {
          html += '<div style="text-align:center; color:#787c82; padding:15px 0;">Chưa có dữ liệu phân công tính tiền.</div>';
        } else {
          allMissions.forEach(function(item) {
            const isPaid = (item.status === 'paid');
            html += `
              <div style="display:flex; justify-content:space-between; border-bottom:1px solid #f0f0f1; padding:6px 0; align-items:center;">
                <div style="max-width:65%; text-align:left;">
                  <span style="font-weight:600; color:#1d2327; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${escAttr(item.title)}">${escHtml(item.title)}</span>
                  <span style="font-size:11px; color:#787c82;">Kỳ: ${escHtml(item.period_value)} (${item.kpi_achieved}/${item.kpi_target})</span>
                </div>
                <div style="text-align:right;">
                  <div style="font-weight:bold; color:${isPaid ? '#166534' : '#b45309'}; font-size:13px; white-space:nowrap;">+${fmt(item.amount)} đ</div>
                  <span style="display:inline-block; font-size:9.5px; padding:1px 4px; border-radius:3px; font-weight:600; line-height:1; margin-top:2px; background:${isPaid ? '#dcfce7' : '#fef3c7'}; color:${isPaid ? '#15803d' : '#d97706'};">${isPaid ? 'Đã chuyển' : 'Chưa chuyển'}</span>
                </div>
              </div>
            `;
          });
        }
      } else {
        if (walletError === 'unauthorized') {
          html += `
            <div style="text-align:center; color:#ef4444; padding:20px 10px; font-size:13px; font-weight:500; line-height:1.5;">
              🔒 Vui lòng đăng nhập tài khoản thành viên để liên kết tài khoản ngân hàng nhận tiền.
            </div>
          `;
        } else if (walletError === 'error' || !walletData) {
          html += `
            <div style="text-align:center; color:#ef4444; padding:20px 10px; font-size:13px; font-weight:500;">
              ❌ Lỗi tải dữ liệu ví thành viên. Vui lòng liên hệ Admin.
            </div>
          `;
        } else {
          html += `
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px; padding:10px 4px; text-align:center; margin-bottom:15px;">
              <div style="font-size:9.5px; color:#15803d; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Tổng hoa hồng tích lũy khả dụng</div>
              <div style="font-size:16px; color:#166534; font-weight:bold; margin-top:2px;">${fmt(walletData.available_balance)} đ</div>
            </div>
          `;

          html += `
            <div style="margin-bottom:12px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <h4 style="margin:0; font-size:13px; color:#334155; font-weight:600;">🏦 Tài khoản nhận tiền</h4>
                <button type="button" id="st-toggle-bank-form-btn" class="st-form-btn" style="padding:2px 6px; font-size:10px; border-radius:3px;">+ Cấu hình</button>
              </div>
          `;

          html += `
              <div id="st-bank-form-container" style="display:none; background:#f1f5f9; border:1.5px solid #cbd5e1; border-radius:4px; padding:10px; margin-bottom:10px; text-align:left;">
                <h5 id="st-bank-form-title" style="margin:0 0 8px; font-size:11.5px; color:#1e293b;">Thêm tài khoản ngân hàng</h5>
                <input type="hidden" id="st-bank-edit-id" value="">
                <div class="st-form-group">
                  <label class="st-form-label">Chọn ngân hàng</label>
                  <select id="st-bank-code" class="st-form-select">
                    ${BANK_LIST.map(b => `<option value="${escAttr(b.code)}" data-name="${escAttr(b.name)}">${escHtml(b.code)} - ${escHtml(b.name)}</option>`).join('')}
                  </select>
                </div>
                <div class="st-form-group">
                  <label class="st-form-label">Số tài khoản</label>
                  <input type="text" id="st-bank-number" class="st-form-input" placeholder="Nhập số tài khoản...">
                </div>
                <div class="st-form-group">
                  <label class="st-form-label">Tên chủ tài khoản (Viết hoa không dấu)</label>
                  <input type="text" id="st-bank-name" class="st-form-input" placeholder="VD: NGUYEN VAN A">
                </div>
                <div style="display:flex; gap:6px; justify-content:flex-end;">
                  <button type="button" id="st-bank-cancel-btn" class="st-form-btn-secondary" style="padding:4px 10px; font-size:11px;">Hủy</button>
                  <button type="button" id="st-bank-submit-btn" class="st-form-btn" style="padding:4px 10px; font-size:11px;">Lưu</button>
                </div>
              </div>
          `;

          if (bankAccounts.length === 0) {
            html += `<div style="text-align:center; color:#94a3b8; font-size:11.5px; padding:10px 0; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:4px;">Bạn chưa liên kết tài khoản ngân hàng nhận tiền.</div>`;
          } else {
            html += `<div style="display:flex; flex-direction:column; gap:6px;">`;
            bankAccounts.forEach(function(bank) {
              html += `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; padding:8px 10px; display:flex; justify-content:space-between; align-items:center; text-align:left;">
                  <div>
                    <div style="font-weight:600; color:#334155; font-size:12px;">${escHtml(bank.bank_code)} - ${escHtml(bank.account_number)}</div>
                    <div style="font-size:10.5px; color:#64748b; margin-top:2px;">${escHtml(bank.account_name)}</div>
                  </div>
                  <div style="display:flex; gap:4px;">
                    <button type="button" class="st-bank-edit-btn st-form-btn-secondary" data-id="${bank.id}" data-code="${escAttr(bank.bank_code)}" data-name="${escAttr(bank.bank_name)}" data-number="${escAttr(bank.account_number)}" data-account-name="${escAttr(bank.account_name)}" style="padding:2px 5px; font-size:9.5px; border-radius:3px;">Sửa</button>
                    <button type="button" class="st-bank-delete-btn st-form-btn-secondary" data-id="${bank.id}" style="padding:2px 5px; font-size:9.5px; color:#ef4444; border-color:#fca5a5; background:#fff5f5; border-radius:3px;">Xóa</button>
                  </div>
                </div>
              `;
            });
            html += `</div>`;
          }
          html += `</div>`;
        }
      }
    } else if (activeTab === 'history') {
      const list = data.completed_missions || [];
      if (list.length === 0) {
        html = '<div style="text-align:center; color:#787c82; padding:15px 0;">Chưa có lịch sử nhiệm vụ hoàn thành.</div>';
      } else {
        list.forEach(function(m) {
          html += `
            <div style="border-bottom:1px solid #f0f0f1; padding:8px 0;">
              <div style="font-weight:600; color:#1d2327; text-align:left;">${escHtml(m.title)}</div>
              <div style="display:flex; justify-content:space-between; font-size:11.5px; color:#787c82; margin-top:3px;">
                <span>Kỳ: <strong>${escHtml(m.period_value)}</strong></span>
                <span style="color:#15803d; font-weight:600;">✔ Đã đạt (${m.kpi_achieved}/${m.kpi_target})</span>
              </div>
            </div>
          `;
        });
      }
    }

    innerContent.innerHTML = html;
  }

  function showGearPasswordModal() {
    const passOverlay = document.getElementById('st-pass-overlay');
    if (passOverlay) {
      passOverlay.style.display = 'flex';
      const inp = document.getElementById('st-gear-pass-input');
      if (inp) {
        inp.value = '';
        inp.focus();
      }
      const err = document.getElementById('st-gear-pass-error');
      if (err) err.style.display = 'none';
    }
  }

  function hideGearPasswordModal() {
    const passOverlay = document.getElementById('st-pass-overlay');
    if (passOverlay) passOverlay.style.display = 'none';
  }

  function submitGearPassword() {
    const inp = document.getElementById('st-gear-pass-input');
    const err = document.getElementById('st-gear-pass-error');
    const submitBtn = document.getElementById('st-gear-pass-submit');
    const password = (inp ? inp.value : '').trim();

    if (!password) {
      if (err) {
        err.textContent = 'Vui lòng nhập mật khẩu!';
        err.style.display = 'block';
      }
      return;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Đang xác thực...';
    }
    if (err) err.style.display = 'none';

    const verifyUrl = STConfig.verify_endpoint ? STConfig.verify_endpoint.replace(/verify-staff/g, 'verify-gear-password') : '/wp-json/tracking/v1/verify-gear-password';

    fetch(verifyUrl, {
      method: 'POST',
      credentials: 'omit',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ password: password })
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Xác nhận';
        }
        if (data && data.success) {
          try {
            localStorage.setItem('st_gear_unlocked_date', getLocalDateString());
          } catch (e) {}
          hideGearPasswordModal();
          openModal();
        } else {
          if (err) {
            err.textContent = (data && data.error) ? data.error : 'Mật khẩu không chính xác!';
            err.style.display = 'block';
          }
        }
      })
      .catch(function() {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Xác nhận';
        }
        if (err) {
          err.textContent = 'Lỗi kết nối máy chủ!';
          err.style.display = 'block';
        }
      });
  }

  function openModal() {
    const user = loadUser();
    if (user.emp_code) {
      const dbOverlay = document.getElementById('st-dashboard-overlay');
      if (dbOverlay) {
        const userInfo = document.getElementById('st-db-user-info');
        if (userInfo) {
          userInfo.textContent = user.name + ' (' + user.class_code + ')';
        }
        dbOverlay.classList.add('st-open');
        dashboardCache = null;
        selectTab(activeTab);
      }
    } else {
      const overlay = document.getElementById('st-overlay');
      if (overlay) {
        const errorEl = document.getElementById('st-verification-error');
        const successEl = document.getElementById('st-verification-success');
        if (errorEl) errorEl.style.display = 'none';
        if (successEl) successEl.style.display = 'none';

        overlay.classList.add('st-open');
        const inp = document.getElementById('st-input-empcode');
        if (inp) inp.focus();
      }
    }
  }

  function closeModal() {
    const overlay = document.getElementById('st-overlay');
    const dbOverlay = document.getElementById('st-dashboard-overlay');
    if (overlay) overlay.classList.remove('st-open');
    if (dbOverlay) dbOverlay.classList.remove('st-open');
  }

  function handleLogout() {
    if (confirm('Bạn có chắc chắn muốn đăng xuất và xóa toàn bộ thông tin lưu trữ?')) {
      isMissionActive = false;
      if (scrollListenerAdded) {
        window.removeEventListener('scroll', onScrollEvent);
        scrollListenerAdded = false;
      }
      if (ticker) {
        clearInterval(ticker);
        ticker = null;
      }
      activeSeconds = 0;

      dashboardCache = null;
      saveUser({ name: '', emp_code: '', class_code: '', team: '' });
      try {
        localStorage.removeItem('st_user');
      } catch (e) {}
      try {
        localStorage.removeItem('st_gear_unlocked_date');
      } catch (e) {}
      try {
        sessionStorage.removeItem('st_user');
      } catch (e) {}
      document.cookie = 'st_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';

      try {
        const cacheKey = 'st_ass_' + encodeURIComponent(window.location.pathname).replace(/%/g, '_');
        sessionStorage.removeItem(cacheKey);
      } catch (e) {}

      const empInp = document.getElementById('st-input-empcode');
      const classInp = document.getElementById('st-input-classcode');
      if (empInp) {
        empInp.value = '';
        empInp.readOnly = false;
      }
      if (classInp) {
        classInp.value = '';
        classInp.readOnly = false;
      }

      const btnWrapper = document.getElementById('st-id-btn-wrapper');
      if (btnWrapper) btnWrapper.style.display = 'none';

      closeModal();

      const overlay = document.getElementById('st-overlay');
      if (overlay) {
        overlay.classList.add('st-open');
        const inp = document.getElementById('st-input-empcode');
        if (inp) inp.focus();
      }

      showToast('Đăng xuất thành công, thông tin của bạn đã được xóa sạch!', false);
    }
  }

  function saveForm() {
    const emp_code = (document.getElementById('st-input-empcode').value || '').trim();
    const class_code = (document.getElementById('st-input-classcode').value || '').trim();
    const errorEl = document.getElementById('st-verification-error');
    const successEl = document.getElementById('st-verification-success');
    const saveBtn = document.getElementById('st-modal-save');

    if (errorEl) errorEl.style.display = 'none';
    if (successEl) successEl.style.display = 'none';

    if (!emp_code || !class_code) {
      if (errorEl) {
        errorEl.textContent = 'Vui lòng nhập đầy đủ Mã nhân viên và Mã lớp!';
        errorEl.style.display = 'block';
      } else {
        alert('Vui lòng nhập đầy đủ Mã nhân viên và Mã lớp!');
      }
      return;
    }

    // 1. Kiểm tra từ ngữ bậy bạ bỉ ổi
    const badWords = [
      'địt', 'dit', 'lồn', 'lon', 'cặc', 'cac', 'đéo', 'deo', 'buồi', 'buoi', 
      'đụ', 'du', 'chịch', 'chich', 'phịch', 'phich', 'fuck', 'bitch', 'ass', 'sex',
      'đm', 'vcl', 'clm', 'clgt', 'mẹ', 'me'
    ];
    const checkStr = (emp_code + ' ' + class_code).toLowerCase();
    const hasBad = badWords.some(function(w) {
      return checkStr.includes(w);
    });

    if (hasBad) {
      if (errorEl) {
        errorEl.textContent = 'Vui lòng nhập mã hợp lệ, không sử dụng từ ngữ bậy bạ!';
        errorEl.style.display = 'block';
      } else {
        alert('Vui lòng nhập mã hợp lệ, không sử dụng từ ngữ bậy bạ!');
      }
      return;
    }

    // 2. Kiểm tra định dạng mã hợp lệ (Alphanumeric, không dấu, không khoảng trắng)
    const codeRegex = /^[a-zA-Z0-9\-_./]+$/;
    if (!codeRegex.test(emp_code) || !codeRegex.test(class_code)) {
      if (errorEl) {
        errorEl.textContent = 'Mã nhân viên và Mã lớp chỉ được phép chứa ký tự không dấu (A-Z, a-z, 0-9), không chứa khoảng trắng hay ký tự đặc biệt!';
        errorEl.style.display = 'block';
      } else {
        alert('Mã nhân viên và Mã lớp chỉ được phép chứa ký tự không dấu và số!');
      }
      return;
    }

    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Đang xác thực...';
    }

    const verifyUrl = STConfig.verify_endpoint || (STConfig.endpoint ? STConfig.endpoint.replace(/log/g, 'verify-staff') : '/wp-json/tracking/v1/verify-staff');

    fetch(verifyUrl, {
      method: 'POST',
      credentials: 'omit',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ emp_code: emp_code, class_code: class_code }),
    })
      .then(function (response) {
        if (!response.ok) {
          if (response.status === 500) {
            throw new Error('db_error');
          }
          if (response.status === 403) {
            throw new Error('nonce_error');
          }
          if (response.status === 429) {
            throw new Error('rate_limit');
          }
          throw new Error('http_error_' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (data && data.success) {
          if (successEl) {
            successEl.textContent = 'Xác thực thành công: ' + data.user_name;
            successEl.style.display = 'block';
          }
          saveUser({
            name: data.user_name,
            emp_code: emp_code,
            class_code: class_code,
            team: data.team
          });
          setTimeout(function() {
            closeModal();
            if (saveBtn) {
              saveBtn.disabled = false;
              saveBtn.textContent = 'Xác nhận';
            }
            checkMissionAndStart();
            openModal();
          }, 800);
        } else {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Xác nhận';
          }
          if (errorEl) {
            errorEl.textContent = (data && data.error) ? data.error : 'Thông tin không tồn tại!';
            errorEl.style.display = 'block';
          } else {
            alert((data && data.error) ? data.error : 'Thông tin không tồn tại!');
          }
        }
      })
      .catch(function (err) {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Xác nhận';
        }
        
        let msg = 'Không thể kết nối đến máy chủ!';
        if (err && err.message) {
          if (err.message === 'db_error') {
            msg = 'Hệ thống gặp sự cố: Không thể kết nối cơ sở dữ liệu trên máy chủ!';
          } else if (err.message === 'nonce_error') {
            msg = 'Yêu cầu bị từ chối (403): Phiên làm việc hết hạn hoặc bị chặn. Vui lòng tải lại trang (F5)!';
          } else if (err.message === 'rate_limit') {
            msg = 'Bạn đang thao tác quá nhanh. V vui lòng thử lại sau!';
          } else if (err.message.indexOf('http_error_') === 0) {
            msg = 'Máy chủ phản hồi lỗi kỹ thuật (' + err.message.replace('http_error_', '') + ')!';
          }
        }
        if (err && err.name === 'SyntaxError') {
          msg = 'Lỗi phản hồi hệ thống: Phản hồi từ máy chủ không hợp lệ (có thể cơ sở dữ liệu bị ngắt kết nối)!';
        }
        
        if (errorEl) {
          errorEl.textContent = msg;
          errorEl.style.display = 'block';
        } else {
          alert(msg);
        }
      });
  }

  /* ── Tiny helpers ───────────────────────────────────────────────────────── */
  function fmt(num) {
    if (num === null || num === undefined) return '0';
    return Number(num).toLocaleString('vi-VN');
  }
  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function escAttr(s) { return escHtml(s); }

  function saveBankAccount() {
    const editId = document.getElementById('st-bank-edit-id').value;
    const bankSelect = document.getElementById('st-bank-code');
    if (!bankSelect) return;
    const bankCode = bankSelect.value;
    const bankOption = bankSelect.options[bankSelect.selectedIndex];
    const bankName = bankOption.getAttribute('data-name');
    const accountNumber = document.getElementById('st-bank-number').value.trim();
    const accountName = document.getElementById('st-bank-name').value.trim().toUpperCase();
    const user = loadUser();

    if (!accountNumber || !accountName) {
      alert('Vui lòng điền đầy đủ số tài khoản và tên chủ tài khoản!');
      return;
    }

    const payload = {
      emp_code: user.emp_code,
      class_code: user.class_code,
      bank_code: bankCode,
      bank_name: bankName,
      account_number: accountNumber,
      account_name: accountName
    };

    const submitBtn = document.getElementById('st-bank-submit-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Đang lưu...';

    const baseUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/bank-accounts');
    const url = editId ? `${baseUrl}/${editId}` : baseUrl;
    const method = editId ? 'PUT' : 'POST';

    fetch(url, {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': STConfig.nonce
      },
      body: JSON.stringify(payload)
    })
      .then(res => res.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.textContent = editId ? 'Cập nhật' : 'Lưu';

        if (data && data.success) {
          showToast(data.message || 'Lưu tài khoản ngân hàng thành công!');
          selectTab('money');
        } else {
          alert((data && data.error) ? data.error : 'Có lỗi xảy ra khi lưu tài khoản ngân hàng!');
        }
      })
      .catch(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = editId ? 'Cập nhật' : 'Lưu';
        alert('Lỗi kết nối máy chủ!');
      });
  }

  function deleteBankAccount(id) {
    const user = loadUser();
    const queryParams = `?emp_code=${encodeURIComponent(user.emp_code)}&class_code=${encodeURIComponent(user.class_code)}`;
    const baseUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/bank-accounts');
    fetch(`${baseUrl}/${id}${queryParams}`, {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': STConfig.nonce
      }
    })
      .then(res => res.json())
      .then(data => {
        if (data && data.success) {
          showToast('Đã xóa tài khoản ngân hàng thành công.');
          selectTab('money');
        } else {
          alert((data && data.error) ? data.error : 'Xóa tài khoản ngân hàng thất bại!');
        }
      })
      .catch(() => {
        alert('Lỗi kết nối máy chủ!');
      });
  }

  function submitWithdrawal() {
    const bankSelect = document.getElementById('st-withdraw-bank');
    if (!bankSelect) return;
    
    const bankAccountId = bankSelect.value;
    const amountInput = document.getElementById('st-withdraw-amount');
    const amount = parseFloat(amountInput.value);

    if (isNaN(amount) || amount < 50000) {
      alert('Số tiền rút tối thiểu là 50.000 đ!');
      return;
    }

    if (amount > walletData.available_balance) {
      alert('Số dư khả dụng trong ví không đủ!');
      return;
    }

    const submitBtn = document.getElementById('st-withdraw-submit-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Đang gửi yêu cầu...';

    const url = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/withdrawals');
    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': STConfig.nonce
      },
      body: JSON.stringify({
        bank_account_id: parseInt(bankAccountId, 10),
        amount: amount
      })
    })
      .then(res => res.json())
      .then(data => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Gửi yêu cầu rút tiền';

        if (data && data.success) {
          showToast('🎉 Gửi yêu cầu rút tiền thành công! Vui lòng chờ phê duyệt.');
          selectTab('money');
        } else {
          alert((data && data.error) ? data.error : 'Gửi yêu cầu rút tiền thất bại!');
        }
      })
      .catch(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Gửi yêu cầu rút tiền';
        alert('Lỗi kết nối máy chủ!');
      });
  }

  function cancelWithdrawal(id) {
    const baseUrl = STConfig.endpoint.replace('/log', '/member-dashboard').replace('tracking/v1/member-dashboard', 'staff-tracking/v1/withdrawals');
    fetch(`${baseUrl}/${id}/cancel`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': STConfig.nonce
      }
    })
      .then(res => res.json())
      .then(data => {
        if (data && data.success) {
          showToast('Hủy yêu cầu rút tiền thành công.');
          selectTab('money');
        } else {
          alert((data && data.error) ? data.error : 'Hủy yêu cầu rút tiền thất bại!');
        }
      })
      .catch(() => {
        alert('Lỗi kết nối máy chủ!');
      });
  }

  /* ── Global initial activity protection ────────────────────────────────── */
  function isSeoCrawler() {
    const ua = (navigator.userAgent || '').toLowerCase();
    return /googlebot|google-inspectiontool|bingbot|yandexbot|baiduspider|duckduckbot|facebookexternalhit|twitterbot|linkedinbot|slackbot|whatsapp|telegrambot|applebot|ahrefsbot|semrushbot|mj12bot|screaming frog/.test(ua);
  }

  // Removed initial activity guard logic as it causes issues on slow connections.

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      buildUI();
      checkMissionAndStart();
    });
  } else {
    buildUI();
    checkMissionAndStart();
  }

  // Auto-refresh dashboard when window is focused (to pick up completed tasks instantly)
  window.addEventListener('focus', function() {
    const user = loadUser();
    const dbOverlay = document.getElementById('st-dashboard-overlay');
    if (user.emp_code && user.class_code && dbOverlay && dbOverlay.style.display === 'flex') {
      dashboardCache = null; // Invalidate cache
      selectTab(activeTab);
    }
  });

})();
