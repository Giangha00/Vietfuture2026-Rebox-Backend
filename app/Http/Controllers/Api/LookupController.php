<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Station;
use App\Support\Serializers;

class LookupController extends Controller
{
    public function categories()
    {
        $categories = Category::query()->orderBy('name')->get()
            ->map(fn (Category $c) => Serializers::category($c));

        return response()->json(['categories' => $categories]);
    }

    public function stations()
    {
        $stations = Station::query()->where('is_active', true)->orderBy('city')->get()
            ->map(fn (Station $s) => Serializers::station($s));

        return response()->json(['stations' => $stations]);
    }
}
