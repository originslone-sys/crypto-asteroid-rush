/* ============================================
   UNOBIX - Game Start v5.0
   File: js/game-start.js
   CORRIGIDO: Anti-loop, error handling robusto,
   proteção contra dupla execução
   ============================================ */

// Flag global para evitar dupla execução
let _gameStartInProgress = false;
let _gameStartAttempts = 0;
const _MAX_START_ATTEMPTS = 2;

/**
 * Iniciar jogo com tela de carregamento
 * Protegido contra chamadas duplicadas
 */
async function startGameWithLoading() {
    // ── Anti-loop: evitar dupla execução ──
    if (_gameStartInProgress) {
        console.warn('⚠️ startGameWithLoading já em execução — ignorando chamada duplicada');
        return;
    }
    
    // ── Anti-loop: limitar tentativas por sessão de página ──
    _gameStartAttempts++;
    if (_gameStartAttempts > _MAX_START_ATTEMPTS) {
        console.error('❌ Máximo de tentativas de início atingido — voltando ao menu');
        _safeShowMenu();
        return;
    }
    
    _gameStartInProgress = true;
    console.log('🎮 Iniciando jogo... (tentativa ' + _gameStartAttempts + ')');

    try {
        // Determinar hard mode (oculto do jogador)
        if (typeof determineHardMode === 'function') {
            determineHardMode();
        }

        await actualStartGame();
    } catch (error) {
        console.error('❌ Erro em startGameWithLoading:', error);
        _safeShowMenu();
    } finally {
        _gameStartInProgress = false;
    }
}

/**
 * Obter Google UID de várias fontes
 */
function getGoogleUidFromSources() {
    const sources = [
        () => gameState?.googleUid,
        () => gameState?.user?.uid,
        () => window.authManager?.currentUser?.uid,
        () => window.authManager?.getUserId?.(),
        () => localStorage.getItem('googleUid'),
        () => sessionStorage.getItem('googleUid')
    ];
    
    for (const source of sources) {
        try {
            const uid = source();
            if (uid && typeof uid === 'string' && uid.length >= 10) {
                return uid;
            }
        } catch (e) {}
    }
    
    return null;
}

/**
 * Mostrar menu de forma segura (sem travar)
 */
function _safeShowMenu() {
    try {
        // Limpar qualquer estado de loading
        if (typeof showLoading === 'function') {
            showLoading(false);
        }
        
        // Verificar se usuário está logado para mostrar modal correto
        const isLoggedIn = !!(gameState?.user || gameState?.googleUid || window.authManager?.currentUser);
        
        if (typeof showModal === 'function') {
            showModal(isLoggedIn ? 'gameMenuModal' : 'connectModal');
        }
    } catch (e) {
        console.error('Erro ao mostrar menu:', e);
    }
}

/**
 * Efetivamente iniciar o jogo
 */
