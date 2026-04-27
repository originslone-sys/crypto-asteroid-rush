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

## 3. Sistema de Vidas ✅

### Configuração base
| Parâmetro | Valor |
|---|---|
| Vidas máximas | **5** |
| Tempo de recarga por vida | **30 minutos** |
| Tempo total para encher do zero | 2h30 |
| Quando consome | **Ao falhar a fase** (vencer não consome vida) |
| Escopo | **Exclusivas da campanha** (não interferem em outros modos) |

### Premium
- Recarga **2x mais rápida** (15 min por vida)
- Vidas máximas aumentadas para **7**

### Compra com créditos
| Opção | Custo |
|---|---|
| 1 vida avulsa | 1 crédito |
| Pacote de 5 vidas (com desconto) | 4 créditos |
| Refill completo (encher tudo) | 3 créditos |

> Refill completo só disponível quando vidas < máximo.

### Configurável no painel admin
- ✅ Quantidade máxima de vidas (padrão e premium)
- ✅ Tempo de recarga em minutos (padrão e premium)
- ✅ Comportamento de consumo (ao entrar / ao falhar)
- ✅ Custo de compra avulsa
- ✅ Custo do pacote de 5 vidas
- ✅ Custo do refill completo
- ✅ Toggle de "vidas ilimitadas" para promoções/eventos

## 4. Custo em Créditos ✅

### Modelo: cobrança **por setor** (com override por fase no admin)

| Fase | Custo (créditos) |
|---|---|
| Treino | 1 |
| F1 a F5 (Setor 1) | 1 |
| F6 a F10 (Setor 2) | 2 |

### Regras
- **Bosses (F5 e F10)** não cobram extra — custo igual ao do setor
- **Re-jogar fase** (para melhorar estrelas): mesmo custo da 1ª vez (protege economia)
- **Continuar após game over** (recupera 1 vida + ressuscita no ponto): **+2 créditos**

### Configurável no painel admin
- ✅ Custo padrão por setor
- ✅ Override de custo por fase individual (sobrepõe o do setor)
- ✅ Custo da fase treino
- ✅ Toggle: cobrar extra em bosses + valor extra
- ✅ Custo de re-jogada (% do original OU valor fixo)
- ✅ Custo do "continuar" após game over
- ✅ Toggle "fases grátis" (promoções/eventos)
- ✅ Toggle "primeira jogada grátis" por fase recém-desbloqueada

## 5. Recompensas em BRL e Estrelas ✅

### Recompensa base (1ª vez completando)

| Fase | BRL base (1⭐) | Observação |
|---|---|---|
| Treino | R$ 0,00 | Treino dá só XP |
| F1 | R$ 0,05 | |
| F2 | R$ 0,08 | |
| F3 | R$ 0,12 | |
| F4 | R$ 0,18 | |
| **F5 (boss)** | R$ 0,40 | Final do Setor 1 |
| F6 | R$ 0,25 | |
| F7 | R$ 0,35 | |
| F8 | R$ 0,50 | |
| F9 | R$ 0,70 | |
| **F10 (boss)** | R$ 1,50 | Boss final |

**Total possível 1ª vez (sem bônus de estrelas):** R$ 5,13
**Custo total em créditos para completar:** 16 créditos

### Multiplicador por estrelas
| Estrelas | Multiplicador |
|---|---|
| 1 ⭐ | 100% (base) |
| 2 ⭐ | 125% |
| 3 ⭐ | 150% |

### Critérios das estrelas
- **1 ⭐** Completar a fase (vencer)
- **2 ⭐** Completar perdendo no máximo **50% de HP**
- **3 ⭐** Completar **sem tomar dano** (perfect run)

### Política de re-jogada
- Paga apenas a **diferença** entre estrelas anteriores e novas
  - Ex: tinha 1⭐ (recebeu 100%), agora fez 3⭐ → recebe os **50% extras**
- Evita farm e incentiva o jogador a perseguir 3⭐

### Bônus de sequência (streak)
- Completar **3 fases seguidas sem falhar** → **+10%** na próxima fase
- Configurável (% e número de fases que ativam)

