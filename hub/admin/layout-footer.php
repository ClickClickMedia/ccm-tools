    </div><!-- /.page-container -->

    <!-- Toast Container -->
    <div id="toast-container"></div>

    <script src="../assets/js/main.js"></script>
    <?php if (!empty($pageScript)): ?>
        <script src="../assets/js/<?= h($pageScript) ?>"></script>
    <?php endif; ?>
</body>
</html>
