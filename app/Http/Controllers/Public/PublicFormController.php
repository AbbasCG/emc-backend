<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\FormUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicFormResource;
use App\Models\DynamicForm;
use App\Models\FormVersion;
use App\Services\Forms\FormAvailabilityService;
use App\Services\Forms\FormShareService;
use App\Services\Forms\FormSnapshotService;
use App\Services\Forms\FormSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public face of a form: read a published form by its link, and submit answers to it.
 *
 * Respondents are served from the PUBLISHED VERSION (frozen content), never from the editable draft, and every answer is
 * stored against the version it was given for. A draft, archived or deleted form answers 404 exactly like a link that never
 * existed; a closed one answers 200 with its state, so people are told it is closed instead of "not found".
 *
 * Availability (closed, not open yet, ended, full, sign-in required) is decided on the server, twice: before validating, and
 * again inside the locked transaction that stores the submission. Submission is rate limited per form and per address
 * (`form_submissions`) and signs the submission to the signed-in user when a valid token is sent (`optional.sanctum`).
 *
 * Phase 3: a request may carry a share-link token in the `X-Form-Share` header. It is judged by FormShareService against THIS form
 * (a token of another form is simply invalid), and the form's access rules (link required, sign-in, invitation) are part of the
 * same availability decision. A visitor who has not shown they may see a restricted form is told so without learning its title.
 */
class PublicFormController extends Controller
{
    public function __construct(
        private readonly FormAvailabilityService $availability,
        private readonly FormSnapshotService $snapshots,
        private readonly FormShareService $shares,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $form = $this->reachableForm($slug);
        [$content, $version] = $this->servedContent($form);
        $share = $this->shares->resolve($form, $this->shareToken($request));

        return response()->json([
            'success' => true,
            'data'    => PublicFormResource::present($form, $content, $this->availability->evaluate($form, $request->user(), $share), $version?->version_number),
        ]);
    }

    public function submit(Request $request, string $slug, FormSubmissionService $submissions): JsonResponse
    {
        $form = $this->reachableForm($slug);
        $user = $request->user();

        $shareToken = $this->shareToken($request);
        $state = $this->availability->evaluate($form, $user, $this->shares->resolve($form, $shareToken));
        if ($state['state'] !== FormAvailabilityService::OPEN) {
            return $this->refusal(new FormUnavailableException($state['state'], (string) $state['message']));
        }

        [$content, $version] = $this->servedContent($form);
        $questions = FormSnapshotService::questionModels($content);

        $validated = $submissions->validate($form, $request->all(), $questions);

        try {
            $submission = $submissions->submit($form, $validated, $user, $questions, $version?->id, $shareToken);
        } catch (FormUnavailableException $e) {
            return $this->refusal($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Form submitted successfully.',
            'data'    => ['submission_id' => $submission->id],
        ], 201);
    }

    /** The share token, from the header only: a query string would put it in access logs and referrers. */
    private function shareToken(Request $request): ?string
    {
        $token = $request->header('X-Form-Share');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /** Drafts, archived and deleted forms do not exist as far as a visitor can tell. */
    private function reachableForm(string $slug): DynamicForm
    {
        return DynamicForm::with('publishedVersion')
            ->where('slug', $slug)
            ->whereIn('status', ['active', 'closed'])
            ->firstOrFail();
    }

    /**
     * The content respondents are given: the published version's. A form that went live before versions existed and has not
     * been backfilled yet is served from its live questions (and its submissions carry no version) rather than going dark.
     *
     * @return array{0: array<string, mixed>, 1: FormVersion|null}
     */
    private function servedContent(DynamicForm $form): array
    {
        $version = $form->publishedVersion;
        if ($version !== null) {
            return [$version->schema, $version];
        }

        return [FormSnapshotService::content($form->getAttributes(), $form->questions()->get()), null];
    }

    private function refusal(FormUnavailableException $e): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $e->getMessage(), 'code' => $e->state], $e->httpStatus());
    }
}
