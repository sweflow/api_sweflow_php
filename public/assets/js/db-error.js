(function () {
    var btn   = document.getElementById('retry-btn');
    var label = document.getElementById('retry-label');
    if (!btn) return;

    function setSpinnerLabel(text) {
        label.textContent = '';
        var span = document.createElement('span');
        span.className = 'btn-spinner';
        label.appendChild(span);
        label.appendChild(document.createTextNode(' ' + text));
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.classList.remove('btn-offline');
        setSpinnerLabel('Verificando...');

        fetch('/api/db-status?_=' + Date.now(), { cache: 'no-store' })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json')) throw new Error('not-json');
                return r.json();
            })
            .then(function (data) {
                if (data.conectado) {
                    setSpinnerLabel('Conectado! Redirecionando...');
                    var destino = (document.referrer && document.referrer !== window.location.href)
                        ? document.referrer
                        : '/';
                    setTimeout(function () {
                        window.location.replace(destino);
                    }, 600);
                } else {
                    mostrarOffline();
                }
            })
            .catch(function () {
                mostrarOffline();
            });
    });

    function mostrarOffline() {
        btn.classList.add('btn-offline');
        label.textContent = 'Banco ainda offline';
        setTimeout(function () {
            btn.disabled = false;
            btn.classList.remove('btn-offline');
            label.textContent = 'Tentar novamente';
        }, 3000);
    }
})();
