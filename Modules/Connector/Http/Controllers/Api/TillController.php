<?php

namespace Modules\Connector\Http\Controllers\Api;

use App\BusinessLocation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\EIS\Models\EisConfiguration;
use Modules\EIS\Models\EisSetting;

class TillController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $locations = BusinessLocation::where('business_id', $user->business_id);
        $permitted = $user->permitted_locations((int) $user->business_id);
        if ($permitted !== 'all') {
            $locations->whereIn('id', $permitted);
        }
        $locationIds = $locations->pluck('id');
        $tills = DB::table('connector_tills')->where('business_id', $user->business_id)
            ->whereIn('location_id', $locationIds)->where('is_active', true)->orderBy('name')->get();
        $terminals = EisSetting::where('business_id', $user->business_id)->whereIn('location_id', $locationIds)
            ->get(['id', 'location_id', 'device_id']);
        $configurationsByTerminal = EisConfiguration::where('business_id', $user->business_id)
            ->whereIn('eis_setting_id', $terminals->pluck('id'))
            ->orderByDesc('id')
            ->get(['eis_setting_id', 'is_vat_registered', 'tax_office_code', 'raw_response'])
            ->unique('eis_setting_id')
            ->keyBy('eis_setting_id');
        foreach ($terminals as $terminal) {
            $configuration = $configurationsByTerminal->get($terminal->id);
            $rawVatStatus = $configuration
                ? data_get($configuration->raw_response, 'data.taxpayerConfiguration.isVATRegistered')
                : null;
            $terminal->is_vat_registered = is_bool($rawVatStatus)
                ? $rawVatStatus
                : ($configuration ? $configuration->is_vat_registered : null);
            $taxOfficeName = $configuration
                ? data_get($configuration->raw_response, 'data.taxpayerConfiguration.taxOffice.name')
                : null;
            $terminal->tax_office = is_string($taxOfficeName) && trim($taxOfficeName) !== ''
                ? trim($taxOfficeName)
                : ($configuration ? $configuration->tax_office_code : null);
        }
        return response()->json(['data' => ['tills' => $tills, 'terminals' => $terminals]]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->can('business_settings.access'), 403);
        $input = $request->validate([
            'name' => 'required|string|max:100',
            'location_id' => 'required|integer|min:1',
            'eis_setting_id' => 'required|integer|min:1',
        ]);
        $permitted = $user->permitted_locations((int) $user->business_id);
        abort_unless($permitted === 'all' || in_array((int) $input['location_id'], $permitted), 403);
        BusinessLocation::where('business_id', $user->business_id)->findOrFail($input['location_id']);
        EisSetting::where('business_id', $user->business_id)->where('location_id', $input['location_id'])
            ->findOrFail($input['eis_setting_id']);
        $name = trim($input['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a till name.']);
        }
        $id = DB::transaction(function () use ($user, $input, $name) {
            BusinessLocation::where('business_id', $user->business_id)->lockForUpdate()->findOrFail($input['location_id']);
            $query = DB::table('connector_tills')->where('business_id', $user->business_id)
                ->where('location_id', $input['location_id'])->where('name', $name);
            $existing = $query->first();
            $values = ['eis_setting_id' => $input['eis_setting_id'], 'is_active' => true, 'updated_at' => now()];
            if ($existing) {
                $query->update($values);
                return $existing->id;
            }
            return DB::table('connector_tills')->insertGetId($values + [
                'business_id' => $user->business_id, 'location_id' => $input['location_id'],
                'name' => $name, 'created_at' => now(),
            ]);
        });
        return response()->json(['data' => DB::table('connector_tills')->where('id', $id)->first()]);
    }
}
