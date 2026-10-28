<?php
// includes/theme_init.php — itinatakda ang light/dark bago mag-load ang CSS (walang "flash" ng maling tema).
// Isama sa <head> ng bawat layout. Ang toggle mismo ay nasa assets/script.js (sxSetTheme).
// Pinili ng user (localStorage 'sx-theme') > setting ng device.
?>
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('sx-theme'); } catch (e) {}
            if (t !== 'light' && t !== 'dark') {
                t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', t);
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
