# TagForge Modular GTM
## WordPress / WooCommerce Plugin

**Version:** 5.3.0
**Author:** Amit Wadhwa · [xava.ie](https://xava.ie)
**Plugin URI:** [tagforge.io](https://tagforge.io)
**Requires:** WordPress 6.0+, WooCommerce 8.0+, PHP 8.0+
**Licence:** GPL-2.0+

---

## What This Plugin Does

TagForge Modular GTM assembles, pre-fills and delivers ready-to-import Google Tag Manager containers. Customers answer a few questions in an AI-powered chat, receive a personalised container recommendation, enter their measurement IDs, and receive a correctly structured GTM JSON file — ready to import in 60 seconds.

---

## Shortcodes

### [tagforge_builder]
**Full two-column buying experience.**

Renders the complete builder — product meta, AI chat, WooCommerce Add-on fields, and Add to Cart. Self-contained and embeddable anywhere. Use on the homepage, `/build` product page, or any landing page.

| Attribute | Default | Options | Notes |
|---|---|---|---|
| `product_id` | option: `tagforge_builder_product_id` | Any WC product ID | The Custom Container product |
| `chat_style` | `conversational` | `conversational` \| `guided` | Conversational = natural expert flow. Guided = strict Q1–Q4. |

```
[tagforge_builder]
[tagforge_builder chat_style="guided"]
[tagforge_builder product_id="1242" chat_style="conversational"]
```

**Legacy alias:** `[tagforge_builder_full]` maps to this shortcode.

---

### [tagforge_chat]
**Chat-only entry widget.**

Displays Q1 site-type buttons only. On selection, immediately redirects to `/build?site_type=VALUE` where Q2–Q4 complete inside the full builder. Use in sidebars, blog posts, or any secondary placement. Mobile-safe — purchase always completes on `/build`.

| Attribute | Default | Notes |
|---|---|---|
| `theme` | `dark` | Widget colour theme |
| `title` | Build your container | Widget heading |
| `subtitle` | Answer a few questions… | Widget subheading |

```
[tagforge_chat]
[tagforge_chat theme="dark" title="Build your GTM container"]
```

**Legacy alias:** `[tagforge_builder_widget]` maps to this shortcode.

---

## Recommended Page Setup

| Page / Location | Shortcode | Notes |
|---|---|---|
| Homepage | `[tagforge_builder]` | Full two-column experience, no redirect |
| /build product page | `[tagforge_builder]` | Via template or Elementor shortcode widget |
| Blog post sidebar | `[tagforge_chat]` | Redirects to /build after Q1 |
| Product page sidebar | `[tagforge_chat]` | Redirects to /build after Q1 |
| Landing pages | `[tagforge_builder]` | Full experience anywhere |

---

## JavaScript Assets

| File | Handle | Loaded by | Purpose |
|---|---|---|---|
| `tagforge-builder.js` | `tagforge-builder` | Both shortcodes | Conversation engine, Claude proxy, recommendation parsing, overlays |
| `tagforge-buildpage.js` | `tagforge-buildpage` | `[tagforge_builder]` only | Left column state — TF_BuildPage object, form reveal, field population |
| `tagforge-builder.css` | `tagforge-builder` | Both shortcodes | All builder styles |

---

## AI Builder — Chat Modes

**Conversational** (`chat_style="conversational"`)
Claude acts as a senior GTM consultant. Discovers platform, goal, ad platforms and CMP in natural conversation. Reaches recommendation within 3 exchanges. Recommended for production.

**Guided** (`chat_style="guided"`)
Strict Q1–Q4 sequence with structured OPTIONS lines. More predictable, less natural. Good for A/B testing.

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

---

## Pricing Tiers

| Modules | Price |
|---|---|
| 1–5 | €49 |
| 6–9 | €79 |
| 10–13 | €109 |
| 14–17 | €129 |
| 18+ | €149 |

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

Configure in **TF Factory → AI Builder → Zoho settings**:
- Endpoint: `https://xwpd-zgpvh.maillist-manage.net/weboptin.zc`
- Form ID: stored in settings
- Preview URL field: `CONTACT_CF4`

---

## Placeholder IDs

| Key | Dummy Value |
|---|---|
| `GA4_MEASUREMENT_ID` | `G-XXXXXXXXXX` |
| `PIXEL_ID` | `000000000000000` |
| `GADS_CONVERSION_ID` | `AW-0000000000` |
| `GADS_CONVERSION_LABEL` | `XXXXXXXXXXXXXXXX` |
| `LI_PARTNER_ID` | `0000000` |
| `TIKTOK_PIXEL_ID` | `C000000000000000000` |
| `CLARITY_PROJECT_ID` | `xxxxxxxxxx` |
| `HOTJAR_SITE_ID` | `0000000` |
| `BING_UET_TAG_ID` | `0000000` |
| `PINTEREST_TAG_ID` | `0000000000000` |
| `COOKIEBOT_DOMAIN_ID` | `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` |

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
│   ├── class-tagforge-admin.php
│   ├── class-tagforge-product-ui.php
│   ├── class-tagforge-resend.php
│   ├── class-tagforge-ai-builder.php
│   ├── class-tagforge-ai-admin.php
│   ├── class-tagforge-ai-pricing.php
│   ├── class-tagforge-ai-db.php
│   └── class-tagforge-zoho.php
├── assets/
│   ├── tagforge-builder.js
│   ├── tagforge-builder.css
│   ├── tagforge-buildpage.js          ← new v5.2
│   ├── tagforge-product-ui.js
│   ├── tagforge-product-ui.css
│   ├── tagforge-ai-admin.js
│   └── tagforge-ai-admin.css
├── templates/
│   └── builder-product.php            ← thin wrapper v5.2
└── modules/
    └── [25 JSON module files]
```

---

## Changelog

### 5.3.0 — 2026-06-30
- **Master Export admin screen** (`TagForge > Master Export`): assembles every module in `/modules/` into a single GTM container for full-stack testing. Auto-discovers all `{{PLACEHOLDER}}` keys from JSON files — new modules surface new ID fields automatically.
- ID fields persist between sessions via `wp_options`.
- Modules with no ID provided are automatically excluded from the export (e.g. leave Google Ads blank → both conversion and remarketing modules are dropped). Skipped modules listed in the success notice.
- Mutually exclusive CMP selector (Complianz / Consentmo / Cookiebot / None). Cookiebot Domain ID field shown/hidden by JS.
- Export summary shows module count, tags, triggers and variables assembled.

### 5.2.1
- Factory: `consent-mode-v2` auto-suppressed when self-managing CMP present
- Trigger enum normalisation fixes (`SCROLL_DEPTH`, `CUSTOM_EVENT`)
- Parameter type uppercasing throughout (`TEMPLATE`, `BOOLEAN`, `LIST`, `MAP`)
- `normalise_for_gtm`: GA4 event tags (`gaawe`) auto-inject `measurementIdOverride`
- Deduplication of tags, triggers, variables and builtInVariables across merged modules
- Consent type application per tag type (`analytics_storage`, `ad_storage + ad_user_data`)

---

## Known Issues / To Do

- `wordpress-starter` plugin `Puc_v4p11` deprecated notices — Vivek to update
- WP_DEBUG off before public launch
- Checkout Pixel (working on Covy) — productise in v5.x
- Complianz Shopify module — no GTM path, parked

---

*TagForge.io · A Xava Division · xava.ie*
