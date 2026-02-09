/* ============================================
   UNOBIX - Ship Renderer v5.0 AAA+++
   File: js/ship-renderer.js
   Baseado no v3.1 (funcionava) + upgrades visuais
   
   v5.0: Qualidade AAA+++ com segurança
   - Polyfill roundRect para browsers antigos
   - Try-catch defensivo em cada camada
   - Detalhes extras em high-end devices
   - iOS optimization mantida
   ============================================ */

let shipCache = {
    lastDesign: null,
    gradients: {},
    time: 0,
    frame: 0
};

// ============================================
// POLYFILL roundRect (browsers antigos)
// ============================================
if (typeof CanvasRenderingContext2D !== 'undefined' && 
    !CanvasRenderingContext2D.prototype.roundRect) {
    CanvasRenderingContext2D.prototype.roundRect = function(x, y, w, h, r) {
        if (typeof r === 'number') r = [r, r, r, r];
        if (!Array.isArray(r)) r = [0, 0, 0, 0];
        const [tl, tr, br, bl] = r.length === 1 ? [r[0], r[0], r[0], r[0]] : r;
        this.moveTo(x + tl, y);
        this.lineTo(x + w - tr, y);
        this.arcTo(x + w, y, x + w, y + tr, tr);
        this.lineTo(x + w, y + h - br);
        this.arcTo(x + w, y + h, x + w - br, y + h, br);
        this.lineTo(x + bl, y + h);
        this.arcTo(x, y + h, x, y + h - bl, bl);
        this.lineTo(x, y + tl);
        this.arcTo(x, y, x + tl, y, tl);
        this.closePath();
        return this;
    };
}

// ============================================
// HELPERS
// ============================================
function _safeShadow(color, blur) {
    try {
        if (typeof DeviceProfile !== 'undefined' && DeviceProfile.maxShadowBlur !== undefined) {
            ctx.shadowColor = color;
            ctx.shadowBlur = Math.min(blur, DeviceProfile.maxShadowBlur);
        } else {
            ctx.shadowColor = color;
            ctx.shadowBlur = blur;
        }
    } catch(e) { ctx.shadowBlur = 0; }
}

function _clearShadow() {
    ctx.shadowColor = 'transparent';
    ctx.shadowBlur = 0;
}

function _isHighEnd() {
    if (typeof DeviceProfile !== 'undefined') {
        return DeviceProfile.quality === 'high';
    }
    return true; // default to high
}

function shadeColor(color, percent) {
    if (!color || color.length < 7) return color || '#888888';
    try {
        const num = parseInt(color.replace('#', ''), 16);
        const amt = Math.round(2.55 * percent);
        const R = Math.max(0, Math.min(255, (num >> 16) + amt));
        const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
        const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
        return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
    } catch(e) { return color; }
}

function lightenColor(color, percent) {
    return shadeColor(color, percent);
}

// Hex para rgba string (seguro)
function _rgba(hex, alpha) {
    if (!hex || hex.length < 7) return 'rgba(128,128,128,' + alpha + ')';
    try {
        const num = parseInt(hex.replace('#', ''), 16);
        return 'rgba(' + ((num >> 16) & 255) + ',' + ((num >> 8) & 255) + ',' + (num & 255) + ',' + alpha + ')';
    } catch(e) { return 'rgba(128,128,128,' + alpha + ')'; }
}

// ============================================
// MAIN DRAW
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
        if (flash) ctx.globalAlpha = 0.5;
    }
    
    try { drawAmbientGlow(design, time); } catch(e) {}
    try { drawMainEngines(design, time); } catch(e) {}
    try { drawWings(design, time); } catch(e) {}
    try { drawFuselage(design, time); } catch(e) {}
    try { drawPanelDetails(design, time); } catch(e) {}
    try { drawCockpit(design, time); } catch(e) {}
    try { drawWeaponPods(design, time); } catch(e) {}
    try { drawNavigationLights(design, time); } catch(e) {}
    try { drawSpeedTrails(design, time, tilt); } catch(e) {}
    
    ctx.globalAlpha = 1;
    gameState.lastX = ship.x;
    ctx.restore();
}

