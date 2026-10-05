@php
    $logoOntDataUri = $logoOntDataUri ?? ('data:image/png;base64,'.base64_encode((string) file_get_contents(base_path('Modules/Courrier/resources/ONT.png'))));
@endphp

@if (($enteteInstitutionnelleLettre ?? false) === true)
    <div class="filigrane" aria-hidden="true">
        <img src="{{ $logoOntDataUri }}" alt="">
    </div>

    <table class="entete-institutionnelle">
        <tr>
            <td class="identite-institutionnelle">
                <div class="republique">REPUBLIQUE DEMOCRATIQUE DU CONGO</div>
                <div class="nom-institution">OFFICE NATIONAL DU TOURISME</div>
                <img class="logo-entete" src="{{ $logoOntDataUri }}" alt="Logo ONT">
                <div class="fonction-entete">Le Directeur Général</div>
            </td>
            <td class="date-reference">
                @if (isset($dateOfficielle) && isset($referenceNref))
                    <div class="date-ligne">Kinshasa, le {{ $dateOfficielle }}</div>
                    <div class="reference">N/Réf : {{ $referenceNref }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if (isset($copiesInformation) && is_array($copiesInformation) && $copiesInformation !== [])
        <div class="copies-information">
            <strong>Transmis copie pour information à :</strong>
            <ul>
                @foreach ($copiesInformation as $copie)
                    <li>{{ $copie }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@else
    @php
        $ontLogoSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
            .'<rect width="40" height="40" rx="10" fill="#184b85"/>'
            .'<circle cx="26" cy="14" r="6" fill="#f8ae33"/>'
            .'<g stroke="#f8ae33" stroke-width="1.6" stroke-linecap="round">'
            .'<line x1="26" y1="3.5" x2="26" y2="1.5"/>'
            .'<line x1="34.5" y1="6.5" x2="36" y2="5"/>'
            .'<line x1="37.5" y1="14" x2="39.5" y2="14"/>'
            .'<line x1="17.5" y1="6.5" x2="16" y2="5"/>'
            .'</g>'
            .'<path d="M12 30.5c0-4.8 3.6-8.7 8-8.7s8 3.9 8 8.7c0 .9-.9 1.5-1.7 1.1l-2.9-1.4c-2.2-1-4.6-1-6.8 0l-2.9 1.4c-.8.4-1.7-.2-1.7-1.1z" fill="#fff"/>'
            .'<path d="M15 22c-1.6-2.8-1.3-6 .6-8.4M25 22c1.6-2.8 1.3-6-.6-8.4" stroke="#f8ae33" stroke-width="1.8" stroke-linecap="round" fill="none"/>'
            .'<circle cx="17.2" cy="27.5" r="1.1" fill="#184b85"/>'
            .'<circle cx="22.8" cy="27.5" r="1.1" fill="#184b85"/>'
            .'</svg>';
    @endphp
    <table class="entete-tableau">
        <tr>
            <td class="entete-logo"><img src="data:image/svg+xml;base64,{{ base64_encode($ontLogoSvg) }}" width="42" height="42"></td>
            <td class="entete-texte">
                <h1>République Démocratique du Congo</h1>
                <h2>Office National du Tourisme (ONT)</h2>
            </td>
        </tr>
    </table>
@endif
