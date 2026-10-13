<?php

namespace Modules\Courrier\Tests\Unit;

use Mockery;
use Modules\Courrier\Enums\AvisDg;
use Modules\Courrier\Enums\CourrierStatut;
use Modules\Courrier\Enums\DispatchStatut;
use Modules\Courrier\Enums\DispatchTypeDestination;
use Modules\Courrier\Enums\DocumentProduitOrigine;
use Modules\Courrier\Enums\DocumentProduitStatut;
use Modules\Courrier\Models\Courrier;
use Modules\Courrier\Models\DispatchCourrier;
use Modules\Courrier\Models\DocumentProduitDirection;
use Modules\Courrier\Policies\DispatchCourrierPolicy;
use Modules\Kernel\Enums\Poste;
use Modules\Kernel\Enums\UserRole;
use Modules\Kernel\Models\User;
use Modules\Kernel\Support\DelegationResolver;
use Tests\TestCase;

class DispatchCourrierAutonomePolicyTest extends TestCase
{
    public function test_scan_access_is_limited_to_sec2_or_the_transmitted_dispatch_destination(): void
    {
        $delegations = Mockery::mock(DelegationResolver::class);
        $delegations->shouldReceive('utilisateurHabilite')->andReturnUsing(
            fn (User $user, array $postes): bool => $user->poste === Poste::SECRETARIAT_2
                && in_array(Poste::SECRETARIAT_2, $postes, true),
        );
        $policy = new DispatchCourrierPolicy($delegations);
        $dispatch = $this->dispatchAutonome();
        $sec2 = $this->user(10, UserRole::AGENT_CIRCUIT_COURRIER, Poste::SECRETARIAT_2, 1);
        $directionDestinataire = $this->user(11, UserRole::SECRETARIAT_DIRECTION, null, 2);
        $autreDirection = $this->user(12, UserRole::SECRETARIAT_DIRECTION, null, 3);

        self::assertTrue($policy->consulterScanAutonomeSec2($sec2, $dispatch));
        self::assertFalse($policy->consulterScanAutonomeSec2($directionDestinataire, $dispatch));

        $this->marquerTransmis($dispatch);

        self::assertTrue($policy->consulterScanAutonomeSec2($directionDestinataire, $dispatch));
        self::assertFalse($policy->consulterScanAutonomeSec2($autreDirection, $dispatch));
    }

    public function test_null_dossier_is_not_a_valid_autonomous_dispatch_when_the_document_is_historical(): void
    {
        $delegations = Mockery::mock(DelegationResolver::class);
        $delegations->shouldReceive('utilisateurHabilite')->andReturnTrue();
        $policy = new DispatchCourrierPolicy($delegations);
        $dispatch = $this->dispatchAutonome();
        $dispatch->courrier->documentProduitDirection->origine = DocumentProduitOrigine::TRAITEMENT_DIRECTION;
        $sec2 = $this->user(10, UserRole::AGENT_CIRCUIT_COURRIER, Poste::SECRETARIAT_2, 1);

        self::assertFalse($policy->consulterScanAutonomeSec2($sec2, $dispatch));
    }

    public function test_sec2_execution_policy_rejects_completed_or_non_pending_dispatches(): void
    {
        $delegations = Mockery::mock(DelegationResolver::class);
        $delegations->shouldReceive('utilisateurHabilite')
            ->with(Mockery::type(User::class), [Poste::SECRETARIAT_2])
            ->andReturnUsing(fn (User $user): bool => $user->poste === Poste::SECRETARIAT_2);
        $policy = new DispatchCourrierPolicy($delegations);
        $dispatch = $this->dispatchAutonome();
        $sec2 = $this->user(10, UserRole::AGENT_CIRCUIT_COURRIER, Poste::SECRETARIAT_2, 1);
        $autrePoste = $this->user(13, UserRole::AGENT_CIRCUIT_COURRIER, Poste::SECRETARIAT_1, 1);

        self::assertTrue($policy->executer($sec2, $dispatch));
        self::assertFalse($policy->executer($autrePoste, $dispatch));

        $dispatch->statut = DispatchStatut::EXECUTE;
        self::assertFalse($policy->executer($sec2, $dispatch));
    }

    private function dispatchAutonome(): DispatchCourrier
    {
        $document = (new DocumentProduitDirection)->forceFill([
            'id' => 7,
            'origine' => DocumentProduitOrigine::AUTONOME,
            'statut' => DocumentProduitStatut::ENTRE_CIRCUIT,
            'direction_id' => 1,
            'courrier_id' => 30,
            'traitement_direction_id' => null,
            'document_source_id' => null,
            'scan_officiel_document_id' => 91,
            'transmis_reception_par_id' => 14,
            'transmis_reception_at' => '2026-10-13 09:00:00',
            'recu_par_id' => 15,
            'recu_at' => '2026-10-13 09:10:00',
        ]);
        $courrier = (new Courrier)->forceFill([
            'id' => 30,
            'dossier_id' => null,
            'statut' => CourrierStatut::EN_DISPATCH,
            'direction_origine_id' => 1,
            'direction_destination_id' => 2,
            'reference_documentaire' => '123/DG/ABC/ONT/2026',
            'numero_enregistrement' => 'ONT-2026-123',
            'avis_dg' => AvisDg::FAVORABLE,
            'avis_dg_rendu_par_id' => 20,
            'avis_dg_rendu_at' => '2026-10-13 09:20:00',
        ]);
        $courrier->setRelation('documentProduitDirection', $document);
        $dispatch = (new DispatchCourrier)->forceFill([
            'id' => 50,
            'courrier_id' => 30,
            'dossier_id' => null,
            'type_destination' => DispatchTypeDestination::DIRECTION,
            'direction_id' => 2,
            'statut' => DispatchStatut::EN_ATTENTE,
            'decisionnaire_id' => 20,
            'decisionnaire_poste' => Poste::DG,
            'autorite_poste' => Poste::DG,
            'decide_at' => '2026-10-13 09:20:00',
        ]);
        $dispatch->setRelation('courrier', $courrier);

        return $dispatch;
    }

    private function marquerTransmis(DispatchCourrier $dispatch): void
    {
        $dispatch->statut = DispatchStatut::EXECUTE;
        $dispatch->execute_par_id = 25;
        $dispatch->execute_at = '2026-10-13 10:00:00';
        $dispatch->courrier->statut = CourrierStatut::DISPATCH_EXECUTE;
    }

    private function user(int $id, UserRole $role, ?Poste $poste, int $directionId): User
    {
        return (new User)->forceFill([
            'id' => $id,
            'role' => $role,
            'poste' => $poste,
            'direction_id' => $directionId,
        ]);
    }
}
