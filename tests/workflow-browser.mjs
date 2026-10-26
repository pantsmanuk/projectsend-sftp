import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
const origin = process.env.SFTP_WORKFLOW_ORIGIN ?? 'http://127.0.0.1:8766';
const socket = new WebSocket('ws://127.0.0.1:9222/session');
await new Promise((resolve, reject) => {
    const timer = setTimeout(() => { socket.close(); reject(new Error('Firefox automation endpoint did not open.')); }, 10000);
    socket.addEventListener('open', () => { clearTimeout(timer); resolve(); }, { once: true });
    socket.addEventListener('error', () => { clearTimeout(timer); reject(new Error('Start Firefox with the temporary profile and automation port documented in README.md.')); }, { once: true });
});
let nextId = 0;
const pending = new Map();
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(String(data));
    const task = pending.get(message.id);
    if (!task) return;
    clearTimeout(task.timer);
    pending.delete(message.id);
    if (message.type === 'error') task.reject(new Error(JSON.stringify(message)));
    else task.resolve(message.result);
});
function command(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++nextId;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Timeout: ${method}`)); }, 15000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
let context;
async function evaluate(expression) {
    const result = await command('script.evaluate', { expression, target: { context }, awaitPromise: true });
    assert.equal(result.type, 'success', JSON.stringify(result));
    return result.result.value;
}
async function navigate(path) { await command('browsingContext.navigate', { context, url: origin + path, wait: 'complete' }); }
async function screenshot(name) {
    const result = await command('browsingContext.captureScreenshot', { context, origin: 'document' });
    writeFileSync(`/tmp/projectsend-sftp-workflow-${name}.png`, Buffer.from(result.data, 'base64'));
}
async function clickAndWait(selector, path) {
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    for (let attempt = 0; attempt < 60; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 100));
        if (await evaluate('location.pathname') === path && await evaluate('document.readyState') === 'complete') {
            await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
            return;
        }
    }
    throw new Error(`Navigation did not reach ${path}`);
}

async function waitFor(expression) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(expression)) return;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Condition did not become true: ${expression}`);
}
try {
    await command('session.new', { capabilities: {} });
    ({ context } = await command('browsingContext.create', { type: 'tab' }));
    await command('browsingContext.activate', { context });
    await command('browsingContext.setViewport', { context, viewport: { width: 1280, height: 1000 } });
    await navigate('/sftp/transfers?preview_role=admin');
    assert.equal(await evaluate('document.querySelector(".module-nav").textContent.includes("Destinations")'), false);
    assert.equal(await evaluate('[...document.links].some(link => new URL(link.href).pathname.startsWith("/system/settings/sftp"))'), false);
    await evaluate('document.querySelector(".module-nav a").focus()');
    assert.equal(await evaluate('document.activeElement.textContent'), 'My transfers');
    await screenshot('admin-transfers-navigation');
    await navigate('/system/settings/sftp');
    assert.equal(await evaluate('document.querySelector("h1").textContent'), 'SFTP destinations');
    assert.equal(await evaluate('document.querySelector(".module-nav [aria-current=page]").textContent'), 'Destinations');
    console.log('PASS: Administrator transfer pages omit destination navigation while separate configuration remains accessible');
    await navigate('/sftp/transfers?preview_role=staff');
    await evaluate('localStorage.setItem("appearance","light")');
    assert.equal(await evaluate('document.querySelector("h1").textContent'), 'My transfers');
    assert.equal(await evaluate('document.querySelector(".module-nav").textContent.includes("Destinations")'), false);
    await navigate('/sftp/transfers/create?q=no-such-backup');
    assert.equal(await evaluate('document.body.textContent.includes("No matching backups")'), true);
    await clickAndWait('[aria-label="Clear search"]', '/sftp/transfers/create');
    await waitFor('document.activeElement.id === "q"');
    await screenshot('files-desktop');
    await evaluate('document.querySelector("#q").value="Example customer"; document.querySelector("[data-search-form]").requestSubmit()');
    await waitFor('location.search.includes("Example") && document.querySelectorAll("tbody tr").length===1');
    const reviewPath = await evaluate('new URL(document.querySelector("tbody a").href).pathname');
    await clickAndWait('tbody a', reviewPath);
    await evaluate('document.querySelector("[type=submit]").click()');
    await waitFor('document.querySelector("[data-form-error]") !== null');
    assert.equal(await evaluate('document.activeElement.id'), 'destination_id');
    assert.equal(await evaluate('document.querySelector("#destination_id").getAttribute("aria-invalid")'), 'true');
    console.log('PASS: Staff file search, clear focus, review, and missing-destination validation');
    await screenshot('review-error');
    const csrf = await evaluate('document.querySelector("[name=_token]").value');
    await evaluate('document.querySelector("#destination_id").focus()');
    assert.equal(await evaluate('document.activeElement.id'), 'destination_id');
    await command('input.performActions', { context, actions: [{ type: 'key', id: 'keyboard', actions: [{ type: 'keyDown', value: 'p' }, { type: 'keyUp', value: 'p' }] }] });
    assert.notEqual(await evaluate('document.querySelector("#destination_id").value'), '');
    await command('input.performActions', { context, actions: [{ type: 'key', id: 'keyboard', actions: [{ type: 'keyDown', value: ' ' }, { type: 'keyUp', value: ' ' }] }] });
    await screenshot('native-select-open');
    await command('input.performActions', { context, actions: [{ type: 'key', id: 'keyboard', actions: [{ type: 'keyDown', value: '\uE00C' }, { type: 'keyUp', value: '\uE00C' }] }] });
    await evaluate(' document.querySelector("#destination_id").dispatchEvent(new Event("change",{bubbles:true})); document.querySelector(".form-actions a").click()');
    assert.equal(await evaluate('document.querySelector("dialog").open'), true);
    assert.equal(await evaluate('document.activeElement.hasAttribute("data-stay")'), true);
    await evaluate('document.querySelector("[data-stay]").click()');
    await screenshot('review-desktop');
    const requestId = await evaluate('document.querySelector("[name=_request_id]").value');
    await clickAndWait('[type=submit]', '/sftp/transfers/'+requestId);
    assert.equal(await evaluate('document.querySelector("[data-status-label]").textContent'), 'Waiting to start');
    assert.equal(await evaluate('document.querySelector("[data-duration-value]").textContent'), '');
    async function simulate(status) {
        const code = await evaluate(`(async()=>{const response=await fetch('/__fixture/state/${requestId}',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:${JSON.stringify(csrf)},status:${JSON.stringify(status)}})});return response.status;})()`);
        assert.equal(code, 200);
    }
    await simulate('running');
    await waitFor('document.querySelector("[data-status-label]").textContent === "Sending backup"');
    assert.equal(await evaluate('document.querySelector("[data-progress-percent]").textContent'), '100%');
    assert.equal(await evaluate('document.querySelector("[data-status-message]").textContent.includes("only after")'), true);
    const elapsed = await evaluate('document.querySelector("[data-duration-value]").textContent');
    await waitFor(`document.querySelector('[data-duration-value]').textContent !== ${JSON.stringify(elapsed)}`);
    assert.equal(await evaluate('document.querySelector("[data-duration-label]").textContent'), 'Elapsed time:');
    assert.equal(await evaluate('document.querySelector("[data-transfer-duration]").closest("[aria-live]") === null'), true);
    await screenshot('progress-desktop');
    await simulate('completed');
    await waitFor('document.querySelector("[data-status-label]").textContent === "Completed"');
    const duration = await evaluate('document.querySelector("[data-duration-value]").textContent');
    assert.equal(await evaluate('document.querySelector("[data-duration-label]").textContent'), 'Duration:');
    await new Promise(resolve => setTimeout(resolve, 1200));
    assert.equal(await evaluate('document.querySelector("[data-duration-value]").textContent'), duration);
    await navigate('/sftp/transfers/'+requestId);
    assert.equal(await evaluate('document.querySelector("[data-duration-value]").textContent'), duration);
    await screenshot('completed-desktop');
    await navigate('/sftp/transfers');
    assert.equal(await evaluate(`document.querySelector('tbody a[href$="${requestId}"]')!==null`), true);
    assert.equal(await evaluate(`document.querySelector('tbody a[href$="${requestId}"]').closest('tr').querySelector('[data-duration-value]').textContent`), duration);
    console.log('PASS: Native destination selector, unsaved guard, submission, live progress, confirmed completion and history');
    await simulate('uncertain');
    await navigate('/sftp/transfers/'+requestId);
    assert.equal(await evaluate('document.querySelector("[data-status-message]").textContent.includes("before sending")'), true);
    await screenshot('uncertain-desktop');
    await simulate('failed');
    await navigate('/sftp/transfers/'+requestId);
    assert.equal(await evaluate('document.querySelector("[data-status-label]").textContent'), 'Not completed');
    await simulate('running');
    await navigate('/sftp/transfers/'+requestId);
    await evaluate('window.fetch=async()=>{throw new TypeError("fixture offline")}; document.querySelector(".page-heading a").focus()');
    await waitFor('document.querySelector("[data-refresh-message]").textContent.includes("paused")');
    const pausedDuration = await evaluate('document.querySelector("[data-duration-value]").textContent');
    await new Promise(resolve => setTimeout(resolve, 1200));
    assert.equal(await evaluate('document.querySelector("[data-duration-value]").textContent'), pausedDuration);
    assert.equal(await evaluate('document.activeElement.textContent'), 'Refresh status');
    await navigate('/sftp/transfers/'+requestId);
    await evaluate('window.fetch=async()=>new Response("",{status:401})');
    await waitFor('document.querySelector("[data-refresh-message]").textContent.includes("session")');
    console.log('PASS: Unconfirmed/failed delivery guidance, offline pause, session-change stop and stable keyboard focus');
    await command('browsingContext.setViewport', { context, viewport: { width: 390, height: 844 } });
    await navigate(reviewPath);
    await evaluate('document.querySelector(".selected-backup h2").textContent="Customer database ".repeat(15); document.querySelector("select option:last-child").textContent="Database instance ".repeat(12)');
    await screenshot('review-mobile-light');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await evaluate('localStorage.setItem("appearance","dark")');
    await navigate('/sftp/transfers/'+requestId);
    assert.equal(await evaluate('document.documentElement.dataset.theme'), 'dark');
    await screenshot('progress-mobile-dark');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await navigate('/sftp/transfers/create');
    await screenshot('files-mobile-dark');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await navigate('/sftp/transfers/create?preview_role=empty');
    assert.equal(await evaluate('document.body.textContent.includes("No backups available")'), true);
    await navigate('/system/settings/sftp?preview_role=admin');
    assert.equal(await evaluate('document.querySelector("h1").textContent'), 'SFTP destinations');
    await screenshot('configuration-sibling');
    console.log('PASS: Narrow light/dark layouts, empty library, and separate administrator destination page');
} finally {
    if (context) await command('browsingContext.close', { context }).catch(()=>{});
    await command('session.end').catch(()=>{});
    socket.close();
}
