<?php

namespace App\Http\Controllers\Api;

use App\Enums\EscrowStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddRewardRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\StoreUploadRequest;
use App\Models\Post;
use App\Services\GrowthService;
use App\Services\PostPresenter;
use App\Services\PostService;
use App\Services\UploadService;
use App\Support\ApiResponse;
use App\Support\RefundWindow;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function __construct(
        private PostService $posts,
        private PostPresenter $presenter,
        private UploadService $uploads,
        private GrowthService $growth,
    ) {}

    public function upload(StoreUploadRequest $request)
    {
        return ApiResponse::ok($this->uploads->store($request->user(), $request->file('file')));
    }

    public function index(Request $request)
    {
        $lostWithin = $request->query('lost_within');
        if ($lostWithin !== null && $lostWithin !== '' && ! in_array($lostWithin, ['3d', '1m', '6m'], true)) {
            throw new BusinessException('筛选无效');
        }

        $hasReward = $request->query('has_reward');
        if ($hasReward !== null && $hasReward !== '' && ! in_array((string) $hasReward, ['0', '1'], true)) {
            throw new BusinessException('筛选无效');
        }

        $province = $this->areaQuery($request, 'province');
        $city = $this->areaQuery($request, 'city');
        $district = $this->areaQuery($request, 'district');
        $town = $this->areaQuery($request, 'town');
        if (($town && ! $district) || ($district && ! $city) || (($city || $province) && ! ($city && $province))) {
            throw new BusinessException('筛选无效');
        }

        $paginator = $this->posts->publicList([
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'town' => $town,
            'lost_within' => $lostWithin ?: null,
            'has_reward' => $hasReward === null || $hasReward === '' ? null : (string) $hasReward,
        ]);

        $paginator->through(fn (Post $post) => $this->presenter->card($post, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function store(StorePostRequest $request)
    {
        $post = $this->posts->create($request->user(), $request->validated());

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function trace(Request $request)
    {
        $code = $request->query('code');
        if (! is_string($code)) {
            throw new BusinessException('未找到该爪迹编号', 404);
        }

        $post = $this->posts->findByTrace($request->user(), $code);

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function show(Request $request, Post $post)
    {
        $post = $this->posts->show($request->user(), $post);

        return ApiResponse::ok($this->presenter->detail($post, $request->user()));
    }

    public function mine(Request $request)
    {
        $paginator = $this->posts->myPosts($request->user());
        $paginator->through(fn (Post $post) => $this->presenter->card($post, $request->user()));

        return ApiResponse::paginated($paginator);
    }

    public function pay(Request $request, Post $post)
    {
        $order = $this->posts->pay($request->user(), $post);

        $payload = [
            'id' => $order->id,
            'status' => $order->status->value,
            'amount' => $order->amount,
            'out_trade_no' => $order->out_trade_no,
        ];
        if ($payment = $this->posts->pullPayment()) {
            $payload['payment'] = $payment;
        }

        return ApiResponse::ok($payload);
    }

    public function addReward(AddRewardRequest $request, Post $post)
    {
        $post = $this->posts->addReward($request->user(), $post, (int) $request->validated('add_amount'));
        $payload = $this->presenter->detail($post->fresh(), $request->user());
        if ($payment = $this->posts->pullPayment()) {
            $payload['payment'] = $payment;
        }

        return ApiResponse::ok($payload);
    }

    public function withdraw(Request $request, Post $post)
    {
        $post = $this->posts->withdraw($request->user(), $post);
        $post->load('escrow');
        $order = $post->escrow;
        $text = '已撤回';
        if ($order?->status === EscrowStatus::RefundReview) {
            $text = '已撤回。退款需平台确认，通过后才会原路退回。';
        } elseif ($order && $order->status === EscrowStatus::Held && RefundWindow::expired($order)) {
            $text = '已撤回。已超过微信退款期限，请联系平台退回托管金。';
        }

        return ApiResponse::ok([
            'id' => $post->id,
            'status' => $post->status->value,
            'status_text' => $post->status->label(),
            'refund_text' => $text,
        ]);
    }

    public function light(Request $request, Post $post)
    {
        $post = $this->posts->light($request->user(), $post);

        return ApiResponse::ok([
            'id' => $post->id,
            'status' => $post->status->value,
            'lit_until' => $post->lit_until?->toIso8601String(),
            'light_days_left' => $this->presenter->detail($post, $request->user())['light_days_left'],
        ]);
    }

    public function share(Request $request, Post $post)
    {
        $post = $this->posts->share($post);
        $awarded = $request->boolean('growth')
            ? $this->growth->awardShare($request->user(), 'post', $post->id)
            : 0;

        return ApiResponse::ok([
            'share_count' => $post->share_count,
            'growth_awarded' => $awarded,
        ]);
    }

    private function areaQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new BusinessException('筛选无效');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 40) {
            throw new BusinessException('筛选无效');
        }

        return $value;
    }
}
