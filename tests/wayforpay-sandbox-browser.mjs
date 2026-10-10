import fs from 'node:fs';
import { chromium } from 'playwright';

const inputPath = process.env.WFPMS_SANDBOX_INPUT;
if (!inputPath) throw new Error('Sandbox browser handoff file is missing.');
const payment = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
if (typeof payment.redirect_url !== 'string') {
  throw new Error('Sandbox browser handoff is invalid.');
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
try {
  await page.goto(payment.redirect_url, { waitUntil: 'domcontentloaded', timeout: 90000 });
  if (!new URL(page.url()).hostname.endsWith('wayforpay.com')) {
    throw new Error('The browser did not reach the WayForPay hosted payment page.');
  }
  await page.waitForTimeout(5000);

  let filledCard = false;
  for (const frame of page.frames()) {
    const fields = frame.locator('input:visible');
    const count = await fields.count().catch(() => 0);
    const descriptors = [];
    for (let index = 0; index < count; index += 1) {
      const field = fields.nth(index);
      const descriptor = await field.evaluate((element) => {
        const label = element.labels ? [...element.labels].map((item) => item.innerText).join(' ') : '';
        return [element.name, element.id, element.placeholder, element.autocomplete, element.getAttribute('aria-label'), label]
          .filter(Boolean).join(' ').toLowerCase();
      });
      descriptors.push({ field, descriptor });
    }

    const find = (pattern) => descriptors.find(({ descriptor }) => pattern.test(descriptor))?.field;
    const card = find(/(^|[^\w])card($|[^\w])|card.?number|cardpan|(^|[^\w])pan($|[^\w])|карт|номер.*карт/);
    const month = find(/exp.?month|expiry.?month|місяц.*закін|month/);
    const year = find(/exp.?year|expiry.?year|рік.*закін|year/);
    const expiry = find(/expiry|expiration|термін дії|срок действия/);
    const cvv = find(/cvv|cvc|security.?code|код безпеки|код безопасности/);
    if (!card || !cvv || (!expiry && (!month || !year))) continue;

    await card.fill('4111111111111111');
    if (expiry) await expiry.fill('12/30');
    else {
      await month.fill('12');
      await year.fill('2030');
    }
    await cvv.fill('123');
    const holder = find(/card.?holder|card.?name|ім.?я власника|имя владельца/);
    if (holder) await holder.fill('SANDBOX TEST');

    const submit = frame.locator('button[type="submit"]:visible, input[type="submit"]:visible').first();
    if (await submit.count()) await submit.click({ timeout: 15000 });
    else await frame.locator('form').first().evaluate((form) => form.requestSubmit());
    filledCard = true;
    break;
  }

  if (!filledCard) throw new Error('Could not identify the sandbox card form on the hosted payment page.');
  const returnOrigin = new URL(payment.redirect_url).origin;
  await page.waitForURL((url) => url.origin === returnOrigin, { timeout: 20000 }).catch(() => {});
  console.log('PASS: submitted one published test card on the WayForPay hosted sandbox page.');
} finally {
  await browser.close();
}
