<?php // includes/footer.php ?>
</div><!-- /.container -->
</main>
</div><!-- /.layout -->
<footer class="footer" role="contentinfo">
  <div class="container-fluid py-3 d-flex justify-content-between">
    <small>&copy; <?= date('Y') ?> Nigeria Police - Database Security System (Final Year Project)</small>
    <small>WCAG 2.1 AA target | OWASP Top 10 hardened</small>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="assets/js/app.js?v=3" defer nonce="<?= e(csp_nonce()) ?>"></script>
<?php if (!empty($_SESSION['user_id'])): ?>
<div class="modal fade" id="timeoutModal" tabindex="-1" role="alertdialog" aria-modal="true" aria-labelledby="timeoutTitle" aria-describedby="timeoutDesc">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="h5 modal-title" id="timeoutTitle">Session expiring</h2></div>
    <div class="modal-body"><p id="timeoutDesc">Your session expires in <strong id="timeoutCount">120</strong> seconds due to inactivity. Press Continue to stay logged in.</p></div>
    <div class="modal-footer"><button class="btn btn-primary" id="timeoutStay" type="button">Continue session</button><a class="btn btn-secondary" href="logout.php">Logout now</a></div>
  </div></div>
</div>
<?php endif; ?>
</body>
</html>
