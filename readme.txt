=== FeedTrix – AI Search, llms.txt, MCP & WebMCP for WooCommerce ===
Contributors: feedtrix
Tags: llms.txt, ai, chatgpt, woocommerce, webmcp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get your WooCommerce store found in ChatGPT and AI search: free AI readiness audit, llms.txt, WebMCP for AI agents, AI bot stats, sales from AI.

== Description ==

Make your WooCommerce store visible in ChatGPT, Google AI Overviews, Perplexity, Claude, Gemini and Copilot. FeedTrix is an AI search optimization (AEO / GEO) plugin: it runs a free AI readiness audit, serves llms.txt, adds AI-ready product structured data and WebMCP tools for AI shopping agents, and shows which sales came from AI.

More and more customers ask AI assistants what to buy. FeedTrix prepares your catalog so that AI assistants understand it, recommend your products and send buyers to your store.

**Free without an account:** the AI readiness audit, a basic /llms.txt built from your catalog, WebMCP tools for AI agents in the browser and AI search checks in Site Health. **With a FeedTrix account** (14-day free trial, then a paid plan): full /llms.txt and /llms-full.txt with AI descriptions, AI-ready structured data, an MCP server for AI agents, AI bot statistics, sales from AI and IndexNow.

= What the plugin does =

* **Free AI readiness audit** (no account needed): one click in WooCommerce → FeedTrix checks your store the way ChatGPT, Perplexity and Claude see it (llms.txt, AI bot access, product structured data, speed, WebMCP) and lists what to fix first.
* **Basic /llms.txt** (no account needed): store description, product categories and up to 50 products with current price and stock, built locally from your catalog and refreshed after every product change.
* **Full /llms.txt and /llms-full.txt** (with an account): your whole catalog with AI descriptions, current prices and stock, served from your own domain. A local cache and a fallback copy keep the files available even if the FeedTrix API is slow.
* **AI-ready structured data (JSON-LD)** on product pages: a description for AI assistants, attributes and questions with answers. It extends the Product data of WooCommerce, Yoast SEO or Rank Math instead of duplicating it. Price and availability always come from your store.
* **Questions and answers tab** on product pages (optional, off by default).
* **Instant updates**: price and stock changes are sent to FeedTrix right away (HMAC-signed webhooks), so AI assistants never see outdated prices.
* **AI bot statistics** (with an account): visits from GPTBot, PerplexityBot, ClaudeBot and other AI crawlers in a WordPress dashboard widget and in the FeedTrix dashboard, with no personal data.
* **Site Health checks** (no account needed): Tools → Site Health warns when robots.txt blocks ChatGPT, Perplexity, Claude or Gemini bots, and checks llms.txt and WebMCP.
* **Sales from AI**: paid orders from customers who came from ChatGPT, Perplexity, Claude, Gemini or Copilot in the last 30 days, in one report in the FeedTrix dashboard.
* **IndexNow**: the verification file (/{key}.txt) for fast indexing of changes in Bing and ChatGPT Search.
* **WebMCP tools for AI agents in the browser** (no account needed): AI agents built into the browser (such as Gemini in Chrome) can browse categories, search and compare products, read product details, check shipping and add to the cart through 11 store tools instead of clicking around the page. The tools run in the customer's own session through the WooCommerce Store API, no data is sent to FeedTrix, and the customer always confirms payment. Browsers without WebMCP ignore them.
* **MCP server for AI agents**: FeedTrix gives your store its own Model Context Protocol server. AI assistants and shopping agents (Claude, ChatGPT and others) can search your catalog, compare products and check live prices and stock. Its address is listed in /llms.txt; sales from it are counted in "Sales from AI".

Available in English, Polish, German, Spanish, French and Italian.

== Installation ==

