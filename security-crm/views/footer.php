    </main>

    <footer class="foot">
      SecOps CRM · PHP native + MySQL · <code>security_crm</code>
    </footer>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/app.js" defer></script>
<script nonce="<?= e(csp_nonce()) ?>">
/* Offline shell: app.css/app.js are cached, PHP pages are network-only. */
if ('serviceWorker' in navigator && (window.isSecureContext || location.hostname === 'localhost')) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js', { scope: './' })
      // Check for a new sw.js on every page load, so an app update is not
      // served from last week's cache until the browser feels like asking.
      .then(function (reg) { reg.update(); })
      .catch(function () { /* http on a LAN: no SW, the app still works */ });
  });
}
</script>
</body>
</html>
