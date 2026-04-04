// login.js
import { login, isLogado } from './api.js';

// Redireciona se já estiver logado
if (isLogado()) window.location.href = 'dashboard.html';

const form      = document.getElementById('login-form');
const feedback  = document.getElementById('feedback');
const btnText   = document.getElementById('btn-text');
const submitBtn = document.getElementById('submit-btn');

function setFeedback(msg, type = 'error') {
    feedback.textContent = msg;
    feedback.className = `feedback ${type}`;
}

function setLoading(on) {
    submitBtn.disabled = on;
    btnText.innerHTML = on
        ? '<span class="spinner"></span> Entrando...'
        : 'Entrar';
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    feedback.className = 'feedback';

    const loginVal = document.getElementById('login').value.trim();
    const senhaVal = document.getElementById('senha').value;

    if (!loginVal || !senhaVal) {
        setFeedback('Preencha todos os campos.');
        return;
    }

    setLoading(true);
    try {
        await login(loginVal, senhaVal);
        setFeedback('Login realizado! Redirecionando...', 'success');
        setTimeout(() => window.location.href = 'dashboard.html', 600);
    } catch (err) {
        setFeedback(err.message || 'Credenciais inválidas.');
    } finally {
        setLoading(false);
    }
});