### Limite diário
- **R$ 10,00 por dia** em recompensas da campanha (anti-abuse)
- Toggle no admin para desabilitar

### Configurável no painel admin
- ✅ BRL base por fase (cada fase editável)
- ✅ Multiplicadores por estrelas (1⭐, 2⭐, 3⭐)
- ✅ Critério da 2⭐ (% de HP máximo perdido)
- ✅ Política de re-jogada (apenas diferença / 100% / % reduzida)
- ✅ Bônus de sequência (% e quantidade de fases)
- ✅ Limite diário de ganho (valor + toggle on/off)
- ✅ Evento "recompensa dobrada" (toggle global)
- ✅ Promoções temporárias por fase (multiplicador customizado + janela de tempo)

## 6. Resolução / Orientação Mobile ✅

### Orientação
- **Portrait (vertical)** — padrão mobile moderno
- Bloqueio de rotação ativo (se o usuário girar o celular, força volta para portrait)

> Caso o tipo de scroll definido na seção 7 seja side-scroller landscape, esta decisão será revista.

### Resolução do canvas
- **414 x 896 px** (referência iPhone 11/12/13 — boa área visível e compatibilidade ampla)

### Comportamento no Desktop
- Janela mobile **centralizada** (414x896)
- Background ao redor: **espelho do gameplay desfocado** (estilo Netflix) — imersivo e focal
- Sem barra de scroll do navegador interferindo

### Comportamento no Mobile (celular real)
- **Tela cheia** (fullscreen API) — UX nativa
- Esconde barra de URL e barra de status quando possível

### Controles

**Mobile (touch):**
- Arrastar com o dedo para mover a nave
- **Tiro automático**

**Desktop:**
- **WASD** ou **setas** para mover
- **Tiro automático** (mesma mecânica do mobile)
- Espaço reservado para futuros power-ups/tiro especial

### Configurável no painel admin
- ✅ Resolução do canvas (largura × altura)
- ✅ Orientação forçada (portrait / landscape)
- ✅ Bloqueio de rotação ON/OFF
- ✅ Tema do background lateral no desktop (gameplay-blur / cor sólida / imagem)
- ✅ Tiro automático ON/OFF (testar variantes)
- ✅ Sensibilidade do touch (multiplicador 0.5x a 2.0x)
- ✅ Velocidade base de movimento da nave

## 7. Tipo de Scroll ✅

### Decisão: **Vertical scroll com cenário rolando** (estilo Sky Force / Galaga / 1942)

### Como funciona
- Cenário rola **de cima para baixo** (parallax vertical contínuo)
- Inimigos e asteroides entram pela parte superior da tela
- Nave do jogador se move livremente dentro da área inferior da tela
- Ao chegar no boss, o **cenário trava** e a tela vira uma "arena" para a luta
- Mantém a essência do Space Impact (fases, ondas, bosses, power-ups) adaptada para portrait

### Justificativa
- Casa perfeitamente com a orientação **portrait** já definida
- Formato consagrado em mobile (Sky Force, Phoenix Force)
- Diferencia visualmente dos modos atuais (que não têm cenário rolando)
- Permite arte temática por setor sem complicar implementação

### Camadas de parallax (proposta inicial)
1. **Camada de fundo:** estrelas distantes (rolagem lenta)
2. **Camada do meio:** nebulosa / planetas (rolagem média)
3. **Camada de frente:** asteroides decorativos / detritos (rolagem rápida)

### Tema visual por setor
| Setor | Tema |
|---|---|
| Setor 1 | Cinturão de Asteroides (azul/cinza, rochas) |
| Setor 2 | Zona de Detritos (laranja/vermelho, sucata espacial) |

### Configurável no painel admin
- ✅ Velocidade do scroll do cenário (ajustável por fase)
- ✅ Número de camadas de parallax (1, 2 ou 3 — para performance)
- ✅ Tema visual por setor (asset pack: estrelas, asteroides, nebulosa, etc)
- ✅ Velocidade base dos inimigos descendo
- ✅ Tempo até boss aparecer (após cenário travar)
- ✅ Toggle: cenário continua rolando durante boss fight (sim/não)

