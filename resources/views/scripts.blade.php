(() => {
    document.querySelectorAll('[data-reveal]').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.reveal);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.textContent = show ? button.dataset.hide : button.dataset.show;
        });
    });
    document.querySelectorAll('[data-search-form]').forEach(search => {
        let composing = false;
        search.addEventListener('compositionstart', () => { composing = true; });
        search.addEventListener('compositionend', () => { composing = false; });
        search.addEventListener('submit', event => { if (composing) event.preventDefault(); });
    });
    if (location.hash === '#q') document.getElementById('q')?.focus();
    const form = document.querySelector('[data-destination-form], [data-delete-form], [data-transfer-form]');
    if (!form) return;
    (form.querySelector('[aria-invalid=true]') || document.querySelector('[data-form-error]'))?.focus();
    let dirty = false;
    let submitting = false;
    const submit = form.querySelector('[type=submit]');
    const originalLabel = submit.textContent;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', event => {
        if (submitting) { event.preventDefault(); return; }
        submitting = true;
        submit.disabled = true;
        submit.textContent = submit.dataset.saving;
        form.setAttribute('aria-busy', 'true');
    });
    const dialog = document.getElementById('leave-dialog');
    document.querySelector('[data-stay]').addEventListener('click', () => dialog.close());
    document.querySelector('[data-leave]').addEventListener('click', () => { dirty = false; });
    document.querySelectorAll('a:not([data-leave])').forEach(link => {
        link.addEventListener('click', event => {
            if (!dirty || submitting || event.ctrlKey || event.metaKey || event.shiftKey || link.hash) return;
            event.preventDefault();
            document.querySelector('[data-leave]').href = link.href;
            dialog.showModal();
        });
    });
    window.addEventListener('beforeunload', event => {
        if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            submitting = false;
            submit.disabled = false;
            submit.textContent = originalLabel;
            form.removeAttribute('aria-busy');
            form.querySelectorAll('[type=password], [name=private_key_passphrase], [type=file]').forEach(input => { input.value = ''; });
        }
    });
})();

(() => {
    const panel = document.querySelector('[data-transfer-status]');
    if (!panel) return;
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    const duration = panel.querySelector('[data-transfer-duration]');
    let clock;
    function updateDuration(data) {
        clearInterval(clock);
        duration.querySelector('[data-duration-label]').textContent = data.elapsed_label;
        const value = duration.querySelector('[data-duration-value]');
        value.textContent = data.elapsed_text;
        if (data.status !== 'running' || data.elapsed_seconds === null) return;
        const measuredAt = performance.now();
        clock = setInterval(() => {
            if (document.hidden) return;
            const seconds = data.elapsed_seconds + Math.floor((performance.now() - measuredAt) / 1000);
            value.textContent = [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
                .map(part => String(part).padStart(2, '0')).join(':');
        }, 1000);
    }
    updateDuration({status: panel.dataset.state,
        elapsed_seconds: duration.dataset.seconds === '' ? null : Number(duration.dataset.seconds),
        elapsed_label: duration.querySelector('[data-duration-label]').textContent,
        elapsed_text: duration.querySelector('[data-duration-value]').textContent});
    if (!['queued', 'running'].includes(panel.dataset.state)) return;
    let timer;
    let current;
    let stopped = false;
    let attempts = 0;
    const message = panel.querySelector('[data-refresh-message]');
    const stop = text => { stopped = true; clearTimeout(timer); clearInterval(clock); message.textContent = text; };
    async function refresh() {
        if (stopped || current) return;
        clearTimeout(timer);
        if (document.hidden) { timer = setTimeout(refresh, 5000); return; }
        if (++attempts > 180) { stop(panel.dataset.paused); return; }
        current = new AbortController();
        const timeout = setTimeout(() => current?.abort(), 10000);
        try {
            const response = await fetch(panel.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: current.signal });
            if ([401, 403, 404, 419].includes(response.status)) { stop(panel.dataset.session); return; }
            if (!response.ok) throw new Error('status');
            const { data } = await response.json();
            panel.dataset.state = data.status;
            updateDuration(data);
            const label = panel.querySelector('[data-status-label]');
            label.textContent = data.label;
            label.classList.toggle('enabled', data.status === 'completed');
            panel.querySelector('[data-status-message]').textContent = data.message;
            panel.querySelector('[data-progress-text]').textContent = data.progress_text;
            panel.querySelector('[data-progress-percent]').textContent = `${data.percent}%`;
            const progress = panel.querySelector('progress');
            progress.value = data.percent;
            progress.setAttribute('aria-valuetext', data.progress_text);
            if (!['queued', 'running'].includes(data.status)) stop(panel.dataset.updated);
        } catch {
            stop(panel.dataset.paused);
        } finally {
            clearTimeout(timeout);
            current = null;
            if (!stopped) timer = setTimeout(refresh, 5000);
        }
    }
    timer = setTimeout(refresh, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !stopped) refresh(); });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); clearInterval(clock); current?.abort(); });
})();
