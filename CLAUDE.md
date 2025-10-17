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

When MemberPress creates a Stripe subscription (see `MeprStripeGateway.php` lines 2407-2413):

```php
$tax_inclusive = $mepr_options->attr('tax_calc_type') === 'inclusive';

if ($tax_inclusive) {
    // For inclusive: send TOTAL to Stripe
    $plan_id = $this->get_stripe_plan_id($sub, $prd, $sub->total);
    // Get INCLUSIVE tax rate
    $tax_rate_id = $this->get_stripe_tax_rate_id(..., true);
} else {
    // For exclusive: send SUBTOTAL to Stripe
    $plan_id = $this->get_stripe_plan_id($sub, $prd, $sub->price);
    // Get EXCLUSIVE tax rate
    $tax_rate_id = $this->get_stripe_tax_rate_id(..., false);
}
```

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

// 2. Check if it has tax rates
$tax_rate_id = $stripe_sub['items']['data'][0]['tax_rates'][0]['id'] ?? null;

// 3. Look up the tax rate to check if it's inclusive
if ($tax_rate_id) {
    $tax_rate_obj = $gateway->send_stripe_request("tax_rates/{$tax_rate_id}", array(), 'get');
    $existing_tax_is_inclusive = !empty($tax_rate_obj['inclusive']);
}
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
// Detect existing tax configuration
$existing_tax_is_inclusive = false;
if (!empty($stripe_sub['items']['data'][0]['tax_rates'])) {
    $tax_rate_id = $stripe_sub['items']['data'][0]['tax_rates'][0]['id'];
    $tax_rate_obj = $gateway->send_stripe_request("tax_rates/{$tax_rate_id}", [], 'get');
    $existing_tax_is_inclusive = !empty($tax_rate_obj['inclusive']);
}

// User's input is interpreted based on detected configuration
$price_for_stripe = floatval($rule['new_price']);

// Create new Stripe price/plan
$plan_id = $gateway->get_stripe_plan_id($subscription, $product, $price_for_stripe);

// Preserve existing tax configuration
$tax_rate_id = $gateway->get_stripe_tax_rate_id(
    $subscription->tax_desc,
    $subscription->tax_rate,
    $product,
    $existing_tax_is_inclusive  // Use same inclusive/exclusive setting
);

// Update subscription item with new price and tax rate
$item_update = [
    'id' => $item_id,
    'price' => $new_price_id,
    'tax_rates' => [$tax_rate_id]
];
```

#### 2. MemberPress Update (`update_memberpress_subscription`)

```php
$new_price = floatval($rule['new_price']);
$stripe_tax_is_inclusive = !empty($rule['stripe_tax_is_inclusive']);

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
