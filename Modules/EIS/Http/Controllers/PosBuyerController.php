<?php

namespace Modules\EIS\Http\Controllers;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\EIS\Services\Sales\PosBuyerValidationService;

class PosBuyerController extends Controller
{
    public function authorizationRequirement(Request $request, PosBuyerValidationService $validation)
    {
        $input = $request->validate([
            'contact_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
        ]);
        $user = $request->user();
        $businessId = (int) $user->business_id;
        abort_unless($businessId > 0
            && ($user->can('sell.create') || $user->can('direct_sell.access') || $user->can('so.create')), 403);

        $locationId = (int) $input['location_id'];
        BusinessLocation::where('business_id', $businessId)->findOrFail($locationId);
        $permitted = $user->permitted_locations($businessId);
        abort_unless($permitted === 'all' || in_array($locationId, $permitted), 403);

        return response()->json([
            'success' => true,
            'data' => $validation->checkAuthorizationRequirement(
                $businessId,
                (int) $input['contact_id'],
                $locationId
            ),
        ]);
    }
}
