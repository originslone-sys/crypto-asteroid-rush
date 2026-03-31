/* ============================================
   UNOBIX - Game Start v2
   js/game-start-v2.js
   Mode-aware start flow (normal/hard/extreme/training)
   ============================================ */

const MODE_DISPLAY_NAMES = {
    normal: 'NORMAL',
    hard: 'HARD',
    extreme: 'EXTREME',
    training: 'TRAINING'
};

/**
 * Iniciar jogo com tela de carregamento
 * v2: No determineHardMode() — mode is user-chosen
 */
function startGameWithLoading() {
    console.log('🎮 Iniciando jogo (v2)...');
    actualStartGame();
}

/**
 * Efetivamente iniciar o jogo
 * v2: Uses window.selectedGameMode and window.isTrainingMode
 */
async function actualStartGame() {
    // Aguardar configs do admin antes de iniciar
    if (window._modesConfigReady) await window._modesConfigReady;

    const modeConfig = getCurrentModeConfig();
    console.log('🎯 Configs do modo carregadas:', JSON.stringify({
        mode: window.selectedGameMode || 'normal',
        speed: modeConfig.speed_multiplier,
        max_asteroids: modeConfig.max_asteroids,
        spawn_interval: modeConfig.spawn_interval,
        spawn_rates: modeConfig.spawn_rates
    }));

    const missionNum = (typeof missionStats !== 'undefined' ? missionStats.totalMissions : 0) + 1;
    const selectedGameMode = window.selectedGameMode || 'normal';
    const isTraining = !!window.isTrainingMode;

    console.log('🚀 Iniciando missão', missionNum, `[mode: ${selectedGameMode}, training: ${isTraining}]`);

    // Set mode on missionStats early
    if (typeof missionStats !== 'undefined') {
        missionStats.gameMode = selectedGameMode;
        missionStats.isHardMode = (selectedGameMode === 'hard' || selectedGameMode === 'extreme');
    }

    try {
        // Training mode: skip session creation entirely
        if (isTraining) {
            console.log('🏋️ Modo treino — pulando criação de sessão');
            if (typeof showNotification === 'function') {
                showNotification('MODO TREINO', 'Sem custo de créditos', true);
            }
            _proceedWithGameStart();
            return;
        }

        if (typeof showNotification === 'function') {
            showNotification('PREPARANDO', 'Criando sessão da missão...', true);
        }

        // Obter Google UID
        const googleUid = getGoogleUidFromSources();

        console.log('🔑 Google UID:', googleUid ? googleUid.substring(0, 15) + '...' : 'NENHUM');

        if (!googleUid) {
            throw new Error('Usuário não autenticado. Faça login novamente.');
        }

        // Salvar no gameState
        if (typeof gameState !== 'undefined') {
            gameState.googleUid = googleUid;
        }

        // Criar sessão no servidor — pass selected mode
        const sessionResult = await SessionManager.startSession(googleUid, false, selectedGameMode);

        if (!sessionResult || !sessionResult.success) {
            throw new Error(sessionResult?.error || 'Falha ao criar sessão');
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

        // Redirecionar para compra de créditos se não tiver créditos
        if (error.errorCode === 'NO_CREDITS') {
            const creditsNeeded = error.creditsRequired || 0;
            const modeName = MODE_DISPLAY_NAMES[selectedGameMode] || selectedGameMode.toUpperCase();
            const msg = creditsNeeded > 0
                ? `Voce precisa de ${creditsNeeded} creditos para jogar no modo ${modeName}! Redirecionando para a loja...`
                : 'Voce precisa de creditos para jogar! Redirecionando para a loja...';

            if (typeof gameAlert === 'function') {
                await gameAlert(msg, 'warning', 'SEM CREDITOS');
            } else {
                alert(msg);
            }
            setTimeout(() => { window.location.href = 'wallet.html'; }, 1500);
            return;
        }

        // Sessão travada — oferecer opção de abandonar
        if (error.errorCode === 'ACTIVE_SESSION_EXISTS' && error.canForce) {
            let shouldForce = false;
            if (typeof gameConfirm === 'function') {
                shouldForce = await gameConfirm(
                    'Sua partida anterior travou. Deseja abandonar e iniciar uma nova?',
                    'PARTIDA TRAVADA'
                );
            } else {
                shouldForce = confirm('Sua partida anterior travou. Deseja abandonar e iniciar uma nova?');
            }

            if (shouldForce) {
                try {
                    const googleUid = getGoogleUidFromSources();
                    const retryResult = await SessionManager.startSession(googleUid, true, selectedGameMode);
                    if (retryResult && retryResult.success) {
                        console.log('✅ Sessão forçada criada:', retryResult.session_id);

                        if (typeof missionStats !== 'undefined') {
                            missionStats.totalMissions = retryResult.mission_number;
                            localStorage.setItem('totalMissions', missionStats.totalMissions.toString());
                            if (retryResult.is_hard_mode !== undefined) {
                                missionStats.isHardMode = retryResult.is_hard_mode;
                            }
                        }

                        // Continuar com início do jogo
                        _proceedWithGameStart();
                        return;
                    }
                } catch (retryError) {
                    console.error('❌ Falha ao forçar sessão:', retryError);
                }
            }

            if (typeof showModal === 'function') showModal('gameMenuModal');
            return;
        }

        if (typeof gameAlert === 'function') {
            await gameAlert('Falha ao iniciar missao: ' + error.message, 'error', 'ERRO');
        } else {
            alert('Erro: ' + error.message);
        }

        if (typeof showModal === 'function') {
            showModal('gameMenuModal');
        }
        return;
    }

    _proceedWithGameStart();
}

/**
 * Inicializar estado do jogo e começar partida
 * Chamado após sessão criada com sucesso (normal ou force) ou em training mode
 * v2: Uses CONFIG.INITIAL_LIVES (3), shows mode badge
 */
function _proceedWithGameStart() {
    const selectedGameMode = window.selectedGameMode || 'normal';

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

        // Resetar estado do jogo — v2: 3 lives via CONFIG.INITIAL_LIVES
        gameState.gameActive = true;
        gameState.score = 0;
        gameState.earnings = 0;
        gameState.lives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 3;
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

    // Show mode badge on screen
    const modeBadge = document.getElementById('gameModeBadge');
    if (modeBadge) {
        const modeName = MODE_DISPLAY_NAMES[selectedGameMode] || selectedGameMode.toUpperCase();
        modeBadge.className = 'game-mode-badge mode-' + selectedGameMode;
        modeBadge.textContent = modeName;
    }

    if (typeof showNotification === 'function') {
        showNotification('NAVE PRONTA', gameState?.currentSessionShip?.name || 'Pronto', true);
    }

    if (typeof showModal === 'function') {
        showModal('');
    }

    // Atualizar UI
    if (typeof resetLivesDisplay === 'function') resetLivesDisplay();
    if (typeof updateUI === 'function') updateUI();

    // Countdown 3, 2, 1, GO! antes de ativar o jogo
    _showCountdown(() => {
        if (typeof startGameTimer === 'function') startGameTimer();
        if (typeof startSpawnTimer === 'function') startSpawnTimer();
        if (typeof gameLoop === 'function') gameLoop();


        // Info da missão
        if (typeof showMissionStartInfo === 'function') {
            showMissionStartInfo();
        }
    });
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
 * Resetar display de vidas
 * v2: Uses CONFIG.INITIAL_LIVES (3)
 */
function resetLivesDisplay() {
    const livesContainer = document.getElementById('lives');
    if (!livesContainer) return;

    const initialLives = typeof CONFIG !== 'undefined' ? CONFIG.INITIAL_LIVES : 3;
    livesContainer.innerHTML = '';

    for (let i = 0; i < initialLives; i++) {
        const life = document.createElement('span');
        life.className = 'life active';
        livesContainer.appendChild(life);
    }
}

/**
 * Mostrar info de início da missão
 * v2: Includes mode name in notification
 */
function showMissionStartInfo() {
    const missionNum = typeof missionStats !== 'undefined' ? missionStats.totalMissions : 1;
    const selectedGameMode = window.selectedGameMode || 'normal';
    const modeName = MODE_DISPLAY_NAMES[selectedGameMode] || selectedGameMode.toUpperCase();
    const isTraining = !!window.isTrainingMode;

    const subtitle = isTraining
        ? `Modo Treino — Boa sorte, Comandante!`
        : `Modo ${modeName} — Boa sorte, Comandante!`;

    if (typeof showNotification === 'function') {
        showNotification(`MISSÃO #${missionNum}`, subtitle, true);
    }

    console.log('📊 Missão iniciada:', {
        number: missionNum,
        mode: selectedGameMode,
        training: isTraining,
        hardMode: typeof missionStats !== 'undefined' ? missionStats.isHardMode : false
    });
}

/**
 * Countdown visual 3, 2, 1, GO!
 * Overlay sobre o canvas, chama callback ao finalizar
 */
function _showCountdown(onComplete) {
    const overlay = document.createElement('div');
    overlay.id = 'countdownOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:9999;pointer-events:none;';

    const text = document.createElement('div');
    text.style.cssText = "font-family:'Orbitron',monospace;font-size:6rem;font-weight:900;color:#fff;text-shadow:0 0 40px rgba(77,166,255,0.8),0 0 80px rgba(77,166,255,0.4);transition:transform 0.3s ease,opacity 0.3s ease;";
    overlay.appendChild(text);
    document.body.appendChild(overlay);

    const steps = ['3', '2', '1', 'GO!'];
    let i = 0;

    function showNext() {
        if (i >= steps.length) {
            overlay.style.opacity = '0';
            overlay.style.transition = 'opacity 0.3s ease';
            setTimeout(() => { overlay.remove(); onComplete(); }, 300);
            return;
        }
        text.textContent = steps[i];
        text.style.transform = 'scale(1.5)';
        text.style.opacity = '0.3';
        requestAnimationFrame(() => {
            text.style.transform = 'scale(1)';
            text.style.opacity = '1';
        });
        if (steps[i] === 'GO!') {
            text.style.color = '#00d68f';
            text.style.textShadow = '0 0 40px rgba(0,214,143,0.8),0 0 80px rgba(0,214,143,0.4)';
        }
        i++;
        setTimeout(showNext, steps[i - 1] === 'GO!' ? 600 : 800);
    }

    showNext();
}

// Exportar funções
window.startGameWithLoading = startGameWithLoading;
window.actualStartGame = actualStartGame;
window._proceedWithGameStart = _proceedWithGameStart;
window.resetLivesDisplay = resetLivesDisplay;
window.showMissionStartInfo = showMissionStartInfo;
window.getGoogleUidFromSources = getGoogleUidFromSources;
window.MODE_DISPLAY_NAMES = MODE_DISPLAY_NAMES;

console.log('📦 game-start-v2.js carregado');
