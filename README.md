# MemberPress Stripe Price Updater

A WordPress plugin that changes the price of **existing** MemberPress subscriptions billed through Stripe.

> **Not officially supported.** This tool was built to fill a gap in MemberPress, which has no built-in way to raise or lower prices for current subscribers. It is provided as-is, with no warranty and no official support. MemberPress support can't help with it. **Test it on a staging site with Stripe in test mode before using it on a live site.**

## What it does

When you change a membership's price in MemberPress, only new signups get the new price. Existing subscribers keep paying the old one. This plugin updates them too:

- Changes the price on each matching **Stripe** subscription.
- Updates the matching **MemberPress** subscription so both show the same amount.
- Starts at the next renewal. No one is charged right away and there's no proration.
- Runs in small batches in the background, logs every subscription it touched, and emails the site admin when a job finishes.

## Download and install

Download the latest version here:

**https://github.com/cartpauj/mp-stripe-price-updater/releases/latest/download/mp-stripe-price-updater.zip**

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Upload the zip and activate it. If you're updating, choose **Replace current with uploaded**.
3. Go to **MemberPress → Stripe Price Updater**.

Use the zip from the link above. Don't use GitHub's green "Code" button or the "Source code (zip)" files: those name the folder differently and WordPress installs them as a second copy of the plugin.

## How to use it

1. Pick the Stripe gateway and the membership.
2. Optionally narrow it down by current price (exact price or a range) and billing period.
3. Enter the new price and preview which subscriptions will change.
4. Save the rule and run it. You can watch progress, cancel, and export logs as CSV.

### How the new price is read

The plugin looks at each Stripe subscription and handles tax the same way it's already set up:

- **Tax-inclusive** subscriptions: the price you enter is the total the customer pays.
- **Tax-exclusive or untaxed** subscriptions: the price you enter is the price before tax. Tax is added on top as before.
- **Subscriptions with a recurring coupon** keep their discount. They pay the new price minus the discount.

## What it skips

To avoid charging people the wrong amount, these subscriptions are skipped and logged as errors so you can handle them manually:

- Billed in a currency other than your site's main currency.
- Has a coupon that only lasts a limited time.
- Bills on a different schedule than the membership does now (for example, the membership was changed from monthly to yearly).
- Has no real Stripe subscription yet (for example, a cart checkout that didn't finish).

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MemberPress with at least one Stripe gateway

## Developer filters

- `mpsp_max_subscriptions_per_run`: subscriptions processed per batch (default `25`).
- `mpsp_max_execution_time`: seconds a batch may run (default `120`).

## License

GPL v2 or later. See [LICENSE](LICENSE).