## 8. Mecânicas de Gameplay ✅

### HP da nave
- **HP em barra** com **100 HP** padrão
- Permite balanceamento fino e casa com o critério de estrelas (2⭐ ≤ 50% dano, 3⭐ sem dano)

### Tiros
- **Tiro padrão:** automático, contínuo, dano baixo
- **Munição:** infinita (estilo arcade clássico)
- **Tiros especiais:** apenas via power-ups dropados

### Power-ups (5 no MVP)

| Power-up | Efeito | Duração |
|---|---|---|
| 🛡️ Escudo | Bloqueia o próximo dano | Até ser atingido |
| ⚡ Tiro Triplo | 3 projéteis em leque | 10s |
| ❤️ Reparo | Recupera 25 HP | Instantâneo |
| 💣 Bomba | Destrói todos os inimigos na tela | Instantâneo |
| ⏰ Slow-time | Inimigos 50% mais lentos | 5s |

> Power-ups para expansão futura: Mísseis, Multiplicador BRL.

### Sistema de drop de power-ups
- **Modelo híbrido:**
  - Power-ups **garantidos** em pontos-chave de algumas ondas (script)
  - Power-ups **aleatórios** em inimigos especiais raros (chance baixa)

### Tipos de inimigos (MVP — 6 tipos)

| Inimigo | Comportamento | HP | Dano |
|---|---|---|---|
| Asteroide pequeno | Desce reto | 1 | 10 |
| Asteroide grande | Desce reto, lento | 3 | 25 |
| Inimigo Kamikaze | Persegue o jogador | 2 | 30 |
| Inimigo Atirador | Para e atira projéteis | 4 | 15 (por projétil) |
| Inimigo Esquivo | Desce em zig-zag | 2 | 20 |
| Mini-Boss (raro) | Aparece em 1-2 fases pré-boss | 15 | 40 |

> Cada setor tem **variação de arte** dos inimigos (mesmo comportamento, visual diferente).

### Sistema de combo
- Multiplicador acumulável até **x5**
- Cada **10 inimigos destruídos sem tomar dano** = +1x
- O multiplicador afeta apenas o **XP** ganho na fase (não o BRL, para evitar abuse)
- **Reseta** ao tomar qualquer dano

### Pausa
- Botão de pausa habilitado durante a fase
- Menu da pausa: Continuar / Sair / Configurações
- **Sair = perde 1 vida** (anti-cheat: impede pausar para escapar de morte iminente)

### Configurável no painel admin
- ✅ HP máximo da nave (padrão e premium)
- ✅ Dano de cada tipo de inimigo
- ✅ HP de cada tipo de inimigo
- ✅ Velocidade de cada tipo de inimigo
- ✅ Lista de power-ups ativos (toggle individual por power-up)
- ✅ Taxa de drop de cada power-up
- ✅ Duração de cada power-up
- ✅ Habilitar/desabilitar combo
- ✅ Multiplicador máximo de combo (1x a 10x)
- ✅ Quantidade de inimigos para subir 1x no combo
- ✅ Toggle "munição infinita"
- ✅ Toggle "pausa habilitada"
- ✅ Cooldown do tiro automático (ms)
- ✅ Dano base do tiro padrão

## 9. Estrutura de Fase ✅

### Duração média
- Fases iniciais: **60s**
- Fases avançadas: **90s**
- Fases com boss: **sem timer** (até derrotar ou morrer)

### Estrutura de fase normal (F1, F2, F3, F4, F6, F7, F8, F9)

```
[0s — 5s]   Introdução: cenário rolando, sem inimigos
[5s — 50s]  3 ondas de inimigos (~15s cada)
            • Onda 1: inimigos básicos
            • Onda 2: mix
            • Onda 3: avançada
[50s — 60s] Pausa curta + power-up garantido (Reparo)
[Final]     "Stage Clear" → tela de resultados
```

### Estrutura de fase com boss (F5 e F10)

