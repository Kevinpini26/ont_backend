<?php

namespace Modules\Courrier\Support;

use Illuminate\Validation\ValidationException;

/**
 * Convertit le contenu structuré TipTap (JSON ProseMirror) en HTML, pour le
 * rendu des corps TipTap pour les PDF.
 * L'éditeur lui-même ne fait jamais ce rendu côté serveur (TipTapEditor.jsx)
 * — chaque nœud texte est échappé explicitement. Le rendu historique reste
 * tolérant pour préserver les PDF existants; renderOfficial valide d'abord
 * strictement le sous-ensemble réellement produit par TipTapEditor.
 */
class TipTapHtmlRenderer
{
    public function render(?array $document): string
    {
        if (! $document) {
            return '';
        }

        return $this->renderNodes($document['content'] ?? []);
    }

    public function renderOfficial(array $document): string
    {
        if (($document['type'] ?? null) !== 'doc'
            || array_diff(array_keys($document), ['type', 'content']) !== []
            || ! isset($document['content'])
            || ! is_array($document['content'])
            || ! array_is_list($document['content'])
            || $document['content'] === []) {
            throw ValidationException::withMessages([
                'contenu' => 'Le contenu doit être un document TipTap non vide et entièrement représentable.',
            ]);
        }

        foreach ($document['content'] as $node) {
            $this->validerBlocOfficiel($node);
        }

        if (! $this->contientTexteSignificatif($document['content'])) {
            throw ValidationException::withMessages([
                'contenu' => 'Le document officiel doit contenir du texte.',
            ]);
        }

        return $this->renderNodes($document['content']);
    }

    private function validerBlocOfficiel(mixed $node): void
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null)) {
            $this->contenuOfficielInvalide();
        }

        $type = $node['type'];
        $clesAutorisees = match ($type) {
            'paragraph', 'bulletList' => ['type', 'content'],
            'heading' => ['type', 'attrs', 'content'],
            default => null,
        };

        if ($clesAutorisees === null || array_diff(array_keys($node), $clesAutorisees) !== []) {
            $this->contenuOfficielInvalide();
        }

        if ($type === 'heading' && ($node['attrs'] ?? null) !== ['level' => 2]) {
            $this->contenuOfficielInvalide();
        }

        if ($type === 'paragraph'
            && (! array_key_exists('content', $node) || $node['content'] === [])) {
            return;
        }

        $enfants = $node['content'] ?? null;
        if (! is_array($enfants) || ! array_is_list($enfants)) {
            $this->contenuOfficielInvalide();
        }

        if ($type === 'paragraph' || $type === 'heading') {
            foreach ($enfants as $enfant) {
                $this->validerInlineOfficiel($enfant);
            }

            return;
        }

        if ($enfants === []) {
            $this->contenuOfficielInvalide();
        }

        if ($type === 'bulletList') {
            foreach ($enfants as $enfant) {
                if (! is_array($enfant) || ($enfant['type'] ?? null) !== 'listItem') {
                    $this->contenuOfficielInvalide();
                }

                $this->validerListItemOfficiel($enfant);
            }

            return;
        }

        foreach ($enfants as $enfant) {
            $this->validerBlocOfficiel($enfant);
        }
    }

    private function contientTexteSignificatif(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'text' && trim($node['text']) !== '') {
                return true;
            }

            if (isset($node['content']) && $this->contientTexteSignificatif($node['content'])) {
                return true;
            }
        }

        return false;
    }

    private function validerListItemOfficiel(array $node): void
    {
        if (array_diff(array_keys($node), ['type', 'content']) !== []
            || ! is_array($node['content'] ?? null)
            || ! array_is_list($node['content'])
            || ($node['content'][0]['type'] ?? null) !== 'paragraph') {
            $this->contenuOfficielInvalide();
        }

        foreach ($node['content'] as $enfant) {
            $this->validerBlocOfficiel($enfant);
        }
    }

    private function validerInlineOfficiel(mixed $node): void
    {
        if (! is_array($node) || ! is_string($node['type'] ?? null)) {
            $this->contenuOfficielInvalide();
        }

        if ($node['type'] !== 'text'
            || array_diff(array_keys($node), ['type', 'text', 'marks']) !== []
            || ! isset($node['text'])
            || ! is_string($node['text'])
            || $node['text'] === '') {
            $this->contenuOfficielInvalide();
        }

        $marques = $node['marks'] ?? [];
        if (! is_array($marques) || ! array_is_list($marques)) {
            $this->contenuOfficielInvalide();
        }

        $typesMarques = [];
        foreach ($marques as $marque) {
            if (! is_array($marque)
                || array_keys($marque) !== ['type']
                || ! in_array($marque['type'] ?? null, ['bold', 'italic'], true)
                || in_array($marque['type'], $typesMarques, true)) {
                $this->contenuOfficielInvalide();
            }

            $typesMarques[] = $marque['type'];
        }
    }

    private function contenuOfficielInvalide(): never
    {
        throw ValidationException::withMessages([
            'contenu' => 'Le contenu contient une structure TipTap non prise en charge pour le PDF officiel.',
        ]);
    }

    private function renderNodes(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->renderNode($node);
        }

        return $html;
    }

    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? null;
        $contenuEnfants = fn () => $this->renderNodes($node['content'] ?? []);

        return match ($type) {
            'paragraph' => '<p>'.$contenuEnfants().'</p>',
            'heading' => $this->renderHeading($node, $contenuEnfants()),
            'bulletList' => '<ul>'.$contenuEnfants().'</ul>',
            'orderedList' => '<ol>'.$contenuEnfants().'</ol>',
            'listItem' => '<li>'.$contenuEnfants().'</li>',
            'blockquote' => '<blockquote>'.$contenuEnfants().'</blockquote>',
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'text' => $this->renderText($node),
            default => $contenuEnfants(),
        };
    }

    private function renderHeading(array $node, string $contenu): string
    {
        $niveau = min(max((int) ($node['attrs']['level'] ?? 2), 1), 6);

        return "<h{$niveau}>{$contenu}</h{$niveau}>";
    }

    private function renderText(array $node): string
    {
        $texte = e($node['text'] ?? '');

        foreach (($node['marks'] ?? []) as $marque) {
            $texte = match ($marque['type'] ?? null) {
                'bold' => "<strong>{$texte}</strong>",
                'italic' => "<em>{$texte}</em>",
                'strike' => "<s>{$texte}</s>",
                'code' => "<code>{$texte}</code>",
                default => $texte,
            };
        }

        return $texte;
    }
}
