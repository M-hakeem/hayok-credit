<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganisationController extends Controller
{
    public function index()
    {
        $organisations = Organisation::withCount('users')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $organisations,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'nullable|email|unique:organisations,email',
            'phone' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ]);

        ['plain' => $plain, 'hash' => $hash] = Organisation::generateApiKey();

        $organisation = Organisation::create([
            'name'   => $request->name,
            'slug'   => Str::slug($request->name) . '-' . Str::random(6),
            'email'  => $request->email,
            'phone'  => $request->phone,
            'notes'  => $request->notes,
            'api_key' => $hash,
            'status' => 'active',
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Organisation created. Store the API key now — it will not be shown again.',
            'data'    => [
                'organisation' => $organisation,
                'api_key'      => $plain,
            ],
        ], 201);
    }

    public function show($id)
    {
        $organisation = Organisation::withCount('users')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data'   => $organisation,
        ]);
    }

    public function update(Request $request, $id)
    {
        $organisation = Organisation::findOrFail($id);

        $request->validate([
            'name'   => 'sometimes|string|max:255',
            'email'  => 'sometimes|nullable|email|unique:organisations,email,' . $organisation->id,
            'phone'  => 'sometimes|nullable|string|max:20',
            'status' => 'sometimes|in:active,inactive,suspended',
            'notes'  => 'sometimes|nullable|string|max:1000',
        ]);

        $organisation->update($request->only(['name', 'email', 'phone', 'status', 'notes']));

        return response()->json([
            'status' => 'success',
            'message' => 'Organisation updated.',
            'data'   => $organisation,
        ]);
    }

    public function regenerateKey($id)
    {
        $organisation = Organisation::findOrFail($id);

        ['plain' => $plain, 'hash' => $hash] = Organisation::generateApiKey();

        $organisation->update(['api_key' => $hash]);

        return response()->json([
            'status'  => 'success',
            'message' => 'API key regenerated. Store it now — it will not be shown again.',
            'data'    => [
                'organisation' => $organisation,
                'api_key'      => $plain,
            ],
        ]);
    }

    public function users($id)
    {
        $organisation = Organisation::findOrFail($id);

        $users = $organisation->users()
            ->with('loans')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data'   => $users,
        ]);
    }

    public function destroy($id)
    {
        $organisation = Organisation::findOrFail($id);
        $organisation->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Organisation removed.',
        ]);
    }
}
