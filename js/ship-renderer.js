/* ============================================
   UNOBIX - Ship Renderer v4.0
   File: js/ship-renderer.js
   Cada nave tem silhueta única via shipShape
   
   v4.0: Formas distintas por nave
   - fighter: Caça clássico (Phoenix)
   - bomber: Bombardeiro largo (Guardian)
   - delta: Asa delta (Thunder)
   - heavy: Caça pesado com canhões (Inferno)
   - stealth: Angular facetado (Nebula)
   - viper: Estreito e longo (Viper)
   - brawler: Largo com escudos (Wolf)
   - xwing: Asas X com painéis solares (Hawk)
   ============================================ */

let shipCache = { lastDesign: null, gradients: {}, time: 0 };

function _safeShadow(color, blur) {
    if (typeof DeviceProfile !== 'undefined' && DeviceProfile.maxShadowBlur !== undefined) {
        ctx.shadowColor = color;
        ctx.shadowBlur = Math.min(blur, DeviceProfile.maxShadowBlur);
    } else {
        ctx.shadowColor = color;
        ctx.shadowBlur = blur;
    }
}

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
    
    // Selecionar renderer por shape
    const shape = design.shipShape || 'fighter';
    
    switch (shape) {
        case 'bomber':   drawShipBomber(design, time); break;
        case 'delta':    drawShipDelta(design, time); break;
        case 'heavy':    drawShipHeavy(design, time); break;
        case 'stealth':  drawShipStealth(design, time); break;
        case 'viper':    drawShipViper(design, time); break;
        case 'brawler':  drawShipBrawler(design, time); break;
        case 'xwing':    drawShipXWing(design, time); break;
        default:         drawShipFighter(design, time); break;
    }
    
    // Luzes de navegação (universal)
    drawNavLights(design, time);
    drawSpeedTrails(design, time, tilt);
    
    ctx.globalAlpha = 1;
    gameState.lastX = ship.x;
    ctx.restore();
}

// ============================================
// COMMON HELPERS
// ============================================

function _drawEngineFlame(x, y, length, design, time, index) {
    const pulse = Math.sin(time * 14 + (index || 0) * 0.5) * 0.12 + 0.88;
    const len = length * pulse;
    
    // Nozzle
    ctx.fillStyle = '#1a1a24';
    ctx.beginPath();
    ctx.ellipse(x, y, 4, 2, 0, 0, Math.PI * 2);
    ctx.fill();
    
    // Core flame
    const coreGrad = ctx.createLinearGradient(x, y, x, y + len * 0.5);
    coreGrad.addColorStop(0, '#ffffff');
    coreGrad.addColorStop(0.4, design.engineGlow);
    coreGrad.addColorStop(1, 'transparent');
    ctx.fillStyle = coreGrad;
    ctx.beginPath();
    ctx.moveTo(x - 2, y);
    ctx.quadraticCurveTo(x, y + len * 0.5, x, y + len * 0.55);
    ctx.quadraticCurveTo(x, y + len * 0.5, x + 2, y);
    ctx.closePath();
    ctx.fill();
    
    // Outer flame
    const flameGrad = ctx.createLinearGradient(x, y, x, y + len);
    flameGrad.addColorStop(0, design.engineGlow + 'cc');
    flameGrad.addColorStop(0.3, '#ff8800aa');
    flameGrad.addColorStop(0.6, '#ff440066');
    flameGrad.addColorStop(1, 'transparent');
    ctx.fillStyle = flameGrad;
    ctx.beginPath();
    ctx.moveTo(x - 3.5, y);
    ctx.bezierCurveTo(x - 5, y + len * 0.4, x - 1.5, y + len * 0.8, x, y + len);
    ctx.bezierCurveTo(x + 1.5, y + len * 0.8, x + 5, y + len * 0.4, x + 3.5, y);
    ctx.closePath();
    ctx.fill();
}

