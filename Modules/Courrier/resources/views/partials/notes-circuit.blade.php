{{-- Notes du circuit visibles (jamais les rappels personnels). Variables : $notes (collection de CourrierAnnotation), $masquees (bool). --}}
@if ($masquees)
    <p style="font-size: 9px; font-style: italic;">Notes du circuit non reproduites : courrier confidentiel.</p>
@elseif ($notes->isNotEmpty())
    <table class="historique" style="width: 100%; border-collapse: collapse; margin-top: 6px;">
        <tr>
            <th style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; background: #eef2f7; text-align: left;">Type</th>
            <th style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; background: #eef2f7; text-align: left;">Auteur</th>
            <th style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; background: #eef2f7; text-align: left;">Date</th>
            <th style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; background: #eef2f7; text-align: left;">Destinataire</th>
            <th style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; background: #eef2f7; text-align: left;">Note</th>
        </tr>
        @foreach ($notes as $note)
            <tr>
                <td style="border: 1px solid #999; padding: 4px 6px; font-size: 9px; font-weight: bold;">{{ $note->type->label() }}</td>
                <td style="border: 1px solid #999; padding: 4px 6px; font-size: 9px;">
                    {{ $note->auteur?->name }}@if ($note->auteur?->poste) ({{ $note->auteur->poste->label() }})@endif
                </td>
                <td style="border: 1px solid #999; padding: 4px 6px; font-size: 9px;">{{ $note->created_at?->timezone(config('app.timezone_affichage', 'Africa/Kinshasa'))->format('d/m/Y H:i') }}</td>
                <td style="border: 1px solid #999; padding: 4px 6px; font-size: 9px;">
                    {{ \Modules\Kernel\Enums\Poste::tryFrom($note->destinataire_poste ?? '')?->label() ?? $note->destinataireDirection?->nom ?? '' }}
                </td>
                <td style="border: 1px solid #999; padding: 4px 6px; font-size: 9px;">
                    {{ $note->contenu }}@if ($note->annule_par_id) (corrigée)@endif
                </td>
            </tr>
        @endforeach
    </table>
@else
    <p style="font-size: 9px; font-style: italic;">Aucune note du circuit.</p>
@endif
