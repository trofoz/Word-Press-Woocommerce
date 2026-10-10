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

  let filledCard = false;
  let diagnostic = [];
  const deadline = Date.now() + 30000;
  while (!filledCard && Date.now() < deadline) {
    diagnostic = [];
    for (const frame of page.frames()) {
      const fields = frame.locator('input:visible');
      const count = await fields.count().catch(() => 0);
      const descriptors = [];
      for (let index = 0; index < count; index += 1) {
        const field = fields.nth(index);
        const info = await field.evaluate((element) => {
          const label = element.labels ? [...element.labels].map((item) => item.innerText).join(' ') : '';
          return {
            descriptor: [element.type, element.name, element.id, element.placeholder, element.autocomplete, element.getAttribute('aria-label'), label]
              .filter(Boolean).join(' ').toLowerCase(),
            safe: [element.type, element.name, element.id, element.placeholder, element.getAttribute('aria-label')]
              .filter(Boolean).join('|').slice(0, 160),
          };
        });
        descriptors.push({ field, ...info });
      }

      const find = (pattern) => descriptors.find(({ descriptor }) => pattern.test(descriptor))?.field;
      const card = find(/(^|[^\w])card($|[^\w])|card.?number|cardnumber|cardpan|cc.?number|(^|[^\w])pan($|[^\w])|карт|номер.*карт/);
      const month = find(/exp.?month|expiry.?month|cc.?exp.?month|місяц.*закін|month/);
      const year = find(/exp.?year|expiry.?year|cc.?exp.?year|рік.*закін|year/);
      const expiry = find(/expiry|expiration|validity|cc.?exp|термін дії|срок действия/);
      const cvv = find(/cvv|cvc|secure|cc.?csc|security.?code|код безпеки|код безопасности/);
      diagnostic.push({
        frame_origin: (() => { try { return new URL(frame.url()).origin; } catch { return '<opaque>'; } })(),
        visible_inputs: descriptors.map(({ safe }) => safe),
        visible_buttons: await frame.locator('button:visible').allTextContents().catch(() => []),
      });
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
    if (!filledCard) await page.waitForTimeout(1000);
  }

  if (!filledCard) {
    console.log(`SAFE_PAYMENT_FORM_DIAGNOSTIC ${JSON.stringify(diagnostic)}`);
    throw new Error('Could not identify the sandbox card form on the hosted payment page.');
  }
  const returnOrigin = new URL(process.env.WFPMS_TUNNEL_URL).origin;
  const browserReturned = await page.waitForURL((url) => url.origin === returnOrigin, { timeout: 20000 })
    .then(() => true)
    .catch(() => false);
  const resultText = await page.locator('[role="alert"], [aria-live], h1, h2').allTextContents().catch(() => []);
  const safeResultText = resultText
    .map((text) => text.replace(/\d/g, '#').replace(/https?:\/\/\S+/gi, '<URL>').slice(0, 120))
    .filter((text) => /success|fail|error|declin|approv|відхилен|успіш|помил|оплач|refus/i.test(text));
  console.log(`PASS: submitted one published test card; browser_return=${browserReturned}; result_labels=${JSON.stringify(safeResultText)}.`);
} finally {
  await browser.close();
}
