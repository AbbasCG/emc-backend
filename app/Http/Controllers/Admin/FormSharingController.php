<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DynamicForm;
use App\Models\FormInvitation;
use App\Models\FormShareLink;
use App\Models\User;
use App\Services\Forms\FormShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Share screen's API: how a form is reached, by whom, and through which links.
 *
 * Reading is open to whoever may edit the form; every change (making or revoking a link, the access mode, invitations) is for the
 * owner and platform admins only (DynamicFormPolicy::manageSharing). A link's token is returned once, in the response that creates
 * it; nothing here can show it again, because only its hash is stored.
 */
class FormSharingController extends Controller
{
    public function __construct(private readonly FormShareService $shares) {}

    public function show(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('viewSharing', $form);

        return response()->json(['success' => true, 'data' => $this->payload($request, $form)]);
    }

    public function updateAccess(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageSharing', $form);

        $data = $request->validate([
            'access_mode'         => ['required', Rule::in(DynamicForm::ACCESS_MODES)],
            'requires_share_link' => ['required', 'boolean'],
        ], [
            'access_mode.in' => 'نمط الوصول غير صالح.',
        ]);

        $form = $this->shares->setAccess($form, $request->user(), $data['access_mode'], (bool) $data['requires_share_link'], $request);

        return response()->json(['success' => true, 'data' => $this->payload($request, $form)]);
    }

    public function storeLink(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageSharing', $form);

        $data = $request->validate([
            'label'      => ['required', 'string', 'max:'.FormShareService::LABEL_MAX],
            'expires_at' => ['nullable', 'date'],
            'max_uses'   => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ], [
            'label.required'  => 'اكتب اسماً للرابط يساعدك على تمييزه.',
            'label.max'       => 'اسم الرابط أطول من المسموح ('.FormShareService::LABEL_MAX.' حرفاً).',
            'expires_at.date' => 'موعد انتهاء الرابط غير صالح.',
            'max_uses.min'    => 'الحد الأقصى للإجابات يجب أن يكون 1 على الأقل.',
            'max_uses.integer' => 'الحد الأقصى للإجابات يجب أن يكون رقماً صحيحاً.',
        ]);

        [$link, $token] = $this->shares->createLink(
            $form,
            $request->user(),
            $data['label'],
            isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            $request,
        );

        return response()->json([
            'success' => true,
            'data'    => $this->linkRow($link->loadCount('submissions')->load(['creator', 'revoker'])) + [
                // The only time the token exists in readable form.
                'token' => $token,
                'path'  => '/forms/'.$form->slug.'/s/'.$token,
            ],
        ], 201);
    }

    public function revokeLink(Request $request, DynamicForm $form, FormShareLink $link): JsonResponse
    {
        abort_if($link->form_id !== $form->id, 404);
        $this->authorize('manageSharing', $form);

        $link = $this->shares->revoke($link, $request->user(), $request);

        return response()->json(['success' => true, 'data' => $this->linkRow($link->loadCount('submissions')->load(['creator', 'revoker']))]);
    }

    public function invitationCandidates(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageSharing', $form);

        return response()->json([
            'success' => true,
            'data'    => $this->shares->candidates($form, (string) $request->query('q', ''))->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function invite(Request $request, DynamicForm $form): JsonResponse
    {
        $this->authorize('manageSharing', $form);
        $data = $request->validate(['user_id' => ['required', 'integer']], ['user_id.required' => 'اختر المستخدم الذي تريد دعوته.']);

        $invitation = $this->shares->invite($form, $request->user(), (int) $data['user_id'], $request);

        return response()->json(['success' => true, 'data' => $this->invitationRow($invitation->load(['user', 'inviter', 'revoker']))], 201);
    }

    public function revokeInvitation(Request $request, DynamicForm $form, FormInvitation $invitation): JsonResponse
    {
        abort_if($invitation->form_id !== $form->id, 404);
        $this->authorize('manageSharing', $form);

        $invitation = $this->shares->revokeInvitation($invitation, $request->user(), $request);

        return response()->json(['success' => true, 'data' => $this->invitationRow($invitation->load(['user', 'inviter', 'revoker']))]);
    }

    // ── Shapes ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function payload(Request $request, DynamicForm $form): array
    {
        $form->refresh();
        $links = $form->shareLinks()->withCount('submissions')->with(['creator', 'revoker'])->get();
        $invitations = $form->invitations()->with(['user', 'inviter', 'revoker'])->get();

        return [
            'form'        => ['id' => $form->id, 'slug' => $form->slug, 'status' => $form->status, 'public_path' => '/forms/'.$form->slug],
            'access'      => ['mode' => $form->accessMode(), 'requires_share_link' => (bool) $form->requires_share_link],
            'public'      => $this->shares->publicStatus($form),
            'links'       => $links->map(fn (FormShareLink $l) => $this->linkRow($l))->values(),
            'invitations' => $invitations->map(fn (FormInvitation $i) => $this->invitationRow($i))->values(),
            'abilities'   => ['manage' => $request->user()->can('manageSharing', $form)],
        ];
    }

    /** @return array<string, mixed> */
    private function linkRow(FormShareLink $link): array
    {
        $uses = (int) ($link->submissions_count ?? $this->shares->uses($link));

        return [
            'id'          => $link->id,
            'label'       => $link->label,
            'token_hint'  => $link->token_hint,
            'status'      => $link->statusFor($uses),
            'uses'        => $uses,
            'max_uses'    => $link->max_uses,
            'expires_at'  => $link->expires_at?->toIso8601String(),
            'created_at'  => $link->created_at?->toIso8601String(),
            'created_by'  => $link->creator ? ['id' => $link->creator->id, 'name' => $link->creator->name] : null,
            'revoked_at'  => $link->revoked_at?->toIso8601String(),
            'revoked_by'  => $link->revoker ? ['id' => $link->revoker->id, 'name' => $link->revoker->name] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function invitationRow(FormInvitation $invitation): array
    {
        return [
            'id'         => $invitation->id,
            'user'       => $invitation->user ? ['id' => $invitation->user->id, 'name' => $invitation->user->name] : null,
            'invited_by' => $invitation->inviter ? ['id' => $invitation->inviter->id, 'name' => $invitation->inviter->name] : null,
            'created_at' => $invitation->created_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'revoked_by' => $invitation->revoker ? ['id' => $invitation->revoker->id, 'name' => $invitation->revoker->name] : null,
        ];
    }
}
