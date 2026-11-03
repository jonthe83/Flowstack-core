import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

async function noHorizontalScroll(page) {
  const box = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    cw: document.documentElement.clientWidth,
  }));
  expect(box.sw).toBeLessThanOrEqual(box.cw + 1);
}

async function matchesBaseline(page, name) {
  const expected = fs.readFileSync(path.join(here, 'baselines', name + '.txt'), 'utf8').trim();
  const actual = (await page.locator('body').innerText())
    .replace(/\u00a0/g, ' ')
    .split('\n')
    .map((line) => line.replace(/[ \t]+/g, ' ').trim())
    .filter((line) => line !== '')
    .join('\n');
  expect(actual).toBe(expected);
}

for (const [name, url, title] of [
  ['flow1', '/flow/1', 'Påminnelser'],
  ['flow2', '/flow/2', 'Inkorg'],
  ['flow3', '/flow/3', 'Ordrar'],
]) {
  test(name + ' ritas, är utf-8 och scrollar inte i sidled', async ({ page }) => {
    const response = await page.goto(url);
    expect(response.status()).toBe(200);
    const charset = await page.evaluate(() => document.characterSet);
    expect(charset.toLowerCase()).toBe('utf-8');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
    if (name === 'flow3') {
      await expect(page.getByRole('cell', { name: 'Åsa Öberg' })).toBeVisible();
      await expect(page.getByRole('cell', { name: 'Malmö' })).toBeVisible();
      await expect(page.getByRole('cell', { name: 'Batteri' })).toBeVisible();
    }
    await matchesBaseline(page, name);
    await noHorizontalScroll(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await noHorizontalScroll(page);
  });
}

test('flöde 2 har tre rutor', async ({ page }) => {
  await page.goto('/flow/2');
  await expect(page.getByRole('complementary', { name: 'Lista' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Ärende' })).toBeVisible();
  await expect(page.getByRole('complementary', { name: 'Kund' })).toBeVisible();
});

test('dubbelklick skickar formuläret en gång', async ({ page }) => {
  let posts = 0;
  await page.route('**/save', async (route) => {
    posts += 1;
    await new Promise((resolve) => setTimeout(resolve, 400));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: '{"ok":true}',
    });
  });
  await page.goto('/flow/double');
  const button = page.getByRole('button', { name: 'Vunnen' });
  await button.click();
  await page.waitForFunction(() => document.querySelector('form[data-js="vunnen"]')?.getAttribute('data-busy') === '1');
  await button.click({ force: true });
  await page.evaluate(() => {
    document.querySelector('form[data-js="vunnen"]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await page.waitForFunction(() => document.querySelector('form[data-js="vunnen"]')?.getAttribute('data-busy') !== '1');
  expect(posts).toBe(1);
});

test('ägarkort i Ändra håller fälten inne på smal bredd', async ({ page }) => {
  await page.goto('/flow/owner');
  await expect(page.getByRole('heading', { name: 'Bekräfta' })).toBeVisible();
  const staffDir = await page.locator('.v3-hright > .v3-owner').evaluate((el) => getComputedStyle(el).flexDirection);
  expect(staffDir).toBe('row');
  for (const width of [1100, 390, 320]) {
    await page.setViewportSize({ width, height: 900 });
    const report = await page.evaluate(() => {
      const cards = [...document.querySelectorAll('.v3-owners > .v3-owner')];
      return cards.map((card) => {
        const cr = card.getBoundingClientRect();
        const dir = getComputedStyle(card).flexDirection;
        const spilled = [...card.querySelectorAll('.v3-kv, .v3-kv *, header, .v3-pillsw button, input')].filter((el) => {
          const r = el.getBoundingClientRect();
          if (r.width < 1 || r.height < 1) {
            return false;
          }
          return r.right > cr.right + 1.5 || r.left < cr.left - 1.5;
        }).map((el) => (el.getAttribute('data-field') || el.className || el.tagName).toString());
        const squeezed = [...card.querySelectorAll('.v3-pillsw button')].filter((btn) => btn.clientWidth + 1 < btn.scrollWidth).map((btn) => btn.textContent || '');
        const name = card.querySelector('[data-field="name"]');
        const nameBox = name ? name.getBoundingClientRect() : { width: 0 };
        return {
          dir,
          spilled,
          squeezed,
          nameWidth: nameBox.width,
          scroll: card.scrollWidth,
          client: card.clientWidth,
        };
      });
    });
    expect(report.length).toBe(2);
    for (const card of report) {
      expect(card.dir, `bredd ${width}`).toBe('column');
      expect(card.spilled, `bredd ${width}`).toEqual([]);
      expect(card.squeezed, `bredd ${width}`).toEqual([]);
      expect(card.scroll, `bredd ${width}`).toBeLessThanOrEqual(card.client + 1);
      expect(card.nameWidth, `bredd ${width}`).toBeGreaterThan(80);
    }
  }
});

test('skärmdumpen matchar baslinjen när pixeljämförelse är på', async ({ page }) => {
  await page.goto('/flow/shot');
  await expect(page.getByRole('heading', { name: 'JEAB' })).toBeVisible();
  await expect(page.getByText('Åsa Öberg i Malmö')).toBeVisible();
  if (process.env.JEAB_PIXEL_SHOTS === '1') {
    await expect(page).toHaveScreenshot('flow-shot.png', { fullPage: true });
  } else {
    const png = await page.screenshot({ fullPage: true });
    expect(png.length).toBeGreaterThan(1000);
    expect(png.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
  }
});
