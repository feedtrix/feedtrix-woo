/**
 * FeedTrix WebMCP: store tools for AI agents in the browser (navigator.modelContext).
 * Calls the public WooCommerce Store API in the customer's own session. Without WebMCP support it does nothing.
 * The agent never pays: go_to_checkout only opens the checkout page, the customer confirms the order.
 */
(function () {
	'use strict';
	// document.modelContext (Chrome 150+); navigator.modelContext in early origin-trial builds
	var mc = document.modelContext || navigator.modelContext;
	var cfg = window.feedtrixWebMcp;
	if (!mc || !cfg || typeof mc.registerTool !== 'function') {
		return;
	}
	var nonce = cfg.nonce;

	function api(path, method, body) {
		var opts = { method: method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.headers.Nonce = nonce;
			opts.body = JSON.stringify(body);
		}
		return fetch(cfg.api + path, opts).then(function (r) {
			nonce = r.headers.get('Nonce') || nonce;
			return r.json().then(function (data) {
				if (!r.ok) {
					throw new Error(text(data && data.message) || 'HTTP ' + r.status);
				}
				return data;
			});
		});
	}

	function money(prices, field) {
		if (!prices || prices[field] === undefined || prices[field] === '') {
			return null;
		}
		var unit = Math.pow(10, prices.currency_minor_unit || 0);
		return Math.round(parseInt(prices[field], 10)) / unit;
	}

	function text(html) {
		var d = document.createElement('div');
		d.innerHTML = html || '';
		return (d.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function product(p, full) {
		var out = {
			id: p.id,
			name: text(p.name),
			url: p.permalink,
			price: money(p.prices, 'price'),
			regular_price: money(p.prices, 'regular_price'),
			currency: p.prices ? p.prices.currency_code : cfg.currency,
			in_stock: p.is_in_stock,
			purchasable: p.is_purchasable,
			rating: p.review_count ? Number(p.average_rating) : null,
			reviews: p.review_count || 0,
			has_variants: !!(p.variations && p.variations.length),
			image: p.images && p.images[0] ? p.images[0].src : null,
			summary: text(p.short_description).slice(0, 160)
		};
		if (full) {
			out.description = text(p.description).slice(0, 3000);
			out.sku = p.sku || null;
			out.categories = (p.categories || []).map(function (c) { return text(c.name); });
			out.attributes = (p.attributes || []).map(function (a) {
				return { name: a.name, values: (a.terms || []).map(function (t) { return t.name; }), variation: !!a.has_variations };
			});
			out.variants = (p.variations || []).map(function (v) {
				return { id: v.id, attributes: (v.attributes || []).map(function (a) { return { attribute: a.name, value: a.value }; }) };
			});
		}
		return out;
	}

	function cart(c) {
		return {
			items: (c.items || []).map(function (i) {
				return { key: i.key, id: i.id, name: text(i.name), quantity: i.quantity, total: money(i.totals, 'line_total') };
			}),
			items_count: c.items_count,
			coupons: (c.coupons || []).map(function (x) { return x.code; }),
			total: money(c.totals, 'total_price'),
			currency: c.totals ? c.totals.currency_code : cfg.currency,
			needs_shipping: c.needs_shipping,
			shipping_options: (c.shipping_rates || []).reduce(function (all, pkg) {
				return all.concat((pkg.shipping_rates || []).map(function (r) {
					return { id: r.rate_id, name: text(r.name), price: money({ price: r.price, currency_minor_unit: r.currency_minor_unit }, 'price'), selected: r.selected, delivery_time: r.delivery_time ? text(r.delivery_time.value || r.delivery_time) : null };
				}));
			}, []),
			cart_url: cfg.cart,
			checkout_url: cfg.checkout
		};
	}

	// cart changed by the agent: refresh mini-cart (classic themes and blocks) and mark the visit for AI sales attribution
	function changed(data) {
		try {
			if (window.jQuery) {
				window.jQuery(document.body).trigger('wc_fragment_refresh');
			}
			document.body.dispatchEvent(new CustomEvent('wc-blocks_added_to_cart', { bubbles: true }));
		} catch (e) { /* ignore */ }
		if (cfg.track && document.cookie.indexOf(cfg.cookie + '=') === -1) {
			var v = JSON.stringify({ source: 'feedtrix', medium: 'webmcp', landing_page: location.pathname.slice(0, 200), timestamp: Math.floor(Date.now() / 1000) });
			document.cookie = cfg.cookie + '=' + encodeURIComponent(v) + ';path=/;max-age=' + 30 * 86400 + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
		}
		return cart(data);
	}

	// tool output is text for the model (JSON, max ~1.5K recommended by Chrome)
	function result(data) {
		return JSON.stringify(data);
	}

	function tool(name, description, schema, run, readOnly) {
		return {
			name: name,
			description: description,
			inputSchema: schema,
			annotations: { readOnlyHint: !!readOnly },
			execute: function (args) {
				return Promise.resolve()
					.then(function () { return run(args || {}); })
					.then(result, function (e) { return result({ error: String(e && e.message ? e.message : e) }); });
			}
		};
	}

	var store = cfg.store ? ' in the store "' + cfg.store + '"' : '';
	var tools = [
		tool('search_products', 'Search products' + store + ' by keywords, price range (in ' + cfg.currency + ') and availability. Returns name, price, stock, rating and product URL.', {
			type: 'object',
			properties: {
				query: { type: 'string', description: 'Keywords, e.g. "trail running shoes"' },
				min_price: { type: 'number' },
				max_price: { type: 'number' },
				in_stock_only: { type: 'boolean', default: true },
				order_by: { type: 'string', enum: ['relevance', 'price', 'popularity', 'rating', 'date'] },
				order: { type: 'string', enum: ['asc', 'desc'] },
				limit: { type: 'integer', minimum: 1, maximum: 20, default: 5 }
			}
		}, function (a) {
			var q = new URLSearchParams({ per_page: String(Math.min(20, Math.max(1, a.limit || 5))) });
			if (a.query) { q.set('search', a.query); }
			if (a.min_price !== undefined) { q.set('min_price', String(Math.round(a.min_price * Math.pow(10, cfg.decimals)))); }
			if (a.max_price !== undefined) { q.set('max_price', String(Math.round(a.max_price * Math.pow(10, cfg.decimals)))); }
			if (a.in_stock_only !== false) { q.append('stock_status[]', 'instock'); }
			if (a.order_by && a.order_by !== 'relevance') { q.set('orderby', a.order_by); q.set('order', a.order || (a.order_by === 'price' ? 'asc' : 'desc')); }
			return api('products?' + q).then(function (list) {
				return { products: list.map(function (p) { return product(p, false); }) };
			});
		}, true),

		tool('get_product', 'Full details of one product: description, attributes, variants (sizes, colours) with their ids, price and stock.', {
			type: 'object',
			properties: { id: { type: 'integer', description: 'Product id from search_products' } },
			required: ['id']
		}, function (a) {
			return api('products/' + encodeURIComponent(a.id)).then(function (p) { return product(p, true); });
		}, true),

		tool('get_categories', 'Product categories' + store + ' with the number of products in each. Use a category id in search_products to browse it.', {
			type: 'object', properties: {}
		}, function () {
			return api('products/categories?per_page=100').then(function (list) {
				return {
					categories: list.filter(function (c) { return c.count > 0; }).map(function (c) {
						return { id: c.id, name: text(c.name), parent: c.parent || null, products: c.count, url: c.permalink || null };
					})
				};
			});
		}, true),

		tool('compare_products', 'Compare 2 to 4 products side by side: price, stock, rating and attributes (size, material, colour…). Use product ids from search_products.', {
			type: 'object',
			properties: { ids: { type: 'array', items: { type: 'integer' }, minItems: 2, maxItems: 4, description: 'Product ids' } },
			required: ['ids']
		}, function (a) {
			var ids = (a.ids || []).slice(0, 4);
			if (ids.length < 2) {
				throw new Error('Pass 2 to 4 product ids.');
			}
			return Promise.all(ids.map(function (id) { return api('products/' + encodeURIComponent(id)); })).then(function (list) {
				var names = {};
				list.forEach(function (p) { (p.attributes || []).forEach(function (at) { names[at.name] = true; }); });
				return {
					products: list.map(function (p) {
						var attrs = {};
						(p.attributes || []).forEach(function (at) { attrs[at.name] = (at.terms || []).map(function (t) { return t.name; }).join(', '); });
						return { id: p.id, name: text(p.name), url: p.permalink, price: money(p.prices, 'price'), currency: p.prices ? p.prices.currency_code : cfg.currency, in_stock: p.is_in_stock, rating: p.review_count ? Number(p.average_rating) : null, reviews: p.review_count || 0, attributes: attrs };
					}),
					compared_attributes: Object.keys(names)
				};
			});
		}, true),

		tool('add_to_cart', 'Add a product to the customer\'s cart. For a product with variants pass variant_id from get_product.', {
			type: 'object',
			properties: {
				id: { type: 'integer', description: 'Product id' },
				variant_id: { type: 'integer', description: 'Variant id (optional)' },
				quantity: { type: 'integer', minimum: 1, default: 1 }
			},
			required: ['id']
		}, function (a) {
			return api('cart/add-item', 'POST', { id: a.variant_id || a.id, quantity: a.quantity || 1 }).then(changed);
		}),

		tool('get_cart', 'Current cart: items, total, coupons and shipping options.', { type: 'object', properties: {} }, function () {
			return api('cart').then(cart);
		}, true),

		tool('update_cart_item', 'Change the quantity of a cart item (0 removes it). Use the item key from get_cart.', {
			type: 'object',
			properties: { key: { type: 'string' }, quantity: { type: 'integer', minimum: 0 } },
			required: ['key', 'quantity']
		}, function (a) {
			return a.quantity > 0
				? api('cart/update-item', 'POST', { key: a.key, quantity: a.quantity }).then(changed)
				: api('cart/remove-item', 'POST', { key: a.key }).then(changed);
		}),

		tool('apply_coupon', 'Apply a discount code to the cart.', {
			type: 'object', properties: { code: { type: 'string' } }, required: ['code']
		}, function (a) {
			return api('cart/apply-coupon', 'POST', { code: a.code }).then(changed);
		}),

		tool('shipping_options', 'Shipping methods and costs for the current cart. Optionally pass the delivery country (ISO code, e.g. PL) and postcode.', {
			type: 'object', properties: { country: { type: 'string' }, postcode: { type: 'string' }, city: { type: 'string' } }
		}, function (a) {
			if (!a.country) {
				return api('cart').then(function (c) { return { shipping_options: cart(c).shipping_options }; });
			}
			return api('cart/update-customer', 'POST', { shipping_address: { country: a.country, postcode: a.postcode || '', city: a.city || '' } })
				.then(function (c) { return { shipping_options: cart(c).shipping_options }; });
		}),

		tool('store_policies', 'Store policy pages: returns, terms and privacy (URLs to read).', { type: 'object', properties: {} }, function () {
			return { store: cfg.store, currency: cfg.currency, pages: cfg.pages };
		}, true),

		tool('go_to_checkout', 'Open the checkout page so the customer can review the order and pay. The agent never places or pays for the order.', { type: 'object', properties: {} }, function () {
			setTimeout(function () { location.href = cfg.checkout; }, 50);
			return { checkout_url: cfg.checkout, note: 'The customer confirms and pays on the checkout page.' };
		})
	];

	// one tool at a time: a name already registered (e.g. by another script) throws, the rest still register
	tools.forEach(function (t) {
		try {
			Promise.resolve(mc.registerTool(t)).catch(function () {});
		} catch (e) { /* name taken or API changed */ }
	});
})();
