'use strict';

const {chromium, firefox, webkit} = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const manifest = JSON.parse(fs.readFileSync('/app/.runtime/deployment-lab/fixture.json', 'utf8'));
const label = process.argv[2];
const phase = process.argv[3];
const phases = ['local-before', 'sso', 'sso-outage', 'sso-recovery', 'local-after'];
if (!/^[a-z0-9-]+$/.test(label || '') || !phases.includes(phase)) throw new Error('Invalid bounded mode case');
const report = {started_at_utc: new Date().toISOString(), label, phase, https_errors_ignored: false,
  script_sha256: crypto.createHash('sha256').update(fs.readFileSync(__filename)).digest('hex'),
  playwright: require('playwright/package.json').version, tests: [], failed: 0};
const stateFile = (engine, profile) => `/results/${label}-${engine}-${profile}-private-state.json`;
async function status(context, url, expected, options = {}) {
  const response = await context.request.get(url, {maxRedirects: 0, ...options});
  assert.equal(response.status(), expected, `GET ${new URL(url).pathname}`);
  return response;
}
async function protectedMe(context, origin, expected) {
  return status(context, `${origin}/me`, expected, {headers: {Accept: 'application/json'}});
}
async function nativeLogin(page, origin, user) {
  assert.equal((await page.goto(`${origin}/login`)).status(), 200);
  await page.getByLabel('Correo').fill(user.email);
  await page.getByLabel('Contraseña', {exact: true}).fill(user.password);
  await page.getByRole('button', {name: 'Entrar', exact: true}).click();
  await page.waitForURL(`${origin}/me`);
}
(async () => {
  let index = 0;
  for (const [engineName, engine] of Object.entries({chromium, firefox, webkit})) {
    for (const [profileName, profile] of Object.entries(manifest.profiles)) {
      const result = {engine: engineName, profile: profileName, checks: [], passed: false};
      let browser, context;
      try {
        const user = profile.users[12 + index];
        browser = await engine.launch(engineName === 'firefox' ? {headless: true,
          firefoxUserPrefs: {'network.cookie.cookieBehavior': 1, 'network.proxy.type': 0,
            'network.trr.mode': 5, 'security.enterprise_roots.enabled': true}} : {headless: true});
        result.version = browser.version();
        context = await browser.newContext({ignoreHTTPSErrors: false,
          ...(phase === 'local-before' ? {} : {storageState: stateFile(engineName, profileName)})});
        const page = await context.newPage();
        page.setDefaultTimeout(20000);
        let localCredentials = 0, centralCredentials = 0, opRequests = 0;
        context.on('request', request => {
          if (request.url().startsWith(profile.op + '/')) opRequests++;
          if (request.method() === 'POST' && request.url() === profile.op + '/login') centralCredentials++;
          if (request.method() === 'POST' && ['one', 'two'].some(role => request.url() === profile[role] + '/login')) localCredentials++;
        });
        for (const role of ['one', 'two']) {
          const health = await (await status(context, profile[role] + '/lab/health', 200)).json();
          assert.equal(health.configuration_cached, true);
          assert.equal(health.routes_cached, true);
          assert.equal(health.opcache, true);
          assert.equal(health.sso_enabled, phase.startsWith('sso'));
        }
        result.checks.push('real_config_route_caches_and_opcache_both_rps');
        if (phase.startsWith('local')) {
          await status(context, profile.op + '/.well-known/openid-configuration', 503);
          opRequests = 0;
          for (const role of ['one', 'two']) {
            await protectedMe(context, profile[role], 401);
            await status(context, profile[role] + '/sso/login', 404);
            await status(context, profile[role] + '/sso/callback', 404);
            const missingCsrf = await context.request.post(profile[role] + '/login', {form: {email: user.email, password: user.password}});
            assert.equal(missingCsrf.status(), 419);
            await nativeLogin(page, profile[role], user);
            const me = await (await protectedMe(context, profile[role], 200)).json();
            assert.equal(String(me.local_id), user.local_ids[role]);
            assert.equal(me.issuer, null);
            assert.equal(me.subject, null);
            assert.equal(me.can_view_reports, role === 'one');
          }
          assert.equal(centralCredentials, 0);
          assert.equal(localCredentials, 2);
          assert.equal(opRequests, 0);
          result.checks.push('both_rps_require_fresh_local_login', 'sso_endpoints_404', 'native_login_csrf_enforced',
            'local_credentials_work_with_op_down', 'no_browser_op_contact_during_local_auth', 'same_local_ids_and_independent_acl');
          if (phase === 'local-after') result.checks.push('sso_sessions_rejected_after_disable');
        } else if (phase === 'sso-outage') {
          await status(context, profile.op + '/.well-known/openid-configuration', 503);
          for (const role of ['one', 'two']) {
            await protectedMe(context, profile[role], 401);
            const native = await status(context, profile[role] + '/login', 302);
            assert.equal(native.headers().location, profile[role] + '/sso/login');
          }
          result.checks.push('op_outage_denies_both_authenticated_rps', 'no_automatic_local_fallback');
        } else {
          for (const role of ['one', 'two']) {
            await protectedMe(context, profile[role], 401);
            const native = await status(context, profile[role] + '/login', 302);
            assert.equal(native.headers().location, profile[role] + '/sso/login');
            await page.goto(profile[role] + '/sso/login');
            if (role === 'one' && phase === 'sso') {
              await page.waitForURL(profile.op + '/login');
              await page.getByLabel('Correo').fill(user.email);
              await page.getByLabel('Contraseña', {exact: true}).fill(user.password);
              await page.getByRole('button', {name: 'Entrar', exact: true}).click();
            }
            await page.waitForURL(profile[role] + '/');
            const me = await (await protectedMe(context, profile[role], 200)).json();
            assert.equal(String(me.local_id), user.local_ids[role]);
            assert.equal(me.issuer, profile.op);
            assert.equal(me.subject, user.subject);
            assert.equal(me.can_view_reports, role === 'one');
            // A real same-origin CSRF token ensures a 403 comes from the mode middleware.
            const token = await page.locator('input[name="_token"]').first().inputValue();
            const blocked = await context.request.post(profile[role] + '/login', {form: {_token: token,
              email: user.email, password: user.password}, headers: {Accept: 'application/json'}});
            assert.equal(blocked.status(), 403);
          }
          assert.equal(centralCredentials, phase === 'sso' ? 1 : 0);
          result.checks.push('old_sessions_rejected', 'native_login_redirected_and_password_post_403',
            'same_explicitly_linked_accounts_and_acl', 'one_central_login_for_two_rps');
          if (phase === 'sso-recovery') result.checks.push('sso_recovers_without_credential_resubmission');
        }
        await context.storageState({path: stateFile(engineName, profileName)});
        result.passed = true;
        console.log(`${phase}/${engineName}/${profileName}: ${result.checks.length} checks passed`);
      } catch (error) {
        result.error_type = error.name;
        result.failed_after_checks = result.checks.length;
        if (typeof error.actual === 'number' || typeof error.actual === 'boolean') result.actual = error.actual;
        // Never serialize a browser error: it may contain callback URLs, passwords or tokens.
        report.failed++;
        console.log(`${phase}/${engineName}/${profileName}: FAILED (${error.name})`);
      } finally {
        if (context) await context.close();
        if (browser) await browser.close();
      }
      report.tests.push(result);
      fs.writeFileSync(`/results/${label}-${phase}.json`, JSON.stringify(report, null, 2));
    }
    index++;
  }
  report.finished_at_utc = new Date().toISOString();
  fs.writeFileSync(`/results/${label}-${phase}.json`, JSON.stringify(report, null, 2));
  process.exitCode = report.failed ? 1 : 0;
})().catch(error => { console.error(error.name); process.exitCode = 1; });
