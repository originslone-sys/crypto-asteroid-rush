# CONTEXT RECOVERY PLAN
## Para evitar perda de contexto no futuro

## PROBLEMA IDENTIFICADO (2026-02-02)
**Erro de JSON truncado** interrompeu fluxo de trabalho, causando perda de contexto.

## CAUSA RAÍZ
1. Resposta muito longa/complexa
2. JSON mal formado na transmissão
3. Falta de consulta à memória antes de continuar

## SOLUÇÃO PREVENTIVA

### 1. ANTES DE QUALQUER RESPOSTA:
```javascript
// Fluxo obrigatório
1. Consultar MEMORY.md para contexto histórico
2. Ler últimos logs/mensagens do usuário
3. Verificar estado atual do problema
4. Só então responder
```

### 2. ESTRUTURA DE RESPOSTAS:
- **Máximo 500-800 palavras por resposta**
- **Seções claras com marcadores**
- **Evitar JSON complexo em respostas**
- **Usar checkpoints**: "Continue?" após seções longas

### 3. MANUTENÇÃO DE CONTEXTO:
- **Atualizar MEMORY.md após cada interação significativa**
- **Manter resumo do estado atual no início de cada resposta**
- **Referenciar aprendizados anteriores**

### 4. TRATAMENTO DE ERROS DE COMUNICAÇÃO:
```
SE (resposta anterior truncada/corrompida):
  1. Reconhecer: "Houve erro de comunicação"
  2. Recuperar: Consultar memória e logs
  3. Resumir: "Retomando de onde paramos..."
  4. Continuar: A partir do último ponto válido
```

## APLICAÇÃO IMEDIATA (CRYPTO ASTEROID RUSH)

### ESTADO ATUAL:
1. ✅ Login funciona (auth-google.php)
2. ✅ Testes básicos passam
3. ❌ game-start.php retorna 500
4. ✅ Diagnóstico: UID truncado vs completo
5. ✅ Correção aplicada: LIKE search
6. ⏳ Aguardando teste

### PRÓXIMOS PASSOS:
1. Testar `test-game-start-fix.php`
2. Se OK → Testar jogo
3. Se falhar → Verificar logs Cloud Run
4. Considerar migração users-only (sugestão do usuário)

### APRENDIZADO APLICADO:
- ✅ Não fazer "tapa-buraco"
- ✅ Analisar sistema todo
- ✅ Ouvir observações do usuário
- ✅ Manter fluxo contínuo

---

**IMPLEMENTADO EM:** 2026-02-02 20:35 UTC  
**POR:** Jarvis (após feedback crítico do usuário)  
**OBJETIVO:** Evitar repetição de perda de contexto