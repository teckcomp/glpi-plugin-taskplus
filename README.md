# Task+ — hub unificado de tarefas para GLPI 11

Rotinas recorrentes, tarefas avulsas, tarefas de projeto e tarefas de
chamado numa tela só ("Hoje"), com conclusão em 1 clique, papéis de
usuário e gestor, painel de indicadores e histórico auditável.

Derivado da base do [ProjectPlus](https://github.com/teckcomp/glpi-plugin-projectplus),
sem os módulos de Projetos, Modelos, Orçamento, Custos e Relatórios.

**Estado:** em desenvolvimento (Etapa 2 concluída — rotinas recorrentes).
Ver `ROADMAP` no projeto para o plano completo.

Já funciona:

- **Tarefas avulsas** com CRUD em modal, conclusão em 1 clique gravando
  autor e hora, badges de horário-limite/atraso e KPIs do dia
- **Rotinas recorrentes** (diária com opção "só dias úteis", semanal com
  dias marcáveis, mensal por dia fixo ou por posição como "última sexta"),
  com instruções, horário-limite, pausa/retomada e término opcional
- **Geração automática** das ocorrências do dia pela ação automática
  `taskplusgen`, idempotente (rodar N vezes ao dia não duplica)
- Tela **Hoje** agrupada em Rotinas diárias / semanais / mensais / Avulsas,
  com seção separada de atrasadas
- Exclusão sempre **soft**, preservando a trilha para o histórico

## Ações automáticas

O plugin registra duas ações automáticas (Configurar → Ações automáticas):

| Nome | O que faz |
|---|---|
| `taskplusgen` | Materializa as ocorrências do dia a partir das rotinas ativas (de hora em hora) |
| `taskplusalerts` | Alertas de horário-limite e pendências (chega na Etapa 6) |

Execução manual, para teste:

```bash
sudo -u www-data php <glpi>/front/cron.php --force taskplusgen
```

### Regras de calendário

- "Só dias úteis" = segunda a sexta; feriados não são considerados
- Mensal por dia fixo em mês curto cai no **último dia** do mês (uma rotina
  "dia 31" roda em 28/fev, 30/abr etc.)
- Mensal por posição com "5ª" não gera em meses em que aquele dia da semana
  só ocorre 4 vezes — para "a última", use a opção **Última**

## Requisitos

- GLPI 11.0.x
- PHP >= 8.2

## Instalação (desenvolvimento)

Copie a pasta `taskplus/` para `<glpi>/plugins/` e rode:

```bash
php bin/console plugin:install taskplus
php bin/console plugin:activate taskplus
```

## Licença

GPL-2.0-or-later — © Teckcomp I.T. Services
