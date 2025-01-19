<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Item;
use App\ItemDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ItemController extends Controller
{
    public function addItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "item_code" => "required|unique:items",
            "name" => "required"
        ]);

        if ($validator->fails()) {
            return response()->json([
                "status" => false,
                "message" => $validator->errors()
            ]);
        }

        $imagePath = '';
        if($request->image)
        {
            $validator = Validator::make($request->all(), [
                'image' => 'mimes:jpeg,jpg,png,gif|max:2048', // Validation rules
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = time().'.'.$image->getClientOriginalExtension(); // Generate a unique name for the image
                $image->storeAs('public/images', $imageName); // Store in the 'storage/public/images' directory
                $imagePath =asset('storage/images/' . $imageName);
            }
        }

        Item::create([
            'item_code'  => $request->item_code,
            'name'       => $request->name,
            'image'      => $imagePath,
            'category'   => $request->category ? $request->category : '',
            'created_by' => auth()->user()->id,
            'updated_by' => auth()->user()->id,
            'status'     => 1
        ]);

        return response()->json([
            "status" => true,
            "message" => "Item registered successfully"
        ]);
    }

    public function getItem(Request $request)
    {
        $sortBy = $request->input('sort_by', 'item_code');
        $sortOrder = $request->input('sort_order', 'desc');

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'desc'; // Default to ascending if invalid
        }

        $query  = Item::query();
        $item = $query->where('status', 1)->orderBy($sortBy, $sortOrder)->paginate(10);
        $item->appends([
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
        ]);

        return response()->json([
            'status' => true,
            'data' => $item->items(),
            'pagination' => [
                'current_page' => $item->currentPage(),
                'total_pages' => $item->lastPage(),
                'next_page' => $item->nextPageUrl(),
                'prev_page' => $item->previousPageUrl(),
            ],
        ]);
    }

    public function updateItem(Request $request, $item_code)
    {
        if (Item::where('item_code', $item_code)->exists())
        {
            $item = Item::where('item_code', $item_code)->first()->get();

            $imagePath = '';
            if($request->image)
            {
                $validator = Validator::make($request->all(), [
                    'image' => 'mimes:jpeg,jpg,png,gif|max:2048', // Validation rules
                ]);

                if ($validator->fails()) {
                    return response()->json($validator->errors(), 422);
                }

                if ($request->hasFile('image')) {
                    $image = $request->file('image');
                    $imageName = time().'.'.$image->getClientOriginalExtension(); // Generate a unique name for the image
                    $image->storeAs('public/images', $imageName); // Store in the 'storage/public/images' directory
                    $imagePath =asset('storage/images/' . $imageName);
                }
            }

            Item::where('item_code', $item_code)
            ->update([
                'name'       => $request->name ? $request->name : $item->name,
                'image'      => $imagePath,
                'category'   => $request->category ? $request->category : $item->category,
                'updated_at' => date("Y-m-d H:i:s"),
                'updated_by' => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Item updated successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function deleteItem($item_code)
    {
        if (Item::where('item_code', $item_code)->exists())
        {
            Item::where('item_code', $item_code)
            ->update([
                'status'     => 0,
                'updated_at' => date("Y-m-d H:i:s"),
                'updated_by' => auth()->user()->id
            ]);

            return response()->json([
                "status" => true,
                "message" => "Item deleted successfully"
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function getItemById($item_code)
    {
        if (Item::where('item_code', $item_code)->where('status', 1)->exists())
        {
            $item = Item::where('item_code', $item_code)->first();

            $itemDet = DB::table('items_details')
                        ->join('locations', 'items_details.location_id', '=', 'locations.id')
                        ->where('items_details.status', 1)
                        ->select('items_details.qty', 'items_details.price', 'locations.name AS location')
                        ->get();

            $data = $item;
            $data['item_details'] = $itemDet;

            return response()->json([
                "status" => true,
                "data" => $data
            ]);
        }
        else
        {
            return response()->json([
                "status" => false,
                "message" => "Item not found"
            ], 404);
        }
    }

    public function getUniqueCategories()
    {
        $categories = Item::where('status', 1)->distinct()->pluck('category');

        return response()->json([
            "status" => true,
            "data"  => $categories
        ]);
    }
}
