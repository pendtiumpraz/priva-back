<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiaAssessment;
use App\Models\TiaAssessment;
use App\Models\MaturityAssessment;
use Illuminate\Http\Request;

/**
 * Sprint F1/F2/F3: LIA, TIA, Maturity assessments — generic CRUD.
 * Each module has its own prefix but shares create/update/delete logic.
 */
class AssessmentsController extends Controller
{
    private function model(string $kind)
    {
        return match ($kind) {
            'lia' => new LiaAssessment,
            'tia' => new TiaAssessment,
            'maturity' => new MaturityAssessment,
            default => abort(404, 'Unknown assessment kind'),
        };
    }

    public function index(Request $request, string $kind)
    {
        $model = $this->model($kind);
        $query = $model->newQuery()->where('org_id', $request->user()->org_id);
        if ($request->get('trash')) $query->onlyTrashed();
        return response()->json(['data' => $query->orderByDesc('created_at')->get()]);
    }

    public function show(Request $request, string $kind, string $id)
    {
        $model = $this->model($kind);
        $record = $model->newQuery()->where('org_id', $request->user()->org_id)->withTrashed()->findOrFail($id);
        return response()->json(['data' => $record]);
    }

    public function store(Request $request, string $kind)
    {
        $data = $request->all();
        $data['org_id'] = $request->user()->org_id;
        $data['created_by'] = $request->user()->id;

        $record = $this->model($kind)->newQuery()->create($data);
        return response()->json(['message' => 'Created', 'data' => $record], 201);
    }

    public function update(Request $request, string $kind, string $id)
    {
        $record = $this->model($kind)->newQuery()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $record->update($request->all());
        return response()->json(['message' => 'Updated', 'data' => $record->fresh()]);
    }

    public function destroy(Request $request, string $kind, string $id)
    {
        $record = $this->model($kind)->newQuery()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $record->delete();
        return response()->json(['message' => 'Moved to trash']);
    }

    public function restore(Request $request, string $kind, string $id)
    {
        $record = $this->model($kind)->newQuery()->onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $record->restore();
        return response()->json(['message' => 'Restored']);
    }

    public function forceDelete(Request $request, string $kind, string $id)
    {
        $record = $this->model($kind)->newQuery()->withTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $record->forceDelete();
        return response()->json(['message' => 'Deleted permanently']);
    }
}
