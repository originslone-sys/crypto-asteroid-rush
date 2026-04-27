# Modo Campanha — Documento de Planejamento

> Status: **Em mapeamento** | Branch: `claude/new-game-mode-planning-crtrQ`

## Objetivo
Criar um novo modo de jogo inspirado em **Space Impact** (Nokia clássico), com fases sequenciais, sistema de XP, vidas e chefões. Foco em **engajamento**, **retenção** e **monetização**.

---

## 1. Escopo do Lançamento

| Item | Decisão |
|---|---|
| Fases | 10 fases + 1 fase treino |
| Setores | 2 setores (5 fases cada) |
| Bosses | 2 (Fase 5 e Fase 10) |
| HTML | Separado (`campaign.html`, `campaign-game.html`) |
| Plataforma | Somente web |
| Tela | Formato mobile (portrait) — mesmo no PC, em janela mobile centralizada |
| Vidas | Sistema de vidas |
| Recompensas | BRL |
| Custo de fase | Créditos (inclusive fase treino) |
| Desbloqueio | Por nível de XP (não sequencial) |
| Arte | Nova e exclusiva |
| Painel Admin | Página dedicada para gerenciamento completo |

---

## 2. Sistema de XP ✅

### Níveis
- **Total:** 30 níveis
- **XP fixo por fase** (não escala com dificuldade ou desempenho do jogador)
- **Equilibrado** entre fase treino e fases normais (treino dá menos, mas pode ser repetida)

### XP por fase (proposta)

| Fase | XP fixo | Observação |
|---|---|---|
| Treino | 50 | Repetível para farm controlado |
| F1 | 80 | |
| F2 | 100 | |
| F3 | 120 | |
| F4 | 140 | |
| **F5 (boss)** | 200 | Final do Setor 1 |
| F6 | 180 | |
| F7 | 200 | |
| F8 | 220 | |
| F9 | 240 | |
| **F10 (boss)** | 350 | Boss final |

### Tabela de desbloqueio

| Fase | Nível mínimo |
|---|---|
| Treino | 1 |
| F1 | 1 |
| F2 | 3 |
| F3 | 5 |
| F4 | 8 |
| **F5 (boss)** | 11 |
| F6 | 14 |
| F7 | 17 |
| F8 | 20 |
| F9 | 23 |
| **F10 (boss)** | 25 |

### Curva de XP por nível (cumulativo)

| Nível | XP total acumulado |
|---|---|
| 1 | 0 |
| 3 | 220 |
| 5 | 500 |
| 8 | 1.000 |
| 11 | 1.700 |
| 14 | 2.500 |
| 17 | 3.500 |
| 20 | 4.700 |
| 23 | 6.000 |
| 25 | 7.500 |
| 30 | 11.000 |

### Configurável no painel admin
- XP por fase (cada fase editável)
- Nível mínimo de desbloqueio por fase
- Tabela de XP por nível
- XP bônus por estrelas (a definir)

---

## 3. Sistema de Vidas
> ⏳ **Pendente de decisão**

## 4. Custo em Créditos
> ⏳ **Pendente de decisão**

## 5. Recompensas em BRL
> ⏳ **Pendente de decisão**

## 6. Resolução / Orientação Mobile
> ⏳ **Pendente de decisão**

## 7. Tipo de Scroll
> ⏳ **Pendente de decisão** (mecânica v2 vertical OU side-scroller estilo Space Impact)

## 8. Mecânicas de Gameplay
> ⏳ **Pendente de decisão**

## 9. Estrutura de Fase
> ⏳ **Pendente de decisão**

## 10. Bosses
> ⏳ **Pendente de decisão**

## 11. Persistência e UI do Mapa
> ⏳ **Pendente de decisão**

## 12. Onboarding e Tutorial
> ⏳ **Pendente de decisão**

## 13. Engajamento Diário/Semanal
> ⏳ **Pendente de decisão**

## 14. Monetização Adicional
> ⏳ **Pendente de decisão**

## 15. Anti-cheat
> ⏳ **Pendente de decisão**

## 16. Integrações com o Site
> ⏳ **Pendente de decisão**

## 17. Versão / Lançamento
> ⏳ **Pendente de decisão**

## 18. Painel Administrativo
> ⏳ **A consolidar conforme decisões avançam**

Página: `admin/pages/campaign.php`

Funcionalidades previstas (vão sendo adicionadas conforme decisões):
- Gerenciar XP por fase ✅
- Gerenciar tabela de níveis ✅
- Gerenciar desbloqueios por nível ✅

---

## Arquivos planejados (visão geral, evolui conforme decisões)

### Frontend
- `campaign.html` — mapa de fases
- `campaign-game.html` — tela de jogo
- `js/campaign-map.js`
- `js/campaign-config.js`
- `js/campaign-engine.js`
- `js/campaign-boss.js`

### Backend
- `api/campaign-start.php`
- `api/campaign-end.php`
- `api/campaign-progress.php`
- `api/campaign-config.php` (público — lê configs do admin)
- `api/admin/campaign-*.php` (CRUD do admin)

### Admin
- `admin/pages/campaign.php`

### Banco de Dados
- `campaign_stages` — definição estática
- `campaign_progress` — progresso por usuário
- `campaign_xp_table` — curva de XP por nível
- `campaign_unlocks` — XP necessário por fase
- `campaign_bosses` — definição dos bosses
- `campaign_settings` — configs gerais (vidas, cooldown, etc)
