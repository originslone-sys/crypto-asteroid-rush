/* ============================================
   CRYPTO ASTEROID RUSH - Audio System
   File: js/game-audio.js
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

function playSound(filename, volume = 1) {
    if (!isAudioUnlocked || !gameState.audioEnabled) return;
    
    try {
        const audio = new Audio('sounds/' + filename);
        audio.volume = volume;
        audio.play().catch(() => {});
    } catch (e) {}
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