```
[0s — 30s]  2 ondas de warm-up (inimigos do setor)
[30s — 35s] "Warning! Boss approaching" (cenário trava, música muda)
[35s — fim] Boss fight sem timer
            • 3 fases de HP (100% → 50% → 25% → morte)
[Final]     Recompensa especial + tela de resultados
```

### Tabela de ondas por fase (MVP)

| Fase | Duração | Onda 1 | Onda 2 | Onda 3 | Especial |
|---|---|---|---|---|---|
| Treino | 45s | 5 asteroides | 3 asteroides | — | Tutorial guiado |
| F1 | 60s | 8 asteroides | 5 asteroides + 2 kamikaze | 3 asteroides | — |
| F2 | 60s | 10 asteroides | 6 asteroides + 3 kamikaze | 5 atiradores | — |
| F3 | 70s | 8 kamikaze | 5 atiradores + 4 esquivos | 6 asteroides | Power-up garantido |
| F4 | 75s | 10 esquivos | 6 atiradores + 4 kamikaze | 8 mix | Mini-boss raro |
| **F5 (boss)** | s/ timer | 5 atiradores | 8 mix do setor | — | **BOSS 1** |
| F6 | 70s | 12 mix setor 2 | 8 atiradores | 6 esquivos | — |
| F7 | 80s | 10 kamikaze | 6 atiradores + 5 esquivos | 8 mix | Power-up garantido |
| F8 | 85s | 8 esquivos | 10 mix | 8 atiradores | Mini-boss raro |
| F9 | 90s | 12 mix | 10 mix avançado | 8 mix difícil | 2 mini-bosses |
| **F10 (boss)** | s/ timer | 8 mix difícil | 10 mix avançado | — | **BOSS FINAL** |

### Ritmo de spawning (transição entre ondas)
- **Modelo híbrido:**
  - Onda dura no máximo **20s**
  - Se o jogador limpar antes de **15s**, a próxima onda começa imediatamente
  - Recompensa skill e mantém o ritmo

### Power-up de fim de fase
- Em fases **sem boss**: power-up de **Reparo** garantido antes do "Stage Clear"
- Em fases **com boss**: recompensa especial via boss (vide seção 10)

### XP por inimigo (bônus ao XP fixo da fase)
| Inimigo | XP |
|---|---|
| Asteroide pequeno | 1 |
| Asteroide grande | 2 |
| Kamikaze | 3 |
| Atirador | 5 |
| Esquivo | 4 |
| Mini-Boss | 50 |

> XP de inimigos é um **bônus pequeno** — o foco continua no XP fixo da fase.

### Configurável no painel admin
- ✅ Duração de cada fase (segundos)
- ✅ Editor de ondas por fase (lista com tipo + quantidade de inimigos)
- ✅ Tempo de cada onda
- ✅ Modo de transição entre ondas (fixo / limpar tela / híbrido)
- ✅ Timeout máximo por onda
- ✅ Power-up garantido de fim de fase (toggle + tipo do power-up)
- ✅ XP de cada tipo de inimigo
- ✅ Frequência de mini-bosses (% de chance por fase)
- ✅ Tempo do "warning" antes do boss
- ✅ Tempo do warm-up das fases de boss

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
- Gerenciar Sistema de Vidas (máx, recarga, consumo, custos, premium, ilimitado) ✅
- Gerenciar Custo em Créditos (setor, fase, treino, boss, re-jogada, continuar, promoções) ✅
- Gerenciar Recompensas em BRL (base por fase, estrelas, re-jogada, streak, limite diário, eventos) ✅
- Gerenciar Resolução/Orientação Mobile (canvas, rotação, controles, sensibilidade) ✅
- Gerenciar Scroll/Parallax (velocidade, camadas, tema por setor, comportamento no boss) ✅
- Gerenciar Mecânicas de Gameplay (HP, inimigos, power-ups, combo, pausa, tiros) ✅
- Gerenciar Estrutura de Fase (duração, ondas, transições, XP por inimigo, mini-bosses) ✅

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
