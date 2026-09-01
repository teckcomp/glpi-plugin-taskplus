# Tarefas (Task+) — hub unificado de tarefas para GLPI 11

Plugin para GLPI 11 que reúne, numa tela só, tudo o que um técnico tem
para fazer no dia: chamados atribuídos, tarefas de chamado, tarefas de
projeto, rotinas recorrentes e tarefas avulsas — com conclusão em 1
clique, quadro kanban, papéis de usuário e gestor, alertas, e-mails
automáticos, painel de indicadores e histórico auditável.

Desenvolvido pela [Teckcomp I.T. Services](https://github.com/teckcomp).
Derivado da base do [ProjectPlus](https://github.com/teckcomp/glpi-plugin-projectplus),
sem os módulos de Projetos, Modelos, Orçamento, Custos e Relatórios.

**Versão atual:** `0.2.1-beta` · **GLPI:** 11.0.x · **Licença:** GPL-2.0-or-later

---

## As 5 origens de trabalho

| # | Origem | Onde vive | O que dá para fazer |
|---|--------|-----------|---------------------|
| 1 | Rotina recorrente | tabelas do plugin | concluir em 1 clique, pendência, pular o dia, quadro |
| 2 | Tarefa avulsa | tabelas do plugin | CRUD completo, concluir em 1 clique, pendência, quadro |
| 3 | Tarefa de projeto | `glpi_projecttasks` | leitura + link; pendência e conclusão pelo quadro |
| 4 | Tarefa de chamado | `glpi_tickettasks` | leitura + link; pendência e conclusão pelo quadro |
| 5 | Chamado | `glpi_tickets` | card de leitura ordenado pelo prazo de SLA, link para o chamado |

As origens nativas (3–5) são sempre lidas do GLPI — o plugin nunca as
duplica. Escrita em tabela nativa acontece exclusivamente pelos objetos
do core (ex.: concluir uma tarefa de chamado pelo quadro).

## A tela Hoje — 3 colunas

1. **Chamados** — chamados onde o usuário é atribuído, observador ou
   requerente (papéis somados no badge), todos os status exceto
   Solucionado/Fechado, ordenados pelo **prazo de SLA mais próximo**
   (TTO enquanto não assumido, senão TTR; estourado primeiro; sem SLA no
   fim, por prioridade). Única ação: abrir o chamado.
2. **Tarefas do Sistema** — tarefas de chamado e de projeto do usuário,
   com filtro segmentado por origem.
3. **Minhas tarefas** — rotinas do dia e avulsas, com conclusão em 1
   clique, pendência, pular hoje e a trava de duplicadas.

Busca local (título, descrição, categoria e `#número` de chamado) e
filtro por período cobrem a tela inteira.

## As 8 telas

| Tela | O que faz |
|------|-----------|
| **Hoje** | as 3 colunas acima + KPIs (Atrasadas · Para hoje · Pendentes · Concluídas) |
| **Quadro** | kanban com as 4 colunas de sistema + fases por setor, arrastar e soltar |
| **Rotinas** | CRUD de rotinas (diária/só dias úteis, semanal, mensal por dia fixo ou posição) |
| **Semana** | grade seg–dom somente leitura |
| **Equipe** | (gestor) acompanhar técnicos, concluir/editar/pendenciar tarefas deles, criar avulsas e rotinas para técnico ou para todo o setor, dialogar nas tarefas |
| **Painel** | indicadores pessoais: heatmap de conclusão, melhor dia da semana, taxa (feitas ÷ devidas) |
| **Histórico** | trilha auditável por dia, com restauração de avulsa excluída (só o dono) |
| **Configurações** | fases por setor, horários dos e-mails, opções gerais |

Todas as telas trazem o **sino de alertas** no cabeçalho da sidebar.

## Diálogo das tarefas (com anexos)

Cada tarefa do plugin (avulsa ou dia de rotina) tem uma **conversa**:
o técnico comenta pelo modal da tela Hoje; o gestor lê e responde pelo
botão "Diálogo" no card do técnico na tela Equipe. Participam da
escrita o **dono** e o **criador** da tarefa (quando distinto — caso
das tarefas criadas pelo gestor); outros gestores do setor acompanham
como leitores. Comentários aceitam **anexo** (armazenado como Document
nativo do GLPI — tipos permitidos e limite de tamanho valem os do
core), com download controlado pela mesma régua de participação.
Excluir comentário: só o autor; exclusão soft, trilha preservada.
Tarefas nativas (chamado/projeto) ficam fora — o acompanhamento delas
vive no próprio GLPI.

## Papéis e direitos (aba "Tarefas" do Perfil)

| Direito | Quem | O que libera |
|---------|------|--------------|
| `plugin_taskplus_task` | usuário | tarefas próprias: Hoje, Quadro, Rotinas, Semana, Painel, Histórico; e-mail de fim de dia |
| `plugin_taskplus_manage` | gestor (exige "Gerente" no grupo do GLPI) | tela Equipe, fases dos setores geridos, alerta no sino sobre a equipe, resumo matinal por e-mail |
| `plugin_taskplus_home` | qualquer perfil | **página inicial**: o login cai direto na tela Hoje, no lugar da Visão Geral do GLPI (o link "Visão Geral" no fim da sidebar volta ao painel nativo por clique; dashboards embutidos não são afetados) |

Os três direitos nascem **desmarcados** em todos os perfis; mudança de
direito vale no próximo login.

## Automação

| Ação automática | Frequência | O que faz |
|-----------------|------------|-----------|
| `taskplusgen` | 30 min | materializa as ocorrências do dia das rotinas (idempotente) |
| `taskplusalerts` | 10 min | alertas de horário-limite (sino + navegador), e-mail de fim de dia ao técnico e resumo matinal ao gestor |

Os dois e-mails usam a **cadeia nativa de notificações** do GLPI (fila +
cron `queuednotification`) e têm horário configurável (fim de dia 18:00,
resumo matinal 08:00, por padrão). O alerta do sino tem canal de
notificação do navegador opcional (opt-in do usuário).

### Agendamento: modo GLPI x modo CLI

As duas ações são registradas **sem forçar o modo**, exatamente como o
GLPI faz com as próprias: numa instalação nova elas nascem em **modo
GLPI** (`allowmode` permite os dois) e **funcionam sem nenhum crontab** —
não há passo manual depois de instalar. Se a instalação vier de um pacote
com cron de sistema (`GLPI_SYSTEM_CRON`), o próprio core já as registra em
modo CLI.

| | Modo GLPI (padrão) | Modo CLI (recomendado em produção) |
|---|---|---|
| Configuração | nenhuma | crontab no usuário do webserver |
| Disparo | pela navegação dos usuários (o GLPI chama `front/cron.php` no rodapé das páginas, no máximo a cada 5 min por sessão) | pelo relógio do sistema |
| Limite | `cron_limit` tarefas por chamada (padrão 5) | sem limite |
| Ponto fraco | **sem tráfego não roda**: o e-mail de fim de dia das 18:00 só sai quando alguém abrir uma página depois desse horário — sai atrasado, e se ninguém acessar naquele dia, aquele envio não acontece (não há rajada retroativa no dia seguinte) | nenhum |

⚠️ **Em servidor com muitas ações automáticas, troque para o modo CLI na
implantação.** O GLPI ordena a fila colocando as tarefas de plugin
**depois** de todas as nativas e executa no máximo `cron_limit` por
chamada (padrão **5**). Se houver cinco ou mais tarefas nativas
permanentemente vencidas, as do plugin nunca chegam à vez: nada roda,
e **sem erro, sem log e sem alterar o estado da tarefa** — o sintoma é a
tela Hoje vazia, que o técnico interpreta como "não tenho tarefa". Além
do modo CLI, vale conferir se o `cron_limit` do servidor comporta o
número de ações automáticas instaladas.

Para trocar: *Configurar → Ações automáticas* → a ação → **Modo de
execução**. Nada no plugin precisa ser reinstalado. Crontab sugerido:

```
* * * * * /usr/bin/php /var/www/html/glpi/front/cron.php >/dev/null 2>&1
```

## Requisitos

- GLPI **11.0.x** (homologado no 11.0.8, em produção no 11.0.6)
- PHP 8.3 ou superior (em uso no 8.4), MySQL/MariaDB
- Nenhum crontab é **exigido** — as ações automáticas funcionam em modo
  GLPI assim que o plugin é ativado. Em produção, o modo CLI é
  recomendado (ver *Agendamento*, acima)
- Para os e-mails: em *Configurar → Notificações*, habilitar
  acompanhamento **e** acompanhamento por e-mail, configurar o SMTP, e
  garantir e-mail válido nos usuários. O envio é assíncrono — quem
  despacha a fila é o cron `queuednotification`.

## Instalação

**A pasta de destino tem que se chamar `taskplus`** — é o nome interno do
plugin, e o GLPI não o reconhece sob outro nome.

### Via git (recomendado)

```bash
cd /var/www/html/glpi/plugins
git clone --branch v0.2.1-beta \
  https://github.com/teckcomp/glpi-plugin-taskplus.git taskplus
chown -R www-data:www-data taskplus
sudo -u www-data php ../bin/console plugin:install taskplus
sudo -u www-data php ../bin/console plugin:activate taskplus
```

O `taskplus` ao final do `git clone` não é opcional: sem ele a pasta
nasceria como `glpi-plugin-taskplus`.

### Via zip

```bash
cd /var/www/html/glpi/plugins
unzip taskplus.zip                      # cria plugins/taskplus
chown -R www-data:www-data taskplus
sudo -u www-data php ../bin/console plugin:install taskplus
sudo -u www-data php ../bin/console plugin:activate taskplus
```

### Atualizando de uma versão anterior

Por git:

```bash
cd /var/www/html/glpi/plugins/taskplus
git fetch --tags && git checkout v0.2.1-beta
chown -R www-data:www-data .
cd /var/www/html/glpi
sudo -u www-data php bin/console plugin:install --force taskplus
sudo -u www-data php bin/console plugin:activate taskplus
sudo -u www-data php bin/console cache:clear
```

Por zip: sobrescreva os arquivos e rode os mesmos três comandos do
console.

O `--force` reexecuta a migração e **desativa** o plugin — a ativação na
sequência é obrigatória. Os dados são preservados: as tabelas do plugin
não são recriadas, apenas migradas.

Depois da instalação: marque os direitos na aba **Tarefas** de cada
perfil (Administração → Perfis) e saia/entre para o menu aparecer.

## Estrutura

- 7 tabelas próprias: `routines`, `occurrences`, `phases`, `pendings`,
  `alerts`, `comments`, `comment_reads` (prefixo `glpi_plugin_taskplus_`)
- Exclusão sempre **soft** — a trilha do Histórico é preservada
- Twig + JavaScript puro (sem framework), CSRF rotacionado a cada
  resposta, escrita nativa só via objetos do core

## Licença

GPL-2.0-or-later. Veja `LICENSE`.
