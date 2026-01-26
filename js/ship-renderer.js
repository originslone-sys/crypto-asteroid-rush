/* ============================================
   CRYPTO ASTEROID RUSH - Ship Renderer v3.0
   File: js/ship-renderer.js
   Sleek, aerodynamic fighter ships
   ============================================ */

let shipCache = {
    lastDesign: null,
    gradients: {},
    time: 0
};

function drawShipAAA() {
    if (!gameState.ship || !gameState.ship.design) return;
    
    const ship = gameState.ship;
    const design = ship.design;
    const time = Date.now() * 0.001;
    shipCache.time = time;
    
    ctx.save();
    ctx.translate(ship.x, ship.y);
    
    const moveSpeed = ship.x - (gameState.lastX || ship.x);
    const tilt = Math.max(-0.2, Math.min(0.2, moveSpeed * 0.015));
    ctx.rotate(tilt);
    
    if (gameState.invincibilityFrames > 0) {
        const flash = Math.floor(gameState.invincibilityFrames / 4) % 2;
        if (flash) ctx.globalAlpha = 0.5;
    }
    
    drawMainEngines(design, time);
    drawWings(design, time);
    drawFuselage(design, time);
    drawCockpit(design, time);
    drawWeaponPods(design, time);
    drawNavigationLights(design, time);
    drawSpeedTrails(design, time, tilt);
    
    ctx.globalAlpha = 1;
    gameState.lastX = ship.x;
    ctx.restore();
}

function drawMainEngines(design, time) {
    const enginePulse = Math.sin(time * 12) * 0.15 + 0.85;
    const flameLength = 28 + Math.sin(time * 10) * 6;
    
    [-10, 10].forEach((offsetX, index) => {
        const pulse = Math.sin(time * 14 + index * 0.5) * 0.12 + 0.88;
        const length = flameLength * pulse;
        
        const housingGrad = ctx.createLinearGradient(offsetX - 6, 20, offsetX + 6, 32);
        housingGrad.addColorStop(0, '#3a3a4a');
        housingGrad.addColorStop(0.5, '#1a1a24');
        housingGrad.addColorStop(1, '#0a0a12');
        
        ctx.fillStyle = housingGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 6, 20);
        ctx.lineTo(offsetX + 6, 20);
        ctx.lineTo(offsetX + 5, 32);
        ctx.lineTo(offsetX - 5, 32);
        ctx.closePath();
        ctx.fill();
        
        ctx.strokeStyle = '#5a5a6a';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.ellipse(offsetX, 32, 5, 2, 0, 0, Math.PI * 2);
        ctx.stroke();
        
        const coreGrad = ctx.createLinearGradient(offsetX, 32, offsetX, 32 + length * 0.5);
        coreGrad.addColorStop(0, '#ffffff');
        coreGrad.addColorStop(0.3, '#88ffff');
        coreGrad.addColorStop(0.6, design.engineGlow);
        coreGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = coreGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 2.5, 32);
        ctx.quadraticCurveTo(offsetX - 3, 32 + length * 0.3, offsetX, 32 + length * 0.5);
        ctx.quadraticCurveTo(offsetX + 3, 32 + length * 0.3, offsetX + 2.5, 32);
        ctx.closePath();
        ctx.fill();
        
        const flameGrad = ctx.createLinearGradient(offsetX, 32, offsetX, 32 + length);
        flameGrad.addColorStop(0, design.engineGlow + 'dd');
        flameGrad.addColorStop(0.25, '#ff8800cc');
        flameGrad.addColorStop(0.5, '#ff4400aa');
        flameGrad.addColorStop(0.75, '#ff220055');
        flameGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = flameGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 4, 32);
        ctx.bezierCurveTo(offsetX - 6, 32 + length * 0.4, offsetX - 2, 32 + length * 0.8, offsetX, 32 + length);
        ctx.bezierCurveTo(offsetX + 2, 32 + length * 0.8, offsetX + 6, 32 + length * 0.4, offsetX + 4, 32);
        ctx.closePath();
        ctx.fill();
    });
    
    ctx.shadowColor = design.engineGlow;
    ctx.shadowBlur = 20 * enginePulse;
}

