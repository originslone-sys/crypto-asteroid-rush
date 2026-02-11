/* ============================================
   CRYPTO ASTEROID RUSH - Audio System v2.0
   File: js/game-audio.js

   MUDANÇAS v2.0:
   - CRÍTICO: AudioPool reutiliza Audio objects (previne memory leak)
   - playSound() agora usa AudioPool em vez de new Audio() a cada chamada
   - cleanup() libera todos os recursos de áudio
   - Antes: ~1200 Audio objects criados por partida → freeze da aba
   - Agora: máximo de 4 Audio objects por som (reutilizados)
   ============================================ */

let audioContext = null;
let isAudioUnlocked = false;
let backgroundMusic = null;
let audioAttempts = 0;
const MAX_AUDIO_ATTEMPTS = 3;

function unlockAudio() {
    if (isAudioUnlocked || audioAttempts >= MAX_AUDIO_ATTEMPTS) return;
    
    try {
        if (!audioContext) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
        }
        
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        gainNode.gain.value = 0.001;
        oscillator.start();
        
        setTimeout(() => {
            oscillator.stop();
            isAudioUnlocked = true;
            audioAttempts = 0;
            console.log('✅ Audio unlocked');
            
            if (gameState.gameActive && gameState.audioEnabled && backgroundMusic === null) {
                playBackgroundMusic();
            }
        }, 50);
        
    } catch (e) {
        audioAttempts++;
        setTimeout(() => tryAlternativeAudioUnlock(), 100);
    }
}

function tryAlternativeAudioUnlock() {
    if (isAudioUnlocked) return;
    
    try {
        const silentAudio = new Audio();
        silentAudio.volume = 0.001;
        silentAudio.src = 'data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAZGF0YQQAAAAAAA==';
        
        silentAudio.play().then(() => {
            silentAudio.pause();
            isAudioUnlocked = true;
            console.log('✅ Audio unlocked (alternative)');
            
            if (gameState.gameActive && gameState.audioEnabled && backgroundMusic === null) {
                playBackgroundMusic();
            }
        }).catch(() => {});
    } catch (e) {}
}

// ============================================
// AUDIO POOL - Reutiliza Audio objects
// Previne memory leak fatal no Chrome mobile
// ============================================
const AudioPool = {
    _pools: {},
    _maxPerSound: 4,

    play(filename, volume = 1) {
        if (!isAudioUnlocked || !gameState.audioEnabled) return;

        try {
            if (!this._pools[filename]) {
                this._pools[filename] = [];
            }

            const pool = this._pools[filename];
            let audio = null;

            // Encontrar Audio element disponível (pausado ou terminado)
            for (let i = 0; i < pool.length; i++) {
                if (pool[i].paused || pool[i].ended) {
                    audio = pool[i];
                    break;
                }
            }

            // Criar novo apenas se pool não está cheio
            if (!audio && pool.length < this._maxPerSound) {
                audio = new Audio('sounds/' + filename);
                pool.push(audio);
            }

            if (audio) {
                audio.volume = volume;
                audio.currentTime = 0;
                audio.play().catch(() => {});
            }
        } catch (e) {}
    },

    cleanup() {
        for (const key in this._pools) {
            this._pools[key].forEach(a => {
                try { a.pause(); a.src = ''; } catch (e) {}
            });
        }
        this._pools = {};
    }
};

function playSound(filename, volume = 1) {
    if (!isAudioUnlocked || !gameState.audioEnabled) return;
    AudioPool.play(filename, volume);
}

function playBackgroundMusic() {
    if (!gameState.audioEnabled || !isAudioUnlocked) return;
    
    try {
        if (backgroundMusic) {
            backgroundMusic.pause();
            backgroundMusic.currentTime = 0;
        }
        
        backgroundMusic = new Audio('sounds/background.mp3');
        backgroundMusic.loop = true;
        backgroundMusic.volume = 0.4;
        backgroundMusic.preload = 'auto';
        
        backgroundMusic.play().then(() => {
            console.log('🎵 Background music started');
        }).catch(() => {
            unlockAudio();
        });
    } catch (e) {}
}

function stopBackgroundMusic() {
    if (backgroundMusic) {
        backgroundMusic.pause();
        backgroundMusic.currentTime = 0;
    }
}

function toggleAudio() {
    gameState.audioEnabled = !gameState.audioEnabled;
    
    const icon = document.getElementById('audioIcon');
    if (icon) {
        icon.className = gameState.audioEnabled ? 'fas fa-volume-up' : 'fas fa-volume-mute';
    }
    
    localStorage.setItem('audioEnabled', gameState.audioEnabled);
    
    if (!gameState.audioEnabled) {
        stopBackgroundMusic();
    } else if (gameState.gameActive && isAudioUnlocked) {
        playBackgroundMusic();
    }
}

window.audioContext = audioContext;
window.isAudioUnlocked = isAudioUnlocked;
window.backgroundMusic = backgroundMusic;
window.unlockAudio = unlockAudio;
window.tryAlternativeAudioUnlock = tryAlternativeAudioUnlock;
window.playSound = playSound;
window.playBackgroundMusic = playBackgroundMusic;
window.stopBackgroundMusic = stopBackgroundMusic;
window.toggleAudio = toggleAudio;
window.AudioPool = AudioPool;
