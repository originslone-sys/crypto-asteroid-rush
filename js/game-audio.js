/* ============================================
   CRYPTO ASTEROID RUSH - Audio System (disabled)
   File: js/game-audio.js
   Audio removido para performance em dispositivos móveis
   ============================================ */

let isAudioUnlocked = false;

function unlockAudio() {}
function tryAlternativeAudioUnlock() {}
function playSound() {}
function playBackgroundMusic() {}
function stopBackgroundMusic() {}
function toggleAudio() {
    const icon = document.getElementById('audioIcon');
    if (icon) icon.className = 'fas fa-volume-mute';
}

window.isAudioUnlocked = false;
window.unlockAudio = unlockAudio;
window.tryAlternativeAudioUnlock = tryAlternativeAudioUnlock;
window.playSound = playSound;
window.playBackgroundMusic = playBackgroundMusic;
window.stopBackgroundMusic = stopBackgroundMusic;
window.toggleAudio = toggleAudio;
