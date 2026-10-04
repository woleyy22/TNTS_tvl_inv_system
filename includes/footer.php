    </main>
</div>
<script>
    (function () {
        var btn = document.getElementById('menuBtn');
        var bar = document.getElementById('sidebar');
        if (btn && bar) {
            btn.addEventListener('click', function () { bar.classList.toggle('open'); });
        }
    })();
</script>
</body>
</html>