1. Create an account at [panel.feedtrix.com](https://panel.feedtrix.com) and add your store.
2. Install and activate the plugin (Plugins → Add New, or upload the package downloaded from the FeedTrix dashboard).
3. A package downloaded from the FeedTrix dashboard is already connected. Otherwise go to WooCommerce → FeedTrix → Settings and paste the Store Key and API key from the dashboard.
4. Make sure there is no static llms.txt file in the WordPress root directory: the web server would serve it instead of the FeedTrix version.

== Frequently Asked Questions ==

= How do I get my WooCommerce store into ChatGPT? =

ChatGPT, Perplexity and other AI assistants recommend products they can read and trust: a clear catalog with current prices, product structured data, open access for AI bots in robots.txt and an llms.txt file. Run the free audit in WooCommerce → FeedTrix to see what your store is missing; FeedTrix fixes most of it automatically after you connect the store.

= What is llms.txt? =

llms.txt is a plain text file at the root of your site (yourstore.com/llms.txt) that tells AI assistants what the site is about, in a format that is easy for language models to read. FeedTrix generates it from your catalog with current prices and stock, plus /llms-full.txt with all products.

= What is WebMCP? =

WebMCP is a new browser standard (Chrome) that lets a website give AI agents built into the browser a list of tools. With FeedTrix, an agent such as Gemini in Chrome can browse categories, search and compare products, read details, check shipping and fill the cart through these tools instead of clicking around the page. The customer always reviews and pays on the checkout page. It works without a FeedTrix account.

= What is the difference between WebMCP and the MCP server? =

WebMCP tools work in the customer's browser while your store is open. The MCP server (with a FeedTrix account) works without a browser: AI assistants such as Claude or ChatGPT connect to it to search your catalog and check prices and stock.

= Is this SEO for AI (AEO / GEO)? =

Yes. Answer engine optimization (AEO) and generative engine optimization (GEO) mean making your content easy for AI assistants to find, understand and recommend. FeedTrix does this for WooCommerce product catalogs and measures the result: AI bot visits and sales from AI.

= Do I need a FeedTrix account? =

Not for the AI readiness audit, the basic /llms.txt, WebMCP tools and Site Health checks: they work right after installation. The full llms.txt with AI descriptions, llms-full.txt, structured data, the MCP server, AI bot statistics and sales from AI need a FeedTrix account; you can start with a 14-day free trial.

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
4. Free AI readiness audit in WooCommerce → FeedTrix: score, sections and what to fix first.
5. WebMCP: an AI agent in the browser searches and compares products and adds one to the cart through the store's tools.
6. MCP server: an AI assistant searches the catalog and checks live prices and stock.
7. Site Health: a warning when robots.txt blocks AI bots.
8. Dashboard widget: AI bot visits in the last 7 days (with a FeedTrix account).

== Privacy ==

When "Sales from AI" is enabled, the plugin stores a first-party cookie `ft_ai_source` (30 days) for visitors who arrive from an AI assistant. It holds the source domain, the landing page path and the time of the visit. For paid orders of such visitors the plugin sends the order number, totals, currency and that visit source to FeedTrix. No customer data is sent. If the WP Consent API is active, the cookie is set only with consent for statistics. Developers can override this with the `feedtrix_attribution_allowed` filter.

== External services ==

This plugin connects to the FeedTrix API (https://api.feedtrix.com), operated by FeedTrix, to provide its features. Data is sent only after the store is connected with a Store Key and API key, and every request is signed with the store's API key.

* **Free AI readiness audit** (only when you click "Run the audit"): your store's home page URL and the WordPress administrator language are sent to the public FeedTrix audit API, which then visits your store's public pages (home page, robots.txt, llms.txt, one product page) like an AI bot. No other data is sent.
* **Connecting the store** (WooCommerce → FeedTrix → Settings): the Store Key, the store URL and the WordPress administrator language are sent to verify the keys.
* **Product changes**: when a product is created, updated or deleted, the product ID, the changed fields and the current price and stock status are sent.
* **/llms.txt, /llms-full.txt and product structured data**: the plugin requests these files and the product's JSON-LD from FeedTrix when they are not in the local cache. The request contains the Store Key and the product ID.
* **AI bot statistics** (can be turned off): hourly counts of visits per AI bot name and visited product ID. No IP addresses or other personal data.
* **Sales from AI** (can be turned off): for paid orders of visitors who came from an AI assistant, the order number, totals, currency and the visit source (AI domain, landing page path, time of the visit). No customer data.
* **Connection test**: the FeedTrix dashboard can call /wp-json/feedtrix/status on your store; the plugin answers only to requests signed with your API key and sends a test webhook back.

FeedTrix terms of service: https://feedtrix.com/terms
FeedTrix privacy policy: https://feedtrix.com/privacy

== Changelog ==

= 1.2.0 =
* Free AI readiness audit in WooCommerce → FeedTrix, no account needed: score, sections and what to fix first.
* WebMCP: 11 tools for AI agents in the browser (categories, search products, compare products, product details, cart, coupons, shipping options, store policies, go to checkout). They use the WooCommerce Store API in the customer's session; the customer always confirms payment. Browsers without WebMCP ignore them. Works without a FeedTrix account; can be turned off in Settings.
* Sales from AI: carts filled by an AI agent through WebMCP are attributed to AI.
* After activation, one click on the Plugins screen runs the free audit.
* Basic /llms.txt without an account, built locally from the catalog (categories and up to 50 products with price and stock).
* Site Health: AI search checks (AI bots blocked in robots.txt, llms.txt, WebMCP).
* Dashboard widget with AI bot visits for connected stores.
* A request for a WordPress.org review on the FeedTrix screen only, after 50 AI bot visits or 14 days after installation.

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
