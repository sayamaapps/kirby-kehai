// assets/kehai.js against a minimal fake DOM and fake timers (no
// dependencies): it fetches only this site's own route, waits the delay,
// shows at most N real entries, each once, with the real age; closing ends
// it for the page view; it never touches cookies or browser storage and
// never sets an inline style.
//   node --test tests/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/kehai.js', import.meta.url), 'utf8');
const NOW = Date.parse('2026-10-13T10:00:00Z');

function setup({ attributes = {}, answer, ok = true, clockOffset = 0, deferred = false } = {}) {
  let clock = NOW + clockOffset;
  const timers = [];
  const fetched = [];
  const touched = [];
  const shown = []; // every text that became visible
  let release = null; // with deferred: answers the pending fetch
  const style = new Proxy({}, { set() { touched.push('style'); return true; } });
  const make = (cls) => {
    const el = {
      className: cls, hidden: false, listeners: {}, classes: new Set(), style, _text: '',
      get textContent() { return el._text; },
      set textContent(value) { el._text = value; },
      classList: {
        add: (c) => { el.classes.add(c); if (c === 'kehai-open') { shown.push(line.textContent); } },
        remove: (c) => el.classes.delete(c),
        contains: (c) => el.classes.has(c),
      },
      addEventListener: (type, fn) => { el.listeners[type] = fn; },
      setAttribute: () => touched.push('setAttribute'),
    };
    return el;
  };
  const line = make('kehai-text');
  const close = make('kehai-close');
  close.hidden = true;
  const attrs = {
    'data-kehai-src': 'https://site.example/kehai/events.json?lang=en',
    'data-kehai-delay': '8', 'data-kehai-max': '3', 'data-kehai-duration': '6', 'data-kehai-gap': '10',
    ...attributes,
  };
  const box = make('kehai');
  box.getAttribute = (name) => (name in attrs ? attrs[name] : null);
  box.querySelector = (sel) => (sel === '.kehai-text' ? line : sel === '.kehai-close' ? close : null);
  const document = {
    querySelector: (sel) => (sel === '[data-kehai]' ? box : null),
    get cookie() { touched.push('cookie'); return ''; },
    set cookie(v) { touched.push('cookie'); },
  };
  const storage = new Proxy({}, { get() { touched.push('storage'); return () => null; } });
  const FakeDate = class extends Date {
    static now() { return clock; }
  };
  const window = {
    location: new URL('https://site.example/page'),
    setTimeout: (fn, ms) => { timers.push({ at: clock + ms, fn }); return timers.length; },
    clearTimeout: (id) => { if (timers[id - 1]) timers[id - 1].fn = null; },
    fetch: (url, options) => {
      fetched.push({ url, options });
      const response = { ok, status: ok ? 200 : 500, json: () => Promise.resolve(answer) };
      return deferred ? new Promise((resolve) => { release = () => resolve(response); }) : Promise.resolve(response);
    },
  };
  Object.defineProperty(window, 'localStorage', { get() { touched.push('storage'); return storage; } });
  Object.defineProperty(window, 'sessionStorage', { get() { touched.push('storage'); return storage; } });
  vm.runInNewContext(source, { window, document, URL, Date: FakeDate, Math, Array, String, parseInt, isNaN, Promise, Error });

  const settle = () => new Promise((resolve) => setImmediate(resolve));
  /** Advances the fake clock, running due timers in order (and letting promises settle). */
  const advance = async (seconds) => {
    const until = clock + seconds * 1000;
    for (;;) {
      await settle();
      const due = timers.filter((t) => t.fn && t.at <= until).sort((a, b) => a.at - b.at)[0];
      if (!due) break;
      clock = Math.max(clock, due.at);
      const fn = due.fn;
      due.fn = null;
      fn();
    }
    clock = until;
    await settle();
  };
  return { box, line, close, fetched, touched, shown, advance, release: () => release() };
}