// ============================================
// AMBIENT GLOW (under-ship light) — NEW
// ============================================
function drawAmbientGlow(design, time) {
    if (!_isHighEnd()) return;
    
    const pulse = Math.sin(time * 3) * 0.08 + 0.2;
    const glowGrad = ctx.createRadialGradient(0, 5, 0, 0, 5, 50);
    glowGrad.addColorStop(0, _rgba(design.engineGlow, pulse));
    glowGrad.addColorStop(0.6, _rgba(design.primary, pulse * 0.2));
    glowGrad.addColorStop(1, 'transparent');
    
    ctx.fillStyle = glowGrad;
    ctx.beginPath();
    ctx.ellipse(0, 10, 48, 32, 0, 0, Math.PI * 2);
    ctx.fill();
}

// ============================================
// ENGINES — v3.1 base + terceira camada afterburn
// ============================================
function drawMainEngines(design, time) {
    const enginePulse = Math.sin(time * 12) * 0.15 + 0.85;
    const flameLength = 28 + Math.sin(time * 10) * 6;
    
    [-10, 10].forEach(function(offsetX, index) {
        var pulse = Math.sin(time * 14 + index * 0.5) * 0.12 + 0.88;
        var length = flameLength * pulse;
        
        // Nacelle housing (melhorado)
        var housingGrad = ctx.createLinearGradient(offsetX - 7, 18, offsetX + 7, 33);
        housingGrad.addColorStop(0, '#484858');
        housingGrad.addColorStop(0.3, '#2a2a36');
        housingGrad.addColorStop(0.7, '#1a1a24');
        housingGrad.addColorStop(1, '#0e0e16');
        
        ctx.fillStyle = housingGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 6, 20);
        ctx.lineTo(offsetX + 6, 20);
        ctx.lineTo(offsetX + 5, 32);
        ctx.lineTo(offsetX - 5, 32);
        ctx.closePath();
        ctx.fill();
        
        // Nacelle rivets
        if (_isHighEnd()) {
            ctx.fillStyle = '#5a5a6a';
            [22, 26, 30].forEach(function(ry) {
                ctx.beginPath();
                ctx.arc(offsetX - 5.5, ry, 0.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(offsetX + 5.5, ry, 0.5, 0, Math.PI * 2);
                ctx.fill();
            });
        }
        
        // Nozzle rings
        ctx.strokeStyle = '#6a6a7a';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.ellipse(offsetX, 32, 5, 2, 0, 0, Math.PI * 2);
        ctx.stroke();
        
        ctx.strokeStyle = '#3a3a4a';
        ctx.lineWidth = 0.7;
        ctx.beginPath();
        ctx.ellipse(offsetX, 32, 3.5, 1.3, 0, 0, Math.PI * 2);
        ctx.stroke();
        
        // FLAME 1: Core (white-hot)
        var coreGrad = ctx.createLinearGradient(offsetX, 32, offsetX, 32 + length * 0.5);
        coreGrad.addColorStop(0, '#ffffff');
        coreGrad.addColorStop(0.2, _rgba(design.engineGlow, 0.9));
        coreGrad.addColorStop(0.6, _rgba(design.engineGlow, 0.5));
        coreGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = coreGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 2.5, 32);
        ctx.quadraticCurveTo(offsetX - 3, 32 + length * 0.3, offsetX, 32 + length * 0.5);
        ctx.quadraticCurveTo(offsetX + 3, 32 + length * 0.3, offsetX + 2.5, 32);
        ctx.closePath();
        ctx.fill();
        
        // FLAME 2: Plasma (main glow) — original
        var flameGrad = ctx.createLinearGradient(offsetX, 32, offsetX, 32 + length);
        flameGrad.addColorStop(0, _rgba(design.engineGlow, 0.87));
        flameGrad.addColorStop(0.25, 'rgba(255,136,0,0.8)');
        flameGrad.addColorStop(0.5, 'rgba(255,68,0,0.67)');
        flameGrad.addColorStop(0.75, 'rgba(255,34,0,0.33)');
        flameGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = flameGrad;
        ctx.beginPath();
        ctx.moveTo(offsetX - 4, 32);
        ctx.bezierCurveTo(offsetX - 6, 32 + length * 0.4, offsetX - 2, 32 + length * 0.8, offsetX, 32 + length);
        ctx.bezierCurveTo(offsetX + 2, 32 + length * 0.8, offsetX + 6, 32 + length * 0.4, offsetX + 4, 32);
        ctx.closePath();
        ctx.fill();
        
        // FLAME 3: Afterburn trail (NEW — flickering)
        if (_isHighEnd()) {
            var flicker = Math.sin(time * 25 + index * 3) * 0.3 + 0.7;
            var afterLen = length * (0.5 + flicker * 0.4);
            var wobble = Math.sin(time * 30 + index) * 1.5;
            
            var afterGrad = ctx.createLinearGradient(offsetX, 32 + length * 0.5, offsetX, 32 + length * 0.5 + afterLen);
            afterGrad.addColorStop(0, 'rgba(255,100,0,0.25)');
            afterGrad.addColorStop(0.4, 'rgba(255,50,0,0.15)');
            afterGrad.addColorStop(1, 'transparent');
            
            ctx.fillStyle = afterGrad;
            ctx.beginPath();
            ctx.moveTo(offsetX - 2, 32 + length * 0.5);
            ctx.quadraticCurveTo(offsetX + wobble, 32 + length * 0.5 + afterLen * 0.6, offsetX, 32 + length * 0.5 + afterLen);
            ctx.quadraticCurveTo(offsetX - wobble, 32 + length * 0.5 + afterLen * 0.6, offsetX + 2, 32 + length * 0.5);
            ctx.closePath();
            ctx.fill();
        }
        
        // Heat shimmer particles (NEW)
        if (_isHighEnd() && shipCache.frame % 3 === 0) {
            ctx.fillStyle = _rgba(design.engineGlow, 0.12);
            for (var p = 0; p < 2; p++) {
                var py = 32 + length * 0.6 + Math.random() * length * 0.4;
                var px = offsetX + (Math.random() - 0.5) * 6;
                ctx.beginPath();
                ctx.arc(px, py, 0.5 + Math.random() * 1.2, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    });
    
    _safeShadow(design.engineGlow, 20 * enginePulse);
}

// ============================================
// WINGS — v3.1 base + flaps, rivets, specular
// ============================================
function drawWings(design, time) {
    _clearShadow();
    
    [1, -1].forEach(function(side) {
        var x = side;
        
        // Main wing (original shape)
        var wingGrad = ctx.createLinearGradient(0, 0, side * 45, 15);
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
        
        // Leading edge specular (original)
        ctx.strokeStyle = _rgba(lightenColor(design.primary, 30), 0.5);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x * 8, 5);
        ctx.lineTo(x * 48, -5);
        ctx.stroke();
        
        // Trailing edge shadow (NEW)
        ctx.strokeStyle = _rgba(shadeColor(design.primary, -50), 0.3);
        ctx.lineWidth = 0.7;
        ctx.beginPath();
        ctx.moveTo(x * 12, 24);
        ctx.lineTo(x * 42, 20);
        ctx.stroke();
        
        // Panel lines (original + extra)
        ctx.strokeStyle = _rgba(shadeColor(design.primary, -30), 0.35);
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(x * 15, 6);
        ctx.lineTo(x * 40, 0);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x * 18, 12);
        ctx.lineTo(x * 38, 8);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x * 20, 18);
        ctx.lineTo(x * 36, 14);
        ctx.stroke();
        
        // Wing rivets (NEW)
        if (_isHighEnd()) {
            ctx.fillStyle = _rgba(lightenColor(design.primary, 20), 0.4);
            for (var rv = 14; rv < 40; rv += 7) {
                var ry = 7 + (rv - 14) * -0.25;
                ctx.beginPath();
                ctx.arc(x * rv, ry, 0.5, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.arc(x * rv, ry + 7, 0.5, 0, Math.PI * 2);
                ctx.fill();
            }
        }
        
        // Wingtip (original)
        ctx.fillStyle = design.secondary;
        ctx.beginPath();
        ctx.ellipse(x * 46, 0, 4, 8, side * 0.2, 0, Math.PI * 2);
        ctx.fill();
        
        // Wingtip specular (NEW)
        if (_isHighEnd()) {
            ctx.fillStyle = _rgba(lightenColor(design.secondary, 50), 0.35);
            ctx.beginPath();
            ctx.ellipse(x * 45, -3, 2, 3.5, side * 0.2, 0, Math.PI * 2);
            ctx.fill();
        }
        
        // Wing thruster (original)
        var thrusterGrad = ctx.createRadialGradient(x * 38, 18, 0, x * 38, 18, 5);
        thrusterGrad.addColorStop(0, design.engineGlow);
        thrusterGrad.addColorStop(0.5, _rgba(design.engineGlow, 0.5));
        thrusterGrad.addColorStop(1, 'transparent');
        
        ctx.fillStyle = thrusterGrad;
        ctx.beginPath();
        ctx.arc(x * 38, 18, 5, 0, Math.PI * 2);
        ctx.fill();
        
        // Flap (NEW — trailing edge)
        ctx.fillStyle = shadeColor(design.primary, -18);
        ctx.beginPath();
        ctx.moveTo(x * 18, 22);
        ctx.lineTo(x * 36, 18);
        ctx.lineTo(x * 35, 23);
        ctx.lineTo(x * 17, 27);
        ctx.closePath();
        ctx.fill();
    });
}

