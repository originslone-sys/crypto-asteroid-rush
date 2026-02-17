/* ============================================
   UNOBIX Blog - Gerenciador Central de Ads
   blog/ad-manager.js

   Configure seus scripts de ads AQUI.
   Eles serão injetados automaticamente em
   todas as páginas do blog.
   ============================================ */

(function () {
    'use strict';

    // =============================================
    //  SCRIPTS GLOBAIS (carregam UMA vez por página)
    //  Monetag, AdSense global, etc.
    //  Cada item pode ter:
    //    src: URL do script externo
    //    inline: código JS inline para executar
    //    attrs: objeto com atributos extras (data-cfasync, etc)
    // =============================================
    var GLOBAL_SCRIPTS = [
        // Monetag
        {
            src: 'https://quge5.com/88/tag.min.js',
            attrs: { 'data-zone': '212120', 'data-cfasync': 'false', 'async': true }
        }
    ];


    // =============================================
    //  SLOTS DE ADS (injetados nos containers HTML)
    //  Cole o HTML de cada ad dentro de 'html:'
    //  Use enabled: false para desativar sem apagar
    // =============================================
    var ADS_CONFIG = {

        // --- SLOTS GLOBAIS (todas as páginas) ---

        // Banner horizontal no topo (Leaderboard - adaptativo)
        'ad-leaderboard-top': {
            html: '<div style="width:100%;margin:auto;position:relative;z-index:1;"><iframe data-aa="2427790" src="//acceptable.a-ads.com/2427790/?size=Adaptive" style="border:0;padding:0;width:100%;height:90px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Banner horizontal no rodapé do conteúdo
        'ad-leaderboard-bottom': {
            html: '<div style="width:100%;margin:auto;position:relative;z-index:1;"><iframe data-aa="2427790" src="//acceptable.a-ads.com/2427790/?size=Adaptive" style="border:0;padding:0;width:100%;height:90px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Ad no sidebar / menu lateral (300x250)
        'ad-sidebar-1': {
            html: '<div style="width:250px;margin:auto;z-index:1;"><iframe data-aa="2427791" src="//ad.a-ads.com/2427791/?size=300x250" style="border:0;padding:0;width:250px;height:250px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Coluna direita - primeiro slot (300x250)
        'ad-right-rail-1': {
            html: '<div style="width:300px;margin:auto;z-index:1;"><iframe data-aa="2427791" src="//ad.a-ads.com/2427791/?size=300x250" style="border:0;padding:0;width:300px;height:250px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Coluna direita - segundo slot (300x250)
        'ad-right-rail-2': {
            html: '<div style="width:300px;margin:auto;z-index:1;"><iframe data-aa="2427791" src="//ad.a-ads.com/2427791/?size=300x250" style="border:0;padding:0;width:300px;height:250px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Ad entre blocos de conteúdo - primeiro (adaptativo)
        'ad-infeed-1': {
            html: '<div style="width:100%;margin:auto;position:relative;z-index:1;"><iframe data-aa="2427790" src="//acceptable.a-ads.com/2427790/?size=Adaptive" style="border:0;padding:0;width:100%;height:90px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Ad entre blocos de conteúdo - segundo (adaptativo)
        'ad-infeed-2': {
            html: '<div style="width:100%;margin:auto;position:relative;z-index:1;"><iframe data-aa="2427790" src="//acceptable.a-ads.com/2427790/?size=Adaptive" style="border:0;padding:0;width:100%;height:90px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // --- SLOTS EXCLUSIVOS DA PÁGINA PRINCIPAL (index) ---

        // Rectangle ao lado do hero (300x250)
        'ad-hero-rectangle': {
            html: '<div style="width:300px;margin:auto;z-index:1;"><iframe data-aa="2427791" src="//ad.a-ads.com/2427791/?size=300x250" style="border:0;padding:0;width:300px;height:250px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Banner largo na zona above-the-fold (adaptativo)
        'ad-above-fold-wide': {
            html: '<div style="width:100%;margin:auto;position:relative;z-index:1;"><iframe data-aa="2427790" src="//acceptable.a-ads.com/2427790/?size=Adaptive" style="border:0;padding:0;width:100%;height:90px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        },

        // Quadrado na zona above-the-fold (300x250)
        'ad-above-fold-square': {
            html: '<div style="width:300px;margin:auto;z-index:1;"><iframe data-aa="2427791" src="//ad.a-ads.com/2427791/?size=300x250" style="border:0;padding:0;width:300px;height:250px;overflow:hidden;display:block;margin:auto"></iframe></div>',
            enabled: true
        }
    };


    // =============================================
    //  MOTOR - NÃO PRECISA EDITAR ABAIXO
    // =============================================

    function loadGlobalScripts() {
        for (var i = 0; i < GLOBAL_SCRIPTS.length; i++) {
            var config = GLOBAL_SCRIPTS[i];
            var script = document.createElement('script');

            if (config.src) {
                script.src = config.src;
                script.async = true;
            } else if (config.inline) {
                script.textContent = config.inline;
            }

            // Atributos extras (data-cfasync, etc)
            if (config.attrs) {
                var keys = Object.keys(config.attrs);
                for (var j = 0; j < keys.length; j++) {
                    var val = config.attrs[keys[j]];
                    if (val === true) {
                        script[keys[j]] = true;
                    } else {
                        script.setAttribute(keys[j], val);
                    }
                }
            }

            document.head.appendChild(script);
        }
    }

    function injectAd(slotId, config) {
        var container = document.getElementById(slotId);
        if (!container) return;

        if (!config.enabled) {
            container.style.display = 'none';
            return;
        }

        if (!config.html) return;

        // Limpar placeholder
        container.innerHTML = config.html;

        // Re-executar scripts que foram injetados via innerHTML
        var scripts = container.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var original = scripts[i];
            var replacement = document.createElement('script');
            if (original.src) {
                replacement.src = original.src;
                replacement.async = true;
            } else {
                replacement.textContent = original.textContent;
            }
            original.parentNode.replaceChild(replacement, original);
        }
    }

    function init() {
        loadGlobalScripts();

        var slotIds = Object.keys(ADS_CONFIG);
        for (var i = 0; i < slotIds.length; i++) {
            injectAd(slotIds[i], ADS_CONFIG[slotIds[i]]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

