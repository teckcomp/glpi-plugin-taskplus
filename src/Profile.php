<?php

namespace GlpiPlugin\Taskplus;

use CommonGLPI;
use Html;
use Profile as CoreProfile;
use Session;

/**
 * Task+ — aba "Task+" na tela de Perfil (complemento da Etapa 5a).
 *
 * A Etapa 0 registrou os dois direitos do plugin em glpi_profilerights
 * (Migration::addRight), mas NUNCA criou a interface para marcá-los: a
 * aba "Todos" do Perfil só exibe direitos apresentados por alguma
 * classe, então os do Task+ ficavam invisíveis — configurável apenas
 * por SQL, que não usamos. Esta classe fecha o buraco no padrão
 * consagrado dos plugins GLPI (o ProjectPlus tem a mesma aba):
 *
 *  - registrada no setup.php com Plugin::registerClass(...,
 *    ['addtabon' => 'Profile']);
 *  - o formulário POSTa para o Profile.form.php do CORE com os campos
 *    `_<nome_do_direito>[bit]` — o próprio core processa qualquer
 *    direito existente em glpi_profilerights no update do perfil
 *    (ProfileRight::updateProfileRights). Nenhum SQL nosso.
 *
 * DECISÃO DE EXIBIÇÃO: cada direito aparece como UM interruptor (só o
 * bit READ), porque é ASSIM que o plugin os consome — Access::can()
 * verifica READ em tudo; os bits CREATE/UPDATE/DELETE registrados na
 * Etapa 0 nunca são checados em lugar nenhum. Mostrar 4 colunas só
 * geraria a dúvida "preciso marcar todas?". Salvar por aqui grava
 * value=1 (READ) — suficiente para todos os gates atuais; se um dia um
 * gate usar outro bit, esta matriz é o único lugar a ajustar.
 */
class Profile extends CoreProfile
{
    /**
     * Nome da aba na tela de Perfil. Só para o próprio Perfil — a
     * classe estende CoreProfile por padrão de plugin, mas a aba não
     * deve vazar para outros itemtypes.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof CoreProfile) {
            return self::createTabEntry(__('Tarefas', 'taskplus'), 0, $item::getType(), 'ti ti-checklist');
        }
        return '';
    }

    /**
     * Conteúdo da aba.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CoreProfile && (int) $item->getID() > 0) {
            $tab = new self();
            $tab->showTaskplusForm((int) $item->getID());
        }
        return true;
    }

    /**
     * As duas linhas da matriz de direitos. Método público e puro de
     * propósito: o harness confere rótulos e campos sem renderizar.
     */
    public static function rightsMatrix(): array
    {
        return [
            [
                'rights' => [READ => __('Habilitado', 'taskplus')],
                'label'  => __('Usar o plugin Tarefas (tarefas próprias: Hoje, Quadro, Rotinas)', 'taskplus'),
                'field'  => 'plugin_taskplus_task',
            ],
            [
                'rights' => [READ => __('Habilitado', 'taskplus')],
                'label'  => __('Gestão de equipe (tela Equipe e fases dos setores — exige "Gerente" no grupo)', 'taskplus'),
                'field'  => 'plugin_taskplus_manage',
            ],
            // 8b: página inicial por perfil (decisão nº 10) — vale no
            // PRÓXIMO login (T19: a sessão guarda os direitos do perfil).
            [
                'rights' => [READ => __('Habilitado', 'taskplus')],
                'label'  => __('Página inicial: entrar direto na tela Hoje do plugin (no lugar da Visão Geral)', 'taskplus'),
                'field'  => 'plugin_taskplus_home',
            ],
        ];
    }

    /**
     * Formulário da aba: matriz do core + botão Salvar POSTando para o
     * Profile.form.php NATIVO. Quem não pode editar perfis vê a matriz
     * travada (canedit=false), sem form.
     */
    public function showTaskplusForm(int $profilesId): void
    {
        $profile = new CoreProfile();
        if (!$profile->getFromDB($profilesId)) {
            return;
        }

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . CoreProfile::getFormURL() . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::rightsMatrix(), [
            'canedit' => $canedit,
            'title'   => __('Tarefas', 'taskplus'),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profilesId]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