function _drawCockpitGlass(cx, cy, w, h, design, time) {
    const shimmer = Math.sin(time * 2.5) * 0.1 + 0.9;
    
    // Frame
    ctx.fillStyle = '#3a3a4a';
    ctx.beginPath();
    ctx.ellipse(cx, cy, w + 2, h + 2, 0, 0, Math.PI * 2);
    ctx.fill();
    
    // Glass
    const glassGrad = ctx.createLinearGradient(cx - w, cy - h, cx + w, cy + h);
    glassGrad.addColorStop(0, '#ffffff' + Math.floor(shimmer * 140).toString(16).padStart(2, '0'));
    glassGrad.addColorStop(0.3, lightenColor(design.cockpitTint, 20) + 'aa');
    glassGrad.addColorStop(0.6, design.cockpitTint + '77');
    glassGrad.addColorStop(1, shadeColor(design.cockpitTint, -30) + '66');
    ctx.fillStyle = glassGrad;
    ctx.beginPath();
    ctx.ellipse(cx, cy, w, h, 0, 0, Math.PI * 2);
    ctx.fill();
    
    // Highlight
    ctx.fillStyle = 'rgba(255,255,255,0.3)';
    ctx.beginPath();
    ctx.ellipse(cx - w * 0.2, cy - h * 0.3, w * 0.4, h * 0.3, -0.3, 0, Math.PI * 2);
    ctx.fill();
}

function _fillBody(points, design) {
    const bodyGrad = ctx.createLinearGradient(-15, -35, 15, 30);
    bodyGrad.addColorStop(0, lightenColor(design.primary, 15));
    bodyGrad.addColorStop(0.3, design.primary);
    bodyGrad.addColorStop(0.7, shadeColor(design.primary, -15));
    bodyGrad.addColorStop(1, shadeColor(design.primary, -35));
    
    ctx.fillStyle = bodyGrad;
    ctx.beginPath();
    ctx.moveTo(points[0][0], points[0][1]);
    for (let i = 1; i < points.length; i++) {
        ctx.lineTo(points[i][0], points[i][1]);
    }
    ctx.closePath();
    ctx.fill();
    
    // Edge highlight
    ctx.strokeStyle = lightenColor(design.primary, 30) + '50';
    ctx.lineWidth = 1;
    ctx.stroke();
}

// ============================================
// SHIP 1: FIGHTER (Phoenix) — Caça clássico
// ============================================
function drawShipFighter(design, time) {
    // Engine housings
    [-10, 10].forEach((ox, i) => {
        ctx.fillStyle = '#1a1a24';
        ctx.beginPath();
        ctx.roundRect(ox - 5, 18, 10, 12, 2);
        ctx.fill();
        _drawEngineFlame(ox, 30, 26 + Math.sin(time * 10) * 5, design, time, i);
    });
    
    // Wings - swept angular
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, 0, s * 45, 10);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -35));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 8, 5);
        ctx.lineTo(s * 46, -6);
        ctx.lineTo(s * 40, 20);
        ctx.lineTo(s * 8, 24);
        ctx.closePath();
        ctx.fill();
        
        // Wingtip accent
        ctx.fillStyle = design.secondary;
        ctx.beginPath();
        ctx.ellipse(s * 44, -2, 3, 7, s * 0.2, 0, Math.PI * 2);
        ctx.fill();
    });
    
    // Fuselage
    _fillBody([[0,-38],[14,-5],[14,22],[8,28],[-8,28],[-14,22],[-14,-5]], design);
    
    // Center ridge
    ctx.fillStyle = design.secondary + '88';
    ctx.beginPath();
    ctx.moveTo(0, -30); ctx.lineTo(3, -22); ctx.lineTo(3, 15);
    ctx.lineTo(0, 22); ctx.lineTo(-3, 15); ctx.lineTo(-3, -22);
    ctx.closePath();
    ctx.fill();
    
    // Cockpit
    _drawCockpitGlass(0, -18, 7, 10, design, time);
    
    // Weapon pods
    [-16, 16].forEach(x => {
        ctx.fillStyle = '#2a2a35';
        ctx.beginPath();
        ctx.roundRect(x - 2, -30, 4, 12, 1);
        ctx.fill();
        const cp = Math.sin(time * 6) * 0.25 + 0.75;
        ctx.fillStyle = design.accent + Math.floor(cp * 200).toString(16).padStart(2, '0');
        ctx.beginPath();
        ctx.arc(x, -31, 1.5, 0, Math.PI * 2);
        ctx.fill();
    });
}

