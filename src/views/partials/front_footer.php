<?php
/**
 * Front-site shared footer (v1.3.1 迭代: 前台多页导航拆分).
 * Expects: $siteName, $copyright, $icpNumber, $footerHtml
 */
?>
    <!-- Footer -->
    <footer class="footer">
            <p><?= h($siteName) ?> <?= h($copyright) ?></p>
            <p class="mt-1">MoeRNG v<?= APP_VERSION ?> &mdash; Open-source under MIT License</p>
            <?php if (!empty($icpNumber)): ?>
            <p class="mt-1"><a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" class="footer-link"><?= h($icpNumber) ?></a></p>
            <?php endif; ?>
            <?php if (!empty($footerHtml)): ?>
            <p class="mt-1 footer-custom"><?= h($footerHtml) ?></p>
            <?php endif; ?>
        </footer>
    </div>

    <script src="/public/js/helpers.js?v=<?= ASSET_VER ?>"></script>
    <script src="/public/js/app.js?v=<?= ASSET_VER ?>"></script>

</body>
</html>
