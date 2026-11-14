<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Setting;
use App\Services\GrowthService;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private GrowthService $growth,
        private ProfileService $profiles,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return ApiResponse::ok($this->profiles->payload($user, $this->growth->summary($user)));
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $this->profiles->update($request->user(), $request->validated());

        return ApiResponse::ok($this->profiles->payload($user, $this->growth->summary($user)));
    }

    public function visit(Request $request)
    {
        $user = $request->user();
        $awarded = $this->growth->visit($user);

        return ApiResponse::ok([
            'growth_awarded' => $awarded,
            'medal' => $this->growth->summary($user),
        ]);
    }

    public function growth(Request $request)
    {
        return ApiResponse::ok($this->growth->detail($request->user()));
    }

    public function settings()
    {
        return ApiResponse::ok(Setting::publicPayload());
    }
}
