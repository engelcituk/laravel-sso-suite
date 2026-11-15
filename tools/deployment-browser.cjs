'use strict';

const {chromium, firefox, webkit} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const manifest = JSON.parse(fs.readFileSync('/app/.runtime/deployment-lab/fixture.json', 'utf8'));
const label = process.argv[2] || 'evaluation';
if (!/^[a-z0-9-]+$/.test(label)) throw new Error('Invalid label');
const report = {started_at_utc: new Date().toISOString(), playwright: require('playwright/package.json').version,
  browser_script_sha256: require('node:crypto').createHash('sha256').update(fs.readFileSync(__filename)).digest('hex'),
  https_errors_ignored: false, tests: [], failed: 0};

async function navigate(page, url, expected = 200) {
  const response = await page.goto(url);
  assert.equal(response.status(), expected, `Navigation status at ${new URL(url).pathname}`);
  return response;
}
async function me(page, origin) {
  const response = await navigate(page, `${origin}/me`);
  return response.json();
}
async function enter(page, origin) {
  await navigate(page, origin);
  await page.locator('a[href$="/sso/login"]').click();
}

(async () => {
  let index = 0;
  for (const [engineName, engine] of Object.entries({chromium, firefox, webkit})) {
    for (const [profileName, profile] of Object.entries(manifest.profiles)) {
      const result = {engine: engineName, profile: profileName, checks: [], passed: false};
      let browser, context;
      try {
        if (engineName === 'firefox') {
          browser = await engine.launch({headless: true,
            firefoxUserPrefs: {'network.cookie.cookieBehavior': 1, 'network.proxy.type': 0, 'network.trr.mode': 5,
              'security.enterprise_roots.enabled': true}});
          context = await browser.newContext({ignoreHTTPSErrors: false});
        } else {
          browser = await engine.launch({headless: true});
          context = await browser.newContext({ignoreHTTPSErrors: false});
        }
        result.version = browser.version();
        const page = await context.newPage();
        page.setDefaultTimeout(20000);
        let credentials = 0;
        const crossSites = [];
        result.responses = [];
        page.on('response', response => {
          const url = new URL(response.url());
          if (url.protocol === 'https:') {
            result.responses.push({host: url.hostname, path: url.pathname.replace(/[a-f0-9]{32,}/g, '[id]'), status: response.status()});
            if (result.responses.length > 20) result.responses.shift();
          }
        });
        page.on('request', async request => {
          if (request.url() === `${profile.op}/login` && request.method() === 'POST') credentials++;
          if (request.url().startsWith(`${profile.op}/oidc/authorize`)) {
            try { crossSites.push((await request.allHeaders())['sec-fetch-site']); } catch { /* Page can close after a completed case. */ }
          }
        });
        const user = profile.users[8 + index];
        result.phase = 'first_login';
        await enter(page, profile.one);
        await page.waitForURL(`${profile.op}/login`);
        await page.getByLabel('Correo').fill(user.email);
        await page.getByLabel('Contraseña', {exact: true}).fill(user.password);
        await page.getByLabel('Recordarme en este dispositivo').check();
        await page.getByRole('button', {name: 'Entrar', exact: true}).click();
        await page.waitForURL(`${profile.one}/`);
        result.phase = 'first_identity';
        const first = await me(page, profile.one);
        assert.equal(first.can_view_reports, false);
        await navigate(page, `${profile.one}/reports`, 403);
        result.phase = 'second_login';
        await enter(page, profile.two);
        await page.waitForURL(`${profile.two}/`);
        const second = await me(page, profile.two);
        assert.equal(second.subject, first.subject);
        assert.equal(second.issuer, profile.op);
        assert.equal(credentials, 1, 'Exactly one credential submission');
        result.cross_site_headers_seen = crossSites;
        const sites = ['op', 'one', 'two'].map(role => new URL(profile[role]).hostname.split('.').slice(-2).join('.'));
        assert.equal(new Set(sites).size, 3, 'Three distinct registrable sites are required');
        result.registrable_sites = sites;
        result.checks.push('real_cross_site_top_level_sso', 'one_credential_submission_two_rps', 'independent_acl_denied');
        const cookies = (await context.cookies()).filter(cookie => cookie.name.endsWith('_session') || cookie.name === '__Host-sso_remember');
        assert.equal(cookies.length, 4);
        for (const cookie of cookies) {
          assert.equal(cookie.secure, true);
          assert.equal(cookie.httpOnly, true);
          assert.equal(cookie.sameSite, 'Lax');
          assert.ok(!cookie.domain.startsWith('.'));
        }
        result.cookie_attributes = cookies.map(({name, domain, secure, httpOnly, sameSite}) => ({name, domain, secure, httpOnly, sameSite}));
        result.checks.push('secure_host_only_lax_cookies');

        await navigate(page, `${profile.one}/lab/frame`);
        const frame = page.frameLocator('iframe');
        const body = await frame.locator('body').textContent();
        const probe = JSON.parse(body);
        assert.equal(probe.authenticated, false);
        result.iframe_fetch_site = probe.fetch_site;
        if (probe.fetch_site !== null) assert.equal(probe.fetch_site, 'cross-site');
        const top = await navigate(page, `${profile.op}/lab/cookie-probe`);
        assert.equal((await top.json()).authenticated, true);
        result.checks.push('iframe_get_does_not_authenticate', 'top_level_op_still_authenticated');

        await navigate(page, `${profile.one}/lab/attack`);
        const negativeResponse = page.waitForResponse(response => response.url() === `${profile.op}/sso/devices/revoke-all`);
        await page.evaluate(target => {
          const form = document.createElement('form'); form.method = 'POST'; form.action = target;
          document.body.appendChild(form); form.submit();
        }, `${profile.op}/sso/devices/revoke-all`);
        assert.equal((await negativeResponse).status(), 419);
        await page.waitForURL(`${profile.op}/sso/devices/revoke-all`);
        await page.waitForLoadState('load');
        await me(page, profile.two);
        result.checks.push('cross_site_csrf_rejected_session_not_revoked');

        await context.clearCookies({name: `${profileName}_op_session`});
        await enter(page, profile.one);
        await page.waitForURL(`${profile.one}/`);
        assert.equal(credentials, 1);
        result.checks.push('remember_restore_without_new_credentials');
        await navigate(page, `${profile.op}/sso/devices`);
        await page.getByLabel('Contraseña actual').fill(user.password);
        await page.getByRole('button', {name: 'Confirmar contraseña', exact: true}).click();
        await page.getByLabel('Nombre del dispositivo').first().fill('Browser laboratory');
        await page.getByRole('button', {name: /^Guardar nombre/}).first().click();
        assert.ok((await page.locator('body').textContent()).includes('Browser laboratory'));
        await page.screenshot({path: `/results/${label}-${engineName}-${profileName}.png`, fullPage: true});
        result.checks.push('recent_password_device_management');

        await navigate(page, profile.one);
        await page.getByRole('button', {name: 'Cerrar sesión global', exact: true}).click();
        await page.waitForURL(`${profile.op}/oidc/logout?**`);
        await page.locator('button[name="approve"][value="1"]').click();
        assert.ok((await page.locator('body').textContent()).includes('Sesión global cerrada'));
        await navigate(page, profile.two);
        const deniedStatus = await page.evaluate(async () => (await fetch('/me', {headers: {Accept: 'application/json'}})).status);
        assert.equal(deniedStatus, 401);
        result.checks.push('global_logout_other_rp_denied');
        result.credentials_submissions = credentials;
        result.passed = true;
        console.log(`${engineName}/${profileName}: ${result.checks.length} browser checks passed`);
      } catch (error) {
        result.error_type = error.name; // Never export URLs containing codes, hints or cookie values.
        result.error_message = error instanceof assert.AssertionError ? `assertion failed at check ${result.checks.length}` : `browser operation failed at check ${result.checks.length}`;
        result.error_hint = error.message.split('\n')[0].replace(/https?:\/\/[^\s'"]+/g, '<url>').replace(/[A-Za-z0-9_-]{48,}/g, '<redacted>');
        if (typeof error.actual === 'number') result.actual_status = error.actual;
        report.failed++;
        console.log(`${engineName}/${profileName}: FAILED after ${result.checks.length} checks (${error.name})`);
      } finally {
        if (context) await context.close();
        if (browser && browser.isConnected()) await browser.close();
      }
      report.tests.push(result);
      fs.writeFileSync(`/results/${label}-browsers.json`, JSON.stringify(report, null, 2));
    }
    index++;
  }
  report.finished_at_utc = new Date().toISOString();
  fs.writeFileSync(`/results/${label}-browsers.json`, JSON.stringify(report, null, 2));
  process.exitCode = report.failed ? 1 : 0;
})().catch(error => {console.error(error.name); process.exitCode = 1;});
