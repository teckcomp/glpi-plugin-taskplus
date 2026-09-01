# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/);
versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [0.2.2-beta] — 2026-09-01

### Adicionado

- **Trilha do diálogo no Histórico** (11a): cada linha do recorte com
  conversa mostra o botão `Diálogo (n) 📎` e abre a thread em leitura
  pura — comentários e anexos de evidência ganharam porta de entrada
  depois que o dia passa. Ler no Histórico **não** marca como lido
  (auditar não é participar); tarefa excluída não oferece o botão.
- **Validação da execução pelo gestor** (11b, decisão nº 61). Tarefa
  **criada por gestor**, ao ser concluída (por qualquer caminho — Hoje,
  Quadro ou Equipe), entra numa fila de validação: a tela Equipe ganha
  a seção "Aguardando validação" no topo e os botões **Validar** e
  **Reprovar** por item. Validam o criador ou qualquer gestor de setor
  do dono. Reprovar exige **comentário obrigatório**, gravado no
  diálogo da tarefa com o prefixo `[Reprovação]`, e devolve a tarefa
  para **aberta no dia original** — ela reaparece atrasada, e o não
  lido do sino avisa o técnico. O técnico vê "aguardando validação" /
  "execução validada" na tela Hoje. Ocorrências concluídas **antes**
  desta versão ficam fora da fila (sem varredura retroativa).

### Alterado

- **Schema**: a tabela de ocorrências ganhou `validation` (0 não se
  aplica · 1 aguardando · 2 validada), `users_id_validate` e
  `validation_date` — exige `plugin:install --force` + `plugin:activate`.
- **Histórico**: a contagem da trilha do diálogo deixou de engolir
  erro em silêncio (item 9 da fila) — falha agora aparece no log.

## [0.2.1-beta] — 2026-08-21

Primeira correção de campo do piloto de 30 dias: quatro achados de uso
real, todos **sem tocar em schema, direitos ou ações automáticas**.

### Adicionado

- **Filtro no seletor de alvo** do Painel e do Histórico (achado A-2).
  A caixa "Ver painel de" / "Ver histórico de" ganhou um campo de busca
  ao lado, os técnicos passaram a aparecer **agrupados por setor** e o
  filtro casa **nome ou setor, sem acento**, com um contador "N de M"
  dizendo que a lista está recortada. Em base de cliente há setor com
  mais de 150 membros — a lista plana era impraticável. "Meu painel",
  "Meu histórico", as opções de equipe e **o alvo já selecionado** nunca
  são filtrados: opção ativa escondida faria o seletor trocar de valor
  sozinho e disparar uma leitura que ninguém pediu.
- **Exportar CSV no Histórico**: uma linha por tarefa (data, tarefa,
  categoria, origem, rotina, estado, conclusão, motivo), com o alvo, o
  período e a busca aplicada registrados no cabeçalho do arquivo. Sai
  **exatamente o que está na tela** — a busca digitada vale também para
  o arquivo.
- **Exportar CSV no Painel em modo equipe**: uma linha por responsável
  (nome, setor, devidas, concluídas, pendentes, taxa), para quantificar
  o mês de todo o setor num arquivo só.
- Os dois arquivos saem com **BOM UTF-8** e separador `;`, que é o que o
  Excel em português abre sem quebrar acento nem coluna, e com célula
  iniciada por `=`, `+`, `-` ou `@` neutralizada — título de tarefa é
  texto livre e não deve virar fórmula na planilha do gestor. A geração
  é **no navegador**: sem endpoint novo, sem leitura extra no servidor e
  sem chance de exportar além do que o servidor já autorizou para
  aquela tela.

### Alterado

- **O escopo do gestor passa a considerar só quem usa o plugin**
  (decisão nº 58, achado A-2). As telas Equipe, Painel e Histórico
  listavam todo usuário ativo dos grupos administrados — e em base de
  cliente isso traz contas que estão num grupo do GLPI mas não são
  técnicas (um único grupo com 266 membros). Elas nunca teriam tarefa
  aqui, porque sequer acessam o plugin: eram ruído no seletor, na tela
  Equipe e linha zerada no CSV do Painel. Agora só entra quem tem o
  direito `plugin_taskplus_task` em algum perfil. Um técnico que apareça
  a menos é sinal de perfil sem o direito — e, sem ele, essa pessoa não
  consegue usar o Task+ de qualquer forma.

