<?php
/**
 * ShopTrack - Reusable Footer Partial
 */
?>
        </main>
    </div> <!-- .app-main -->
</div> <!-- .app-container -->

<!-- Global Toast Notifications Container -->
<div id="toast-container"></div>

<!-- Core App Scripts -->
<script src="assets/js/app.js"></script>

<?php if (!empty($extraScripts) && is_array($extraScripts)): ?>
    <?php foreach ($extraScripts as $script): ?>
        <script src="<?= h($script) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
