(() => {
    'use strict';

    const body = document.body;
    const csrf = body.dataset.csrf || '';
    const state = { currentBot: null, polling: null };
    const titles = {
        dashboard: ['داشبورد', 'نمای زنده نصب‌ها و مشتری‌ها'],
        bots: ['ربات‌ها', 'مدیریت نصب‌های مستقل مشتری‌ها'],
        operations: ['عملیات', 'صف اجرا و تاریخچه نگهداری'],
        backups: ['بکاپ‌ها', 'نسخه‌های امن اطلاعات هر ربات'],
        settings: ['تنظیمات', 'امنیت حساب و وضعیت زیرساخت'],
    };

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

    function toast(message, error = false, title = '') {
        const item = document.createElement('div');
        item.className = `toast${error ? ' toast-error' : ''}`;
        item.innerHTML = error
            ? '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01"/><path d="M10.3 3.7 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>'
            : '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>';
        const text = document.createElement('div');
        const strong = document.createElement('strong');
        const span = document.createElement('span');
        strong.textContent = title || (error ? 'عملیات ناموفق' : 'انجام شد');
        span.textContent = message;
        text.append(strong, span);
        item.append(text);
        $('#toastStack').append(item);
        window.setTimeout(() => item.remove(), 5500);
    }

    async function api(action, options = {}) {
        const method = options.method || 'GET';
        const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
        let url = `api.php?action=${encodeURIComponent(action)}`;
        if (method === 'GET' && options.params) {
            url += `&${new URLSearchParams(options.params)}`;
        }
        if (method !== 'GET') {
            init.headers['X-CSRF-Token'] = csrf;
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.data || {});
        }
        const response = await fetch(url, init);
        let payload;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('پاسخ نامعتبر از سرور دریافت شد.');
        }
        if (response.status === 401) {
            window.location.href = 'login.php';
            throw new Error('نشست منقضی شده است.');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'عملیات انجام نشد.');
        }
        return payload;
    }

    function switchView(name) {
        if (!titles[name]) return;
        $$('[data-view]').forEach(el => el.classList.toggle('is-visible', el.dataset.view === name));
        $$('.nav-item[data-view-target]').forEach(el => el.classList.toggle('is-active', el.dataset.viewTarget === name));
        $('#pageTitle').textContent = titles[name][0];
        $('.page-title p').textContent = titles[name][1];
        $('#sidebar').classList.remove('is-open');
        history.replaceState(null, '', `#${name}`);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    $$('[data-view-target]').forEach(button => button.addEventListener('click', () => switchView(button.dataset.viewTarget)));
    $('#mobileMenu')?.addEventListener('click', () => $('#sidebar').classList.toggle('is-open'));
    const initialView = location.hash.slice(1);
    if (titles[initialView]) switchView(initialView);

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        body.style.overflow = 'hidden';
        modal.querySelector('input,select,button')?.focus();
    }

    function closeModal(modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!$('.modal.is-open') && !$('.drawer.is-open')) body.style.overflow = '';
    }

    $$('[data-open-modal]').forEach(el => el.addEventListener('click', () => openModal(el.dataset.openModal)));
    $$('[data-close-modal]').forEach(el => el.addEventListener('click', () => closeModal(el.closest('.modal'))));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const opened = $('.modal.is-open') || $('.drawer.is-open');
        if (opened?.classList.contains('drawer')) closeDrawer(); else if (opened) closeModal(opened);
    });

    const expiryField = $('#createBotForm [name="expires_at"]');
    if (expiryField && !expiryField.value) {
        const date = new Date(Date.now() + 30 * 86400000);
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        expiryField.value = date.toISOString().slice(0, 16);
    }

    $('[data-toggle-secret]')?.addEventListener('click', event => {
        const input = event.currentTarget.parentElement.querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    $('#createBotForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        const data = Object.fromEntries(new FormData(form).entries());
        button.disabled = true;
        button.textContent = 'در حال ثبت...';
        try {
            const result = await api('create', { method: 'POST', data });
            closeModal($('#createBot'));
            toast(result.message, false, 'ساخت ربات آغاز شد');
            window.setTimeout(() => window.location.reload(), 1300);
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
            button.innerHTML = '<svg class="icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5v14"/></svg>ساخت و راه‌اندازی';
        }
    });

    function faStatus(status) {
        return ({ active: 'فعال', provisioning: 'در حال ساخت', suspended: 'متوقف', expired: 'منقضی', error: 'نیازمند بررسی' })[status] || status || '-';
    }

    function toLocalInput(utc) {
        const date = new Date(utc);
        if (Number.isNaN(date.getTime())) return '';
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    }

    function openDrawer(bot) {
        state.currentBot = bot;
        $('#drawerCustomer').textContent = bot.customer_name || 'مشتری';
        $('#drawerBot').textContent = `@${bot.bot_username || '-'}`;
        $('#drawerStatus').textContent = faStatus(bot.status);
        $('#drawerExpiry').textContent = new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium' }).format(new Date(bot.expires_at));
        $('#drawerSlug').textContent = bot.slug || '-';
        $('#drawerDomain').textContent = bot.domain || '-';
        $('#drawerAdmin').textContent = bot.admin_id || '-';
        $('#drawerBackup').textContent = bot.backup_schedule === 'daily' ? 'روزانه' : bot.backup_schedule === 'weekly' ? 'هفتگی' : 'خاموش';
        $('#drawerNotes').textContent = bot.notes || 'یادداشتی ثبت نشده است.';
        $('#extendForm [name="expires_at"]').value = toLocalInput(bot.expires_at);
        $('#botDrawer').classList.add('is-open');
        $('#botDrawer').setAttribute('aria-hidden', 'false');
        body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        $('#botDrawer').classList.remove('is-open');
        $('#botDrawer').setAttribute('aria-hidden', 'true');
        body.style.overflow = '';
    }

    document.addEventListener('click', event => {
        const details = event.target.closest('[data-bot-details]');
        if (details) {
            try { openDrawer(JSON.parse(details.dataset.botDetails)); } catch (_) { toast('اطلاعات ربات قابل خواندن نیست.', true); }
        }
    });
    $$('[data-close-drawer]').forEach(el => el.addEventListener('click', closeDrawer));

    $$('.action-grid [data-operation]').forEach(button => button.addEventListener('click', async () => {
        if (!state.currentBot) return;
        const operation = button.dataset.operation;
        const labels = { start: 'روشن کردن', stop: 'متوقف کردن', restart: 'راه‌اندازی مجدد', update: 'بروزرسانی', backup: 'ساخت بکاپ' };
        if (['stop', 'update'].includes(operation) && !window.confirm(`${labels[operation]} ربات «${state.currentBot.customer_name}» انجام شود؟`)) return;
        button.disabled = true;
        try {
            const result = await api('operate', { method: 'POST', data: { slug: state.currentBot.slug, operation } });
            toast(result.message, false, labels[operation]);
            closeDrawer();
            window.setTimeout(() => window.location.reload(), 1100);
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
        }
    }));

    $('#extendForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!state.currentBot) return;
        const button = event.currentTarget.querySelector('button');
        button.disabled = true;
        try {
            const result = await api('extend', { method: 'POST', data: { slug: state.currentBot.slug, expires_at: event.currentTarget.expires_at.value } });
            toast(result.message, false, 'اعتبار تمدید شد');
            closeDrawer();
            window.setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
        }
    });

    $('#deleteBot')?.addEventListener('click', async () => {
        if (!state.currentBot) return;
        const expected = state.currentBot.slug;
        const entered = window.prompt(`برای حذف کامل، شناسه «${expected}» را وارد کنید. پیش از حذف بکاپ نهایی ساخته می‌شود.`);
        if (entered !== expected) {
            if (entered !== null) toast('شناسه واردشده مطابقت ندارد.', true);
            return;
        }
        try {
            const result = await api('operate', { method: 'POST', data: { slug: expected, operation: 'remove' } });
            toast(result.message, false, 'حذف در صف قرار گرفت');
            closeDrawer();
            window.setTimeout(() => window.location.reload(), 1000);
        } catch (error) {
            toast(error.message, true);
        }
    });

    $('[data-show-logs]')?.addEventListener('click', async () => {
        if (!state.currentBot) return;
        openModal('logsModal');
        const output = $('#logOutput');
        output.textContent = 'در حال دریافت گزارش...';
        try {
            const result = await api('logs', { params: { slug: state.currentBot.slug } });
            output.textContent = result.logs || 'گزارشی ثبت نشده است.';
        } catch (error) {
            output.textContent = error.message;
        }
    });

    function filterBots() {
        const query = ($('#botSearch')?.value || '').trim().toLocaleLowerCase('fa');
        const status = $('#statusFilter')?.value || '';
        $$('#allBots [data-bot-row]').forEach(row => {
            const matchesText = !query || (row.dataset.search || '').includes(query);
            const matchesStatus = !status || row.dataset.status === status;
            row.hidden = !(matchesText && matchesStatus);
        });
    }
    $('#botSearch')?.addEventListener('input', filterBots);
    $('#statusFilter')?.addEventListener('change', filterBots);

    async function loadBackups() {
        const slug = $('#backupBot').value;
        if (!slug) {
            toast('ابتدا یک ربات را انتخاب کنید.', true);
            return;
        }
        const list = $('#backupList');
        list.innerHTML = '<div class="quiet-empty">در حال دریافت نسخه‌ها...</div>';
        try {
            const result = await api('backups', { params: { slug } });
            list.replaceChildren();
            if (!result.backups?.length) {
                list.innerHTML = '<div class="empty-state"><strong>بکاپی وجود ندارد</strong><span>اولین نسخه را از دکمه ساخت بکاپ ایجاد کنید.</span></div>';
                return;
            }
            result.backups.forEach(backup => {
                const row = document.createElement('div');
                row.className = 'backup-item';
                const name = document.createElement('strong');
                const size = document.createElement('span');
                const date = document.createElement('time');
                const restore = document.createElement('button');
                name.textContent = backup.name;
                size.textContent = backup.size_human;
                date.textContent = backup.created_at;
                restore.className = 'btn btn-secondary';
                restore.textContent = 'بازیابی';
                restore.addEventListener('click', () => restoreBackup(slug, backup.name, restore));
                row.append(name, size, date, restore);
                list.append(row);
            });
        } catch (error) {
            list.innerHTML = '<div class="quiet-empty">دریافت فهرست ناموفق بود.</div>';
            toast(error.message, true);
        }
    }
    $('#loadBackups')?.addEventListener('click', loadBackups);

    $('#createBackup')?.addEventListener('click', async event => {
        const slug = $('#backupBot').value;
        if (!slug) return toast('ابتدا یک ربات را انتخاب کنید.', true);
        event.currentTarget.disabled = true;
        try {
            const result = await api('operate', { method: 'POST', data: { slug, operation: 'backup' } });
            toast(result.message, false, 'بکاپ در صف قرار گرفت');
        } catch (error) {
            toast(error.message, true);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    async function restoreBackup(slug, backup, button) {
        if (!window.confirm(`اطلاعات ربات از نسخه «${backup}» بازیابی شود؟ پیش از بازیابی یک بکاپ ایمنی ساخته می‌شود.`)) return;
        button.disabled = true;
        try {
            const result = await api('operate', { method: 'POST', data: { slug, operation: 'restore', backup } });
            toast(result.message, false, 'بازیابی در صف قرار گرفت');
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
        }
    }

    $('#passwordForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form).entries());
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            const result = await api('change_password', { method: 'POST', data });
            toast(result.message, false, 'تنظیمات امنیتی');
            form.reset();
        } catch (error) {
            toast(error.message, true);
        } finally {
            button.disabled = false;
        }
    });

    $('#refreshAll')?.addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try {
            await api('snapshot');
            window.location.reload();
        } catch (error) {
            toast(error.message, true);
            event.currentTarget.disabled = false;
        }
    });

    const hasPending = () => $$('.op-queued,.op-working').length > 0 || $('.status-wait');
    if (hasPending()) {
        state.polling = window.setInterval(async () => {
            if (document.hidden) return;
            try {
                const result = await api('snapshot');
                const pending = result.operations?.some(op => ['queued', 'working'].includes(op.status));
                if (!pending) window.location.reload();
            } catch (_) {
                // A visible alert already communicates agent connectivity.
            }
        }, 8000);
    }
})();