function drawWings(design, time) {
    ctx.shadowBlur = 0;
    
    [1, -1].forEach(side => {
        const x = side;
        
        const wingGrad = ctx.createLinearGradient(0, 0, side * 45, 15);
        wingGrad.addColorStop(0, shadeColor(design.primary, -10));
        wingGrad.addColorStop(0.4, design.primary);
        wingGrad.addColorStop(0.7, shadeColor(design.primary, -20));
        wingGrad.addColorStop(1, shadeColor(design.primary, -40));
        
        ctx.fillStyle = wingGrad;
        ctx.beginPath();
        ctx.moveTo(x * 8, 5);
        ctx.lineTo(x * 48, -5);
        ctx.lineTo(x * 42, 20);
        ctx.lineTo(x * 8, 25);
        ctx.closePath();
        ctx.fill();
        
        ctx.strokeStyle = lightenColor(design.primary, 30) + '80';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x * 8, 5);
        ctx.lineTo(x * 48, -5);
        ctx.stroke();
        
        ctx.strokeStyle = shadeColor(design.primary, -30) + '60';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(x * 15, 6);
        ctx.lineTo(x * 40, 0);
        ctx.moveTo(x * 18, 12);
        ctx.lineTo(x * 38, 8);
        ctx.stroke();
        
        ctx.fillStyle = design.secondary;
        ctx.beginPath();
        ctx.ellipse(x * 46, 0, 4, 8, side * 0.2, 0, Math.PI * 2);
        ctx.fill();
        
        const thrusterGrad = ctx.createRadialGradient(x * 38, 18, 0, x * 38, 18, 5);
        thrusterGrad.addColorStop(0, design.engineGlow);
        thrusterGrad.addColorStop(0.5, design.engineGlow + '80');
        thrusterGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = thrusterGrad;
        ctx.beginPath();
        ctx.arc(x * 38, 18, 5, 0, Math.PI * 2);
        ctx.fill();
    });
}

function drawFuselage(design, time) {
    const bodyGrad = ctx.createLinearGradient(-15, -35, 15, 30);
    bodyGrad.addColorStop(0, lightenColor(design.primary, 20));
    bodyGrad.addColorStop(0.2, design.primary);
    bodyGrad.addColorStop(0.5, shadeColor(design.primary, -10));
    bodyGrad.addColorStop(0.8, shadeColor(design.primary, -25));
    bodyGrad.addColorStop(1, shadeColor(design.primary, -40));
    
    ctx.fillStyle = bodyGrad;
    ctx.beginPath();
    ctx.moveTo(0, -38);
    ctx.bezierCurveTo(8, -32, 14, -20, 15, -5);
    ctx.bezierCurveTo(16, 10, 14, 22, 8, 28);
    ctx.lineTo(-8, 28);
    ctx.bezierCurveTo(-14, 22, -16, 10, -15, -5);
    ctx.bezierCurveTo(-14, -20, -8, -32, 0, -38);
    ctx.closePath();
    ctx.fill();
    
    ctx.strokeStyle = lightenColor(design.primary, 40) + '60';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(-6, -35);
    ctx.quadraticCurveTo(0, -38, 6, -35);
    ctx.stroke();
    
    const ridgeGrad = ctx.createLinearGradient(-3, -30, 3, 20);
    ridgeGrad.addColorStop(0, lightenColor(design.secondary, 20));
    ridgeGrad.addColorStop(0.5, design.secondary);
    ridgeGrad.addColorStop(1, shadeColor(design.secondary, -20));
    
    ctx.fillStyle = ridgeGrad;
    ctx.beginPath();
    ctx.moveTo(0, -32);
    ctx.lineTo(3, -25);
    ctx.lineTo(3, 15);
    ctx.lineTo(0, 22);
    ctx.lineTo(-3, 15);
    ctx.lineTo(-3, -25);
    ctx.closePath();
    ctx.fill();
    
    ctx.strokeStyle = shadeColor(design.primary, -30) + '50';
    ctx.lineWidth = 0.5;
    [-20, -10, 5, 15].forEach(y => {
        ctx.beginPath();
        ctx.moveTo(-12, y);
        ctx.lineTo(12, y);
        ctx.stroke();
    });
    
    ctx.fillStyle = '#1a1a24';
    [-1, 1].forEach(side => {
        ctx.fillRect(side * 8, -15, 3, 8);
        ctx.fillRect(side * 6, 5, 4, 6);
    });
}

