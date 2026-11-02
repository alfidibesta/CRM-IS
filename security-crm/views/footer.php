    </main>

    <footer class="foot">
      SecOps CRM · PHP native + MySQL · <code>security_crm</code>
    </footer>
  </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
