/* ============================================
   CRYPTO ASTEROID RUSH - Game Session
   File: js/game-session.js
   ============================================ */

let currentSession = {
    id: null,
    startTime: null,
    wallet: null,
    hardMode: false
};

function startSession(wallet) {
    currentSession = {
        id: Date.now(),
        startTime: new Date(),
        wallet: wallet,
        hardMode: missionStats.isHardMode
    };
    console.log('📋 Session started:', currentSession.id, currentSession.hardMode ? '(Hard Mode)' : '');
    return currentSession;
}

function endSession() {
    if (currentSession.id) {
        console.log('📋 Session ended:', currentSession.id);
        currentSession = { id: null, startTime: null, wallet: null, hardMode: false };
    }
}

function getSession() {
    return currentSession;
}

window.startSession = startSession;
window.endSession = endSession;
window.getSession = getSession;
window.currentSession = currentSession;
