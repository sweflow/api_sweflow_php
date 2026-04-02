/**
 * api.js — Camada de comunicação com a Sweflow API
 */

const API_BASE = 'https://api.typper.shop';

// ── Storage ───────────────────────────────────────────────────────────────

const storage = {
    get:   (key) => localStorage.getItem(key),
    set:   (key, val) => localStorage.setItem(key, val),
    clear: () => {
        ['access_token', 'refresh_token', 'usuario'].forEach(k => localStorage.removeItem(k));
    },
};

export const getUsuario = () => {
    try { return JSON.parse(storage.get('usuario') || 'null'); } catch { return null; }
};
export const getToken  = () => storage.get('access_token');
export const isLogado  = () => !!getToken();

// ── Mensagens de erro amigáveis ───────────────────────────────────────────

function mensagemAmigavel(status, apiMessage, path) {
    // Erros de autenticação/login
    if (path === '/api/login' || path === '/api/auth/login') {
        if (status === 400 || status === 401) {
            return 'E-mail/username ou senha incorretos. Verifique suas credenciais.';
        }
        if (status === 403) {
            // Pode ser conta desativada ou e-mail não verificado
            if (apiMessage && apiMessage.toLowerCase().includes('email')) {
                return 'Confirme seu e-mail antes de fazer login. Verifique sua caixa de entrada.';
            }
            return 'Acesso negado. Sua conta pode estar desativada.';
        }
    }

    // Erros genéricos por status
    if (status === 401) return 'Sessão expirada. Faça login novamente.';
    if (status === 403) return 'Você não tem permissão para realizar esta ação.';
    if (status === 404) return 'Recurso não encontrado.';
    if (status === 422) return apiMessage || 'Dados inválidos. Verifique os campos.';
    if (status === 429) return 'Muitas tentativas. Aguarde alguns instantes e tente novamente.';
    if (status >= 500)  return 'Erro no servidor. Tente novamente em instantes.';

    return apiMessage || 'Ocorreu um erro inesperado.';
}

// ── HTTP base ─────────────────────────────────────────────────────────────

async function http(method, path, body = null, retry = true) {
    const token = getToken();
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (token) headers['Authorization'] = `Bearer ${token}`;

    let res;
    try {
        res = await fetch(`${API_BASE}${path}`, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });
    } catch (networkErr) {
        throw new Error('Não foi possível conectar à API. Verifique sua conexão.');
    }

    // Rota de login: trata 400/401/403 como erro de credenciais, nunca redireciona
    if (!res.ok && (path === '/api/login' || path === '/api/auth/login')) {
        const data = await res.json().catch(() => ({}));
        const msg  = mensagemAmigavel(res.status, data.message || data.error, path);
        throw Object.assign(new Error(msg), { status: res.status, data });
    }

    // Token expirado em outras rotas — tenta refresh uma vez
    if (res.status === 401 && retry) {
        const ok = await tentarRefresh();
        if (ok) return http(method, path, body, false);
        storage.clear();
        window.location.href = 'index.html';
        return;
    }

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const msg = mensagemAmigavel(res.status, data.message || data.error, path);
        throw Object.assign(new Error(msg), { status: res.status, data });
    }

    return data;
}

async function tentarRefresh() {
    const refresh = storage.get('refresh_token');
    if (!refresh) return false;
    try {
        const res = await fetch(`${API_BASE}/api/auth/refresh`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: refresh }),
        });
        if (!res.ok) return false;
        const data = await res.json();
        storage.set('access_token', data.access_token);
        storage.set('refresh_token', data.refresh_token);
        return true;
    } catch {
        return false;
    }
}

// ── Auth ──────────────────────────────────────────────────────────────────

export async function login(loginVal, senha) {
    const data = await http('POST', '/api/login', { login: loginVal, senha });
    storage.set('access_token', data.access_token);
    storage.set('refresh_token', data.refresh_token);
    storage.set('usuario', JSON.stringify(data.usuario));
    return data.usuario;
}

export async function logout() {
    try { await http('POST', '/api/auth/logout'); } catch { /* ignora */ }
    finally { storage.clear(); }
}

// ── Perfil ────────────────────────────────────────────────────────────────

export async function getPerfil() {
    return http('GET', '/api/perfil');
}

export async function atualizarPerfil(dados) {
    return http('PUT', '/api/perfil', dados);
}