// ============================================
// SHIP 2: BOMBER (Guardian) — Bombardeiro largo
// ============================================
function drawShipBomber(design, time) {
    // Triple engines
    [-12, 0, 12].forEach((ox, i) => {
        ctx.fillStyle = '#1a1a24';
        ctx.beginPath();
        ctx.roundRect(ox - 4, 20, 8, 10, 2);
        ctx.fill();
        _drawEngineFlame(ox, 30, 22 + Math.sin(time * 9 + i) * 4, design, time, i);
    });
    
    // Wide stubby wings
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, 0, s * 38, 0);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -30));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 10, -2);
        ctx.lineTo(s * 38, -6);
        ctx.lineTo(s * 40, 8);
        ctx.lineTo(s * 36, 20);
        ctx.lineTo(s * 10, 18);
        ctx.closePath();
        ctx.fill();
        
        // Wing armor plate
        ctx.fillStyle = design.secondary + '55';
        ctx.beginPath();
        ctx.roundRect(s * 18, 0, 14 * Math.abs(s), 14, 2);
        ctx.fill();
    });
    
    // Wide fuselage
    _fillBody([[0,-30],[18,-5],[18,22],[10,28],[-10,28],[-18,22],[-18,-5]], design);
    
    // Armor lines
    ctx.strokeStyle = design.secondary + '40';
    ctx.lineWidth = 1;
    [-15, -5, 5, 15].forEach(y => {
        ctx.beginPath(); ctx.moveTo(-14, y); ctx.lineTo(14, y); ctx.stroke();
    });
    
    // Rectangular cockpit
    ctx.fillStyle = '#3a3a4a';
    ctx.beginPath();
    ctx.roundRect(-8, -22, 16, 14, 3);
    ctx.fill();
    
    const gGrad = ctx.createLinearGradient(-6, -20, 6, -10);
    const sh = Math.sin(time * 2.5) * 0.1 + 0.9;
    gGrad.addColorStop(0, '#ffffff' + Math.floor(sh * 120).toString(16).padStart(2, '0'));
    gGrad.addColorStop(0.4, design.cockpitTint + '99');
    gGrad.addColorStop(1, shadeColor(design.cockpitTint, -20) + '66');
    ctx.fillStyle = gGrad;
    ctx.beginPath();
    ctx.roundRect(-6, -20, 12, 10, 2);
    ctx.fill();
}

// ============================================
// SHIP 3: DELTA (Thunder) — Asa delta aerodinâmica
// ============================================
function drawShipDelta(design, time) {
    // Single center engine (powerful)
    ctx.fillStyle = '#1a1a24';
    ctx.beginPath();
    ctx.roundRect(-6, 18, 12, 10, 3);
    ctx.fill();
    _drawEngineFlame(0, 28, 32 + Math.sin(time * 12) * 6, design, time, 0);
    
    // Delta wings (one big triangle)
    const dGrad = ctx.createLinearGradient(-44, 0, 44, 0);
    dGrad.addColorStop(0, shadeColor(design.primary, -30));
    dGrad.addColorStop(0.5, design.primary);
    dGrad.addColorStop(1, shadeColor(design.primary, -30));
    ctx.fillStyle = dGrad;
    ctx.beginPath();
    ctx.moveTo(0, -38);
    ctx.lineTo(44, 22);
    ctx.lineTo(0, 16);
    ctx.lineTo(-44, 22);
    ctx.closePath();
    ctx.fill();
    
    ctx.strokeStyle = lightenColor(design.primary, 25) + '40';
    ctx.lineWidth = 1;
    ctx.stroke();
    
    // Speed stripes
    ctx.strokeStyle = design.secondary + '55';
    ctx.lineWidth = 1.5;
    [1, -1].forEach(s => {
        ctx.beginPath();
        ctx.moveTo(s * 5, -28);
        ctx.lineTo(s * 30, 16);
        ctx.stroke();
    });
    
    // Fuselage center spine
    _fillBody([[0,-38],[10,0],[8,20],[-8,20],[-10,0]], design);
    
    // Cockpit - elongated
    _drawCockpitGlass(0, -16, 5, 14, design, time);
    
    // Wingtip thrusters
    [1, -1].forEach((s, i) => {
        const thrGrad = ctx.createRadialGradient(s * 40, 20, 0, s * 40, 20, 6);
        thrGrad.addColorStop(0, design.engineGlow);
        thrGrad.addColorStop(0.5, design.engineGlow + '60');
        thrGrad.addColorStop(1, 'transparent');
        ctx.fillStyle = thrGrad;
        ctx.beginPath();
        ctx.arc(s * 40, 20, 6, 0, Math.PI * 2);
        ctx.fill();
    });
}

