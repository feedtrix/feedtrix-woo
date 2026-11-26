=== FeedTrix ===
Contributors: feedtrix
Tags: ai, seo, llms.txt, woocommerce, structured data
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get your store recommended by ChatGPT, Perplexity and Gemini: llms.txt, AI-ready product data, AI bot statistics and sales from AI.

== Description ==

More and more customers ask AI assistants what to buy. FeedTrix prepares your WooCommerce catalog so that AI assistants understand it, recommend your products and send buyers to your store.

The plugin connects your store to the [FeedTrix](https://feedtrix.com) service, which builds the AI-ready content from your catalog. A FeedTrix account is required (14-day free trial, then a paid plan).

= What the plugin does =

* **/llms.txt and /llms-full.txt** served from your own domain: your catalog with current prices and stock, in a format AI assistants read. A local cache and a fallback copy keep the files available even if the FeedTrix API is slow.
* **AI-ready structured data (JSON-LD)** on product pages: a description for AI assistants, attributes and questions with answers. It extends the Product data of WooCommerce, Yoast SEO or Rank Math instead of duplicating it. Price and availability always come from your store.
* **Questions and answers tab** on product pages (optional, off by default).
* **Instant updates**: price and stock changes are sent to FeedTrix right away (HMAC-signed webhooks), so AI assistants never see outdated prices.
* **AI bot statistics**: visits from GPTBot, PerplexityBot, ClaudeBot and other AI crawlers, with no personal data.
* **Sales from AI**: paid orders from customers who came from ChatGPT, Perplexity, Claude, Gemini or Copilot in the last 30 days, in one report in the FeedTrix dashboard.
* **IndexNow**: the verification file (/{key}.txt) for fast indexing of changes in Bing and ChatGPT Search.
* **MCP server for AI agents**: FeedTrix gives your store its own Model Context Protocol server. AI assistants and shopping agents (Claude, ChatGPT and others) can search your catalog, compare products and check live prices and stock. Its address is listed in /llms.txt; sales from it are counted in "Sales from AI".

Available in English, Polish, German, Spanish, French and Italian.

== Installation ==

1. Create an account at [panel.feedtrix.com](https://panel.feedtrix.com) and add your store.
2. Install and activate the plugin (Plugins → Add New, or upload the package downloaded from the FeedTrix dashboard).
3. A package downloaded from the FeedTrix dashboard is already connected. Otherwise go to WooCommerce → FeedTrix → Settings and paste the Store Key and API key from the dashboard.
4. Make sure there is no static llms.txt file in the WordPress root directory: the web server would serve it instead of the FeedTrix version.

== Frequently Asked Questions ==

= Do I need a FeedTrix account? =

Yes. The plugin is the connector between your store and the FeedTrix service, which generates the content for AI assistants. You can start with a 14-day free trial.

= Will it slow down my store? =

No. Data is sent to FeedTrix after the response to the visitor is finished, and the files and structured data are cached locally.

= Does it conflict with Yoast SEO or Rank Math? =

No. If WooCommerce, Yoast WooCommerce SEO or Rank Math already outputs a Product node, the plugin extends it instead of adding a second one.

= What happens to /llms.txt if FeedTrix is unavailable? =

The plugin serves the last valid copy of the file. If your FeedTrix subscription is inactive, the files are not served.

= Which customer data is sent? =

None. For "Sales from AI" the plugin sends only the order number, totals, currency and the AI source of the visit. See the Privacy and External services sections.

== Screenshots ==

1. Overview: connection status, features working in your store and a preview of how an AI assistant recommends your products.
2. Settings: store keys and switches for each feature.
3. The /llms.txt file served from the store's domain (a Polish demo store; the file is written in your store's language).

== Privacy ==

When "Sales from AI" is enabled, the plugin stores a first-party cookie `ft_ai_source` (30 days) for visitors who arrive from an AI assistant. It holds the source domain, the landing page path and the time of the visit. For paid orders of such visitors the plugin sends the order number, totals, currency and that visit source to FeedTrix. No customer data is sent. If the WP Consent API is active, the cookie is set only with consent for statistics. Developers can override this with the `feedtrix_attribution_allowed` filter.

== External services ==

This plugin connects to the FeedTrix API (https://api.feedtrix.com), operated by FeedTrix, to provide its features. Data is sent only after the store is connected with a Store Key and API key, and every request is signed with the store's API key.

* **Connecting the store** (WooCommerce → FeedTrix → Settings): the Store Key, the store URL and the WordPress administrator language are sent to verify the keys.
* **Product changes**: when a product is created, updated or deleted, the product ID, the changed fields and the current price and stock status are sent.
* **/llms.txt, /llms-full.txt and product structured data**: the plugin requests these files and the product's JSON-LD from FeedTrix when they are not in the local cache. The request contains the Store Key and the product ID.
* **AI bot statistics** (can be turned off): hourly counts of visits per AI bot name and visited product ID. No IP addresses or other personal data.
* **Sales from AI** (can be turned off): for paid orders of visitors who came from an AI assistant, the order number, totals, currency and the visit source (AI domain, landing page path, time of the visit). No customer data.
* **Connection test**: the FeedTrix dashboard can call /wp-json/feedtrix/status on your store; the plugin answers only to requests signed with your API key and sends a test webhook back.

FeedTrix terms of service: https://feedtrix.com/terms
FeedTrix privacy policy: https://feedtrix.com/privacy

== Changelog ==

= 1.1.3 =
* The FeedTrix API URL is no longer editable in the dashboard; local test environments set the FEEDTRIX_ENDPOINT constant in wp-config.php.

= 1.1.2 =
* PHP 7.4 compatibility: no PHP 8-only string functions.
* The connect notice is shown only on the Plugins screen and can be dismissed.

= 1.1.1 =
* When the FeedTrix subscription is inactive, /llms.txt and /llms-full.txt are no longer served (404) and the local backup copy is removed.
* External services: links to the FeedTrix terms of service and privacy policy.
* The FeedTrix free trial is now 14 days.

= 1.1.0 =
* Sales from AI: orders from customers who came through the FeedTrix MCP server (utm_medium=mcp) are attributed to MCP.
* /llms.txt lists the address of your store's MCP server for AI agents.

= 1.0.0 =
* First public release.
* /llms.txt and /llms-full.txt (table of contents with /llms-full/1.txt, /llms-full/2.txt… of up to 100 products each) served from your own domain, with current prices and stock.
* FeedTrix structured data (JSON-LD) on product pages: description for AI assistants, attributes and FAQ, merged with the Product data from WooCommerce, Yoast SEO or Rank Math. Optional questions and answers tab.
* Instant price and stock updates through signed webhooks; FeedTrix refreshes the local copies in the store when your content changes.
* AI bot statistics (GPTBot, PerplexityBot, ClaudeBot and more), sent to FeedTrix every hour, no personal data.
* Sales from AI: paid orders from customers who came from AI assistants in the last 30 days. The attribution cookie respects the WP Consent API.
* IndexNow key file for fast indexing in Bing and ChatGPT Search.
* Connect with keys from the FeedTrix dashboard; switches can also be changed from the dashboard.
* Translations: English, Polish, German, Spanish, French and Italian.
