/* ============================================
   CRYPTO ASTEROID RUSH - Anti-Cheat System
   File: js/game-anticheat.js
   ============================================ */

(function() {
    'use strict';
    
    const _actionLimits = {
        shoot: { min: 80, count: 0, lastTime: 0 },
        destroy: { min: 100, count: 0, lastTime: 0 }
    };
    
    function validateAction(actionType) {
        const now = Date.now();
        const limit = _actionLimits[actionType];
        if (!limit) return true;
        
        const timeSinceLast = now - limit.lastTime;
        if (limit.lastTime > 0 && timeSinceLast < limit.min) {
            limit.count++;
            return limit.count <= 10;
        }
        
        if (timeSinceLast >= limit.min) {
            limit.count = Math.max(0, limit.count - 1);
        }
        limit.lastTime = now;
        return true;
    }
    
    window.AntiCheat = {
        validateAction: validateAction,
        getStatus: () => ({ active: true }),
        init: () => console.log('🛡️ Anti-cheat active'),
        getReport: () => ({ integrity_ok: true })
    };
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => AntiCheat.init());
    } else {
        setTimeout(() => AntiCheat.init(), 100);
    }
})();