// ============================================
// SHIP 4: HEAVY (Inferno) — Caça pesado com canhões
// ============================================
function drawShipHeavy(design, time) {
    // Dual engines
    [-8, 8].forEach((ox, i) => {
        ctx.fillStyle = '#1a1a24';
        ctx.beginPath();
        ctx.roundRect(ox - 5, 20, 10, 10, 2);
        ctx.fill();
        _drawEngineFlame(ox, 30, 28 + Math.sin(time * 11 + i) * 5, design, time, i);
    });
    
    // Angular wings
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, 0, s * 36, 10);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -30));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 10, 0);
        ctx.lineTo(s * 36, -8);
        ctx.lineTo(s * 34, 18);
        ctx.lineTo(s * 10, 20);
        ctx.closePath();
        ctx.fill();
    });
    
    // CANNONS (unique feature) — tall lateral barrels
    [-22, 22].forEach((x, i) => {
        // Cannon body
        const cGrad = ctx.createLinearGradient(x - 3, -32, x + 3, 14);
        cGrad.addColorStop(0, design.accent);
        cGrad.addColorStop(0.3, shadeColor(design.accent, -20));
        cGrad.addColorStop(1, shadeColor(design.accent, -40));
        ctx.fillStyle = cGrad;
        ctx.beginPath();
        ctx.roundRect(x - 3, -32, 6, 42, 2);
        ctx.fill();
        
        // Muzzle glow
        const cp = Math.sin(time * 8 + i) * 0.3 + 0.7;
        _safeShadow(design.accent, 8 * cp);
        ctx.fillStyle = design.engineGlow;
        ctx.beginPath();
        ctx.arc(x, -33, 2.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;
    });
    
    // Fuselage
    _fillBody([[0,-34],[14,-6],[14,22],[8,28],[-8,28],[-14,22],[-14,-6]], design);
    
    // Cockpit
    _drawCockpitGlass(0, -14, 6, 9, design, time);
    
    // Center heat vents
    ctx.fillStyle = design.accent + '44';
    [-6, 0, 6].forEach(y => {
        ctx.beginPath();
        ctx.roundRect(-8, y, 16, 2, 1);
        ctx.fill();
    });
}

