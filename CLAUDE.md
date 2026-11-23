# MemberPress Stripe Price Updater - Technical Documentation

## Tax Handling Architecture

This document explains how the plugin handles tax calculations for MemberPress subscriptions with Stripe integration.

---

## Key Revelations

### 1. MemberPress Tax Storage Model

**MemberPress ALWAYS stores subscription data in "exclusive" format**, regardless of whether taxes are configured as inclusive or exclusive:

- `price` field = subtotal (pre-tax amount)
- `tax_amount` field = calculated tax
- `total` field = price + tax_amount
- `tax_rate` field = percentage rate

This is consistent across all subscriptions - the database structure doesn't change based on tax configuration.

### 2. Stripe Tax Rate Configuration

Stripe has two types of tax rates:

- **Exclusive** (`inclusive: false`): Tax is added ON TOP of the price
  - Example: $100 price + 7.5% tax = $107.50 total

- **Inclusive** (`inclusive: true`): Tax is INCLUDED IN the price
  - Example: $100 price with 7.5% inclusive tax = $100 total (tax is ~$6.98 of that $100)

**Critical Insight**: The `inclusive` property is set on the **Tax Rate object** itself, not on the subscription. When you apply a tax rate to a subscription, the subscription inherits that behavior.

### 3. How MemberPress Creates Stripe Subscriptions

When MemberPress creates a Stripe subscription (see `MeprStripeGateway.php`, search for `get_stripe_price_id($tax_inclusive`):

```php
// Inclusive: send TOTAL to Stripe. Exclusive: send SUBTOTAL.
$price_id = $this->get_stripe_price_id($tax_inclusive ? (float) $sub->total : (float) $sub->price, $prd, $sub);

// Tax rates are only used when Stripe automatic tax is OFF
if (!self::use_automatic_tax() && $sub->tax_rate > 0 && $sub->tax_amount > 0) {
    $tax_rate_id = $this->get_stripe_tax_rate_id($sub->tax_desc, $sub->tax_rate, $prd, $tax_inclusive);
}
```

**MemberPress 1.14.0+ changes:**
- `get_stripe_plan_id($sub, $prd, $amount)` was removed and replaced by `get_stripe_price_id(float $amount, MeprProduct $prd, ?MeprSubscription $sub = null, bool $force_one_time = false)`. The plugin calls the new one when it exists and falls back to the old one on older MemberPress.
- **Stripe automatic tax** (`MeprStripeGateway::use_automatic_tax()`): Stripe calculates tax itself (`automatic_tax.enabled` on the subscription), no tax rates are attached, and inclusive/exclusive lives on the price's `tax_behavior`. Tax rates can't be combined with automatic tax.
- **Multi-currency**: subscriptions can have their own `currency`. Rule prices are in the site's primary currency, so the plugin skips subscriptions billed in any other currency.
- **Shopping cart**: still one MP subscription ↔ one Stripe subscription with one item. But cart subs start with a local placeholder `subscr_id` (`mc-sub-...` in membercore) until Stripe creates the real one, and that can fail and stay forever. The queries only pick up `subscr_id LIKE 'sub\_%'`.
- **Coupons**: MP puts the FULL price on the Stripe subscription and attaches a Stripe coupon (`duration: forever`), but stores the DISCOUNTED price itself. The coupon stays after our price swap, so the plugin stores `new price − discount` in MP (`charged_price`). Coupons with any other duration are skipped with an error.
- **Billing terms changed on the membership**: `get_stripe_price_id()` builds the price from the subscription's terms but caches it under the membership's current terms. If they differ, the plugin skips the subscription so it doesn't poison that cache.
- **VAT reverse charge**: `tax_reversal_amount` is scaled with the price change.

**Key Point**: The subscription's Stripe configuration is determined by the **global MemberPress setting at the time of creation**. This setting is NOT stored with the subscription, so we cannot reliably determine later whether a subscription was created with inclusive or exclusive taxes.

### 4. The Problem We Solved

When updating an existing subscription's price:

1. **We don't know** if the subscription was originally created with inclusive or exclusive taxes
2. **MemberPress global setting** might have changed since the subscription was created
3. **Stripe subscription** already has tax rates configured as inclusive OR exclusive
4. **We must preserve** the existing Stripe configuration to avoid breaking the subscription

---

## Solution: Automatic Tax Configuration Detection

Instead of asking the user whether their price includes tax (which they might not know), we **automatically detect** the existing Stripe subscription's tax configuration.

### Detection Process

