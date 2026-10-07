<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingOcr\AccountCodeRegistry;
use Illuminate\Http\JsonResponse;

class AccountingAccountLibraryController extends Controller
{
    public function __invoke(AccountCodeRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->library()]);
    }
}