// ============================================
// SHIP 5: STEALTH (Nebula) — Angular facetado
// ============================================
function drawShipStealth(design, time) {
    // Hidden engines (flush)
    [-10, 10].forEach((ox, i) => {
        _drawEngineFlame(ox, 22, 20 + Math.sin(time * 10 + i) * 4, design, time, i);
    });
    
    // Faceted main body (no curves, all angles)
    const bGrad = ctx.createLinearGradient(-30, -36, 30, 22);
    bGrad.addColorStop(0, lightenColor(design.primary, 10));
    bGrad.addColorStop(0.3, design.primary);
    bGrad.addColorStop(0.7, shadeColor(design.primary, -15));
    bGrad.addColorStop(1, shadeColor(design.primary, -35));
    
    // Main hull (angular pentagon)
    ctx.fillStyle = bGrad;
    ctx.beginPath();
    ctx.moveTo(0, -36);
    ctx.lineTo(28, 6);
    ctx.lineTo(22, 22);
    ctx.lineTo(-22, 22);
    ctx.lineTo(-28, 6);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = design.secondary + '40';
    ctx.lineWidth = 1;
    ctx.stroke();
    
    // Angular wing extensions
    [1, -1].forEach(s => {
        ctx.fillStyle = shadeColor(design.primary, -15);
        ctx.beginPath();
        ctx.moveTo(s * 28, 6);
        ctx.lineTo(s * 42, 12);
        ctx.lineTo(s * 22, 22);
        ctx.closePath();
        ctx.fill();
    });
    
    // Faceted panels (stealth signature)
    ctx.strokeStyle = design.secondary + '30';
    ctx.lineWidth = 0.5;
    // Diagonal panel lines
    ctx.beginPath();
    ctx.moveTo(0, -36); ctx.lineTo(15, 22);
    ctx.moveTo(0, -36); ctx.lineTo(-15, 22);
    ctx.moveTo(-28, 6); ctx.lineTo(0, -10);
    ctx.moveTo(28, 6); ctx.lineTo(0, -10);
    ctx.stroke();
    
    // Cockpit - narrow slit
    const sh = Math.sin(time * 2.5) * 0.1 + 0.9;
    ctx.fillStyle = '#3a3a4a';
    ctx.beginPath();
    ctx.moveTo(0, -26);
    ctx.lineTo(6, -10);
    ctx.lineTo(-6, -10);
    ctx.closePath();
    ctx.fill();
    
    ctx.fillStyle = design.cockpitTint + Math.floor(sh * 120).toString(16).padStart(2, '0');
    ctx.beginPath();
    ctx.moveTo(0, -24);
    ctx.lineTo(4, -12);
    ctx.lineTo(-4, -12);
    ctx.closePath();
    ctx.fill();
}

// ============================================
// SHIP 6: VIPER — Estreito e longo (serpente)
// ============================================
function drawShipViper(design, time) {
    // Single rear engine
    ctx.fillStyle = '#1a1a24';
    ctx.beginPath();
    ctx.roundRect(-4, 22, 8, 8, 2);
    ctx.fill();
    _drawEngineFlame(0, 30, 30 + Math.sin(time * 13) * 6, design, time, 0);
    
    // Swept-back thin wings
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, 0, s * 38, 10);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -30));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 5, 6);
        ctx.lineTo(s * 38, 16);
        ctx.lineTo(s * 32, 24);
        ctx.lineTo(s * 5, 18);
        ctx.closePath();
        ctx.fill();
        
        // Wing fang accent
        ctx.fillStyle = design.secondary + '88';
        ctx.beginPath();
        ctx.moveTo(s * 32, 18);
        ctx.lineTo(s * 38, 16);
        ctx.lineTo(s * 36, 24);
        ctx.closePath();
        ctx.fill();
    });
    
    // Narrow elongated fuselage
    _fillBody([[0,-42],[8,-10],[8,24],[-8,24],[-8,-10]], design);
    
    // Spine line (serpent ridge)
    ctx.strokeStyle = design.secondary;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(0, -38);
    ctx.lineTo(0, 20);
    ctx.stroke();
    
    // Scale pattern (unique viper detail)
    ctx.strokeStyle = design.secondary + '33';
    ctx.lineWidth = 0.5;
    for (let y = -30; y < 18; y += 6) {
        ctx.beginPath();
        ctx.moveTo(-6, y);
        ctx.quadraticCurveTo(0, y + 3, 6, y);
        ctx.stroke();
    }
    
    // Narrow cockpit
    _drawCockpitGlass(0, -22, 4, 10, design, time);
    
    // Fangs (front weapons)
    [-6, 6].forEach(x => {
        ctx.fillStyle = design.accent + 'cc';
        ctx.beginPath();
        ctx.moveTo(x, -38);
        ctx.lineTo(x + 1.5, -28);
        ctx.lineTo(x - 1.5, -28);
        ctx.closePath();
        ctx.fill();
    });
}

