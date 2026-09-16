/**
 * Top Attack Countries — world bubble map for Nginx Cache Purge Preload
 * Description: Renders bubble markers on the jsVectorMap "world" map, sized
 *              and colored by ban count, for the Fail2Ban Security tab.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */
(function (window, document) {
    'use strict';

    /**
     * Approximate ISO 3166-1 alpha-2 country centroids [lat, lng] and
     * English display names. Static, offline, public-domain reference
     * data — used only to position bubble markers on the map. A country
     * code returned by RIPE RDAP but missing from this table is simply
     * skipped as a marker; it still appears in the ranked list, which is
     * rendered server-side from the same data and does not depend on
     * this table at all.
     */
    var NPPP_F2B_GEO = {
        AD: [42.55, 1.58, 'Andorra'], AE: [23.42, 53.85, 'United Arab Emirates'],
        AF: [33.94, 67.71, 'Afghanistan'], AG: [17.06, -61.8, 'Antigua and Barbuda'],
        AI: [18.22, -63.07, 'Anguilla'], AL: [41.15, 20.17, 'Albania'],
        AM: [40.07, 45.04, 'Armenia'], AO: [-11.2, 17.87, 'Angola'],
        AR: [-38.42, -63.62, 'Argentina'], AS: [-14.27, -170.13, 'American Samoa'],
        AT: [47.52, 14.55, 'Austria'], AU: [-25.27, 133.78, 'Australia'],
        AW: [12.52, -69.97, 'Aruba'], AX: [60.12, 19.9, 'Aland Islands'],
        AZ: [40.14, 47.58, 'Azerbaijan'], BA: [43.92, 17.68, 'Bosnia and Herzegovina'],
        BB: [13.19, -59.54, 'Barbados'], BD: [23.68, 90.36, 'Bangladesh'],
        BE: [50.5, 4.47, 'Belgium'], BF: [12.24, -1.56, 'Burkina Faso'],
        BG: [42.73, 25.49, 'Bulgaria'], BH: [26.07, 50.56, 'Bahrain'],
        BI: [-3.37, 29.92, 'Burundi'], BJ: [9.31, 2.32, 'Benin'],
        BM: [32.32, -64.75, 'Bermuda'], BN: [4.54, 114.73, 'Brunei'],
        BO: [-16.29, -63.59, 'Bolivia'], BR: [-14.24, -51.93, 'Brazil'],
        BS: [25.03, -77.4, 'Bahamas'], BT: [27.51, 90.43, 'Bhutan'],
        BW: [-22.33, 24.68, 'Botswana'], BY: [53.71, 27.95, 'Belarus'],
        BZ: [17.19, -88.5, 'Belize'], CA: [56.13, -106.35, 'Canada'],
        CD: [-4.04, 21.76, 'DR Congo'], CF: [6.61, 20.94, 'Central African Republic'],
        CG: [-0.23, 15.83, 'Congo'], CH: [46.82, 8.23, 'Switzerland'],
        CI: [7.54, -5.55, 'Ivory Coast'], CL: [-35.68, -71.54, 'Chile'],
        CM: [7.37, 12.35, 'Cameroon'], CN: [35.86, 104.2, 'China'],
        CO: [4.57, -74.3, 'Colombia'], CR: [9.75, -83.75, 'Costa Rica'],
        CU: [21.52, -77.78, 'Cuba'], CV: [16.0, -24.01, 'Cabo Verde'],
        CW: [12.17, -68.99, 'Curacao'], CY: [35.13, 33.43, 'Cyprus'],
        CZ: [49.82, 15.47, 'Czechia'], DE: [51.17, 10.45, 'Germany'],
        DJ: [11.83, 42.59, 'Djibouti'], DK: [56.26, 9.5, 'Denmark'],
        DM: [15.41, -61.37, 'Dominica'], DO: [18.74, -70.16, 'Dominican Republic'],
        DZ: [28.03, 1.66, 'Algeria'], EC: [-1.83, -78.18, 'Ecuador'],
        EE: [58.6, 25.01, 'Estonia'], EG: [26.82, 30.8, 'Egypt'],
        ER: [15.18, 39.78, 'Eritrea'], ES: [40.46, -3.75, 'Spain'],
        ET: [9.15, 40.49, 'Ethiopia'], FI: [61.92, 25.75, 'Finland'],
        FJ: [-17.71, 178.07, 'Fiji'], FK: [-51.8, -59.5, 'Falkland Islands'],
        FM: [7.43, 150.55, 'Micronesia'], FO: [61.89, -6.91, 'Faroe Islands'],
        FR: [46.23, 2.21, 'France'], GA: [-0.8, 11.61, 'Gabon'],
        GB: [55.38, -3.44, 'United Kingdom'], GD: [12.26, -61.6, 'Grenada'],
        GE: [42.32, 43.36, 'Georgia'], GF: [3.93, -53.13, 'French Guiana'],
        GG: [49.47, -2.58, 'Guernsey'], GH: [7.95, -1.02, 'Ghana'],
        GI: [36.14, -5.35, 'Gibraltar'], GL: [71.71, -42.6, 'Greenland'],
        GM: [13.44, -15.31, 'Gambia'], GN: [9.95, -9.7, 'Guinea'],
        GP: [16.27, -61.55, 'Guadeloupe'], GQ: [1.65, 10.27, 'Equatorial Guinea'],
        GR: [39.07, 21.82, 'Greece'], GT: [15.78, -90.23, 'Guatemala'],
        GU: [13.44, 144.79, 'Guam'], GW: [11.8, -15.18, 'Guinea-Bissau'],
        GY: [4.86, -58.93, 'Guyana'], HK: [22.4, 114.11, 'Hong Kong'],
        HN: [15.2, -86.24, 'Honduras'], HR: [45.1, 15.2, 'Croatia'],
        HT: [18.97, -72.29, 'Haiti'], HU: [47.16, 19.5, 'Hungary'],
        ID: [-0.79, 113.92, 'Indonesia'], IE: [53.14, -7.69, 'Ireland'],
        IL: [31.05, 34.85, 'Israel'], IM: [54.24, -4.55, 'Isle of Man'],
        IN: [20.59, 78.96, 'India'], IQ: [33.22, 43.68, 'Iraq'],
        IR: [32.43, 53.69, 'Iran'], IS: [64.96, -19.02, 'Iceland'],
        IT: [41.87, 12.57, 'Italy'], JE: [49.21, -2.13, 'Jersey'],
        JM: [18.11, -77.3, 'Jamaica'], JO: [30.59, 36.24, 'Jordan'],
        JP: [36.2, 138.25, 'Japan'], KE: [-0.02, 37.91, 'Kenya'],
        KG: [41.2, 74.77, 'Kyrgyzstan'], KH: [12.57, 104.99, 'Cambodia'],
        KI: [1.87, -157.36, 'Kiribati'], KM: [-11.88, 43.87, 'Comoros'],
        KN: [17.36, -62.78, 'Saint Kitts and Nevis'], KP: [40.34, 127.51, 'North Korea'],
        KR: [35.91, 127.77, 'South Korea'], KW: [29.31, 47.48, 'Kuwait'],
        KY: [19.31, -81.25, 'Cayman Islands'], KZ: [48.02, 66.92, 'Kazakhstan'],
        LA: [19.86, 102.5, 'Laos'], LB: [33.85, 35.86, 'Lebanon'],
        LC: [13.91, -60.98, 'Saint Lucia'], LI: [47.17, 9.56, 'Liechtenstein'],
        LK: [7.87, 80.77, 'Sri Lanka'], LR: [6.43, -9.43, 'Liberia'],
        LS: [-29.61, 28.23, 'Lesotho'], LT: [55.17, 23.88, 'Lithuania'],
        LU: [49.82, 6.13, 'Luxembourg'], LV: [56.88, 24.6, 'Latvia'],
        LY: [26.34, 17.23, 'Libya'], MA: [31.79, -7.09, 'Morocco'],
        MC: [43.75, 7.41, 'Monaco'], MD: [47.41, 28.37, 'Moldova'],
        ME: [42.71, 19.37, 'Montenegro'], MF: [18.07, -63.05, 'Saint Martin'],
        MG: [-18.77, 46.87, 'Madagascar'], MH: [7.13, 171.18, 'Marshall Islands'],
        MK: [41.61, 21.75, 'North Macedonia'], ML: [17.57, -4.0, 'Mali'],
        MM: [21.91, 95.96, 'Myanmar'], MN: [46.86, 103.85, 'Mongolia'],
        MO: [22.2, 113.55, 'Macao'], MP: [17.33, 145.38, 'Northern Mariana Islands'],
        MQ: [14.64, -61.02, 'Martinique'], MR: [21.01, -10.94, 'Mauritania'],
        MS: [16.74, -62.19, 'Montserrat'], MT: [35.94, 14.38, 'Malta'],
        MU: [-20.35, 57.55, 'Mauritius'], MV: [3.2, 73.22, 'Maldives'],
        MW: [-13.25, 34.3, 'Malawi'], MX: [23.63, -102.55, 'Mexico'],
        MY: [4.21, 101.98, 'Malaysia'], MZ: [-18.67, 35.53, 'Mozambique'],
        NA: [-22.96, 18.49, 'Namibia'], NC: [-20.9, 165.62, 'New Caledonia'],
        NE: [17.61, 8.08, 'Niger'], NG: [9.08, 8.68, 'Nigeria'],
        NI: [12.87, -85.21, 'Nicaragua'], NL: [52.13, 5.29, 'Netherlands'],
        NO: [60.47, 8.47, 'Norway'], NP: [28.39, 84.12, 'Nepal'],
        NR: [-0.52, 166.93, 'Nauru'], NU: [-19.05, -169.87, 'Niue'],
        NZ: [-40.9, 174.89, 'New Zealand'], OM: [21.51, 55.92, 'Oman'],
        PA: [8.54, -80.78, 'Panama'], PE: [-9.19, -75.02, 'Peru'],
        PF: [-17.68, -149.41, 'French Polynesia'], PG: [-6.31, 143.96, 'Papua New Guinea'],
        PH: [12.88, 121.77, 'Philippines'], PK: [30.38, 69.35, 'Pakistan'],
        PL: [51.92, 19.15, 'Poland'], PM: [46.94, -56.27, 'Saint Pierre and Miquelon'],
        PR: [18.22, -66.59, 'Puerto Rico'], PS: [31.95, 35.23, 'Palestine'],
        PT: [39.4, -8.22, 'Portugal'], PW: [7.51, 134.58, 'Palau'],
        PY: [-23.44, -58.44, 'Paraguay'], QA: [25.35, 51.18, 'Qatar'],
        RE: [-21.12, 55.54, 'Reunion'], RO: [45.94, 24.97, 'Romania'],
        RS: [44.02, 21.01, 'Serbia'], RU: [61.52, 105.32, 'Russia'],
        RW: [-1.94, 29.87, 'Rwanda'], SA: [23.89, 45.08, 'Saudi Arabia'],
        SB: [-9.65, 160.16, 'Solomon Islands'], SC: [-4.68, 55.49, 'Seychelles'],
        SD: [12.86, 30.22, 'Sudan'], SE: [60.13, 18.64, 'Sweden'],
        SG: [1.35, 103.82, 'Singapore'], SI: [46.15, 14.99, 'Slovenia'],
        SK: [48.67, 19.7, 'Slovakia'], SL: [8.46, -11.78, 'Sierra Leone'],
        SM: [43.94, 12.46, 'San Marino'], SN: [14.5, -14.45, 'Senegal'],
        SO: [5.15, 46.2, 'Somalia'], SR: [3.92, -56.03, 'Suriname'],
        SS: [6.88, 31.31, 'South Sudan'], ST: [0.19, 6.61, 'Sao Tome and Principe'],
        SV: [13.79, -88.9, 'El Salvador'], SX: [18.03, -63.06, 'Sint Maarten'],
        SY: [34.8, 38.997, 'Syria'], SZ: [-26.52, 31.47, 'Eswatini'],
        TC: [21.69, -71.8, 'Turks and Caicos Islands'], TD: [15.45, 18.73, 'Chad'],
        TG: [8.62, 0.82, 'Togo'], TH: [15.87, 100.99, 'Thailand'],
        TJ: [38.86, 71.28, 'Tajikistan'], TL: [-8.87, 125.73, 'Timor-Leste'],
        TM: [38.97, 59.56, 'Turkmenistan'], TN: [33.89, 9.54, 'Tunisia'],
        TO: [-21.18, -175.2, 'Tonga'], TR: [38.96, 35.24, 'Turkiye'],
        TT: [10.69, -61.22, 'Trinidad and Tobago'], TV: [-7.11, 177.65, 'Tuvalu'],
        TW: [23.7, 120.96, 'Taiwan'], TZ: [-6.37, 34.89, 'Tanzania'],
        UA: [48.38, 31.17, 'Ukraine'], UG: [1.37, 32.29, 'Uganda'],
        US: [39.83, -98.58, 'United States'], UY: [-32.52, -55.77, 'Uruguay'],
        UZ: [41.38, 64.59, 'Uzbekistan'], VA: [41.9, 12.45, 'Vatican City'],
        VC: [12.98, -61.29, 'Saint Vincent and the Grenadines'], VE: [6.42, -66.59, 'Venezuela'],
        VG: [18.42, -64.64, 'British Virgin Islands'], VI: [18.34, -64.9, 'U.S. Virgin Islands'],
        VN: [14.06, 108.28, 'Vietnam'], VU: [-15.38, 166.96, 'Vanuatu'],
        WS: [-13.76, -172.1, 'Samoa'], XK: [42.6, 20.9, 'Kosovo'],
        YE: [15.55, 48.52, 'Yemen'], YT: [-12.83, 45.17, 'Mayotte'],
        ZA: [-30.56, 22.94, 'South Africa'], ZM: [-13.13, 27.85, 'Zambia'],
        ZW: [-19.02, 29.15, 'Zimbabwe']
    };

    var MIN_RADIUS = 6;
    var MAX_RADIUS = 22;

    // Perceptual (square-root) area scale — a 10x count difference should
    // not look 10x scarier on screen than it actually is.
    function nppp_f2b_radius_for(count, maxCount) {
        if (maxCount <= 0) {
            return MIN_RADIUS;
        }
        var t = Math.sqrt(count / maxCount);
        return Math.round(MIN_RADIUS + t * (MAX_RADIUS - MIN_RADIUS));
    }

    // Amber (#f8b334) -> ban red (#ea1919) intensity ramp, matching the
    // existing Fail2Ban tab palette.
    function nppp_f2b_color_for(count, maxCount) {
        var t = maxCount > 0 ? count / maxCount : 0;
        var from = [248, 179, 52];
        var to = [234, 25, 25];
        var r = Math.round(from[0] + (to[0] - from[0]) * t);
        var g = Math.round(from[1] + (to[1] - from[1]) * t);
        var b = Math.round(from[2] + (to[2] - from[2]) * t);
        return 'rgb(' + r + ',' + g + ',' + b + ')';
    }

    var nppp_f2b_map_instance = null;

    /**
     * @param {string} containerSelector  CSS selector for the map container.
     * @param {Array}  data               [{ code: 'US', count: 42 }, ...]
     */
    window.nppp_f2b_init_country_map = function (containerSelector, data) {
        var container = document.querySelector(containerSelector);
        if (!container || typeof window.jsVectorMap === 'undefined') {
            return;
        }

        // Clean teardown before a fresh AJAX-loaded render — mirrors the
        // DataTables destroy/reinit pattern already used for the Live Feed.
        if (nppp_f2b_map_instance && typeof nppp_f2b_map_instance.destroy === 'function') {
            try {
                nppp_f2b_map_instance.destroy();
            } catch (e) { /* no-op: container is about to be wiped anyway */ }
            nppp_f2b_map_instance = null;
        }
        container.innerHTML = '';

        if (!data || !data.length) {
            return;
        }

        var maxCount = 0;
        for (var i = 0; i < data.length; i++) {
            if (data[i].count > maxCount) {
                maxCount = data[i].count;
            }
        }

        var markers = [];
        for (var j = 0; j < data.length; j++) {
            var row = data[j];
            var geo = NPPP_F2B_GEO[row.code];
            if (!geo) {
                continue;
            }
            markers.push({
                name: geo[2] + ' \u2014 ' + row.count.toLocaleString() + ' ' + (window.nppp_f2b_map_i18n && window.nppp_f2b_map_i18n.bansLabel ? window.nppp_f2b_map_i18n.bansLabel : 'bans'),
                coords: [geo[0], geo[1]],
                style: {
                    initial: {
                        r: nppp_f2b_radius_for(row.count, maxCount),
                        fill: nppp_f2b_color_for(row.count, maxCount),
                        'fill-opacity': 0.72,
                        stroke: '#fff',
                        'stroke-width': 1,
                        'stroke-opacity': 0.9
                    },
                    hover: {
                        'fill-opacity': 0.95,
                        cursor: 'pointer'
                    }
                }
            });
        }

        nppp_f2b_map_instance = new window.jsVectorMap({
            selector: containerSelector,
            map: 'world',
            zoomButtons: true,
            zoomOnScroll: false,
            draggable: true,
            backgroundColor: 'transparent',
            regionStyle: {
                initial: { fill: '#e4e9ef', stroke: '#c9d2dc', 'stroke-width': 0.5 },
                hover: { fill: '#d3ddea', cursor: 'default' }
            },
            markersSelectable: false,
            markers: markers,
            showTooltip: true
        });
    };

    // Destroy hook exposed for the admin JS teardown path (tab switch away).
    window.nppp_f2b_destroy_country_map = function () {
        if (nppp_f2b_map_instance && typeof nppp_f2b_map_instance.destroy === 'function') {
            try {
                nppp_f2b_map_instance.destroy();
            } catch (e) { /* no-op */ }
            nppp_f2b_map_instance = null;
        }
    };
})(window, document);
