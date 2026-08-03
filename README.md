# Task+ — hub unificado de tarefas para GLPI 11

Rotinas recorrentes, tarefas avulsas, tarefas de projeto e tarefas de
chamado numa tela só ("Hoje"), com conclusão em 1 clique, papéis de
usuário e gestor, painel de indicadores e histórico auditável.

Derivado da base do [ProjectPlus](https://github.com/teckcomp/glpi-plugin-projectplus),
sem os módulos de Projetos, Modelos, Orçamento, Custos e Relatórios.

**Estado:** em desenvolvimento (Etapa 1 — tarefas avulsas + tela Hoje:
CRUD de avulsa, conclusão em 1 clique com autor/hora, badges de
horário-limite/atraso e KPIs do dia). Ver `ROADMAP` no projeto para o
plano completo.

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