```php
// 1. Retrieve existing Stripe subscription
$stripe_sub = $gateway->send_stripe_request("subscriptions/{$stripe_sub_id}", array(), 'get');

// 2. Stripe Tax (automatic tax): inclusive/exclusive is the price's tax_behavior
$uses_automatic_tax = !empty($stripe_sub['automatic_tax']['enabled']);
if ($uses_automatic_tax) {
    $existing_tax_is_inclusive = ($stripe_sub['items']['data'][0]['price']['tax_behavior'] ?? '') === 'inclusive';
} elseif ($tax_rate_id = $stripe_sub['items']['data'][0]['tax_rates'][0]['id'] ?? null) {
    // 3. Tax rates: look up the tax rate to check if it's inclusive
    $tax_rate_obj = $gateway->send_stripe_request("tax_rates/{$tax_rate_id}", array(), 'get');
    $existing_tax_is_inclusive = !empty($tax_rate_obj['inclusive']);
}

// 4. Under automatic tax, the NEW price's tax_behavior wins (MemberPress sets it from the
//    current global setting, which may have changed), so MP matches what Stripe will charge
```

### Price Interpretation Logic

Based on the detected configuration:

**If Stripe has INCLUSIVE taxes:**
- User's input is interpreted as the **TOTAL** (what customer pays)
- Send this total to Stripe (inclusive tax rate extracts the tax)
- Convert to subtotal for MemberPress: `subtotal = total / (1 + tax_rate/100)`

**If Stripe has EXCLUSIVE taxes (or no taxes):**
- User's input is interpreted as the **SUBTOTAL**
- Send this subtotal to Stripe (exclusive tax rate adds tax on top)
- Use as-is for MemberPress

---

## How the Tool Works Now

### User Workflow

1. **Select membership and filter criteria**
2. **Enter new price** (single number - no checkbox needed)
3. **Save and run**

The plugin handles everything automatically.

### Behind the Scenes

```
┌─────────────────────────────────────────────────────────────┐
│ 1. User enters: $200                                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Plugin queries Stripe subscription                       │
│    - Gets existing tax_rates configuration                  │
│    - Determines: inclusive or exclusive?                    │
└──────────────────────┬──────────────────────────────────────┘
                       │
        ┌──────────────┴──────────────┐
        │                             │
        ▼                             ▼
┌──────────────────┐        ┌──────────────────┐
│ INCLUSIVE TAXES  │        │ EXCLUSIVE TAXES  │
└────────┬─────────┘        └────────┬─────────┘
         │                           │
         ▼                           ▼
┌──────────────────┐        ┌──────────────────┐
│ $200 = TOTAL     │        │ $200 = SUBTOTAL  │
│                  │        │                  │
│ Stripe:          │        │ Stripe:          │
│   $200 (tax in)  │        │   $200 + $15 tax │
│                  │        │   = $215 total   │
│ MemberPress:     │        │                  │
│   $186.05 sub    │        │ MemberPress:     │
│   + $13.95 tax   │        │   $200 sub       │
│   = $200 total   │        │   + $15 tax      │
│                  │        │   = $215 total   │
└──────────────────┘        └──────────────────┘
```

### Code Flow

#### 1. Stripe Update (`update_stripe_subscription`)

```php
// Detect existing tax configuration (see Detection Process above)
$uses_automatic_tax = !empty($stripe_sub['automatic_tax']['enabled']);
$existing_has_tax_rates = !empty($stripe_sub['items']['data'][0]['tax_rates']);
// ... sets $existing_tax_is_inclusive

// User's input is interpreted based on detected configuration
$price_for_stripe = floatval($rule['new_price']);

// Create new Stripe price. Check the OLD method first: MemberPress 1.12.x also has a
// get_stripe_price_id() with a different signature ($sub, $prd, $price).
if (method_exists($gateway, 'get_stripe_plan_id')) {
    $plan_id = $gateway->get_stripe_plan_id($subscription, $product, $price_for_stripe);  // < 1.14.0
} else {
    $plan_id = $gateway->get_stripe_price_id((float) $price_for_stripe, $product, $subscription);  // 1.14.0+
}

// Read the new price back from Stripe
$new_price_obj = $gateway->send_stripe_request("prices/{$new_price_id}", array(), 'get');

// Billing period guard: MemberPress caches prices by the membership's CURRENT terms. If the
// new price's recurring interval/interval_count differs from the subscription's current price,
// abort — switching intervals makes Stripe reset the billing cycle and charge immediately.

// Under automatic tax, use the new price's tax_behavior for $existing_tax_is_inclusive

$item_update = ['id' => $item_id, 'price' => $new_price_id];

// Preserve existing tax rates — only when NOT automatic tax AND the item already had tax rates
if (!$uses_automatic_tax && $existing_has_tax_rates && $subscription->tax_rate > 0) {
    $tax_rate_id = $gateway->get_stripe_tax_rate_id(
        $subscription->tax_desc,
        $subscription->tax_rate,
        $product,
        $existing_tax_is_inclusive  // Use same inclusive/exclusive setting
    );
    if (!empty($tax_rate_id)) {
        $item_update['tax_rates'] = [$tax_rate_id];
    }
}

// Returned to the MemberPress update step:
//   'stripe_tax_is_inclusive' => $existing_tax_is_inclusive,
//   'stripe_has_tax'          => $uses_automatic_tax || $existing_has_tax_rates
```

