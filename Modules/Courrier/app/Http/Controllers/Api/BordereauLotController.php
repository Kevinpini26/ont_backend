<?php

namespace Modules\Courrier\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Courrier\Contracts\BordereauLotPdfGenerator;
use Modules\Courrier\Http\Requests\CreerBordereauLotRequest;
use Modules\Courrier\Http\Resources\BordereauLotResource;
use Modules\Courrier\Models\BordereauLot;
use Modules\Courrier\Services\CourrierCircuitService;

/**
 * Lot C, points 1-2 : transmission par lot vers le tri — un seul bordereau,
 * une seule décharge, une trace individuelle par dossier (voir
 * BordereauLot::courriers()).
 */
class BordereauLotController extends Controller
{
    public function __construct(
        private readonly CourrierCircuitService $circuit,
        private readonly BordereauLotPdfGenerator $pdf,
    ) {}

    public function store(CreerBordereauLotRequest $request)
    {
        $bordereau = $this->circuit->grouperEnBordereauLot($request->validated('courrier_ids'), $request->user());

        return new BordereauLotResource($bordereau->load(['emetteur', 'courriers']));
    }

    public function show(Request $request, BordereauLot $bordereau)
    {
        $this->authorize('view', $bordereau);

        return new BordereauLotResource($bordereau->load(['emetteur', 'accuseReceptionPar', 'courriers']));
    }

    public function accuserReception(Request $request, BordereauLot $bordereau)
    {
        $this->authorize('accuserReception', $bordereau);

        $bordereau = $this->circuit->accuserReceptionLot($bordereau, $request->user());

        return new BordereauLotResource($bordereau->load(['emetteur', 'accuseReceptionPar', 'courriers']));
    }

    public function telechargerPdf(Request $request, BordereauLot $bordereau)
    {
        $this->authorize('view', $bordereau);

        $pdf = $this->pdf->generer($bordereau);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"bordereau-lot-{$bordereau->numero}.pdf\"",
        ]);
    }
}
