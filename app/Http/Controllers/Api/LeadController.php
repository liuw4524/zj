<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppealRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\StoreUploadRequest;
use App\Models\Lead;
use App\Models\Post;
use App\Models\ReceiptImage;
use App\Services\LeadService;
use App\Services\PostPresenter;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $leads,
        private PostPresenter $presenter,
    ) {}

    public function store(StoreLeadRequest $request, Post $post)
    {
        $lead = $this->leads->create($request->user(), $post, $request->validated());
        $lead->load('post.escrow');
        $payload = $this->presenter->lead($lead, $request->user());
        $payload['growth_awarded'] = $this->leads->growthAwarded;

        return ApiResponse::ok($payload);
    }

    public function index(Request $request, Post $post)
    {
        $paginator = $this->leads->forPost($request->user(), $post);
        $paginator->through(fn (Lead $lead) => $this->presenter->lead($lead, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function show(Request $request, Lead $lead)
    {
        $lead = $this->leads->show($request->user(), $lead);

        return ApiResponse::ok($this->presenter->lead($lead, $request->user()));
    }

    public function mine(Request $request)
    {
        $paginator = $this->leads->mine($request->user());
        $paginator->through(fn (Lead $lead) => $this->presenter->lead($lead, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function unreadCount(Request $request)
    {
        return ApiResponse::ok([
            'count' => $this->leads->unreadCount($request->user()),
        ]);
    }

    public function incoming(Request $request)
    {
        $paginator = $this->leads->incoming($request->user());
        $paginator->through(fn (Lead $lead) => $this->presenter->lead($lead, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function match(Request $request, Lead $lead)
    {
        $lead = $this->leads->match($request->user(), $lead);
        $lead->load('post.escrow', 'images');

        return ApiResponse::ok($this->presenter->lead($lead, $request->user()));
    }

    public function acknowledge(Request $request, Lead $lead)
    {
        $lead = $this->leads->acknowledge($request->user(), $lead);
        $lead->load('post.escrow', 'images');

        return ApiResponse::ok($this->presenter->lead($lead, $request->user()));
    }

    public function reject(Request $request, Lead $lead)
    {
        $lead = $this->leads->reject($request->user(), $lead);
        $lead->load('post.escrow', 'images');

        return ApiResponse::ok($this->presenter->lead($lead, $request->user()));
    }

    public function storeReceipt(StoreUploadRequest $request, Lead $lead)
    {
        $image = $this->leads->addReceipt($request->user(), $lead, $request->file('file'));

        return ApiResponse::ok([
            'id' => $image->id,
            'path' => $image->path,
            'url' => Storage::disk('public')->url($image->path),
        ]);
    }

    public function destroyReceipt(Request $request, Lead $lead, ReceiptImage $receiptImage)
    {
        $this->leads->removeReceipt($request->user(), $lead, $receiptImage);

        return ApiResponse::ok();
    }

    public function clearReceipts(Request $request, Lead $lead)
    {
        $this->leads->clearReceipts($request->user(), $lead);

        return ApiResponse::ok();
    }

    public function confirm(Request $request, Lead $lead)
    {
        $post = $this->leads->confirm($request->user(), $lead);

        return ApiResponse::ok([
            'id' => $post->id,
            'status' => $post->status->value,
            'status_text' => $post->status->label(),
        ]);
    }

    public function notMine(Request $request, Lead $lead)
    {
        $lead = $this->leads->notMine($request->user(), $lead);

        return ApiResponse::ok([
            'id' => $lead->id,
            'status' => $lead->status->value,
            'status_text' => $lead->status->label(),
            'post_status' => $lead->post->status->value,
        ]);
    }

    public function appeal(StoreAppealRequest $request, Post $post)
    {
        $appeal = $this->leads->appeal($request->user(), $post, $request->validated('content'));
        $appeal->load('post.escrow');

        return ApiResponse::ok([
            'id' => $appeal->id,
            'status' => $appeal->post->escrow->status->value,
        ]);
    }
}
