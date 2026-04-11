/**
 * trusted-types-policy.js
 * Deve ser carregado APÓS DOMPurify e ANTES de qualquer outro script.
 *
 * createHTML usa DOMPurify.sanitize() — strings passam por sanitização real.
 * Sem DOMPurify disponível, lança erro em vez de aceitar qualquer string.
 */
if (window.trustedTypes && window.trustedTypes.createPolicy) {
    window.trustedTypes.createPolicy('default', {
        createHTML: (s) => {
            if (typeof window.DOMPurify === 'undefined') {
                // DOMPurify não carregou — rejeita para não criar falsa sensação de segurança
                throw new TypeError('DOMPurify não disponível — innerHTML bloqueado por segurança.');
            }
            return window.DOMPurify.sanitize(s, {
                // Permite tags HTML comuns de layout e formatação
                ALLOWED_TAGS: [
                    'div','span','p','br','hr','b','i','u','s','strong','em',
                    'h1','h2','h3','h4','h5','h6',
                    'ul','ol','li','table','thead','tbody','tr','th','td',
                    'a','img','code','pre','blockquote','label',
                    'input','select','option','button','form',
                    'article','section','header','footer','nav','main','aside',
                    'figure','figcaption','details','summary',
                    'small','sub','sup','mark','del','ins',
                ],
                ALLOWED_ATTR: [
                    'class','id','style','href','src','alt','title','type',
                    'value','placeholder','checked','disabled','selected',
                    'aria-*','role','tabindex',
                    'target','rel','colspan','rowspan',
                    'width','height','for','name','method','action',
                ],
                // Permite data-* via flag dedicada (ALLOWED_ATTR não suporta wildcard)
                ALLOW_DATA_ATTR: true,
                FORBID_ATTR: ['onerror','onload','onclick','onmouseover','onfocus','onblur'],
                // Não permite <script>, <iframe>, <object>, <embed>
                FORCE_BODY: false,
            });
        },
        createScriptURL: (s) => {
            const url = new URL(s, location.origin);
            if (url.protocol === 'javascript:' || url.protocol === 'data:') {
                throw new TypeError('URL bloqueada pela política Trusted Types: ' + s);
            }
            return s;
        },
        createScript: (s) => {
            // Não bloqueia createScript na política default — o Monaco loader precisa disso.
            // O Monaco configura sua própria política 'monaco-editor' via require.config.
            // Bloquear aqui quebraria o carregamento do editor.
            return s;
        },
    });
}
