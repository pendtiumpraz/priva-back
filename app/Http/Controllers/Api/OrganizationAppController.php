<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\OrganizationApp;

class OrganizationAppController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $apps = OrganizationApp::where('org_id', $orgId)->orderBy('name')->get();
        
        if ($request->user()->role === 'superadmin' || $request->user()->role === 'admin') {
            $apps->makeVisible(['staging_db_password', 'prod_db_password']);
        }
        
        return response()->json(['data' => $apps]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $data = $request->all();
        $data['org_id'] = $request->user()->org_id;

        $app = OrganizationApp::create($data);

        return response()->json(['data' => $app, 'message' => 'Aplikasi berhasil ditambahkan'], 201);
    }

    public function show(Request $request, $id)
    {
        $app = OrganizationApp::where('org_id', $request->user()->org_id)->findOrFail($id);
        
        // Show passwords specifically when viewing detail IF needed, but usually hidden.
        // If we want to allow editing, we should make it visible to superadmin/admin.
        if ($request->user()->role === 'superadmin' || $request->user()->role === 'admin') {
            $app->makeVisible(['staging_db_password', 'prod_db_password']);
        }
        
        return response()->json(['data' => $app]);
    }

    public function update(Request $request, $id)
    {
        $app = OrganizationApp::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $data = $request->all();
        // Prevent clearing password if not provided
        if (empty($data['staging_db_password'])) unset($data['staging_db_password']);
        if (empty($data['prod_db_password'])) unset($data['prod_db_password']);

        $app->update($data);
        return response()->json(['data' => $app, 'message' => 'Aplikasi berhasil diupdate']);
    }

    public function destroy(Request $request, $id)
    {
        $app = OrganizationApp::where('org_id', $request->user()->org_id)->findOrFail($id);
        $app->delete();
        return response()->json(['message' => 'Aplikasi berhasil dihapus']);
    }
}
