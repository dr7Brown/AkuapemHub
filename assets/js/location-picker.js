/*!
 * LocationPicker — Leaflet.js powered location picker widget.
 *
 * Requires Leaflet CSS + JS to be loaded on the page:
 *   <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
 *   <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
 *
 * Usage:
 *   new LocationPicker({
 *     container:    '#map-div',         // map container element or selector
 *     searchInput:  '#venue',           // text input for location name / venue
 *     latInput:     '#loc_lat',         // hidden input for latitude
 *     lngInput:     '#loc_lng',         // hidden input for longitude
 *     addressInput: '#gps_address',     // (optional) input for full address
 *     mapsLinkInput:'#google_maps_link',// (optional) auto-generates Google Maps URL
 *     defaultLat:   5.773,             // initial map center (Akropong)
 *     defaultLng:  -0.101,
 *     zoom:         13,
 *     apiBase:      '/location_api.php',
 *     onSelect:     function(data) {}  // {name, address, lat, lng, googleUrl}
 *   });
 */
(function (global) {
    'use strict';

    function el(sel) {
        return typeof sel === 'string' ? document.querySelector(sel) : sel;
    }

    function LocationPicker(opts) {
        this.opts       = Object.assign({
            defaultLat:   5.773,
            defaultLng:  -0.101,
            zoom:         13,
            apiBase:      '/location_api.php',
            onSelect:     null,
        }, opts);

        this._container     = el(this.opts.container);
        this._searchInput   = el(this.opts.searchInput);
        this._latInput      = el(this.opts.latInput);
        this._lngInput      = el(this.opts.lngInput);
        this._addressInput  = el(this.opts.addressInput);
        this._mapsLinkInput = el(this.opts.mapsLinkInput);

        if (!this._container) return;

        this._buildSearchUI();
        this._initMap();
    }

    // ── Build search input + suggestions dropdown ──────────────────────────────
    LocationPicker.prototype._buildSearchUI = function () {
        var self = this;

        // Wrap the search input in a relative container for the dropdown
        if (this._searchInput) {
            var wrap = document.createElement('div');
            wrap.style.cssText = 'position:relative;';
            this._searchInput.parentNode.insertBefore(wrap, this._searchInput);
            wrap.appendChild(this._searchInput);

            var list = document.createElement('ul');
            list.className = 'lp-suggestions';
            list.style.cssText = [
                'position:absolute;top:100%;left:0;right:0;z-index:10000;',
                'background:#fff;border:1px solid #e5e7eb;border-top:none;',
                'border-radius:0 0 8px 8px;',
                'max-height:220px;overflow-y:auto;margin:0;padding:0;',
                'list-style:none;box-shadow:0 4px 16px rgba(0,0,0,.1);display:none;',
            ].join('');
            wrap.appendChild(list);
            this._suggestList = list;

            var timer;
            this._searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                var q = this.value.trim();
                if (q.length < 3) { list.style.display = 'none'; return; }
                timer = setTimeout(function () { self._search(q); }, 350);
            });

            this._searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') list.style.display = 'none';
            });

            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) list.style.display = 'none';
            });
        }
    };

    // ── Nominatim search via local proxy ───────────────────────────────────────
    LocationPicker.prototype._search = function (q) {
        var self = this;
        fetch(this.opts.apiBase + '?action=search&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (results) {
                self._showSuggestions(results);
            })
            .catch(function () {});
    };

    LocationPicker.prototype._showSuggestions = function (results) {
        var self = this;
        var list = this._suggestList;
        if (!list) return;
        list.innerHTML = '';

        if (!results || !results.length) {
            list.style.display = 'none';
            return;
        }

        results.forEach(function (r) {
            var li = document.createElement('li');
            li.style.cssText = 'padding:9px 12px;cursor:pointer;font-size:.84rem;border-bottom:1px solid #f1f5f9;line-height:1.4;';
            li.textContent = r.display_name || r.name;
            li.addEventListener('mouseenter', function () { this.style.background = '#f0fdf4'; });
            li.addEventListener('mouseleave', function () { this.style.background = ''; });
            li.addEventListener('click', function () {
                list.style.display = 'none';
                self._selectLocation({
                    name:    r.name || r.display_name,
                    address: r.display_name,
                    lat:     r.lat,
                    lng:     r.lng,
                });
            });
            list.appendChild(li);
        });

        list.style.display = 'block';
    };

    // ── Init Leaflet map ───────────────────────────────────────────────────────
    LocationPicker.prototype._initMap = function () {
        var self    = this;
        var initLat = parseFloat(this._latInput && this._latInput.value) || this.opts.defaultLat;
        var initLng = parseFloat(this._lngInput && this._lngInput.value) || this.opts.defaultLng;

        this._map = L.map(this._container, { scrollWheelZoom: false }).setView([initLat, initLng], this.opts.zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(this._map);

        // Place initial marker if coordinates already set
        if (this._latInput && this._latInput.value && this._lngInput && this._lngInput.value) {
            this._marker = L.marker([initLat, initLng], { draggable: true }).addTo(this._map);
            this._bindMarkerDrag();
        }

        // Click on map to place / move marker
        this._map.on('click', function (e) {
            self._placeMarker(e.latlng.lat, e.latlng.lng);
            self._reverseGeocode(e.latlng.lat, e.latlng.lng);
        });

        // Resize fix when map is inside a tab/hidden panel
        setTimeout(function () { self._map.invalidateSize(); }, 200);
    };

    LocationPicker.prototype._placeMarker = function (lat, lng) {
        if (this._marker) {
            this._marker.setLatLng([lat, lng]);
        } else {
            this._marker = L.marker([lat, lng], { draggable: true }).addTo(this._map);
            this._bindMarkerDrag();
        }
        this._map.panTo([lat, lng]);
    };

    LocationPicker.prototype._bindMarkerDrag = function () {
        var self = this;
        this._marker.on('dragend', function (e) {
            var ll = e.target.getLatLng();
            self._reverseGeocode(ll.lat, ll.lng);
        });
    };

    // ── Reverse geocode via proxy ──────────────────────────────────────────────
    LocationPicker.prototype._reverseGeocode = function (lat, lng) {
        var self = this;
        fetch(this.opts.apiBase + '?action=reverse&lat=' + lat + '&lng=' + lng)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                self._selectLocation({
                    name:    data.name    || data.display_name,
                    address: data.address || data.display_name,
                    lat:     lat,
                    lng:     lng,
                });
            })
            .catch(function () {
                // Fallback: just store coordinates without an address label
                self._selectLocation({ name: '', address: '', lat: lat, lng: lng });
            });
    };

    // ── Commit selected location ───────────────────────────────────────────────
    LocationPicker.prototype._selectLocation = function (data) {
        var lat = parseFloat(data.lat);
        var lng = parseFloat(data.lng);

        // Update hidden inputs
        if (this._latInput)  this._latInput.value  = lat;
        if (this._lngInput)  this._lngInput.value  = lng;

        // Update visible text inputs
        if (this._searchInput && !this._searchInput.value.trim() && data.name) {
            this._searchInput.value = data.name;
        }
        if (this._addressInput && data.address) {
            this._addressInput.value = data.address;
        }
        if (this._mapsLinkInput && (!this._mapsLinkInput.value || this._mapsLinkInput.dataset.autoGenerated)) {
            this._mapsLinkInput.value = 'https://maps.google.com/?q=' + lat + ',' + lng;
            this._mapsLinkInput.dataset.autoGenerated = '1';
        }

        // Move map + marker
        this._placeMarker(lat, lng);
        this._map.setView([lat, lng], Math.max(this._map.getZoom(), 15));

        // Callback
        var googleUrl = 'https://maps.google.com/?q=' + lat + ',' + lng;
        if (typeof this.opts.onSelect === 'function') {
            this.opts.onSelect({ name: data.name, address: data.address, lat: lat, lng: lng, googleUrl: googleUrl });
        }

        // Update status badge if present
        var badge = this._container.parentElement && this._container.parentElement.querySelector('.lp-status');
        if (badge) badge.textContent = data.address || (lat.toFixed(5) + ', ' + lng.toFixed(5));
    };

    // ── Public: re-center (call when container becomes visible) ───────────────
    LocationPicker.prototype.invalidate = function () {
        if (this._map) this._map.invalidateSize();
    };

    global.LocationPicker = LocationPicker;
}(window));
