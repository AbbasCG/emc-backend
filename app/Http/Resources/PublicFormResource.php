<?php

namespace App\Http\Resources;

use App\Models\DynamicForm;
use App\Services\Forms\FormAvailabilityService;

/**
 * A form as an anonymous respondent sees it: what is needed to fill it in, nothing about who owns it, which department it
 * belongs to, how many people have answered or who may see the answers.
 *
 * It is built from a published version's frozen content, never from the editable draft, so editing a live form changes
 * nothing for respondents until the edit is published. `availability` says whether (and why not) the form is accepting
 * answers right now; the questions are only included while it is, so a closed or full form reveals nothing but its title.
 */
final class PublicFormResource
{
    /**
     * @param  array<string, mixed>  $content  the version content: form{title,description,type} and questions[]
     * @param  array<string, mixed>  $availability  FormAvailabilityService::evaluate()
     * @return array<string, mixed>
     */
    public static function present(DynamicForm $form, array $content, array $availability, ?int $versionNumber = null, bool $preview = false): array
    {
        $open = $preview || $availability['state'] === 'open';
        $meta = $content['form'] ?? [];
        // A visitor who has not shown they may see a restricted form (no valid link, not an invited signed-in user) is told why,
        // but learns nothing about the form: not its title, not its description. An invited form also keeps them back until sign-in.
        $hidden = ! $preview && (in_array($availability['state'], FormAvailabilityService::HIDES_FORM, true)
            || ($form->invitation_only && $availability['state'] !== FormAvailabilityService::OPEN));

        return [
            'id'           => $form->id,
            'title'        => $hidden ? 'نموذج مقيَّد' : ($meta['title'] ?? $form->title),
            'slug'         => $form->slug,
            'description'  => $hidden ? null : ($meta['description'] ?? null),
            'type'         => $hidden ? null : ($meta['type'] ?? $form->type),
            'form_type'    => $hidden ? null : ($meta['type'] ?? $form->type),
            'status'       => $form->status,
            'version'      => $versionNumber,
            'preview'      => $preview,
            'availability' => $availability,
            'questions'    => $open ? self::questions($content['questions'] ?? []) : [],
            // How the respondent moves through the form (today: the review step). Part of the published version.
            'flow'         => $open && ! $hidden ? ($meta['flow'] ?? null) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function questions(array $questions): array
    {
        return array_values(array_map(fn (array $q) => [
            'id'          => $q['id'],
            'label'       => $q['label'],
            'type'        => $q['type'],
            'options'     => $q['options'] ?? null,
            'is_required' => (bool) $q['required'],
            'required'    => (bool) $q['required'],
            'sort_order'  => (int) $q['sort_order'],
            'description' => $q['description'] ?? null,
            'placeholder' => $q['placeholder'] ?? null,
            'help_text'   => $q['help_text'] ?? null,
            'config'      => $q['config'] ?? null,
            // The rule that shows or hides this block. The browser evaluates it for comfort; the server enforces it on every submission.
            'logic'       => $q['logic'] ?? null,
        ], $questions));
    }
}
