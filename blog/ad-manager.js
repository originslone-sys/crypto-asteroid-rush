/* ============================================
   UNOBIX Blog - Gerenciador Central de Ads
   blog/ad-manager.js

   Configure seus scripts de ads AQUI.
   Eles serão injetados automaticamente em
   todas as páginas do blog.
   ============================================ */

(function () {
    'use strict';

    var ADS_CONFIG = {

        // =============================================
        //  SLOTS GLOBAIS (aparecem em TODAS as páginas)
        // =============================================

        // Banner horizontal no topo (728x90 Leaderboard)
        'ad-leaderboard-top': {
            html: '',
            // Exemplo:
            // html: '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXXXXX" crossorigin="anonymous"><\/script><ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-XXXXXXX" data-ad-slot="1111111111" data-ad-format="horizontal"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});<\/script>',
           //Script A-ADS
           <div id="frame" style="width: 100%;margin: auto;position: relative; z-index: 99998;">
          <iframe data-aa='2427790' src='//acceptable.a-ads.com/2427790/?size=Adaptive'
                            style='border:0; padding:0; width:70%; height:auto; overflow:hidden;display: block;margin: auto'></iframe>
        </div>
            enabled: true
        },

        // Banner horizontal no rodapé do conteúdo (728x90)
        'ad-leaderboard-bottom': {
            html: '',
            enabled: true
        },

        // Ad no sidebar / menu lateral (300x250)
        'ad-sidebar-1': {
            html: '',
            enabled: true
        },

        // Coluna direita - primeiro slot (300x250)
//Script A-ADS
<div id="frame" style="width: 300px;margin: auto;z-index: 99998;height: auto">
          <iframe data-aa='2427791' src='//ad.a-ads.com/2427791/?size=300x250'
                            style='border:0; padding:0; width:300px; height:250px; overflow:hidden;display: block;margin: auto'></iframe>
        </div>
        'ad-right-rail-1': {
            html: '',
            enabled: true
        },

        // Coluna direita - segundo slot (300x250)
        'ad-right-rail-2': {
            html: '',
            enabled: true
        },

        // Ad entre blocos de conteúdo - primeiro (responsivo)
        'ad-infeed-1': {
            html: '',
            enabled: true
        },

        // Ad entre blocos de conteúdo - segundo (responsivo)
        'ad-infeed-2': {
            html: '',
            enabled: true
        },


        // =============================================
        //  SLOTS EXCLUSIVOS DA PÁGINA PRINCIPAL (index)
        // =============================================

        // Rectangle ao lado do hero (300x250)
        'ad-hero-rectangle': {
            html: '',
            enabled: true
        },

        // Banner largo na zona above-the-fold (468x60)
        'ad-above-fold-wide': {
            html: '',
            enabled: true
        },

        // Quadrado na zona above-the-fold (300x250)
        'ad-above-fold-square': {
            html: '',
            enabled: true
        }
    };


    // =============================================
    //  CONFIGURAÇÃO GLOBAL DO ADSENSE (opcional)
    //  Cole aqui o <script> principal do AdSense
    //  que precisa carregar uma vez por página.
    // =============================================
    var GLOBAL_HEAD_SCRIPT = '';
    // Exemplo:
    // var GLOBAL_HEAD_SCRIPT = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-XXXXXXX" crossorigin="anonymous"><\/script>';
   // Scripts Monetag
<script src="https://3nbf4.com/act/files/tag.min.js?z=10622094" data-cfasync="false" async></script>

<script>(function(s){s.dataset.zone='10622119',s.src='https://nap5k.com/tag.min.js'})([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>

<script>(function(s){s.dataset.zone='10622121',s.src='https://gizokraijaw.net/vignette.min.js'})([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>


    // =============================================
    //  MOTOR - NÃO PRECISA EDITAR ABAIXO
    // =============================================

    function injectGlobalScript() {
        if (!GLOBAL_HEAD_SCRIPT) return;

        var temp = document.createElement('div');
        temp.innerHTML = GLOBAL_HEAD_SCRIPT;
        var scripts = temp.querySelectorAll('script');

        for (var i = 0; i < scripts.length; i++) {
            var s = document.createElement('script');
            if (scripts[i].src) {
                s.src = scripts[i].src;
                s.async = true;
                if (scripts[i].crossOrigin) s.crossOrigin = scripts[i].crossOrigin;
            } else {
                s.textContent = scripts[i].textContent;
            }
            document.head.appendChild(s);
        }
    }

    function injectAd(slotId, config) {
        var container = document.getElementById(slotId);
        if (!container) return;
        if (!config.enabled) {
            container.style.display = 'none';
            return;
        }
        if (!config.html) return; // Slot ativo mas sem script ainda

        // Limpar placeholder
        container.innerHTML = '';

        // Injetar HTML
        var temp = document.createElement('div');
        temp.innerHTML = config.html;

        // Mover nós filhos para o container
        while (temp.firstChild) {
            container.appendChild(temp.firstChild);
        }

        // Executar scripts inline
        var scripts = container.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var original = scripts[i];
            var replacement = document.createElement('script');
            if (original.src) {
                replacement.src = original.src;
                replacement.async = true;
                if (original.crossOrigin) replacement.crossOrigin = original.crossOrigin;
            } else {
                replacement.textContent = original.textContent;
            }
            original.parentNode.replaceChild(replacement, original);
        }
    }

    function init() {
        injectGlobalScript();

        var slotIds = Object.keys(ADS_CONFIG);
        for (var i = 0; i < slotIds.length; i++) {
            injectAd(slotIds[i], ADS_CONFIG[slotIds[i]]);
        }
    }

    // Iniciar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