// ============================================
// SHIP 7: BRAWLER (Wolf) — Largo com escudos laterais
// ============================================
function drawShipBrawler(design, time) {
    // Dual heavy engines
    [-10, 10].forEach((ox, i) => {
        ctx.fillStyle = '#1a1a24';
        ctx.beginPath();
        ctx.roundRect(ox - 5, 18, 10, 12, 2);
        ctx.fill();
        _drawEngineFlame(ox, 30, 24 + Math.sin(time * 10 + i) * 5, design, time, i);
    });
    
    // Short angular wings
    [1, -1].forEach(s => {
        ctx.fillStyle = shadeColor(design.primary, -10);
        ctx.beginPath();
        ctx.moveTo(s * 12, -2);
        ctx.lineTo(s * 24, -6);
        ctx.lineTo(s * 22, 18);
        ctx.lineTo(s * 12, 20);
        ctx.closePath();
        ctx.fill();
    });
    
    // SHIELD PODS (unique feature)
    [1, -1].forEach(s => {
        // Shield mount
        ctx.fillStyle = design.secondary + '66';
        ctx.beginPath();
        ctx.roundRect(s * 24, -6, 14 * s * s, 24, 4);
        ctx.fill();
        
        // Shield connection
        ctx.fillStyle = shadeColor(design.primary, -20);
        ctx.beginPath();
        ctx.moveTo(s * 22, 0);
        ctx.lineTo(s * 24, -2);
        ctx.lineTo(s * 24, 16);
        ctx.lineTo(s * 22, 18);
        ctx.closePath();
        ctx.fill();
        
        // Shield energy glow
        const sp = Math.sin(time * 4 + (s > 0 ? 0 : Math.PI)) * 0.3 + 0.7;
        ctx.fillStyle = design.engineGlow + Math.floor(sp * 80).toString(16).padStart(2, '0');
        ctx.beginPath();
        const sx = s > 0 ? 31 : -31;
        ctx.roundRect(sx - 4, 0, 8, 12, 2);
        ctx.fill();
    });
    
    // Wide fuselage
    _fillBody([[0,-28],[18,-2],[18,20],[10,26],[-10,26],[-18,20],[-18,-2]], design);
    
    // Armor cross pattern
    ctx.strokeStyle = design.secondary + '35';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(-14, 0); ctx.lineTo(14, 0);
    ctx.moveTo(0, -18); ctx.lineTo(0, 18);
    ctx.stroke();
    
    // Rectangular cockpit (tactical)
    ctx.fillStyle = '#3a3a4a';
    ctx.beginPath();
    ctx.roundRect(-7, -20, 14, 12, 3);
    ctx.fill();
    
    const sh = Math.sin(time * 2.5) * 0.1 + 0.9;
    const gGrad = ctx.createLinearGradient(-5, -18, 5, -10);
    gGrad.addColorStop(0, '#ffffff' + Math.floor(sh * 100).toString(16).padStart(2, '0'));
    gGrad.addColorStop(0.5, design.cockpitTint + '88');
    gGrad.addColorStop(1, shadeColor(design.cockpitTint, -20) + '55');
    ctx.fillStyle = gGrad;
    ctx.beginPath();
    ctx.roundRect(-5, -18, 10, 8, 2);
    ctx.fill();
}

