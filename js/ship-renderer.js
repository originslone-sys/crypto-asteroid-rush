/* ============================================
   UNOBIX - Ship Renderer v5.0 AAA+++
   File: js/ship-renderer.js
   
   Modelo único de caça realista — máximo detalhe
   Mesmo design base do v3.1, qualidade extrema:
   - Fuselagem com painéis metálicos e rebites
   - Cockpit com HUD interno e reflexos dinâmicos
   - Motores multicamada (core + plasma + afterburn)
   - Asas com flaps, rivets e specular highlight
   - Luzes de navegação com bloom realista
   - Speed trails com motion blur
   - Ambient occlusion e iluminação direcional
   - iOS safe (DeviceProfile aware)
   ============================================ */

let shipCache = {
    lastDesign: null,
    gradients: {},
    time: 0,
    frame: 0
};

// ============================================
// SAFE SHADOW (iOS / low-end)
// ============================================
function _safeShadow(color, blur) {
    if (typeof DeviceProfile !== 'undefined' && DeviceProfile.maxShadowBlur !== undefined) {
        ctx.shadowColor = color;
        ctx.shadowBlur = Math.min(blur, DeviceProfile.maxShadowBlur);
    } else {
        ctx.shadowColor = color;
        ctx.shadowBlur = blur;
    }
}

function _clearShadow() {
    ctx.shadowColor = 'transparent';
    ctx.shadowBlur = 0;
}

function _isLowEnd() {
    return typeof DeviceProfile !== 'undefined' && DeviceProfile.quality === 'low';
}

// ============================================
// COLOR HELPERS
// ============================================
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

function hexToRgba(hex, alpha) {
    if (!hex || hex.length < 7) return `rgba(128,128,128,${alpha})`;
    const num = parseInt(hex.replace('#', ''), 16);
    return `rgba(${(num >> 16) & 255}, ${(num >> 8) & 255}, ${num & 255}, ${alpha})`;
}

// ============================================
// MAIN DRAW ENTRY
// ============================================
function drawShipAAA() {
    if (!gameState.ship || !gameState.ship.design) return;
    
    const ship = gameState.ship;
    const design = ship.design;
    const time = Date.now() * 0.001;
    shipCache.time = time;
    shipCache.frame++;
    
    ctx.save();
    ctx.translate(ship.x, ship.y);
    
    const moveSpeed = ship.x - (gameState.lastX || ship.x);
    const tilt = Math.max(-0.2, Math.min(0.2, moveSpeed * 0.015));
    ctx.rotate(tilt);
    
    if (gameState.invincibilityFrames > 0) {
        const flash = Math.floor(gameState.invincibilityFrames / 4) % 2;
        if (flash) ctx.globalAlpha = 0.45;
    }
    
    // === DRAW ORDER (back to front) ===
    drawAmbientGlow(design, time);
    drawMainEngines(design, time);
    drawWings(design, time);
    drawFuselage(design, time);
    drawPanelDetails(design, time);
    drawCockpit(design, time);
    drawWeaponPods(design, time);
    drawNavigationLights(design, time);
    drawSpeedTrails(design, time, tilt);
    
    ctx.globalAlpha = 1;
    gameState.lastX = ship.x;
    ctx.restore();
}

// ============================================
// AMBIENT GLOW (under-ship soft light)
// ============================================
function drawAmbientGlow(design, time) {
    if (_isLowEnd()) return;
    
    const pulse = Math.sin(time * 3) * 0.08 + 0.25;
    const glowGrad = ctx.createRadialGradient(0, 5, 0, 0, 5, 55);
    glowGrad.addColorStop(0, hexToRgba(design.engineGlow, pulse));
    glowGrad.addColorStop(0.5, hexToRgba(design.primary, pulse * 0.3));
    glowGrad.addColorStop(1, 'transparent');
    
    ctx.fillStyle = glowGrad;
    ctx.beginPath();
    ctx.ellipse(0, 10, 50, 35, 0, 0, Math.PI * 2);
    ctx.fill();
}