// ============================================
// FUSELAGE — v3.1 base (bezier curves, original shape)
// ============================================
function drawFuselage(design, time) {
    // Main body (ORIGINAL bezier shape)
    var bodyGrad = ctx.createLinearGradient(-15, -35, 15, 30);
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
    
    // Nose highlight (original + improved)
    ctx.strokeStyle = _rgba(lightenColor(design.primary, 40), 0.45);
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(-6, -35);
    ctx.quadraticCurveTo(0, -38, 6, -35);
    ctx.stroke();
    
    // Second nose highlight (NEW)
    if (_isHighEnd()) {
        ctx.strokeStyle = _rgba(lightenColor(design.primary, 50), 0.25);
        ctx.lineWidth = 0.7;
        ctx.beginPath();
        ctx.moveTo(-4, -34);
        ctx.quadraticCurveTo(0, -37, 4, -34);
        ctx.stroke();
    }
    
    // Center ridge (original)
    var ridgeGrad = ctx.createLinearGradient(-3, -30, 3, 20);
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
    
    // Ridge specular line (NEW)
    if (_isHighEnd()) {
        ctx.strokeStyle = _rgba(lightenColor(design.secondary, 40), 0.35);
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(0, -31);
        ctx.lineTo(0, 20);
        ctx.stroke();
    }
    
    // Panel seam lines (original)
    ctx.strokeStyle = _rgba(shadeColor(design.primary, -30), 0.3);
    ctx.lineWidth = 0.5;
    [-20, -10, 5, 15].forEach(function(y) {
        ctx.beginPath();
        ctx.moveTo(-12, y);
        ctx.lineTo(12, y);
        ctx.stroke();
    });
    
    // Side vents (original)
    ctx.fillStyle = '#1a1a24';
    [-1, 1].forEach(function(side) {
        ctx.fillRect(side * 8, -15, 3, 8);
        ctx.fillRect(side * 6, 5, 4, 6);
    });
    
    // Ambient occlusion at wing root (NEW)
    if (_isHighEnd()) {
        ctx.fillStyle = 'rgba(0,0,0,0.08)';
        ctx.fillRect(-16, 3, 32, 6);
    }
}