async function actualStartGame() {
    const missionNum = (typeof missionStats !== 'undefined' ? missionStats.totalMissions : 0) + 1;
    console.log('🚀 Iniciando missão', missionNum);

    // ── ETAPA 1: Criar sessão no servidor (BARREIRA PRÉ-JOGO) ──
    try {
        if (typeof showNotification === 'function') {
            showNotification('PREPARANDO', 'Verificando disponibilidade...', true);
        }

        const googleUid = getGoogleUidFromSources();
        console.log('🔑 Google UID:', googleUid ? googleUid.substring(0, 15) + '...' : 'NENHUM');

        if (!googleUid) {
            // Sem login — voltar para tela de login sem alert bloqueante
            if (typeof NotificationSystem !== 'undefined') {
                NotificationSystem.modal(
                    'Login Necessário',
                    'Você precisa estar logado para jogar. Faça login com sua conta Google.',
                    { icon: '🔑', btnText: 'Fazer Login', btnClass: 'primary' }
                );
            }
            if (typeof showModal === 'function') showModal('connectModal');
            return; // Sair limpo, sem throw
        }

        // Salvar no gameState
        if (typeof gameState !== 'undefined') {
            gameState.googleUid = googleUid;
        }

        // ── Criar sessão — aqui o backend faz TODAS as verificações ──
        // Se falhar, SessionManager._notifyBlock() já mostra o feedback correto
        const sessionResult = await SessionManager.startSession(googleUid);

        if (!sessionResult || !sessionResult.success) {
            // SessionManager já mostrou a notificação — apenas voltar ao menu
            console.warn('⚠️ Sessão não criada — voltando ao menu');
            _safeShowMenu();
            return; // Sair limpo, sem throw + gameAlert duplicado
        }

        console.log('✅ Sessão criada:', sessionResult.session_id);

        // Atualizar stats
        if (typeof missionStats !== 'undefined') {
            missionStats.totalMissions = sessionResult.mission_number;
            localStorage.setItem('totalMissions', missionStats.totalMissions.toString());

            if (sessionResult.is_hard_mode !== undefined) {
                missionStats.isHardMode = sessionResult.is_hard_mode;
            }
        }

    } catch (error) {
        console.error('❌ Falha ao iniciar sessão:', error);
        
        // Só mostrar gameAlert se SessionManager NÃO já tratou o erro
        if (!error._handled) {
            if (typeof NotificationSystem !== 'undefined') {
                NotificationSystem.error('Erro', error.message || 'Falha ao iniciar missão. Tente novamente.');
            } else if (typeof gameAlert === 'function') {
                // Usar timeout para não bloquear a thread principal
                setTimeout(async () => {
                    await gameAlert('Falha ao iniciar missão: ' + error.message, 'error', 'ERRO');
                }, 100);
            }
        }
        
        _safeShowMenu();
        return;
    }

    // ══════════════════════════════════════════════
    // ── ETAPA 2: Sessão criada com sucesso — iniciar jogo ──
    // A partir daqui NÃO pode falhar silenciosamente
    // ══════════════════════════════════════════════

    // Resetar stats da missão
    if (typeof missionStats !== 'undefined') {
        missionStats.rareCount = 0;
        missionStats.epicCount = 0;
        missionStats.legendaryCount = 0;
    }

    // Criar asteroides iniciais
    if (typeof gameState !== 'undefined') {
        gameState.asteroids = [];
        const initialAsteroids = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_ASTEROIDS : 5;
        
        for (let i = 0; i < initialAsteroids; i++) {
            if (typeof createAsteroid === 'function') {
                const asteroid = createAsteroid(i, false);
                asteroid.y = -50 - (i * 80);
                gameState.asteroids.push(asteroid);
            }
        }
        
        gameState.asteroidSpawnCounter = initialAsteroids;
        
        // Resetar estado do jogo
        gameState.gameActive = true;
        gameState.score = 0;
        gameState.earnings = 0;
        gameState.lives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 6;
        gameState.invincibilityFrames = 0;
        gameState.destroyedAsteroids = [];
        gameState.bullets = [];
        gameState.particles = [];
        gameState.lastFireTime = 0;
        gameState.keys = { left: false, right: false, fire: false };
        
        // Nave
        const shipDesign = typeof getShipForGame === 'function' ? getShipForGame() : { name: 'Default Ship' };
        gameState.currentSessionShip = shipDesign;
        
        console.log('🚀 Usando nave:', shipDesign.name);
        
        if (typeof canvas !== 'undefined') {
            gameState.ship = {
                x: canvas.width / 2,
                y: canvas.height - 120,
                width: 80,
                height: 70,
                speed: typeof CONFIG !== 'undefined' ? CONFIG.SHIP_SPEED : 8,
                design: shipDesign
            };
            gameState.lastX = gameState.ship.x;
        }
    }

    if (typeof showNotification === 'function') {
        showNotification('NAVE PRONTA', gameState?.currentSessionShip?.name || 'Pronto', true);
    }
    
    if (typeof showModal === 'function') {
        showModal('');
    }

    // Atualizar UI e iniciar timers
    if (typeof resetLivesDisplay === 'function') resetLivesDisplay();
    if (typeof updateUI === 'function') updateUI();
    if (typeof startGameTimer === 'function') startGameTimer();
    if (typeof startSpawnTimer === 'function') startSpawnTimer();
    if (typeof gameLoop === 'function') gameLoop();

    // Áudio
    if (typeof gameState !== 'undefined' && gameState.audioEnabled) {
        setTimeout(() => {
            if (typeof isAudioUnlocked !== 'undefined' && !isAudioUnlocked) {
                if (typeof unlockAudio === 'function') unlockAudio();
                setTimeout(() => {
                    if (typeof playBackgroundMusic === 'function') {
                        playBackgroundMusic();
                    }
                }, 300);
            } else if (typeof playBackgroundMusic === 'function') {
                playBackgroundMusic();
            }
        }, 500);
    }

    // Info da missão
    if (typeof showMissionStartInfo === 'function') {
        showMissionStartInfo();
    }
}

/**
 * Resetar display de vidas
 */
function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;

    const initialLives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 6;
    livesContainer.innerHTML = '';
    
    for (let i = 0; i < initialLives; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

/**
 * Mostrar info de início da missão
 */
function showMissionStartInfo() {
    const missionNum = typeof missionStats !== 'undefined' ? missionStats.totalMissions : 1;
    
    if (typeof showNotification === 'function') {
        showNotification(`MISSÃO #${missionNum}`, 'Boa sorte, Comandante!', true);
    }

    console.log('📊 Missão iniciada:', { 
        number: missionNum,
        hardMode: typeof missionStats !== 'undefined' ? missionStats.isHardMode : false
    });
}

// Exportar funções
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;
window.getGoogleUidFromSources = getGoogleUidFromSources;

console.log('📦 game-start.js v5.0 carregado (com proteção anti-loop)');
