// @ts-check
const { test, expect } = require('@playwright/test');
const mysql = require('mysql2/promise');

const RUN_ID = Math.random().toString(16).slice(2, 10);
const ORG_EMAIL = `e2e_pw_org_${RUN_ID}@example.com`;
const CUST1_EMAIL = `e2e_pw_c1_${RUN_ID}@example.com`;
const CUST2_EMAIL = `e2e_pw_c2_${RUN_ID}@example.com`;
const EVENT_TITLE = `PW Grand Saloon ${RUN_ID}`;
const EVENT_LOCATION = 'Carrer de Colon 15, 46004 Valencia, Spain';

async function getDbConnection() {
  return mysql.createConnection({
    host: process.env.DB_HOST || 'database',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'ticket_user',
    password: process.env.DB_PASSWORD || 'ticket_password',
    database: process.env.DB_NAME || 'ticketing_db',
  });
}

async function registerCustomer(page, name, email) {
  await page.goto('/account/register');
  await page.locator('input[name="role"][value="customer"]').check();
  await page.fill('#name', name);
  await page.fill('#email', email);
  await page.fill('#password', 'secret123');
  await page.fill('#password_confirm', 'secret123');
  await page.click('#register-submit-btn');
  await expect(page).toHaveURL(/\/account$/);
}

