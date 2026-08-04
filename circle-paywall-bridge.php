<?php
/**
 * Plugin Name: Circle Paywall Bridge
 * Description: Makes in-app checkout links on biddytarot.com work correctly
 *              inside the Circle branded app's webview. When a buy button
 *              linking to biddytarot.spiffy.co/checkout/* is tapped inside the
 *              app, this navigates to it in the same view instead of opening a
 *              new tab, which lets the app fire Google's External Content Links
 *              disclosure and hand off to checkout. Has no effect outside the
 *              app or on any non-checkout link. Diagnostic logging is available
 *              behind the DEV MODE toggle below (off by default).
 *
 * -------------------------------------------------------------------------
 *
 * Background: In July 2026 Google Play flagged our branded app for not 
 * using the External Content Links (ECL) API.
 * The trigger was that our buy buttons use target="_blank". Inside the app's
 * webview a new-window request is not routed through the ECL disclosure, so
 * users reached external checkout without Google's required screen. This
 * plugin removes that path: in-app, on checkout links only, it cancels the
 * new-window behaviour and does a normal top-level navigation instead, which
 * the app intercepts and routes through the disclosure correctly.
 *
 * On window.webview.goToPaywallUrl: Circle support recommended calling this
 * from our pages. In our testing on a real device (Motorola Edge 2023,
 * Android 15, Chrome WebView 150) both window.goToPaywallUrl and
 * window.webview.goToPaywallUrl were present as functions, but calling either
 * returned without error and produced no navigation (still on the page 4s
 * later, no pagehide/beforeunload). Because a plain top-level navigation does
 * fire the disclosure and reach checkout, this plugin relies on that and does
 * not call goToPaywallUrl. If that function is expected to do something, its
 * no-op behavior on our pages may be worth a look on the app side.
 *
 * Two webview contexts (the main open issue): the app injects a different
 * window.webview into different pages, decided before our JS runs (visible in
 * the page-view log line, before any tap):
 *   - Standard context: keys goToPaywallUrl, isComingFromReactNative-
 *     AuthenticatedWebview, ctaSuccessful. Disclosure fires, checkout loads.
 *     WORKS.
 *   - Purchase-completion context: the above plus isInsideMobileWebview,
 *     isComingFromReactNativeWebview, onEventPurchaseEnded, paymentSuccessful.
 *     Disclosure fires, then the flow requests /mobile/v1/cookies and receives
 *     "Cannot GET /mobile/v1/cookies" (an Express 404), then returns the user
 *     to the previous screen. No checkout. FAILS.
 * Same behavior reproduces from a locked-space "Join" tap, which never
 * touches our site. We can detect which context a page gets but cannot
 * influence it -- what decides the context, and why /mobile/v1/cookies 404s,
 * are app-side questions.
 * -------------------------------------------------------------------------
 *
 * Version: 3.2
 */

if (!defined('ABSPATH')) { exit; }

/* =========================================================================
 * DEV MODE
 *
 * This is a diagnostic switch, off in production.
 *
 * When true, the script logs the tap -> navigation lifecycle (and each in-app
 * page load, with the window.webview key list) to a private file via a small
 * admin-ajax endpoint. This is how the two-webview-context behaviour described
 * in the header was identified. When false, nothing is logged and the endpoint
 * is not even registered.
 *
 *   true  = verbose diagnostic logging to a private file (testing only)
 *   false = production; no logging, no endpoint, no page-view beacons
 *
 * Log path (dev mode only):
 *   wp-content/uploads/circle-bridge-log/circle-bridge.log
 * ====================================================================== */
define('CIRCLE_BRIDGE_DEV_MODE', false);