const at = (secondsAgo) => new Date(NOW - secondsAgo * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z');
const texts = {
  now: 'just now', minute: '1 minute ago', minutes: '{count} minutes ago',
  hour: '1 hour ago', hours: '{count} hours ago', day: '1 day ago', days: '{count} days ago',
};
const answer = (events, now = '2026-10-13T10:00:00Z') => ({ now, texts, events });
const four = answer([
  { text: 'Someone from Austria booked “Jahreskurs”', at: at(2 * 3600) },
  { text: 'Someone booked “Jahreskurs”', at: at(30) },
  { text: 'Someone booked “Wochenendkurs”', at: at(26 * 3600) },
  { text: 'Someone booked “Jahreskurs”', at: at(5 * 86400) },
]);

test('waits the delay, then fetches its own route once', async () => {
  const { fetched, advance } = setup({ answer: four });
  await advance(7.9);
  assert.equal(fetched.length, 0, 'nothing before the delay');
  await advance(0.2);
  assert.equal(fetched.length, 1);
  assert.equal(fetched[0].url, 'https://site.example/kehai/events.json?lang=en');
  assert.equal(fetched[0].options.credentials, 'same-origin');
  await advance(600);
  assert.equal(fetched.length, 1, 'no second request, no polling');
});

test('shows at most max entries, each once, in order, with the real age', async () => {
  const { shown, box, line, close, advance } = setup({ answer: four });
  await advance(8);
  assert.equal(box.classList.contains('kehai-open'), true);
  assert.equal(close.hidden, false, 'close button visible while open');
  assert.equal(line.textContent, 'Someone from Austria booked “Jahreskurs” · 2 hours ago');
  await advance(6);
  assert.equal(box.classList.contains('kehai-open'), false, 'hidden after the duration');
  assert.equal(line.textContent, '', 'live region emptied');
  assert.equal(close.hidden, true);
  await advance(600);
  assert.deepEqual(shown, [
    'Someone from Austria booked “Jahreskurs” · 2 hours ago',
    'Someone booked “Jahreskurs” · just now', // 30 s old at load, shown 24 s later: 54 s
    'Someone booked “Wochenendkurs” · 1 day ago',
  ], 'three (data-kehai-max), never the fourth, never a repeat; ages measured when shown');
});

test('relative time from the real timestamp', async () => {
  const cases = [
    [10, 'just now'], [59, 'just now'], [60, '1 minute ago'], [119, '1 minute ago'], [45 * 60, '45 minutes ago'],
    [59 * 60 + 59, '59 minutes ago'], [3600, '1 hour ago'], [89 * 60, '1 hour ago'], [91 * 60, '2 hours ago'],
    [23 * 3600 + 20 * 60, '23 hours ago'], [24 * 3600, '1 day ago'], [36 * 3600 + 60, '2 days ago'], [13 * 86400, '13 days ago'],
  ];
  for (const [secondsAgo, expected] of cases) {
    // shown right at the delay: age = secondsAgo + 8 s delay
    const { line, advance } = setup({ answer: answer([{ text: 'X', at: at(secondsAgo - 8) }]) });
    await advance(8);
    assert.equal(line.textContent, 'X · ' + expected, `${secondsAgo} s`);
  }
});

test('a visitor clock that is days off does not change the age', async () => {
  for (const offset of [3 * 86400 * 1000, -5 * 3600 * 1000]) {
    const { line, advance } = setup({ answer: answer([{ text: 'X', at: at(2 * 3600) }], '2026-10-13T10:00:08Z'), clockOffset: offset });
    await advance(8);
    assert.equal(line.textContent, 'X · 2 hours ago', `visitor clock off by ${offset / 3600000} hours`);
  }
});

test('a visitor clock that is right is used as it is (cached answer)', async () => {
  // The answer may be up to a minute old (Cache-Control); the visitor's own clock is then the better "now".
  const { line, advance } = setup({ answer: answer([{ text: 'X', at: at(3600 - 8) }], '2026-10-13T09:59:00Z') });
  await advance(8);
  assert.equal(line.textContent, 'X · 1 hour ago');
});

test('skips invalid and future entries, never invents one', async () => {
  const { shown, advance } = setup({
    answer: answer([
      { text: '', at: at(60) }, { text: 'no time' }, { text: 'bad time', at: 'yesterday' },
      { text: 'future', at: at(-3600) }, { text: 42, at: at(60) }, null, { text: 'real', at: at(3600) },
    ]),
  });
  await advance(600);
  assert.deepEqual(shown, ['real · 1 hour ago']);
});

test('nothing to show: stays hidden', async () => {
  for (const setupWith of [{ answer: answer([]) }, { answer: four, ok: false }, { answer: null }, { answer: { events: 'x' } }]) {
    const { shown, box, advance } = setup(setupWith);
    await advance(600);
    assert.deepEqual(shown, []);
    assert.equal(box.classList.contains('kehai-open'), false);
  }
});

test('closing ends it for this page view', async () => {
  const { shown, box, close, advance } = setup({ answer: four });
  await advance(9);
  close.listeners.click();
  assert.equal(box.classList.contains('kehai-open'), false);
  assert.equal(close.hidden, true);
  await advance(600);
  assert.equal(shown.length, 1, 'no further entry after closing');
});

test('closing while the answer is on its way: nothing is shown', async () => {
  const { shown, close, advance, release, fetched } = setup({ answer: four, deferred: true });
  await advance(8);
  assert.equal(fetched.length, 1, 'request sent');
  close.listeners.click();
  release();
  await advance(600);
  assert.deepEqual(shown, []);
});

test('closing before the delay: no request at all', async () => {
  const { fetched, close, advance } = setup({ answer: four });
  close.listeners.click();
  await advance(600);
  assert.equal(fetched.length, 0);
});

for (const [name, src] of [
  ['foreign host', 'https://evil.example/kehai/events.json'],
  ['other path', 'https://site.example/api/users.json'],
  ['javascript:', 'javascript:alert(1)'],
  ['data:', 'data:application/json,{}'],
  ['protocol-relative foreign host', '//evil.example/kehai/events.json'],
]) {
  test(`refuses a ${name} address`, async () => {
    const { fetched, advance } = setup({ answer: four, attributes: { 'data-kehai-src': src } });
    await advance(600);
    assert.equal(fetched.length, 0);
  });
}

test('works under a subfolder', async () => {
  const { fetched, advance } = setup({ answer: four, attributes: { 'data-kehai-src': 'https://site.example/sub/kehai/events.json' } });
  await advance(8);
  assert.equal(fetched.length, 1);
});

test('settings from data attributes, clamped', async () => {
  const { fetched, shown, advance } = setup({ answer: four, attributes: { 'data-kehai-delay': '2', 'data-kehai-max': '1', 'data-kehai-duration': '1' } });
  await advance(2);
  assert.equal(fetched.length, 1, 'delay 2');
  await advance(1.9);
  assert.equal(shown.length, 1, 'duration is at least 2 seconds');
  await advance(600);
  assert.equal(shown.length, 1, 'max 1');
});

test('no cookie, no storage, no inline style', async () => {
  const { touched, advance, close } = setup({ answer: four });
  await advance(30);
  close.listeners.click();
  await advance(600);
  assert.deepEqual(touched, []);
});