function drawCockpit(design, time) {
    const shimmer = Math.sin(time * 2.5) * 0.1 + 0.9;
    
    const frameGrad = ctx.createLinearGradient(-10, -28, 10, -10);
    frameGrad.addColorStop(0, '#5a5a6a');
    frameGrad.addColorStop(0.5, '#3a3a4a');
    frameGrad.addColorStop(1, '#4a4a5a');
    
    ctx.fillStyle = frameGrad;
    ctx.beginPath();
    ctx.moveTo(0, -30);
    ctx.lineTo(10, -18);
    ctx.lineTo(8, -8);
    ctx.lineTo(-8, -8);
    ctx.lineTo(-10, -18);
    ctx.closePath();
    ctx.fill();
    
    const glassGrad = ctx.createLinearGradient(-8, -28, 8, -10);
    glassGrad.addColorStop(0, '#ffffff' + Math.floor(shimmer * 180).toString(16).padStart(2, '0'));
    glassGrad.addColorStop(0.2, lightenColor(design.cockpitTint, 30) + 'aa');
    glassGrad.addColorStop(0.5, design.cockpitTint + '88');
    glassGrad.addColorStop(0.8, shadeColor(design.cockpitTint, -20) + '77');
    glassGrad.addColorStop(1, shadeColor(design.primary, -30) + '99');
    
    ctx.fillStyle = glassGrad;
    ctx.beginPath();
    ctx.moveTo(0, -28);
    ctx.lineTo(8, -17);
    ctx.lineTo(6, -10);
    ctx.lineTo(-6, -10);
    ctx.lineTo(-8, -17);
    ctx.closePath();
    ctx.fill();
    
    ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
    ctx.beginPath();
    ctx.moveTo(-3, -26);
    ctx.lineTo(3, -26);
    ctx.lineTo(5, -20);
    ctx.lineTo(-2, -20);
    ctx.closePath();
    ctx.fill();
    
    ctx.fillStyle = '#00ff88' + Math.floor(shimmer * 60).toString(16).padStart(2, '0');
    ctx.fillRect(-4, -18, 2, 1);
    ctx.fillRect(1, -16, 3, 1);
    ctx.fillRect(-2, -14, 4, 1);
}

