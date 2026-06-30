# TagForge Modular GTM
## WordPress / WooCommerce Plugin

**Version:** 5.3.1
**Author:** Amit Wadhwa · [xava.ie](https://xava.ie)
**Plugin URI:** [tagforge.io](https://tagforge.io)
**Requires:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.0+
**Licence:** GPL-2.0+

---

## What This Plugin Does

TagForge Modular GTM assembles, pre-fills and delivers ready-to-import Google Tag Manager containers. Customers answer a few questions in an AI-powered chat, receive a personalised container recommendation, enter their measurement IDs, and receive a correctly structured GTM JSON file — ready to import in 60 seconds.

---

## Changelog

### 5.3.0 — 2026-06-30
- **Master Export admin screen** (`TagForge > Master Export`): assembles every module in `/modules/` into a single GTM container for full-stack testing. Auto-discovers all `{{PLACEHOLDER}}` keys from JSON files — new modules surface new ID fields automatically.
- ID fields persist between sessions via `wp_options`.
- Modules with no ID provided are automatically excluded from the export (e.g. leave Google Ads blank → both conversion and remarketing modules are dropped). Skipped modules listed in the success notice.
- Mutually exclusive CMP selector (Complianz / Consentmo / Cookiebot / None). Cookiebot Domain ID field shown/hidden by JS.
- Export summary shows module count, tags, triggers and variables assembled.

### 5.2.1
- Factory: `consent-mode-v2` auto-suppressed when self-managing CMP (`consentmo-cmp`, `cookiebot-cmp`) present
- Trigger enum normalisation fixes (`SCROLL_DEPTH`, `CUSTOM_EVENT`, `scrollDepth` camelCase variant)
- Parameter type uppercasing throughout (`TEMPLATE`, `BOOLEAN`, `LIST`, `MAP`, `TAG_REFERENCE` etc.) — GTM import was rejecting lowercase type enums
- `normalise_for_gtm()`: GA4 event tags (`gaawe`) auto-inject `measurementIdOverride` if absent; CUSTOM_EVENT triggers rewritten to `customEventFilter` format
- `dedup_merged()`: deduplication of tags, triggers, variables and builtInVariables across merged modules
- `apply_consent_types()`: per-tag consent settings (`analytics_storage`, `ad_storage + ad_user_data`, `NOT_SET`) applied based on tag type and name
- `tagforge-buildpage.js` extracted as standalone asset
- `templates/builder-product.php` refactored as thin wrapper
- Fix: `resolve_path()` was using `+` (addition) instead of `.` (string concatenation)

### 5.2.0
- Factory pipeline fully refactored: ID allocator, trigger reference rewriting, per-module JSON merge
- `normalise_for_gtm()` introduced — structures assembled container to match real GTM export format exactly
- `_tagforge_meta` block added to container root (generated_by, version, licence, consent note)
- `builtInVariable` name mapping added (Video Title, Click URL, Scroll Depth etc.)
- Module count increased to 25 (added `bing-uet`, `engagement-timer`, `search-tracking`)
- Admin order meta box: module chips display, expiry date, download button, regenerate & resend
- Regenerate & Resend action (`admin-post.php?action=tagforge_regen`) — rebuilds container and resends delivery email
- `ensure_artifact()` lock transient prevents double-build on duplicate order hooks

### 5.1.0
- Zoho Campaigns integration: configurable endpoint, form ID and UID in AI Builder settings
- `class-tagforge-zoho.php` introduced as dedicated Zoho class (previously inline)
- `realpath()` guard fix in download handler — prevents path traversal false-negative when upload directory not yet created
- AI Builder sessions admin: follow-up flag column, CSV export of sessions
- Zoho lead creation wired to `tagforge_builder_lead_captured` action hook

### 5.0.0
- `[tagforge_resend_download]` shortcode — `class-tagforge-resend.php`: customers re-request download by entering order number + email; rate-limited by IP, regenerates fresh timed token and sends branded email
- Zoho Campaigns preview delivery moved to `class-tagforge-zoho.php`
- `class-tagforge-resend.php` registered via `init` hook (additive — no v4.x code modified)
- Delivery email HTML block redesigned (gradient CTA button, TagForge brand colours)

---

### v4.0.2

**Changes (JS and CSS only — no PHP modified)**

- `tagforge-builder.js` — `getBuilderProductId()` reads from `TF_Builder.product_id` first, falls back to data attribute
- `tagforge-builder.js` — `showRecommendation()` calls `TF_BuildPage.onRecommendation()` when on the builder product page
- `tagforge-builder.js` — `startIdCollection()` delegates to `TF_BuildPage.focusAddonFields()` on builder page; falls back to inline chat form on other contexts
- `tagforge-builder.js` — `proceedToCheckout()` delegates to `TF_BuildPage.populateAddonFields()` and `TF_BuildPage.submitForm()` on builder page
- `tagforge-builder.css` — Full builder product page grid layout, form animation, addon field reveal, highlight border, mobile reflow
- `templates/builder-product.php` — New self-contained two-column product page template with deferred inline JS (`TF_BuildPage` object)

**New file**
- `templates/builder-product.php` — Custom WooCommerce product template for the AI Builder product. Applied via `template_include` filter in child theme `functions.php` at priority 99.

---

### v4.0.1

- `tagforge-builder.js` — Full inline ID form implementation (`showIdForm`, `collectAndSubmitIds`, `getNeededIds` with complete module→ID mapping)
- `tagforge-builder.js` — `populateWooCommerceForm()` rewritten to target fields by `data-addon-name` attribute
- `tagforge-builder.js` — `submitToCart()` simplified
- `tagforge-builder.css` — Inline ID form styles

---

### v4.0.0 — AI Builder

**New features (additive — no existing v3.x functionality modified)**

- Claude API integration via PHP REST proxy
- AI Builder conversational UI — qualification flow leading to personalised bundle recommendation
- Custom container naming — Claude generates a bespoke name after Q2–Q5
- Homepage widget shortcode `[tagforge_builder_widget]`
- Full builder shortcode `[tagforge_builder_full]`
- Email capture at preview gate
- Partial JSON preview overlay (free lead magnet CTA)
- Server-side refinement limiting — 2 downstream refinements per session
- Dynamic pricing — module count drives price tier, enforced server-side
- Builder sessions admin screen
- AI Builder settings tab — Claude API key, usage limits
- Placeholder ID system — syntactically valid GTM-importable dummy values
- Builder sessions database table
- Zoho CRM lead creation hook on email capture

**No changes to v3.x files:**
- `class-tagforge-factory.php` ✓
- `class-tagforge-order.php` ✓ (one filter hook added: `tagforge_order_modules`)
- `class-tagforge-product-ui.php` ✓
- `helpers.php` ✓
- All 22 module JSON files ✓

---

### v3.7.3 — Stable baseline

- 22 tracking modules
- Factory assembly pipeline
- WooCommerce order processing with Product Add-ons
- Timed download token (7 days)
- Delivery email
- Admin order meta box
- Test page and settings

---

## Shortcodes

### [tagforge_builder]
**Full two-column buying experience.**

Renders the complete builder — product meta, AI chat, WooCommerce Add-on fields, and Add to Cart. Self-contained and embeddable anywhere.

| Attribute | Default | Options | Notes |
|---|---|---|---|
| `product_id` | option: `tagforge_builder_product_id` | Any WC product ID | The Custom Container product |
| `chat_style` | `conversational` | `conversational` \| `guided` | Conversational = natural expert flow. Guided = strict Q1–Q4. |

```
[tagforge_builder]
[tagforge_builder chat_style="guided"]
[tagforge_builder product_id="1242" chat_style="conversational"]
```

**Legacy aliases:** `[tagforge_builder_full]`, `[tagforge_builder_widget]`

---

### [tagforge_chat]
**Chat-only entry widget.**

Displays Q1 site-type buttons only. On selection, redirects to `/build?site_type=VALUE`. Use in sidebars, blog posts, or any secondary placement.

---

### [tagforge_resend_download]
**Customer self-service download resend.**

Renders a form where customers enter their order number and billing email to regenerate a fresh timed download link. Rate-limited by IP. Suitable for a `/resend-download/` page.

---

## Recommended Page Setup

| Page / Location | Shortcode | Notes |
|---|---|---|
| Homepage | `[tagforge_builder]` | Full two-column experience |
| /build product page | `[tagforge_builder]` | Via template or Elementor shortcode widget |
| /resend-download/ | `[tagforge_resend_download]` | Customer self-service |
| Blog post sidebar | `[tagforge_chat]` | Redirects to /build after Q1 |
| Landing pages | `[tagforge_builder]` | Full experience anywhere |

---

## Admin Screens

| Screen | Slug | Purpose |
|---|---|---|
| Settings | `tagforge` | Global defaults, email config, expiry, debug |
| Admin Test | `tagforge-test` | Quick container test with a subset of modules |
| Master Export | `tagforge-master` | Full-stack test: all modules in one container |
| AI Builder | `tagforge-ai-builder` | Claude API key, session stats, rate limits |
| Builder Sessions | `tagforge-ai-sessions` | All sessions with status, CSV export |
| Readme | `tagforge-readme` | This file rendered in-admin |

---

## Module Library (25 modules)

| Slug | Description | Platform |
|---|---|---|
| `gtag-basic` | GA4 Configuration tag | Any |
| `ecom-base` | GA4 ecommerce base events | Any |
| `ecom-advanced` | GA4 full ecommerce funnel | Any |
| `consent-mode-v2` | Consent Mode v2 defaults | Any |
| `complianz-cmp` | Complianz CMP integration | WordPress |
| `consentmo-cmp` | Consentmo GDPR CMP | Shopify |
| `cookiebot-cmp` | Cookiebot/Usercentrics CMP | Any |
| `facebook-pixel` | Meta Pixel base tag | Any |
| `facebook-events` | Meta standard events | Any |
| `google-ads-conversion` | Google Ads conversion tracking | Any |
| `google-ads-remarketing` | Google Ads remarketing | Any |
| `linkedin-insight` | LinkedIn Insight Tag | Any |
| `tiktok-pixel` | TikTok Pixel | Any |
| `pinterest-tag` | Pinterest Tag | Any |
| `bing-uet` | Microsoft Bing UET Tag | Any |
| `microsoft-clarity` | Microsoft Clarity | Any |
| `hotjar` | Hotjar session recording | Any |
| `scroll-depth` | Scroll depth tracking | Any |
| `click-tracking` | Click tracking | Any |
| `outbound-link-tracking` | Outbound link tracking | Any |
| `form-tracking` | Form submission tracking | Any |
| `search-tracking` | Site search tracking | Any |
| `yt-video-tracking` | YouTube video engagement | Any |
| `engagement-timer` | Time on page timer | Any |

---

## Factory Rules

- `consent-mode-v2` always included unless `consentmo-cmp` or `cookiebot-cmp` present (they manage consent natively)
- `gtag-basic` always included when any GA4 module present
- `facebook-pixel` always included when `facebook-events` present
- `ecom-base` always included when `ecom-advanced` present
- Never more than one CMP module per container
- Master Export: modules with no ID supplied are auto-excluded from the assembled container

---

## Pricing Tiers

| Modules | Price |
|---|---|
| 1–5 | €49 |
| 6–9 | €79 |
| 10–13 | €109 |
| 14–17 | €129 |
| 18+ | €149 |

Filterable via `tagforge_builder_price_tiers` hook.

---

## REST Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/wp-json/tagforge/v1/builder/session` | Initialise session |
| POST | `/wp-json/tagforge/v1/builder/chat` | Send message to Claude |
| GET | `/wp-json/tagforge/v1/builder/cmps` | Get available CMP modules dynamically |
| POST | `/wp-json/tagforge/v1/builder/send-preview` | Assemble preview container, POST to Zoho |
| POST | `/wp-json/tagforge/v1/builder/complete` | Mark session purchased |

---

## Zoho Campaigns Integration

Preview container delivery via Zoho Campaigns web-optin form POST. No OAuth required.

Configure in **TagForge → AI Builder → Zoho settings**:
- Endpoint: `https://xwpd-zgpvh.maillist-manage.net/weboptin.zc`
- Form ID: stored in settings
- Preview URL field: `CONTACT_CF4`

---

## Placeholder IDs

| Key | Dummy Value | Source |
|---|---|---|
| `GA4_MEASUREMENT_ID` | `G-XXXXXXXXXX` | GA4 > Admin > Data Streams |
| `PIXEL_ID` | `000000000000000` | Meta Events Manager > Pixel ID |
| `GADS_CONVERSION_ID` | `AW-0000000000` | Google Ads > Tools > Conversions |
| `GADS_CONVERSION_LABEL` | `XXXXXXXXXXXXXXXX` | Google Ads conversion label |
| `LI_PARTNER_ID` | `0000000` | LinkedIn Campaign Manager > Insight Tag |
| `TIKTOK_PIXEL_ID` | `C000000000000000000` | TikTok Ads Manager > Events > Pixel |
| `PINTEREST_TAG_ID` | `0000000000000` | Pinterest Ads > Conversions |
| `BING_UET_TAG_ID` | `0000000` | Microsoft Advertising > UET tags |
| `HOTJAR_SITE_ID` | `0000000` | Hotjar > Settings > Site ID |
| `CLARITY_PROJECT_ID` | `xxxxxxxxxx` | Clarity > Settings > Project ID |
| `COOKIEBOT_DOMAIN_ID` | `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` | Cookiebot > Dashboard > Domain group ID |

---

## Database Table

`{prefix}tagforge_builder_sessions`

| Column | Type | Notes |
|---|---|---|
| `session_id` | VARCHAR(64) | Unique session token |
| `email` | VARCHAR(255) | Captured at preview gate |
| `answers` | LONGTEXT | JSON: Q1–Q5 answers |
| `modules` | LONGTEXT | JSON: module slug array |
| `custom_name` | VARCHAR(255) | Claude-generated name |
| `price` | DECIMAL(8,2) | Calculated tier price |
| `refinements` | TINYINT | Count used (max 2) |
| `status` | VARCHAR(32) | active / purchased / expired |
| `follow_up` | TINYINT | Admin follow-up flag |
| `order_id` | BIGINT UNSIGNED | WooCommerce order ID |

Created on activation. Optionally dropped on deactivation. Always dropped on uninstall.

---

## Settings (`tagforge_options`)

| Key | Default | Notes |
|---|---|---|
| `claude_api_key` | — | Anthropic API key — server-side only, never exposed to browser |
| `builder_refinement_limit` | 2 | Max downstream refinements per session |
| `builder_rate_limit` | 10 | Max Claude calls per session per hour |
| `builder_delete_on_deactivate` | false | Drop DB table on deactivation |
| `default_modules_csv` | — | Global default modules always included in orders |
| `expiry_days` | 7 | Download link expiry in days |
| `email_customer` | 1 | Send download email to customer |
| `email_admin` | 0 | Send copy to admin |
| `admin_email` | — | Admin copy recipient |
| `debug` | 0 | Enable verbose debug logging |
| `zoho_endpoint` | — | Zoho Campaigns web-optin endpoint |
| `zoho_form_id` | — | Zoho form ID |
| `zoho_uid` | — | Zoho UID |

---

## Claude API

- **Model:** `claude-sonnet-4-6`
- **Endpoint:** `https://api.anthropic.com/v1/messages`
- **Max tokens:** 1024 per call
- **Key storage:** `tagforge_options['claude_api_key']` — never exposed to browser

---

## Builder Product Page — Two Column Layout

The `/build` product page uses `templates/builder-product.php` applied via `template_include` filter in the child theme.

```
Desktop:                    Mobile:
┌─────────┬──────────┐     ┌──────────────┐
│  meta   │          │     │     meta     │
│─────────│  chat    │     │──────────────│
│  form   │          │     │     chat     │
│         │          │     │──────────────│
└─────────┴──────────┘     │     form     │
                            └──────────────┘
```

**Left column:** product name, price range, description → on recommendation: form animates in, relevant Add-on fields reveal, price updates to exact tier.

---

## Child Theme Setup

```php
// Apply custom builder template to product 1242
add_filter( 'template_include', function( $template ) {
    if ( is_singular( 'product' ) && (int) get_the_ID() === 1242 ) {
        $custom = WP_PLUGIN_DIR . '/tagforge-modular-gtm/templates/builder-product.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}, 99 );

// Suppress product images sitewide
remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
```

**Elementor Theme Builder:** Single Product template must have `EXCLUDE: In Category: Custom` condition. Product 1242 must be in the "Custom" product category.

---

## File Structure

```
tagforge-modular-gtm/
├── tagforge-modular-gtm.php
├── uninstall.php
├── README.md
├── includes/
│   ├── helpers.php
│   ├── class-tagforge-factory.php
│   ├── class-tagforge-order.php
│   ├── class-tagforge-admin.php          ← Master Export added v5.3
│   ├── class-tagforge-product-ui.php
│   ├── class-tagforge-resend.php         ← new v5.0
│   ├── class-tagforge-zoho.php           ← new v5.0
│   ├── class-tagforge-ai-builder.php     ← v4.0
│   ├── class-tagforge-ai-admin.php       ← v4.0
│   ├── class-tagforge-ai-pricing.php     ← v4.0
│   └── class-tagforge-ai-db.php          ← v4.0
├── assets/
│   ├── tagforge-builder.js
│   ├── tagforge-builder.css
│   ├── tagforge-buildpage.js             ← extracted v5.2
│   ├── tagforge-product-ui.js
│   ├── tagforge-product-ui.css
│   ├── tagforge-ai-admin.js
│   └── tagforge-ai-admin.css
├── templates/
│   └── builder-product.php               ← thin wrapper v5.2
└── modules/
    └── [25 JSON module files]
```

---

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- WooCommerce Product Add-ons (official extension)
- PHP 8.0+
- Anthropic API key (Claude)
- Hello Elementor child theme (TagForge child)
- Elementor Pro

---

## Known Issues / To Do

- `wordpress-starter` plugin `Puc_v4p11` deprecated notices — update library or replace plugin
- WP_DEBUG off before public launch
- Checkout Pixel (working on Covy) — productise in v5.x
- Complianz Shopify module — no GTM path, parked
- Free basic container CTA — preview overlay in place, free tier assembly not yet implemented

---

*TagForge.io · A Xava Division · xava.ie · amit@xava.ie*
