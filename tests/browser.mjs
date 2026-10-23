import assert from 'node:assert/strict';
import { writeFileSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

// Requires the loopback fixture and Firefox's temporary-profile BiDi endpoint.
// Uses Node's native WebSocket; no browser automation dependency is installed.
const packagePath = dirname(dirname(fileURLToPath(import.meta.url)));
const autoload = resolve(process.argv[2] ?? `${packagePath}/../../vendor/autoload.php`);
const origin = process.env.SFTP_BROWSER_ORIGIN ?? 'http://127.0.0.1:8765';
const keyPath = `/tmp/projectsend-sftp-browser-key-${process.pid}`;
const key = spawnSync('php', ['-r', 'require $argv[1]; echo phpseclib3\\Crypt\\EC::createKey("Ed25519")->toString("OpenSSH");', autoload], { encoding: 'utf8' });
assert.equal(key.status, 0, key.stderr);
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
    writeFileSync(`/tmp/projectsend-sftp-${name}.png`, Buffer.from(result.data, 'base64'));
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
try {
    writeFileSync(keyPath, key.stdout, { mode: 0o600 });
    await command('session.new', { capabilities: {} });
    ({ context } = await command('browsingContext.create', { type: 'tab' }));
    await command('browsingContext.setViewport', { context, viewport: { width: 1280, height: 1000 } });
    await navigate('/system/settings/sftp/create');
    assert.equal(await evaluate('document.querySelector("h1").textContent'), 'Add destination');
    await screenshot('create-desktop');
    assert.equal(await evaluate('document.querySelector("#windows_acl_managed").checked'), false);
    assert.equal(await evaluate('document.querySelector("#windows_acl_managed").getAttribute("aria-describedby")'), 'windows_acl_managed-help windows_acl_managed-error');
    await evaluate('document.querySelector("#windows_acl_managed").focus()');
    await command('input.performActions', { context, actions: [{ type: 'key', id: 'acl-keyboard', actions: [{ type: 'keyDown', value: ' ' }, { type: 'keyUp', value: ' ' }] }] });
    assert.equal(await evaluate('document.querySelector("#windows_acl_managed").checked'), true);
    console.log('PASS: Windows ACL policy defaults off, exposes its warning, and supports keyboard opt-in');
    await evaluate('document.querySelector("[data-reveal]").click()');
    assert.equal(await evaluate('document.querySelector("#private_key_passphrase").type'), 'text');
    await evaluate('document.querySelector("[data-reveal]").click()');
    assert.equal(await evaluate('document.querySelector("#private_key_passphrase").type'), 'password');
    await evaluate('document.querySelector("#name").focus()');
    await command('input.performActions', { context, actions: [{ type: 'key', id: 'keyboard', actions: [{ type: 'keyDown', value: '\uE004' }, { type: 'keyUp', value: '\uE004' }] }] });
    assert.equal(await evaluate('document.activeElement.id'), 'host');
    console.log('PASS: Form renders; passphrase toggle and keyboard navigation work');
    await evaluate('document.querySelector("#name").value="Unsaved"; document.querySelector("#name").dispatchEvent(new Event("input", {bubbles:true})); document.querySelector(".form-actions a").click()');
    assert.equal(await evaluate('document.querySelector("dialog").open'), true);
    assert.equal(await evaluate('document.activeElement.hasAttribute("data-stay")'), true);
    await evaluate('document.querySelector("[data-stay]").click()');
    assert.equal(await evaluate('document.querySelector("dialog").open'), false);
    console.log('PASS: Unsaved changes open a dialog focused on keeping edits');
    const values = { name: `Browser database ${Date.now()}`, host: 'db01.internal', port: '22', username: 'backup_inbox', directory: '/incoming/backups', host_fingerprint: 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' };
    await evaluate(`for (const [name,value] of Object.entries(${JSON.stringify(values)})) { const input=document.getElementById(name); input.value=value; input.dispatchEvent(new Event("input",{bubbles:true})); }`);
    const node = await command('script.evaluate', { expression: 'document.querySelector("#private_key")', target: { context }, awaitPromise: false });
    await command('input.setFiles', { context, element: { sharedId: node.result.sharedId }, files: [keyPath] });
    await clickAndWait('[type=submit]', '/system/settings/sftp');
    assert.equal(await evaluate('document.body.textContent.includes("saved.")'), true);
    const editPath = await evaluate(`new URL(Array.from(document.querySelectorAll('tbody tr')).find(row=>row.textContent.includes(${JSON.stringify(values.name)})).querySelector('a').href).pathname`);
    await navigate(editPath);
    assert.equal(await evaluate('document.body.textContent.includes("A key is saved.")'), true);
    assert.equal(await evaluate('document.querySelector("#windows_acl_managed").checked'), true);
    await evaluate('document.querySelector("#windows_acl_managed").click()');
    await evaluate('document.querySelector("[name=enabled][type=checkbox]").click()');
    await clickAndWait('[type=submit]', '/system/settings/sftp');
    assert.equal(await evaluate(`Array.from(document.querySelectorAll('tbody tr')).find(row=>row.textContent.includes(${JSON.stringify(values.name)})).textContent.includes('Enabled')`), true);
    console.log('PASS: Browser uploads a key, creates a destination, edits it, and enables it');
    await navigate(editPath);
    assert.equal(await evaluate('document.querySelector("#windows_acl_managed").checked'), false);
    await evaluate('document.querySelector("[name=enabled][type=checkbox]").click()');
    await clickAndWait('[type=submit]', '/system/settings/sftp');
    await screenshot('list-desktop');
    await navigate(editPath);
    await evaluate('document.querySelector("#host").value="sftp://invalid"; document.querySelector("#private_key_passphrase").value="test-only-unsaved-secret";');
    await clickAndWait('[type=submit]', editPath);
    for (let attempt=0; attempt<30 && !await evaluate('document.body.textContent.includes("Destination not saved")'); attempt++) await new Promise(resolve=>setTimeout(resolve,100));
    assert.equal(await evaluate('document.querySelector("#host").getAttribute("aria-invalid")'), 'true');
    assert.equal(await evaluate('document.activeElement.id'), 'host');
    assert.equal(await evaluate('document.querySelector("#private_key_passphrase").value'), '');
    assert.equal(await evaluate('document.body.innerHTML.includes("test-only-unsaved-secret")'), false);
    console.log('PASS: Server validation focuses the invalid field and never repopulates credentials');
    await command('browsingContext.setViewport', { context, viewport: { width: 390, height: 844 } });
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true);
    await screenshot('form-mobile');
    await evaluate('document.documentElement.dataset.theme="dark"');
    await screenshot('form-mobile-dark');
    console.log('PASS: Narrow viewport has no page overflow; light and dark screenshots captured');
    const deletePath = editPath.replace(/\/edit$/, '/delete');
    await clickAndWait('.danger-link', deletePath);
    assert.equal(await evaluate('document.querySelector("h1").textContent'), 'Delete destination');
    assert.equal(await evaluate('document.body.textContent.includes("backup_inbox@db01.internal:22")'), true);
    assert.equal(await evaluate('document.body.textContent.includes('+JSON.stringify(values.name)+')'), true);
    assert.equal(await evaluate('document.activeElement.textContent.trim()'), 'Keep destination');
    await screenshot('delete-confirmation-mobile');
    await clickAndWait('[data-delete-form] a', editPath);
    assert.equal(await evaluate('document.body.textContent.includes("A key is saved.")'), true);
    await clickAndWait('.danger-link', deletePath);
    await clickAndWait('[data-delete-form] [type=submit]', '/system/settings/sftp');
    assert.equal(await evaluate('document.body.textContent.includes('+JSON.stringify(values.name)+')'), true);
    assert.equal(await evaluate('Array.from(document.querySelectorAll("tbody tr")).some(row => row.textContent.includes('+JSON.stringify(values.name)+'))'), false);
    console.log('PASS: Deletion names the target, focuses Keep destination, permits cancellation, and deletes only after confirmation');
} finally {
    rmSync(keyPath, { force: true });
    try { await command('session.end'); } finally { socket.close(); }
}