test.describe.serial('SwiftDesk Ticketing Full Browser E2E', () => {
  /** @type {number} */
  let createdEventId = 0;

  test.afterAll(async () => {
    const conn = await getDbConnection();
    try {
      await conn.execute('DELETE FROM tickets WHERE contact LIKE ?', [`e2e_pw_%_${RUN_ID}@example.com`]);
      await conn.execute('DELETE FROM events WHERE title = ?', [EVENT_TITLE]);
      await conn.execute('DELETE FROM customers WHERE email LIKE ?', [`e2e_pw_%_${RUN_ID}@example.com`]);
    } finally {
      await conn.end();
    }
  });

  test('Organizer registers with password 1111, creates seated event with location, and rearranges seats', async ({ page }) => {
    // 0. Top bar Account Icon opens submenu with Sign In, Register Account & Admin Login
    await page.goto('/');
    const accountTrigger = page.locator('[data-account-trigger]');
    const accountPopover = page.locator('[data-account-popover]');
    await expect(accountTrigger).toBeVisible();
    await expect(accountPopover).toBeHidden();
    await accountTrigger.click();
    await expect(accountPopover).toBeVisible();
    await expect(accountPopover).toContainText('Sign In');
    await expect(accountPopover).toContainText('Register Account');
    await expect(accountPopover).toContainText('Admin Login');

    // 1. Open Register Customer Account page via the Account submenu
    await accountPopover.locator('a:has-text("Register Account")').click();
    await expect(page).toHaveURL(/\/account\/register$/);
    await expect(page.locator('h1')).toContainText('Register Customer Account');

    // Organizer password box starts hidden for Customer
    const orgPwdGroup = page.locator('#organizer-password-group');
    await expect(orgPwdGroup).toBeHidden();

    // Selecting Organizer reveals Organizer Password field
    await page.locator('input[name="role"][value="organizer"]').check();
    await expect(orgPwdGroup).toBeVisible();

    // Wrong organizer password (9999) is rejected
    await page.fill('#organizer_password', '9999');
    await page.fill('#name', 'Playwright Organizer');
    await page.fill('#email', ORG_EMAIL);
    await page.fill('#password', 'secret123');
    await page.fill('#password_confirm', 'secret123');
    await page.click('#register-submit-btn');
    await expect(page.locator('.alert-error')).toContainText('Invalid Organizer password');

    // Correct organizer password (1111) succeeds and redirects to /organizer/events/new
    await page.fill('#organizer_password', '1111');
    await page.fill('#password', 'secret123');
    await page.fill('#password_confirm', 'secret123');
    await page.click('#register-submit-btn');
    await expect(page).toHaveURL(/\/organizer\/events\/new$/);

    // 2. Create a Saloon / Hall event with Location and 4x4 Seat Map
    await page.fill('input[name="event[title]"]', EVENT_TITLE);
    await page.selectOption('select[name="event[category]"]', 'saloon');
    await page.fill('input[name="event[venue]"]', 'Playwright Saloon Hall');
    await page.fill('input[name="event[location]"]', EVENT_LOCATION);
    await page.fill('input[name="event[seatRows]"]', '4');
    await page.fill('input[name="event[seatsPerRow]"]', '4');
    // Trigger preset rebuild cleanly
    await page.locator('#form-seat-editor button[data-action="apply-preset"]').click();
    await expect(page.locator('#seat-builder-total-pill')).toContainText('16 Mapped Seats');
    await page.fill('textarea[name="event[description]"]', 'Real browser Playwright test event.');

    await page.click('button:has-text("Publish New Event")');
    await expect(page).toHaveURL(/\/account$/);
    await expect(page.locator('body')).toContainText(EVENT_TITLE);
    await expect(page.locator('body')).toContainText(EVENT_LOCATION);

    // Extract created event ID from Arrange Seats link
    const arrangeHref = await page.locator('a:has-text("Arrange Seats")').first().getAttribute('href');
    expect(arrangeHref).toMatch(/\/organizer\/events\/(\d+)\/seats/);
    createdEventId = Number(arrangeHref.match(/\/organizer\/events\/(\d+)\/seats/)[1]);

    // 3. Open Arrange Seat Positions editor, move Stage to LEFT, and move a seat to an empty aisle slot
    await page.goto(`/organizer/events/${createdEventId}/seats`);
    await expect(page.locator('h1')).toContainText('Arrange Seat Positions');

    // Move Stage to Left
    await page.locator('#organizer-seat-editor button[data-stage-pos="left"]').click();
    await expect(page.locator('#organizer-seat-editor .seat-map-stage-side')).toBeVisible();

    // Move seat A1 onto the empty aisle slot in row 0, col 2 using click-to-move
    await page.locator('#organizer-seat-editor button[data-r="0"][data-c="0"]').click();
    await expect(page.locator('#organizer-seat-editor button[data-r="0"][data-c="0"]')).toHaveClass(/is-move-source/);
    await page.locator('#organizer-seat-editor button[data-r="0"][data-c="2"]').click();
    await expect(page.locator('#organizer-seat-editor button[data-r="0"][data-c="2"]')).toContainText('A1');

    // Save Seat Positions -> redirects to /events/{id}
    await page.click('button:has-text("Save Seat Positions")');
    await expect(page).toHaveURL(new RegExp(`/events/${createdEventId}$`));
    await expect(page.locator('.seat-map-stage-side')).toBeVisible();
    await expect(page.locator('body')).toContainText(EVENT_LOCATION);
    await expect(page.locator('#event-location-map')).toBeVisible();
    await expect(page.locator('#event-location-map iframe.location-map-iframe')).toHaveAttribute('src', /output=embed/);

    // Verify Search by Location on the home page finds the new event by address and filters out non-matching cities
    await page.goto('/');
    const locInput = page.locator('#event-location-search');
    await expect(locInput).toBeVisible();
    await locInput.fill('Carrer de Colon');
    await expect(page.locator(`.event-card:not([hidden]):has-text("${EVENT_TITLE}")`)).toBeVisible();
    await locInput.fill('NonExistentMarsCity999');
    await expect(page.locator('#events-search-empty')).toBeVisible();
    await page.locator('#events-search-reset-btn').click();
    await expect(page.locator(`.event-card:not([hidden]):has-text("${EVENT_TITLE}")`)).toBeVisible();

    // Return to event page for Organizer seat check
    await page.goto(`/events/${createdEventId}`);

    // 4. Verify Organizer clicking a seat sees interactive selection + "Sign Out to Buy" prompt
    await page.locator('button[data-preview-seat="A1"]').click();
    await expect(page.locator('#preview-seat-badge')).toContainText('Seat A1 · 1 Person');
    await expect(page.locator('#organizer-switch-buy-btn')).toContainText('Sign Out to Buy Seat A1');
  });

  test('Seat map sits at bottom of event page, customer clicks seat to buy, and 2nd person cannot take same seat', async ({ browser }) => {
    expect(createdEventId).toBeGreaterThan(0);

    // Context 1: Customer 1
    const ctx1 = await browser.newContext();
    const page1 = await ctx1.newPage();
    try {
      await registerCustomer(page1, 'Buyer One', CUST1_EMAIL);
      await page1.goto(`/events/${createdEventId}`);

      // Verify seat map is visually BELOW the pricing tiers heading
      const tiersBox = await page1.locator('#tiers-heading').boundingBox();
      const mapBox = await page1.locator('#event-seat-map').boundingBox();
      expect(tiersBox).not.toBeNull();
      expect(mapBox).not.toBeNull();
      expect(mapBox.y).toBeGreaterThan(tiersBox.y);

      // Verify 1 Seat = 1 Person Only badge is shown
      await expect(page1.locator('#event-seat-map')).toContainText('1 Seat = 1 Person Only');

      // Click seat A1 on the map -> Buy Seat button activates with exact price (149$)
      const seatA1 = page1.locator('button[data-preview-seat="A1"]');
      await seatA1.click();
      await expect(seatA1).toHaveClass(/is-selected/);
      const buyBtn = page1.locator('#direct-seat-buy-btn');
      await expect(buyBtn).toBeEnabled();
      await expect(buyBtn).toContainText('Buy Seat A1 for 149$');

      // Buy Seat A1 in 1 click
      await buyBtn.click();
      await expect(page1).toHaveURL(/\/account\/tickets\/\d+\/confirmed$/);
      await expect(page1.locator('body')).toContainText('You bought the ticket for 149$!');
      await expect(page1.locator('body')).toContainText('Reserved Seat: A1 · 1 Person Only');
      await expect(page1.locator('body')).toContainText(EVENT_LOCATION);
    } finally {
      await ctx1.close();
    }

    // Context 2: Customer 2 sees Seat A1 as Taken (cannot click) and buys Seat D4 (19$)
    const ctx2 = await browser.newContext();
    const page2 = await ctx2.newPage();
    try {
      await registerCustomer(page2, 'Buyer Two', CUST2_EMAIL);
      await page2.goto(`/events/${createdEventId}`);

      await expect(page2.locator('#event-seat-map')).toContainText('15 Free');
      await expect(page2.locator('#event-seat-map')).toContainText('1 Taken');
      await expect(page2.locator('button[data-preview-seat="A1"]')).toHaveCount(0);
      await expect(page2.locator('span.seat-btn.is-taken[title*="Seat A1"]')).toBeVisible();

      // Customer 2 picks Seat D4 (Standard 19$) and buys it
      const seatD4 = page2.locator('button[data-preview-seat="D4"]');
      await seatD4.click();
      await expect(page2.locator('#direct-seat-buy-btn')).toContainText('Buy Seat D4 for 19$');
      await page2.locator('#direct-seat-buy-btn').click();
      await expect(page2).toHaveURL(/\/account\/tickets\/\d+\/confirmed$/);
      await expect(page2.locator('body')).toContainText('Reserved Seat: D4 · 1 Person Only');
      await expect(page2.locator('body')).toContainText('19$');
    } finally {
      await ctx2.close();
    }
  });

  test('Admin can log in and rearrange seat positions while booked seats stay protected', async ({ page }) => {
    expect(createdEventId).toBeGreaterThan(0);

    await page.goto('/admin/login');
    await page.fill('#identity', 'admin@swiftdesk.local');
    await page.fill('#password', '1234');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/\/admin$/);

    await page.goto(`/admin/events/${createdEventId}/seats`);
    await expect(page.locator('h1')).toContainText('Arrange Seat Positions');

    // Booked seats A1 & D4 have the red locked booked class in the editor
    await expect(page.locator('#admin-seat-editor .is-editor-booked')).toHaveCount(2);

    // Admin switches Stage to Bottom and saves
    await page.locator('#admin-seat-editor button[data-stage-pos="bottom"]').click();
    await page.click('button:has-text("Save Seat Positions")');
    await expect(page).toHaveURL(/\/admin\/events$/);

    // Public page now shows Stage at Bottom
    await page.goto(`/events/${createdEventId}`);
    await expect(page.locator('.seat-map-stage-bottom')).toBeVisible();
  });
});
