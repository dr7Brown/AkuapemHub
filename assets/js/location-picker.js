/**
 * Location & Mapping Module — AkuapemHub
 * Provides LocationPicker (form modal) and MapViewer (display component).
 * Uses Leaflet.js + OpenStreetMap; Leaflet is lazy-loaded on first use.
 */
(function () {
    'use strict';

    var API_URL      = window.LOCATION_API    || 'location_api.php';
    var ASSETS_BASE  = window.LOCATION_ASSETS || 'assets/';
    var LEAFLET_CSS  = ASSETS_BASE + 'css/leaflet.css';
    var LEAFLET_JS   = ASSETS_BASE + 'js/leaflet.js';

    // Default map center: Akuapem / Eastern Region, Ghana
    var DEFAULT_CENTER = [5.85, -0.10];
    var DEFAULT_ZOOM   = 11;

    // ── Leaflet lazy loader ──────────────────────────────────────────────────
    var _leafletPromise = null;

    function loadLeaflet() {
        if (typeof L !== 'undefined') return Promise.resolve();
        if (_leafletPromise) return _leafletPromise;
        _leafletPromise = new Promise(function (resolve, reject) {
            if (!document.querySelector('link[data-leaflet]')) {
                var link = document.createElement('link');
                link.rel          = 'stylesheet';
                link.href         = LEAFLET_CSS;
                link.dataset.leaflet = '1';
                document.head.appendChild(link);
            }
            var script    = document.createElement('script');
            script.src    = LEAFLET_JS;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return _leafletPromise;
    }

    // ── HTTP helper ──────────────────────────────────────────────────────────
    function apiPost(data) {
        return fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString(),
        }).then(function (r) { return r.json(); }).catch(function () { return {}; });
    }

    // ── HTML escape ──────────────────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ════════════════════════════════════════════════════════════════════════
    // LocationPicker — modal for selecting and saving a location inside a form
    // ════════════════════════════════════════════════════════════════════════
    function LocationPicker(btn) {
        this.btn   = btn;
        this.form  = btn.closest('form');
        if (!this.form) return;

        // Which form fields to fill on confirm
        var fn = btn.dataset.fieldName    || 'venue';
        var fa = btn.dataset.fieldAddress || 'gps_address';
        var fl = btn.dataset.fieldLat     || '';
        var fg = btn.dataset.fieldLng     || '';

        this._idInput   = this.form.querySelector('input[name="location_id"]');
        this._nameInput = this.form.querySelector('input[name="' + fn + '"]');
        this._addrInput = fa ? this.form.querySelector('input[name="' + fa + '"]') : null;
        this._latInput  = fl ? this.form.querySelector('input[name="' + fl + '"]') : null;
        this._lngInput  = fg ? this.form.querySelector('input[name="' + fg + '"]') : null;

        this._modal   = null;
        this._map     = null;
        this._marker  = null;
        this._pending = null;

        // Create or find preview element
        this._preview = this.form.querySelector('.loc-preview[data-for="' + btn.dataset.previewFor + '"]')
                     || this.form.querySelector('.loc-preview');
        if (!this._preview) {
            this._preview = document.createElement('div');
            this._preview.className = 'loc-preview';
            btn.parentNode.insertBefore(this._preview, btn);
        }

        // Pre-populate preview from existing saved location
        var self = this;
        var existId = parseInt((self._idInput && self._idInput.value) || '0', 10);
        if (existId) {
            apiPost({ action: 'get', id: existId }).then(function (r) {
                if (r.location) self._showPreview(r.location.location_name, r.location.formatted_address);
            });
        }

        btn.addEventListener('click', function () { self.open(); });
    }

    LocationPicker.prototype.open = function () {
        var self = this;
        loadLeaflet().then(function () {
            if (!self._modal) self._buildModal();
            if (!self._modal.parentNode) document.body.appendChild(self._modal);
            self._modal.classList.add('lm-open');
            // _initMap must run AFTER the modal is in the DOM and visible so
            // Leaflet can measure the container's real pixel dimensions.
            if (!self._map) {
                self._initMap();
            } else {
                setTimeout(function () { self._map.invalidateSize(); }, 80);
            }
        }).catch(function () {
            alert('Could not load the map library. Please check your internet connection and try again.');
        });
    };

    LocationPicker.prototype._buildModal = function () {
        var self  = this;
        var modal = document.createElement('div');
        modal.className = 'lm-overlay';
        modal.innerHTML =
            '<div class="lm-modal" role="dialog" aria-modal="true" aria-label="Pick a location">' +
                '<div class="lm-hd">' +
                    '<span class="lm-hd-title">📍 Pick a Location</span>' +
                    '<button type="button" class="lm-x" aria-label="Close">&#x2715;</button>' +
                '</div>' +
                '<div class="lm-sb-wrap">' +
                    '<input class="lm-sb" type="text" placeholder="Search place, street, landmark…" autocomplete="off" spellcheck="false">' +
                    '<div class="lm-sr"></div>' +
                '</div>' +
                '<div class="lm-map-area"></div>' +
                '<div class="lm-ft">' +
                    '<div class="lm-ft-info">' +
                        '<p class="lm-ft-name">Tap the map or search to select</p>' +
                        '<p class="lm-ft-addr"></p>' +
                    '</div>' +
                    '<div class="lm-ft-btns">' +
                        '<button type="button" class="lm-btn-my button button-secondary button-small">📍 My Location</button>' +
                        '<button type="button" class="lm-btn-ok button button-primary" disabled>Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        this._modal  = modal;
        this._mapDiv = modal.querySelector('.lm-map-area');

        // ── Search ───────────────────────────────────────────────────────────
        var searchInput = modal.querySelector('.lm-sb');
        var resultsDiv  = modal.querySelector('.lm-sr');
        var searchTimer;

        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            if (q.length < 2) { resultsDiv.innerHTML = ''; resultsDiv.classList.remove('lm-sr-open'); return; }
            searchTimer = setTimeout(function () {
                apiPost({ action: 'search', q: q }).then(function (r) {
                    resultsDiv.innerHTML = '';
                    var items = r.results || [];
                    if (!items.length) { resultsDiv.classList.remove('lm-sr-open'); return; }
                    items.forEach(function (item) {
                        var el = document.createElement('div');
                        el.className   = 'lm-sr-item';
                        el.textContent = item.display_name;
                        el.addEventListener('click', function () {
                            self._pin(item.lat, item.lng, item.name, item.display_name);
                            self._map.setView([item.lat, item.lng], 16);
                            resultsDiv.innerHTML = '';
                            resultsDiv.classList.remove('lm-sr-open');
                            searchInput.value = '';
                        });
                        resultsDiv.appendChild(el);
                    });
                    resultsDiv.classList.add('lm-sr-open');
                });
            }, 400);
        });

        // Close results when clicking elsewhere
        document.addEventListener('click', function (e) {
            if (!modal.contains(e.target)) { resultsDiv.innerHTML = ''; resultsDiv.classList.remove('lm-sr-open'); }
        });

        // ── My Location ──────────────────────────────────────────────────────
        modal.querySelector('.lm-btn-my').addEventListener('click', function () {
            if (!navigator.geolocation) { alert('Geolocation is not supported by your browser.'); return; }
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude, lng = pos.coords.longitude;
                    self._map.setView([lat, lng], 17);
                    self._pin(lat, lng, 'My Location', '');
                    self._reverseGeocode(lat, lng);
                },
                function () { alert('Could not access your location. Check browser permissions.'); }
            );
        });

        // ── Confirm ──────────────────────────────────────────────────────────
        modal.querySelector('.lm-btn-ok').addEventListener('click', function () { self._confirm(); });

        // ── Close ────────────────────────────────────────────────────────────
        modal.querySelector('.lm-x').addEventListener('click', function () { self._close(); });
        modal.addEventListener('pointerdown', function (e) { if (e.target === modal) self._close(); });
    };

    LocationPicker.prototype._initMap = function () {
        var self = this;
        var map = L.map(this._mapDiv, { zoomControl: true }).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);
        this._map = map;

        // Load existing pin if editing
        var existId = parseInt((this._idInput && this._idInput.value) || '0', 10);
        if (existId) {
            apiPost({ action: 'get', id: existId }).then(function (r) {
                if (r.location) {
                    var lat = parseFloat(r.location.latitude);
                    var lng = parseFloat(r.location.longitude);
                    self._pin(lat, lng, r.location.location_name, r.location.formatted_address);
                    map.setView([lat, lng], 15);
                }
            });
        }

        // Map click → pin + reverse geocode
        map.on('click', function (e) {
            var lat = e.latlng.lat, lng = e.latlng.lng;
            self._pin(lat, lng, lat.toFixed(5) + ', ' + lng.toFixed(5), '');
            self._reverseGeocode(lat, lng);
        });

        // Force layout recalculation now that the container is visible
        setTimeout(function () { map.invalidateSize(); }, 50);
    };

    LocationPicker.prototype._pin = function (lat, lng, name, address) {
        if (this._marker) {
            this._marker.setLatLng([lat, lng]);
        } else {
            var self = this;
            this._marker = L.marker([lat, lng], { draggable: true }).addTo(this._map);
            this._marker.on('dragend', function (e) {
                var pos = e.target.getLatLng();
                self._pin(pos.lat, pos.lng, (self._pending && self._pending.name) || '', '');
                self._reverseGeocode(pos.lat, pos.lng);
            });
        }
        this._pending = { lat: lat, lng: lng, name: name, address: address };
        this._modal.querySelector('.lm-ft-name').textContent = name || lat.toFixed(5) + ', ' + lng.toFixed(5);
        this._modal.querySelector('.lm-ft-addr').textContent = address;
        this._modal.querySelector('.lm-btn-ok').disabled = false;
    };

    LocationPicker.prototype._reverseGeocode = function (lat, lng) {
        var self = this;
        apiPost({ action: 'reverse', lat: lat, lng: lng }).then(function (r) {
            if (r.display_name) {
                var name = r.name || r.display_name.split(',')[0].trim();
                self._pin(lat, lng, name, r.display_name);
            }
        });
    };

    LocationPicker.prototype._confirm = function () {
        if (!this._pending) return;
        var self = this;
        var btn  = this._modal.querySelector('.lm-btn-ok');
        btn.disabled    = true;
        btn.textContent = 'Saving…';

        var csrf = (this.form.querySelector('input[name="csrf_token"]') || {}).value || '';
        apiPost({
            action:     'save',
            lat:        this._pending.lat,
            lng:        this._pending.lng,
            name:       this._pending.name,
            address:    this._pending.address,
            csrf_token: csrf,
        }).then(function (r) {
            btn.disabled    = false;
            btn.textContent = 'Confirm';
            if (r.error) { alert('Location error: ' + r.error); return; }

            if (self._idInput)   self._idInput.value   = r.id;
            if (self._nameInput) { self._nameInput.removeAttribute('readonly'); self._nameInput.value = r.name; }
            if (self._addrInput) { self._addrInput.removeAttribute('readonly'); self._addrInput.value = r.address; }
            if (self._latInput)  self._latInput.value  = r.lat;
            if (self._lngInput)  self._lngInput.value  = r.lng;

            self._showPreview(r.name, r.address);
            self._close();
        });
    };

    LocationPicker.prototype._showPreview = function (name, address) {
        this._preview.innerHTML =
            '<span class="loc-pin-ico">📍</span>' +
            '<span class="loc-pin-nm">' + esc(name) + '</span>' +
            (address ? '<span class="loc-pin-addr">' + esc(address) + '</span>' : '');
        this._preview.classList.add('loc-has-pin');
        this.btn.innerHTML = '✏️ Change Location on Map';
    };

    LocationPicker.prototype._close = function () {
        this._modal.classList.remove('lm-open');
    };

    // ════════════════════════════════════════════════════════════════════════
    // MapViewer — display-only embedded map with directions
    // Usage: <div data-map-viewer data-lat="5.85" data-lng="-0.1"
    //              data-name="Venue" data-address="Full address"
    //              data-gm-url="https://..." data-osm-url="https://..."></div>
    // ════════════════════════════════════════════════════════════════════════
    function MapViewer(el) {
        this.el      = el;
        this.lat     = parseFloat(el.dataset.lat  || '0');
        this.lng     = parseFloat(el.dataset.lng  || '0');
        this.name    = el.dataset.name    || '';
        this.address = el.dataset.address || '';
        this.gmUrl   = el.dataset.gmUrl   || ('https://www.google.com/maps?q=' + encodeURIComponent(this.lat + ',' + this.lng));
        if (!this.lat || !this.lng) return;
        var self = this;
        loadLeaflet().then(function () { self._init(); });
    }

    MapViewer.prototype._init = function () {
        var el  = this.el;
        var lat = this.lat, lng = this.lng;

        // Map container
        var mapDiv = document.createElement('div');
        mapDiv.style.cssText = 'height:' + (el.dataset.height || '240px') + ';border-radius:12px;overflow:hidden;';
        el.appendChild(mapDiv);

        var map = L.map(mapDiv, { scrollWheelZoom: false }).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(map);

        var popup = '<strong>' + esc(this.name) + '</strong>' +
            (this.address ? '<br><span style="font-size:.8rem;color:#6b7280">' + esc(this.address) + '</span>' : '');
        L.marker([lat, lng]).addTo(map).bindPopup(popup).openPopup();

        // Directions buttons
        var waze   = 'https://waze.com/ul?ll=' + encodeURIComponent(lat + ',' + lng) + '&navigate=yes';
        var apple  = 'https://maps.apple.com/?q=' + encodeURIComponent(lat + ',' + lng);
        var dirDiv = document.createElement('div');
        dirDiv.className = 'loc-dirs';
        dirDiv.innerHTML =
            '<a href="' + esc(this.gmUrl) + '" target="_blank" rel="noopener" class="loc-dir-btn loc-dir-gm">Google Maps</a>' +
            '<a href="' + esc(apple) + '" target="_blank" rel="noopener" class="loc-dir-btn loc-dir-apple">Apple Maps</a>' +
            '<a href="' + esc(waze)  + '" target="_blank" rel="noopener" class="loc-dir-btn loc-dir-waze">Waze</a>';
        el.appendChild(dirDiv);
    };

    // ════════════════════════════════════════════════════════════════════════
    // NearbyWidget — "Events / Jobs near me" widget
    // Usage: <div data-nearby-widget data-module="events" data-radius="10"></div>
    // ════════════════════════════════════════════════════════════════════════
    function NearbyWidget(el) {
        this.el     = el;
        this.module = el.dataset.module || 'events';
        this.radius = parseInt(el.dataset.radius || '10', 10);
        this._render();
    }

    NearbyWidget.prototype._render = function () {
        var self = this;
        var el   = this.el;
        el.innerHTML =
            '<button type="button" class="loc-nearby-btn button button-secondary button-small">📍 Show Near Me</button>' +
            '<div class="loc-nearby-results"></div>';

        el.querySelector('.loc-nearby-btn').addEventListener('click', function () {
            if (!navigator.geolocation) { el.querySelector('.loc-nearby-results').textContent = 'Geolocation not supported.'; return; }
            var btn = el.querySelector('.loc-nearby-btn');
            btn.disabled    = true;
            btn.textContent = 'Locating…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                btn.disabled    = false;
                btn.textContent = '📍 Show Near Me';
                self._fetch(pos.coords.latitude, pos.coords.longitude);
            }, function () {
                btn.disabled    = false;
                btn.textContent = '📍 Show Near Me';
                el.querySelector('.loc-nearby-results').textContent = 'Location access denied.';
            });
        });
    };

    NearbyWidget.prototype._fetch = function (lat, lng) {
        var self = this;
        var res  = this.el.querySelector('.loc-nearby-results');
        res.innerHTML = '<p style="color:var(--muted,#6b7280);font-size:.85rem;">Searching within ' + this.radius + ' km…</p>';
        apiPost({ action: 'nearby', lat: lat, lng: lng, radius: this.radius, module: this.module }).then(function (r) {
            var items = r.results || [];
            if (!items.length) { res.innerHTML = '<p style="color:var(--muted);font-size:.85rem;">No results found nearby.</p>'; return; }
            res.innerHTML = '<ul class="loc-nearby-list">' + items.map(function (item) {
                return '<li class="loc-nearby-item">' +
                    '<span class="loc-nearby-name">' + esc(item.title) + '</span>' +
                    '<span class="loc-nearby-dist">' + item.distance_km + ' km away</span>' +
                    '<span class="loc-nearby-addr">' + esc(item.location_name || item.formatted_address) + '</span>' +
                '</li>';
            }).join('') + '</ul>';
        });
    };

    // ── Auto-init ────────────────────────────────────────────────────────────
    function initAll(root) {
        root.querySelectorAll('[data-location-picker]:not([data-lm-init])').forEach(function (el) {
            el.dataset.lmInit = '1';
            new LocationPicker(el);
        });
        root.querySelectorAll('[data-map-viewer]:not([data-lm-init])').forEach(function (el) {
            el.dataset.lmInit = '1';
            new MapViewer(el);
        });
        root.querySelectorAll('[data-nearby-widget]:not([data-lm-init])').forEach(function (el) {
            el.dataset.lmInit = '1';
            new NearbyWidget(el);
        });
    }

    document.addEventListener('DOMContentLoaded', function () { initAll(document); });

    // Watch for AJAX-injected content (admin panel, dynamic pages)
    new MutationObserver(function (muts) {
        muts.forEach(function (m) {
            m.addedNodes.forEach(function (n) { if (n.nodeType === 1) initAll(n); });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    // Public API
    window.LocationPicker        = LocationPicker;
    window.MapViewer             = MapViewer;
    window.NearbyWidget          = NearbyWidget;
    window.initLocationComponents = initAll;
})();
