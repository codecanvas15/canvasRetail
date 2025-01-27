<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\ItemDetail;
use App\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LocationController extends Controller
{
    //
    public function addLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        Location::create([
            'name'          => $request->name,
            'created_by'    => auth()->user()->id,
            'updated_by'    => auth()->user()->id,
            'status'        => 1
        ]);

        return response()->json([
            "status" => true,
            "message" => "Location registered successfully"
        ]);
    }

    public function getLocation(Request $request)
    {
        $sortBy = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'desc');

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Location::query();
        $locations = $query->where('status', 1)->orderBy($sortBy, $sortOrder)->paginate(10);
        $locations->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $locations->items(),
            'pagination' => [
                'current_page' => $locations->currentPage(),
                'total_pages' => $locations->lastPage(),
                'next_page' => $locations->nextPageUrl(),
                'prev_page' => $locations->previousPageUrl(),
            ],
        ]);
    }

    public function updateLocation(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        if (Location::where('id', $id)->exists())
        {
            $location = Location::find($id);
            $location->update([
                'name'          => $request->name,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Location updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Location not found"
            ], 404);
        }
    }

    public function deleteLocation($id)
    {
        if (Location::where('id', $id)->exists())
        {
            $location = Location::find($id);
            $location->update([
                'status'        => 0,
                'updated_at'    => date("Y-m-d H:i:s"),
                'updated_by'    => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Location deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Location not found"
            ], 404);
        }
    }

    public function getLocationById($id)
    {
        if (Location::where('id', $id)->where('status', 1)->exists())
        {
            $location = Location::where('id', $id)->where('status', 1)->first();

            $items = DB::table('items')
                    ->leftJoin('items_details', 'items.item_code', '=', 'items_details.item_code')
                    ->where('items_details.location_id', $id)
                    ->where('items_details.status', 1)
                    ->where('items.status', 1)
                    ->select('items.item_code', 'items.name', 'items.image', 'items.category', 'items_details.qty', 'items_details.price')
                    ->get();

            $data = $location;
            $data['items'] = $items;

            return response()->json([
                "status"    => true,
                "data"      => $data
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Location not found"
            ], 404);
        }
    }
}