// ============================================
// MAIN ENGINES — 3 camadas de chama + heat haze
// ============================================
function drawMainEngines(design, time) {
    const basePulse = Math.sin(time * 12) * 0.15 + 0.85;
    const baseLength = 30 + Math.sin(time * 10) * 8;
    
    [-10, 10].forEach((ox, idx) => {
        const pulse = Math.sin(time * 14 + idx * 0.7) * 0.12 + 0.88;
        const flameLen = baseLength * pulse;
        
        // === ENGINE HOUSING (nacelle) ===
        const housingGrad = ctx.createLinearGradient(ox - 7, 18, ox + 7, 34);
        housingGrad.addColorStop(0, '#484858');
        housingGrad.addColorStop(0.3, '#2a2a36');
        housingGrad.addColorStop(0.7, '#1a1a24');
        housingGrad.addColorStop(1, '#0e0e16');
        
        ctx.fillStyle = housingGrad;
        ctx.beginPath();
        ctx.moveTo(ox - 7, 18);
        ctx.lineTo(ox + 7, 18);
        ctx.quadraticCurveTo(ox + 6.5, 26, ox + 6, 33);
        ctx.lineTo(ox - 6, 33);
        ctx.quadraticCurveTo(ox - 6.5, 26, ox - 7, 18);
        ctx.closePath();
        ctx.fill();
        
        // Housing rivets
        if (!_isLowEnd()) {
            ctx.fillStyle = '#5a5a6a';
            for (let ry = 20; ry < 32; ry += 4) {
                ctx.beginPath();
                ctx.arc(ox - 6, ry, 0.6, 0, Math.PI * 2);
                ctx.arc(ox + 6, ry, 0.6, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        
        // Nozzle ring
        ctx.strokeStyle = '#6a6a7a';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.ellipse(ox, 33, 6, 2.2, 0, 0, Math.PI * 2);
        ctx.stroke();
        
        // Inner nozzle ring
        ctx.strokeStyle = '#3a3a4a';
        ctx.lineWidth = 0.8;
        ctx.beginPath();
        ctx.ellipse(ox, 33, 4, 1.5, 0, 0, Math.PI * 2);
        ctx.stroke();
        
        // === FLAME LAYER 1: Core (white-hot) ===
        const coreGrad = ctx.createLinearGradient(ox, 33, ox, 33 + flameLen * 0.45);
        coreGrad.addColorStop(0, '#ffffffee');
        coreGrad.addColorStop(0.2, '#ccffffff');
        coreGrad.addColorStop(0.5, design.engineGlow + 'cc');
        coreGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = coreGrad;
        ctx.beginPath();
        ctx.moveTo(ox - 2.5, 33);
        ctx.quadraticCurveTo(ox - 3, 33 + flameLen * 0.25, ox, 33 + flameLen * 0.45);
        ctx.quadraticCurveTo(ox + 3, 33 + flameLen * 0.25, ox + 2.5, 33);
        ctx.closePath();
        ctx.fill();
        
        // === FLAME LAYER 2: Plasma (main color) ===
        const plasmaGrad = ctx.createLinearGradient(ox, 33, ox, 33 + flameLen * 0.8);
        plasmaGrad.addColorStop(0, design.engineGlow + 'dd');
        plasmaGrad.addColorStop(0.2, design.engineGlow + 'bb');
        plasmaGrad.addColorStop(0.5, '#ff8800aa');
        plasmaGrad.addColorStop(0.75, '#ff440066');
        plasmaGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = plasmaGrad;
        ctx.beginPath();
        ctx.moveTo(ox - 4.5, 33);
        ctx.bezierCurveTo(
            ox - 6, 33 + flameLen * 0.35,
            ox - 2, 33 + flameLen * 0.7,
            ox, 33 + flameLen * 0.8
        );
        ctx.bezierCurveTo(
            ox + 2, 33 + flameLen * 0.7,
            ox + 6, 33 + flameLen * 0.35,
            ox + 4.5, 33
        );
        ctx.closePath();
        ctx.fill();
        
        // === FLAME LAYER 3: Afterburn trail (flickering) ===
        const flicker = Math.sin(time * 25 + idx * 3) * 0.3 + 0.7;
        const afterLen = flameLen * (0.6 + flicker * 0.5);
        
        const afterGrad = ctx.createLinearGradient(ox, 33 + flameLen * 0.4, ox, 33 + flameLen * 0.4 + afterLen);
        afterGrad.addColorStop(0, '#ff660044');
        afterGrad.addColorStop(0.3, '#ff330033');
        afterGrad.addColorStop(0.6, '#ff110018');
        afterGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = afterGrad;
        ctx.beginPath();
        const wobble = Math.sin(time * 30 + idx) * 1.5;
        ctx.moveTo(ox - 2.5, 33 + flameLen * 0.4);
        ctx.quadraticCurveTo(
            ox + wobble, 33 + flameLen * 0.4 + afterLen * 0.6,
            ox + wobble * 0.5, 33 + flameLen * 0.4 + afterLen
        );
        ctx.quadraticCurveTo(
            ox - wobble, 33 + flameLen * 0.4 + afterLen * 0.6,
            ox + 2.5, 33 + flameLen * 0.4
        );
        ctx.closePath();
        ctx.fill();
        
        // === HEAT HAZE (shimmer particles) ===
        if (!_isLowEnd() && shipCache.frame % 2 === 0) {
            ctx.fillStyle = hexToRgba(design.engineGlow, 0.15);
            for (let p = 0; p < 3; p++) {
                const py = 33 + flameLen * 0.5 + Math.random() * flameLen * 0.5;
                const px = ox + (Math.random() - 0.5) * 8;
                const ps = 0.5 + Math.random() * 1.5;
                ctx.beginPath();
                ctx.arc(px, py, ps, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    });
    
    // Engine glow on hull
    _safeShadow(design.engineGlow, 18 * basePulse);
}

// ============================================
// WINGS — multi-layer com flaps e estrutura
// ============================================
function drawWings(design, time) {
    _clearShadow();
    
    [1, -1].forEach(side => {
        const s = side;
        
        // === MAIN WING ===
        const wingGrad = ctx.createLinearGradient(0, 0, s * 50, 15);
        wingGrad.addColorStop(0, shadeColor(design.primary, -5));
        wingGrad.addColorStop(0.25, design.primary);
        wingGrad.addColorStop(0.5, shadeColor(design.primary, -12));
        wingGrad.addColorStop(0.75, shadeColor(design.primary, -25));
        wingGrad.addColorStop(1, shadeColor(design.primary, -45));
        
        ctx.fillStyle = wingGrad;
        ctx.beginPath();
        ctx.moveTo(s * 8, 5);
        ctx.lineTo(s * 48, -5);
        ctx.lineTo(s * 44, 8);
        ctx.lineTo(s * 42, 20);
        ctx.lineTo(s * 8, 25);
        ctx.closePath();
        ctx.fill();
        
        // Leading edge highlight (specular)
        ctx.strokeStyle = lightenColor(design.primary, 40) + '80';
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(s * 8, 5);
        ctx.lineTo(s * 48, -5);
        ctx.stroke();
        
        // Trailing edge shadow
        ctx.strokeStyle = shadeColor(design.primary, -50) + '50';
        ctx.lineWidth = 0.8;
        ctx.beginPath();
        ctx.moveTo(s * 12, 24);
        ctx.lineTo(s * 42, 20);
        ctx.stroke();
        
        // === WING PANEL LINES ===
        ctx.strokeStyle = shadeColor(design.primary, -30) + '55';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(s * 14, 7);
        ctx.lineTo(s * 44, 1);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(s * 16, 14);
        ctx.lineTo(s * 40, 10);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(s * 18, 20);
        ctx.lineTo(s * 38, 16);
        ctx.stroke();
        
        // Chord-wise ribs
        if (!_isLowEnd()) {
            for (let r = 18; r < 40; r += 7) {
                ctx.beginPath();
                ctx.moveTo(s * r, 5 + (r - 8) * -0.25);
                ctx.lineTo(s * r, 20 + (r - 8) * -0.12);
                ctx.stroke();
            }
        }
        
        // === WING RIVETS ===
        if (!_isLowEnd()) {
            ctx.fillStyle = lightenColor(design.primary, 20) + '60';
            for (let rv = 12; rv < 42; rv += 6) {
                const ry = 8 + (rv - 12) * -0.28;
                ctx.beginPath();
                ctx.arc(s * rv, ry, 0.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(s * rv, ry + 8, 0.5, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        
        // === WINGTIP (design.secondary) ===
        const tipGrad = ctx.createRadialGradient(s * 46, 0, 0, s * 46, 0, 8);
        tipGrad.addColorStop(0, design.secondary);
        tipGrad.addColorStop(0.6, shadeColor(design.secondary, -20));
        tipGrad.addColorStop(1, shadeColor(design.secondary, -50));
        
        ctx.fillStyle = tipGrad;
        ctx.beginPath();
        ctx.ellipse(s * 46, 0, 4.5, 9, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
        
        // Wingtip specular
        ctx.fillStyle = lightenColor(design.secondary, 50) + '55';
        ctx.beginPath();
        ctx.ellipse(s * 45, -3, 2, 3.5, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
        
        // === WING THRUSTER ===
        const thrPulse = Math.sin(time * 8 + (side > 0 ? 0 : Math.PI)) * 0.3 + 0.7;
        const thrGrad = ctx.createRadialGradient(s * 38, 18, 0, s * 38, 18, 6);
        thrGrad.addColorStop(0, '#ffffff' + Math.floor(thrPulse * 160).toString(16).padStart(2, '0'));
        thrGrad.addColorStop(0.3, design.engineGlow + Math.floor(thrPulse * 200).toString(16).padStart(2, '0'));
        thrGrad.addColorStop(0.7, design.engineGlow + '40');
        thrGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = thrGrad;
        ctx.beginPath();
        ctx.arc(s * 38, 18, 6, 0, Math.PI * 2);
        ctx.fill();
        
        // === FLAP (trailing edge) ===
        ctx.fillStyle = shadeColor(design.primary, -18);
        ctx.beginPath();
        ctx.moveTo(s * 18, 22);
        ctx.lineTo(s * 36, 18);
        ctx.lineTo(s * 35, 24);
        ctx.lineTo(s * 17, 27);
        ctx.closePath();
        ctx.fill();
        
        ctx.strokeStyle = shadeColor(design.primary, -40) + '40';
        ctx.lineWidth = 0.4;
        ctx.beginPath();
        ctx.moveTo(s * 18, 22);
        ctx.lineTo(s * 36, 18);
        ctx.stroke();
    });
}

// ============================================
// FUSELAGE — curvas suaves com painéis e reflexos
// ============================================
function drawFuselage(design, time) {
    _clearShadow();
    
    // === MAIN BODY (bezier curves) ===
    const bodyGrad = ctx.createLinearGradient(-18, -38, 18, 30);
    bodyGrad.addColorStop(0, lightenColor(design.primary, 25));
    bodyGrad.addColorStop(0.15, lightenColor(design.primary, 10));
    bodyGrad.addColorStop(0.35, design.primary);
    bodyGrad.addColorStop(0.55, shadeColor(design.primary, -12));
    bodyGrad.addColorStop(0.75, shadeColor(design.primary, -28));
    bodyGrad.addColorStop(1, shadeColor(design.primary, -45));
    
    ctx.fillStyle = bodyGrad;
    ctx.beginPath();
    ctx.moveTo(0, -38);
    ctx.bezierCurveTo(9, -33, 14, -22, 15, -8);
    ctx.bezierCurveTo(16, 8, 15, 20, 9, 28);
    ctx.lineTo(-9, 28);
    ctx.bezierCurveTo(-15, 20, -16, 8, -15, -8);
    ctx.bezierCurveTo(-14, -22, -9, -33, 0, -38);
    ctx.closePath();
    ctx.fill();
    
    // === NOSE HIGHLIGHT (specular reflection) ===
    ctx.strokeStyle = lightenColor(design.primary, 50) + '70';
    ctx.lineWidth = 1.8;
    ctx.beginPath();
    ctx.moveTo(-6, -36);
    ctx.quadraticCurveTo(0, -39, 6, -36);
    ctx.stroke();
    
    ctx.strokeStyle = lightenColor(design.primary, 30) + '40';
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    ctx.moveTo(-4, -35);
    ctx.quadraticCurveTo(0, -37.5, 4, -35);
    ctx.stroke();
    
    // === CENTER RIDGE (spine) ===
    const ridgeGrad = ctx.createLinearGradient(-3, -32, 3, 22);
    ridgeGrad.addColorStop(0, lightenColor(design.secondary, 25));
    ridgeGrad.addColorStop(0.2, design.secondary);
    ridgeGrad.addColorStop(0.6, shadeColor(design.secondary, -15));
    ridgeGrad.addColorStop(1, shadeColor(design.secondary, -30));
    
    ctx.fillStyle = ridgeGrad;
    ctx.beginPath();
    ctx.moveTo(0, -33);
    ctx.lineTo(3.5, -26);
    ctx.lineTo(3.5, 16);
    ctx.quadraticCurveTo(2, 22, 0, 24);
    ctx.quadraticCurveTo(-2, 22, -3.5, 16);
    ctx.lineTo(-3.5, -26);
    ctx.closePath();
    ctx.fill();
    
    // Ridge specular line
    ctx.strokeStyle = lightenColor(design.secondary, 40) + '55';
    ctx.lineWidth = 0.6;
    ctx.beginPath();
    ctx.moveTo(0, -32);
    ctx.lineTo(0, 20);
    ctx.stroke();
    
    // === AMBIENT OCCLUSION (wing junction shadows) ===
    if (!_isLowEnd()) {
        const aoGrad = ctx.createLinearGradient(0, 0, 18, 0);
        aoGrad.addColorStop(0, 'transparent');
        aoGrad.addColorStop(0.5, 'rgba(0,0,0,0.12)');
        aoGrad.addColorStop(1, 'transparent');
        ctx.fillStyle = aoGrad;
        ctx.fillRect(-18, 2, 36, 8);
    }
}

// ============================================
// PANEL DETAILS — linhas, rebites, vents, greeblies
// ============================================
function drawPanelDetails(design, time) {
    // === PANEL SEAM LINES ===
    ctx.strokeStyle = shadeColor(design.primary, -35) + '50';
    ctx.lineWidth = 0.5;
    
    [-22, -12, 2, 12].forEach(y => {
        ctx.beginPath();
        ctx.moveTo(-13, y);
        ctx.lineTo(13, y);
        ctx.stroke();
    });
    
    // Diagonal panel (near cockpit)
    ctx.beginPath();
    ctx.moveTo(-10, -24);
    ctx.lineTo(-14, -10);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(10, -24);
    ctx.lineTo(14, -10);
    ctx.stroke();
    
    // === SIDE VENTS (intake/exhaust) ===
    [-1, 1].forEach(side => {
        ctx.fillStyle = '#0e0e18';
        ctx.beginPath();
        ctx.roundRect(side * 9, -16, 3.5, 8, 1);
        ctx.fill();
        
        // Vent slats
        ctx.strokeStyle = '#2a2a3a';
        ctx.lineWidth = 0.4;
        for (let vy = -15; vy < -9; vy += 2) {
            ctx.beginPath();
            ctx.moveTo(side * 9.5, vy);
            ctx.lineTo(side * 12, vy);
            ctx.stroke();
        }
        
        // Lower heat vent
        ctx.fillStyle = '#0e0e18';
        ctx.beginPath();
        ctx.roundRect(side * 7, 4, 4.5, 7, 1);
        ctx.fill();
        
        // Heat glow inside vent
        if (!_isLowEnd()) {
            const heatPulse = Math.sin(time * 5 + side) * 0.1 + 0.15;
            ctx.fillStyle = hexToRgba(design.engineGlow, heatPulse);
            ctx.beginPath();
            ctx.roundRect(side * 7.5, 5, 3.5, 5, 0.5);
            ctx.fill();
        }
    });
    
    // === HULL RIVETS ===
    if (!_isLowEnd()) {
        ctx.fillStyle = lightenColor(design.primary, 15) + '55';
        for (let ry = -28; ry < 24; ry += 5) {
            const rx = 12 + Math.sin(ry * 0.08) * 2;
            ctx.beginPath();
            ctx.arc(-rx, ry, 0.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(rx, ry, 0.5, 0, Math.PI * 2);
            ctx.fill();
        }
    }
    
    // === ACCENT STRIPE ===
    ctx.fillStyle = design.accent + '30';
    ctx.beginPath();
    ctx.moveTo(-14, -6);
    ctx.lineTo(14, -6);
    ctx.lineTo(14, -3);
    ctx.lineTo(-14, -3);
    ctx.closePath();
    ctx.fill();
    
    // === TAIL SECTION ===
    ctx.fillStyle = shadeColor(design.primary, -20);
    ctx.beginPath();
    ctx.moveTo(-7, 22);
    ctx.lineTo(7, 22);
    ctx.lineTo(8, 28);
    ctx.lineTo(-8, 28);
    ctx.closePath();
    ctx.fill();
    
    ctx.fillStyle = design.accent + '88';
    ctx.beginPath();
    ctx.arc(-5, 25, 1, 0, Math.PI * 2);
    ctx.arc(5, 25, 1, 0, Math.PI * 2);
    ctx.fill();
}

// ============================================
// COCKPIT — multi-layer glass com HUD interno
// ============================================
function drawCockpit(design, time) {
    const shimmer = Math.sin(time * 2.5) * 0.1 + 0.9;
    
    // === COCKPIT FRAME ===
    const frameGrad = ctx.createLinearGradient(-11, -30, 11, -8);
    frameGrad.addColorStop(0, '#6a6a7a');
    frameGrad.addColorStop(0.3, '#4a4a5a');
    frameGrad.addColorStop(0.7, '#3a3a4a');
    frameGrad.addColorStop(1, '#4a4a5a');
    
    ctx.fillStyle = frameGrad;
    ctx.beginPath();
    ctx.moveTo(0, -31);
    ctx.lineTo(10.5, -18);
    ctx.lineTo(9, -7);
    ctx.lineTo(-9, -7);
    ctx.lineTo(-10.5, -18);
    ctx.closePath();
    ctx.fill();
    
    // Frame edge highlight
    ctx.strokeStyle = '#7a7a8a80';
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    ctx.moveTo(0, -31);
    ctx.lineTo(10.5, -18);
    ctx.moveTo(0, -31);
    ctx.lineTo(-10.5, -18);
    ctx.stroke();
    
    // === GLASS ===
    const glassGrad = ctx.createLinearGradient(-9, -30, 9, -8);
    const shimAlpha = Math.floor(shimmer * 200).toString(16).padStart(2, '0');
    glassGrad.addColorStop(0, '#ffffff' + shimAlpha);
    glassGrad.addColorStop(0.15, lightenColor(design.cockpitTint, 35) + 'bb');
    glassGrad.addColorStop(0.45, design.cockpitTint + '99');
    glassGrad.addColorStop(0.75, shadeColor(design.cockpitTint, -25) + '88');
    glassGrad.addColorStop(1, shadeColor(design.primary, -35) + 'aa');
    
    ctx.fillStyle = glassGrad;
    ctx.beginPath();
    ctx.moveTo(0, -29);
    ctx.lineTo(9, -17);
    ctx.lineTo(7.5, -9);
    ctx.lineTo(-7.5, -9);
    ctx.lineTo(-9, -17);
    ctx.closePath();
    ctx.fill();
    
    // === GLASS REFLECTIONS ===
    ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
    ctx.beginPath();
    ctx.moveTo(-4, -28);
    ctx.lineTo(3, -28);
    ctx.lineTo(5.5, -21);
    ctx.lineTo(-2.5, -21);
    ctx.closePath();
    ctx.fill();
    
    ctx.fillStyle = 'rgba(255, 255, 255, 0.12)';
    ctx.beginPath();
    ctx.moveTo(-6, -20);
    ctx.lineTo(0, -20);
    ctx.lineTo(1, -15);
    ctx.lineTo(-5.5, -15);
    ctx.closePath();
    ctx.fill();
    
    // === HUD ELEMENTS ===
    if (!_isLowEnd()) {
        const hudFlicker = Math.sin(time * 4) * 0.15 + 0.5;
        const hudAlpha = Math.floor(hudFlicker * 80).toString(16).padStart(2, '0');
        const hudColor = '#00ff88' + hudAlpha;
        
        // Targeting reticle
        ctx.strokeStyle = hudColor;
        ctx.lineWidth = 0.3;
        ctx.beginPath();
        ctx.arc(0, -18, 2.5, 0, Math.PI * 2);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(-1, -18); ctx.lineTo(1, -18);
        ctx.moveTo(0, -19); ctx.lineTo(0, -17);
        ctx.stroke();
        
        // Data bars
        ctx.fillStyle = hudColor;
        ctx.fillRect(-5, -14, 3, 0.5);
        ctx.fillRect(-5, -12.5, 2, 0.5);
        ctx.fillRect(2, -14, 3, 0.5);
        ctx.fillRect(3, -12.5, 2, 0.5);
        
        // Status indicator
        if (Math.floor(time * 2) % 2 === 0) {
            ctx.fillStyle = '#00ff8844';
            ctx.fillRect(-1.5, -11, 3, 0.5);
        }
    }
    
    // === CANOPY FRAME LINES ===
    ctx.strokeStyle = '#5a5a6a88';
    ctx.lineWidth = 0.5;
    ctx.beginPath();
    ctx.moveTo(0, -29);
    ctx.lineTo(0, -9);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(-7, -17);
    ctx.lineTo(7, -17);
    ctx.stroke();
}

// ============================================
// WEAPON PODS — detailed barrels com glow
// ============================================
function drawWeaponPods(design, time) {
    const chargePulse = Math.sin(time * 6) * 0.25 + 0.75;
    
    [-1, 1].forEach((side, idx) => {
        const x = side * 16;
        
        // === PYLON ===
        ctx.fillStyle = '#3a3a4a';
        ctx.beginPath();
        ctx.roundRect(x - 1.5, -6, 3, 8, 0.5);
        ctx.fill();
        
        // === WEAPON HOUSING ===
        const mountGrad = ctx.createLinearGradient(x - 3.5, -22, x + 3.5, -6);
        mountGrad.addColorStop(0, '#555565');
        mountGrad.addColorStop(0.3, '#3a3a4a');
        mountGrad.addColorStop(0.7, '#2a2a35');
        mountGrad.addColorStop(1, '#3a3a4a');
        
        ctx.fillStyle = mountGrad;
        ctx.beginPath();
        ctx.roundRect(x - 3.5, -23, 7, 16, 2);
        ctx.fill();
        
        ctx.strokeStyle = '#5a5a6a55';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.roundRect(x - 3.5, -23, 7, 16, 2);
        ctx.stroke();
        
        // === GUN BARREL ===
        const barrelGrad = ctx.createLinearGradient(x, -36, x, -23);
        barrelGrad.addColorStop(0, '#2a2a35');
        barrelGrad.addColorStop(0.2, '#4a4a5a');
        barrelGrad.addColorStop(0.5, '#3a3a4a');
        barrelGrad.addColorStop(0.8, '#4a4a5a');
        barrelGrad.addColorStop(1, '#2a2a35');
        
        ctx.fillStyle = barrelGrad;
        ctx.beginPath();
        ctx.roundRect(x - 2, -36, 4, 14, 1);
        ctx.fill();
        
        // Barrel bore
        ctx.fillStyle = '#0e0e14';
        ctx.beginPath();
        ctx.arc(x, -36, 2, 0, Math.PI * 2);
        ctx.fill();
        
        // === MUZZLE CHARGE GLOW ===
        const chargeAlpha = Math.floor(chargePulse * 220).toString(16).padStart(2, '0');
        ctx.fillStyle = design.accent + chargeAlpha;
        ctx.beginPath();
        ctx.arc(x, -36, 1.3, 0, Math.PI * 2);
        ctx.fill();
        
        _safeShadow(design.accent, 8 * chargePulse);
        ctx.fillStyle = design.accent + Math.floor(chargePulse * 150).toString(16).padStart(2, '0');
        ctx.beginPath();
        ctx.arc(x, -36, 0.8, 0, Math.PI * 2);
        ctx.fill();
        _clearShadow();
        
        // === AMMO INDICATOR LEDs ===
        if (!_isLowEnd()) {
            ctx.fillStyle = '#00ff5555';
            for (let ly = -20; ly > -23; ly -= 2) {
                ctx.beginPath();
                ctx.arc(x + 2.5, ly, 0.4, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    });
}

// ============================================
// NAVIGATION LIGHTS — aviation + bloom
// ============================================
function drawNavigationLights(design, time) {
    const blink = Math.floor(time * 2) % 2 === 0;
    const fastBlink = Math.floor(time * 4) % 2 === 0;
    
    // GREEN STARBOARD
    if (blink) {
        _safeShadow('#00ff00', 10);
        ctx.fillStyle = '#00ff0033';
        ctx.beginPath();
        ctx.arc(42, -2, 5, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = blink ? '#00ff00' : '#003300';
    ctx.beginPath();
    ctx.arc(42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // RED PORT
    if (blink) {
        _safeShadow('#ff0000', 10);
        ctx.fillStyle = '#ff000033';
        ctx.beginPath();
        ctx.arc(-42, -2, 5, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = blink ? '#ff0000' : '#330000';
    ctx.beginPath();
    ctx.arc(-42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // WHITE NOSE BEACON
    ctx.fillStyle = '#ffffff';
    _safeShadow('#ffffff', 8);
    ctx.beginPath();
    ctx.arc(0, -37, 1.5, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // AMBER TAIL STROBE
    if (fastBlink) {
        _safeShadow('#ffaa00', 8);
        ctx.fillStyle = '#ffaa0033';
        ctx.beginPath();
        ctx.arc(0, 27, 4, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = fastBlink ? '#ffaa00' : '#442200';
    ctx.beginPath();
    ctx.arc(0, 27, 1.5, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // WINGTIP ACCENT
    [44, -44].forEach(x => {
        ctx.fillStyle = design.accent + (blink ? 'cc' : '33');
        if (blink) _safeShadow(design.accent, 6);
        ctx.beginPath();
        ctx.arc(x, 2, 1.5, 0, Math.PI * 2);
        ctx.fill();
    });
    _clearShadow();
}

// ============================================
// SPEED TRAILS — motion blur when turning
// ============================================
function drawSpeedTrails(design, time, tilt) {
    if (Math.abs(tilt) < 0.03) return;
    
    const trailAlpha = Math.min(Math.abs(tilt) * 5, 0.7);
    const dir = tilt > 0 ? -1 : 1;
    
    for (let layer = 0; layer < 2; layer++) {
        const layerAlpha = trailAlpha * (1 - layer * 0.4);
        ctx.strokeStyle = design.primary + Math.floor(layerAlpha * 100).toString(16).padStart(2, '0');
        ctx.lineWidth = 1 - layer * 0.4;
        
        for (let i = 0; i < 5; i++) {
            const startY = -28 + i * 14;
            const length = 14 + Math.random() * 10;
            const x = dir * -(32 + i * 4 + layer * 6);
            
            ctx.beginPath();
            ctx.moveTo(x, startY);
            ctx.lineTo(x + dir * length, startY + 3);
            ctx.stroke();
        }
    }
    
    // Engine trail extension when turning hard
    if (Math.abs(tilt) > 0.1) {
        ctx.strokeStyle = hexToRgba(design.engineGlow, trailAlpha * 0.4);
        ctx.lineWidth = 1.5;
        [-10, 10].forEach(ox => {
            ctx.beginPath();
            ctx.moveTo(ox, 38);
            ctx.quadraticCurveTo(ox + dir * 8, 50, ox + dir * 15, 58);
            ctx.stroke();
        });
    }
}

// ============================================
// EXPORTS
// ============================================
window.drawShipAAA = drawShipAAA;
window.shadeColor = shadeColor;
window.lightenColor = lightenColor;

console.log('📦 ship-renderer.js v5.0 AAA+++ carregado');