// ============================================
// PANEL DETAILS — NEW (all new details)
// ============================================
function drawPanelDetails(design, time) {
    if (!_isHighEnd()) return;
    
    // Diagonal seams near cockpit
    ctx.strokeStyle = _rgba(shadeColor(design.primary, -35), 0.3);
    ctx.lineWidth = 0.4;
    ctx.beginPath();
    ctx.moveTo(-10, -24);
    ctx.lineTo(-14, -10);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(10, -24);
    ctx.lineTo(14, -10);
    ctx.stroke();
    
    // Vent slats inside side vents
    ctx.strokeStyle = '#2a2a3a';
    ctx.lineWidth = 0.3;
    [-1, 1].forEach(function(side) {
        for (var vy = -14; vy < -8; vy += 2) {
            ctx.beginPath();
            ctx.moveTo(side * 8.5, vy);
            ctx.lineTo(side * 10.5, vy);
            ctx.stroke();
        }
    });
    
    // Heat glow inside lower vents
    var heatPulse = Math.sin(time * 5) * 0.08 + 0.1;
    ctx.fillStyle = _rgba(design.engineGlow, heatPulse);
    [-1, 1].forEach(function(side) {
        ctx.beginPath();
        ctx.fillRect(side * 6.5, 6, 3, 4);
    });
    
    // Hull rivets along body edge
    ctx.fillStyle = _rgba(lightenColor(design.primary, 15), 0.35);
    for (var ry = -26; ry < 22; ry += 5) {
        ctx.beginPath();
        ctx.arc(-13, ry, 0.4, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(13, ry, 0.4, 0, Math.PI * 2);
        ctx.fill();
    }
    
    // Accent stripe
    ctx.fillStyle = _rgba(design.accent, 0.2);
    ctx.fillRect(-13, -5, 26, 2.5);
    
    // Tail detail
    ctx.fillStyle = _rgba(design.accent, 0.5);
    ctx.beginPath();
    ctx.arc(-5, 25, 0.8, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(5, 25, 0.8, 0, Math.PI * 2);
    ctx.fill();
}

// ============================================
// COCKPIT — v3.1 base + HUD elements
// ============================================
function drawCockpit(design, time) {
    var shimmer = Math.sin(time * 2.5) * 0.1 + 0.9;
    
    // Frame (original)
    var frameGrad = ctx.createLinearGradient(-10, -28, 10, -10);
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
    
    // Frame edge highlights (NEW)
    ctx.strokeStyle = 'rgba(120,120,140,0.5)';
    ctx.lineWidth = 0.7;
    ctx.beginPath();
    ctx.moveTo(0, -30);
    ctx.lineTo(10, -18);
    ctx.moveTo(0, -30);
    ctx.lineTo(-10, -18);
    ctx.stroke();
    
    // Glass (original)
    var glassGrad = ctx.createLinearGradient(-8, -28, 8, -10);
    glassGrad.addColorStop(0, _rgba('#ffffff', shimmer * 0.7));
    glassGrad.addColorStop(0.2, _rgba(lightenColor(design.cockpitTint, 30), 0.67));
    glassGrad.addColorStop(0.5, _rgba(design.cockpitTint, 0.53));
    glassGrad.addColorStop(0.8, _rgba(shadeColor(design.cockpitTint, -20), 0.47));
    glassGrad.addColorStop(1, _rgba(shadeColor(design.primary, -30), 0.6));
    
    ctx.fillStyle = glassGrad;
    ctx.beginPath();
    ctx.moveTo(0, -28);
    ctx.lineTo(8, -17);
    ctx.lineTo(6, -10);
    ctx.lineTo(-6, -10);
    ctx.lineTo(-8, -17);
    ctx.closePath();
    ctx.fill();
    
    // Top reflection (original)
    ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
    ctx.beginPath();
    ctx.moveTo(-3, -26);
    ctx.lineTo(3, -26);
    ctx.lineTo(5, -20);
    ctx.lineTo(-2, -20);
    ctx.closePath();
    ctx.fill();
    
    // Lower reflection (NEW)
    ctx.fillStyle = 'rgba(255, 255, 255, 0.1)';
    ctx.beginPath();
    ctx.moveTo(-5, -19);
    ctx.lineTo(0, -19);
    ctx.lineTo(1, -14);
    ctx.lineTo(-4.5, -14);
    ctx.closePath();
    ctx.fill();
    
    // HUD instruments (original - updated)
    var hudAlpha = Math.sin(time * 4) * 0.1 + 0.35;
    ctx.fillStyle = _rgba('#00ff88', hudAlpha);
    ctx.fillRect(-4, -18, 2, 1);
    ctx.fillRect(1, -16, 3, 1);
    ctx.fillRect(-2, -14, 4, 1);
    
    // HUD targeting reticle (NEW)
    if (_isHighEnd()) {
        ctx.strokeStyle = _rgba('#00ff88', hudAlpha * 0.6);
        ctx.lineWidth = 0.25;
        ctx.beginPath();
        ctx.arc(0, -18, 2, 0, Math.PI * 2);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(-0.8, -18); ctx.lineTo(0.8, -18);
        ctx.moveTo(0, -18.8); ctx.lineTo(0, -17.2);
        ctx.stroke();
    }
    
    // Canopy frame lines (NEW)
    if (_isHighEnd()) {
        ctx.strokeStyle = 'rgba(90,90,106,0.4)';
        ctx.lineWidth = 0.4;
        ctx.beginPath();
        ctx.moveTo(0, -28);
        ctx.lineTo(0, -10);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(-6.5, -17);
        ctx.lineTo(6.5, -17);
        ctx.stroke();
    }
}

// ============================================
// WEAPON PODS — v3.1 base + pylon + LEDs
// ============================================
function drawWeaponPods(design, time) {
    var chargePulse = Math.sin(time * 6) * 0.25 + 0.75;
    
    [-1, 1].forEach(function(side) {
        var x = side * 16;
        
        // Pylon (NEW — connection to wing)
        if (_isHighEnd()) {
            ctx.fillStyle = '#3a3a4a';
            ctx.fillRect(x - 1, -4, 2, 6);
        }
        
        // Mount housing (original)
        var mountGrad = ctx.createLinearGradient(x - 3, -20, x + 3, -5);
        mountGrad.addColorStop(0, '#4a4a5a');
        mountGrad.addColorStop(0.5, '#2a2a35');
        mountGrad.addColorStop(1, '#3a3a4a');
        
        ctx.fillStyle = mountGrad;
        ctx.beginPath();
        ctx.roundRect(x - 3, -22, 6, 14, 2);
        ctx.fill();
        
        // Housing edge (NEW)
        if (_isHighEnd()) {
            ctx.strokeStyle = 'rgba(90,90,106,0.3)';
            ctx.lineWidth = 0.4;
            ctx.beginPath();
            ctx.roundRect(x - 3, -22, 6, 14, 2);
            ctx.stroke();
        }
        
        // Barrel (original)
        var barrelGrad = ctx.createLinearGradient(x, -32, x, -22);
        barrelGrad.addColorStop(0, '#2a2a35');
        barrelGrad.addColorStop(0.3, '#4a4a5a');
        barrelGrad.addColorStop(0.7, '#3a3a4a');
        barrelGrad.addColorStop(1, '#2a2a35');
        
        ctx.fillStyle = barrelGrad;
        ctx.beginPath();
        ctx.roundRect(x - 2, -34, 4, 14, 1);
        ctx.fill();
        
        // Bore (original)
        ctx.fillStyle = '#1a1a22';
        ctx.beginPath();
        ctx.arc(x, -34, 2, 0, Math.PI * 2);
        ctx.fill();
        
        // Charge glow (original)
        ctx.fillStyle = _rgba(design.accent, chargePulse * 0.8);
        ctx.beginPath();
        ctx.arc(x, -34, 1.2, 0, Math.PI * 2);
        ctx.fill();
        
        _safeShadow(design.accent, 6 * chargePulse);
        ctx.beginPath();
        ctx.arc(x, -34, 0.8, 0, Math.PI * 2);
        ctx.fill();
        _clearShadow();
        
        // Ammo LEDs (NEW)
        if (_isHighEnd()) {
            ctx.fillStyle = 'rgba(0,255,80,0.3)';
            [-20, -18].forEach(function(ly) {
                ctx.beginPath();
                ctx.arc(x + 2.2, ly, 0.35, 0, Math.PI * 2);
                ctx.fill();
            });
        }
    });
}

// ============================================
// NAVIGATION LIGHTS — v3.1 base + bloom rings
// ============================================
function drawNavigationLights(design, time) {
    var blink = Math.floor(time * 2) % 2 === 0;
    var fastBlink = Math.floor(time * 4) % 2 === 0;
    
    // Green starboard
    if (blink && _isHighEnd()) {
        ctx.fillStyle = 'rgba(0,255,0,0.15)';
        ctx.beginPath();
        ctx.arc(42, -2, 5, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = blink ? '#00ff00' : '#003300';
    if (blink) _safeShadow('#00ff00', 8);
    ctx.beginPath();
    ctx.arc(42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // Red port
    if (blink && _isHighEnd()) {
        ctx.fillStyle = 'rgba(255,0,0,0.15)';
        ctx.beginPath();
        ctx.arc(-42, -2, 5, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = blink ? '#ff0000' : '#330000';
    if (blink) _safeShadow('#ff0000', 8);
    ctx.beginPath();
    ctx.arc(-42, -2, 2, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // White nose
    ctx.fillStyle = '#ffffff';
    _safeShadow('#ffffff', 6);
    ctx.beginPath();
    ctx.arc(0, -36, 1.5, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // Amber tail
    if (fastBlink && _isHighEnd()) {
        ctx.fillStyle = 'rgba(255,170,0,0.15)';
        ctx.beginPath();
        ctx.arc(0, 26, 4, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.fillStyle = fastBlink ? '#ffaa00' : '#442200';
    if (fastBlink) _safeShadow('#ffaa00', 6);
    ctx.beginPath();
    ctx.arc(0, 26, 1.5, 0, Math.PI * 2);
    ctx.fill();
    _clearShadow();
    
    // Wingtip accents (original)
    [44, -44].forEach(function(wx) {
        ctx.fillStyle = _rgba(design.accent, blink ? 0.8 : 0.25);
        if (blink) _safeShadow(design.accent, 5);
        ctx.beginPath();
        ctx.arc(wx, 2, 1.5, 0, Math.PI * 2);
        ctx.fill();
    });
    _clearShadow();
}

// ============================================
// SPEED TRAILS — v3.1 base + engine trail
// ============================================
function drawSpeedTrails(design, time, tilt) {
    if (Math.abs(tilt) < 0.03) return;
    
    var trailAlpha = Math.min(Math.abs(tilt) * 4, 0.6);
    var dir = tilt > 0 ? -1 : 1;
    
    ctx.strokeStyle = _rgba(design.primary, trailAlpha);
    ctx.lineWidth = 1;
    
    for (var i = 0; i < 4; i++) {
        var startY = -25 + i * 15;
        var length = 12 + Math.random() * 8;
        var x = dir * -(30 + i * 5);
        
        ctx.beginPath();
        ctx.moveTo(x, startY);
        ctx.lineTo(x + dir * length, startY + 4);
        ctx.stroke();
    }
    
    // Engine trail when turning hard (NEW)
    if (_isHighEnd() && Math.abs(tilt) > 0.1) {
        ctx.strokeStyle = _rgba(design.engineGlow, trailAlpha * 0.3);
        ctx.lineWidth = 1.2;
        [-10, 10].forEach(function(ox) {
            ctx.beginPath();
            ctx.moveTo(ox, 36);
            ctx.quadraticCurveTo(ox + dir * 6, 46, ox + dir * 12, 54);
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

console.log('\u{1F4E6} ship-renderer.js v5.0 AAA+++ carregado');
