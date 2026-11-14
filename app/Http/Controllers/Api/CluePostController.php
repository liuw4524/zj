<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCluePostRequest;
use App\Http\Requests\UpdateClueContactRequest;
use App\Models\ClueContactRequest;
use App\Models\CluePost;
use App\Services\CluePostPresenter;
use App\Services\CluePostService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CluePostController extends Controller
{
    public function __construct(
        private CluePostService $clues,
        private CluePostPresenter $presenter,
    ) {}

    public function share(Request $request, CluePost $cluePost)
    {
        return ApiResponse::ok([
            'growth_awarded' => $this->clues->share($request->user(), $cluePost),
        ]);
    }

    public function index(Request $request)
    {
        $province = trim((string) $request->query('province', ''));
        $city = trim((string) $request->query('city', ''));
        if ($province === '' || $city === '') {
            throw new BusinessException('请选择城市');
        }

        $paginator = $this->clues->publicList($province, $city);
        $paginator->through(fn (CluePost $post) => $this->presenter->card($post, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function store(StoreCluePostRequest $request)
    {
        $post = $this->clues->create($request->user(), $request->validated());

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function mine(Request $request)
    {
        $paginator = $this->clues->myPosts($request->user());
        $paginator->through(fn (CluePost $post) => $this->presenter->card($post, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function show(Request $request, CluePost $cluePost)
    {
        $post = $this->clues->show($request->user(), $cluePost);

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function light(Request $request, CluePost $cluePost)
    {
        $post = $this->clues->light($request->user(), $cluePost);

        return ApiResponse::ok($this->presenter->detail($post->load(['images', 'contactRequests.user']), $request->user()));
    }

    public function withdraw(Request $request, CluePost $cluePost)
    {
        $post = $this->clues->withdraw($request->user(), $cluePost);

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function updateContact(UpdateClueContactRequest $request, CluePost $cluePost)
    {
        $post = $this->clues->updateContact($request->user(), $cluePost, (bool) $request->validated('contact_public'));

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function storeRequest(Request $request, CluePost $cluePost)
    {
        $item = $this->clues->requestContact($request->user(), $cluePost);

        return ApiResponse::ok($this->presenter->requestItem($item));
    }

    public function myRequests(Request $request)
    {
        $paginator = $this->clues->myRequests($request->user());
        $paginator->through(fn (ClueContactRequest $item) => $this->presenter->requestItem($item));

        return ApiResponse::paginated($paginator);
    }

    public function approveRequest(Request $request, ClueContactRequest $clueContactRequest)
    {
        $item = $this->clues->approveRequest($request->user(), $clueContactRequest);

        return ApiResponse::ok($this->presenter->requestItem($item));
    }

    public function rejectRequest(Request $request, ClueContactRequest $clueContactRequest)
    {
        $item = $this->clues->rejectRequest($request->user(), $clueContactRequest);

        return ApiResponse::ok($this->presenter->requestItem($item));
    }

    public function cancelRequest(Request $request, ClueContactRequest $clueContactRequest)
    {
        $item = $this->clues->cancelRequest($request->user(), $clueContactRequest);

        return ApiResponse::ok($this->presenter->requestItem($item));
    }
}