- **Quem cria a rotina, controla a rotina** (decisão nº 57, achado A-1).
  Rotina que o gestor criou para um técnico — individual ou por setor —
  passa a ser editada, pausada, retomada e excluída **só pelo criador**.
  O técnico continua vendo a rotina na tela Rotinas (com o badge "criada
  pelo gestor") e agindo normalmente nas tarefas do dia: concluir,
  pendência e diálogo. Substitui a regra da 5c-2, em que a rotina
  nascia sob controle total do técnico e o gestor não tinha como
  desfazer a própria criação.

### Adicionado

- Seção **"Criadas para a equipe"** na tela Rotinas, visível para quem
  criou rotina para terceiros. A criação por setor aparece como **lote**
  ("Backup — 3 técnicos"), com pausar/retomar, editar e excluir valendo
  para todos de uma vez; o lote expande e mostra cada técnico, com
  pausar e excluir individuais. Rotina criada para um técnico só é um
  lote de um.
- Lote reconstituído a partir dos próprios dados (criador, instante de
  criação e campos da recorrência) — **sem coluna nova e sem
  reinstalação**, valendo também para as rotinas já criadas.

### Corrigido

- **A folha de estilo passa a carregar com a versão do plugin na URL**
  (`taskplus.css?v=…`), como o JS já fazia. Sem isso, o navegador de
  quem já usava o plugin servia o CSS antigo depois de uma atualização e
  a tela saía com estilo de uma versão e marcação de outra — e a única
  saída era pedir Ctrl+F5 máquina por máquina.

## [0.2.0-beta] — 2026-08-13

Primeira versão instalada em produção. Consolida o pós-beta: diálogo com
contador de não lidos, Painel e Histórico da equipe para o gestor, e uma
rodada de higiene que tirou o plugin da dependência de crontab manual e
fez a recusa do servidor chegar à tela com o texto certo.

### Adicionado

**Diálogo**
- Contador de comentários não lidos por tarefa: badge `💬 N` no card da
  tela Hoje, zerado ao abrir o diálogo; na tela Equipe o mesmo
  mecanismo com o gestor como leitor, no rótulo do botão
  ("Diálogo (2)")

**Painel da equipe**
- Gestor pode abrir o Painel de um técnico do seu escopo, com a mesma
  régua da tela Equipe
- Painel agregado da equipe: união dos dias dos técnicos com marca de
  dono, seleção por setor ou "todos", e tabela de taxa por responsável
  semeada com todo o recorte

**Histórico com alvo**
- Gestor pode ver o histórico de um técnico do seu escopo, com faixa de
  identificação do alvo e alvo revalidado a cada consulta
- Restauração de tarefa excluída pelo gestor, com confirmação extra
  quando a tarefa é de outra pessoa

**Instalação**
- As ações automáticas (`taskplusgen`, `taskplusalerts`) passam a nascer
  em modo GLPI, como as tarefas nativas do core: **instalação nova não
  exige mais configurar crontab à mão**. Bases já instaladas mantêm o
  modo atual
- README com a seção "Agendamento: modo GLPI x modo CLI", incluindo a
  limitação do modo interno (sem tráfego no GLPI, o disparo atrasa)

### Alterado

- Notificações passam a assinar **"Tarefas"** em vez de "Task+" —
  modelos, nomes de notificação, assuntos, corpos e mensagens de log —,
  com migração que **preserva qualquer edição feita pelo admin** (troca
  só o que ainda estiver idêntico ao texto original) e que é retomável
  se a instalação for interrompida no meio

### Corrigido

- Download de anexo do diálogo deixou de usar API depreciada e passou a
  devolver a resposta pelo fluxo do core, eliminando três linhas de
  ruído em `php-errors.log` a cada download
- Recusa de acesso nas telas Equipe e Configurações e nos 10 endpoints
  JSON passou a usar as exceções HTTP do core em vez de encerrar o
  script por fora do fluxo. Mesmo status HTTP de antes, mesma régua de
  permissão; a diferença é que a resposta agora passa pelo tratamento
  de erro do GLPI e o registro vai para o log de acesso, não para o de
  erros
- `ajax/alerts.php` não rotula mais como JSON a resposta de um método
  não aceito
- Erro do servidor volta a aparecer com o texto certo na tela: as
  chamadas do plugin passaram a declarar que esperam JSON, então uma
  recusa chega como JSON estruturado e o aviso mostra o motivo real em
  vez de "Falha de comunicação com o servidor". Vale principalmente
  para **sessão expirada**, que agora avisa "Sua sessão expirou. Por
  favor, autentique-se novamente." em vez da mensagem genérica

## [0.1.0-beta] — 2026-08-10

Primeira versão pública (beta). Consolida as etapas 0 a 8 do
desenvolvimento, agrupadas por área.

### Adicionado

**Tela Hoje e tarefas próprias**
- Tela Hoje em 3 colunas: Chamados · Tarefas do Sistema · Minhas
  tarefas (responsiva: 2 colunas ≤1500px, 1 ≤1100px)
- Tarefas avulsas com CRUD em modal, conclusão em 1 clique com
  auditoria (autor e hora), badges de horário-limite e atraso
- KPIs do dia: Atrasadas · Para hoje · Pendentes · Concluídas
- Coluna Chamados: chamados onde o usuário é atribuído, observador ou
  requerente, ordenados pelo prazo de SLA mais próximo (estourado
  primeiro), card somente leitura com link para o chamado
- Agregação das tarefas de chamado (`glpi_tickettasks`) e de projeto
  (`glpi_projecttasks`) em leitura, com filtro por origem
- Busca local (título, descrição, categoria, `#número` de chamado) e
  filtro por período
- Trava de tarefas duplicadas na criação própria: aviso com
  confirmação por título normalizado, dono e data (avulsas vivas)

**Rotinas recorrentes**
- Recorrência diária (com "só dias úteis"), semanal (dias marcáveis) e
  mensal (dia fixo — em mês curto cai no último dia — ou posição, ex.
  "última sexta"), com pausa/retomada e término opcional
- Geração idempotente das ocorrências do dia (ação automática
  `taskplusgen`, 30 min), inclusive imediata na criação
- Edição da ocorrência do dia sem tocar a rotina; "pular hoje" com
  motivo e desfazer

**Pendências e quadro**
- Pendência com motivo, data e hora de retorno obrigatórios, expiração
  automática, exibida no bloco de origem
- Quadro kanban: 4 colunas de sistema + fases por setor (grupos do
  GLPI), arrastar e soltar, conclusão de tarefas nativas via objetos do
  core
- Fases por setor administráveis em Configurações (admin e gestor do
  setor)

**Gestão de equipe**
- Tela Equipe (gestor): contadores e lista expandível por técnico
- Ações do gestor sobre tarefas do plugin dos técnicos: concluir,
  desfazer, editar e pendenciar, com badge de auditoria
- Criação de avulsas e rotinas para um técnico ou para todos os membros
  ativos de um setor, com badge "criada pelo gestor"

**Acompanhamento**
- Tela Semana: grade seg–dom somente leitura
- Tela Painel: heatmap de conclusão estilo GitHub, melhor dia da
  semana, taxa feitas ÷ devidas (período 90 dias, teto 180)
- Tela Histórico: trilha auditável agrupada por dia (padrão 30 dias,
  teto 180), com restauração de avulsa excluída pelo próprio dono

**Diálogo das tarefas**
- Conversa por tarefa do plugin (avulsa ou dia de rotina): thread no
  modal da tela Hoje e botão "Diálogo" nos cards da tela Equipe
- Régua de participação: escrevem o dono e o criador da tarefa (quando
  distinto); demais gestores do setor acompanham como leitores;
  excluir comentário só o autor (exclusão soft)
- Anexos por comentário armazenados como Document nativo do GLPI
  (tipos permitidos e limite de tamanho do core; deduplicação por
  conteúdo), com download servido pelo plugin sob a mesma régua de
  participação — o gestor do dono também baixa

**Alertas e e-mails**
- Sino de alertas na sidebar com notificação do navegador opcional;
  alerta de horário-limite estourado ao dono e aos gestores (uma
  tentativa por ocorrência; pendência ativa não alerta)
- E-mail de fim de dia ao técnico (18:00, configurável) com abertas,
  atrasadas e pendentes
- Resumo matinal ao gestor (08:00, configurável) com uma linha por
  técnico; ambos pela cadeia nativa de notificações do GLPI (fila +
  cron `queuednotification`)

**Perfil e página inicial**
- Aba "Tarefas" na tela de Perfil com os 3 direitos do plugin
  (`plugin_taskplus_task`, `plugin_taskplus_manage`,
  `plugin_taskplus_home`), todos nascendo desmarcados
- Página inicial por perfil: com o direito de home, o login cai direto
  na tela Hoje; link "Visão Geral" na sidebar volta ao painel nativo
  por clique; dashboards embutidos preservados

**Identidade**
- Marca própria (caderno com checks e lápis) na sidebar e no card da
  lista de plugins (`logo.png`); nome de exibição "Tarefas"; slogan
  "Suas tarefas do dia, em um único lugar"

### Segurança
- CSRF rotacionado a cada resposta AJAX (sucesso e erro)
- Posse revalidada no servidor a cada escrita; escopo do gestor
  reverificado a cada POST
- Exclusão sempre soft; escrita em tabela nativa só via objetos do core
- Saída JSON com `JSON_HEX_TAG|AMP|APOS|QUOT`; DOM escrito com
  `textContent`

[0.1.0-beta]: https://github.com/teckcomp/glpi-plugin-taskplus/releases/tag/v0.1.0-beta
