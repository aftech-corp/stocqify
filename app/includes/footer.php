    </main><!-- /.main -->
</div><!-- /.flex-1 wrapper -->
</div><!-- /.app root -->

<script>
// Mobile sidebar toggle
(function () {
    const toggle  = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('mobile-open');
    });

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function (e) {
        if (window.innerWidth < 1024
            && !sidebar.contains(e.target)
            && !toggle.contains(e.target)
            && sidebar.classList.contains('mobile-open')) {
            sidebar.classList.remove('mobile-open');
        }
    });
})();

// Auto-dismiss flash messages after 4 s
setTimeout(function () {
    document.querySelectorAll('[role="alert"]').forEach(function (el) {
        el.style.transition = 'opacity .5s';
        el.style.opacity    = '0';
        setTimeout(function () { el.remove(); }, 500);
    });
}, 4000);
</script>

<style>
@media (max-width: 1023px) {
    #sidebar {
        position: fixed;
        top: 0; left: 0;
        transform: translateX(-100%);
    }
    #sidebar.mobile-open {
        transform: translateX(0);
        box-shadow: 4px 0 24px rgba(0,0,0,.5);
    }
}
</style>

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