/* --- Logging endpoint: only registered when DEV MODE is on --------------- */
if (CIRCLE_BRIDGE_DEV_MODE) {

    function circle_bridge_log_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'circle-bridge-log';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Deny from all\n");
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    function circle_bridge_log_handler() {
        $row = array('ts' => gmdate('c'));
        $count = 0;
        foreach ($_POST as $k => $v) {
            if ($k === 'action') { continue; }
            if ($count++ >= 30) { break; }
            $key = substr(sanitize_key($k), 0, 40);
            if (!is_string($v)) { $v = wp_json_encode($v); }
            $row[$key] = substr(sanitize_text_field(wp_unslash($v)), 0, 1000);
        }
        $line = wp_json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(circle_bridge_log_dir() . '/circle-bridge.log', $line, FILE_APPEND | LOCK_EX);
        wp_die('', '', array('response' => 204));
    }
    add_action('wp_ajax_circle_bridge_log',        'circle_bridge_log_handler');
    add_action('wp_ajax_nopriv_circle_bridge_log', 'circle_bridge_log_handler');
}

/* --- Frontend ------------------------------------------------------------ */
add_action('wp_head', function () {
    $ajax = admin_url('admin-ajax.php');
    $dev  = CIRCLE_BRIDGE_DEV_MODE ? 'true' : 'false';
    ?>
<script>
(function () {
  'use strict';

  var DEV      = <?php echo $dev; ?>;
  var AJAX_URL = <?php echo wp_json_encode($ajax); ?>;

  // Gate: only run inside the app's webview. window.ReactNativeWebView is present only 
  // there, so on the normal web this whole script returns immediately and changes nothing.
  if (!window.ReactNativeWebView) { return; }

  // ---- Diagnostic logging (dev mode only; no-op in production) ----
  var PAGE_ID = 'p_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
  var lastTapId = null, lastTapTime = 0;

  function report(event, extra) {
    if (!DEV) { return; }
    try {
      var body = new URLSearchParams();
      body.set('action', 'circle_bridge_log');
      body.set('event', event);
      body.set('pageId', PAGE_ID);
      if (lastTapId) { body.set('tapId', lastTapId); }
      body.set('sinceTapMs', String(lastTapTime ? (Date.now() - lastTapTime) : ''));
      if (extra) {
        Object.keys(extra).forEach(function (k) {
          var v = extra[k];
          body.set(k, (v === null || v === undefined) ? '' : String(v));
        });
      }
      fetch(AJAX_URL, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' });
    } catch (e) { /* logging must never break the page */ }
  }

  function env() {
    var wv = null; try { wv = window.webview || null; } catch (e) {}
    var keys = '';
    try { if (wv) { keys = Object.getOwnPropertyNames(wv).join(',').slice(0, 300); } } catch (e) {}
    return {
      url:         location.href,
      referrer:    document.referrer,
      hasWebview:  !!wv,
      webviewKeys: keys,
      visibility:  (document.visibilityState || '')
    };
  }

  // ---- The fix ----
  function isPaywallLink(a) {
    try {
      var u = new URL(a.href, location.href);
      return u.protocol === 'https:'
          && u.hostname.toLowerCase() === 'biddytarot.spiffy.co'
          && u.pathname.toLowerCase().indexOf('/checkout/') === 0;
    } catch (e) { return false; }
  }

  if (DEV) { report('page-view', env()); }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || !isPaywallLink(a)) { return; }

    if (DEV) {
      lastTapId = 't_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
      lastTapTime = Date.now();
      var snap = env();
      snap.href = a.href;
      snap.targetAttr = a.getAttribute('target') || '';
      report('tap', snap);
    }

    // Navigate in the same view instead of opening a new tab. target="_blank"
    // inside the webview is not routed through the ECL disclosure; a top-level
    // navigation is. (See the header for the known purchase-completion-context
    // failure this does not resolve -- that one is app-side.)
    e.preventDefault();
    if (DEV) { report('navigating-in-place', { href: a.href }); }
    window.location.href = a.href;
  }, true);

  // Dev-only navigation confirmation: proves the in-place nav actually fired.
  if (DEV) {
    window.addEventListener('pagehide', function () {
      if (lastTapId && (Date.now() - lastTapTime) < 10000) { report('nav-pagehide', {}); }
    }, true);
    window.addEventListener('beforeunload', function () {
      if (lastTapId && (Date.now() - lastTapTime) < 10000) { report('nav-beforeunload', {}); }
    }, true);
  }
})();
</script>
<?php }, 1);