// ============================================
// SHIP 8: X-WING (Hawk) — Asas duplas em X, painéis solares
// ============================================
function drawShipXWing(design, time) {
    // Dual engines
    [-6, 6].forEach((ox, i) => {
        ctx.fillStyle = '#1a1a24';
        ctx.beginPath();
        ctx.roundRect(ox - 4, 20, 8, 10, 2);
        ctx.fill();
        _drawEngineFlame(ox, 30, 26 + Math.sin(time * 11 + i) * 5, design, time, i);
    });
    
    // Upper X-wings (sweep upward)
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, -8, s * 42, -22);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -30));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 8, -8);
        ctx.lineTo(s * 42, -24);
        ctx.lineTo(s * 38, -16);
        ctx.lineTo(s * 8, -2);
        ctx.closePath();
        ctx.fill();
    });
    
    // Lower X-wings (sweep downward)
    [1, -1].forEach(s => {
        const wGrad = ctx.createLinearGradient(0, 10, s * 40, 22);
        wGrad.addColorStop(0, design.primary);
        wGrad.addColorStop(1, shadeColor(design.primary, -25));
        ctx.fillStyle = wGrad;
        ctx.beginPath();
        ctx.moveTo(s * 8, 10);
        ctx.lineTo(s * 40, 20);
        ctx.lineTo(s * 36, 26);
        ctx.lineTo(s * 8, 18);
        ctx.closePath();
        ctx.fill();
    });
    
    // Solar panel struts (connecting upper/lower wings)
    [1, -1].forEach(s => {
        ctx.strokeStyle = design.secondary + '99';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(s * 40, -22);
        ctx.lineTo(s * 38, 22);
        ctx.stroke();
        
        // Solar panel glow
        const sp = Math.sin(time * 3 + (s > 0 ? 0 : 1.5)) * 0.2 + 0.8;
        ctx.fillStyle = design.secondary + Math.floor(sp * 50).toString(16).padStart(2, '0');
        ctx.beginPath();
        ctx.moveTo(s * 40, -20);
        ctx.lineTo(s * 42, -10);
        ctx.lineTo(s * 40, 0);
        ctx.lineTo(s * 38, -10);
        ctx.closePath();
        ctx.fill();
        
        ctx.beginPath();
        ctx.moveTo(s * 39, 4);
        ctx.lineTo(s * 41, 12);
        ctx.lineTo(s * 39, 20);
        ctx.lineTo(s * 37, 12);
        ctx.closePath();
        ctx.fill();
    });
    
    // Wingtip cannons
    [1, -1].forEach((s, i) => {
        const cp = Math.sin(time * 7 + i) * 0.3 + 0.7;
        // Upper
        ctx.fillStyle = '#2a2a35';
        ctx.beginPath();
        ctx.arc(s * 42, -24, 2.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = design.accent + Math.floor(cp * 180).toString(16).padStart(2, '0');
        ctx.beginPath();
        ctx.arc(s * 42, -24, 1.5, 0, Math.PI * 2);
        ctx.fill();
        // Lower
        ctx.fillStyle = '#2a2a35';
        ctx.beginPath();
        ctx.arc(s * 40, 20, 2, 0, Math.PI * 2);
        ctx.fill();
    });
    
    // Fuselage - slim
    _fillBody([[0,-36],[10,-6],[10,22],[-10,22],[-10,-6]], design);
    
    // Center ridge
    ctx.fillStyle = design.secondary + '66';
    ctx.beginPath();
    ctx.moveTo(0, -32); ctx.lineTo(2, -20); ctx.lineTo(2, 16);
    ctx.lineTo(0, 20); ctx.lineTo(-2, 16); ctx.lineTo(-2, -20);
    ctx.closePath();
    ctx.fill();
    
    // Cockpit - round bubble
    _drawCockpitGlass(0, -16, 5, 10, design, time);
}

// ============================================
// NAVIGATION LIGHTS (universal)
// ============================================
function drawNavLights(design, time) {
    const blink = Math.floor(time * 2) % 2 === 0;
    
    // Green starboard
    ctx.fillStyle = blink ? '#00ff00' : '#003300';
    if (blink) _safeShadow('#00ff00', 6);
    ctx.beginPath(); ctx.arc(40, -2, 1.5, 0, Math.PI * 2); ctx.fill();
    
    // Red port
    ctx.fillStyle = blink ? '#ff0000' : '#330000';
    if (blink) _safeShadow('#ff0000', 6);
    ctx.beginPath(); ctx.arc(-40, -2, 1.5, 0, Math.PI * 2); ctx.fill();
    
    // White nose
    ctx.shadowBlur = 0;
    ctx.fillStyle = '#ffffff';
    _safeShadow('#ffffff', 4);
    ctx.beginPath(); ctx.arc(0, -36, 1.2, 0, Math.PI * 2); ctx.fill();
    
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

window.drawShipAAA = drawShipAAA;
window.shadeColor = shadeColor;
window.lightenColor = lightenColor;

console.log('📦 ship-renderer.js v4.0 carregado (8 silhuetas únicas)');