function drawWeaponPods(design, time) {
    const chargePulse = Math.sin(time * 6) * 0.25 + 0.75;
    
    [-1, 1].forEach(side => {
        const x = side * 16;
        
        const mountGrad = ctx.createLinearGradient(x - 3, -20, x + 3, -5);
        mountGrad.addColorStop(0, '#4a4a5a');
        mountGrad.addColorStop(0.5, '#2a2a35');
        mountGrad.addColorStop(1, '#3a3a4a');
        
        ctx.fillStyle = mountGrad;
        ctx.beginPath();
        ctx.roundRect(x - 3, -22, 6, 14, 2);
        ctx.fill();
        
        const barrelGrad = ctx.createLinearGradient(x, -32, x, -22);
        barrelGrad.addColorStop(0, '#2a2a35');
        barrelGrad.addColorStop(0.3, '#4a4a5a');
        barrelGrad.addColorStop(0.7, '#3a3a4a');
        barrelGrad.addColorStop(1, '#2a2a35');
        
        ctx.fillStyle = barrelGrad;
        ctx.beginPath();
        ctx.roundRect(x - 2, -34, 4, 14, 1);
        ctx.fill();
        
        ctx.fillStyle = '#1a1a22';
        ctx.beginPath();
        ctx.arc(x, -34, 2, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.fillStyle = design.accent + Math.floor(chargePulse * 200).toString(16).padStart(2, '0');
        ctx.beginPath();
        ctx.arc(x, -34, 1.2, 0, Math.PI * 2);
        ctx.fill();
        
        ctx.shadowColor = design.accent;
        ctx.shadowBlur = 6 * chargePulse;
        ctx.beginPath();
        ctx.arc(x, -34, 0.8, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;
    });
}

function drawNavigationLights(design, time) {
    const blink = Math.floor(time * 2) % 2 === 0;
    const fastBlink = Math.floor(time * 4) % 2 === 0;
    
    ctx.fillStyle = blink ? '#00ff00' : '#003300';
    ctx.shadowColor = '#00ff00';
    ctx.shadowBlur = blink ? 8 : 0;
    ctx.beginPath();
    ctx.arc(42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    
    ctx.fillStyle = blink ? '#ff0000' : '#330000';
    ctx.shadowColor = '#ff0000';
    ctx.shadowBlur = blink ? 8 : 0;
    ctx.beginPath();
    ctx.arc(-42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    
    ctx.fillStyle = '#ffffff';
    ctx.shadowColor = '#ffffff';
    ctx.shadowBlur = 6;
    ctx.beginPath();
    ctx.arc(0, -36, 1.5, 0, Math.PI * 2);
    ctx.fill();
    
    ctx.fillStyle = fastBlink ? '#ffaa00' : '#442200';
    ctx.shadowColor = '#ffaa00';
    ctx.shadowBlur = fastBlink ? 6 : 0;
    ctx.beginPath();
    ctx.arc(0, 26, 1.5, 0, Math.PI * 2);
    ctx.fill();
    
    [44, -44].forEach(x => {
        ctx.fillStyle = design.accent + (blink ? 'cc' : '44');
        ctx.shadowColor = design.accent;
        ctx.shadowBlur = blink ? 5 : 0;
        ctx.beginPath();
        ctx.arc(x, 2, 1.5, 0, Math.PI * 2);
        ctx.fill();
    });
    
    ctx.shadowBlur = 0;
}

function drawSpeedTrails(design, time, tilt) {
    if (Math.abs(tilt) > 0.03) {
        const trailAlpha = Math.min(Math.abs(tilt) * 4, 0.6);
        ctx.strokeStyle = design.primary + Math.floor(trailAlpha * 100).toString(16).padStart(2, '0');
        ctx.lineWidth = 1;
        
        for (let i = 0; i < 4; i++) {
            const startY = -25 + i * 15;
            const length = 12 + Math.random() * 8;
            const x = (tilt > 0 ? -1 : 1) * (30 + i * 5);
            
            ctx.beginPath();
            ctx.moveTo(x, startY);
            ctx.lineTo(x + (tilt > 0 ? -length : length), startY + 4);
            ctx.stroke();
        }
    }
}

function shadeColor(color, percent) {
    if (!color || color.length < 7) return color;
    const num = parseInt(color.replace('#', ''), 16);
    const amt = Math.round(2.55 * percent);
    const R = Math.max(0, Math.min(255, (num >> 16) + amt));
    const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
    const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
    return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
}

function lightenColor(color, percent) {
    return shadeColor(color, percent);
}

window.drawShipAAA = drawShipAAA;
window.shadeColor = shadeColor;
window.lightenColor = lightenColor;