#### 2. MemberPress Update (`update_memberpress_subscription`)

```php
$new_price = floatval($rule['new_price']);
$stripe_tax_is_inclusive = !empty($rule['stripe_tax_is_inclusive']);
// If Stripe doesn't tax this subscription, MP doesn't either (the real code uses $tax_rate below)
$tax_rate = (!isset($rule['stripe_has_tax']) || $rule['stripe_has_tax']) ? $subscription->tax_rate : 0;

if ($stripe_tax_is_inclusive && $subscription->tax_rate > 0) {
    // INCLUSIVE: User entered TOTAL, convert to subtotal for MP
    $subtotal = $new_price / (1 + ($subscription->tax_rate / 100.00));
    $new_tax_amount = $subtotal * ($subscription->tax_rate / 100.00);
    $new_total = $subtotal + $new_tax_amount;
    $new_price_for_db = $subtotal;  // MP always stores subtotal in 'price' field
} else {
    // EXCLUSIVE: User entered SUBTOTAL, use as-is
    $new_price_for_db = $new_price;
    if ($subscription->tax_rate > 0) {
        $new_tax_amount = $new_price * ($subscription->tax_rate / 100.00);
        $new_total = $new_price + $new_tax_amount;
    }
}

// Update database
$wpdb->update(
    $wpdb->prefix . 'mepr_subscriptions',
    [
        'price' => $new_price_for_db,      // Subtotal
        'total' => $new_total,             // Subtotal + tax
        'tax_amount' => $new_tax_amount    // Calculated tax
    ],
    ['id' => $subscription->id]
);
```

---

## Examples

### Example 1: Exclusive Tax Subscription (7.5%)

**Original subscription:**
- Stripe: Has exclusive tax rate configured
- MP: price=$100, tax_amount=$7.50, total=$107.50

**User action:**
- Enters: $200

**What happens:**
1. Plugin detects: Exclusive taxes
2. Interprets $200 as: Subtotal
3. Stripe update: Creates plan with $200, applies exclusive 7.5% tax rate
4. MP update: Stores price=$200, calculates tax=$15, total=$215

**Result:**
- **Stripe dashboard**: $200 + $15 (7.5% tax) = $215 total
- **MemberPress dashboard**: $200 + $15 = $215 total
- ✅ **Match!**

### Example 2: Inclusive Tax Subscription (7.5%)

**Original subscription:**
- Stripe: Has inclusive tax rate configured
- MP: price=$93.02, tax_amount=$6.98, total=$100

**User action:**
- Enters: $200

**What happens:**
1. Plugin detects: Inclusive taxes
2. Interprets $200 as: Total (with tax)
3. Calculates: $200 / 1.075 = $186.05 subtotal
4. Stripe update: Creates plan with $200, applies inclusive 7.5% tax rate
5. MP update: Stores price=$186.05, calculates tax=$13.95, total=$200

**Result:**
- **Stripe dashboard**: $200 (includes $13.95 tax)
- **MemberPress dashboard**: $186.05 + $13.95 = $200 total
- ✅ **Match!**

### Example 3: Subscription with No Taxes

**Original subscription:**
- Stripe: No tax rates
- MP: price=$100, tax_amount=$0, total=$100

**User action:**
- Enters: $200

**What happens:**
1. Plugin detects: No taxes
2. Interprets $200 as: Price (no tax calculation needed)
3. Stripe update: Creates plan with $200, no tax rates
4. MP update: Stores price=$200, tax=$0, total=$200

**Result:**
- **Stripe dashboard**: $200
- **MemberPress dashboard**: $200
- ✅ **Match!**

