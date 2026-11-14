<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportOrderRequest;
use App\Services\SupportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function __construct(private SupportService $supports) {}

    public function show(Request $request)
    {
        return ApiResponse::ok($this->supports->overview($request->user()));
    }

    public function store(StoreSupportOrderRequest $request)
    {
        $order = $this->supports->give(
            $request->user(),
            (int) $request->validated('paw_count'),
            $request->boolean('anonymous'),
            (string) $request->validated('client_token'),
        );
        $payload = $this->supports->orderPayload($order, $request->user()->fresh());
        if ($payment = $this->supports->pullPayment()) {
            $payload['payment'] = $payment;
        }

        return ApiResponse::ok($payload);
    }

    public function order(Request $request, int $supportOrder)
    {
        $order = $this->supports->findOwn($request->user(), $supportOrder);

        return ApiResponse::ok($this->supports->orderPayload($order, $request->user()));
    }
}