---

## Benefits of This Approach

### 1. **User-Friendly**
- No confusing checkbox
- Just enter the price you want
- Plugin figures out the rest

### 2. **Accurate**
- Always respects existing subscription configuration
- No risk of mismatched calculations
- Consistent results between MP and Stripe

### 3. **Flexible**
- Works with any tax configuration
- Handles mixed scenarios (some subs inclusive, some exclusive)
- Preserves existing setup

### 4. **Robust**
- Queries actual Stripe data (source of truth)
- Doesn't rely on guesses or assumptions
- Handles edge cases gracefully

---

## Technical Considerations

### Database Fields NOT Updated

The following subscription fields are **never modified** by this tool:
- `tax_rate` - Preserved from original subscription
- `tax_desc` - Preserved from original subscription
- `tax_class` - Preserved from original subscription

### Stripe Tax Rate Reuse

The plugin uses MemberPress's `get_stripe_tax_rate_id()` function which:
- Checks for existing tax rate with matching parameters
- Reuses if found (avoids creating duplicates)
- Creates new one only if needed

The cache key includes:
- Tax percentage
- Display name
- **Inclusive/exclusive flag**

So inclusive and exclusive versions are cached separately.

### Error Handling

If tax rate detection fails:
- Defaults to treating price as exclusive (safer assumption)
- Logs error for debugging
- Continues with update (doesn't fail completely)

Job processing:
- All catches use `\Throwable`, so PHP `Error`s (e.g. a removed MemberPress method) become error entries instead of killing the cron run
- If resuming a job throws, the job is marked `failed` and its rule disabled (same as Cancel), so it can't sit at "running" forever
- A subscription this rule already updated successfully at this price is never picked up again (the price check alone misses inclusive-tax subs, since MP stores the subtotal)
- Each subscription gets a `processing` entry BEFORE it's touched, then updated with the result. If PHP dies mid-update (memory/time limit), the row stays `processing` and the subscription isn't retried forever
- `check_database_version()` runs on `init` (every request, including cron and AJAX), so the widened enums exist before anything writes `processing`/`unknown`
- `maybe_stop_jobs_for_new_version()` also runs on `init`: once per plugin version (tracked in the `mpsp_jobs_reset_version` option) it cancels every unfinished job and disables every rule, so nothing from an older version keeps running. The admin reviews settings and starts a fresh job. Finished job history and logs are kept. Deliberately not tied to activation/upgrade hooks, which zip-upload updates can skip
- Automatic cron runs also skip subscriptions that already failed (or were left `processing`) on this rule, so they aren't retried every 5 minutes with a new email each time; "Process Now" still retries them

---

## Future Improvements

### Possible Enhancements

1. **Preview showing interpretation**
   - Show user how their price will be interpreted
   - Display calculated subtotal/total before saving

2. **Bulk conversion**
   - Convert all subscriptions from inclusive to exclusive (or vice versa)
   - One-time migration tool

3. **Manual override**
   - Advanced option to override auto-detection
   - For edge cases where admin knows better

4. **Tax rate consolidation**
   - Tool to identify and merge duplicate tax rates
   - Clean up Stripe account

---

## Troubleshooting

### Issue: MP and Stripe totals don't match after update

**Possible causes:**
1. Tax rate in MP doesn't match Stripe
2. Stripe subscription has multiple tax rates (we only check first one)
3. Currency rounding differences

**How to debug:**
1. Check subscription's `tax_rate` in MP database
2. Check tax rate in Stripe dashboard
3. Verify they match
4. Check error logs for tax rate lookup failures

### Issue: "Failed to get or create Stripe price"

**Possible causes:**
1. Stripe API connectivity issue
2. Invalid product configuration
3. Currency mismatch

**Solution:**
1. Check error logs for detailed message
2. Verify Stripe API keys are correct
3. Test Stripe connection manually

---

## Related Files

- `mp-stripe-price-updater.php` - Main plugin file
- `memberpress/app/gateways/MeprStripeGateway.php` - MemberPress Stripe integration
- `memberpress/app/models/MeprSubscription.php` - Subscription model
- `memberpress/app/models/MeprUser.php` - Tax calculation methods

## API References

- [Stripe Tax Rates API](https://docs.stripe.com/api/tax_rates)
- [Stripe Subscriptions API](https://docs.stripe.com/api/subscriptions)
- [Stripe Tax Documentation](https://docs.stripe.com/billing/taxes/tax-rates)
